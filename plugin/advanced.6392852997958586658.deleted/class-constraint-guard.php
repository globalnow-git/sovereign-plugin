<?php
/**
 * SBConstraintGuard — Pre-commit validation engine.
 *
 * Validates all five commit rules before SBCommitGate::execute() proceeds.
 * Returns structured WP_Error with rule identifier on failure.
 *
 * Integration points:
 *   0. commit-execute (mandatory — primary gate)
 *   1. blueprint install
 *   2. blueprint activate
 *   3. release stage
 *   4. release activate
 *   5. visual designer publish
 *   6. schema publish
 *
 * @package SovereignBuilder
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBConstraintGuard {

	/**
	 * Validate a commit request against all five rules.
	 * Called by SBCommitGate::execute() as integration point 0.
	 *
	 * @param  array $commit  sb_commit_requests row.
	 * @param  array $apo     sb_apo_store row.
	 * @return true|WP_Error
	 */
	public static function validate_commit( array $commit, array $apo ): bool|WP_Error {

		// Rule 1: APO status must be approved_for_commit
		if ( $apo['status'] !== 'approved_for_commit' ) {
			return new WP_Error(
				'apo_status',
				"APO must be in 'approved_for_commit' status. Current: {$apo['status']}.",
				[ 'status' => 422, 'rule' => 'apo_status' ]
			);
		}

		// Rule 2: Payload hash must match approved hash
		$current_hash = SBProposalAuthority::hash_payload(
			json_decode( $apo['payload_json'] ?? '{}', true ) ?: []
		);
		if ( $current_hash !== $commit['approved_payload_hash'] ) {
			return new WP_Error(
				'hash_mismatch',
				'APO payload has changed since approval. Commit aborted.',
				[ 'status' => 422, 'rule' => 'hash_mismatch' ]
			);
		}

		// Rule 3: Dual control — min_distinct_approvers satisfied
		if ( $commit['policy_id'] ) {
			$guard = self::check_dual_control( (int) $commit['id'], (int) $commit['policy_id'] );
			if ( is_wp_error( $guard ) ) { return $guard; }
		}

		// Rule 4: APO not expired
		if ( ! empty( $apo['expires_at'] ) && strtotime( $apo['expires_at'] ) < time() ) {
			return new WP_Error(
				'expired',
				'APO has expired and cannot be committed.',
				[ 'status' => 422, 'rule' => 'expired' ]
			);
		}

		// Rule 5: Target store authorized for proposal_type
		$store_check = SBStorePolicy::assert_can_write(
			sanitize_key( $apo['proposal_type'] ?? 'apo' ),
			sanitize_key( $commit['target_store'] ?? '' )
		);
		// Note: if proposal_type is not in policy map, assert_can_write returns WP_Error
		// For general APO commits, target_store validation is domain-specific
		// Non-regulated commits (target_store not in policy map) pass through
		if ( is_wp_error( $store_check ) ) {
			// Only block if the type IS registered but store is wrong
			$allowed = SBStorePolicy::get_allowed_tables( sanitize_key( $apo['proposal_type'] ?? '' ) );
			if ( ! empty( $allowed ) ) {
				return new WP_Error(
					'target_store',
					$store_check->get_error_message(),
					[ 'status' => 422, 'rule' => 'target_store' ]
				);
			}
		}

		// Commit status must be ready
		if ( $commit['status'] !== 'ready' ) {
			return new WP_Error(
				'commit_not_ready',
				"Commit request is not in 'ready' status. Current: {$commit['status']}.",
				[ 'status' => 422, 'rule' => 'apo_status' ]
			);
		}

		return true;
	}

	/**
	 * Validate a blueprint operation (install/activate).
	 * Checks that regulated blueprints declare store policies.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_blueprint( array $blueprint ): bool|WP_Error {
		if ( empty( $blueprint['is_regulated'] ) ) {
			return true; // Non-regulated blueprints pass through
		}
		$config = json_decode( $blueprint['config_json'] ?? '{}', true ) ?: [];
		if ( empty( $config['store_policy_declared'] ) ) {
			return new WP_Error(
				'missing_store_policy',
				"Regulated blueprint '{$blueprint['slug']}' must declare store_policy_declared in config_json.",
				[ 'status' => 422, 'rule' => 'target_store' ]
			);
		}
		return true;
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	private static function check_dual_control( int $commit_id, int $policy_id ): bool|WP_Error {
		global $wpdb;
		$policy = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_dual_control_policies WHERE id = %d", $policy_id ), ARRAY_A );
		if ( ! $policy ) { return true; } // No policy found — pass
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}sb_commit_approvers WHERE commit_request_id = %d", $commit_id )
		);
		if ( $count < (int) $policy['min_distinct_approvers'] ) {
			return new WP_Error(
				'dual_control',
				"Dual control requires {$policy['min_distinct_approvers']} distinct approver(s). Current: {$count}.",
				[ 'status' => 422, 'rule' => 'dual_control', 'required' => $policy['min_distinct_approvers'], 'current' => $count ]
			);
		}
		return true;
	}
}

// === ADDITION: Phase C — Constraint Guard blueprint integration ===
// ── Hook into blueprint activation ────────────────────────────────────────────
// Fires after config is loaded, before apply_config() in SBAppBlueprintManager::activate()

add_action( 'sb_blueprint_pre_activate', function( int $blueprint_id, array $config ): void {
	global $wpdb;
	$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $blueprint_id ), ARRAY_A );
	if ( ! $blueprint ) { return; }

	$result = SBConstraintGuard::validate_blueprint( $blueprint );
	if ( is_wp_error( $result ) ) {
		// Log the constraint failure — activation continues but operator is notified
		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_CONSTRAINT_GUARD_BLOCKED,
			"Blueprint {$blueprint['slug']} failed constraint check on activate: " . $result->get_error_message(),
			get_current_user_id(),
			[ 'blueprint_id' => $blueprint_id, 'rule' => $result->get_error_code() ],
			'warning'
		);
		// For regulated blueprints, block activation on constraint failure
		if ( ! empty( $blueprint['is_regulated'] ) ) {
			// In REST context return a proper error response; otherwise wp_die
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			wp_send_json_error( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], 422 );
		}
		wp_die( esc_html( 'Regulated blueprint activation blocked: ' . $result->get_error_message() ) );
		}
	}
}, 10, 2 );

// ── Hook into blueprint install ───────────────────────────────────────────────

add_action( 'sb_blueprint_pre_install', function( array $config ): void {
	// Validate store_policy_declared on regulated blueprints at install time
	if ( empty( $config['is_regulated'] ) ) { return; }
	if ( empty( $config['store_policy_declared'] ) ) {
		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_CONSTRAINT_GUARD_BLOCKED,
			"Regulated blueprint '{$config['slug']}' missing store_policy_declared — BLOCKED.",
			0,
			[ 'slug' => $config['slug'] ],
			'error'
		);
		// Block install — regulated blueprints without store policy are invalid state
		wp_die( esc_html(
			"Regulated blueprint '{$config['slug']}' cannot be installed: store_policy_declared is required in config_json."
		) );
	}
}, 10, 1 );

// ── Hook into release stage ───────────────────────────────────────────────────

add_action( 'sb_release_pre_stage', function( int $approval_id, array $payload ): void {
	// If release involves a regulated blueprint, run constraint check
	$blueprint_id = absint( $payload['blueprint_id'] ?? 0 );
	if ( ! $blueprint_id ) { return; }

	global $wpdb;
	$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $blueprint_id ), ARRAY_A );
	if ( ! $blueprint || empty( $blueprint['is_regulated'] ) ) { return; }

	$result = SBConstraintGuard::validate_blueprint( $blueprint );
	if ( is_wp_error( $result ) ) {
		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_CONSTRAINT_GUARD_BLOCKED,
			"Release stage blocked for regulated blueprint {$blueprint['slug']}: " . $result->get_error_message(),
			get_current_user_id(),
			[ 'approval_id' => $approval_id, 'blueprint_id' => $blueprint_id, 'rule' => $result->get_error_code() ],
			'error'
		);
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		wp_send_json_error( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], 422 );
	}
	wp_die( esc_html( 'Release stage blocked: ' . $result->get_error_message() ) );
	}
}, 10, 2 );