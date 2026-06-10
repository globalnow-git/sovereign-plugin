<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBAppBlueprintManager {

	public static function install( string $blueprint_json ): int|WP_Error {
		global $wpdb;
		// JSON blob size cap: 512KB
		if ( strlen( $blueprint_json ) > 524288 ) {
			return SB_Extension_API::rest_error( 'payload_too_large', 'Blueprint JSON exceeds 512KB.', 413 );
		}
		$config = json_decode( $blueprint_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $config ) ) {
			return SB_Extension_API::rest_error( 'invalid_json', 'Blueprint JSON invalid: ' . json_last_error_msg(), 400 );
		}
		if ( ! isset( $config['slug'] ) ) {
			return SB_Extension_API::rest_error( 'invalid_json', 'Blueprint JSON missing required slug.', 400 );
		}
		// Schema validation: allowed keys, required keys, types, max lengths
		$schema_errors = SBImportValidator::validate_blueprint( $config );
		if ( ! empty( $schema_errors ) ) {
			return SB_Extension_API::rest_error( 'validation_failed', implode( '; ', $schema_errors ), 422 );
		}
		$errors = self::validate( $config );
		if ( ! empty( $errors ) ) {
			return SB_Extension_API::rest_error( 'validation_failed', implode( '; ', $errors ), 422 );
		}
		$slug    = sanitize_key( $config['slug'] );
		$exists  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sb_app_blueprints WHERE slug = %s", $slug ) );
		$data    = [
			'slug'        => $slug,
			'label'       => sanitize_text_field( $config['label'] ?? $slug ),
			'version'     => sanitize_text_field( $config['version'] ?? '1.0.0' ),
			'config_json' => wp_json_encode( $config ),
			'status'      => 'installed',
			'updated_at'  => current_time( 'mysql' ),
		];
		if ( $exists ) {
			$wpdb->update( "{$wpdb->prefix}sb_app_blueprints", $data, [ 'slug' => $slug ] );
			$id = (int) $exists;
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( "{$wpdb->prefix}sb_app_blueprints", $data );
			$id = (int) $wpdb->insert_id;
		}
		self::create_version_record( $slug, $id, $config, 'installed' );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_BLUEPRINT_INSTALLED, "Blueprint {$slug} v{$config['version']} installed.", get_current_user_id(), [ 'id' => $id ] );
		return $id;
	}

	public static function activate( int $id ): bool|WP_Error {
		global $wpdb;
		$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $id ) );
		if ( ! $blueprint ) {
			return SB_Extension_API::rest_error( 'not_found', 'Blueprint not found.', 404 );
		}
		$config = json_decode( $blueprint->config_json, true );
		// HITM gate if required
		if ( ! empty( $config['requires_hitm_on_activate'] ) ) {
			$approval_id = SB_Approval_Engine::create_approval( 0, 'blueprint_activation', [ 'blueprint_id' => $id, 'slug' => $blueprint->slug ] );
			return rest_ensure_response( [ 'hitm_required' => true, 'approval_id' => $approval_id ] );
		}
		self::apply_config( $id, $config );
		$wpdb->update( "{$wpdb->prefix}sb_app_blueprints", [
			'status'       => 'active',
			'activated_at' => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		do_action( 'sb_blueprint_activated', $id, $config );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_BLUEPRINT_ACTIVATED, "Blueprint {$blueprint->slug} activated.", get_current_user_id(), [ 'id' => $id ] );
		return true;
	}

	public static function deactivate( int $id ): bool {
		global $wpdb;
		$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $id ) );
		if ( ! $blueprint ) { return false; }
		$wpdb->update( "{$wpdb->prefix}sb_app_blueprints", [
			'status'         => 'inactive',
			'deactivated_at' => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		do_action( 'sb_blueprint_deactivated', $id );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_BLUEPRINT_DEACTIVATED, "Blueprint {$blueprint->slug} deactivated.", get_current_user_id(), [ 'id' => $id ] );
		return true;
	}

	public static function upgrade( int $id, string $new_json ): int|WP_Error {
		$result = self::install( $new_json );
		if ( is_wp_error( $result ) ) { return $result; }
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_BLUEPRINT_UPGRADED, "Blueprint ID {$id} upgraded.", get_current_user_id(), [ 'id' => $id ] );
		return $result;
	}

	public static function inspect( int $id ): array|WP_Error {
		global $wpdb;
		$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $id ), ARRAY_A );
		if ( ! $blueprint ) {
			return SB_Extension_API::rest_error( 'not_found', 'Blueprint not found.', 404 );
		}
		$config  = json_decode( $blueprint['config_json'], true );
		$versions = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_definition_versions WHERE entity_type = 'blueprint' AND entity_id = %d ORDER BY version_number DESC",
			$id
		), ARRAY_A );
		return [
			'blueprint'  => $blueprint,
			'config'     => $config,
			'versions'   => $versions,
			'object_map' => self::get_object_map( $config ),
		];
	}

	public static function export( int $id ): array|WP_Error {
		global $wpdb;
		$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $id ) );
		if ( ! $blueprint ) {
			return SB_Extension_API::rest_error( 'not_found', 'Blueprint not found.', 404 );
		}
		$config = json_decode( $blueprint->config_json, true );
		// Scrub internal IDs — export contains only slugs and config
		unset( $config['_internal_id'] );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_BLUEPRINT_EXPORTED, "Blueprint {$blueprint->slug} exported.", get_current_user_id(), [ 'id' => $id ] );
		return $config;
	}

	public static function import( string $json ): int|WP_Error {
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_BLUEPRINT_IMPORTED, 'Blueprint import started.', get_current_user_id() );
		return self::install( $json );
	}

	public static function export_site_config(): array {
		global $wpdb;
		$blueprints = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE status = 'active'", ARRAY_A );
		$schemas    = $wpdb->get_results( "SELECT slug, schema_json FROM {$wpdb->prefix}sb_view_schemas WHERE status = 'active'", ARRAY_A );
		$forms      = $wpdb->get_results( "SELECT slug, fields_json FROM {$wpdb->prefix}sb_tiny_forms WHERE status = 'active'", ARRAY_A );
		$surfaces   = $wpdb->get_results( "SELECT slug, content_json FROM {$wpdb->prefix}sb_ui_surfaces WHERE status = 'active'", ARRAY_A );
		return compact( 'blueprints', 'schemas', 'forms', 'surfaces' );
	}

	private static function apply_config( int $id, array $config ) {
		global $wpdb;
		// Register capabilities
		if ( ! empty( $config['capabilities'] ) ) {
			foreach ( $config['capabilities'] as $cap ) {
				if ( class_exists( 'SBAIIntegrator' ) ) {
					SBAIIntegrator::register_capability( $cap['slug'], $cap );
				}
			}
		}
		// Create roads if missing
		if ( ! empty( $config['roads'] ) ) {
			foreach ( $config['roads'] as $road ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_roads WHERE road_key = %s AND campaign_id = 0",
					$road['road_key']
				) );
				if ( ! $exists ) {
					$wpdb->insert( "{$wpdb->prefix}sb_roads", [
						'road_key'    => sanitize_text_field( $road['road_key'] ),
						'label'       => sanitize_text_field( $road['label'] ?? '' ),
						'campaign_id' => 0,
						'created_at'  => current_time( 'mysql' ),
					] );
				}
			}
		}
		// Register signals
		if ( ! empty( $config['signals'] ) ) {
			foreach ( $config['signals'] as $signal_type ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_signal_definitions WHERE signal_type = %s",
					sanitize_key( $signal_type )
				) );
				if ( ! $exists ) {
					$wpdb->insert( "{$wpdb->prefix}sb_signal_definitions", [
						'signal_type'    => sanitize_key( $signal_type ),
						'label'          => ucwords( str_replace( '_', ' ', $signal_type ) ),
						'capture_method' => 'blueprint',
						'created_at'     => current_time( 'mysql' ),
					] );
				}
			}
		}
		// Materialize forms into sb_tiny_forms
		if ( ! empty( $config['forms'] ) ) {
			foreach ( $config['forms'] as $form ) {
				if ( empty( $form['slug'] ) || empty( $form['fields_json'] ) ) { continue; }
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s",
					sanitize_key( $form['slug'] )
				) );
				if ( $exists ) {
					// Update existing form
					$wpdb->update(
						"{$wpdb->prefix}sb_tiny_forms",
						[
							'label'            => sanitize_text_field( $form['label'] ?? $form['slug'] ),
							'fields_json'      => wp_json_encode( $form['fields_json'] ),
							'save_adapter'     => sanitize_text_field( $form['save_adapter'] ?? 'submission_table' ),
							'save_config_json' => ! empty( $form['save_config_json'] ) ? wp_json_encode( $form['save_config_json'] ) : null,
							'status'           => 'active',
						],
						[ 'slug' => sanitize_key( $form['slug'] ) ]
					);
				} else {
					$wpdb->insert( "{$wpdb->prefix}sb_tiny_forms", [
						'slug'             => sanitize_key( $form['slug'] ),
						'label'            => sanitize_text_field( $form['label'] ?? $form['slug'] ),
						'fields_json'      => wp_json_encode( $form['fields_json'] ),
						'save_adapter'     => sanitize_text_field( $form['save_adapter'] ?? 'submission_table' ),
						'save_config_json' => ! empty( $form['save_config_json'] ) ? wp_json_encode( $form['save_config_json'] ) : null,
						'status'           => 'active',
						'created_at'       => current_time( 'mysql' ),
					] );
				}
			}
		}
		// Materialize schemas into sb_view_schemas
		if ( ! empty( $config['schemas'] ) ) {
			foreach ( $config['schemas'] as $schema ) {
				if ( empty( $schema['slug'] ) || empty( $schema['schema_json'] ) ) { continue; }
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_view_schemas WHERE slug = %s",
					sanitize_key( $schema['slug'] )
				) );
				if ( $exists ) {
					$wpdb->update(
						"{$wpdb->prefix}sb_view_schemas",
						[
							'label'       => sanitize_text_field( $schema['label'] ?? $schema['slug'] ),
							'schema_json' => wp_json_encode( $schema['schema_json'] ),
							'status'      => 'active',
						],
						[ 'slug' => sanitize_key( $schema['slug'] ) ]
					);
				} else {
					$wpdb->insert( "{$wpdb->prefix}sb_view_schemas", [
						'slug'        => sanitize_key( $schema['slug'] ),
						'label'       => sanitize_text_field( $schema['label'] ?? $schema['slug'] ),
						'schema_json' => wp_json_encode( $schema['schema_json'] ),
						'status'      => 'active',
						'created_at'  => current_time( 'mysql' ),
					] );
				}
			}
		}
	}

	private static function validate( array $config ): array {
		$errors = [];
		if ( empty( $config['slug'] ) ) { $errors[] = 'Missing slug.'; }
		if ( empty( $config['version'] ) ) { $errors[] = 'Missing version.'; }
		if ( ! empty( $config['slug'] ) && ! preg_match( '/^[a-z0-9\-]+$/', $config['slug'] ) ) {
			$errors[] = 'Slug must be lowercase alphanumeric with hyphens only.';
		}
		return $errors;
	}

	private static function create_version_record( string $slug, int $id, array $config, string $status ) {
		global $wpdb;
		$last_version = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(version_number) FROM {$wpdb->prefix}sb_definition_versions WHERE entity_type = 'blueprint' AND entity_slug = %s",
			$slug
		) );
		$wpdb->insert( "{$wpdb->prefix}sb_definition_versions", [
			'entity_type'     => 'blueprint',
			'entity_slug'     => $slug,
			'entity_id'       => $id,
			'version_number'  => $last_version + 1,
			'definition_json' => wp_json_encode( $config ),
			'status'          => $status,
			'created_by'      => get_current_user_id(),
			'created_at'      => current_time( 'mysql' ),
		] );
	}

	private static function get_object_map( array $config ): array {
		return [
			'capabilities' => $config['capabilities'] ?? [],
			'signals'      => $config['signals'] ?? [],
			'roads'        => $config['roads'] ?? [],
			'forms'        => $config['forms'] ?? [],
			'surfaces'     => $config['surfaces'] ?? [],
			'placements'   => $config['placements'] ?? [],
			'settings'     => $config['settings'] ?? [],
		];
	}

	// REST handlers
	public static function handle_rest_install( $request ) {
		$params = (array) $request->get_json_params();
		$json   = wp_json_encode( $params['config'] ?? $params );
		$result = self::install( $json );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( [ 'success' => true, 'id' => $result ] );
	}

	public static function handle_rest_activate( $request ) {
		$id = absint( $request->get_json_params()['id'] ?? $request->get_param( 'id' ) ?? 0 );
		if ( ! $id ) { return SB_Extension_API::rest_error( 'missing_id', 'id required.', 400 ); }
		$result = self::activate( $id );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_rest_deactivate( $request ) {
		$id = absint( $request->get_json_params()['id'] ?? 0 );
		if ( ! $id ) { return SB_Extension_API::rest_error( 'missing_id', 'id required.', 400 ); }
		self::deactivate( $id );
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_rest_upgrade( $request ) {
		$params = (array) $request->get_json_params();
		$id     = absint( $params['id'] ?? 0 );
		$json   = wp_json_encode( $params['config'] ?? [] );
		if ( ! $id ) { return SB_Extension_API::rest_error( 'missing_id', 'id required.', 400 ); }
		$result = self::upgrade( $id, $json );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_rest_inspect( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$result = self::inspect( $id );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function handle_rest_export( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$result = self::export( $id );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function handle_rest_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Body cap: 512KB — belt-and-suspenders before install() which also caps
		if ( strlen( $request->get_body() ) > 524288 ) {
			return new WP_REST_Response( [ 'error' => 'Blueprint payload exceeds 512KB.' ], 413 );
		}
		$json = wp_json_encode( $request->get_json_params() );
		if ( ! $json ) {
			return new WP_REST_Response( [ 'error' => 'Invalid JSON body.' ], 400 );
		}
		$result = self::import( $json );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( [ 'success' => true, 'id' => $result ] );
	}

	public static function render_cockpit_screen() {
		if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) { wp_die( 'Forbidden.' ); }
		$guard = SBAdminGuard::require_tables( [ 'sb_app_blueprints' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;
		$blueprints = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints ORDER BY created_at DESC" );
		echo '<div class="wrap">';
		echo '<h1>App Shapes Cockpit</h1>';
		if ( isset( $_GET['installed'] ) ) echo '<div class="notice notice-success"><p>Blueprint installed.</p></div>';
		if ( isset( $_GET['activated'] ) ) echo '<div class="notice notice-success"><p>Blueprint activated.</p></div>';
		if ( isset( $_GET['error'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</p></div>';

		// Install form
		echo '<div style="background:#fff;padding:20px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px">';
		echo '<h3>Install Blueprint</h3>';
		echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_blueprint_install' );
		echo '<input type="hidden" name="action" value="sb_blueprint_install">';
		echo '<textarea name="blueprint_json" rows="8" style="width:100%;font-family:monospace" placeholder=\'{"slug":"my-blueprint","version":"1.0.0","label":"My Blueprint"}\'></textarea><br><br>';
		echo '<button class="button button-primary">Install Blueprint</button>';
		echo '</form></div>';

		// Blueprints list
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Slug</th><th>Label</th><th>Version</th><th>Status</th><th>Activated</th><th>Actions</th></tr></thead><tbody>';
		if ( $blueprints ) {
			foreach ( $blueprints as $bp ) {
				$status_color = [ 'active' => '#46b450', 'installed' => '#0073aa', 'inactive' => '#888' ][ $bp->status ] ?? '#888';
				echo '<tr>';
				echo '<td><code>' . esc_html( $bp->slug ) . '</code></td>';
				echo '<td>' . esc_html( $bp->label ) . '</td>';
				echo '<td>' . esc_html( $bp->version ) . '</td>';
				echo '<td><span style="color:' . $status_color . '">' . esc_html( strtoupper( $bp->status ) ) . '</span></td>';
				echo '<td>' . esc_html( $bp->activated_at ?? '—' ) . '</td>';
				echo '<td>';
				if ( 'active' !== $bp->status ) {
					echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
					wp_nonce_field( 'sb_blueprint_activate' );
					echo '<input type="hidden" name="action" value="sb_blueprint_activate"><input type="hidden" name="blueprint_id" value="' . esc_attr( $bp->id ) . '">';
					echo '<button class="button button-primary">Activate</button></form> ';
				} else {
					echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
					wp_nonce_field( 'sb_blueprint_deactivate' );
					echo '<input type="hidden" name="action" value="sb_blueprint_deactivate"><input type="hidden" name="blueprint_id" value="' . esc_attr( $bp->id ) . '">';
					echo '<button class="button">Deactivate</button></form> ';
				}
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=sb-blueprint-detail&id=' . $bp->id ) ) . '" class="button">Inspect</a>';
				echo '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="6">No blueprints installed.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function render_detail_screen() {
		if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) { wp_die( 'Forbidden.' ); }
		$id     = absint( $_GET['id'] ?? 0 );
		$result = self::inspect( $id );
		if ( is_wp_error( $result ) ) { wp_die( $result->get_error_message() ); }
		$bp     = $result['blueprint'];
		$config = $result['config'];
		echo '<div class="wrap">';
		echo '<h1>Blueprint: ' . esc_html( $bp['slug'] ) . '</h1>';
		echo '<p><strong>Status:</strong> ' . esc_html( $bp['status'] ) . ' &nbsp; <strong>Version:</strong> ' . esc_html( $bp['version'] ) . '</p>';
		echo '<h3>Object Map</h3><pre style="background:#f4f4f4;padding:15px;overflow:auto">' . esc_html( wp_json_encode( $result['object_map'], JSON_PRETTY_PRINT ) ) . '</pre>';
		echo '<h3>Version History</h3>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Version</th><th>Status</th><th>Created</th></tr></thead><tbody>';
		foreach ( $result['versions'] as $v ) {
			echo '<tr><td>#' . esc_html( $v['version_number'] ) . '</td><td>' . esc_html( $v['status'] ) . '</td><td>' . esc_html( $v['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-blueprints' ) ) . '" class="button">Back</a></p>';
		echo '</div>';
	}
}