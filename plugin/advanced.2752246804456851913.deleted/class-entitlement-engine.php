<?php
/**
 * SBEntitlementEngine — WooCommerce and PMPro entitlement automation.
 *
 * Maps WooCommerce product purchases and PMPro membership activations
 * to Sovereign blueprint packs and Kynvaric workspace provisioning.
 *
 * Reads from sb_entitlement_maps (created in Phase A installer).
 * Hooks into existing WooCommerce order completion and PMPro membership
 * change signals already wired in v1.7.
 *
 * @package SovereignBuilder
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBEntitlementEngine {

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'entitlement-engine', '2.2.0', 'SBEntitlementEngine' );
		} );

		// Hook into WooCommerce order completion
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_wc_order_complete' ], 20, 1 );

		// Hook into PMPro membership level changes
		add_action( 'pmpro_after_change_membership_level', [ __CLASS__, 'on_pmpro_level_change' ], 10, 2 );
	}

	// ── WooCommerce trigger ───────────────────────────────────────────────────

	/**
	 * Fire on WooCommerce order completion.
	 * Checks each line item against entitlement maps and provisions blueprints.
	 *
	 * @param int $order_id
	 */
	public static function on_wc_order_complete( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) { return; }

		$order   = wc_get_order( $order_id );
		if ( ! $order ) { return; }

		$user_id = (int) $order->get_user_id();
		if ( ! $user_id ) { return; }

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			self::provision_by_source( 'woo_product', $product_id, $user_id, [
				'order_id'    => $order_id,
				'product_id'  => $product_id,
				'trigger'     => 'wc_order_complete',
			] );
		}
	}

	// ── PMPro trigger ─────────────────────────────────────────────────────────

	/**
	 * Fire on PMPro membership level change.
	 * Only activates on level grant (not cancellation).
	 *
	 * @param int      $level_id  New membership level id (0 = cancelled).
	 * @param int      $user_id
	 */
	public static function on_pmpro_level_change( int $level_id, int $user_id ): void {
		if ( $level_id <= 0 ) { return; } // Cancellation — do not provision

		self::provision_by_source( 'pmpro_level', $level_id, $user_id, [
			'level_id' => $level_id,
			'trigger'  => 'pmpro_level_grant',
		] );
	}

	// ── Core provisioning ─────────────────────────────────────────────────────

	/**
	 * Look up entitlement maps for a source and activate matching blueprints.
	 *
	 * @param string $source_type  woo_product|pmpro_level
	 * @param int    $source_id    Product or level id.
	 * @param int    $user_id      WordPress user receiving the entitlement.
	 * @param array  $context      Trigger context for audit log.
	 */
	public static function provision_by_source(
		string $source_type,
		int    $source_id,
		int    $user_id,
		array  $context = []
	): void {
		global $wpdb;

		$maps = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_entitlement_maps
			 WHERE source_type = %s AND source_id = %d",
			$source_type, $source_id
		), ARRAY_A );

		if ( empty( $maps ) ) { return; }

		foreach ( $maps as $map ) {
			self::activate_blueprint_for_user(
				$map['blueprint_slug'],
				$map['workspace_profile'],
				$user_id,
				array_merge( $context, [ 'entitlement_map_id' => $map['id'] ] )
			);
		}
	}

	/**
	 * Activate a blueprint for a specific user.
	 *
	 * - Looks up blueprint by slug.
	 * - Runs SBConstraintGuard::validate_blueprint() for regulated blueprints.
	 * - Calls SBAppBlueprintManager::activate() if not already active.
	 * - Seeds workspace profile roles if specified.
	 * - Records audit log entry.
	 *
	 * @param string $blueprint_slug
	 * @param string $workspace_profile  Kynvaric role pack key (may be empty).
	 * @param int    $user_id
	 * @param array  $context
	 */
	public static function activate_blueprint_for_user(
		string $blueprint_slug,
		string $workspace_profile,
		int    $user_id,
		array  $context = []
	): void {
		global $wpdb;

		$blueprint = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE slug = %s",
			$blueprint_slug
		), ARRAY_A );

		if ( ! $blueprint ) {
			SB_Event_Logger::log_audit(
				SB_Event_Keys::EV_ENTITLEMENT_PROVISION_FAILED,
				"Entitlement: blueprint '{$blueprint_slug}' not found for user {$user_id}.",
				0,
				$context,
				'error'
			);
			return;
		}

		// Constraint guard for regulated blueprints
		if ( ! empty( $blueprint['is_regulated'] ) ) {
			$guard = SBConstraintGuard::validate_blueprint( $blueprint );
			if ( is_wp_error( $guard ) ) {
				SB_Event_Logger::log_audit(
					SB_Event_Keys::EV_ENTITLEMENT_PROVISION_FAILED,
					"Regulated blueprint '{$blueprint_slug}' failed constraint check: " . $guard->get_error_message(),
					0,
					$context,
					'error'
				);
				return;
			}
		}

		// Activate blueprint if not already active
		if ( $blueprint['status'] !== 'active' ) {
			$result = SBAppBlueprintManager::activate( (int) $blueprint['id'] );
			if ( is_wp_error( $result ) ) {
				SB_Event_Logger::log_audit(
					SB_Event_Keys::EV_ENTITLEMENT_PROVISION_FAILED,
					"Blueprint activation failed for '{$blueprint_slug}': " . $result->get_error_message(),
					0,
					$context,
					'error'
				);
				return;
			}
		}

		// Grant user entitlement meta
		$entitled_blueprints   = get_user_meta( $user_id, '_sb_entitled_blueprints', true ) ?: [];
		$entitled_blueprints[] = $blueprint_slug;
		update_user_meta( $user_id, '_sb_entitled_blueprints', array_unique( $entitled_blueprints ) );

		// Seed workspace profile if specified
		if ( ! empty( $workspace_profile ) ) {
			self::seed_workspace_profile( $workspace_profile, $user_id, (int) $blueprint['id'] );
		}

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_ENTITLEMENT_PROVISIONED,
			"Blueprint '{$blueprint_slug}' provisioned for user {$user_id}.",
			$user_id,
			array_merge( $context, [ 'blueprint_slug' => $blueprint_slug, 'workspace_profile' => $workspace_profile ] )
		);

		do_action( 'sb_entitlement_provisioned', $blueprint_slug, $user_id, $context );
	}

	// ── Workspace profile seeding ─────────────────────────────────────────────

	/**
	 * Seed a workspace profile for a user on a blueprint.
	 *
	 * Profile packs are defined in wp_options as JSON arrays:
	 *   option key: sb_workspace_profile_{profile_key}
	 *   value: { "capabilities": [...], "review_session_auto_open": bool }
	 *
	 * @param string $profile_key
	 * @param int    $user_id
	 * @param int    $blueprint_id
	 */
	public static function seed_workspace_profile(
		string $profile_key,
		int    $user_id,
		int    $blueprint_id
	): void {
		$option_key = 'sb_workspace_profile_' . sanitize_key( $profile_key );
		$profile    = json_decode( get_option( $option_key, '[]' ), true );
		if ( empty( $profile ) ) { return; }

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) { return; }

		// Grant defined capabilities
		if ( ! empty( $profile['capabilities'] ) && is_array( $profile['capabilities'] ) ) {
			foreach ( $profile['capabilities'] as $cap ) {
				$user->add_cap( sanitize_key( $cap ) );
			}
		}

		// Auto-open a review session if the profile specifies it
		if ( ! empty( $profile['review_session_auto_open'] ) && class_exists( 'SBKynvaricWorkspace' ) ) {
			SBKynvaricWorkspace::open_session( [
				'client_id'           => $user_id,
				'engagement_id'       => $blueprint_id,
				'assigned_to_user_id'=> $user_id,
			] );
		}
	}

	// ── Map management ────────────────────────────────────────────────────────

	/**
	 * Create an entitlement map entry.
	 *
	 * @return int|WP_Error  New map ID.
	 */
	public static function create_map(
		string $source_type,
		int    $source_id,
		string $blueprint_slug,
		string $workspace_profile = ''
	): int|WP_Error {
		global $wpdb;

		if ( ! in_array( $source_type, [ 'woo_product', 'pmpro_level' ], true ) ) {
			return new WP_Error( 'invalid_source_type', "source_type must be woo_product or pmpro_level.", [ 'status' => 400 ] );
		}

		$wpdb->insert( "{$wpdb->prefix}sb_entitlement_maps", [
			'source_type'       => $source_type,
			'source_id'         => $source_id,
			'blueprint_slug'    => sanitize_key( $blueprint_slug ),
			'workspace_profile'=> sanitize_key( $workspace_profile ),
			'created_at'        => current_time( 'mysql' ),
		] );

		$map_id = (int) $wpdb->insert_id;
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_ENTITLEMENT_MAP_CREATED, "Entitlement map {$map_id} created.", get_current_user_id(), [ 'source_type' => $source_type, 'source_id' => $source_id, 'blueprint_slug' => $blueprint_slug ] );
		return $map_id;
	}

	public static function list_maps( int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$per_page = min( 200, max( 1, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_entitlement_maps" );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_entitlement_maps ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'items' => $rows ?: [] ];
	}

	// ── REST wrappers ─────────────────────────────────────────────────────────

	public static function handle_rest_create_map( WP_REST_Request $request ): WP_REST_Response {
		$p      = (array) $request->get_json_params();
		$result = self::create_map(
			sanitize_key( $p['source_type'] ?? '' ),
			absint( $p['source_id'] ?? 0 ),
			sanitize_key( $p['blueprint_slug'] ?? '' ),
			sanitize_key( $p['workspace_profile'] ?? '' )
		);
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
		return new WP_REST_Response( [ 'map_id' => $result ], 201 );
	}

	public static function handle_rest_list_maps( WP_REST_Request $request ): WP_REST_Response {
		$page     = absint( $request->get_param( 'page' ) ?? 1 ) ?: 1;
		$per_page = absint( $request->get_param( 'per_page' ) ?? 50 );
		return new WP_REST_Response( self::list_maps( $page, $per_page ), 200 );
	}

	public static function handle_rest_provision( WP_REST_Request $request ): WP_REST_Response {
		$p = (array) $request->get_json_params();
		self::provision_by_source(
			sanitize_key( $p['source_type'] ?? '' ),
			absint( $p['source_id'] ?? 0 ),
			absint( $p['user_id'] ?? get_current_user_id() ),
			[ 'trigger' => 'manual_provision', 'operator' => get_current_user_id() ]
		);
		return new WP_REST_Response( [ 'success' => true ], 200 );
	}
}