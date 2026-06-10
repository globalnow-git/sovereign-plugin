<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBReleaseManager
 * Full lifecycle management: approval → materialize → stage → activate → rollback → archive.
 */
class SBReleaseManager {

	/**
	 * Materialize an approved object from its approval payload.
	 *
	 * @param int $approval_id
	 * @return array|WP_Error
	 */
	public static function materialize( int $approval_id ) {
		global $wpdb;

		$approval = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d AND status = 'approved'",
			$approval_id
		) );

		if ( ! $approval ) {
			return new WP_Error( 'not_found', 'Approved record not found.', [ 'status' => 404 ] );
		}

		$payload = (array) json_decode( $approval->payload, true );
		$result  = self::dispatch_materializer( $approval->approval_type, $payload, $approval_id );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_RELEASE_STAGED,
			"Approval #{$approval_id} materialized (type: {$approval->approval_type})",
			get_current_user_id(),
			[ 'approval_id' => $approval_id ],
			'info'
		);

		return $result;
	}

	/**
	 * Dispatch to type-specific materializer.
	 *
	 * @param string $type
	 * @param array  $payload
	 * @param int    $approval_id
	 * @return array|WP_Error
	 */
	private static function dispatch_materializer( string $type, array $payload, int $approval_id ) {
		switch ( $type ) {
			case 'blueprint_activation':
				if ( class_exists( 'SBAppBlueprintManager' ) ) {
					$bp_id = absint( $payload['blueprint_id'] ?? 0 );
					if ( $bp_id ) {
						return SBAppBlueprintManager::activate( $bp_id );
					}
				}
				return new WP_Error( 'materializer_error', 'Blueprint ID missing from payload.', [ 'status' => 400 ] );

			case 'schema_publish':
				if ( class_exists( 'SBSchemaDesigner' ) ) {
					$slug = sanitize_key( $payload['slug'] ?? '' );
					if ( $slug ) {
						return SBSchemaDesigner::publish( $slug );
					}
				}
				return new WP_Error( 'materializer_error', 'Schema slug missing.', [ 'status' => 400 ] );

			case 'form_publish':
				global $wpdb;
				$form_id = absint( $payload['form_id'] ?? 0 );
				if ( $form_id ) {
					$wpdb->update( "{$wpdb->prefix}sb_tiny_forms", [
						'status'      => 'active',
						'approved_at' => current_time( 'mysql' ),
					], [ 'id' => $form_id ] );
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_SURFACE_PUBLISHED, "Form #{$form_id} activated via materializer", get_current_user_id(), [], 'info' );
					return [ 'success' => true, 'form_id' => $form_id ];
				}
				return new WP_Error( 'materializer_error', 'Form ID missing.', [ 'status' => 400 ] );

			case 'surface_publish':
				global $wpdb;
				$surface_id = absint( $payload['surface_id'] ?? 0 );
				if ( $surface_id ) {
					$wpdb->update( "{$wpdb->prefix}sb_ui_surfaces", [
						'status'      => 'active',
						'approved_at' => current_time( 'mysql' ),
					], [ 'id' => $surface_id ] );
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_SURFACE_PUBLISHED, "Surface #{$surface_id} activated", get_current_user_id(), [], 'info' );
					return [ 'success' => true, 'surface_id' => $surface_id ];
				}
				return new WP_Error( 'materializer_error', 'Surface ID missing.', [ 'status' => 400 ] );

			case 'debugger_fix':
				if ( class_exists( 'SBDebuggerConsole' ) ) {
					$finding_id = absint( $payload['finding_id'] ?? 0 );
					$fix_id     = sanitize_key( $payload['fix_id'] ?? '' );
					return SBDebuggerConsole::apply_fix_internal( $finding_id, $fix_id );
				}
				return new WP_Error( 'materializer_error', 'Debugger console not loaded.', [ 'status' => 500 ] );

			default:
				do_action( "sb_materialize_{$type}", $payload, $approval_id );
				return [ 'success' => true, 'type' => $type, 'dispatched_via' => 'hook' ];
		}
	}

	/**
	 * Move an approved object to staged status.
	 *
	 * @param int $approval_id
	 * @return bool
	 */
	public static function stage( int $approval_id ): bool {
		global $wpdb;

		$updated = $wpdb->update( "{$wpdb->prefix}sb_approvals",
			[ 'status' => 'staged' ],
			[ 'id' => $approval_id, 'status' => 'approved' ]
		);

		if ( $updated ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_RELEASE_STAGED, "Approval #{$approval_id} staged", get_current_user_id(), [], 'info' );
		}

		return (bool) $updated;
	}

	/**
	 * Activate a staged object.
	 *
	 * @param int $approval_id
	 * @return array|WP_Error
	 */
	public static function activate( int $approval_id ) {
		$result = self::materialize( $approval_id );

		if ( ! is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_RELEASE_ACTIVATED, "Approval #{$approval_id} activated", get_current_user_id(), [], 'info' );
		}

		return $result;
	}

	/**
	 * Rollback the active version of an entity to its previous version.
	 *
	 * @param string $entity_type
	 * @param string $entity_slug
	 * @return array|WP_Error
	 */
	public static function rollback( string $entity_type, string $entity_slug ) {
		global $wpdb;

		// Find current active version
		$current = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_definition_versions
			 WHERE entity_type = %s AND entity_slug = %s AND status = 'active'
			 ORDER BY version_number DESC LIMIT 1",
			$entity_type,
			$entity_slug
		) );

		if ( ! $current ) {
			return new WP_Error( 'not_found', 'No active version found.', [ 'status' => 404 ] );
		}

		// Find previous version
		$previous = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_definition_versions
			 WHERE entity_type = %s AND entity_slug = %s
			   AND version_number < %d
			 ORDER BY version_number DESC LIMIT 1",
			$entity_type,
			$entity_slug,
			(int) $current->version_number
		) );

		if ( ! $previous ) {
			return new WP_Error( 'no_previous', 'No previous version available for rollback.', [ 'status' => 409 ] );
		}

		// Archive current, activate previous
		$wpdb->update( "{$wpdb->prefix}sb_definition_versions",
			[ 'status' => 'archived' ],
			[ 'id' => $current->id ]
		);
		$wpdb->update( "{$wpdb->prefix}sb_definition_versions",
			[ 'status' => 'active', 'activated_at' => current_time( 'mysql' ) ],
			[ 'id' => $previous->id ]
		);

		do_action( "sb_blueprint_rollback", $entity_type, $entity_slug, $previous );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_RELEASE_ROLLED_BACK,
			"Rolled back {$entity_type}/{$entity_slug} from v{$current->version_number} to v{$previous->version_number}",
			get_current_user_id(),
			[ 'entity_type' => $entity_type, 'entity_slug' => $entity_slug ],
			'warning'
		);

		return [
			'success'          => true,
			'from_version'     => $current->version_number,
			'to_version'       => $previous->version_number,
		];
	}

	/**
	 * Archive an entity version.
	 *
	 * @param int $version_id
	 * @return bool
	 */
	public static function archive( int $version_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			"{$wpdb->prefix}sb_definition_versions",
			[ 'status' => 'archived' ],
			[ 'id' => $version_id ]
		);

		if ( $updated ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_RELEASE_ARCHIVED, "Version #{$version_id} archived", get_current_user_id(), [], 'info' );
		}

		return (bool) $updated;
	}

	/**
	 * Get release history for an entity.
	 *
	 * @param string $entity_type
	 * @param string $entity_slug
	 * @return array
	 */
	public static function get_release_history( string $entity_type, string $entity_slug ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT v.*, u.user_email as creator_email
			 FROM {$wpdb->prefix}sb_definition_versions v
			 LEFT JOIN {$wpdb->users} u ON u.ID = v.created_by
			 WHERE v.entity_type = %s AND v.entity_slug = %s
			 ORDER BY v.version_number DESC",
			$entity_type,
			$entity_slug
		), ARRAY_A );
	}

	/**
	 * Create a version record.
	 *
	 * @param string $entity_type
	 * @param string $entity_slug
	 * @param int    $entity_id
	 * @param string $definition_json
	 * @param int    $approval_id
	 * @return int  version ID
	 */
	public static function create_version( string $entity_type, string $entity_slug, int $entity_id, string $definition_json, int $approval_id = 0 ): int {
		global $wpdb;

		$last_version = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(version_number) FROM {$wpdb->prefix}sb_definition_versions
			 WHERE entity_type = %s AND entity_slug = %s",
			$entity_type,
			$entity_slug
		) );

		$wpdb->insert( "{$wpdb->prefix}sb_definition_versions", [
			'entity_type'     => $entity_type,
			'entity_slug'     => $entity_slug,
			'entity_id'       => $entity_id,
			'version_number'  => $last_version + 1,
			'definition_json' => $definition_json,
			'status'          => 'active',
			'approval_id'     => $approval_id,
			'created_by'      => get_current_user_id(),
			'created_at'      => current_time( 'mysql' ),
			'activated_at'    => current_time( 'mysql' ),
		] );

		// Archive previous active version
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}sb_definition_versions
			 SET status = 'archived'
			 WHERE entity_type = %s AND entity_slug = %s
			   AND version_number < %d
			   AND status = 'active'",
			$entity_type,
			$entity_slug,
			$last_version + 1
		) );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_DEFINITION_VERSION_CREATED,
			"Version " . ($last_version + 1) . " created for {$entity_type}/{$entity_slug}",
			get_current_user_id(),
			[ 'entity_type' => $entity_type, 'entity_slug' => $entity_slug ],
			'info'
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Render release dashboard admin screen.
	 */
	public static function render_release_dashboard(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_definition_versions' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$recent_versions = $wpdb->get_results(
			"SELECT v.*, u.user_email as creator_email
			 FROM {$wpdb->prefix}sb_definition_versions v
			 LEFT JOIN {$wpdb->users} u ON u.ID = v.created_by
			 ORDER BY v.created_at DESC LIMIT 50"
		);

		echo '<div class="wrap"><h1>Release Dashboard</h1>';
		echo '<table class="widefat striped"><thead><tr><th>Entity Type</th><th>Slug</th><th>Version</th><th>Status</th><th>Creator</th><th>Activated</th></tr></thead><tbody>';
		foreach ( $recent_versions as $v ) {
			echo '<tr>';
			echo '<td>' . esc_html( $v->entity_type ) . '</td>';
			echo '<td><code>' . esc_html( $v->entity_slug ) . '</code></td>';
			echo '<td>v' . (int) $v->version_number . '</td>';
			echo '<td>' . esc_html( $v->status ) . '</td>';
			echo '<td>' . esc_html( $v->creator_email ?? 'System' ) . '</td>';
			echo '<td>' . esc_html( $v->activated_at ?? '—' ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $recent_versions ) ) {
			echo '<tr><td colspan="6">No release history yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}