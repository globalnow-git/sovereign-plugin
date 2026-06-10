<?php
/**
 * SB_Event_Keys — Canonical event name constants for all audit log emissions.
 * Prevents taxonomy drift between emitters, listeners, dashboards, and alert rules.
 * All log_audit() calls reference these constants — never raw string literals.
 *
 * @package SovereignBuilder
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SB_Event_Keys {

	// ── Core / Bootstrap ─────────────────────────────────────────────────────
	const EV_AD_SYNC_GOOGLE_PENDING                               = 'ad_sync_google_pending';
	const EV_AD_SYNC_NO_DATA                                      = 'ad_sync_no_data';
	const EV_AD_SYNC_RESULTS_SAVED                                = 'ad_sync_results_saved';
	const EV_AI_BUDGET_EXCEEDED                                   = 'ai_budget_exceeded';
	const EV_AI_BUDGET_WARNING                                    = 'ai_budget_warning';
	const EV_AI_CAPABILITY_DRY_RUN                                = 'ai_capability_dry_run';
	const EV_AI_CAPABILITY_INVOKED                                = 'ai_capability_invoked';
	const EV_AI_CAPABILITY_NOT_FOUND                              = 'ai_capability_not_found';
	const EV_AI_RATE_LIMITED                                      = 'ai_rate_limited';
	const EV_API_RESPONSE_TIME_MS                                 = 'api_response_time_ms';
	const EV_APPROVAL_CAP_DENIED                                  = 'approval_cap_denied';
	const EV_APPROVAL_CREATED                                     = 'approval_created';
	const EV_APPROVAL_PROCESSED                                   = 'approval_processed';
	const EV_BLUEPRINT_ACTIVATED                                  = 'blueprint_activated';
	const EV_BLUEPRINT_DEACTIVATED                                = 'blueprint_deactivated';
	const EV_BLUEPRINT_EXPORTED                                   = 'blueprint_exported';
	const EV_BLUEPRINT_IMPORTED                                   = 'blueprint_imported';
	const EV_BLUEPRINT_INSTALLED                                  = 'blueprint_installed';
	const EV_BLUEPRINT_UPGRADED                                   = 'blueprint_upgraded';
	const EV_CONFIG_SNAPSHOT_CREATED                              = 'config_snapshot_created';
	const EV_CONNECTOR_CREDENTIALS_ROTATION_QUEUED                = 'connector_credentials_rotation_queued';
	const EV_CONNECTOR_DEAD_LETTER                                = 'connector_dead_letter';
	const EV_CONNECTOR_DISPATCH_FAILED                            = 'connector_dispatch_failed';
	const EV_CONNECTOR_DISPATCH_OK                                = 'connector_dispatch_ok';
	const EV_CONNECTOR_REPLAYED                                   = 'connector_replayed';
	const EV_CONNECTOR_RETRIED                                    = 'connector_retried';
	const EV_CRON_START                                           = 'cron_start';
	const EV_DEBUG_HEALTH_CHECK_COMPLETE                          = 'debug_health_check_complete';
	const EV_DEBUGGER_FINDING_CREATED                             = 'debugger_finding_created';
	const EV_DEBUGGER_FIX_APPLIED                                 = 'debugger_fix_applied';
	const EV_DEBUGGER_FIX_VERIFIED                                = 'debugger_fix_verified';
	const EV_DEBUGGER_REMEDIATION_GENERATED                       = 'debugger_remediation_generated';
	const EV_DEBUGGER_SCAN_COMPLETE                               = 'debugger_scan_complete';
	const EV_DEFINITION_VERSION_CREATED                           = 'definition_version_created';
	const EV_DEP_GRAPH_BUILT                                      = 'dep_graph_built';
	const EV_DEP_GRAPH_IMPACT_ANALYZED                            = 'dep_graph_impact_analyzed';
	const EV_EDGE_CACHE_PURGED                                    = 'edge_cache_purged';
	const EV_EDGE_COMPILE_FAILED                                  = 'edge_compile_failed';
	const EV_EDGE_COMPILED                                        = 'edge_compiled';
	const EV_EDGE_HTACCESS_WRITTEN                                = 'edge_htaccess_written';
	const EV_EDGE_RULES_CLEARED                                   = 'edge_rules_cleared';
	const EV_EMAIL_FAILED                                         = 'email_failed';
	const EV_EMAIL_QUEUED                                         = 'email_queued';
	const EV_EVENT_EMITTED                                        = 'event_emitted';
	const EV_EVENT_INBOUND_NO_SECRET                              = 'event_inbound_no_secret';
	const EV_EVENT_INBOUND_RECEIVED                               = 'event_inbound_received';
	const EV_EVENT_INBOUND_SIGNATURE_INVALID                      = 'event_inbound_signature_invalid';
	const EV_EVENT_SIGNAL_ROUTING_ERROR                           = 'event_signal_routing_error';
	const EV_EVENT_SUBSCRIBER_ERROR                               = 'event_subscriber_error';
	const EV_FACTORY_LAYER_ERROR                                  = 'factory_layer_error';
	const EV_FACTORY_RUN_COMPLETE                                 = 'factory_run_complete';
	const EV_FACTORY_RUN_QUEUED                                   = 'factory_run_queued';
	const EV_FORM_SUBMISSION_FAILED                               = 'form_submission_failed';
	const EV_FORM_SUBMISSION_RECEIVED                             = 'form_submission_received';
	const EV_FORM_SUBMISSION_SAVED                                = 'form_submission_saved';
	const EV_HEALTH_ALERT_SENT                                    = 'health_alert_sent';
	const EV_JETPACK_SOCIAL_DISPATCHED                            = 'jetpack_social_dispatched';
	const EV_JETPACK_SOCIAL_SKIPPED                               = 'jetpack_social_skipped';
	const EV_JOB_QUEUED                                           = 'job_queued';
	const EV_LICENSE_PING_FAILED                                  = 'license_ping_failed';
	const EV_LICENSE_PING_INACTIVE                                = 'license_ping_inactive';
	const EV_LICENSE_PING_OK                                      = 'license_ping_ok';
	const EV_MARKETER_FRAMEWORK_EVALUATED                         = 'marketer_framework_evaluated';
	const EV_PERF_REGRESSION_DETECTED                             = 'perf_regression_detected';
	const EV_PERF_SNAPSHOT_TAKEN                                  = 'perf_snapshot_taken';
	const EV_PERF_THRESHOLD_EXCEEDED                              = 'perf_threshold_exceeded';
	const EV_PORTABILITY_EXPORT                                   = 'portability_export';
	const EV_PORTABILITY_IMPORT_APPLIED                           = 'portability_import_applied';
	const EV_PROMPT_FAILED                                        = 'prompt_failed';
	const EV_PROMPT_FETCHED                                       = 'prompt_fetched';
	const EV_PROMPT_RETRY                                         = 'prompt_retry';
	const EV_RELEASE_ACTIVATED                                    = 'release_activated';
	const EV_RELEASE_ARCHIVED                                     = 'release_archived';
	const EV_RELEASE_ROLLED_BACK                                  = 'release_rolled_back';
	const EV_RELEASE_STAGED                                       = 'release_staged';
	const EV_ROAD_ENTERED                                         = 'road_entered';
	const EV_ROW_DELETED                                          = 'row_deleted';
	const EV_RULESET_MATCH_SCORE                                  = 'ruleset_match_score';
	const EV_RULESET_SWITCHED                                     = 'ruleset_switched';
	const EV_SCENARIO_CREATED                                     = 'scenario_created';
	const EV_SCHEMA_ARCHIVED                                      = 'schema_archived';
	const EV_SCHEMA_DRAFT_SAVED                                   = 'schema_draft_saved';
	const EV_SCHEMA_PUBLISHED                                     = 'schema_published';
	const EV_SEEDER_COMPLETE                                      = 'seeder_complete';
	const EV_SIGNAL_EVALUATED                                     = 'signal_evaluated';
	const EV_SIGNAL_TRIGGERED                                     = 'signal_triggered';
	const EV_SOCIAL_PLUGIN_NOT_FOUND                              = 'social_plugin_not_found';
	const EV_STEP_CHANNEL_UNHANDLED                               = 'step_channel_unhandled';
	const EV_SURFACE_PUBLISHED                                    = 'surface_published';
	const EV_USER_FIELD_ARCHIVED                                  = 'user_field_archived';
	const EV_USER_FIELD_REGISTERED                                = 'user_field_registered';
	const EV_USER_FIELD_SENSITIVE_CHANGED                         = 'user_field_sensitive_changed';
	const EV_USER_FIELD_SET                                       = 'user_field_set';
	const EV_USER_FIELD_UPDATED                                   = 'user_field_updated';
	const EV_USER_STALLED                                         = 'user_stalled';
	const EV_VISUAL_DESIGNER_EDIT_SUBMITTED                       = 'visual_designer_edit_submitted';

	/**
	 * Return all registered event keys as an array — useful for validation tests.
	 */
	const EV_TELEMETRY_RATE_LIMITED              = 'telemetry_rate_limited';

	public static function all(): array {
		$ref = new \ReflectionClass( __CLASS__ );
		return array_values( $ref->getConstants() );
	}

	/**
	 * Verify a given event key is registered — use in tests and health checks.
	 */
	public static function is_registered( string $key ): bool {
		return in_array( $key, self::all(), true );
	}
}

