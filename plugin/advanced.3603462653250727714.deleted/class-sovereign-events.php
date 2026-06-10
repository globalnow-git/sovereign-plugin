<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SovereignEvents
 * Inbound/outbound event bus with persistence, routing, retry queue, and signal integration.
 */
class SovereignEvents {

	/** @var array Internal subscribers: event_type => [ callable, priority ] */
	private static array $subscribers = [];

	/**
	 * Register an internal subscriber for an event type.
	 *
	 * @param string   $event_type
	 * @param callable $callback
	 * @param int      $priority
	 */
	public static function subscribe( string $event_type, callable $callback, int $priority = 10 ): void {
		self::$subscribers[ $event_type ][] = [ 'callback' => $callback, 'priority' => $priority ];
	}

	/**
	 * Emit an event: persist, route to subscribers, trigger signals.
	 *
	 * @param string $event_type
	 * @param array  $payload
	 * @param string $source  internal|webhook|connector
	 * @return int  event ID
	 */
	public static function emit( string $event_type, array $payload, string $source = 'internal' ): int {
		global $wpdb;

		$event_id = 0;

		// Persist
		$wpdb->insert( "{$wpdb->prefix}sb_connector_events", [
			'event_type'    => sanitize_text_field( $event_type ),
			'source'        => sanitize_key( $source ),
			'direction'     => 'inbound',
			'payload'       => wp_json_encode( $payload ),
			'connector_slug'=> '',
			'status'        => 'received',
			'created_at'    => current_time( 'mysql' ),
		] );
		$event_id = (int) $wpdb->insert_id;

		// Route to internal subscribers
		$subscribers = self::$subscribers[ $event_type ] ?? [];
		usort( $subscribers, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		foreach ( $subscribers as $sub ) {
			try {
				call_user_func( $sub['callback'], $payload, $event_type, $event_id );
			} catch ( \Exception $e ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_SUBSCRIBER_ERROR,
					"Subscriber error for {$event_type}: " . $e->getMessage(),
					0,
					[ 'event_id' => $event_id ],
					'error'
				);
			}
		}

		// WP action hook for extensibility
		do_action( "sb_event_{$event_type}", $payload, $event_id );

		// Signal and journey integration
		self::route_to_signal( $event_type, $payload );

		// Audit
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_EMITTED,
			"Event emitted: {$event_type}",
			get_current_user_id(),
			[ 'event_id' => $event_id, 'source' => $source ],
			'info'
		);

		// Update status
		$wpdb->update(
			"{$wpdb->prefix}sb_connector_events",
			[ 'status' => 'processed', 'resolved_at' => current_time( 'mysql' ) ],
			[ 'id' => $event_id ]
		);

