<?php
/**
 * SBBuildMapMaterializer — Canonical owner of sb_build_map_runtime.
 *
 * Applies approved form/surface/capability/blueprint changes to their
 * respective tables, recomputes graphhash via SBVisualDesigner::normalize_graph(),
 * and rebuilds sb_build_map_runtime rows for the affected blueprint(s).
 *
 * SBReleaseManager remains responsible for release staging/rollback.
 * It may call SBBuildMapMaterializer as part of its workflow but does
 * not own build-map logic.
 *
 * Triggered by:
 *   - sb_approval_approved action (any build-map-affecting approval)
 *   - SBEntitlementEngine after blueprint activation
 *   - Manual repair-system run
 *
 * @package SovereignBuilder
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBBuildMapMaterializer {

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'build-map-materializer', '2.3.0', 'SBBuildMapMaterializer' );
		} );
		// Trigger materialization after any build-map approval is approved
		add_action( 'sb_approval_approved', [ __CLASS__, 'on_approval_approved' ], 10, 2 );
		// Health check hook — runs with existing daily cron
		add_action( 'sb_debug_health_check', [ __CLASS__, 'health_check' ] );
	}

	// ── Approval hook ────────────────────────────────────────────────────────

	/**
	 * Called when an approval is approved.
	 * If the approval type is build-map-related, materialize the affected blueprint.
	 *
	 * @param int    $approval_id
	 * @param object $approval    sb_approvals row.
	 */
	public static function on_approval_approved( int $approval_id, object $approval ): void {
		$build_map_types = [ 'blueprint_activation', 'form_publish', 'surface_publish' ];
		if ( ! in_array( $approval->approval_type ?? '', $build_map_types, true ) ) {
			return;
		}

		$payload      = json_decode( $approval->payload ?? '{}', true );
		$blueprint_id = absint( $payload['blueprint_id'] ?? $payload['node_id'] ?? 0 );

		if ( ! $blueprint_id ) { return; }

		self::run( $blueprint_id );
	}

	// ── Core materializer ────────────────────────────────────────────────────

	/**
	 * Materialize the runtime map for a single blueprint.
	 *
	 * Steps:
	 *   1. Set materialization_status = pending for all blueprint rows.
	 *   2. Fetch blueprint graph via SBVisualDesigner::get_graph_data().
	 *   3. Compute graphhash via SBVisualDesigner::normalize_graph().
	 *   4. Update sbappblueprints.graph_hash.
	 *   5. Delete and rebuild sbbuildmapruntime rows in a transaction.
	 *   6. On success: set materialization_status = complete.
	 *   7. On failure: set materialization_status = failed; log to audit log.
	 *   8. If blueprint is regulated: record authority event.
	 *
	 * @param  int $blueprint_id
	 * @return true|WP_Error
	 */
	public static function run( int $blueprint_id ): bool|WP_Error {
		global $wpdb;

		// Step 1: Mark all existing rows as pending
		$wpdb->update(
			"{$wpdb->prefix}sb_build_map_runtime",
			[ 'materialization_status' => 'pending' ],
			[ 'blueprint_id' => $blueprint_id ]
		);

		try {
			// Step 2: Fetch graph
			$graph = SBVisualDesigner::get_graph_data( $blueprint_id );
			if ( empty( $graph['nodes'] ) ) {
				throw new \RuntimeException( "Blueprint {$blueprint_id} has no nodes." );
			}

			// Step 3: Compute graph hash
			$canonical = [
				'blueprint_id' => $blueprint_id,
				'nodes'        => $graph['nodes'],
				'edges'        => $graph['edges'],
			];
			$graph_hash = SBVisualDesigner::normalize_graph( $canonical );

			// Step 4 & 5: Persist hash + rebuild runtime rows — all in one transaction
			// graph_hash is written INSIDE the transaction so rollback leaves it consistent
			$wpdb->query( 'START TRANSACTION' );

			$wpdb->update(
				"{$wpdb->prefix}sb_app_blueprints",
				[ 'graph_hash' => $graph_hash, 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $blueprint_id ]
			);

			// Delete stale rows for this blueprint
			$wpdb->delete( "{$wpdb->prefix}sb_build_map_runtime", [ 'blueprint_id' => $blueprint_id ] );

			$now = current_time( 'mysql' );
			foreach ( $graph['nodes'] as $node ) {
				// Compute per-node hash via same normalizer (no ad-hoc hashing)
				$node_hash = SBVisualDesigner::normalize_graph( $node );
				$wpdb->insert( "{$wpdb->prefix}sb_build_map_runtime", [
					'blueprint_id'          => $blueprint_id,
					'node_hash'             => $node_hash,
					'node_type'             => sanitize_key( $node['type'] ?? 'unknown' ),
					'node_slug'             => sanitize_text_field( $node['slug'] ?? '' ),
					'label'                 => sanitize_text_field( $node['label'] ?? '' ),
					'status'               => sanitize_key( $node['status'] ?? 'unknown' ),
					'materialization_status'=> 'pending',
					'last_materialized_at' => $now,
				] );
			}

			// Step 6: Mark complete
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}sb_build_map_runtime
				 SET materialization_status = 'complete', last_materialized_at = %s
				 WHERE blueprint_id = %d",
				$now, $blueprint_id
			) );

			$wpdb->query( 'COMMIT' );

			// Step 8: Authority event for regulated blueprints
			$blueprint = $wpdb->get_row( $wpdb->prepare(
				"SELECT slug, is_regulated FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d",
				$blueprint_id
			), ARRAY_A );

			if ( $blueprint && ! empty( $blueprint['is_regulated'] ) ) {
				SBAuditLedgerPlus::record_authority_event(
					'blueprint_graph_committed',
					[
						'blueprint_id'  => $blueprint_id,
						'blueprint_slug'=> $blueprint['slug'],
						'graph_hash'    => $graph_hash,
						'node_count'    => count( $graph['nodes'] ),
						'domain_key'    => 'build_map',
						'aggregate_type'=> 'blueprint',
						'aggregate_id'  => $blueprint_id,
					],
					get_current_user_id()
				);
			}

			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_BUILD_MAP_MATERIALIZED,
				"Build map materialized for blueprint {$blueprint_id}. Nodes: " . count( $graph['nodes'] ) . ".",
				get_current_user_id(),
				[ 'blueprint_id' => $blueprint_id, 'hash' => substr( $graph_hash, 0, 12 ) . '...' ]
			);

			return true;

		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			// Step 7: Mark failed — do NOT write to authority ledger on failure
			$wpdb->update(
				"{$wpdb->prefix}sb_build_map_runtime",
				[ 'materialization_status' => 'failed' ],
				[ 'blueprint_id' => $blueprint_id ]
			);

			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_BUILD_MAP_MATERIALIZATION_FAILED,
				"Build map materialization failed for blueprint {$blueprint_id}: " . $e->getMessage(),
				get_current_user_id(),
				[ 'blueprint_id' => $blueprint_id, 'error' => $e->getMessage() ],
				'error'
			);

			return new WP_Error( 'materialization_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	// ── Archive on blueprint deactivation ────────────────────────────────────

	/**
	 * Mark all runtime rows for a blueprint as archived.
	 * Called when a blueprint is archived or hard-deleted.
	 * Rows are preserved — never deleted automatically.
	 *
	 * @param int $blueprint_id
	 */
	public static function archive( int $blueprint_id ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}sb_build_map_runtime",
			[ 'materialization_status' => 'archived' ],
			[ 'blueprint_id' => $blueprint_id ]
		);
		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_BUILD_MAP_MATERIALIZED,
			"Build map rows archived for blueprint {$blueprint_id}.",
			get_current_user_id(),
			[ 'blueprint_id' => $blueprint_id ]
		);
	}

	// ── Materialize all active blueprints (repair-system) ────────────────────

	/**
	 * Materialize all active blueprints.
	 * Called by repair-system to populate runtime table from scratch.
	 *
	 * @return array { success: int, failed: int }
	 */
	public static function run_all(): array {
		global $wpdb;
		$blueprints = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}sb_app_blueprints WHERE status = 'active' ORDER BY id ASC"
		);

		$success = 0;
		$failed  = 0;
		foreach ( $blueprints as $id ) {
			$result = self::run( (int) $id );
			is_wp_error( $result ) ? $failed++ : $success++;
		}

		return [ 'success' => $success, 'failed' => $failed ];
	}

	// ── Health check ─────────────────────────────────────────────────────────

	/**
	 * Health check: flag any non-archived blueprint whose runtime rows
	 * are complete but last_materialized_at is older than 15 minutes.
	 *
	 * Fires on sb_debug_health_check cron.
	 */
	public static function health_check(): void {
		global $wpdb;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );

		$stale = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT blueprint_id
			 FROM {$wpdb->prefix}sb_build_map_runtime
			 WHERE materialization_status = 'complete'
			   AND last_materialized_at < %s",
			$threshold
		) );

		foreach ( $stale as $blueprint_id ) {
			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_BUILD_MAP_STALE,
				"Build map for blueprint {$blueprint_id} is stale (last materialized > 15 min ago).",
				0,
				[ 'blueprint_id' => $blueprint_id, 'threshold' => $threshold ],
				'warning'
			);
		}

		// Also flag blueprints with no runtime rows at all
		$unmaterilaized = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}sb_app_blueprints
			 WHERE status = 'active'
			   AND id NOT IN (
					SELECT DISTINCT blueprint_id
					FROM {$wpdb->prefix}sb_build_map_runtime
					WHERE materialization_status != 'archived'
			   )"
		);

		foreach ( $unmaterilaized as $blueprint_id ) {
			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_BUILD_MAP_STALE,
				"Active blueprint {$blueprint_id} has no materialized runtime rows.",
				0,
				[ 'blueprint_id' => $blueprint_id ],
				'warning'
			);
		}
	}

	// ── REST wrapper for repair-system ────────────────────────────────────────

	public static function handle_rest_run_all( WP_REST_Request $request ): WP_REST_Response {
		$result = self::run_all();
		return new WP_REST_Response( $result, 200 );
	}
}