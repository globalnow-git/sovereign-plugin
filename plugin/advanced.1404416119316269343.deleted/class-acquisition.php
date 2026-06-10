<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SB_Acquisition
 * Paid acquisition via Facebook Ads and Google Ads APIs.
 * Sovereignty: Both APIs are US-hosted. Ad creative sent — zero user PII.
 * Two HITM gates required: creative approval AND budget approval separately.
 */
class SB_Acquisition {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		add_action( 'sb_factory_run_complete', [ __CLASS__, 'on_factory_complete' ], 30, 3 );
		add_action( 'sb_ad_sync_cron', [ __CLASS__, 'sync_all_results' ] );
		add_action( 'pmpro_after_change_membership_level', [ __CLASS__, 'on_lead_signup' ], 10, 2 );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'acquisition', '1.0.0', 'SB_Acquisition' );
		}
	}

	/** Hook: auto-generate creative when factory run completes if acquisition enabled. */
	public static function on_factory_complete( $run_id, $outputs, $campaign_id ) {
		if ( ! get_post_meta( $campaign_id, '_sb_acquisition_enabled', true ) ) {
			return;
		}
		self::generate_ad_creative( $campaign_id, $run_id );
	}

	/**
	 * Gate 1: Generate ad creative from factory Layer 10 output.
	 * Queues approval — never fires to ad APIs directly.
	 */
	public static function generate_ad_creative( $campaign_id, $factory_run_id ) {
		global $wpdb;

		$run = $wpdb->get_row( $wpdb->prepare(
			"SELECT layer_outputs FROM {$wpdb->prefix}sb_factory_runs WHERE id = %d",
			absint( $factory_run_id )
		) );
		if ( ! $run ) {
			return;
		}

		$system_prompt =
			'You are a professional performance marketing copywriter. ' .
			'Based on the product analysis below, generate ad copy for Facebook and Google. ' .
			'Output ONLY valid JSON, no preamble: ' .
			'{"fb_headline":"(max 40 chars)","fb_body":"(max 125 chars)","fb_cta":"LEARN_MORE",' .
			'"google_headline":"(max 30 chars)","google_description":"(max 90 chars)","target_audience":"(brief description)"}';

		$result = SB_WP_AI_Client::call( $system_prompt, $run->layer_outputs );
		if ( is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'Ad creative generation failed: ' . $result->get_error_message(), 0, [], 'info' );
			return;
		}

		$creative = json_decode( $result, true );
		if ( ! is_array( $creative ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'Ad creative JSON parse failed.', 0, [], 'info' );
			return;
		}

		// Insert creative row
		$wpdb->insert( "{$wpdb->prefix}sb_ad_creatives", [
			'ad_campaign_id' => 0,
			'headline'       => sanitize_text_field( $creative['fb_headline'] ?? '' ),
			'body_text'      => sanitize_textarea_field( $creative['fb_body'] ?? '' ),
			'cta'            => sanitize_key( $creative['fb_cta'] ?? 'LEARN_MORE' ),
			'platform'       => 'facebook',
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql' ),
		] );
		$creative_id = $wpdb->insert_id;

		// Gate 1 HITM: creative approval
		SB_Approval_Engine::create_approval( $campaign_id, 'ad_creative', [
			'creative_id' => $creative_id,
			'creative'    => $creative,
			'note'        => 'Review ad copy for all platforms before budget approval.',
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Ad creative queued for HITM review. Creative ID: {$creative_id}", 0, [], 'info' );
	}

	/**
	 * Gate 2: After creative approved, present budget approval.
	 * Called by approval engine when approval_type = 'ad_creative' is approved.
	 */
	public static function on_creative_approved( $creative_id, $campaign_id ) {
		$daily_cap = (float) SB_Extension_API::get_setting( 'sb_acquisition_daily_cap', 50 );

		// Gate 2 HITM: budget approval
		SB_Approval_Engine::create_approval( $campaign_id, 'ad_budget', [
			'creative_id'    => $creative_id,
			'proposed_daily' => $daily_cap,
			'currency'       => 'USD',
			'note'           => 'Confirm daily budget before campaign launches. This authorises real ad spend.',
		] );
	}

	/**
	 * Create Facebook campaign — called ONLY after both gates approved.
	 * Sovereignty: Facebook Ads API is US-hosted. Ad creative only — no user PII.
	 */
	public static function create_fb_campaign( $creative_id, $daily_budget, $campaign_id ) {
		$app_id      = SB_Extension_API::get_setting( 'sb_fb_app_id', '' );
		$token       = SB_Extension_API::get_setting( 'sb_fb_access_token', '' );
		if ( empty( $app_id ) || empty( $token ) ) {
			return new WP_Error( 'no_fb_credentials', 'Facebook App ID and Access Token not configured.' );
		}

		global $wpdb;
		$creative = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_ad_creatives WHERE id = %d",
			absint( $creative_id )
		) );
		if ( ! $creative ) {
			return new WP_Error( 'creative_not_found', 'Creative record not found.' );
		}

		// Log intent before external call
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Initiating Facebook campaign creation. Creative: {$creative_id}", 0, [], 'info' );

		$ad_account_id = SB_Extension_API::get_setting( 'sb_fb_ad_account_id', '' );
		$api_url       = "https://graph.facebook.com/v18.0/act_{$ad_account_id}/campaigns";

		$response = wp_remote_post( $api_url, [
			'timeout' => 30,
			'body'    => [
				'name'             => sanitize_text_field( $creative->headline ) . ' — SB Campaign',
				'objective'        => 'LINK_CLICKS',
				'status'           => 'PAUSED',
				'daily_budget'     => (int) ( $daily_budget * 100 ), // FB uses cents
				'access_token'     => $token,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$fb_campaign_id = $body['id'] ?? '';

		// Store campaign record
		$wpdb->insert( "{$wpdb->prefix}sb_ad_campaigns", [
			'campaign_id'  => absint( $campaign_id ),
			'platform'     => 'facebook',
			'external_id'  => sanitize_text_field( $fb_campaign_id ),
			'creative_id'  => absint( $creative_id ),
			'daily_budget' => (float) $daily_budget,
			'status'       => 'active',
			'approved_at'  => current_time( 'mysql' ),
			'started_at'   => current_time( 'mysql' ),
			'created_at'   => current_time( 'mysql' ),
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Facebook campaign created. External ID: {$fb_campaign_id}", 0, [ 'external_id' => $fb_campaign_id ], 'info' );
		return $wpdb->insert_id;
	}

	/**
	 * Create Google Ads campaign — called ONLY after both gates approved.
	 * Sovereignty: Google Ads API is US-hosted. Ad creative only — no user PII.
	 */
	public static function create_google_campaign( $creative_id, $daily_budget, $campaign_id ) {
		$customer_id = SB_Extension_API::get_setting( 'sb_google_ads_id', '' );
		$token       = SB_Extension_API::get_setting( 'sb_google_ads_token', '' );
		if ( empty( $customer_id ) || empty( $token ) ) {
			return new WP_Error( 'no_google_credentials', 'Google Ads Customer ID and token not configured.' );
		}

		global $wpdb;
		$creative = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_ad_creatives WHERE id = %d",
			absint( $creative_id )
		) );
		if ( ! $creative ) {
			return new WP_Error( 'creative_not_found', 'Creative record not found.' );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Initiating Google Ads campaign creation. Creative: {$creative_id}", 0, [], 'info' );

		$response = wp_remote_post(
			"https://googleads.googleapis.com/v14/customers/{$customer_id}/campaigns:mutate",
			[
				'timeout' => 30,
				'headers' => [
					'Authorization'     => 'Bearer ' . $token,
					'Content-Type'      => 'application/json',
					'developer-token'   => SB_Extension_API::get_setting( 'sb_google_dev_token', '' ),
				],
				'body' => wp_json_encode( [
					'operations' => [ [
						'create' => [
							'name'                  => sanitize_text_field( $creative->headline ) . ' — SB',
							'status'                => 'PAUSED',
							'advertisingChannelType'=> 'SEARCH',
							'campaignBudget'        => 'customers/' . $customer_id . '/campaignBudgets/~1',
						],
					] ],
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$external_id = $body['results'][0]['resourceName'] ?? '';

		$wpdb->insert( "{$wpdb->prefix}sb_ad_campaigns", [
			'campaign_id'  => absint( $campaign_id ),
			'platform'     => 'google',
			'external_id'  => sanitize_text_field( $external_id ),
			'creative_id'  => absint( $creative_id ),
			'daily_budget' => (float) $daily_budget,
			'status'       => 'active',
			'approved_at'  => current_time( 'mysql' ),
			'started_at'   => current_time( 'mysql' ),
			'created_at'   => current_time( 'mysql' ),
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Google campaign created. External ID: {$external_id}", 0, [], 'info' );
		return $wpdb->insert_id;
	}

	/** Sync results from all active ad campaigns daily. */
	public static function sync_all_results() {
		global $wpdb;
		$campaigns = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_ad_campaigns WHERE status = 'active'"
		);
		foreach ( $campaigns as $campaign ) {
			self::sync_results( $campaign->id );
		}
	}

	public static function sync_results( $ad_campaign_id ) {
		// GAP2 FIX: Implement platform-agnostic ad result sync with platform routing.
		// Real spend/impressions/clicks fetched via platform REST APIs; zero-row guard preserved.
		global $wpdb;
		$campaign = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_ad_campaigns WHERE id = %d",
			absint( $ad_campaign_id )
		) );
		if ( ! $campaign ) {
			return;
		}

		$platform   = sanitize_key( $campaign->platform );
		$ext_id     = sanitize_text_field( $campaign->external_id );
		$spend      = 0.00;
		$impressions = 0;
		$clicks     = 0;
		$conversions = 0;
		$fetched    = false;

		if ( $platform === 'facebook' ) {
			$token   = SB_Extension_API::get_setting( 'sb_fb_access_token', '' );
			$account = SB_Extension_API::get_setting( 'sb_fb_ad_account_id', '' );
			if ( $token && $account && $ext_id ) {
				$url      = "https://graph.facebook.com/v19.0/{$ext_id}/insights?fields=spend,impressions,clicks,conversions&access_token=" . urlencode( $token );
				$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
				if ( ! is_wp_error( $response ) ) {
					$data = json_decode( wp_remote_retrieve_body( $response ), true );
					if ( ! empty( $data['data'][0] ) ) {
						$d           = $data['data'][0];
						$spend       = (float) ( $d['spend'] ?? 0 );
						$impressions = (int) ( $d['impressions'] ?? 0 );
						$clicks      = (int) ( $d['clicks'] ?? 0 );
						$conversions = (int) ( $d['conversions'][0]['value'] ?? 0 );
						$fetched     = true;
					}
				}
			}
		} elseif ( $platform === 'google' ) {
			// Google Ads requires OAuth — log warning; operator must configure credentials
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_AD_SYNC_GOOGLE_PENDING, "Google Ads sync requires OAuth setup for campaign {$ad_campaign_id}.", 0, [], 'verbose' );
		}

		if ( $fetched && ( $spend > 0 || $impressions > 0 ) ) {
			$wpdb->insert( "{$wpdb->prefix}sb_ad_results", [
				'ad_campaign_id' => absint( $ad_campaign_id ),
				'spend_usd'      => $spend,
				'impressions'    => $impressions,
				'clicks'         => $clicks,
				'conversions'    => $conversions,
				'snapshot_at'    => current_time( 'mysql' ),
			] );
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_AD_SYNC_RESULTS_SAVED, "Ad results synced for campaign {$ad_campaign_id}: spend={$spend}", 0, [ 'ad_campaign_id' => $ad_campaign_id ], 'info' );
		} else {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_AD_SYNC_NO_DATA, "Ad sync: no billable data for campaign {$ad_campaign_id} on platform {$platform}.", 0, [], 'verbose' );
		}
	}

	/** When a user signs up via PMPro — log conversion against active ad campaign. */
	public static function on_lead_signup( $level_id, $user_id ) {
		if ( ! $level_id ) {
			return;
		}
		global $wpdb;
		$campaign = $wpdb->get_row(
			"SELECT id FROM {$wpdb->prefix}sb_ad_campaigns WHERE status = 'active' ORDER BY started_at DESC LIMIT 1"
		);
		if ( $campaign ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}sb_ad_results SET conversions = conversions + 1
				 WHERE ad_campaign_id = %d ORDER BY snapshot_at DESC LIMIT 1",
				$campaign->id
			) );
			SB_Signal_Engine::record_signal( 0, 'ad_conversion', 1.0, absint( $user_id ) );
		}
	}
}
SB_Acquisition::init();