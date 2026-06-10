<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Many_Roads {

	const EMAIL_SEQUENCES = [
		'A' => [ 'welcome_sequence_1', 'welcome_sequence_2' ],
		'B' => [ 'upsell_path_sequence_1', 'upsell_path_sequence_2' ],
		'C' => [ 'deadline_close_sequence_1' ]
	];

	public static function enter_road( $user_id, $road_key ) {
		global $wpdb;
		$user_id  = absint( $user_id );
		$road_key = sanitize_key( $road_key );

		$previous_road = get_user_meta( $user_id, '_sb_active_road_key', true );
		update_user_meta( $user_id, '_sb_active_road_key', $road_key );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_ROAD_ENTERED,
			sprintf( "User transition execution paths mapped from segment road %s directly into %s", $previous_road, $road_key ),
			$user_id,
			[ 'previous' => $previous_road, 'current' => $road_key ]
		);

		do_action( 'sb_road_entered', $user_id, $road_key, $previous_road );
		self::queue_road_email_sequences( $user_id, $road_key );
	}

	private static function queue_road_email_sequences( $user_id, $road_key ) {
		$sequences = apply_filters( 'sb_road_email_sequence', self::EMAIL_SEQUENCES, $road_key, $user_id );
		$target_templates = $sequences[ $road_key ] ?? [];

		foreach ( $target_templates as $index => $template_key ) {
			// Leverage Action Scheduler proven pattern tracking configurations safely
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + max( 300, $index * DAY_IN_SECONDS ), // R2-008: min 5min delay on first email
					'sb_execute_outbound_payload_action',
					[
						absint( $user_id ),
						sanitize_key( $template_key ),
						sanitize_key( $road_key ),
					]  // positional args — AS requires indexed array
				);
			}
		}
	}

	public static function handle_email_open_tracking( $request ) {
		global $wpdb;
		$token = sanitize_key( $request['token'] );

		// Per-token open cap: max 5 records per hour (prevents count inflation)
		$open_cap_key   = 'sbrc_open_' . $token;
		$open_cap_count = (int) get_transient( $open_cap_key );
		if ( $open_cap_count >= 5 ) {
			// Serve pixel but don't record
			header( 'Content-Type: image/gif' );
			echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
			exit;
		}
		set_transient( $open_cap_key, $open_cap_count + 1, HOUR_IN_SECONDS );

		// R2-009: resolve user_id from tracking token
		$user_id_open = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}sb_email_events WHERE tracking_token = %s AND event_type = 'sent' LIMIT 1",
			$token
		) );
		$wpdb->insert( "{$wpdb->prefix}sb_email_events", [
			'user_id'        => $user_id_open,
			'event_type'     => 'open',
			'tracking_token' => $token,
			'occurred_at'    => current_time( 'mysql' )
		] );

		SB_Signal_Engine::record_signal( 0, 'email_opened', 1.0, $user_id_open );

		// Tracking pixel: bypass WP_REST_Server to serve binary GIF cleanly
		// Using direct header+output avoids mixing echo binary with REST JSON response
		$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		status_header( 200 );
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: ' . strlen( $gif ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		echo $gif;
		if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); }
		exit; // Intentional: binary response must terminate before WP_REST_Server JSON wrapping
	}

	public static function handle_email_click_tracking( $request ) {
		global $wpdb;
		$token      = sanitize_key( $request['token'] );

		// Per-token click cap: max 5 records per hour (prevents count inflation)
		$click_cap_key   = 'sbrc_click_' . $token;
		$click_cap_count = (int) get_transient( $click_cap_key );
		if ( $click_cap_count >= 5 ) {
			// Redirect but don't record
			$target_url_skip = esc_url_raw( base64_decode( $request['url'] ?? '' ) );
			wp_safe_redirect( $target_url_skip ?: home_url() );
			exit;
		}
		set_transient( $click_cap_key, $click_cap_count + 1, HOUR_IN_SECONDS );
		$target_url = esc_url_raw( base64_decode( $request['url'] ) );

		$wpdb->insert( "{$wpdb->prefix}sb_email_events", [
			'user_id'        => 0,
			'event_type'     => 'click',
			'tracking_token' => $token,
			'occurred_at'    => current_time( 'mysql' )
		] );

		SB_Signal_Engine::record_signal( 0, 'email_clicked', 1.0, 0 );

		// Use wp_safe_redirect then return — avoids exit; in REST callback context
		wp_safe_redirect( $target_url );
		return new WP_REST_Response( null, 302 );
	}

	public static function handle_rest_preview_email( $request ) {
		$params       = $request->get_json_params();
		$template_key = isset( $params['template_key'] ) ? sanitize_key( $params['template_key'] ) : '';
		
		global $wpdb;
		$tpl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_templates WHERE template_key = %s", $template_key ) );
		if ( ! $tpl ) {
			return new WP_Error( 'missing_template', 'Target trace template record unfound.', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [ 'subject' => $tpl->subject, 'html_body' => wpautop( $tpl->body ) ] );
	}

	public static function send_road_email( $user_id, $template_key, $road_key ) {
		global $wpdb;

		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return false;
		}

		// Load template from DB
		$tpl = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_templates WHERE template_key = %s LIMIT 1",
			sanitize_key( $template_key )
		) );
		if ( ! $tpl ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_EMAIL_FAILED, "Template not found: {$template_key}", $user_id, [], 'info' );
			return false;
		}

		// Token substitution — Fix 3
		$tracking_token = wp_generate_password( 32, false );
		$track_open_url = home_url( "/wp-json/sovereign-builder/v1/track/open/{$tracking_token}" );

		// GAP3 FIX: Pull latest form submission meta for this user and merge into token map.
		// Previously: token map was hardcoded — form submissions had no path into email templates.
		$form_meta_tokens = [];
		if ( $user_id ) {
			$latest_sub = $wpdb->get_row( $wpdb->prepare(
				"SELECT s.id FROM {$wpdb->prefix}sb_submissions s WHERE s.user_id = %d ORDER BY s.submitted_at DESC LIMIT 1",
				absint( $user_id )
			) );
			if ( $latest_sub ) {
				$meta_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->prefix}sb_submission_meta WHERE submission_id = %d",
					(int) $latest_sub->id
				) );
				foreach ( $meta_rows as $m ) {
					$token_key = '{{form.' . sanitize_key( $m->meta_key ) . '}}';
					$form_meta_tokens[ $token_key ] = esc_html( $m->meta_value );
				}
			}
		}

		$tokens = array_merge( [
			'{{first_name}}'      => get_user_meta( $user_id, 'first_name', true ) ?: $user->display_name,
			'{{display_name}}'    => $user->display_name,
			'{{site_url}}'        => home_url(),
			'{{factory_url}}'     => home_url( '/the-factory/' ),
			'{{account_url}}'     => home_url( '/my-account/' ),
			'{{unsubscribe_url}}' => home_url( '/unsubscribe/?uid=' . $user_id . '&token=' . wp_hash( 'sb_unsub_' . $user_id ) ),
			'{{road_key}}'        => sanitize_text_field( $road_key ),
		], $form_meta_tokens ); // form submission fields available as {{form.field_key}}

		$subject = str_replace( array_keys( $tokens ), array_values( $tokens ), $tpl->subject );
		$body    = str_replace( array_keys( $tokens ), array_values( $tokens ), $tpl->body );

		// HTML content-type switch — Fix 4
		$from_name  = $tpl->from_name  ?: get_option( 'sb_from_name',  get_bloginfo( 'name' ) );
		$from_email = $tpl->from_email ?: get_option( 'sb_from_email', get_option( 'admin_email' ) );
		$is_html    = ( isset( $tpl->content_type ) && $tpl->content_type === 'text/html' );

		$headers = [
			'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>',
		];

		if ( $is_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			// Embed tracking pixel in HTML body
			$body .= '<img src="' . esc_url( $track_open_url ) . '" width="1" height="1" alt="" style="display:none;" />'; // BUG4 FIX: self-closing XHTML tag
		}

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		// Log email event
		$wpdb->insert( "{$wpdb->prefix}sb_email_events", [
			'user_id'        => absint( $user_id ),
			'event_type'     => $sent ? 'sent' : 'failed',
			'tracking_token' => $tracking_token,
			'occurred_at'    => current_time( 'mysql' ),
		] );

		SB_Event_Logger::log_audit(
			$sent ? 'email_queued' : 'email_failed',
			"Email '{$template_key}' to user {$user_id}: " . ( $sent ? 'sent' : 'FAILED' ),
			$user_id,
			[ 'template' => $template_key, 'is_html' => $is_html ],
			'verbose'
		);

		return $sent;
	}

	// Hook for Action Scheduler to call send_road_email
	public static function execute_outbound_payload( $user_id, $template_key, $road_key ) {
		self::send_road_email( $user_id, $template_key, $road_key );
	}


	/**
	 * Register Action Scheduler listener for outbound email payloads.
	 * Called once from SovereignBuilder::init().
	 */
	public static function register_as_listener(): void {
		// AS listener for scheduled email delivery
		if ( function_exists( 'add_action' ) ) {
			add_action( 'sb_execute_outbound_payload_action', [ static::class, 'execute_outbound_payload' ], 10, 3 );
		}
		// Core signal/road hooks — drive journey creation and rule evaluation
		add_action( 'sb_road_entered',     [ static::class, 'on_road_entered' ],     10, 3 );
		add_action( 'sb_signal_triggered', [ static::class, 'on_signal_triggered' ], 10, 3 );
	}



}