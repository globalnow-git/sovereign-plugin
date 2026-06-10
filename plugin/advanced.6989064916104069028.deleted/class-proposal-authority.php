<?php
/**
 * SBProposalAuthority — APO lifecycle management.
 *
 * Manages AI Proposal Objects (APOs) through their full lifecycle:
 * draft → queued_review → approved_for_commit → committed | rejected | expired | superseded
 *
 * APOs are non-authoritative. They cannot write to operational or audit stores
 * directly. Only commit-execute transforms an APO into an authority event.
 *
 * @package SovereignBuilder
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBProposalAuthority {

	// ── Valid lifecycle states ────────────────────────────────────────────────
	const STATES = [
		'draft',
		'queued_review',
		'approved_for_commit',
		'rejected',
		'expired',
		'superseded',
		'committed',
	];

	// ── Valid state transitions ───────────────────────────────────────────────
	const TRANSITIONS = [
		'draft'               => [ 'queued_review', 'rejected', 'expired' ],
		'queued_review'       => [ 'approved_for_commit', 'rejected', 'expired', 'superseded' ],
		'approved_for_commit' => [ 'committed', 'rejected', 'expired', 'superseded' ],
		'rejected'            => [],
		'expired'             => [],
		'superseded'          => [],
		'committed'           => [],
	];

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'proposal-authority', '2.0.0', 'SBProposalAuthority' );
		} );
		// Auto-expire proposals daily
		add_action( 'sb_daily_license_ping', [ __CLASS__, 'expire_stale_proposals' ] );
	}

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Create a new APO.
	 *
	 * @param  array $args {
	 *   domain_key, proposal_type, subject_type, subject_id,
	 *   payload (array), confidence_score, review_required,
	 *   expires_at, supersedes_proposal_id, agent_slug
	 * }
	 * @return int|WP_Error  New APO id or error.
	 */
	public static function create( array $args ): int|WP_Error {
		global $wpdb;

		// Store policy gate
		$policy = SBStorePolicy::assert_can_write( 'apo', 'sb_apo_store' );
		if ( is_wp_error( $policy ) ) { return $policy; }

		$payload      = $args['payload'] ?? [];
		$payload_hash = self::hash_payload( $payload );

		$row = [
			'proposal_uuid'          => wp_generate_uuid4(),
			'domain_key'             => sanitize_key( $args['domain_key'] ?? 'general' ),
			'proposal_type'          => sanitize_key( $args['proposal_type'] ?? '' ),
			'subject_type'           => sanitize_key( $args['subject_type'] ?? '' ),
			'subject_id'             => absint( $args['subject_id'] ?? 0 ),
			'payload_json'           => wp_json_encode( $payload ),
			'payload_hash'           => $payload_hash,
			'confidence_score'       => (float) ( $args['confidence_score'] ?? 0.0 ),
			'status'                 => 'draft',
			'created_by_user_id'     => get_current_user_id(),
			'created_by_agent_slug'  => sanitize_key( $args['agent_slug'] ?? '' ),
			'review_required'        => (int) ( $args['review_required'] ?? 1 ),
			'expires_at'             => $args['expires_at'] ?? null,
			'supersedes_proposal_id' => absint( $args['supersedes_proposal_id'] ?? 0 ) ?: null,
			'created_at'             => current_time( 'mysql' ),
			'updated_at'             => current_time( 'mysql' ),
		];

		$inserted = $wpdb->insert( "{$wpdb->prefix}sb_apo_store", $row );
		if ( ! $inserted ) {
			return new WP_Error( 'apo_insert_failed', 'Failed to create APO.', [ 'status' => 500 ] );
		}

		$apo_id = (int) $wpdb->insert_id;

		// Record initial transition
		self::record_transition( $apo_id, '', 'draft', 'system', 0, 'created', '' );

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_APO_CREATED,
			"APO {$apo_id} created. Type: {$row['proposal_type']}.",
			get_current_user_id(),
			[ 'apo_id' => $apo_id, 'domain' => $row['domain_key'] ]
		);

		return $apo_id;
	}

	// ── Transition ────────────────────────────────────────────────────────────

	/**
	 * Transition an APO to a new state.
	 *
	 * @param  int    $apo_id
	 * @param  string $to_status   Target state.
	 * @param  string $reason_code Workflow reason.
	 * @param  string $note        Operator note.
	 * @param  string $actor_type  human|system|agent
	 * @return true|WP_Error
	 */
	public static function transition(
		int    $apo_id,
		string $to_status,
		string $reason_code = '',
		string $note = '',
		string $actor_type = 'human'
	): bool|WP_Error {
		global $wpdb;

		$apo = self::get( $apo_id );
		if ( ! $apo ) {
			return new WP_Error( 'apo_not_found', "APO {$apo_id} not found.", [ 'status' => 404 ] );
		}

		$from_status = $apo['status'];
		$allowed     = self::TRANSITIONS[ $from_status ] ?? [];

		if ( ! in_array( $to_status, $allowed, true ) ) {
			return new WP_Error(
				'apo_invalid_transition',
				"Cannot transition APO from '{$from_status}' to '{$to_status}'.",
				[ 'status' => 422, 'from' => $from_status, 'to' => $to_status ]
			);
		}

		// Check expiry
		if ( ! empty( $apo['expires_at'] ) && strtotime( $apo['expires_at'] ) < time() ) {
			$wpdb->update( "{$wpdb->prefix}sb_apo_store",
				[ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $apo_id ]
			);
			return new WP_Error( 'apo_expired', "APO {$apo_id} has expired.", [ 'status' => 422 ] );
		}

		$wpdb->update(
			"{$wpdb->prefix}sb_apo_store",
			[ 'status' => $to_status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $apo_id ]
		);

		self::record_transition( $apo_id, $from_status, $to_status, $actor_type, get_current_user_id(), $reason_code, $note );

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_APO_TRANSITIONED,
			"APO {$apo_id} transitioned {$from_status} → {$to_status}.",
			get_current_user_id(),
			[ 'apo_id' => $apo_id, 'from' => $from_status, 'to' => $to_status, 'reason' => $reason_code ]
		);

		do_action( "sb_apo_{$to_status}", $apo_id, $apo );
		return true;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	public static function get( int $apo_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_apo_store WHERE id = %d", $apo_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function list( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $filters['status'];
		}
		if ( ! empty( $filters['domain_key'] ) ) {
			$where[]  = 'domain_key = %s';
			$values[] = $filters['domain_key'];
		}
		if ( ! empty( $filters['proposal_type'] ) ) {
			$where[]  = 'proposal_type = %s';
			$values[] = $filters['proposal_type'];
		}

		$offset    = ( max( 1, $page ) - 1 ) * min( 200, max( 1, $per_page ) );
		$limit     = min( 200, max( 1, $per_page ) );
		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$values
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_apo_store WHERE {$where_sql}", ...$values )
				: "SELECT COUNT(*) FROM {$wpdb->prefix}sb_apo_store WHERE {$where_sql}"
		);
		$rows = $wpdb->get_results(
			$values
				? $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_apo_store WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...[...$values, $limit, $offset] )
				: "SELECT * FROM {$wpdb->prefix}sb_apo_store WHERE {$where_sql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
			ARRAY_A
		);

		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'items' => $rows ?: [] ];
	}

	// ── Payload hashing ───────────────────────────────────────────────────────

	/**
	 * Canonical payload hash — sorted keys, JSON_UNESCAPED_UNICODE, SHA-256.
	 * Used for both APO creation and commit-execute verification.
	 */
	public static function hash_payload( array $payload ): string {
		// Use same canonical sort as SBVisualDesigner::normalize_graph:
		// recursive ksort → JSON_UNESCAPED_UNICODE → SHA-256
		// Defined inline to avoid class dependency order issues at call time.
		$sort = static function( array &$arr ) use ( &$sort ): void {
			ksort( $arr );
			foreach ( $arr as &$v ) {
				if ( is_array( $v ) ) { $sort( $v ); }
			}
		};
		$sort( $payload );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', (string) $json );
	}

	// ── Expiry sweep ──────────────────────────────────────────────────────────

	public static function expire_stale_proposals(): void {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}sb_apo_store
				 SET status = 'expired', updated_at = %s
				 WHERE expires_at IS NOT NULL
				   AND expires_at < %s
				   AND status IN ('draft','queued_review','approved_for_commit')",
				$now, $now
			)
		);
	}

	// ── REST wrappers ─────────────────────────────────────────────────────────

	public static function handle_rest_create( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$result = self::create( $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message(), 'code' => $result->get_error_code() ], 422 );
		}
		return new WP_REST_Response( [ 'apo_id' => $result ], 201 );
	}

	public static function handle_rest_transition( WP_REST_Request $request ): WP_REST_Response {
		$params   = (array) $request->get_json_params();
		$apo_id   = absint( $params['apo_id'] ?? 0 );
		$to       = sanitize_key( $params['to_status'] ?? '' );
		$reason   = sanitize_text_field( $params['reason_code'] ?? '' );
		$note     = sanitize_textarea_field( $params['note'] ?? '' );
		$result   = self::transition( $apo_id, $to, $reason, $note );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message(), 'rule' => $result->get_error_code() ], 422 );
		}
		return new WP_REST_Response( [ 'success' => true, 'apo_id' => $apo_id, 'to_status' => $to ], 200 );
	}

	public static function handle_rest_list( WP_REST_Request $request ): WP_REST_Response {
		$filters  = [
			'status'        => sanitize_key( $request->get_param( 'status' ) ?? '' ),
			'domain_key'    => sanitize_key( $request->get_param( 'domain_key' ) ?? '' ),
			'proposal_type' => sanitize_key( $request->get_param( 'proposal_type' ) ?? '' ),
		];
		$page     = absint( $request->get_param( 'page' ) ?? 1 ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ?? 50 );
		return new WP_REST_Response( self::list( $filters, $page, $per_page ), 200 );
	}

	public static function handle_rest_detail( WP_REST_Request $request ): WP_REST_Response {
		$id  = absint( $request->get_param( 'id' ) ?? 0 );
		$apo = self::get( $id );
		if ( ! $apo ) {
			return new WP_REST_Response( [ 'error' => 'APO not found.' ], 404 );
		}
		return new WP_REST_Response( $apo, 200 );
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	private static function record_transition(
		int    $apo_id,
		string $from,
		string $to,
		string $actor_type,
		int    $actor_id,
		string $reason,
		string $note
	): void {
		global $wpdb;
		$policy = SBStorePolicy::assert_can_write( 'apo_transition', 'sb_apo_transitions' );
		if ( is_wp_error( $policy ) ) { return; }
		$wpdb->insert( "{$wpdb->prefix}sb_apo_transitions", [
			'proposal_id' => $apo_id,
			'from_status' => $from,
			'to_status'   => $to,
			'actor_type'  => $actor_type,
			'actor_id'    => $actor_id,
			'reason_code' => sanitize_key( $reason ),
			'note'        => sanitize_textarea_field( $note ),
			'created_at'  => current_time( 'mysql' ),
		] );
	}
}

// ── Note: ksort_recursive removed — hash_payload uses inline canonical sort ────
// ── SBVisualDesigner::normalize_graph uses its own private recursive_ksort ────