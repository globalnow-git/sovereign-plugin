<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SB_Campaign_Analyst — AI analysis of aggregate campaign signals.
 * Reads local tables only. Zero PII sent to AI. HITM on every recommendation.
 */
class SB_Campaign_Analyst {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		add_action( 'sb_analyst_cron',     [ __CLASS__, 'run_analysis' ] );
		add_filter( 'sb_admin_menu_items', [ __CLASS__, 'add_menu_items' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'campaign-analyst', '1.0.0', 'SB_Campaign_Analyst' );
		}
	}

	// ── Core ────────────────────────────────────────────────────────────────

	public static function run_analysis() {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		$campaigns = $wpdb->get_results( "SELECT id FROM {$wpdb->prefix}sb_campaigns WHERE status = 'active'" );
		foreach ( $campaigns as $campaign ) {
			self::analyse_campaign( $campaign->id );
		}
	}

	public static function analyse_campaign( $campaign_id, $days = 30 ) {
		global $wpdb;
		$campaign_id = absint( $campaign_id );

		$signals = self::gather_signals( $campaign_id, $days );

		$wpdb->insert( "{$wpdb->prefix}sb_analyst_reports", [
			'campaign_id'  => $campaign_id,
			'signals_json' => wp_json_encode( $signals ),
			'days_analyzed'=> $days,
			'created_at'   => current_time( 'mysql' ),
		] );
		$report_id = $wpdb->insert_id;

		$recommendations = self::generate_recommendations( $signals, $campaign_id );
		if ( is_array( $recommendations ) && ! empty( $recommendations ) ) {
			$wpdb->update( "{$wpdb->prefix}sb_analyst_reports",
				[ 'recommendations_json' => wp_json_encode( $recommendations ) ],
				[ 'id' => $report_id ]
			);
			self::queue_recommendations( $recommendations, $campaign_id );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Analyst report generated for campaign {$campaign_id}. Report: {$report_id}", 0, [], 'info' );
	}

	/**
	 * Gather aggregate metrics only — zero PII.
	 */
	public static function gather_signals( $campaign_id, $days = 30 ) {
		global $wpdb;
		$since = date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$cid   = absint( $campaign_id );

		$sent   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'sent'  AND occurred_at >= %s", $cid, $since ) );
		$opened = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'open'  AND occurred_at >= %s", $cid, $since ) );
		$clicked= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'click' AND occurred_at >= %s", $cid, $since ) );

		$funnel_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND occurred_at >= %s", $cid, $since ) );
		$stalled       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND event_type = 'user_stalled' AND occurred_at >= %s", $cid, $since ) );
		$conversions   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(conversions) FROM {$wpdb->prefix}sb_traffic_snapshots WHERE campaign_id = %d AND snapshot_at >= %s", $cid, $since ) );
		$switches      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_ruleset_switches WHERE campaign_id = %d AND switched_at >= %s", $cid, $since ) );

		$ad_spend = 0.00;
		$ad_conversions = 0;
		if ( class_exists( 'SB_Acquisition' ) ) {
			$ad_spend       = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(r.spend_usd) FROM {$wpdb->prefix}sb_ad_results r JOIN {$wpdb->prefix}sb_ad_campaigns ac ON ac.id = r.ad_campaign_id WHERE ac.campaign_id = %d AND r.snapshot_at >= %s", $cid, $since ) );
			$ad_conversions = (int)  $wpdb->get_var( $wpdb->prepare( "SELECT SUM(r.conversions) FROM {$wpdb->prefix}sb_ad_results r JOIN {$wpdb->prefix}sb_ad_campaigns ac ON ac.id = r.ad_campaign_id WHERE ac.campaign_id = %d AND r.snapshot_at >= %s", $cid, $since ) );
		}

		return [
			'campaign_id'     => $cid,
			'days'            => $days,
			'email_sent'      => $sent,
			'email_open_rate' => $sent > 0 ? round( $opened / $sent, 4 ) : 0,
			'email_click_rate'=> $sent > 0 ? round( $clicked / $sent, 4 ) : 0,
			'funnel_events'   => $funnel_events,
			'stalled_users'   => $stalled,
			'conversions'     => (int) $conversions,
			'ruleset_switches'=> $switches,
			'ad_spend_usd'    => $ad_spend,
			'ad_conversions'  => $ad_conversions,
		];
	}

	/**
	 * Send aggregate signals to AI. Returns structured recommendations.
	 * No PII in payload — counts and rates only.
	 */
	public static function generate_recommendations( array $signals, $campaign_id ) {
		$prompt =
			'You are a data-driven marketing analyst. Analyse the aggregate campaign metrics below. ' .
			'Identify the 3 highest-impact improvements. ' .
			'Output ONLY a valid JSON array — no preamble, no markdown: ' .
			'[{"action_type":"adjust_budget|pause_ad|change_sequence|switch_ruleset|test_subject_line|increase_posting_frequency",' .
			'"target":"(what to change)","rationale":"(why, 1-2 sentences)","expected_impact":"(brief expected result)","confidence":0-100}]';

		$result = SB_WP_AI_Client::call( $prompt, wp_json_encode( $signals ) );
		if ( is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'Analyst AI call failed: ' . $result->get_error_message(), 0, [], 'info' );
			return [];
		}

		$clean = preg_replace( '/^```json\s*|```\s*$/m', '', $result );
		$recs = json_decode( $clean, true );
		if ( ! is_array( $recs ) ) {
			return [];
		}
		return $recs;
	}

	/**
	 * Register campaign analyst admin menu items.
	 */
	public static function add_menu_items( array $items ): array {
		return $items;
	}

}