		return $event_id;
	}

	/**
	 * Route event to signal engine and many roads where applicable.
	 *
	 * @param string $event_type
	 * @param array  $payload
	 */
	private static function route_to_signal( string $event_type, array $payload ): void {
		$user_id = absint( $payload['user_id'] ?? 0 );

		$signal_map = [
			'sb.user.signal_fired'            => function() use ( $payload, $user_id ) {
				$signal_type = sanitize_key( $payload['signal_type'] ?? '' );
				$value       = (float) ( $payload['value'] ?? 1.0 );
				if ( $signal_type && $user_id ) {
					SB_Signal_Engine::record_signal( 0, $signal_type, $value, $user_id );
				}
			},
			'sb.user.road_entered'            => function() use ( $payload, $user_id ) {
				$road_key = sanitize_key( $payload['road_key'] ?? '' );
				if ( $road_key && $user_id ) {
					SB_Many_Roads::enter_road( $user_id, $road_key );
				}
			},
			'sb.commerce.purchase'            => function() use ( $user_id ) {
				if ( $user_id ) {
					SB_Signal_Engine::record_signal( 0, 'wc_purchase', 1.0, $user_id );
				}
			},
		];

		if ( isset( $signal_map[ $event_type ] ) ) {
			try {
				call_user_func( $signal_map[ $event_type ] );
			} catch ( \Exception $e ) {
				// Signal routing failure is non-fatal
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_SIGNAL_ROUTING_ERROR, $e->getMessage(), 0, [], 'warning' );
			}
		}
	}

	/**
	 * Handle inbound webhook — validate HMAC, parse payload, emit.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_inbound( WP_REST_Request $request ) {
		$connector_slug = sanitize_key( $request->get_param( 'connector' ) ?? '' );

		$secret = SB_Extension_API::get_setting( "sb_connector_{$connector_slug}_secret", '' );
		$body   = $request->get_body();

		// Body size cap: 512KB
		if ( strlen( $body ) > 524288 ) {
			return rest_ensure_response( [ 'received' => true ] );
		}

		// Reject when no secret configured — prevents unauthenticated event injection.
		if ( ! $secret ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_INBOUND_NO_SECRET,
				"Inbound event rejected — connector '{$connector_slug}' has no secret configured.",
				0,
				[ 'connector' => $connector_slug ],
				'warning'
			);
			return rest_ensure_response( [ 'received' => true ] ); // 200 to prevent enumeration
		}

		// Validate HMAC + timestamp tolerance + replay protection via SBRoutePolicy.
		$guard = SBRoutePolicy::validate_signed_webhook( $request, $secret, $connector_slug );
		if ( is_wp_error( $guard ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_INBOUND_SIGNATURE_INVALID,
				"Webhook guard failed for connector '{$connector_slug}': " . $guard->get_error_message(),
				0,
				[ 'connector' => $connector_slug, 'code' => $guard->get_error_code() ],
				'warning'
			);
			return rest_ensure_response( [ 'received' => true ] ); // 200 to prevent info leakage
		}

		$payload    = (array) $request->get_json_params();
		$event_type = sanitize_text_field( $payload['event_type'] ?? "sb.connector.{$connector_slug}.inbound" );

		self::emit( $event_type, $payload, 'webhook' );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_INBOUND_RECEIVED,
			"Inbound event received from connector: {$connector_slug}",
			0,
			[ 'connector' => $connector_slug, 'event_type' => $event_type ],
			'info'
		);

		return rest_ensure_response( [ 'received' => true ] );
	}

	/**
	 * Dispatch outbound event to a connector.
	 *
	 * @param string $connector_slug
	 * @param string $event_type
	 * @param array  $payload
	 * @return bool
	 */
	public static function dispatch_outbound( string $connector_slug, string $event_type, array $payload ): bool {
		global $wpdb;

		$endpoint = SB_Extension_API::get_setting( "sb_connector_{$connector_slug}_endpoint", '' );
		$secret   = SB_Extension_API::get_setting( "sb_connector_{$connector_slug}_secret", '' );

		if ( ! $endpoint ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_DISPATCH_FAILED,
				"No endpoint configured for connector: {$connector_slug}",
				0,
				[ 'connector' => $connector_slug ],
				'error'
			);
			return false;
		}

		$body = wp_json_encode( array_merge( $payload, [ 'event_type' => $event_type ] ) );
		$args = [
			'body'    => $body,
			'headers' => [
				'Content-Type'   => 'application/json',
				'X-SB-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
				'X-SB-Event'     => $event_type,
			],
			'timeout' => 15,
		];

		$response = wp_remote_post( $endpoint, $args );
		$success  = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 400;

		// Log event record
		$wpdb->insert( "{$wpdb->prefix}sb_connector_events", [
			'event_type'     => sanitize_text_field( $event_type ),
			'source'         => 'internal',
			'direction'      => 'outbound',
			'payload'        => $body,
			'connector_slug' => sanitize_key( $connector_slug ),
			'status'         => $success ? 'dispatched' : 'failed',
			'retry_count'    => 0,
			'created_at'     => current_time( 'mysql' ),
		] );
		$event_id = (int) $wpdb->insert_id;

		if ( $success ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_DISPATCH_OK,
				"Outbound dispatch OK: {$connector_slug} / {$event_type}",
				0,
				[ 'connector' => $connector_slug, 'event_id' => $event_id ],
				'info'
			);
		} else {
			$error_msg = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response );
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_DISPATCH_FAILED,
				"Outbound dispatch FAILED: {$connector_slug} / {$event_type} — {$error_msg}",
				0,
				[ 'connector' => $connector_slug, 'event_id' => $event_id ],
				'error'
			);

			// Queue for retry
			$retry_limit = (int) SB_Extension_API::get_setting( 'sb_connector_retry_limit', 5 );
			$retry_delay = (int) SB_Extension_API::get_setting( 'sb_connector_retry_delay', 300 );

			$wpdb->update(
				"{$wpdb->prefix}sb_connector_events",
				[
					'status'        => 'retrying',
					'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + $retry_delay ),
				],
				[ 'id' => $event_id ]
			);
		}

		return $success;
	}

	/**
	 * Run retry queue — called by sb_connector_retry_cron.
	 */
	public static function run_retry_queue(): void {
		global $wpdb;

		$retry_limit = (int) SB_Extension_API::get_setting( 'sb_connector_retry_limit', 5 );

		$events = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_connector_events
			 WHERE status = 'retrying'
			   AND next_retry_at <= %s
			 ORDER BY id ASC
			 LIMIT 50",
			current_time( 'mysql' )
		) );

		foreach ( $events as $event ) {
			$payload = (array) json_decode( $event->payload, true );
			$success = self::dispatch_outbound( $event->connector_slug, $event->event_type, $payload );

			if ( $success ) {
				$wpdb->update(
					"{$wpdb->prefix}sb_connector_events",
					[ 'status' => 'dispatched', 'resolved_at' => current_time( 'mysql' ) ],
					[ 'id' => $event->id ]
				);
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_RETRIED, "Retry OK for event #{$event->id}", 0, [], 'info' );
			} else {
				$new_retry_count = (int) $event->retry_count + 1;
				if ( $new_retry_count >= $retry_limit ) {
					$wpdb->update(
						"{$wpdb->prefix}sb_connector_events",
						[ 'status' => 'dead_letter', 'retry_count' => $new_retry_count ],
						[ 'id' => $event->id ]
					);
					do_action( 'sb_connector_dead_letter', $event );
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_DEAD_LETTER, "Event #{$event->id} moved to dead-letter after {$new_retry_count} retries", 0, [], 'error' );
				} else {
					// Exponential backoff: delay * 2^retry
					$base_delay  = (int) SB_Extension_API::get_setting( 'sb_connector_retry_delay', 300 );
					$next_delay  = $base_delay * ( 2 ** $new_retry_count );
					$wpdb->update(
						"{$wpdb->prefix}sb_connector_events",
						[
							'retry_count'   => $new_retry_count,
							'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + $next_delay ),
						],
						[ 'id' => $event->id ]
					);
				}
			}
		}
	}

	/**
	 * Bootstrap internal subscribers from registered blueprints.
	 */
	public static function bootstrap_subscribers(): void {
		// Core routing: commerce purchase → wc_purchase signal
		self::subscribe( 'sb.commerce.purchase', function( $payload ) {
			$user_id = absint( $payload['user_id'] ?? 0 );
			if ( $user_id ) {
				SB_Signal_Engine::record_signal( 0, 'wc_purchase', 1.0, $user_id );
			}
		} );

		do_action( 'sb_sovereign_events_subscribe', new self() );
	}
}