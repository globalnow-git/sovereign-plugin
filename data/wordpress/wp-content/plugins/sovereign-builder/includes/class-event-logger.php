<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Event_Logger {

	public static function log_audit( $action, $message, $user_id = 0, $context = [], $level = 'info' ) {
		$level = sanitize_key( $level );
		
		if ( 'verbose' === $level && ! self::is_verbose() ) {
			return;
		}
		if ( 'debug' === $level && ! self::is_debug() ) {
			return;
		}

		if ( in_array( $level, [ 'verbose', 'debug' ], true ) ) {
			SB_Telemetry_Buffer::push( $action, $message, $user_id, $context, $level );
			return;
		}

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'sb_audit_log', [
			'user_id'   => absint( $user_id ? $user_id : get_current_user_id() ),
			'action'    => sanitize_text_field( $action ),
			'message'   => sanitize_textarea_field( $message ),
			'log_level' => $level,
			'context'   => wp_json_encode( $context ),
			'created_at'=> current_time( 'mysql' ),
		] );
	}

	public static function is_verbose() {
		return get_option( 'sb_log_mode', 'terse' ) === 'verbose';
	}

	public static function is_debug() {
		return get_option( 'sb_log_mode', 'terse' ) === 'debug';
	}

	public static function render_audit_screen() {
		if ( ! current_user_can( 'view_sovereign_audit_logs' ) ) {
			wp_die( __( 'Unauthorized audit footprint entry path forbidden.' ) );
		}
		global $wpdb;

		// Process structural filters securely
		$selected_level = isset( $_GET['log_level'] ) ? sanitize_key( $_GET['log_level'] ) : '';
		$selected_action = isset( $_GET['action_filter'] ) ? sanitize_text_field( $_GET['action_filter'] ) : '';

		$query = "SELECT * FROM {$wpdb->prefix}sb_audit_log WHERE 1=1";
		$params = [];

		if ( $selected_level ) {
			$query .= " AND log_level = %s";
			$params[] = $selected_level;
		}
		if ( $selected_action ) {
			$query .= " AND action = %s";
			$params[] = $selected_action;
		}

		$query .= " ORDER BY id DESC LIMIT 100";
		
		if ( ! empty( $params ) ) {
			$logs = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		} else {
			$logs = $wpdb->get_results( $query );
		}

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>' . esc_html__( 'Sovereign Audit Trail Logging Module' ) . '</h1>';
		
		// Filter interface implementation markup Layout
		// BUG-021: Nonce check on filter form submission
		if ( $selected_level || $selected_action ) { check_admin_referer( 'sb_audit_filter', '_sb_nonce' ); }
		echo '<input type="hidden" name="page" value="sb-audit-logs" />';
		echo '<select name="log_level"><option value="">All Levels</option><option value="info"' . selected( $selected_level, 'info', false ) . '>Info</option><option value="verbose"' . selected( $selected_level, 'verbose', false ) . '>Verbose</option><option value="error"' . selected( $selected_level, 'error', false ) . '>Error</option><option value="debug"' . selected( $selected_level, 'debug', false ) . '>Debug</option></select> ';
		echo '<input type="text" name="action_filter" placeholder="Filter by action name..." value="' . esc_attr( $selected_action ) . '" /> ';
		echo '<button type="submit" class="button button-primary">Apply Filtering Matrix</button>';
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped" style="box-shadow:0 1px 3px rgba(0,0,0,0.1); border-radius:4px; overflow:hidden;">';
		echo '<thead><tr><th>ID</th><th>Timestamp</th><th>Level</th><th>Action Key</th><th>Message Details</th><th>Structural Context Preview</th></tr></thead>';
		echo '<tbody>';
		if ( $logs ) {
			foreach ( $logs as $log ) {
				echo '<tr>';
				echo '<td>' . esc_html( $log->id ) . '</td>';
				echo '<td>' . esc_html( $log->created_at ) . '</td>';
				echo '<td><span class="sb-badge sb-badge-' . esc_attr( $log->log_level ) . '">' . esc_html( strtoupper( $log->log_level ) ) . '</span></td>';
				echo '<td><strong>' . esc_html( $log->action ) . '</strong></td>';
				echo '<td>' . esc_html( $log->message ) . '</td>';
				echo '<td><pre style="margin:0; font-size:11px; max-height:80px; overflow-y:auto; background:#f4f4f4; padding:5px; border-radius:3px;">' . esc_html( wp_json_encode( json_decode( $log->context ), JSON_PRETTY_PRINT ) ) . '</pre></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="6">Zero records registered matching runtime trace filters.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}