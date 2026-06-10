<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Debugger {

	// Ask4 preserved tables + Ask5 additions
	private static function get_all_tables(): array {
		// Delegates to SB_Installer — single source of truth for all table names.
		// Do not duplicate this list; add new tables only in SB_Installer::get_all_tables().
		return SB_Installer::get_all_tables();
	}

	public static function scan_system( $scope = 'full' ): array {
		global $wpdb;
		$snapshot = [];
		$snapshot['tables'] = [];
		foreach ( self::get_all_tables() as $table ) {
			$tbl_full = $wpdb->prefix . $table;
			$snapshot['tables'][ $table ] = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl_full ) ) === $tbl_full ) ? 'exists' : 'missing';
		}
		$snapshot['errors_logged']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_audit_log WHERE log_level = 'error'" );
		$snapshot['stuck_steps']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_journey_steps WHERE status = 'queued' AND scheduled_at < NOW()" );
		$snapshot['cron_health']    = wp_next_scheduled( 'sb_check_signals_cron' ) ? 'healthy' : 'stuck_or_missing';
		$snapshot['missing_tables'] = array_keys( array_filter( $snapshot['tables'], fn( $s ) => 'missing' === $s ) );
		$snapshot['php_version']    = PHP_VERSION;
		$snapshot['wp_version']     = get_bloginfo( 'version' );
		$snapshot['memory_limit']   = ini_get( 'memory_limit' );
		$snapshot['db_version']     = get_option( SB_Installer::DB_VERSION_OPTION, 'unknown' );
		return $snapshot;
	}

	public static function analyze_run( $run_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_factory_runs WHERE id = %d", absint( $run_id ) ), ARRAY_A );
	}

	public static function generate_diagnosis( array $snapshot ): array {
		$issues = [];
		if ( ! empty( $snapshot['missing_tables'] ) ) {
			$issues[] = [ 'severity' => 'critical', 'message' => 'Missing tables: ' . implode( ', ', $snapshot['missing_tables'] ), 'fix' => 'Run /repair-system endpoint.' ];
		}
		if ( $snapshot['errors_logged'] > 0 ) {
			$issues[] = [ 'severity' => 'warning', 'message' => $snapshot['errors_logged'] . ' error(s) in audit log.', 'fix' => 'Review audit log screen.' ];
		}
		if ( $snapshot['stuck_steps'] > 0 ) {
			$issues[] = [ 'severity' => 'warning', 'message' => $snapshot['stuck_steps'] . ' stuck journey step(s).', 'fix' => 'Run Many Roads cron manually.' ];
		}
		if ( 'stuck_or_missing' === $snapshot['cron_health'] ) {
			$issues[] = [ 'severity' => 'critical', 'message' => 'Cron sb_check_signals_cron is not scheduled.', 'fix' => 'Deactivate and reactivate plugin.' ];
		}
		$score = max( 0, 100 - ( count( $snapshot['missing_tables'] ) * 10 ) - ( $snapshot['errors_logged'] > 0 ? 15 : 0 ) - ( $snapshot['stuck_steps'] > 5 ? 10 : 0 ) - ( 'stuck_or_missing' === $snapshot['cron_health'] ? 20 : 0 ) );
		return [ 'issues' => $issues, 'health_score' => $score, 'diagnosis' => empty( $issues ) ? 'System healthy.' : implode( ' | ', array_column( $issues, 'message' ) ) ];
	}

	/**
	 * @deprecated Use SBDebuggerConsole::run_scheduled_health_check() directly.
	 *             This stub exists only for backward compatibility — do not add new call sites here.
	 * @see SBDebuggerConsole::run_scheduled_health_check()
	 */
	public static function run_scheduled_health_check() {
		_doing_it_wrong( __METHOD__, 'Call SBDebuggerConsole::run_scheduled_health_check() directly.', '1.1.0' );
		if ( class_exists( 'SBDebuggerConsole' ) ) {
			SBDebuggerConsole::run_scheduled_health_check();
		}
	}

	public static function run_health_check() {
		$snapshot  = self::scan_system();
		$diagnosis = self::generate_diagnosis( $snapshot );
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_debug_sessions", [
			'scope'          => 'full',
			'snapshot_json'  => wp_json_encode( $snapshot ),
			'diagnosis_text' => $diagnosis['diagnosis'],
			'health_score'   => (int) $diagnosis['health_score'],
			'issues_found'   => count( $diagnosis['issues'] ),
			'created_at'     => current_time( 'mysql' ),
		] );
	}

	public static function is_safe_mode(): bool {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return true; }
		$active_rulesets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_rulesets WHERE status = 'active'" );
		return ( 0 === $active_rulesets );
	}

	public static function render_safe_mode() {
		echo '<div class="wrap" style="background:#fff;padding:30px;border-left:4px solid #dc3232;margin-top:20px;">';
		echo '<h2>Sovereign Builder — Safe Mode</h2>';
		echo '<p>No active rulesets found. System is in safe mode.</p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-rulesets' ) ) . '" class="button button-primary">Manage Rulesets</a></p>';
		echo '</div>';
	}

	public static function render_debugger_screen() {
		if ( ! current_user_can( 'manage_sovereign_debug' ) ) { wp_die( 'Forbidden.' ); }
		$snapshot  = self::scan_system();
		$diagnosis = self::generate_diagnosis( $snapshot );
		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Sovereign Builder Debugger</h1>';
		echo '<p><strong>Health Score:</strong> ' . esc_html( $diagnosis['health_score'] ) . '/100 &nbsp; <strong>PHP:</strong> ' . esc_html( $snapshot['php_version'] ) . ' &nbsp; <strong>WP:</strong> ' . esc_html( $snapshot['wp_version'] ) . ' &nbsp; <strong>DB Version:</strong> ' . esc_html( $snapshot['db_version'] ) . '</p>';
		if ( ! empty( $diagnosis['issues'] ) ) {
			echo '<div class="notice notice-error"><ul>';
			foreach ( $diagnosis['issues'] as $issue ) {
				echo '<li><strong>' . esc_html( strtoupper( $issue['severity'] ) ) . '</strong>: ' . esc_html( $issue['message'] ) . ' &mdash; <em>' . esc_html( $issue['fix'] ) . '</em></li>';
			}
			echo '</ul></div>';
		} else {
			echo '<div class="notice notice-success"><p>All systems healthy.</p></div>';
		}
		echo '<h3>Table Status (' . count( self::get_all_tables() ) . ' tables)</h3>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;max-height:400px;overflow-y:auto;">';
		foreach ( $snapshot['tables'] as $tbl => $st ) {
			$color = ( 'exists' === $st ) ? '#46b450' : '#dc3232';
			echo '<div style="padding:5px;background:#f9f9f9;border-left:3px solid ' . $color . '"><code>' . esc_html( $tbl ) . '</code>: <strong style="color:' . $color . '">' . esc_html( strtoupper( $st ) ) . '</strong></div>';
		}
		echo '</div>';
		echo '<p style="margin-top:20px"><button class="button button-primary" onclick="fetch(sbAdminContext.restBase+\'sovereign-builder/v1/repair-system\',{method:\'POST\',headers:{\'X-WP-Nonce\':sbAdminContext.nonce}}).then(r=>r.json()).then(d=>alert(JSON.stringify(d,null,2)))">Run Repair System</button></p>';
		echo '</div>';
	}
}