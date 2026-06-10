<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBConnectorHealthConsole
 * Operator dashboard for connector status, failures, dead-letter, replay, and credential rotation.
 */
class SBConnectorHealthConsole {

	/**
	 * Return status summary for all configured connectors.
	 *
	 * @return array
	 */
	public static function get_connector_status(): array {
		global $wpdb;

		// Connector slugs are derived from settings keys: sb_connector_{slug}_endpoint
		$options     = $wpdb->get_results(
			"SELECT option_name FROM {$wpdb->prefix}options
			 WHERE option_name LIKE 'sb_connector_%_endpoint'
			 AND option_value != ''",
			ARRAY_A
		);

		$connectors = [];
		foreach ( $options as $opt ) {
			preg_match( '/sb_connector_(.+)_endpoint/', $opt['option_name'], $m );
			if ( empty( $m[1] ) ) {
				continue;
			}
			$slug = $m[1];

			$stats = $wpdb->get_row( $wpdb->prepare(
				"SELECT
				   COUNT(*) AS total,
				   SUM(status = 'dispatched') AS success_count,
				   SUM(status = 'failed') AS fail_count,
				   SUM(status = 'retrying') AS retry_count,
				   SUM(status = 'dead_letter') AS dead_count,
				   MAX(CASE WHEN status = 'dispatched' THEN created_at END) AS last_success,
				   MAX(CASE WHEN status = 'failed' THEN created_at END) AS last_failure
				 FROM {$wpdb->prefix}sb_connector_events
				 WHERE connector_slug = %s
				   AND direction = 'outbound'",
				$slug
			) );

			$success_rate = ( (int) ( $stats->total ?? 0 ) > 0 )
				? round( ( (int) ( $stats->success_count ?? 0 ) / (int) $stats->total ) * 100, 1 )
				: 0;

			$health = 'green';
			if ( (int) ( $stats->dead_count ?? 0 ) > 0 ) {
				$health = 'red';
			} elseif ( (int) ( $stats->retry_count ?? 0 ) > 0 ) {
				$health = 'yellow';
			}

			$connectors[] = [
				'slug'         => $slug,
				'label'        => ucwords( str_replace( [ '_', '-' ], ' ', $slug ) ),
				'health'       => $health,
				'last_success' => $stats->last_success ?? null,
				'last_failure' => $stats->last_failure ?? null,
				'retry_count'  => (int) ( $stats->retry_count ?? 0 ),
				'dead_count'   => (int) ( $stats->dead_count ?? 0 ),
				'success_rate' => $success_rate,
				'auth_valid'   => (bool) SB_Extension_API::get_setting( "sb_connector_{$slug}_secret", '' ),
			];
		}

		return $connectors;
	}

