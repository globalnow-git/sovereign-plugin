<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Installer {

	const DB_VERSION_OPTION = 'sb_db_version';
	const DB_VERSION        = '2.3.0';

	// DEFECT-005 fix: single source of truth for all cron hooks
	// CRON_SCHEDULES is the single source of truth for all SB cron hooks and their recurrences.
	// CRON_HOOKS must always equal array_keys( CRON_SCHEDULES ).
	// schedule_cron() and run_on_deactivation/uninstall both derive their hook lists from here.
	const CRON_SCHEDULES = [
		'sb_check_signals_cron'     => 'hourly',
		'sb_many_roads_cron'        => 'twicedaily',
		'sb_telemetry_buffer_flush' => 'every_5_minutes',
		'sb_analyst_cron'           => 'weekly',
		'sb_ad_sync_cron'           => 'daily',
		'sb_debug_health_check'     => 'daily',
		'sb_connector_retry_cron'   => 'every_15_minutes',
		'sb_perf_snapshot_cron'     => 'daily',
		'sb_daily_license_ping'     => 'daily',
	];

	// CRON_HOOKS: kept as a flat list for backward compat with run_on_deactivation/uninstall.
	// Must match array_keys( CRON_SCHEDULES ) above.
	const CRON_HOOKS = [
		'sb_check_signals_cron',
		'sb_many_roads_cron',
		'sb_telemetry_buffer_flush',
		'sb_analyst_cron',
		'sb_ad_sync_cron',
		'sb_debug_health_check',
		'sb_connector_retry_cron',
		'sb_perf_snapshot_cron',
		'sb_daily_license_ping',  // previously missing — would not be cleared on deactivation
	];

	public static function run_on_activation() {
		global $wpdb;
		self::create_tables();
		self::create_capabilities();
		self::schedule_cron();
		self::seed_signal_definitions();
		// ASK5.5 regulated workflow infrastructure — guarded for load-order safety
		$charset_collate = $wpdb->get_charset_collate();
		if ( function_exists( 'sb_55_maybe_alter_blueprints' ) )   { sb_55_maybe_alter_blueprints( $wpdb->prefix ); }
		if ( function_exists( 'sb_55_phase_a_create_tables' ) )    { sb_55_phase_a_create_tables( $charset_collate, $wpdb->prefix ); }
		if ( function_exists( 'sb_55_phase_b_create_tables' ) )    { sb_55_phase_b_create_tables( $charset_collate, $wpdb->prefix ); }
		if ( function_exists( 'sb_55_seed_dual_control_policies' ) ) { sb_55_seed_dual_control_policies( $wpdb->prefix ); }
		if ( function_exists( 'sb_55_seed_kynvaric_caps' ) )       { sb_55_seed_kynvaric_caps(); }
		// Materialize runtime map for any pre-existing active blueprints
		if ( class_exists( 'SBBuildMapMaterializer' ) ) {
			SBBuildMapMaterializer::run_all();
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		// Post-activation health check — logged to audit, surfaces issues without blocking
		$health = self::verify_environment();
		if ( ! empty( $health['failures'] ) ) {
			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_REPAIR_SYSTEM_RAN,
				'Activation verify_environment found issues: ' . implode( '; ', $health['failures'] ),
				0, $health, 'warning'
			);
		}
	}

	/**
	 * Plugin deactivation — clears all scheduled cron hooks.
	 * Tables and data are preserved; deactivation is non-destructive.
	 * To remove all data use uninstall (delete plugin from WP admin).
	 */
	public static function run_on_deactivation(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// Clear ASK5.5 build map materializer state (non-destructive — rows preserved)
		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_PLUGIN_DEACTIVATED,
			'Sovereign Builder deactivated. Cron hooks cleared. Data preserved.',
			get_current_user_id(),
			[],
			'info'
		);
	}

	public static function maybe_update() {
		global $wpdb; // Required for $wpdb->prefix and ASK5.5 migration calls below
		static $checked = false;
		if ( $checked ) { return; }
		$checked   = true;
		$installed = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			self::create_capabilities();
			self::schedule_cron();
			self::seed_signal_definitions();
			// ASK5.5 migrations — guarded for load-order safety
			$charset_collate = $wpdb->get_charset_collate();
			if ( function_exists( 'sb_55_maybe_alter_blueprints' ) )   { sb_55_maybe_alter_blueprints( $wpdb->prefix ); }
			if ( function_exists( 'sb_55_phase_a_create_tables' ) )    { sb_55_phase_a_create_tables( $charset_collate, $wpdb->prefix ); }
			if ( function_exists( 'sb_55_phase_b_create_tables' ) )    { sb_55_phase_b_create_tables( $charset_collate, $wpdb->prefix ); }
			if ( function_exists( 'sb_55_seed_dual_control_policies' ) ) { sb_55_seed_dual_control_policies( $wpdb->prefix ); }
			if ( function_exists( 'sb_55_seed_kynvaric_caps' ) )       { sb_55_seed_kynvaric_caps(); }
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix = $wpdb->prefix;

		// ── GROUP A-K: All Ask4 tables (preserved) ────────────────────────
		$sql = "CREATE TABLE {$prefix}sb_campaigns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  status varchar(50) NOT NULL DEFAULT 'active',
  team_id bigint(20) unsigned DEFAULT 0,
  entity_type varchar(50) DEFAULT 'campaign',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) $charset_collate;

CREATE TABLE {$prefix}sb_signals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  signal_type varchar(100) NOT NULL DEFAULT '',
  current_value decimal(10,4) NOT NULL DEFAULT 0.0000,
  signal_direction varchar(10) DEFAULT 'inbound',
  triggered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_signal_unique (campaign_id, user_id, signal_type),
  KEY idx_signal_type (signal_type)
) $charset_collate;

CREATE TABLE {$prefix}sb_roads (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  road_key char(1) NOT NULL DEFAULT 'A',
  parent_road_id bigint(20) unsigned DEFAULT 0,
  label varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) $charset_collate;

CREATE TABLE {$prefix}sb_factory_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(50) NOT NULL DEFAULT 'pending',
  progress tinyint(3) unsigned NOT NULL DEFAULT 0,
  input_text longtext,
  prompt_input longtext,
  layer_outputs longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_campaign (campaign_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_approvals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  approval_type varchar(100) NOT NULL DEFAULT 'factory_output',
  payload longtext,
  status varchar(50) NOT NULL DEFAULT 'pending',
  operator_note longtext,
  reviewed_by bigint(20) unsigned DEFAULT 0,
  reviewed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_type (approval_type)
) $charset_collate;

CREATE TABLE {$prefix}sb_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(100) NOT NULL DEFAULT '',
  message longtext,
  log_level varchar(20) DEFAULT 'info',
  context longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_log_level (log_level),
  KEY idx_action (action),
  KEY idx_created (created_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_artifacts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  file_path varchar(500) NOT NULL DEFAULT '',
  file_type varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) $charset_collate;

CREATE TABLE {$prefix}sb_templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_key varchar(100) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext,
  reply_to varchar(255) DEFAULT '',
  preview_text varchar(255) DEFAULT '',
  unsubscribe_url varchar(500) DEFAULT '',
  content_type varchar(50) DEFAULT 'text/plain',
  from_name varchar(255) DEFAULT '',
  from_email varchar(255) DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_template_key (template_key)
) $charset_collate;

CREATE TABLE {$prefix}sb_operator_notes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  target_id bigint(20) unsigned NOT NULL DEFAULT 0,
  target_type varchar(100) NOT NULL DEFAULT 'campaign',
  note longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) $charset_collate;

