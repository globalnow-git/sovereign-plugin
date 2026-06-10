<?php
/**
 * SBEvidenceVault — Evidence storage and linking for regulated workflows.
 *
 * Evidence is stored separately from APOs and authority events.
 * APO payloads may reference evidence IDs but must not store binaries inline.
 * Evidence links APOs, commit requests, and authority events without
 * collapsing store boundaries.
 *
 * @package SovereignBuilder
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBEvidenceVault {

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'evidence-vault', '2.1.0', 'SBEvidenceVault' );
		} );
	}

	// ── Create evidence item ──────────────────────────────────────────────────

	/**
	 * Register a new evidence item.
	 *
	 * Stores metadata only. Binary content is stored at storage_path
	 * on the configured storage provider (local or S3-compatible).
	 *
	 * @param  array $args {
	 *   evidence_type, storage_provider, storage_path, mime_type,
	 *   linked_subject_type, linked_subject_id, retention_class
	 * }
	 * @return int|WP_Error  New evidence item ID.
	 */
	public static function create( array $args ): int|WP_Error {
		global $wpdb;

		$row = [
			'evidence_uuid'        => wp_generate_uuid4(),
			'evidence_type'        => sanitize_key( $args['evidence_type'] ?? 'document' ),
			'storage_provider'     => sanitize_key( $args['storage_provider'] ?? 'local' ),
			'storage_path'         => sanitize_text_field( $args['storage_path'] ?? '' ),
			'mime_type'            => sanitize_mime_type( $args['mime_type'] ?? 'application/octet-stream' ),
			'linked_subject_type'  => sanitize_key( $args['linked_subject_type'] ?? '' ),
			'linked_subject_id'    => absint( $args['linked_subject_id'] ?? 0 ),
			'retention_class'      => sanitize_key( $args['retention_class'] ?? 'standard' ),
			'created_by_user_id'   => get_current_user_id(),
			'created_at'           => current_time( 'mysql' ),
		];

		if ( empty( $row['storage_path'] ) ) {
			return new WP_Error( 'evidence_no_path', 'storage_path is required.', [ 'status' => 400 ] );
		}

		$inserted = $wpdb->insert( "{$wpdb->prefix}sb_evidence_items", $row );
		if ( ! $inserted ) {
			return new WP_Error( 'evidence_insert_failed', 'Failed to register evidence item.', [ 'status' => 500 ] );
		}

		$evidence_id = (int) $wpdb->insert_id;

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_EVIDENCE_CREATED,
			"Evidence item {$evidence_id} registered. Type: {$row['evidence_type']}.",
			get_current_user_id(),
			[ 'evidence_id' => $evidence_id, 'type' => $row['evidence_type'] ]
		);

		return $evidence_id;
	}

	// ── Link evidence to a record ─────────────────────────────────────────────

	/**
	 * Link an evidence item to an APO, commit request, or authority event.
	 *
	 * @param  int    $evidence_id
	 * @param  string $link_type          proposal_support|review_support|commit_support
	 * @param  string $linked_record_type apo|commit_request|authority_event|review_session
	 * @param  int    $linked_record_id
	 * @return int|WP_Error               New link ID.
	 */
	public static function link(
		int    $evidence_id,
		string $link_type,
		string $linked_record_type,
		int    $linked_record_id
	): int|WP_Error {
		global $wpdb;

		$allowed_link_types = [ 'proposal_support', 'review_support', 'commit_support' ];
		if ( ! in_array( $link_type, $allowed_link_types, true ) ) {
			return new WP_Error( 'evidence_invalid_link_type', "Invalid link_type '{$link_type}'.", [ 'status' => 400 ] );
		}

		// Verify evidence item exists
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sb_evidence_items WHERE id = %d", $evidence_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'evidence_not_found', "Evidence item {$evidence_id} not found.", [ 'status' => 404 ] );
		}

		$wpdb->insert( "{$wpdb->prefix}sb_evidence_links", [
			'evidence_id'        => $evidence_id,
			'link_type'          => sanitize_key( $link_type ),
			'linked_record_type' => sanitize_key( $linked_record_type ),
			'linked_record_id'   => $linked_record_id,
			'created_at'         => current_time( 'mysql' ),
		] );

		$link_id = (int) $wpdb->insert_id;

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_EVIDENCE_LINKED,
			"Evidence {$evidence_id} linked to {$linked_record_type} {$linked_record_id}.",
			get_current_user_id(),
			[ 'evidence_id' => $evidence_id, 'record_type' => $linked_record_type, 'record_id' => $linked_record_id ]
		);

		return $link_id;
	}

	// ── List and detail ───────────────────────────────────────────────────────

	public static function list_items( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['evidence_type'] ) ) {
			$where[]  = 'evidence_type = %s';
			$values[] = $filters['evidence_type'];
		}
		if ( ! empty( $filters['linked_subject_type'] ) ) {
			$where[]  = 'linked_subject_type = %s';
			$values[] = $filters['linked_subject_type'];
		}
		if ( ! empty( $filters['linked_subject_id'] ) ) {
			$where[]  = 'linked_subject_id = %d';
			$values[] = (int) $filters['linked_subject_id'];
		}

		$per_page  = min( 200, max( 1, $per_page ) );
		$offset    = ( max( 1, $page ) - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$values
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_evidence_items WHERE {$where_sql}", ...$values )
				: "SELECT COUNT(*) FROM {$wpdb->prefix}sb_evidence_items WHERE {$where_sql}"
		);
		$rows = $wpdb->get_results(
			$values
				? $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_evidence_items WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...[...$values, $per_page, $offset] )
				: "SELECT * FROM {$wpdb->prefix}sb_evidence_items WHERE {$where_sql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}",
			ARRAY_A
		);

		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'items' => $rows ?: [] ];
	}

	public static function get( int $evidence_id ): ?array {
		global $wpdb;
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_evidence_items WHERE id = %d", $evidence_id ), ARRAY_A );
		if ( ! $item ) { return null; }
		// Attach links
		$item['links'] = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_evidence_links WHERE evidence_id = %d ORDER BY id DESC", $evidence_id ),
			ARRAY_A
		) ?: [];
		return $item;
	}

	/**
	 * Build an export package: list of all evidence linked to a subject.
	 * Returns metadata array suitable for download manifest.
	 */
	public static function export_package( string $subject_type, int $subject_id ): array {
		global $wpdb;
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, GROUP_CONCAT(l.link_type) as link_types
				 FROM {$wpdb->prefix}sb_evidence_items i
				 LEFT JOIN {$wpdb->prefix}sb_evidence_links l ON l.evidence_id = i.id
				 WHERE i.linked_subject_type = %s AND i.linked_subject_id = %d
				 GROUP BY i.id ORDER BY i.id ASC",
				$subject_type, $subject_id
			),
			ARRAY_A
		) ?: [];

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_EVIDENCE_EXPORTED,
			"Evidence export package for {$subject_type} {$subject_id}. Items: " . count( $items ) . ".",
			get_current_user_id(),
			[ 'subject_type' => $subject_type, 'subject_id' => $subject_id, 'item_count' => count( $items ) ]
		);

		return [
			'subject_type'  => $subject_type,
			'subject_id'    => $subject_id,
			'exported_at'   => current_time( 'mysql' ),
			'exported_by'   => get_current_user_id(),
			'item_count'    => count( $items ),
			'items'         => $items,
		];
	}

	// ── REST wrappers ─────────────────────────────────────────────────────────

	public static function handle_rest_list( WP_REST_Request $request ): WP_REST_Response {
		$filters  = [
			'evidence_type'       => sanitize_key( $request->get_param( 'evidence_type' ) ?? '' ),
			'linked_subject_type' => sanitize_key( $request->get_param( 'linked_subject_type' ) ?? '' ),
			'linked_subject_id'   => absint( $request->get_param( 'linked_subject_id' ) ?? 0 ),
		];
		$page     = absint( $request->get_param( 'page' ) ?? 1 ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ?? 50 );
		return new WP_REST_Response( self::list_items( $filters, $page, $per_page ), 200 );
	}

	public static function handle_rest_detail( WP_REST_Request $request ): WP_REST_Response {
		$id   = absint( $request->get_param( 'id' ) ?? 0 );
		$item = self::get( $id );
		if ( ! $item ) { return new WP_REST_Response( [ 'error' => 'Evidence item not found.' ], 404 ); }
		return new WP_REST_Response( $item, 200 );
	}

	public static function handle_rest_link( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$result = self::link(
			absint( $p['evidence_id'] ?? 0 ),
			sanitize_key( $p['link_type'] ?? '' ),
			sanitize_key( $p['linked_record_type'] ?? '' ),
			absint( $p['linked_record_id'] ?? 0 )
		);
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
		return new WP_REST_Response( [ 'link_id' => $result ], 201 );
	}

	public static function handle_rest_export_package( WP_REST_Request $request ): WP_REST_Response {
		$subject_type = sanitize_key( $request->get_param( 'subject_type' ) ?? '' );
		$subject_id   = absint( $request->get_param( 'subject_id' ) ?? 0 );
		if ( ! $subject_type || ! $subject_id ) {
			return new WP_REST_Response( [ 'error' => 'subject_type and subject_id required.' ], 400 );
		}
		return new WP_REST_Response( self::export_package( $subject_type, $subject_id ), 200 );
	}
}