// === ADDITION: ASK5.5 Phase A event keys ===
// ── Add to class SB_Event_Keys: ───────────────────────────────────────────────

/*
	// ── ASK5.5 — Store Policy ────────────────────────────────────────────────
	// ── v2.3 — Plugin lifecycle ─────────────────────────────────────────────
	const EV_PLUGIN_DEACTIVATED                                   = 'plugin_deactivated';

	const EV_STORE_POLICY_VIOLATION                               = 'store_policy_violation';

	// ── ASK5.5 — APO Lifecycle ───────────────────────────────────────────────
	const EV_APO_CREATED                                          = 'apo_created';
	const EV_APO_TRANSITIONED                                     = 'apo_transitioned';
	const EV_APO_EXPIRED                                          = 'apo_expired';
	const EV_APO_COMMITTED                                        = 'apo_committed';

	// ── ASK5.5 — Commit Gate ────────────────────────────────────────────────
	const EV_COMMIT_REQUEST_CREATED                               = 'commit_request_created';
	const EV_COMMIT_APPROVED                                      = 'commit_approved';
	const EV_COMMIT_REJECTED                                      = 'commit_rejected';
	const EV_COMMIT_EXECUTED                                      = 'commit_executed';
	const EV_COMMIT_FAILED                                        = 'commit_failed';

	// ── ASK5.5 — Authority Ledger ────────────────────────────────────────────
	const EV_AUTHORITY_EVENT_RECORDED                             = 'authority_event_recorded';
	const EV_COMPENSATING_ENTRY_CREATED                           = 'compensating_entry_created';
	const EV_AUTHORITY_LEDGER_ERROR                               = 'authority_ledger_error';

	// ── ASK5.5 — Provider Types ──────────────────────────────────────────────
	const EV_WORDPRESS_INTERNAL_INVOKED                           = 'wordpress_internal_invoked';
	const EV_LOCAL_LLM_INVOKED                                    = 'local_llm_invoked';
	const EV_CONNECTOR_ONLY_INVOKED                               = 'connector_only_invoked';
*/

