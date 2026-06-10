<?php
/**
 * SBCommitGate — Dual-control, hash-bound commit execution.
 *
 * Handles commit request creation, multi-approver collection,
 * SBConstraintGuard validation, and final atomic execution.
 *
 * commit-execute is integration point 0 for SBConstraintGuard.
 * All five rules must pass before any write occurs.
 *
 * @package SovereignBuilder
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBCommitGate {

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'commit-gate', '2.0.0', 'SBCommitGate' );
		} );
	}

	// ── Create commit request ─────────────────────────────────────────────────

	/**
	 * Create a commit request for an approved APO.
	 *
	 * @param  int    $apo_id
	 * @param  string $commit_type     Domain action description.
	 * @param  string $target_store    Table the commit will write to.
	 * @param  string $sensitivity     low|medium|high|critical
	 * @param  int    $policy_id       Dual-control policy ID (0 = none).
	 * @return int|WP_Error
	 */
	public static function create_commit_request(
		int    $apo_id,
		string $commit_type,
		string $target_store,
		string $sensitivity  = 'medium',
		int    $policy_id    = 0
	): int|WP_Error {
		global $wpdb;

		$apo = SBProposalAuthority::get( $apo_id );
		if ( ! $apo ) {
			return new WP_Error( 'apo_not_found', "APO {$apo_id} not found.", [ 'status' => 404 ] );
		}
		if ( $apo['status'] !== 'approved_for_commit' ) {
			return new WP_Error(
				'apo_status',
				"APO must be in 'approved_for_commit' status. Current: {$apo['status']}.",
				[ 'status' => 422, 'rule' => 'apo_status' ]
			);
		}

		$policy = SBStorePolicy::assert_can_write( 'commit_request', 'sb_commit_requests' );
		if ( is_wp_error( $policy ) ) { return $policy; }

		$wpdb->insert( "{$wpdb->prefix}sb_commit_requests", [
			'apo_id'              => $apo_id,
			'commit_type'         => sanitize_key( $commit_type ),
			'target_store'        => sanitize_key( $target_store ),
			'sensitivity_level'   => sanitize_key( $sensitivity ),
			'approved_payload_hash' => $apo['payload_hash'],
			'policy_id'           => $policy_id ?: null,
			'status'              => 'pending',
			'requested_by_user_id'=> get_current_user_id(),
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		] );

		$commit_id = (int) $wpdb->insert_id;

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_COMMIT_REQUEST_CREATED,
			"Commit request {$commit_id} created for APO {$apo_id}.",
			get_current_user_id(),
			[ 'commit_id' => $commit_id, 'apo_id' => $apo_id ]
		);

		return $commit_id;
	}

	// ── Add approver ──────────────────────────────────────────────────────────

	/**
	 * Record an individual approver for a commit request.
	 * A user who created the request cannot approve if policy disallows self-approval.
	 *
	 * @return true|WP_Error
	 */
	public static function add_approver( int $commit_id, string $note = '' ): bool|WP_Error {
		global $wpdb;

		$commit = self::get_commit( $commit_id );
		if ( ! $commit ) {
			return new WP_Error( 'commit_not_found', "Commit request {$commit_id} not found.", [ 'status' => 404 ] );
		}
		if ( ! in_array( $commit['status'], [ 'pending', 'ready' ], true ) ) {
			return new WP_Error( 'commit_not_approvable', "Commit {$commit_id} is not in approvable state.", [ 'status' => 422 ] );
		}

		$user_id = get_current_user_id();

		// Check self-approval policy
		if ( $commit['policy_id'] ) {
			$policy = self::get_policy( (int) $commit['policy_id'] );
			if ( $policy && $policy['disallow_self_approval'] && (int) $commit['requested_by_user_id'] === $user_id ) {
				return new WP_Error( 'self_approval_disallowed', 'Self-approval is not permitted for this action.', [ 'status' => 403, 'rule' => 'dual_control' ] );
			}
		}

		// Prevent duplicate approvals by same user
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_commit_approvers WHERE commit_request_id = %d AND user_id = %d", $commit_id, $user_id )
		);
		if ( $existing > 0 ) {
			return new WP_Error( 'duplicate_approval', 'You have already approved this commit request.', [ 'status' => 422 ] );
		}

		$policy_record = SBStorePolicy::assert_can_write( 'commit_approver', 'sb_commit_approvers' );
		if ( is_wp_error( $policy_record ) ) { return $policy_record; }

		$wpdb->insert( "{$wpdb->prefix}sb_commit_approvers", [
			'commit_request_id' => $commit_id,
			'user_id'           => $user_id,
			'approved_at'       => current_time( 'mysql' ),
			'note'              => sanitize_textarea_field( $note ),
		] );

		// Check if min_distinct_approvers threshold now met
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sb_commit_approvers WHERE commit_request_id = %d", $commit_id )
		);

		$min_required = 1;
		if ( $commit['policy_id'] ) {
			$pol = self::get_policy( (int) $commit['policy_id'] );
			if ( $pol ) { $min_required = (int) $pol['min_distinct_approvers']; }
		}

		if ( $count >= $min_required ) {
			$wpdb->update( "{$wpdb->prefix}sb_commit_requests",
				[ 'status' => 'ready', 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $commit_id ]
			);
		}

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_COMMIT_APPROVED,
			"User {$user_id} approved commit request {$commit_id}. Approvers: {$count}/{$min_required}.",
			$user_id,
			[ 'commit_id' => $commit_id, 'approver_count' => $count ]
		);

		return true;
	}

	// ── Reject ────────────────────────────────────────────────────────────────

	public static function reject_commit( int $commit_id, string $note = '' ): bool|WP_Error {
		global $wpdb;
		$commit = self::get_commit( $commit_id );
		if ( ! $commit ) { return new WP_Error( 'commit_not_found', "Commit {$commit_id} not found.", [ 'status' => 404 ] ); }
		$wpdb->update( "{$wpdb->prefix}sb_commit_requests",
			[ 'status' => 'rejected', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $commit_id ]
		);
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_COMMIT_REJECTED, "Commit {$commit_id} rejected.", get_current_user_id(), [ 'note' => $note ] );
		return true;
	}

	// ── Execute — Integration Point 0 ─────────────────────────────────────────

	/**
	 * Execute a ready commit request.
	 *
	 * SBConstraintGuard validates all five rules before any write.
	 * Failures return structured errors with rule identifiers.
	 *
	 * @param  int    $commit_id
	 * @param  callable $executor   fn( array $apo ): int|WP_Error
	 *                              Caller-provided function that performs the
	 *                              actual domain write and returns authority_event_id.
	 * @return int|WP_Error         Authority event ID on success.
	 */
	public static function execute( int $commit_id, callable $executor ): int|WP_Error {
		global $wpdb;

		$commit = self::get_commit( $commit_id );
		if ( ! $commit ) {
			return new WP_Error( 'commit_not_found', "Commit {$commit_id} not found.", [ 'status' => 404 ] );
		}

		$apo = SBProposalAuthority::get( (int) $commit['apo_id'] );
		if ( ! $apo ) {
			return new WP_Error( 'apo_not_found', "APO {$commit['apo_id']} not found.", [ 'status' => 404 ] );
		}

		// ── Integration Point 0: SBConstraintGuard ───────────────────────────
		$guard = SBConstraintGuard::validate_commit( $commit, $apo );
		if ( is_wp_error( $guard ) ) { return $guard; }

		// Atomic status transition — prevents double execution on concurrent requests
		// UPDATE only proceeds if status is still 'ready'; check rows_affected to detect race
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}sb_commit_requests
			 SET status = 'executing', approved_by_user_id = %d, updated_at = %s
			 WHERE id = %d AND status = 'ready'",
			get_current_user_id(),
			current_time( 'mysql' ),
			$commit_id
		) );

		if ( $wpdb->rows_affected === 0 ) {
			return new WP_Error(
				'commit_already_executing',
				"Commit {$commit_id} is already executing or was already executed. Concurrent execution prevented.",
				[ 'status' => 409, 'rule' => 'apo_status' ]
			);
		}

		// Call domain executor
		$authority_event_id = $executor( $apo );

		if ( is_wp_error( $authority_event_id ) ) {
			$wpdb->update( "{$wpdb->prefix}sb_commit_requests",
				[ 'status' => 'failed', 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $commit_id ]
			);
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_COMMIT_FAILED, "Commit {$commit_id} execution failed.", get_current_user_id(), [ 'error' => $authority_event_id->get_error_message() ], 'error' );
			return $authority_event_id;
		}

		// Mark committed
		$wpdb->update( "{$wpdb->prefix}sb_commit_requests",
			[
				'status'               => 'committed',
				'committed_event_id'   => $authority_event_id,
				'executed_at'          => current_time( 'mysql' ),
				'updated_at'           => current_time( 'mysql' ),
			],
			[ 'id' => $commit_id ]
		);

		// Transition APO to committed
		SBProposalAuthority::transition( (int) $apo['id'], 'committed', 'commit_executed', "Commit {$commit_id} executed.", 'system' );

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_COMMIT_EXECUTED,
			"Commit {$commit_id} executed. Authority event: {$authority_event_id}.",
			get_current_user_id(),
			[ 'commit_id' => $commit_id, 'authority_event_id' => $authority_event_id ]
		);

		do_action( 'sb_commit_executed', $commit_id, $authority_event_id, $apo );
		return $authority_event_id;
	}

	// ── History ───────────────────────────────────────────────────────────────

	public static function get_history( int $apo_id = 0, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$where = $apo_id ? $wpdb->prepare( 'WHERE apo_id = %d', $apo_id ) : '';
		$limit  = min( 200, max( 1, $per_page ) );
		$offset = ( max( 1, $page ) - 1 ) * $limit;
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_commit_requests {$where}" );
		$rows   = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_commit_requests {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
			ARRAY_A
		);
		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'items' => $rows ?: [] ];
	}

	// ── REST wrappers ─────────────────────────────────────────────────────────

	public static function handle_rest_commit_request( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$result = self::create_commit_request(
			absint( $p['apo_id'] ?? 0 ),
			sanitize_key( $p['commit_type'] ?? '' ),
			sanitize_key( $p['target_store'] ?? '' ),
			sanitize_key( $p['sensitivity_level'] ?? 'medium' ),
			absint( $p['policy_id'] ?? 0 )
		);
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message(), 'rule' => $result->get_error_code() ], 422 ); }
		return new WP_REST_Response( [ 'commit_id' => $result ], 201 );
	}

	public static function handle_rest_commit_approve( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$result = self::add_approver( absint( $p['commit_id'] ?? 0 ), sanitize_textarea_field( $p['note'] ?? '' ) );
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message(), 'rule' => $result->get_error_code() ], 422 ); }
		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	public static function handle_rest_commit_reject( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$result = self::reject_commit( absint( $p['commit_id'] ?? 0 ), sanitize_textarea_field( $p['note'] ?? '' ) );
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	public static function handle_rest_commit_execute( WP_REST_Request $request ): WP_REST_Response {
		$p         = (array) $request->get_json_params();
		$commit_id = absint( $p['commit_id'] ?? 0 );
		// Default executor: record authority event from APO payload
		$result = self::execute( $commit_id, function( array $apo ) use ( $commit_id ): int|WP_Error {
			$payload = json_decode( $apo['payload_json'] ?? '{}', true ) ?: [];
			$payload['apo_id']    = $apo['id'];
			$payload['commit_id'] = $commit_id;
			return SBAuditLedgerPlus::record_authority_event(
				$apo['proposal_type'] . '_committed',
				$payload,
				get_current_user_id(),
				$commit_id
			);
		} );
		if ( is_wp_error( $result ) ) {
			$data = [ 'error' => $result->get_error_message(), 'rule' => $result->get_error_code() ];
			$extra = $result->get_error_data();
			if ( ! empty( $extra['rule'] ) ) { $data['rule'] = $extra['rule']; }
			return new WP_REST_Response( $data, 422 );
		}
		return new WP_REST_Response( [ 'authority_event_id' => $result ], 200 );
	}

	public static function handle_rest_commit_history( WP_REST_Request $request ): WP_REST_Response {
		$apo_id   = absint( $request->get_param( 'apo_id' ) ?? 0 );
		$page     = absint( $request->get_param( 'page' ) ?? 1 ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ?? 50 );
		return new WP_REST_Response( self::get_history( $apo_id, $page, $per_page ), 200 );
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	public static function get_commit( int $commit_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_commit_requests WHERE id = %d", $commit_id ), ARRAY_A );
		return $row ?: null;
	}

	private static function get_policy( int $policy_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_dual_control_policies WHERE id = %d", $policy_id ), ARRAY_A );
		return $row ?: null;
	}
}