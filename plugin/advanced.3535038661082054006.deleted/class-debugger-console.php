<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBDebuggerConsole
 * Extends SB_Debugger with full diagnostic, AI remediation, approval-gated fix, and verification.
 */
// ── SBFixRegistry ─────────────────────────────────────────────────────────────
// Canonical allowlist of debugger and repair-system fix operations.
// Any fix_id not in REGISTRY is rejected. All entries are idempotent and safe.
// This is the single source of truth for what automated fixing is permitted.

class SBFixRegistry {

	const REGISTRY = [
		// fix_id => [ label, callable, reversible, description ]
		'reschedule_cron' => [
			'label'       => 'Reschedule cron hooks',
			'callable'    => [ 'SB_Installer', 'schedule_cron' ],
			'reversible'  => true,
			'description' => 'Re-registers all SB cron events. Safe; WP deduplicates on reschedule.',
		],
		'repair_tables' => [
			'label'       => 'Repair database tables',
			'callable'    => [ 'SB_Installer', 'create_tables' ],
			'reversible'  => true,
			'description' => 'Runs dbDelta to create or update missing SB tables. Never drops columns.',
		],
		'repair_capabilities' => [
			'label'       => 'Repair role capabilities',
			'callable'    => [ 'SB_Installer', 'create_capabilities' ],
			'reversible'  => true,
			'description' => 'Re-seeds all SB capabilities to the administrator role.',
		],
		'repair_ask55_tables' => [
			'label'       => 'Repair ASK5.5 regulated workflow tables',
			'callable'    => [ 'SBFixRegistry', '_repair_ask55_tables' ],
			'reversible'  => true,
			'description' => 'Runs ASK5.5 Phase A/B migrations and seeds dual-control policies.',
		],
		'flush_rewrite_rules' => [
			'label'       => 'Flush rewrite rules',
			'callable'    => 'flush_rewrite_rules',
			'reversible'  => true,
			'description' => 'Flushes WordPress rewrite rules. Regenerated on next page load.',
		],
		'clear_sb_transients' => [
			'label'       => 'Clear SB transient cache',
			'callable'    => [ 'SBFixRegistry', '_clear_sb_transients' ],
			'reversible'  => true,
			'description' => 'Deletes all transients with sb_ prefix. They regenerate automatically.',
		],
		'seed_signal_definitions' => [
			'label'       => 'Re-seed signal definitions',
			'callable'    => [ 'SB_Installer', 'seed_signal_definitions' ],
			'reversible'  => true,
			'description' => 'Inserts or updates default signal definitions. Idempotent INSERT IGNORE.',
		],
	];

	/**
	 * Check whether a fix_id is permitted.
	 * Returns the registry entry or a WP_Error.
	 */
	public static function get( string $fix_id ): array|WP_Error {
		if ( ! array_key_exists( $fix_id, self::REGISTRY ) ) {
			return new WP_Error(
				'fix_not_allowed',
				"Fix ID '{$fix_id}' is not in the allowed fix registry. Permitted: " . implode( ', ', array_keys( self::REGISTRY ) ),
				[ 'status' => 400 ]
			);
		}
		return self::REGISTRY[ $fix_id ];
	}

	/**
	 * Execute a registered fix. Returns [ 'success', 'label', 'result' ] or WP_Error.
	 */
	public static function execute( string $fix_id ): array|WP_Error {
		$entry = self::get( $fix_id );
		if ( is_wp_error( $entry ) ) { return $entry; }

		$callable = $entry['callable'];
		try {
			if ( is_array( $callable ) && $callable[0] === 'SBFixRegistry' ) {
				$result = call_user_func( $callable );
			} elseif ( is_callable( $callable ) ) {
				$result = call_user_func( $callable );
			} else {
				$result = null;
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'fix_exception', 'Fix threw exception: ' . $e->getMessage(), [ 'status' => 500 ] );
		}

		return [ 'success' => true, 'label' => $entry['label'], 'result' => $result ];
	}

	// ── Internal callables for complex fixes ──────────────────────────────────

