<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Approval_Engine {

	// HARDEN-001 fix: type-specific capability map
	const APPROVAL_CAP_MAP = [
		'factory_output'         => 'review_sovereign_outputs',
		'social_post'            => 'approve_sovereign_social',
		'image_brief'            => 'approve_sovereign_social',
		'generated_image'        => 'approve_sovereign_social',
		'video_brief'            => 'approve_sovereign_social',
		'generated_video'        => 'approve_sovereign_social',
		'budget_approval'        => 'approve_sovereign_pricing',
		'analyst_recommendation' => 'manage_sovereign',
		'deployment'             => 'approve_sovereign_deployments',
		'blueprint_activation'   => 'manage_sovereign_blueprints',
		'schema_publish'         => 'manage_sovereign_schemas',
		'form_publish'           => 'manage_sovereign_forms',
		'surface_publish'        => 'manage_sovereign_surfaces',
		'connector_replay'       => 'manage_sovereign',
		'debugger_fix'           => 'manage_sovereign_debug',
	];

	public static function create_approval( $campaign_id, $approval_type, $payload ): int {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_approvals", [
			'campaign_id'   => absint( $campaign_id ),
			'approval_type' => sanitize_key( $approval_type ),
			'payload'       => wp_json_encode( $payload ),
			'status'        => 'pending',
			'created_at'    => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_CREATED, "Approval #{$id} type={$approval_type} queued.", get_current_user_id(), [ 'id' => $id, 'type' => $approval_type ] );
		return $id;
	}

	// BLOCKER-001 fix: writes reviewed_by, reviewed_at, operator_note
	public static function process_approval( int $id, string $action, string $note = '' ): bool {
		global $wpdb;
		if ( ! in_array( $action, [ 'approved', 'rejected' ], true ) ) {
			return false;
		}
		$approval = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d",
			$id
		) );
		if ( ! $approval || 'pending' !== $approval->status ) {
			return false;
		}

		// Check type-specific capability
		$required_cap = self::APPROVAL_CAP_MAP[ $approval->approval_type ] ?? 'manage_sovereign';
		if ( ! current_user_can( $required_cap ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_CAP_DENIED, "User lacks {$required_cap} for approval #{$id}", get_current_user_id(), [], 'error' );
			return false;
		}

		$wpdb->update( "{$wpdb->prefix}sb_approvals", [
			'status'       => $action,
			'operator_note'=> sanitize_textarea_field( $note ),
			'reviewed_by'  => get_current_user_id(),
			'reviewed_at'  => current_time( 'mysql' ),
		], [ 'id' => $id ] );

		SB_Event_Logger::log_audit( "approval_{$action}", "Approval #{$id} {$action}.", get_current_user_id(), [ 'id' => $id, 'note' => $note ] );

		if ( 'approved' === $action ) {
			do_action( 'sb_approval_approved', $id, $approval );
			// Trigger release materializer if applicable
			if ( class_exists( 'SBReleaseManager' ) ) {
				SBReleaseManager::materialize( $id );
			}
		} else {
			do_action( 'sb_approval_rejected', $id, $approval );
		}
		return true;
	}

	public static function handle_rest_approve( $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$id     = absint( $params['approval_id'] ?? 0 );
		$action = sanitize_key( $params['action'] ?? '' );
		$note   = sanitize_textarea_field( $params['note'] ?? '' );
		if ( ! $id || ! in_array( $action, [ 'approved', 'rejected' ], true ) ) {
			return SB_Extension_API::rest_error( 'invalid_params', 'Missing approval_id or action.', 400 );
		}
		$result = self::process_approval( $id, $action, $note );
		if ( ! $result ) {
			return SB_Extension_API::rest_error( 'approval_failed', 'Approval not found, already processed, or insufficient capability.', 422 );
		}
		return rest_ensure_response( [ 'success' => true, 'id' => $id, 'status' => $action ] );
	}

	public static function render_approval_hub_screen() {
		// ISSUE5 FIX: centralized auth resolver — screen shows only approvals the user can action.
		// Uses same APPROVAL_CAP_MAP as process_approval() to prevent UI/action drift.
		if ( ! current_user_can( 'review_sovereign_outputs' ) ) { wp_die( 'Forbidden.' ); }
		$guard = SBAdminGuard::require_tables( [ 'sb_approvals' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;
		// Build WHERE clause filtering by types the current user can actually approve
		$allowed_types = [];
		foreach ( self::APPROVAL_CAP_MAP as $type => $cap ) {
			if ( current_user_can( $cap ) ) {
				$allowed_types[] = $type;
			}
		}
		if ( empty( $allowed_types ) ) {
			// Fallback: show all pending if user has broad manage_sovereign
			if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'No approval types authorized.' ); }
			$pending = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_approvals WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50" );
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) );
			$pending = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE status = 'pending' AND approval_type IN ({$placeholders}) ORDER BY created_at DESC LIMIT 50",
					...$allowed_types
				)
			);
		}
		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Approval Hub</h1>';
		if ( isset( $_GET['processed'] ) ) {
			echo '<div class="notice notice-success"><p>Approval processed.</p></div>';
		}
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>ID</th><th>Type</th><th>Created</th><th>Payload Preview</th><th>Actions</th></tr></thead><tbody>';
		if ( $pending ) {
			foreach ( $pending as $a ) {
				$payload_preview = esc_html( substr( $a->payload ?? '', 0, 80 ) ) . '...';
				echo '<tr>';
				echo '<td>' . esc_html( $a->id ) . '</td>';
				echo '<td><strong>' . esc_html( $a->approval_type ) . '</strong></td>';
				echo '<td>' . esc_html( $a->created_at ) . '</td>';
				echo '<td><code>' . $payload_preview . '</code></td>';
				echo '<td>';
				echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( 'sb_process_approval' );
				echo '<input type="hidden" name="action" value="sb_process_approval">';
				echo '<input type="hidden" name="approval_id" value="' . esc_attr( $a->id ) . '">';
				echo '<input type="hidden" name="approval_action" value="approved">';
				echo '<textarea name="operator_note" placeholder="Note (optional)" style="width:150px;height:30px;vertical-align:middle"></textarea> ';
				echo '<button class="button button-primary">Approve</button>';
				echo '</form> ';
				echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( 'sb_process_approval' );
				echo '<input type="hidden" name="action" value="sb_process_approval">';
				echo '<input type="hidden" name="approval_id" value="' . esc_attr( $a->id ) . '">';
				echo '<input type="hidden" name="approval_action" value="rejected">';
				echo '<button class="button">Reject</button>';
				echo '</form>';
				echo '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="5">No pending approvals.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function render_approval_detail_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$id = absint( $_GET['approval_id'] ?? 0 );
		if ( ! $id ) { wp_die( 'No approval ID.' ); }
		$approval = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d", $id ) );
		if ( ! $approval ) { wp_die( 'Approval not found.' ); }
		echo '<div class="wrap">';
		echo '<h1>Approval #' . esc_html( $id ) . ' — ' . esc_html( $approval->approval_type ) . '</h1>';
		echo '<table class="form-table">';
		echo '<tr><th>Status</th><td><strong>' . esc_html( $approval->status ) . '</strong></td></tr>';
		echo '<tr><th>Created</th><td>' . esc_html( $approval->created_at ) . '</td></tr>';
		echo '<tr><th>Reviewed At</th><td>' . esc_html( $approval->reviewed_at ?? '—' ) . '</td></tr>';
		echo '<tr><th>Reviewed By</th><td>' . esc_html( $approval->reviewed_by ? get_userdata( $approval->reviewed_by )->display_name ?? $approval->reviewed_by : '—' ) . '</td></tr>';
		echo '<tr><th>Operator Note</th><td>' . esc_html( $approval->operator_note ?? '—' ) . '</td></tr>';
		echo '<tr><th>Payload</th><td><pre style="max-height:300px;overflow:auto;background:#f4f4f4;padding:10px">' . esc_html( wp_json_encode( json_decode( $approval->payload ), JSON_PRETTY_PRINT ) ) . '</pre></td></tr>';
		echo '</table>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-approvals' ) ) . '" class="button">Back to Approvals</a></p>';
		echo '</div>';
	}
}