CREATE TABLE {$prefix}sb_scenarios (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned DEFAULT 0,
  factory_run_id bigint(20) unsigned DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  idea_input longtext,
  blueprint_json longtext,
  road_strategy longtext,
  status varchar(20) DEFAULT 'draft',
  created_by bigint(20) unsigned DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_blueprint_steps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scenario_id bigint(20) unsigned NOT NULL DEFAULT 0,
  step_order tinyint(3) unsigned DEFAULT 0,
  step_type varchar(50) DEFAULT '',
  step_label varchar(255) DEFAULT '',
  config_json longtext,
  status varchar(20) DEFAULT 'pending',
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_scenario (scenario_id),
  KEY idx_type (step_type)
) $charset_collate;

CREATE TABLE {$prefix}sb_journeys (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  scenario_id bigint(20) unsigned DEFAULT 0,
  road_key char(1) DEFAULT '',
  current_step bigint(20) unsigned DEFAULT 0,
  status varchar(20) DEFAULT 'active',
  started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_scenario (scenario_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_journey_steps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  journey_id bigint(20) unsigned NOT NULL DEFAULT 0,
  step_type varchar(50) DEFAULT '',
  channel varchar(50) DEFAULT 'email',
  template_key varchar(100) DEFAULT '',
  payload_json longtext,
  status varchar(20) DEFAULT 'queued',
  scheduled_at datetime DEFAULT NULL,
  fired_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_journey (journey_id),
  KEY idx_channel (channel),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_channel_actions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  road_key char(1) DEFAULT '',
  channel varchar(50) DEFAULT 'email',
  action_type varchar(50) DEFAULT '',
  label varchar(255) DEFAULT '',
  delay_days tinyint(3) unsigned DEFAULT 0,
  template_key varchar(100) DEFAULT '',
  config_json longtext,
  is_active tinyint(1) DEFAULT 1,
  is_deadline tinyint(1) DEFAULT 0,
  is_prelaunch tinyint(1) DEFAULT 0,
  generates_signal varchar(100) DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_road_channel (road_key, channel),
  KEY idx_active (is_active)
) $charset_collate;

CREATE TABLE {$prefix}sb_v2_agents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agent_slug varchar(100) NOT NULL DEFAULT '',
  agent_name varchar(255) NOT NULL DEFAULT '',
  system_instruction longtext NOT NULL,
  model_routing varchar(100) DEFAULT 'claude-sonnet-4-20250514',
  temperature decimal(3,2) DEFAULT 0.20,
  max_tokens int(10) unsigned DEFAULT 8192,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_agent_slug (agent_slug)
) $charset_collate;

CREATE TABLE {$prefix}sb_v2_pipeline_configs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pipeline_slug varchar(100) NOT NULL DEFAULT 'default',
  step_order tinyint(3) unsigned DEFAULT 0,
  agent_slug varchar(100) NOT NULL DEFAULT '',
  step_label varchar(255) DEFAULT '',
  system_instruction longtext,
  is_required tinyint(1) DEFAULT 1,
  PRIMARY KEY  (id),
  KEY idx_pipeline (pipeline_slug),
  KEY idx_step_order (step_order)
) $charset_collate;

CREATE TABLE {$prefix}sb_v2_signal_rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rule_slug varchar(100) NOT NULL DEFAULT '',
  journey_id bigint(20) unsigned DEFAULT 0,
  scenario_id bigint(20) unsigned DEFAULT 0,
  logical_operator varchar(10) DEFAULT 'AND',
  conditions_json longtext NOT NULL,
  time_window_hours int(10) unsigned DEFAULT 0,
  action_target_type varchar(50) DEFAULT 'advance_journey',
  action_target_id varchar(100) DEFAULT '',
  is_active tinyint(1) DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_rule_slug (rule_slug),
  KEY idx_journey_scenario (journey_id, scenario_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_design_tokens (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned DEFAULT 0,
  token_key varchar(100) NOT NULL DEFAULT '',
  token_value varchar(500) DEFAULT '',
  token_type varchar(50) DEFAULT 'color',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_key (token_key)
) $charset_collate;

CREATE TABLE {$prefix}sb_level_road_map (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pmpro_level_id int(10) unsigned DEFAULT 0,
  road_key char(1) DEFAULT 'A',
  campaign_id bigint(20) unsigned DEFAULT 0,
  PRIMARY KEY  (id),
  KEY idx_level (pmpro_level_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_settings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  setting_key varchar(100) NOT NULL DEFAULT '',
  setting_value longtext,
  setting_type varchar(50) DEFAULT 'string',
  setting_group varchar(50) DEFAULT 'general',
  description varchar(255) DEFAULT '',
  options_json longtext,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_setting_key (setting_key),
  KEY idx_group (setting_group)
) $charset_collate;

CREATE TABLE {$prefix}sb_ruleset_prompts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  persona_slug varchar(100) NOT NULL DEFAULT '',
  persona_name varchar(255) NOT NULL DEFAULT '',
  methodology_summary text,
  pipeline_slug varchar(100) DEFAULT 'default',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (persona_slug)
) $charset_collate;

CREATE TABLE {$prefix}sb_rulesets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  version varchar(20) DEFAULT '1.0.0',
  name varchar(255) NOT NULL DEFAULT '',
  config_json longtext,
  domain_key varchar(100) DEFAULT '',
  status varchar(20) DEFAULT 'draft',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug_version (slug, version),
  KEY idx_status (status),
  KEY idx_domain (domain_key)
) $charset_collate;

CREATE TABLE {$prefix}sb_ruleset_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ruleset_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_type varchar(50) DEFAULT '',
  item_key varchar(100) DEFAULT '',
  config_json longtext,
  applied_at datetime DEFAULT NULL,
  status varchar(20) DEFAULT 'pending',
  PRIMARY KEY  (id),
  KEY idx_ruleset (ruleset_id),
  KEY idx_type_key (item_type, item_key)
) $charset_collate;