	public static function _repair_ask55_tables(): string {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		if ( function_exists( 'sb_55_maybe_alter_blueprints' ) ) {
			sb_55_maybe_alter_blueprints( $wpdb->prefix );
		}
		if ( function_exists( 'sb_55_phase_a_create_tables' ) ) {
			sb_55_phase_a_create_tables( $charset_collate, $wpdb->prefix );
		}
		if ( function_exists( 'sb_55_phase_b_create_tables' ) ) {
			sb_55_phase_b_create_tables( $charset_collate, $wpdb->prefix );
		}
		if ( function_exists( 'sb_55_seed_dual_control_policies' ) ) {
			sb_55_seed_dual_control_policies( $wpdb->prefix );
		}
		if ( function_exists( 'sb_55_seed_kynvaric_caps' ) ) {
			sb_55_seed_kynvaric_caps();
		}
		return 'ASK5.5 tables and policies repaired.';
	}

	public static function _clear_sb_transients(): string {
		global $wpdb;
		$deleted = $wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE \'_transient_sb%\' OR option_name LIKE \'_transient_timeout_sb%\'"
		);
		return "Cleared {$deleted} SB transients.";
	}

	/**
	 * Capture a lightweight system state snapshot for before/after comparison.
	 * Returns an array with cron hooks present, table count, capability presence.
	 */
	public static function snapshot(): array {
		global $wpdb;
		$cron_hooks    = array_keys( _get_cron_array() ?: [] );
		$sb_cron_count = count( array_intersect( SB_Installer::CRON_HOOKS, $cron_hooks ) );
		$table_count   = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE()
			 AND TABLE_NAME LIKE '{$wpdb->prefix}sb_%'"
		);
		$role          = get_role( 'administrator' );
		$has_manage    = $role ? (int) $role->has_cap( 'manage_sovereign' ) : 0;
		return [
			'sb_cron_hooks_active' => $sb_cron_count,
			'sb_table_count'       => $table_count,
			'has_manage_cap'       => $has_manage,
			'ts'                   => time(),
		];
	}
}


class SBDebuggerConsole extends SB_Debugger {

	/**
	 * Run a full environment scan.
	 *
	 * @return array
	 */
	public static function run_environment_scan(): array {
		$scan = [
			'php_version'       => PHP_VERSION,
			'wp_version'        => get_bloginfo( 'version' ),
			'wp_memory_limit'   => ini_get( 'memory_limit' ),
			'wp_memory_used_mb' => round( memory_get_usage( true ) / 1048576, 2 ),
			'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'wp_cache'          => defined( 'WP_CACHE' ) && WP_CACHE,
			'multisite'         => is_multisite(),
			'active_theme'      => get_stylesheet(),
			'upload_max_size'   => ini_get( 'upload_max_filesize' ),
			'post_max_size'     => ini_get( 'post_max_size' ),
			'max_execution_time'=> ini_get( 'max_execution_time' ),
		];

		$issues = [];
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$issues[] = [ 'severity' => 'warning', 'message' => 'PHP 8.0+ recommended. Running ' . PHP_VERSION ];
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! defined( 'WP_DEBUG_LOG' ) ) {
			$issues[] = [ 'severity' => 'info', 'message' => 'WP_DEBUG is on but WP_DEBUG_LOG is off — errors not logged to file.' ];
		}