// === ADDITION: Phase B event keys and bootstrap additions ===
// ── Add to class SB_Event_Keys: ───────────────────────────────────────────────
/*
	// ── ASK5.5 Phase B — Evidence ────────────────────────────────────────────
	const EV_EVIDENCE_CREATED                                     = 'evidence_created';
	const EV_EVIDENCE_LINKED                                      = 'evidence_linked';
	const EV_EVIDENCE_EXPORTED                                    = 'evidence_exported';

	// ── ASK5.5 Phase B — Review Workspace ────────────────────────────────────
	const EV_REVIEW_SESSION_OPENED                                = 'review_session_opened';
	const EV_REVIEW_SESSION_TRANSITIONED                          = 'review_session_transitioned';
	const EV_SIGNOFF_RECORDED                                     = 'signoff_recorded';
	const EV_SIGNOFF_VOIDED                                       = 'signoff_voided';
*/

// ── Add to SovereignBuilder::bootstrap_hooks(): ───────────────────────────────
/*
		// ASK5.5 Phase B module init
		add_action( 'init', [ 'SBEvidenceVault',      'init' ], 15 );
		add_action( 'init', [ 'SBKynvaricWorkspace',  'init' ], 15 );
*/

// === ADDITION: Phase C event keys and bootstrap additions ===
// ── Add to class SB_Event_Keys: ───────────────────────────────────────────────
/*
	// ── ASK5.5 Phase C — Entitlement ────────────────────────────────────────
	const EV_ENTITLEMENT_MAP_CREATED                              = 'entitlement_map_created';
	const EV_ENTITLEMENT_PROVISIONED                              = 'entitlement_provisioned';
	const EV_ENTITLEMENT_PROVISION_FAILED                         = 'entitlement_provision_failed';

	// ── ASK5.5 Phase C — Plaid ───────────────────────────────────────────────
	const EV_PLAID_SYNC_COMPLETE                                  = 'plaid_sync_complete';
	const EV_PLAID_WEBHOOK_RECEIVED                               = 'plaid_webhook_received';

	// ── ASK5.5 Phase C — Constraint Guard ────────────────────────────────────
	const EV_CONSTRAINT_GUARD_BLOCKED                             = 'constraint_guard_blocked';
*/

// ── Add to SovereignBuilder::bootstrap_hooks(): ───────────────────────────────
/*
		// ASK5.5 Phase C module init
		add_action( 'init', [ 'SBEntitlementEngine', 'init' ], 15 );
		add_action( 'init', [ 'SBPlaidConnector',    'init' ], 15 );
*/