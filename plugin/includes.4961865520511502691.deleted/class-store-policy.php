<?php
/**
 * SBStorePolicy — Object-to-store routing enforcement.
 *
 * Enforces that regulated object types can only be written to their
 * designated stores. Call assert_can_write() before any regulated save.
 *
 * @package SovereignBuilder
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBStorePolicy {

	// ── Store classification map ──────────────────────────────────────────────
	// object_type => [ allowed_tables ]
	private static array $POLICY_MAP = [
		'apo'              => [ 'sb_apo_store' ],
		'apo_transition'   => [ 'sb_apo_transitions' ],
		'commit_request'   => [ 'sb_commit_requests' ],
		'commit_approver'  => [ 'sb_commit_approvers' ],
		'authority_event'  => [ 'sb_authority_events' ],
		'compensating'     => [ 'sb_compensating_entries' ],
		'dual_control'     => [ 'sb_dual_control_policies' ],
		// Operational domain objects (ASK5 native)
		'blueprint'        => [ 'sb_app_blueprints' ],
		'form'             => [ 'sb_tiny_forms' ],
		'surface'          => [ 'sb_ui_surfaces' ],
		'placement'        => [ 'sb_placements' ],
		'approval'         => [ 'sb_approvals' ],
		'audit_log'        => [ 'sb_audit_log' ],
	];

	// ── Cross-store prohibition map ───────────────────────────────────────────
	// object_type => [ forbidden_tables ]
	private static array $FORBIDDEN_MAP = [
		'apo'             => [ 'sb_authority_events', 'sb_audit_log', 'sb_approvals' ],
		'authority_event' => [ 'sb_apo_store', 'sb_audit_log', 'sb_approvals' ],
		'compensating'    => [ 'sb_apo_store', 'sb_audit_log' ],
	];

	/**
	 * Assert that writing object_type to table is permitted.
	 * Throws a WP_Error if the write is policy-prohibited.
	 *
	 * @param  string $object_type  Logical object type key.
	 * @param  string $table        Bare table name (no prefix).
	 * @return true|WP_Error
	 */
	public static function assert_can_write( string $object_type, string $table ) {
		if ( ! isset( self::$POLICY_MAP[ $object_type ] ) ) {
			return new WP_Error(
				'store_policy_unknown_type',
				"Unknown object type '{$object_type}'. Register it in SBStorePolicy::\$POLICY_MAP.",
				[ 'status' => 500 ]
			);
		}
		if ( ! in_array( $table, self::$POLICY_MAP[ $object_type ], true ) ) {
			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_STORE_POLICY_VIOLATION,
				"Store policy violation: '{$object_type}' cannot write to '{$table}'.",
				get_current_user_id(),
				[ 'object_type' => $object_type, 'table' => $table ],
				'error'
			);
			return new WP_Error(
				'store_policy_violation',
				"Object type '{$object_type}' is not permitted to write to table '{$table}'.",
				[ 'status' => 403, 'object_type' => $object_type, 'table' => $table ]
			);
		}
		if ( isset( self::$FORBIDDEN_MAP[ $object_type ] ) &&
		     in_array( $table, self::$FORBIDDEN_MAP[ $object_type ], true ) ) {
			return new WP_Error(
				'store_policy_forbidden',
				"Object type '{$object_type}' is explicitly forbidden from table '{$table}'.",
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Assert that writing object_type to table is NOT permitted.
	 * Useful for test assertions and pre-flight validation.
	 *
	 * @return true|WP_Error Returns true if the write is correctly forbidden.
	 */
	public static function assert_cannot_write( string $object_type, string $table ): bool {
		$result = self::assert_can_write( $object_type, $table );
		return is_wp_error( $result );
	}

	/**
	 * Return all allowed tables for an object type.
	 *
	 * @return string[]
	 */
	public static function get_allowed_tables( string $object_type ): array {
		return self::$POLICY_MAP[ $object_type ] ?? [];
	}

	/**
	 * Register a custom object type at runtime.
	 * Called by regulated blueprint packs during activation.
	 *
	 * @param string   $object_type    Logical type key.
	 * @param string[] $allowed_tables Tables this type may write to.
	 * @param string[] $forbidden_tables Tables this type must never write to.
	 */
	public static function register_type(
		string $object_type,
		array  $allowed_tables,
		array  $forbidden_tables = []
	): void {
		self::$POLICY_MAP[ $object_type ]   = $allowed_tables;
		if ( ! empty( $forbidden_tables ) ) {
			self::$FORBIDDEN_MAP[ $object_type ] = $forbidden_tables;
		}
	}

	/**
	 * No-op init — hooked early to ensure class is loaded before dependent modules.
	 */
	public static function init(): void {
		// Static utility class — no initialization required.
	}

}