		$session_id = self::create_session( 'environment', $scan, $issues );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_SCAN_COMPLETE, 'Environment scan complete', get_current_user_id(), [ 'session_id' => $session_id, 'issues' => count( $issues ) ], 'info' );

		return [ 'session_id' => $session_id, 'scan' => $scan, 'issues' => $issues ];
	}

	/**
	 * Run a plugin inventory scan.
	 *
	 * @return array
	 */
	public static function run_plugin_inventory(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );
		$inventory      = [];
		$issues         = [];

		foreach ( $all_plugins as $path => $plugin ) {
			$is_active = in_array( $path, $active_plugins, true );
			$inventory[] = [
				'name'      => $plugin['Name'],
				'version'   => $plugin['Version'],
				'path'      => $path,
				'is_active' => $is_active,
			];
		}

		$session_id = self::create_session( 'plugins', [ 'count' => count( $inventory ) ], $issues );

		return [ 'session_id' => $session_id, 'plugins' => $inventory, 'active_count' => count( $active_plugins ), 'issues' => $issues ];
	}

	/**
	 * Run a cron inspection.
	 *
	 * @return array
	 */
	public static function run_cron_inspection(): array {
		$crons   = _get_cron_array() ?: [];
		$hooks   = [];
		$issues  = [];
		$now     = time();

		foreach ( $crons as $timestamp => $cron ) {
			foreach ( $cron as $hook => $events ) {
				$overdue = $timestamp < $now - 600;
				if ( $overdue ) {
					$issues[] = [ 'severity' => 'warning', 'message' => "Cron hook '{$hook}' is overdue by " . round( ( $now - $timestamp ) / 60 ) . ' minutes.' ];
				}
				$hooks[] = [
					'hook'      => $hook,
					'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'overdue'   => $overdue,
					'schedules' => array_keys( $events ),
				];
			}
		}

		// Check SB cron hooks are all scheduled
		$expected_hooks = SB_Installer::CRON_HOOKS;
		foreach ( $expected_hooks as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				$issues[] = [ 'severity' => 'warning', 'message' => "Expected cron hook not scheduled: {$hook}" ];
			}
		}

		$session_id = self::create_session( 'cron', [ 'hook_count' => count( $hooks ) ], $issues );

		return [ 'session_id' => $session_id, 'hooks' => $hooks, 'issues' => $issues ];
	}

	/**
	 * Ingest WP debug log and create findings.
	 *
	 * @return array
	 */
	public static function ingest_error_log(): array {
		$log_path = WP_CONTENT_DIR . '/debug.log';
		$findings = [];

		if ( ! file_exists( $log_path ) ) {
			return [ 'error' => 'Debug log not found. Enable WP_DEBUG_LOG in wp-config.php.' ];
		}

		$lines = array_slice( file( $log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [], -200 );

		$session_id = self::create_session( 'error_log', [ 'lines_analyzed' => count( $lines ) ], [] );

		foreach ( $lines as $line ) {
			if ( preg_match( '/(PHP Fatal|PHP Warning|PHP Error|PHP Notice)/i', $line ) ) {
				$severity = str_contains( strtolower( $line ), 'fatal' ) ? 'critical' : 'warning';
				$finding  = self::create_finding( $session_id, $severity, 'error_log', substr( $line, 0, 500 ) );
				$findings[] = $finding;
			}
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_SCAN_COMPLETE, 'Error log ingested', get_current_user_id(), [ 'session_id' => $session_id, 'findings' => count( $findings ) ], 'info' );

		return [ 'session_id' => $session_id, 'findings' => $findings, 'lines_scanned' => count( $lines ) ];
	}

	/**
	 * Run hook inspection.
	 *
	 * @param string $hook_name
	 * @return array
	 */
	public static function run_hook_inspection( string $hook_name ): array {
		global $wp_filter;

		// When called without a specific hook, return a list of all registered hooks
		if ( ! $hook_name ) {
			$all_hooks = array_keys( $wp_filter );
			sort( $all_hooks );
			return [
				'note'       => 'Pass hook_name param to inspect a specific hook.',
				'hook_count' => count( $all_hooks ),
				'hooks'      => array_slice( $all_hooks, 0, 100 ), // Cap at 100 for performance
			];
		}

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return [ 'hook' => $hook_name, 'callbacks' => [], 'note' => 'Hook not registered.' ];
		}

		$callbacks = [];
		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $items ) {
			foreach ( $items as $item ) {
				$fn = $item['function'];
				$name = is_array( $fn )
					? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1]
					: ( is_string( $fn ) ? $fn : 'closure' );

				$callbacks[] = [
					'priority'  => $priority,
					'function'  => $name,
					'accepted_args' => $item['accepted_args'],
				];
			}
		}

		return [ 'hook' => $hook_name, 'callback_count' => count( $callbacks ), 'callbacks' => $callbacks ];
	}

	/**
	 * Generate AI remediation plan for a finding.
	 *
	 * @param int $finding_id
	 * @return array|WP_Error
	 */
	public static function generate_remediation_plan( int $finding_id ) {
		global $wpdb;

		if ( ! current_user_can( 'manage_sovereign_debug' ) ) {
			return new WP_Error( 'unauthorized', 'Insufficient capability.', [ 'status' => 403 ] );
		}

		$finding = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_debug_findings WHERE id = %d",
			$finding_id
		) );

		if ( ! $finding ) {
			return new WP_Error( 'not_found', 'Finding not found.', [ 'status' => 404 ] );
		}

		// Use AI Integrator if available
		if ( class_exists( 'SBAIIntegrator' ) ) {
			$context = [
				'finding_id'   => $finding_id,
				'severity'     => $finding->severity,
				'category'     => $finding->category,
				'description'  => $finding->description,
				'affected_type'=> $finding->affected_type,
			];

			$result = SBAIIntegrator::invoke( 'sb_debug_remediation', $context );

			if ( ! is_wp_error( $result ) ) {
				// Store proposed fix in finding
				$fix_steps = $result['response'] ?? 'AI remediation unavailable.';
				$wpdb->update( "{$wpdb->prefix}sb_debug_findings",
					[ 'proposed_fix_json' => wp_json_encode( [ 'ai_steps' => $fix_steps ] ) ],
					[ 'id' => $finding_id ]
				);

				SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_REMEDIATION_GENERATED, "Remediation plan generated for finding #{$finding_id}", get_current_user_id(), [], 'info' );

				return [ 'success' => true, 'finding_id' => $finding_id, 'fix_steps' => $fix_steps ];
			}
		}

		// Fallback: heuristic plan based on category
		$heuristic = self::heuristic_fix( $finding );
		$wpdb->update( "{$wpdb->prefix}sb_debug_findings",
			[ 'proposed_fix_json' => wp_json_encode( $heuristic ) ],
			[ 'id' => $finding_id ]
		);

		return [ 'success' => true, 'finding_id' => $finding_id, 'fix_steps' => $heuristic ];
	}

	/**
	 * Heuristic fix suggestions by category.
	 *
	 * @param object $finding
	 * @return array
	 */
	private static function heuristic_fix( object $finding ): array {
		$plans = [
			'error_log'   => [ 'Check the file and line indicated in the error.', 'Review recent plugin updates.', 'Verify PHP version compatibility.' ],
			'cron'        => [ 'Run SB_Installer::schedule_cron() via /repair-system.', 'Check wp-cron is enabled in wp-config.php.', 'Verify server-side cron is not blocking WordPress cron.' ],
			'database'    => [ 'Run /repair-system to re-run dbDelta.', 'Check database user permissions.', 'Verify table prefix in wp-config.php.' ],
			'environment' => [ 'Update PHP to 8.0+.', 'Increase memory_limit in php.ini.', 'Enable WP_DEBUG_LOG for better diagnostics.' ],
		];

		return $plans[ $finding->category ] ?? [ 'Investigate ' . $finding->description ];
	}

	/**
	 * Apply a fix — INTERNAL only, called after HITM approval.
	 *
	 * @param int    $finding_id
	 * @param string $fix_id
	 * @return array
	 */
	public static function apply_fix_internal( int $finding_id, string $fix_id ): array {
		global $wpdb;

		// Registry check — reject any fix_id not explicitly permitted
		$entry = SBFixRegistry::get( $fix_id );
		if ( is_wp_error( $entry ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FIX_APPLIED,
				"Fix '{$fix_id}' rejected — not in registry (finding #{$finding_id})",
				get_current_user_id(), [ 'fix_id' => $fix_id ], 'warning'
			);
			return [ 'success' => false, 'error' => $entry->get_error_message(), 'finding_id' => $finding_id ];
		}

		// Capture before-state
		$state_before = SBFixRegistry::snapshot();

		// Execute the fix via registry callable
		$fix_result = SBFixRegistry::execute( $fix_id );
		if ( is_wp_error( $fix_result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FIX_APPLIED,
				"Fix '{$fix_id}' failed (finding #{$finding_id}): " . $fix_result->get_error_message(),
				get_current_user_id(), [ 'fix_id' => $fix_id ], 'error'
			);
			return [ 'success' => false, 'error' => $fix_result->get_error_message(), 'finding_id' => $finding_id ];
		}

		// Capture after-state
		$state_after = SBFixRegistry::snapshot();

		// Mark finding resolved
		$wpdb->update( "{$wpdb->prefix}sb_debug_findings", [
			'status'          => 'resolved',
			'fix_applied_at'  => current_time( 'mysql' ),
			'fix_applied_by'  => get_current_user_id(),
		], [ 'id' => $finding_id ] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FIX_APPLIED,
			"Fix '{$fix_id}' applied to finding #{$finding_id} (label: {$entry['label']})",
			get_current_user_id(),
			[ 'fix_id' => $fix_id, 'before' => $state_before, 'after' => $state_after ],
			'info'
		);

		do_action( 'sb_debugger_fix_applied', $finding_id, $fix_id );

		return [
			'success'      => true,
			'finding_id'   => $finding_id,
			'fix_id'       => $fix_id,
			'label'        => $entry['label'],
			'state_before' => $state_before,
			'state_after'  => $state_after,
		];
	}

	/**
	 * Verify a fix was successful — re-scans relevant scope.
	 *
	 * @param int $finding_id
	 * @return array
	 */
	public static function verify_fix( int $finding_id ): array {
		global $wpdb;

		$finding = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_debug_findings WHERE id = %d",
			$finding_id
		) );

		if ( ! $finding ) {
			return [ 'verified' => false, 'error' => 'Finding not found.' ];
		}

		// Re-run scan based on category
		$re_scan = match( $finding->category ) {
			'cron'        => self::run_cron_inspection(),
			'environment' => self::run_environment_scan(),
			'error_log'   => self::ingest_error_log(),
			default       => [],
		};

		$no_new_issues = empty( $re_scan['issues'] ?? [] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FIX_VERIFIED, "Fix verification for finding #{$finding_id}: " . ( $no_new_issues ? 'PASS' : 'FAIL' ), get_current_user_id(), [], 'info' );

		return [
			'finding_id'    => $finding_id,
			'verified'      => $no_new_issues,
			're_scan_result'=> $re_scan,
		];
	}

	/**
	 * Daily health check — called by sb_debug_health_check cron. (Fixes BLOCKER-004)
	 */
	public static function run_scheduled_health_check(): void {
		global $wpdb;

		$issues      = [];
		$table_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '{$wpdb->prefix}sb_%'" );
		$expected    = count( SB_Installer::get_all_tables() ); // Derived from single source — no hardcoded 70

		if ( $table_count < $expected ) {
			$issues[] = "Expected {$expected} sb_ tables, found {$table_count}. Run /repair-system.";
		}

		// Check expected cron hooks
		foreach ( SB_Installer::CRON_HOOKS as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				$issues[] = "Cron hook not scheduled: {$hook}";
			}
		}

		$health_score = empty( $issues ) ? 100 : max( 0, 100 - count( $issues ) * 20 );

		$wpdb->insert( "{$wpdb->prefix}sb_debug_sessions", [
			'scope'         => 'health_check',
			'snapshot_json' => wp_json_encode( [
				'table_count'  => $table_count,
				'cron_hooks'   => count( SB_Installer::CRON_HOOKS ),
				'issues'       => $issues,
			] ),
			'diagnosis_text'=> implode( "\n", $issues ) ?: 'All checks passed.',
			'health_score'  => $health_score,
			'issues_found'  => count( $issues ),
			'created_at'    => current_time( 'mysql' ),
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUG_HEALTH_CHECK_COMPLETE, "Daily health check complete. Score: {$health_score}", 0, [ 'issues' => count( $issues ) ], $health_score < 80 ? 'warning' : 'info' );

		do_action( 'sb_debug_health_check_complete', $health_score, $issues );
	}

	/**
	 * Create a debug session record.
	 *
	 * @param string $scope
	 * @param array  $snapshot
	 * @param array  $issues
	 * @return int  session ID
	 */
	private static function create_session( string $scope, array $snapshot, array $issues ): int {
		global $wpdb;

		$health_score = empty( $issues ) ? 100 : max( 0, 100 - count( $issues ) * 15 );

		$wpdb->insert( "{$wpdb->prefix}sb_debug_sessions", [
			'scope'         => sanitize_key( $scope ),
			'snapshot_json' => wp_json_encode( $snapshot ),
			'diagnosis_text'=> implode( "\n", array_column( $issues, 'message' ) ),
			'health_score'  => $health_score,
			'issues_found'  => count( $issues ),
			'created_at'    => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Create a debug finding record.
	 *
	 * @param int    $session_id
	 * @param string $severity
	 * @param string $category
	 * @param string $description
	 * @return array
	 */
	private static function create_finding( int $session_id, string $severity, string $category, string $description ): array {
		global $wpdb;

		$wpdb->insert( "{$wpdb->prefix}sb_debug_findings", [
			'session_id'  => $session_id,
			'severity'    => sanitize_key( $severity ),
			'category'    => sanitize_key( $category ),
			'description' => $description,
			'status'      => 'open',
			'created_at'  => current_time( 'mysql' ),
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FINDING_CREATED, "Finding created: [{$severity}] {$category}", get_current_user_id(), [ 'session_id' => $session_id ], $severity === 'critical' ? 'error' : 'warning' );

		return [
			'id'          => (int) $wpdb->insert_id,
			'severity'    => $severity,
			'category'    => $category,
			'description' => $description,
		];
	}

	/**
	 * Render debugger console admin screen.
	 */
	public static function render_debugger_console_screen(): void {
		if ( ! current_user_can( 'manage_sovereign_debug' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_debug_sessions', 'sb_debug_findings' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$sessions = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_debug_sessions ORDER BY created_at DESC LIMIT 10"
		);
		$open_findings = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_debug_findings WHERE status = 'open' ORDER BY severity ASC, created_at DESC LIMIT 50"
		);

		echo '<div class="wrap"><h1>AI Debugger Console</h1>';
		// Nginx fallback notice
		$server_sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( $server_sw && stripos( $server_sw, 'nginx' ) !== false && class_exists( 'SBEdgeCompiler' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Nginx Detected:</strong> Edge static rules require manual nginx.conf config — .htaccess not supported on this server.</p>';
			echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow-x:auto;">' . esc_html( SBEdgeCompiler::get_nginx_config_notice() ) . '</pre></div>';
		}

		// Health score from last session
		$last_session = $sessions[0] ?? null;
		if ( $last_session ) {
			$score = (int) $last_session->health_score;
			$color = $score >= 80 ? 'green' : ( $score >= 50 ? 'orange' : 'red' );
			echo '<div style="margin-bottom:20px;"><strong>Last Health Score: </strong>';
			echo '<span style="font-size:24px;color:' . esc_attr( $color ) . ';font-weight:bold;">' . $score . '/100</span>';
			echo ' — ' . esc_html( $last_session->created_at ) . '</div>';
		}

		// Scan buttons
		echo '<div style="margin-bottom:20px;">';
		$base = esc_url_raw( get_rest_url( null, 'sovereign-builder/v1/debugger/scan/' ) );
		foreach ( [ 'environment', 'plugins', 'cron', 'error_log' ] as $scan_type ) {
			echo '<button class="button sb-debugger-scan" data-scan="' . esc_attr( $scan_type ) . '" data-rest="' . esc_attr( $base . $scan_type ) . '" style="margin-right:8px;">Run ' . esc_html( ucfirst( $scan_type ) ) . ' Scan</button>';
		}
		echo '</div>';

		// Open findings
		echo '<h2>Open Findings (' . count( $open_findings ) . ')</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Category</th><th>Description</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $open_findings as $f ) {
			$color = match( $f->severity ) { 'critical' => 'red', 'warning' => 'orange', default => 'inherit' };
			echo '<tr>';
			echo '<td style="color:' . esc_attr( $color ) . '">' . esc_html( strtoupper( $f->severity ) ) . '</td>';
			echo '<td>' . esc_html( $f->category ) . '</td>';
			echo '<td>' . esc_html( substr( $f->description, 0, 120 ) ) . '</td>';
			echo '<td>' . esc_html( $f->created_at ) . '</td>';
			echo '<td><button class="button sb-remediate" data-finding="' . (int) $f->id . '">AI Fix Plan</button></td>';
			echo '</tr>';
		}
		if ( empty( $open_findings ) ) {
			echo '<tr><td colspan="5">No open findings. Run a scan to check.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Missing logic methods ────────────────────────────────────────────────────

	public static function get_findings( int $session_id = 0 ): array {
		global $wpdb;
		$where = $session_id ? $wpdb->prepare( 'WHERE session_id = %d', $session_id ) : '';
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_debug_findings {$where} ORDER BY severity DESC, id DESC LIMIT 100"
		, ARRAY_A );
		return $rows ?: [];
	}

	public static function get_finding( int $finding_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_debug_findings WHERE id = %d", $finding_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function apply_approved_fix( int $approval_id ): array {
		global $wpdb;
		$approval = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d AND status = 'approved'", $approval_id )
		);
		if ( ! $approval ) {
			return [ 'success' => false, 'error' => 'Approval not found or not approved.' ];
		}
		$payload    = json_decode( $approval->payload ?? '{}', true );
		$fix_id     = sanitize_key( $payload['fix_id'] ?? ( $payload['fix_command'] ?? '' ) );
		$finding_id = absint( $payload['finding_id'] ?? 0 );

		if ( ! $fix_id ) {
			return [ 'success' => false, 'error' => 'No fix_id in approval payload.' ];
		}

		// Registry check — prevents approved-but-not-allowed fixes from executing
		$entry = SBFixRegistry::get( $fix_id );
		if ( is_wp_error( $entry ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FIX_APPLIED,
				"Approved fix '{$fix_id}' rejected — not in registry (approval #{$approval_id})",
				get_current_user_id(), [ 'fix_id' => $fix_id, 'approval_id' => $approval_id ], 'warning'
			);
			return [ 'success' => false, 'error' => $entry->get_error_message() ];
		}

		// Delegate to apply_fix_internal which captures before/after state
		if ( $finding_id ) {
			return self::apply_fix_internal( $finding_id, $fix_id );
		}

		// No finding_id — execute directly via registry (e.g. repair-system flow)
		$state_before = SBFixRegistry::snapshot();
		$fix_result   = SBFixRegistry::execute( $fix_id );
		$state_after  = SBFixRegistry::snapshot();

		if ( is_wp_error( $fix_result ) ) {
			return [ 'success' => false, 'error' => $fix_result->get_error_message() ];
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEBUGGER_FIX_APPLIED,
			"Approved fix '{$fix_id}' executed (approval #{$approval_id})",
			get_current_user_id(),
			[ 'fix_id' => $fix_id, 'approval_id' => $approval_id, 'before' => $state_before, 'after' => $state_after ],
			'info'
		);
		return [ 'success' => true, 'fix_id' => $fix_id, 'label' => $entry['label'], 'state_before' => $state_before, 'state_after' => $state_after ];
	}

	// ── REST wrapper methods ─────────────────────────────────────────────────────

	public static function handle_rest_scan( WP_REST_Request $request ): WP_REST_Response {
		$scope = sanitize_key( $request->get_param( 'scope' ) ?? 'full' );
		// Dispatch to real scan methods by scope — run_environment_scan() takes no params
		$data = match( $scope ) {
			'environment' => self::run_environment_scan(),
			'plugins'     => self::run_plugin_inventory(),
			'cron'        => self::run_cron_inspection(),
			'errorlog'    => self::ingest_error_log(),
			'hooks'       => self::run_hook_inspection(
				sanitize_key( $request->get_param( 'hook_name' ) ?? '' )
			),
			default       => array_merge(
				self::run_environment_scan(),
				[ 'plugins' => self::run_plugin_inventory() ],
				[ 'cron'    => self::run_cron_inspection() ]
			),
		};
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_findings( WP_REST_Request $request ): WP_REST_Response {
		$session_id = absint( $request->get_param( 'session_id' ) ?? 0 );
		$data = self::get_findings( $session_id );
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_finding_detail( WP_REST_Request $request ): WP_REST_Response {
		$id   = absint( $request->get_param( 'id' ) ?? 0 );
		$data = self::get_finding( $id );
		if ( ! $data ) {
			return new WP_REST_Response( [ 'error' => 'Finding not found.' ], 404 );
		}
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_remediate( WP_REST_Request $request ): WP_REST_Response {
		$finding_id = absint( $request->get_param( 'finding_id' ) ?? 0 );
		if ( ! $finding_id ) {
			return new WP_REST_Response( [ 'error' => 'finding_id is required.' ], 400 );
		}
		$data = self::generate_remediation_plan( $finding_id );
		return new WP_REST_Response( $data, 200 );
	}

	public static function handle_rest_apply_fix( WP_REST_Request $request ): WP_REST_Response {
		$approval_id = absint( ( (array) $request->get_json_params() )['approval_id'] ?? 0 );
		if ( ! $approval_id ) {
			return new WP_REST_Response( [ 'error' => 'approval_id required.' ], 400 );
		}
		$result = self::apply_approved_fix( $approval_id );
		return new WP_REST_Response( $result, $result['success'] ? 200 : 422 );
	}

	public static function handle_rest_verify( WP_REST_Request $request ): WP_REST_Response {
		$finding_id = absint( $request->get_param( 'finding_id' ) ?? 0 );
		if ( ! $finding_id ) {
			return new WP_REST_Response( [ 'error' => 'finding_id is required.' ], 400 );
		}
		$data = self::verify_fix( $finding_id );
		return new WP_REST_Response( $data, 200 );
	}

}