	/**
	 * Return recent events for a connector.
	 *
	 * @param string $connector_slug
	 * @param int    $limit
	 * @return array
	 */
	public static function get_recent_events( string $connector_slug, int $limit = 25 ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, event_type, direction, status, retry_count, created_at,
			        LEFT(payload, 200) AS payload_preview
			 FROM {$wpdb->prefix}sb_connector_events
			 WHERE connector_slug = %s
			 ORDER BY id DESC
			 LIMIT %d",
			$connector_slug,
			$limit
		), ARRAY_A );
	}

	/**
	 * Return failed/retrying events for a connector.
	 *
	 * @param string $connector_slug
	 * @return array
	 */
	public static function get_failures( string $connector_slug ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_connector_events
			 WHERE connector_slug = %s
			   AND status IN ('failed', 'retrying', 'dead_letter')
			 ORDER BY created_at DESC
			 LIMIT 100",
			$connector_slug
		), ARRAY_A );
	}

	/**
	 * Replay an event — queue to sb_replay_queue.
	 *
	 * @param int $event_id
	 * @return int|WP_Error  replay queue ID
	 */
	public static function replay_event( int $event_id ) {
		global $wpdb;

		if ( ! current_user_can( 'manage_sovereign' ) ) {
			return new WP_Error( 'unauthorized', 'Insufficient capability.', [ 'status' => 403 ] );
		}

		$event = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_connector_events WHERE id = %d",
			$event_id
		) );

		if ( ! $event ) {
			return new WP_Error( 'not_found', 'Event not found.', [ 'status' => 404 ] );
		}

		$wpdb->insert( "{$wpdb->prefix}sb_replay_queue", [
			'original_event_id' => $event_id,
			'connector_slug'    => $event->connector_slug,
			'payload'           => $event->payload,
			'status'            => 'queued',
			'queued_by'         => get_current_user_id(),
			'queued_at'         => current_time( 'mysql' ),
		] );

		$replay_id = (int) $wpdb->insert_id;

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_REPLAYED,
			"Event #{$event_id} queued for replay (connector: {$event->connector_slug})",
			get_current_user_id(),
			[ 'event_id' => $event_id, 'replay_id' => $replay_id ],
			'info'
		);

		// Execute immediately if small queue
		self::execute_replay( $replay_id );

		return $replay_id;
	}

	/**
	 * Execute a replay queue item.
	 *
	 * @param int $replay_id
	 */
	public static function execute_replay( int $replay_id ): void {
		global $wpdb;

		$replay = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_replay_queue WHERE id = %d AND status = 'queued'",
			$replay_id
		) );

		if ( ! $replay ) {
			return;
		}

		$wpdb->update( "{$wpdb->prefix}sb_replay_queue", [ 'status' => 'executing' ], [ 'id' => $replay_id ] );

		$payload     = (array) json_decode( $replay->payload, true );
		$event_type  = $payload['event_type'] ?? 'sb.connector.replayed';
		$success     = SovereignEvents::dispatch_outbound( $replay->connector_slug, $event_type, $payload );

		$wpdb->update( "{$wpdb->prefix}sb_replay_queue", [
			'status'      => $success ? 'completed' : 'failed',
			'executed_at' => current_time( 'mysql' ),
			'result_json' => wp_json_encode( [ 'success' => $success ] ),
		], [ 'id' => $replay_id ] );
	}

	/**
	 * Rotate credentials for a connector — requires HITM approval.
	 *
	 * @param string $connector_slug
	 * @param array  $new_credentials  [ 'secret' => '...', 'endpoint' => '...' ]
	 * @return array|WP_Error
	 */
	public static function rotate_credentials( string $connector_slug, array $new_credentials ) {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			return new WP_Error( 'unauthorized', 'Insufficient capability.', [ 'status' => 403 ] );
		}

		$approval_id = SB_Approval_Engine::create_approval( [
			'approval_type' => 'connector_replay',
			'payload'       => wp_json_encode( [
				'action'     => 'rotate_credentials',
				'connector'  => $connector_slug,
				'fields'     => array_keys( $new_credentials ),
			] ),
			'campaign_id'   => 0,
		] );

		// Do not apply credentials until approved
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONNECTOR_CREDENTIALS_ROTATION_QUEUED,
			"Credential rotation queued for connector: {$connector_slug}",
			get_current_user_id(),
			[ 'connector' => $connector_slug, 'approval_id' => $approval_id ],
			'info'
		);

		return [ 'success' => true, 'approval_id' => $approval_id ];
	}

	/**
	 * Return blueprints and journeys that depend on a connector.
	 *
	 * @param string $connector_slug
	 * @return array
	 */
	public static function get_dependency_impact( string $connector_slug ): array {
		global $wpdb;

		$blueprints = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, slug, label FROM {$wpdb->prefix}sb_app_blueprints
			 WHERE config_json LIKE %s AND status = 'active'",
			'%' . $wpdb->esc_like( $connector_slug ) . '%'
		), ARRAY_A );

		return [
			'connector_slug' => $connector_slug,
			'affected_blueprints' => $blueprints,
			'risk'               => count( $blueprints ) > 0 ? 'high' : 'low',
		];
	}

	/**
	 * Render admin screen.
	 */
	public static function render_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_connector_events' ] );
		if ( $guard ) { echo $guard; return; }

		$connector_slug = sanitize_key( $_GET['connector'] ?? '' );
		$tab            = sanitize_key( $_GET['tab'] ?? 'overview' );

		echo '<div class="wrap">';
		echo '<h1>Connector Health Console</h1>';

		if ( ! $connector_slug ) {
			// Overview
			$connectors = self::get_connector_status();
			echo '<table class="widefat striped">';
			echo '<thead><tr><th>Connector</th><th>Health</th><th>Last Success</th><th>Last Failure</th><th>Retrying</th><th>Dead-Letter</th><th>Success Rate</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $connectors as $c ) {
				$indicator = match( $c['health'] ) {
					'green' => '<span style="color:green">●</span>',
					'yellow'=> '<span style="color:orange">●</span>',
					default => '<span style="color:red">●</span>',
				};
				$url = admin_url( 'admin.php?page=sb-connector-health&connector=' . esc_attr( $c['slug'] ) );
				echo '<tr>';
				echo '<td>' . esc_html( $c['label'] ) . '</td>';
				echo '<td>' . $indicator . ' ' . esc_html( $c['health'] ) . '</td>';
				echo '<td>' . esc_html( $c['last_success'] ?? '—' ) . '</td>';
				echo '<td>' . esc_html( $c['last_failure'] ?? '—' ) . '</td>';
				echo '<td>' . (int) $c['retry_count'] . '</td>';
				echo '<td>' . (int) $c['dead_count'] . '</td>';
				echo '<td>' . esc_html( $c['success_rate'] ) . '%</td>';
				echo '<td><a href="' . esc_url( $url ) . '">View</a></td>';
				echo '</tr>';
			}
			if ( empty( $connectors ) ) {
				echo '<tr><td colspan="8">No connectors configured. Add connector endpoints in Settings.</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			// Connector detail
			$events = self::get_recent_events( $connector_slug );
			$failures = self::get_failures( $connector_slug );

			echo '<nav class="nav-tab-wrapper">';
			echo '<a href="' . esc_url( admin_url( "admin.php?page=sb-connector-health&connector={$connector_slug}&tab=events" ) ) . '" class="nav-tab' . ( $tab === 'events' ? ' nav-tab-active' : '' ) . '">Recent Events</a>';
			echo '<a href="' . esc_url( admin_url( "admin.php?page=sb-connector-health&connector={$connector_slug}&tab=failures" ) ) . '" class="nav-tab' . ( $tab === 'failures' ? ' nav-tab-active' : '' ) . '">Failures &amp; Dead-Letter</a>';
			echo '</nav>';

			if ( $tab === 'failures' ) {
				echo '<table class="widefat striped" style="margin-top:15px;">';
				echo '<thead><tr><th>ID</th><th>Event Type</th><th>Status</th><th>Retries</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
				foreach ( $failures as $f ) {
					$nonce    = wp_create_nonce( 'sb_replay_' . $f['id'] );
					$replay_url = wp_nonce_url(
						admin_url( "admin-post.php?action=sb_replay_event&event_id={$f['id']}" ),
						'sb_replay_event_' . $f['id']
					);
					echo '<tr>';
					echo '<td>' . (int) $f['id'] . '</td>';
					echo '<td>' . esc_html( $f['event_type'] ) . '</td>';
					echo '<td>' . esc_html( $f['status'] ) . '</td>';
					echo '<td>' . (int) $f['retry_count'] . '</td>';
					echo '<td>' . esc_html( $f['created_at'] ) . '</td>';
					echo '<td><a href="' . esc_url( $replay_url ) . '" onclick="return confirm(\'Replay this event?\')">Replay</a></td>';
					echo '</tr>';
				}
				if ( empty( $failures ) ) {
					echo '<tr><td colspan="6">No failures or dead-letter items.</td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<table class="widefat striped" style="margin-top:15px;">';
				echo '<thead><tr><th>ID</th><th>Event Type</th><th>Direction</th><th>Status</th><th>Created</th></tr></thead><tbody>';
				foreach ( $events as $e ) {
					echo '<tr>';
					echo '<td>' . (int) $e['id'] . '</td>';
					echo '<td>' . esc_html( $e['event_type'] ) . '</td>';
					echo '<td>' . esc_html( $e['direction'] ) . '</td>';
					echo '<td>' . esc_html( $e['status'] ) . '</td>';
					echo '<td>' . esc_html( $e['created_at'] ) . '</td>';
					echo '</tr>';
				}
				if ( empty( $events ) ) {
					echo '<tr><td colspan="5">No recent events for this connector.</td></tr>';
				}
				echo '</tbody></table>';
			}

			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-connector-health' ) ) . '">&larr; All Connectors</a></p>';
		}

		echo '</div>';
	}
}