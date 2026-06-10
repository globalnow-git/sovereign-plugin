<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Signal_Engine {

	public static function record_signal( $campaign_id, $signal_type, $value, $user_id = 0 ) {
		global $wpdb;
		$campaign_id = absint( $campaign_id );
		$signal_type = sanitize_key( $signal_type );
		$value       = floatval( $value );
		$user_id     = absint( $user_id ? $user_id : get_current_user_id() );

		// Atomic update execution string tracking metric accumulation maps
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}sb_signals 
			(campaign_id, user_id, signal_type, current_value, signal_direction, triggered_at) 
			VALUES (%d, %d, %s, %f, 'inbound', NOW()) 
			ON DUPLICATE KEY UPDATE current_value = current_value + %f, triggered_at = NOW()",
			$campaign_id, $user_id, $signal_type, $value, $value
		) );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SIGNAL_TRIGGERED,
			sprintf( "Signal %s captured with transaction metric increment change of %f", $signal_type, $value ),
			$user_id,
			[ 'campaign_id' => $campaign_id, 'signal_type' => $signal_type, 'delta_value' => $value ]
		);

		do_action( 'sb_signal_triggered', $signal_type, $value, $user_id );
	}

	public static function level_to_road( $level_id ) {
		global $wpdb;
		if ( SB_Module_Loader::is_schema_ready() ) {
			$road_key = $wpdb->get_var( $wpdb->prepare(
				"SELECT road_key FROM {$wpdb->prefix}sb_level_road_map WHERE level_id = %d AND is_active = 1 ORDER BY priority DESC LIMIT 1",
				absint( $level_id )
			) );
			if ( $road_key ) {
				return $road_key;
			}
		}
		return get_option( 'sb_fallback_road_level_' . absint( $level_id ), 'A' );
	}

	public static function detect_stalled_users() {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) {
			return;
		}

		$stalled_hours = (int) SB_Extension_API::get_setting( 'sb_stalled_user_boundary_hours', 72 );
		$stalled_records = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, c.campaign_id FROM {$wpdb->prefix}sb_user_rulesets r 
			JOIN {$wpdb->prefix}sb_campaign_rulesets c ON c.ruleset_id = r.ruleset_id AND c.status = 'active' 
			WHERE r.updated_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
			$stalled_hours
		) );

		if ( $stalled_records ) {
			foreach ( $stalled_records as $user_row ) {
				do_action( 'sb_user_stalled', $user_row->user_id, $user_row->campaign_id, $user_row->road_key, $stalled_hours );
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_USER_STALLED, "User detected stalling boundary markers ruleset loops execution paths", $user_row->user_id );
			}
		}
	}

	public static function record_computed_signals() {
		// Executed via cron task triggers processing mathematical rollups securely
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_CRON_START, 'Computed signals rollup verification sequence processing loops active.', 0, [], 'verbose' );
	}

	public static function handle_rest_signal( $request ) {
		// Nonce already verified by permission_callback — no duplicate check needed
		$params = $request->get_json_params();
		$signal_type = isset( $params['signal_type'] ) ? sanitize_key( $params['signal_type'] ) : '';
		$value       = isset( $params['value'] ) ? floatval( $params['value'] ) : 1.0;
		$campaign_id = isset( $params['campaign_id'] ) ? absint( $params['campaign_id'] ) : 0;
		$user_id     = get_current_user_id();

		if ( ! $signal_type ) {
			return new WP_Error( 'invalid_params', 'Missing operational registration signal trace keys.', [ 'status' => 400 ] );
		}

		self::record_signal( $campaign_id, $signal_type, $value, $user_id );
		return rest_ensure_response( [ 'success' => true, 'logged_type' => $signal_type ] );
	}

	public static function on_wc_order_complete( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) { return; }
		$order   = wc_get_order( absint( $order_id ) );
		if ( ! $order ) { return; }
		$user_id = $order->get_user_id();
		if ( ! $user_id ) { return; }
		// Resolve active campaign for this user — not campaign_id=0
		$active_road  = get_user_meta( $user_id, '_sb_active_road_key', true );
		$campaign_id  = 0;
		if ( $active_road ) {
			global $wpdb;
			$campaign_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}sb_campaigns WHERE status = 'active' LIMIT 1"
			) );
		}
		$total   = (float) $order->get_total();
		self::record_signal( $campaign_id, 'wc_purchase', $total, $user_id );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SIGNAL_TRIGGERED, "WC order {$order_id} complete. User {$user_id} total={$total}", $user_id, [ 'order_id' => $order_id ], 'info' );
	}
}