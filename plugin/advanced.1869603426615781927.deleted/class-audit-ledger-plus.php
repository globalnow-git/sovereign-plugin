<?php
/**
 * SBAuditLedgerPlus — Append-only authority ledger with compensating entries.
 *
 * All writes to sb_authority_events are forward-only. No row may be updated
 * or deleted after creation. Corrections use compensating entries that
 * reference original events.
 *
 * Hash chain: each event records its own hash and the prior event's hash,
 * enabling tamper-evidence verification via ledger_integrity_check().
 *
 * @package SovereignBuilder
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBAuditLedgerPlus {

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'audit-ledger-plus', '2.0.0', 'SBAuditLedgerPlus' );
		} );
	}

	// ── Record authority event ────────────────────────────────────────────────

	/**
	 * Record a new authority event (append-only).
	 *
	 * @param  string $event_type  e.g. form_publish_committed, blueprint_graph_committed
	 * @param  array  $payload     Event body — will be JSON-encoded.
	 * @param  int    $caused_by   User ID of human actor.
	 * @param  int    $commit_id   Source commit request ID (0 if direct).
	 * @return int|WP_Error        New event ID.
	 */
	public static function record_authority_event(
		string $event_type,
		array  $payload,
		int    $caused_by = 0,
		int    $commit_id = 0
	): int|WP_Error {
		global $wpdb;

		$policy = SBStorePolicy::assert_can_write( 'authority_event', 'sb_authority_events' );
		if ( is_wp_error( $policy ) ) { return $policy; }

		// Hash chain integrity requires serialized inserts.
		// Lock the last row with SELECT FOR UPDATE so concurrent calls
		// queue rather than reading the same prior_hash simultaneously.
		$wpdb->query( 'START TRANSACTION' );

		// Build hash chain — prior_hash read inside transaction after lock
		$prior_hash = (string) $wpdb->get_var(
			"SELECT event_hash FROM {$wpdb->prefix}sb_authority_events
			 ORDER BY id DESC LIMIT 1 FOR UPDATE"
		);
		$prior_hash = $prior_hash ?: '';
		$event_uuid = wp_generate_uuid4();

		ksort_recursive( $payload );
		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		$event_hash   = hash( 'sha256', $event_uuid . $event_type . (string) $payload_json . $prior_hash );

		$inserted = $wpdb->insert( "{$wpdb->prefix}sb_authority_events", [
			'event_uuid'              => $event_uuid,
			'domain_key'              => sanitize_key( $payload['domain_key'] ?? 'general' ),
			'event_type'              => sanitize_key( $event_type ),
			'aggregate_type'          => sanitize_key( $payload['aggregate_type'] ?? '' ),
			'aggregate_id'            => absint( $payload['aggregate_id'] ?? 0 ),
			'payload_json'            => $payload_json,
			'caused_by_user_id'       => $caused_by ?: get_current_user_id(),
			'source_commit_request_id'=> $commit_id ?: null,
			'prior_event_hash'        => $prior_hash,
			'event_hash'              => $event_hash,
			'created_at'              => current_time( 'mysql' ),
		] );

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'authority_event_insert_failed', 'Failed to record authority event.', [ 'status' => 500 ] );
		}

		$event_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_AUTHORITY_EVENT_RECORDED,
			"Authority event {$event_id} recorded. Type: {$event_type}.",
			$caused_by ?: get_current_user_id(),
			[ 'event_id' => $event_id, 'event_type' => $event_type ]
		);

		return $event_id;
	}

	// ── Compensating entry ────────────────────────────────────────────────────

	/**
	 * Create a compensating entry for an existing authority event.
	 *
	 * Both the correcting authority event and the compensating_entries row
	 * are written in a single transaction. Neither commits if either fails.
	 *
	 * @param  int    $original_event_id  Event being corrected.
	 * @param  string $correction_type    reverse|amend|void_forward
	 * @param  string $reason_code
	 * @param  string $operator_note
	 * @param  array  $correcting_payload Payload for the new correcting event.
	 * @return int|WP_Error               New compensating_entries ID.
	 */
	public static function compensate(
		int    $original_event_id,
		string $correction_type,
		string $reason_code,
		string $operator_note,
		array  $correcting_payload = []
	): int|WP_Error {
		global $wpdb;

		// Verify original event exists
		$original = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, event_type FROM {$wpdb->prefix}sb_authority_events WHERE id = %d", $original_event_id ),
			ARRAY_A
		);
		if ( ! $original ) {
			return new WP_Error( 'authority_event_not_found', "Original event {$original_event_id} not found.", [ 'status' => 404 ] );
		}

		// Atomic transaction — both tables or neither
		$wpdb->query( 'START TRANSACTION' );

		try {
			// 1. Record correcting authority event
			$correcting_payload['correction_of_event_id'] = $original_event_id;
			$correcting_payload['correction_type']         = $correction_type;
			$correcting_payload['reason_code']             = $reason_code;

			$new_event_id = self::record_authority_event(
				"correction_{$correction_type}",
				$correcting_payload,
				get_current_user_id()
			);
			if ( is_wp_error( $new_event_id ) ) { throw new \RuntimeException( $new_event_id->get_error_message() ); }

			// 2. Record compensating entry link
			$policy = SBStorePolicy::assert_can_write( 'compensating', 'sb_compensating_entries' );
			if ( is_wp_error( $policy ) ) { throw new \RuntimeException( $policy->get_error_message() ); }

			$wpdb->insert( "{$wpdb->prefix}sb_compensating_entries", [
				'original_event_id'     => $original_event_id,
				'compensating_event_id' => $new_event_id,
				'correction_type'       => sanitize_key( $correction_type ),
				'reason_code'           => sanitize_key( $reason_code ),
				'operator_note'         => sanitize_textarea_field( $operator_note ),
				'created_at'            => current_time( 'mysql' ),
			] );
			$comp_id = (int) $wpdb->insert_id;

			$wpdb->query( 'COMMIT' );

			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_COMPENSATING_ENTRY_CREATED,
				"Compensating entry {$comp_id} created for event {$original_event_id}.",
				get_current_user_id(),
				[ 'comp_id' => $comp_id, 'original' => $original_event_id, 'type' => $correction_type ]
			);

			return $comp_id;

		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			// Log failure to standard audit log (NOT authority ledger)
			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_AUTHORITY_LEDGER_ERROR,
				"Compensating entry failed for event {$original_event_id}: " . $e->getMessage(),
				get_current_user_id(),
				[ 'original_event_id' => $original_event_id ],
				'error'
			);
			return new WP_Error( 'compensate_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	// ── Integrity check ───────────────────────────────────────────────────────

	/**
	 * Walk the hash chain and verify integrity.
	 * Returns a summary with any broken links.
	 *
	 * @param  int $limit  Max events to check per run (default 1000).
	 * @return array { verified: int, broken: int, broken_ids: int[] }
	 */
	public static function integrity_check( int $limit = 1000 ): array {
		global $wpdb;
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_uuid, event_type, payload_json, prior_event_hash, event_hash
				 FROM {$wpdb->prefix}sb_authority_events
				 ORDER BY id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$verified   = 0;
		$broken     = 0;
		$broken_ids = [];
		$prior_hash = '';

		foreach ( $events as $ev ) {
			// Verify prior hash linkage
			if ( $ev['prior_event_hash'] !== $prior_hash ) {
				$broken++;
				$broken_ids[] = (int) $ev['id'];
			} else {
				// Recompute this event's hash
				$expected = hash( 'sha256', $ev['event_uuid'] . $ev['event_type'] . $ev['payload_json'] . $ev['prior_event_hash'] );
				if ( $expected === $ev['event_hash'] ) {
					$verified++;
				} else {
					$broken++;
					$broken_ids[] = (int) $ev['id'];
				}
			}
			$prior_hash = $ev['event_hash'];
		}

		return [
			'verified'   => $verified,
			'broken'     => $broken,
			'broken_ids' => $broken_ids,
			'checked'    => count( $events ),
		];
	}

	// ── Query helpers ─────────────────────────────────────────────────────────

	public static function list_events( array $filters = [], int $page = 1, int $per_page = 50, int $since_id = 0 ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$values = [];

		if ( $since_id > 0 ) {
			$where[]  = 'id > %d';
			$values[] = $since_id;
		}
		if ( ! empty( $filters['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$values[] = $filters['event_type'];
		}
		if ( ! empty( $filters['domain_key'] ) ) {
			$where[]  = 'domain_key = %s';
			$values[] = $filters['domain_key'];
		}

		$per_page  = min( 200, max( 1, $per_page ) );
		$offset    = ( max( 1, $page ) - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$values
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_authority_events WHERE {$where_sql}", ...$values )
				: "SELECT COUNT(*) FROM {$wpdb->prefix}sb_authority_events WHERE {$where_sql}"
		);
		$rows = $wpdb->get_results(
			$values
				? $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_authority_events WHERE {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d", ...[...$values, $per_page, $offset] )
				: "SELECT * FROM {$wpdb->prefix}sb_authority_events WHERE {$where_sql} ORDER BY id ASC LIMIT {$per_page} OFFSET {$offset}",
			ARRAY_A
		);

		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'items' => $rows ?: [] ];
	}

	// ── REST wrappers ─────────────────────────────────────────────────────────

	public static function handle_rest_events( WP_REST_Request $request ): WP_REST_Response {
		$filters  = [
			'event_type' => sanitize_key( $request->get_param( 'event_type' ) ?? '' ),
			'domain_key' => sanitize_key( $request->get_param( 'domain_key' ) ?? '' ),
		];
		$page     = absint( $request->get_param( 'page' ) ?? 1 ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ?? 50 );
		$since_id = absint( $request->get_param( 'since_id' ) ?? 0 );
		return new WP_REST_Response( self::list_events( $filters, $page, $per_page, $since_id ), 200 );
	}

	public static function handle_rest_compensate( WP_REST_Request $request ): WP_REST_Response {
		$params  = (array) $request->get_json_params();
		$orig_id = absint( $params['original_event_id'] ?? 0 );
		$type    = sanitize_key( $params['correction_type'] ?? '' );
		$reason  = sanitize_key( $params['reason_code'] ?? '' );
		$note    = sanitize_textarea_field( $params['operator_note'] ?? '' );
		$payload = (array) ( $params['correcting_payload'] ?? [] );

		if ( ! $orig_id || ! $type ) {
			return new WP_REST_Response( [ 'error' => 'original_event_id and correction_type are required.' ], 400 );
		}
		$result = self::compensate( $orig_id, $type, $reason, $note, $payload );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 );
		}
		return new WP_REST_Response( [ 'compensating_entry_id' => $result ], 201 );
	}

	public static function handle_rest_integrity_check( WP_REST_Request $request ): WP_REST_Response {
		$limit  = absint( $request->get_param( 'limit' ) ?? 1000 );
		$result = self::integrity_check( $limit );
		return new WP_REST_Response( $result, 200 );
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	private static function get_latest_event_hash(): string {
		global $wpdb;
		$hash = $wpdb->get_var( "SELECT event_hash FROM {$wpdb->prefix}sb_authority_events ORDER BY id DESC LIMIT 1" );
		return $hash ?: '';
	}
}