CREATE TABLE {$prefix}sb_campaign_rulesets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ruleset_id bigint(20) unsigned NOT NULL DEFAULT 0,
  priority tinyint(3) unsigned DEFAULT 0,
  overlap_strategy varchar(20) DEFAULT 'priority',
  status varchar(20) DEFAULT 'active',
  activated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_ruleset (ruleset_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_performance_thresholds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  metric_type varchar(50) DEFAULT 'signal_velocity',
  expected_value decimal(10,4) DEFAULT 0.0000,
  window_hours int(10) unsigned DEFAULT 72,
  fallback_ruleset_id bigint(20) unsigned DEFAULT 0,
  action_type varchar(50) DEFAULT 'switch_ruleset',
  is_active tinyint(1) DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_ruleset_switches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  from_ruleset_id bigint(20) unsigned DEFAULT 0,
  to_ruleset_id bigint(20) unsigned NOT NULL DEFAULT 0,
  trigger_metric varchar(50) DEFAULT '',
  trigger_value decimal(10,4) DEFAULT 0.0000,
  switched_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_switched (switched_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_user_rulesets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ruleset_id bigint(20) unsigned NOT NULL DEFAULT 0,
  road_key char(1) DEFAULT '',
  seq_index smallint(5) unsigned DEFAULT 0,
  score_value decimal(10,2) DEFAULT 0.00,
  state_expires_at datetime DEFAULT NULL,
  entered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_ruleset (ruleset_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_traffic_snapshots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  road_key char(1) DEFAULT '',
  users_active int(10) unsigned DEFAULT 0,
  signals_fired int(10) unsigned DEFAULT 0,
  emails_sent int(10) unsigned DEFAULT 0,
  emails_opened int(10) unsigned DEFAULT 0,
  conversions int(10) unsigned DEFAULT 0,
  snapshot_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign_road (campaign_id, road_key),
  KEY idx_snapshot_at (snapshot_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_funnel_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned DEFAULT 0,
  road_key char(1) DEFAULT '',
  event_type varchar(50) NOT NULL DEFAULT '',
  meta_json longtext,
  occurred_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_campaign (campaign_id),
  KEY idx_event_type (event_type),
  KEY idx_occurred (occurred_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_email_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned DEFAULT 0,
  road_key char(1) DEFAULT '',
  seq_index smallint(5) unsigned DEFAULT 0,
  event_type varchar(20) DEFAULT 'sent',
  tracking_token varchar(64) DEFAULT '',
  occurred_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_token (tracking_token),
  KEY idx_event_type (event_type)
) $charset_collate;

CREATE TABLE {$prefix}sb_social_posts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  approval_id bigint(20) unsigned DEFAULT 0,
  wp_post_id bigint(20) unsigned DEFAULT 0,
  platform varchar(50) DEFAULT '',
  content longtext,
  image_id bigint(20) unsigned DEFAULT 0,
  social_plugin varchar(50) DEFAULT '',
  status varchar(20) DEFAULT 'draft',
  scheduled_at datetime DEFAULT NULL,
  published_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_approval (approval_id),
  KEY idx_status (status),
  KEY idx_platform (platform)
) $charset_collate;

CREATE TABLE {$prefix}sb_image_briefs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned DEFAULT 0,
  factory_run_id bigint(20) unsigned DEFAULT 0,
  brief_json longtext,
  status varchar(20) DEFAULT 'pending',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_generated_images (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  brief_id bigint(20) unsigned DEFAULT 0,
  attachment_id bigint(20) unsigned DEFAULT 0,
  provider varchar(50) DEFAULT '',
  prompt_used longtext,
  cost_usd decimal(8,4) DEFAULT 0.0000,
  status varchar(20) DEFAULT 'generated',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_brief (brief_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_ad_campaigns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned DEFAULT 0,
  platform varchar(20) DEFAULT 'facebook',
  external_id varchar(100) DEFAULT '',
  creative_id bigint(20) unsigned DEFAULT 0,
  daily_budget decimal(10,2) DEFAULT 0.00,
  status varchar(20) DEFAULT 'draft',
  approved_at datetime DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_platform (platform),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_ad_creatives (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ad_campaign_id bigint(20) unsigned DEFAULT 0,
  headline varchar(255) DEFAULT '',
  body_text longtext,
  cta varchar(50) DEFAULT '',
  image_id bigint(20) unsigned DEFAULT 0,
  platform varchar(20) DEFAULT '',
  status varchar(20) DEFAULT 'pending',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ad_campaign (ad_campaign_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_ad_results (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ad_campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  spend_usd decimal(10,2) DEFAULT 0.00,
  impressions int(10) unsigned DEFAULT 0,
  clicks int(10) unsigned DEFAULT 0,
  conversions int(10) unsigned DEFAULT 0,
  snapshot_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ad_campaign (ad_campaign_id),
  KEY idx_snapshot_at (snapshot_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_podcast_episodes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  description longtext,
  audio_path varchar(500) DEFAULT '',
  audio_url varchar(500) DEFAULT '',
  duration_seconds int(10) unsigned DEFAULT 0,
  file_size_bytes bigint(20) unsigned DEFAULT 0,
  season tinyint(3) unsigned DEFAULT 1,
  episode_number smallint(5) unsigned DEFAULT 0,
  factory_run_id bigint(20) unsigned DEFAULT 0,
  status varchar(20) DEFAULT 'draft',
  published_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_published (published_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_podcast_stats (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  episode_id bigint(20) unsigned NOT NULL DEFAULT 0,
  downloads int(10) unsigned DEFAULT 0,
  snapshot_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_episode (episode_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_analyst_reports (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned DEFAULT 0,
  signals_json longtext,
  recommendations_json longtext,
  days_analyzed tinyint(3) unsigned DEFAULT 30,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_created (created_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_signal_definitions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  signal_type varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  capture_method varchar(50) DEFAULT 'hook',
  is_computed tinyint(1) DEFAULT 0,
  is_negative tinyint(1) DEFAULT 0,
  description text,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_signal_type (signal_type),
  KEY idx_capture (capture_method)
) $charset_collate;

CREATE TABLE {$prefix}sb_marketer_signals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ruleset_prompt_id bigint(20) unsigned NOT NULL DEFAULT 0,
  signal_type varchar(100) NOT NULL DEFAULT '',
  weight decimal(4,2) DEFAULT 0.10,
  expected_value decimal(10,4) DEFAULT 0.0000,
  expected_window_days tinyint(3) unsigned DEFAULT 7,
  tolerance_pct decimal(5,2) DEFAULT 20.00,
  is_primary tinyint(1) DEFAULT 0,
  direction varchar(15) DEFAULT 'higher_better',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_prompt (ruleset_prompt_id),
  KEY idx_signal (signal_type)
) $charset_collate;

CREATE TABLE {$prefix}sb_ruleset_performance (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ruleset_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  signal_type varchar(100) DEFAULT '',
  expected_value decimal(10,4) DEFAULT 0.0000,
  actual_value decimal(10,4) DEFAULT 0.0000,
  match_score decimal(5,2) DEFAULT 0.00,
  weight decimal(4,2) DEFAULT 0.10,
  snapshot_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ruleset (ruleset_id),
  KEY idx_campaign (campaign_id),
  KEY idx_snapshot (snapshot_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_ruleset_matchscores (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ruleset_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  total_score decimal(5,2) DEFAULT 0.00,
  rank tinyint(3) unsigned DEFAULT 0,
  users_in_segment int(10) unsigned DEFAULT 0,
  calculated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ruleset (ruleset_id),
  KEY idx_campaign (campaign_id),
  KEY idx_score (total_score)
) $charset_collate;

CREATE TABLE {$prefix}sb_marketer_personas (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  persona_slug varchar(100) NOT NULL DEFAULT '',
  persona_name varchar(255) NOT NULL DEFAULT '',
  methodology_summary text,
  expected_signals_json longtext,
  signal_weights_json longtext,
  pipeline_slug varchar(100) DEFAULT 'default',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (persona_slug)
) $charset_collate;

CREATE TABLE {$prefix}sb_operational_rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  persona_id bigint(20) unsigned DEFAULT 0,
  rule_type varchar(50) DEFAULT '',
  rule_label varchar(255) DEFAULT '',
  conditions_json longtext,
  action_json longtext,
  priority tinyint(3) unsigned DEFAULT 0,
  is_active tinyint(1) DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_persona (persona_id),
  KEY idx_type (rule_type),
  KEY idx_active (is_active)
) $charset_collate;

CREATE TABLE {$prefix}sb_debug_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(50) DEFAULT 'full',
  snapshot_json longtext,
  diagnosis_text longtext,
  health_score tinyint(3) unsigned DEFAULT 0,
  issues_found tinyint(3) unsigned DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_created (created_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_debug_findings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  severity varchar(10) DEFAULT 'info',
  category varchar(50) DEFAULT '',
  description text,
  affected_id bigint(20) unsigned DEFAULT 0,
  affected_type varchar(50) DEFAULT '',
  proposed_fix_json longtext,
  rollback_json longtext,
  status varchar(20) DEFAULT 'open',
  fix_applied_at datetime DEFAULT NULL,
  fix_applied_by bigint(20) unsigned DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_session (session_id),
  KEY idx_severity (severity),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_game_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ruleset_id bigint(20) unsigned DEFAULT 0,
  level smallint(5) unsigned DEFAULT 1,
  score decimal(10,2) DEFAULT 0.00,
  map_id bigint(20) unsigned DEFAULT 0,
  inventory_json longtext,
  quest_json longtext,
  checkpoints_json longtext,
  playtime_seconds int(10) unsigned DEFAULT 0,
  state_json longtext,
  started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_ruleset (ruleset_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_game_assets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  asset_type varchar(50) DEFAULT 'sprite',
  asset_key varchar(100) NOT NULL DEFAULT '',
  file_url varchar(500) DEFAULT '',
  campaign_id bigint(20) unsigned DEFAULT 0,
  sprite_sheet_json longtext,
  animation_json longtext,
  physics_config_json longtext,
  audio_config_json longtext,
  generated_by varchar(20) DEFAULT 'manual',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_type (asset_type),
  KEY idx_campaign (campaign_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_game_maps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ruleset_id bigint(20) unsigned DEFAULT 0,
  name varchar(255) NOT NULL DEFAULT '',
  world_config_json longtext,
  progression_rules_json longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ruleset (ruleset_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_game_worlds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ruleset_id bigint(20) unsigned DEFAULT 0,
  name varchar(255) NOT NULL DEFAULT '',
  world_config_json longtext,
  progression_rules_json longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ruleset (ruleset_id)
) $charset_collate;

CREATE TABLE {$prefix}sb_video_briefs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned DEFAULT 0,
  factory_run_id bigint(20) unsigned DEFAULT 0,
  script_text longtext,
  brief_json longtext,
  duration_secs tinyint(3) unsigned DEFAULT 60,
  provider varchar(50) DEFAULT 'hedra',
  status varchar(20) DEFAULT 'pending',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_campaign (campaign_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_generated_videos (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  brief_id bigint(20) unsigned DEFAULT 0,
  file_path varchar(500) DEFAULT '',
  file_url varchar(500) DEFAULT '',
  file_size bigint(20) unsigned DEFAULT 0,
  provider varchar(50) DEFAULT '',
  cost_usd decimal(8,4) DEFAULT 0.0000,
  status varchar(20) DEFAULT 'generated',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_brief (brief_id),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_config_snapshots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_type varchar(50) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  config_json longtext,
  version int(10) unsigned DEFAULT 1,
  is_current tinyint(1) DEFAULT 0,
  created_by bigint(20) unsigned DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_current (is_current),
  KEY idx_version (version)
) $charset_collate;

CREATE TABLE {$prefix}sb_capability_registry (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  provider varchar(50) DEFAULT 'anthropic',
  model_slug varchar(100) DEFAULT '',
  sovereignty_flag varchar(20) DEFAULT 'canadian',
  requires_hitm tinyint(1) DEFAULT 0,
  supports_dry_run tinyint(1) DEFAULT 1,
  budget_cap decimal(10,4) DEFAULT 0.0000,
  rate_limit_per_hour smallint(5) unsigned DEFAULT 0,
  required_cap varchar(100) DEFAULT 'run_sovereign_factory',
  config_json longtext,
  is_active tinyint(1) DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (slug),
  KEY idx_active (is_active)
) $charset_collate;

CREATE TABLE {$prefix}sb_app_blueprints (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  version varchar(20) DEFAULT '1.0.0',
  config_json longtext,
  status varchar(20) DEFAULT 'installed',
  activated_at datetime DEFAULT NULL,
  deactivated_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (slug),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_view_schemas (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  schema_json longtext NOT NULL,
  status varchar(20) DEFAULT 'draft',
  version smallint(5) unsigned DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (slug),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_tiny_forms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  fields_json longtext NOT NULL,
  validation_json longtext,
  save_adapter varchar(50) DEFAULT 'submission_table',
  save_config_json longtext,
  visibility_rules_json longtext,
  success_message text,
  error_message text,
  status varchar(20) DEFAULT 'draft',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (slug),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_ui_surfaces (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  surface_type varchar(50) DEFAULT 'banner',
  content_json longtext,
  visibility_rules_json longtext,
  placement_region varchar(100) DEFAULT '',
  status varchar(20) DEFAULT 'draft',
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (slug),
  KEY idx_status (status),
  KEY idx_region (placement_region)
) $charset_collate;

CREATE TABLE {$prefix}sb_placements (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  label varchar(255) DEFAULT '',
  surface_slug varchar(100) DEFAULT '',
  form_slug varchar(100) DEFAULT '',
  context_type varchar(50) DEFAULT 'page',
  context_key varchar(100) DEFAULT '',
  road_key char(1) DEFAULT '',
  required_cap varchar(100) DEFAULT '',
  pmpro_level smallint(5) unsigned DEFAULT 0,
  url_param_match varchar(255) DEFAULT '',
  priority tinyint(3) unsigned DEFAULT 10,
  status varchar(20) DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_context (context_type, context_key),
  KEY idx_surface (surface_slug),
  KEY idx_form (form_slug),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_submissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_slug varchar(100) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned DEFAULT 0,
  session_id varchar(64) DEFAULT '',
  status varchar(20) DEFAULT 'received',
  submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_form (form_slug),
  KEY idx_user (user_id),
  KEY idx_status (status),
  KEY idx_submitted (submitted_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_submission_meta (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(100) NOT NULL DEFAULT '',
  meta_value longtext,
  PRIMARY KEY  (id),
  KEY idx_submission (submission_id),
  KEY idx_key (meta_key)
) $charset_collate;

CREATE TABLE {$prefix}sb_definition_versions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_type varchar(50) NOT NULL DEFAULT '',
  entity_slug varchar(100) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned DEFAULT 0,
  version_number smallint(5) unsigned DEFAULT 1,
  definition_json longtext,
  status varchar(20) DEFAULT 'archived',
  approval_id bigint(20) unsigned DEFAULT 0,
  created_by bigint(20) unsigned DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_entity (entity_type, entity_slug),
  KEY idx_version (version_number),
  KEY idx_status (status)
) $charset_collate;

CREATE TABLE {$prefix}sb_connector_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_type varchar(100) NOT NULL DEFAULT '',
  source varchar(50) DEFAULT 'internal',
  direction varchar(10) DEFAULT 'inbound',
  payload longtext,
  connector_slug varchar(100) DEFAULT '',
  status varchar(20) DEFAULT 'received',
  retry_count tinyint(3) unsigned DEFAULT 0,
  next_retry_at datetime DEFAULT NULL,
  resolved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_event_type (event_type),
  KEY idx_status (status),
  KEY idx_connector (connector_slug),
  KEY idx_created (created_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_replay_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  original_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  connector_slug varchar(100) DEFAULT '',
  payload longtext,
  status varchar(20) DEFAULT 'queued',
  queued_by bigint(20) unsigned DEFAULT 0,
  queued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  executed_at datetime DEFAULT NULL,
  result_json longtext,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_connector (connector_slug)
) $charset_collate;

CREATE TABLE {$prefix}sb_sim_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sim_type varchar(50) NOT NULL DEFAULT '',
  params_json longtext,
  results_json longtext,
  predicted_impact_json longtext,
  comparison_json longtext,
  run_by bigint(20) unsigned DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_type (sim_type),
  KEY idx_run_by (run_by),
  KEY idx_created (created_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_dep_graph_snapshots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(50) DEFAULT 'full',
  nodes_json longtext,
  edges_json longtext,
  snapshot_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_scope (scope),
  KEY idx_snapshot (snapshot_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_perf_metrics (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  metric_type varchar(100) NOT NULL DEFAULT '',
  metric_value decimal(12,4) DEFAULT 0.0000,
  context_json longtext,
  captured_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_type (metric_type),
  KEY idx_captured (captured_at)
) $charset_collate;

CREATE TABLE {$prefix}sb_user_field_catalog (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  field_type varchar(50) DEFAULT 'text',
  group_slug varchar(100) DEFAULT 'general',
  validation_json longtext,
  is_sensitive tinyint(1) DEFAULT 0,
  is_public tinyint(1) DEFAULT 0,
  required_cap varchar(100) DEFAULT 'manage_sovereign',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_slug (slug)
) $charset_collate;

CREATE TABLE {$prefix}sb_user_field_history (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  field_slug varchar(100) NOT NULL DEFAULT '',
  old_value longtext,
  new_value longtext,
  changed_by bigint(20) unsigned DEFAULT 0,
  changed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_field (field_slug),
  KEY idx_changed (changed_at)
) $charset_collate;";

		dbDelta( $sql );
	}

	public static function create_capabilities() {
		$caps = [
			// Ask4 capabilities
			'manage_sovereign',
			'run_sovereign_factory',
			'review_sovereign_outputs',
			'approve_sovereign_deployments',
			'approve_sovereign_social',
			'approve_sovereign_pricing',
			'manage_sovereign_prompts',
			'view_sovereign_audit_logs',
			'manage_sovereign_scenarios',
			'manage_sovereign_journeys',
			'manage_sovereign_rulesets',
			'manage_sovereign_traffic',
			'manage_sovereign_games',
			'manage_sovereign_debug',
			// Ask5 capabilities
			'manage_sovereign_blueprints',
			'manage_sovereign_schemas',
			'manage_sovereign_forms',
			'manage_sovereign_surfaces',
			// ASK5.5 Kynvaric capabilities
			'manage_kynvaric_proposals',
			'approve_kynvaric_commits',
			'view_kynvaric_ledger',
			'manage_kynvaric_evidence',
			'manage_kynvaric_review_sessions',
			'sign_off_kynvaric',
		];
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function schedule_cron() {
		// Driven exclusively by CRON_SCHEDULES — single source of truth.
		// Adding a new cron hook: add it to CRON_SCHEDULES and CRON_HOOKS only.
		foreach ( self::CRON_SCHEDULES as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	// HARDEN-004: centralized settings allowlist with type-correct sanitizers
	public static function get_settings_allowlist(): array {
		return [
			'sb_from_name'                  => 'sanitize_text_field',
			'sb_from_email'                 => 'sanitize_email',
			'sb_admin_email'                => 'sanitize_email',
			'sb_anthropic_key'              => 'sanitize_text_field',
			'sb_model_slug'                 => 'sanitize_text_field',
			'sb_signal_key'                 => 'sanitize_text_field',
			'sb_api_timeout'                => 'absint',
			'sb_retry_count'                => 'absint',
			'sb_max_tokens'                 => 'absint',
			'sb_log_mode'                   => 'sanitize_key',
			'sb_log_retention_days'         => 'absint',
			'sb_log_verbose_hours'          => 'absint',
			'sb_image_provider'             => 'sanitize_key',
			'sb_image_api_key'              => 'sanitize_text_field',
			'sb_image_budget_cap'           => 'floatval',
			'sb_video_provider'             => 'sanitize_key',
			'sb_video_api_key'              => 'sanitize_text_field',
			'sb_fb_app_id'                  => 'sanitize_text_field',
			'sb_fb_access_token'            => 'sanitize_text_field',
			'sb_fb_ad_account_id'           => 'sanitize_text_field',
			'sb_google_ads_id'              => 'sanitize_text_field',
			'sb_google_ads_token'           => 'sanitize_text_field',
			'sb_google_dev_token'           => 'sanitize_text_field',
			'sb_acquisition_daily_cap'      => 'floatval',
			'sb_podcast_title'              => 'sanitize_text_field',
			'sb_podcast_author'             => 'sanitize_text_field',
			'sb_podcast_language'           => 'sanitize_text_field',
			'sb_podcast_image_id'           => 'absint',
			'sb_signal_cron'                => 'sanitize_key',
			// Ask5 settings
			'sb_ai_integrator_budget_cap'   => 'floatval',
			'sb_ai_integrator_rate_limit'   => 'absint',
			'sb_blueprint_auto_backup'      => 'absint',
			'sb_sim_max_depth'              => 'absint',
			'sb_connector_retry_limit'      => 'absint',
			'sb_connector_retry_delay'      => 'absint',
			'sb_perf_snapshot_interval'     => 'sanitize_key',
			'sb_debugger_ai_enabled'        => 'absint',
			'sb_user_field_history_days'    => 'absint',
			'sb_dep_graph_auto_refresh'     => 'absint',
		];
	}

	// BLOCKER-003 fix: expanded delete allowlist with per-table capability
	public static function get_delete_capability_map(): array {
		return [
			'sb_v2_agents'            => 'manage_sovereign',
			'sb_v2_pipeline_configs'  => 'manage_sovereign',
			'sb_channel_actions'      => 'manage_sovereign',
			'sb_approvals'            => 'manage_sovereign',
			'sb_rulesets'             => 'manage_sovereign_rulesets',
			'sb_ruleset_items'        => 'manage_sovereign_rulesets',
			'sb_campaign_rulesets'    => 'manage_sovereign_rulesets',
			'sb_scenarios'            => 'manage_sovereign_scenarios',
			'sb_blueprint_steps'      => 'manage_sovereign_scenarios',
			'sb_journeys'             => 'manage_sovereign_journeys',
			'sb_journey_steps'        => 'manage_sovereign_journeys',
			'sb_marketer_personas'    => 'manage_sovereign_rulesets',
			'sb_marketer_signals'     => 'manage_sovereign_rulesets',
			'sb_templates'            => 'manage_sovereign',
			'sb_artifacts'            => 'manage_sovereign',
			'sb_social_posts'         => 'approve_sovereign_social',
			'sb_image_briefs'         => 'manage_sovereign',
			'sb_generated_images'     => 'manage_sovereign',
			'sb_ad_campaigns'         => 'approve_sovereign_pricing',
			'sb_ad_creatives'         => 'approve_sovereign_social',
			'sb_podcast_episodes'     => 'manage_sovereign',
			'sb_signal_definitions'   => 'manage_sovereign',
			'sb_analyst_reports'      => 'manage_sovereign',
			'sb_operator_notes'       => 'manage_sovereign',
			'sb_app_blueprints'       => 'manage_sovereign_blueprints',
			'sb_view_schemas'         => 'manage_sovereign_schemas',
			'sb_tiny_forms'           => 'manage_sovereign_forms',
			'sb_ui_surfaces'          => 'manage_sovereign_surfaces',
			'sb_placements'           => 'manage_sovereign',
			'sb_submissions'          => 'manage_sovereign',
			'sb_definition_versions'  => 'manage_sovereign_schemas',
			'sb_connector_events'     => 'manage_sovereign',
			'sb_user_field_catalog'   => 'manage_sovereign',
			'sb_game_sessions'        => 'manage_sovereign_games',
			'sb_game_assets'          => 'manage_sovereign_games',
			// Ask5 system/audit tables — manage_sovereign required (not operator-deletable via UI)
			'sb_user_field_history'   => 'manage_sovereign',
			'sb_perf_metrics'         => 'manage_sovereign',
			'sb_dep_graph_snapshots'  => 'manage_sovereign',
			'sb_sim_runs'             => 'manage_sovereign',
			'sb_replay_queue'         => 'manage_sovereign',
		];
	}

	/**
	 * Show an admin notice if the DB schema or cron schedules appear incomplete.
	 * Fires on every admin page load — lightweight because it uses get_option() only.
	 * Operators dismiss it by running repair-system; it returns after plugin updates.
	 */
	public static function maybe_show_repair_notice(): void {
		global $wpdb;
		// Only show to users who can fix it
		if ( ! current_user_can( 'manage_sovereign' ) ) { return; }

		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		$code_version      = self::DB_VERSION;
		$version_mismatch  = version_compare( $installed_version, $code_version, '<' );

		// Check ASK5.5 critical tables exist
		$ask55_tables = [ 'sb_apo_store', 'sb_authority_events', 'sb_build_map_runtime', 'sb_commit_requests' ];
		$missing_tables = [];
		foreach ( $ask55_tables as $t ) {
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$t}'" );
			if ( ! $exists ) { $missing_tables[] = $t; }
		}

		// Check if key cron events are scheduled
		$missing_crons = [];
		$required_crons = [ 'sb_debug_health_check', 'sb_telemetry_buffer_flush', 'sb_daily_license_ping' ];
		foreach ( $required_crons as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				$missing_crons[] = $hook;
			}
		}

		if ( ! $version_mismatch && empty( $missing_crons ) && empty( $missing_tables ) ) { return; }

		// Build notice message
		$repair_url = esc_url( admin_url( 'admin.php?page=sovereign-builder&sb_action=repair' ) );
		$issues = [];
		if ( ! empty( $missing_tables ) ) {
			$issues[] = 'ASK5.5 tables missing: ' . esc_html( implode( ', ', $missing_tables ) ) . '. Run repair system.';
		}
		if ( $version_mismatch ) {
			$issues[] = sprintf(
				'DB schema is at v%s but plugin requires v%s.',
				esc_html( $installed_version ),
				esc_html( $code_version )
			);
		}
		if ( ! empty( $missing_crons ) ) {
			$issues[] = 'Cron jobs not scheduled: ' . esc_html( implode( ', ', $missing_crons ) ) . '.';
		}

		echo '<div class="notice notice-warning"><p>';
		echo '<strong>Sovereign Builder — Action Required:</strong> ';
		echo implode( ' ', $issues );
		echo ' <a href="' . $repair_url . '" class="button button-small" style="margin-left:8px;">Run Repair System</a>';
		echo '</p></div>';
	}

	/**
	 * Canonical table list — single source of truth used by create_tables,
	 * run_on_uninstall, and SBDebuggerConsole health check.
	 * Add new tables here only — all other lists delegate to this method.
	 */
	public static function get_all_tables(): array {
		return [
			// Ask2 core
			'sb_campaigns','sb_signals','sb_roads','sb_factory_runs',
			'sb_approvals','sb_audit_log','sb_artifacts','sb_templates','sb_operator_notes',
			// Ask3 advanced
			'sb_scenarios','sb_blueprint_steps','sb_journeys','sb_journey_steps',
			'sb_channel_actions','sb_v2_agents','sb_v2_pipeline_configs','sb_v2_signal_rules',
			'sb_design_tokens','sb_level_road_map','sb_settings','sb_ruleset_prompts',
			'sb_rulesets','sb_ruleset_items','sb_campaign_rulesets','sb_performance_thresholds',
			'sb_ruleset_switches','sb_user_rulesets','sb_traffic_snapshots','sb_funnel_events',
			'sb_email_events',
			// Ask4 marketing
			'sb_social_posts','sb_image_briefs','sb_generated_images',
			'sb_ad_campaigns','sb_ad_creatives','sb_ad_results',
			'sb_podcast_episodes','sb_podcast_stats','sb_analyst_reports',
			'sb_signal_definitions','sb_marketer_signals','sb_ruleset_performance',
			'sb_ruleset_matchscores','sb_marketer_personas','sb_operational_rules',
			'sb_debug_sessions','sb_debug_findings','sb_game_sessions','sb_game_assets',
			'sb_game_maps','sb_game_worlds','sb_video_briefs','sb_generated_videos',
			'sb_config_snapshots',
			// Ask5
			'sb_capability_registry','sb_app_blueprints','sb_view_schemas','sb_tiny_forms',
			'sb_ui_surfaces','sb_placements','sb_submissions','sb_submission_meta',
			'sb_definition_versions','sb_connector_events','sb_replay_queue','sb_sim_runs',
			'sb_dep_graph_snapshots','sb_perf_metrics','sb_user_field_catalog',
			'sb_user_field_history',
			// ASK5.5 Phase A — regulated workflow substrate
			'sb_apo_store','sb_apo_transitions','sb_commit_requests','sb_commit_approvers',
			'sb_authority_events','sb_compensating_entries','sb_dual_control_policies',
			'sb_entitlement_maps','sb_build_map_runtime',
			// ASK5.5 Phase B — workspace and evidence
			'sb_review_sessions','sb_review_queue_items','sb_signoff_records',
			'sb_evidence_items','sb_evidence_links',
		];
	}

	// DEFECT-001 fix: complete uninstall delegates to get_all_tables() — single source
	/**
	 * Lightweight post-activation / post-repair environment health check.
	 * Returns a structured report: pass/fail per check, plus a 'failures' list.
	 * Never throws — always returns an array. Called after activation and by repair-system.
	 */
	public static function verify_environment(): array {
		global $wpdb;
		$pass     = [];
		$failures = [];

		// 1. DB version matches installed
		$installed = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			$pass['db_version'] = "DB version {$installed} matches {$installed}.";
		} else {
			$failures[] = "DB version mismatch: installed={$installed}, expected=" . self::DB_VERSION;
		}

		// 2. Core SB tables present (spot-check 5 representative tables)
		$core_spot = [ 'sb_campaigns', 'sb_app_blueprints', 'sb_tiny_forms', 'sb_approvals', 'sb_signals' ];
		$missing_core = [];
		foreach ( $core_spot as $t ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$t}'" ) ) {
				$missing_core[] = $t;
			}
		}
		if ( empty( $missing_core ) ) {
			$pass['core_tables'] = 'Core table spot-check passed.';
		} else {
			$failures[] = 'Missing core tables: ' . implode( ', ', $missing_core );
		}

		// 3. ASK5.5 tables present (4 critical ones)
		$ask55_spot = [ 'sb_apo_store', 'sb_authority_events', 'sb_build_map_runtime', 'sb_commit_requests' ];
		$missing_55 = [];
		foreach ( $ask55_spot as $t ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$t}'" ) ) {
				$missing_55[] = $t;
			}
		}
		if ( empty( $missing_55 ) ) {
			$pass['ask55_tables'] = 'ASK5.5 table spot-check passed.';
		} else {
			$failures[] = 'Missing ASK5.5 tables: ' . implode( ', ', $missing_55 ) . '. Run repair-system.';
		}

		// 4. All CRON_HOOKS scheduled
		$missing_crons = [];
		foreach ( self::CRON_HOOKS as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				$missing_crons[] = $hook;
			}
		}
		if ( empty( $missing_crons ) ) {
			$pass['cron_hooks'] = 'All ' . count( self::CRON_HOOKS ) . ' cron hooks scheduled.';
		} else {
			$failures[] = 'Unscheduled cron hooks: ' . implode( ', ', $missing_crons );
		}

		// 5. Administrator has manage_sovereign capability
		$role = get_role( 'administrator' );
		if ( $role && $role->has_cap( 'manage_sovereign' ) ) {
			$pass['capabilities'] = 'manage_sovereign capability present on administrator.';
		} else {
			$failures[] = 'manage_sovereign capability missing from administrator role.';
		}

		// 6. DB version option key exists
		if ( get_option( self::DB_VERSION_OPTION ) !== false ) {
			$pass['version_option'] = 'DB version option exists.';
		} else {
			$failures[] = 'DB_VERSION_OPTION not set — may indicate a failed activation.';
		}

		return [
			'pass'     => $pass,
			'failures' => $failures,
			'healthy'  => empty( $failures ),
			'ts'       => current_time( 'mysql' ),
		];
	}

	public static function run_on_uninstall(): void {
		global $wpdb;
		$tables = self::get_all_tables(); // Single source of truth
		foreach ( $tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'sb\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key LIKE 'sb\_%'" );
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public static function seed_signal_definitions() {
		global $wpdb;
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_signal_definitions" ) > 0 ) {
			return;
		}
		$signals = [
			[ 'pmpro_signup', 'PMPro Signup Completed', 'hook', 0, 0, 'Triggered when user completes membership checkout.' ],
			[ 'pmpro_upgrade', 'PMPro Membership Upgrade', 'hook', 0, 0, 'Triggered on level escalation.' ],
			[ 'pmpro_cancel', 'PMPro Level Cancellation', 'hook', 0, 1, 'Negative signal on cancellation.' ],
			[ 'wc_purchase', 'WooCommerce Purchase Completed', 'hook', 0, 0, 'Fired on order completion.' ],
			[ 'factory_run_complete', 'Factory Pipeline Execution Success', 'hook', 0, 0, 'Fired when factory run completes.' ],
			[ 'email_opened', 'Outbound Email Opened', 'hook', 0, 0, 'Fired when tracking pixel resolves.' ],
			[ 'email_clicked', 'Outbound Link Clicked', 'hook', 0, 0, 'Fired when tracked link clicked.' ],
			[ 'road_entered', 'Many Roads Segment Changed', 'hook', 0, 0, 'Fired on funnel state change.' ],
			[ 'user_stalled', 'User Stalled Flag Activated', 'hook', 0, 1, 'Flagged by telemetry scan.' ],
			[ 'ad_conversion', 'Paid Network Acquisition Event', 'hook', 0, 0, 'Inbound conversion payload.' ],
			[ 'content_consumed', 'Content Reading Session', 'hook', 0, 0, 'JavaScript beacon.' ],
			[ 'video_played', 'Video Playback Settled', 'hook', 0, 0, 'Media engine watch log.' ],
			[ 'podcast_listened', 'Audio Feed Downloaded', 'hook', 0, 0, 'Proxy media streaming event.' ],
			[ 'upsell_accepted', 'Upsell Path Accepted', 'hook', 0, 0, 'Checkout upsell acceptance.' ],
			[ 'upsell_declined', 'Upsell Path Declined', 'hook', 0, 1, 'Checkout upsell avoidance.' ],
			[ 'sequence_completed', 'Automation Path Exhausted', 'hook', 1, 0, 'Fired on terminal step.' ],
			[ 'form_submitted', 'Tiny Form Submitted', 'hook', 0, 0, 'Ask5: fired on form submission.' ],
			[ 'blueprint_activated', 'Blueprint Activated', 'hook', 0, 0, 'Ask5: blueprint went active.' ],
		];
		foreach ( $signals as $s ) {
			$wpdb->insert( "{$wpdb->prefix}sb_signal_definitions", [
				'signal_type'    => $s[0],
				'label'          => $s[1],
				'capture_method' => $s[2],
				'is_computed'    => $s[3],
				'is_negative'    => $s[4],
				'description'    => $s[5],
				'created_at'     => current_time( 'mysql' ),
			] );
		}
	}
}

// === ADDITION: Installer updates — DB_VERSION 1.2.0, new tables, caps, migrations ===
// ── DB_VERSION change ─────────────────────────────────────────────────────────
// In class SB_Installer, change:
//   const DB_VERSION = '1.1.0';
// To:
//   const DB_VERSION = '1.2.0';

// ── get_all_tables() additions ────────────────────────────────────────────────
// Append to the return array in SB_Installer::get_all_tables():
/*
			// ASK5.5 Phase A — regulated workflow substrate
			'sb_apo_store', 'sb_apo_transitions', 'sb_commit_requests', 'sb_commit_approvers',
			'sb_authority_events', 'sb_compensating_entries', 'sb_dual_control_policies',
			'sb_entitlement_maps',
			// ASK5.5 Build Map
			'sb_build_map_runtime',
*/

// ── New CREATE TABLE statements (add to create_tables()) ─────────────────────

function sb_55_phase_a_create_tables( string $charset_collate, string $prefix ): void {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// APO store
	dbDelta( "CREATE TABLE {$prefix}sb_apo_store (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  proposal_uuid char(36) NOT NULL DEFAULT '',
  domain_key varchar(100) NOT NULL DEFAULT '',
  proposal_type varchar(100) NOT NULL DEFAULT '',
  subject_type varchar(100) NOT NULL DEFAULT '',
  subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
  payload_json longtext NOT NULL,
  payload_hash char(64) NOT NULL DEFAULT '',
  confidence_score decimal(5,4) NOT NULL DEFAULT 0.0000,
  status varchar(50) NOT NULL DEFAULT 'draft',
  created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by_agent_slug varchar(100) NOT NULL DEFAULT '',
  review_required tinyint(1) NOT NULL DEFAULT 1,
  expires_at datetime DEFAULT NULL,
  supersedes_proposal_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_domain (domain_key),
  KEY idx_proposal_type (proposal_type),
  KEY idx_payload_hash (payload_hash),
  KEY idx_subject (subject_type, subject_id)
) {$charset_collate};" );

	// APO transitions
	dbDelta( "CREATE TABLE {$prefix}sb_apo_transitions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  proposal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  from_status varchar(50) NOT NULL DEFAULT '',
  to_status varchar(50) NOT NULL DEFAULT '',
  actor_type varchar(50) NOT NULL DEFAULT 'human',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason_code varchar(100) NOT NULL DEFAULT '',
  note longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_proposal (proposal_id),
  KEY idx_to_status (to_status)
) {$charset_collate};" );

	// Commit requests
	dbDelta( "CREATE TABLE {$prefix}sb_commit_requests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  apo_id bigint(20) unsigned NOT NULL DEFAULT 0,
  commit_type varchar(100) NOT NULL DEFAULT '',
  target_store varchar(100) NOT NULL DEFAULT '',
  sensitivity_level varchar(50) NOT NULL DEFAULT 'medium',
  approved_payload_hash char(64) NOT NULL DEFAULT '',
  policy_id bigint(20) unsigned DEFAULT NULL,
  status varchar(50) NOT NULL DEFAULT 'pending',
  requested_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_by_user_id bigint(20) unsigned DEFAULT NULL,
  committed_event_id bigint(20) unsigned DEFAULT NULL,
  executed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_apo (apo_id),
  KEY idx_status (status)
) {$charset_collate};" );

	// Commit approvers (multi-approver dual control)
	dbDelta( "CREATE TABLE {$prefix}sb_commit_approvers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  commit_request_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note text,
  PRIMARY KEY  (id),
  KEY idx_commit (commit_request_id),
  KEY idx_user (user_id),
  UNIQUE KEY idx_commit_user (commit_request_id, user_id)
) {$charset_collate};" );

	// Authority events (append-only)
	dbDelta( "CREATE TABLE {$prefix}sb_authority_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_uuid char(36) NOT NULL DEFAULT '',
  domain_key varchar(100) NOT NULL DEFAULT '',
  event_type varchar(100) NOT NULL DEFAULT '',
  aggregate_type varchar(100) NOT NULL DEFAULT '',
  aggregate_id bigint(20) unsigned NOT NULL DEFAULT 0,
  payload_json longtext NOT NULL,
  caused_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_commit_request_id bigint(20) unsigned DEFAULT NULL,
  prior_event_hash varchar(255) NOT NULL DEFAULT '',
  event_hash varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_event_hash (event_hash),
  KEY idx_prior_hash (prior_event_hash),
  KEY idx_event_type (event_type),
  KEY idx_domain (domain_key),
  KEY idx_aggregate (aggregate_type, aggregate_id)
) {$charset_collate};" );

	// Compensating entries
	dbDelta( "CREATE TABLE {$prefix}sb_compensating_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  original_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  compensating_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  correction_type varchar(100) NOT NULL DEFAULT '',
  reason_code varchar(100) NOT NULL DEFAULT '',
  operator_note longtext,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_original (original_event_id),
  KEY idx_compensating (compensating_event_id)
) {$charset_collate};" );

	// Dual control policies
	dbDelta( "CREATE TABLE {$prefix}sb_dual_control_policies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  action_key varchar(100) NOT NULL DEFAULT '',
  sensitivity_level varchar(50) NOT NULL DEFAULT 'medium',
  requires_dual_control tinyint(1) NOT NULL DEFAULT 0,
  disallow_self_approval tinyint(1) NOT NULL DEFAULT 1,
  min_distinct_approvers tinyint(3) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_action_key (action_key)
) {$charset_collate};" );

	// Entitlement maps (WooCommerce/PMPro → blueprint)
	dbDelta( "CREATE TABLE {$prefix}sb_entitlement_maps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_type varchar(50) NOT NULL DEFAULT 'woo_product',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  blueprint_slug varchar(150) NOT NULL DEFAULT '',
  workspace_profile varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_source (source_type, source_id),
  KEY idx_blueprint (blueprint_slug)
) {$charset_collate};" );

	// Build map runtime snapshot
	dbDelta( "CREATE TABLE {$prefix}sb_build_map_runtime (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  blueprint_id bigint(20) unsigned NOT NULL DEFAULT 0,
  node_hash char(64) NOT NULL DEFAULT '',
  node_type varchar(50) NOT NULL DEFAULT '',
  node_slug varchar(100) NOT NULL DEFAULT '',
  label varchar(255) DEFAULT '',
  status varchar(20) DEFAULT '',
  materialization_status varchar(20) NOT NULL DEFAULT 'pending',
  last_materialized_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_blueprint (blueprint_id),
  KEY idx_node_type (node_type),
  KEY idx_node_slug (node_slug),
  KEY idx_node_hash (node_hash),
  KEY idx_status (status),
  KEY idx_mat_status (materialization_status)
) {$charset_collate};" );
}

// ── maybe_update() ALTER TABLE migrations ────────────────────────────────────
// Add to SB_Installer::maybe_update() inside the version comparison block:

function sb_55_maybe_alter_blueprints( string $prefix ): void {
	global $wpdb;
	// Add graph_hash column if not exists
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$prefix}sb_app_blueprints" );
	if ( ! in_array( 'graph_hash', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$prefix}sb_app_blueprints ADD COLUMN graph_hash char(64) NOT NULL DEFAULT '' AFTER config_json" );
	}
	// Add is_regulated column if not exists
	if ( ! in_array( 'is_regulated', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$prefix}sb_app_blueprints ADD COLUMN is_regulated tinyint(1) NOT NULL DEFAULT 0 AFTER graph_hash" );
	}
}

// ── Kynvaric capabilities ─────────────────────────────────────────────────────
// Add to SB_Installer::create_capabilities() or seed_capabilities():

function sb_55_seed_kynvaric_caps(): void {
	$caps = [
		'manage_kynvaric_proposals',
		'approve_kynvaric_commits',
		'view_kynvaric_ledger',
		'manage_kynvaric_evidence',
		'manage_kynvaric_review_sessions',
		'sign_off_kynvaric',
	];
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( $caps as $cap ) {
			$admin->add_cap( $cap );
		}
	}
}

// ── Default dual control policies seeded on repair-system ────────────────────
function sb_55_seed_dual_control_policies( string $prefix ): void {
	global $wpdb;
	$exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}sb_dual_control_policies" );
	if ( $exists > 0 ) { return; }
	$policies = [
		[ 'action_key' => 'commit_execute_critical',   'sensitivity_level' => 'critical', 'requires_dual_control' => 1, 'disallow_self_approval' => 1, 'min_distinct_approvers' => 2 ],
		[ 'action_key' => 'commit_execute_high',       'sensitivity_level' => 'high',     'requires_dual_control' => 1, 'disallow_self_approval' => 1, 'min_distinct_approvers' => 2 ],
		[ 'action_key' => 'commit_execute_medium',     'sensitivity_level' => 'medium',   'requires_dual_control' => 0, 'disallow_self_approval' => 1, 'min_distinct_approvers' => 1 ],
		[ 'action_key' => 'commit_execute_low',        'sensitivity_level' => 'low',      'requires_dual_control' => 0, 'disallow_self_approval' => 0, 'min_distinct_approvers' => 1 ],
	];
	foreach ( $policies as $p ) {
		$p['created_at'] = current_time( 'mysql' );
		$wpdb->insert( "{$prefix}sb_dual_control_policies", $p );
	}
}

// === ADDITION: Phase B installer additions ===
// ── get_all_tables() additions ────────────────────────────────────────────────
/*
			// ASK5.5 Phase B — workspace and evidence
			'sb_review_sessions', 'sb_review_queue_items', 'sb_signoff_records',
			'sb_evidence_items', 'sb_evidence_links',
*/

function sb_55_phase_b_create_tables( string $charset_collate, string $prefix ): void {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// Review sessions
	dbDelta( "CREATE TABLE {$prefix}sb_review_sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_uuid char(36) NOT NULL DEFAULT '',
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  engagement_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(50) NOT NULL DEFAULT 'open',
  opened_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  assigned_to_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_client (client_id),
  KEY idx_assigned (assigned_to_user_id)
) {$charset_collate};" );

	// Review queue items
	dbDelta( "CREATE TABLE {$prefix}sb_review_queue_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  review_session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  queue_type varchar(100) NOT NULL DEFAULT 'exception',
  proposal_id bigint(20) unsigned DEFAULT NULL,
  severity varchar(50) NOT NULL DEFAULT 'medium',
  status varchar(50) NOT NULL DEFAULT 'open',
  assigned_to_user_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_session (review_session_id),
  KEY idx_status (status),
  KEY idx_queue_type (queue_type),
  KEY idx_severity (severity)
) {$charset_collate};" );

	// Sign-off records (append-only — never delete)
	dbDelta( "CREATE TABLE {$prefix}sb_signoff_records (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  review_session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  signoff_type varchar(100) NOT NULL DEFAULT 'preparer_review',
  signed_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  authority_event_id bigint(20) unsigned DEFAULT NULL,
  status varchar(50) NOT NULL DEFAULT 'signed',
  voided_signoff_id bigint(20) unsigned DEFAULT NULL,
  void_reason text,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_session (review_session_id),
  KEY idx_signed_by (signed_by_user_id),
  KEY idx_status (status)
) {$charset_collate};" );

	// Evidence items
	dbDelta( "CREATE TABLE {$prefix}sb_evidence_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  evidence_uuid char(36) NOT NULL DEFAULT '',
  evidence_type varchar(100) NOT NULL DEFAULT 'document',
  storage_provider varchar(100) NOT NULL DEFAULT 'local',
  storage_path varchar(500) NOT NULL DEFAULT '',
  mime_type varchar(150) NOT NULL DEFAULT 'application/octet-stream',
  linked_subject_type varchar(100) NOT NULL DEFAULT '',
  linked_subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
  retention_class varchar(100) NOT NULL DEFAULT 'standard',
  created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_evidence_type (evidence_type),
  KEY idx_subject (linked_subject_type, linked_subject_id),
  KEY idx_retention (retention_class)
) {$charset_collate};" );

	// Evidence links
	dbDelta( "CREATE TABLE {$prefix}sb_evidence_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  evidence_id bigint(20) unsigned NOT NULL DEFAULT 0,
  link_type varchar(100) NOT NULL DEFAULT 'proposal_support',
  linked_record_type varchar(100) NOT NULL DEFAULT '',
  linked_record_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_evidence (evidence_id),
  KEY idx_linked_record (linked_record_type, linked_record_id)
) {$charset_collate};" );
}

// === ADDITION: DB_VERSION bump to 1.3.0 for Phase B ===
// === ADDITION: Phase C installer — DB_VERSION 1.4.0 ===