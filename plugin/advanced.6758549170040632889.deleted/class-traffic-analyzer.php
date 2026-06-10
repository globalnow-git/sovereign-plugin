<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SB_Traffic_Analyzer {

	public static function init() {
		add_action( 'sb_modules_register',    [ __CLASS__, 'self_register' ] );
		add_action( 'sb_road_entered',        [ __CLASS__, 'record_road_event' ], 10, 3 );
		add_action( 'sb_signal_triggered',    [ __CLASS__, 'record_signal_event' ], 10, 3 );
		add_action( 'sb_user_stalled',        [ __CLASS__, 'record_stalled' ], 10, 4 );
		add_action( 'sb_many_roads_cron',     [ __CLASS__, 'take_snapshot' ] );
		add_action( 'rest_api_init',          [ __CLASS__, 'register_routes' ] );
		add_filter( 'sb_admin_menu_items',    [ __CLASS__, 'add_menu_items' ] );
		add_filter( 'sb_dashboard_stat_cards',[ __CLASS__, 'add_stat_cards' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'traffic-analyzer', '1.0.0', 'SB_Traffic_Analyzer' );
		}
	}

	// ── Event recording ─────────────────────────────────────────────────────

	public static function record_road_event( $user_id, $road_key, $previous_road ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		$campaign_id = self::get_active_campaign( $user_id );
		$wpdb->insert( "{$wpdb->prefix}sb_funnel_events", [
			'user_id'     => absint( $user_id ),
			'campaign_id' => $campaign_id,
			'road_key'    => sanitize_key( $road_key ),
			'event_type'  => 'road_entered',
			'meta_json'   => wp_json_encode( [ 'from' => $previous_road ] ),
			'occurred_at' => current_time( 'mysql' ),
		] );
	}

	public static function record_signal_event( $signal_type, $value, $user_id ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		$campaign_id = self::get_active_campaign( $user_id );
		$wpdb->insert( "{$wpdb->prefix}sb_funnel_events", [
			'user_id'     => absint( $user_id ),
			'campaign_id' => $campaign_id,
			'road_key'    => '',
			'event_type'  => 'signal_fired',
			'meta_json'   => wp_json_encode( [ 'type' => $signal_type, 'value' => $value ] ),
			'occurred_at' => current_time( 'mysql' ),
		] );
	}

	public static function record_stalled( $user_id, $campaign_id, $road_key, $hours_idle ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		$wpdb->insert( "{$wpdb->prefix}sb_funnel_events", [
			'user_id'     => absint( $user_id ),
			'campaign_id' => absint( $campaign_id ),
			'road_key'    => sanitize_key( $road_key ),
			'event_type'  => 'user_stalled',
			'meta_json'   => wp_json_encode( [ 'hours_idle' => $hours_idle ] ),
			'occurred_at' => current_time( 'mysql' ),
		] );
	}

	// ── Snapshot ────────────────────────────────────────────────────────────

	public static function take_snapshot() {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		$retention = (int) SB_Extension_API::get_setting( 'sb_log_retention_days', 30 );
		$campaigns = $wpdb->get_col( "SELECT DISTINCT id FROM {$wpdb->prefix}sb_campaigns WHERE status = 'active'" );

		foreach ( $campaigns as $campaign_id ) {
			$roads = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT road_key FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND road_key != '' AND occurred_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
				$campaign_id
			) );
			foreach ( $roads as $road ) {
				$users   = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND road_key = %s AND event_type = 'road_entered' AND occurred_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
					$campaign_id, $road
				) );
				$signals = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND road_key = %s AND event_type = 'signal_fired' AND occurred_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
					$campaign_id, $road
				) );
				$sent    = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'sent' AND occurred_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
					$campaign_id
				) );
				$opened  = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'open' AND occurred_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
					$campaign_id
				) );
				$wpdb->insert( "{$wpdb->prefix}sb_traffic_snapshots", [
					'campaign_id'   => absint( $campaign_id ),
					'road_key'      => sanitize_key( $road ),
					'users_active'  => $users,
					'signals_fired' => $signals,
					'emails_sent'   => $sent,
					'emails_opened' => $opened,
					'conversions'   => 0,
					'snapshot_at'   => current_time( 'mysql' ),
				] );
			}
		}

		// Prune old funnel events
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}sb_funnel_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$retention
		) );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_CRON_START, 'Traffic snapshot taken.', 0, [], 'verbose' );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	private static function get_active_campaign( $user_id ) {
		global $wpdb;
		$road = get_user_meta( absint( $user_id ), '_sb_active_road_key', true );
		if ( ! $road ) { return 0; }
		return (int) $wpdb->get_var(
"SELECT id FROM {$wpdb->prefix}sb_campaigns WHERE status = 'active' LIMIT 1" // No user input — prepare() not required
		);
	}

	// ── Admin screens ───────────────────────────────────────────────────────

	public static function add_menu_items( $items ) {
		$items[] = [ 'title' => 'Traffic', 'menu_title' => 'Traffic', 'capability' => 'manage_sovereign_traffic', 'slug' => 'sb-traffic', 'callback' => [ __CLASS__, 'render_traffic_screen' ] ];
		return $items;
	}

	public static function add_stat_cards( $cards ) {
		global $wpdb;
		$active = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sb_journeys WHERE status = 'active'" );
		$cards[] = [ 'label' => 'Active journeys', 'value' => (int) $active, 'color' => '#2271b1' ];
		return $cards;
	}

	public static function render_traffic_screen() {
		global $wpdb;
		echo '<div class="wrap sb-wrap"><h1>Traffic Analyzer</h1>';

		// Funnel overview
		echo '<h2>Funnel — Last 7 days</h2>';
		$funnel = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; $wpdb->prefix is safe
			"SELECT road_key, COUNT(DISTINCT user_id) AS users FROM {$wpdb->prefix}sb_funnel_events WHERE event_type = 'road_entered' AND occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY road_key ORDER BY road_key"
		);
		if ( $funnel ) {
			echo '<table class="widefat striped" style="max-width:500px"><thead><tr><th>Road</th><th>Users entered</th></tr></thead><tbody>';
			$prev = null;
			foreach ( $funnel as $row ) {
				$drop = ( $prev && $prev->users > 0 ) ? round( ( 1 - $row->users / $prev->users ) * 100 ) . '% drop' : '';
				echo '<tr><td><strong>Road ' . esc_html( strtoupper( $row->road_key ) ) . '</strong></td><td>' . absint( $row->users ) . ' <small style="color:#888">' . esc_html( $drop ) . '</small></td></tr>';
				$prev = $row;
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No funnel data yet. Users entering roads will appear here.</p>';
		}

		// Email performance
		echo '<h2>Email performance — Last 30 days</h2>';
		$sent   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE event_type = 'sent'   AND occurred_at > DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		$opened = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE event_type = 'open'   AND occurred_at > DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		$clicked= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE event_type = 'click'  AND occurred_at > DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		$open_r = $sent > 0 ? round( $opened / $sent * 100, 1 ) : 0;
		$click_r= $sent > 0 ? round( $clicked / $sent * 100, 1 ) : 0;
		echo '<div class="sb-three-col">';
		echo '<div class="sb-stat-card"><span class="sb-stat-value">' . $sent . '</span><span class="sb-stat-label">Sent</span></div>';
		echo '<div class="sb-stat-card"><span class="sb-stat-value">' . $open_r . '%</span><span class="sb-stat-label">Open rate</span></div>';
		echo '<div class="sb-stat-card"><span class="sb-stat-value">' . $click_r . '%</span><span class="sb-stat-label">Click rate</span></div>';
		echo '</div>';

		// Stalled users
		echo '<h2>Stalled users</h2>';
		$stalled = $wpdb->get_results(
			"SELECT fe.user_id, fe.road_key, fe.meta_json, fe.occurred_at, u.user_email
			 FROM {$wpdb->prefix}sb_funnel_events fe LEFT JOIN {$wpdb->users} u ON u.ID = fe.user_id
			 WHERE fe.event_type = 'user_stalled' ORDER BY fe.occurred_at DESC LIMIT 50"
		);
		if ( $stalled ) {
			echo '<table class="widefat striped"><thead><tr><th>User</th><th>Road</th><th>Idle (hours)</th><th>Detected</th></tr></thead><tbody>';
			foreach ( $stalled as $s ) {
				$meta  = json_decode( $s->meta_json, true );
				$hours = $meta['hours_idle'] ?? '—';
				echo '<tr><td>' . esc_html( $s->user_email ?: $s->user_id ) . '</td><td>' . esc_html( $s->road_key ) . '</td><td>' . esc_html( $hours ) . '</td><td>' . esc_html( $s->occurred_at ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No stalled users detected.</p>';
		}

		// Export
		echo '<p style="margin-top:20px"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=sb_export_traffic_csv' ), 'sb_export_traffic' ) ) . '">Export CSV</a></p>';
		echo '</div>';
	}

	// ── REST ────────────────────────────────────────────────────────────────

	public static function register_routes() {
		$cap = fn() => current_user_can( 'manage_sovereign_traffic' );
		register_rest_route( 'sovereign-builder/v1', '/traffic/(?P<campaign_id>\d+)', [
			'methods' => 'GET', 'permission_callback' => $cap,
			'callback' => function( $r ) {
				global $wpdb;
				$cid = absint( $r['campaign_id'] );
				return rest_ensure_response( [
					'funnel'    => $wpdb->get_results( $wpdb->prepare( "SELECT road_key, COUNT(DISTINCT user_id) AS users FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND event_type = 'road_entered' GROUP BY road_key", $cid ) ),
					'signals'   => $wpdb->get_results( $wpdb->prepare( "SELECT event_type, COUNT(*) AS count FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d GROUP BY event_type", $cid ) ),
					'stalled'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND event_type = 'user_stalled'", $cid ) ),
				] );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/traffic/snapshots', [
			'methods' => 'GET', 'permission_callback' => $cap,
			'callback' => fn() => rest_ensure_response( ( function() { global $wpdb; return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_traffic_snapshots ORDER BY snapshot_at DESC LIMIT 200" ); } )() ),
		] );
	}
}
SB_Traffic_Analyzer::init();