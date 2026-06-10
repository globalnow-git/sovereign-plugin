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

		$export_nonce = wp_create_nonce( 'sb_blueprint_export' );
		?>
		<style>
		.sb-cockpit { max-width: 1100px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
		.sb-cockpit h1 { font-size: 1.6rem; color: #1a1a2e; margin-bottom: 0.25rem; }
		.sb-cockpit .sb-subtitle { color: #6b7280; margin-bottom: 2rem; font-size: 0.92rem; }
		.sb-panel { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08), 0 0 0 1px rgba(0,0,0,.05); margin-bottom: 1.5rem; overflow: hidden; }
		.sb-panel-header { background: #1a1a2e; color: #f5f0e8; padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
		.sb-panel-header h2 { margin: 0; font-size: 1rem; font-weight: 600; color: #f5f0e8; }
		.sb-panel-body { padding: 1.5rem; }
		.sb-import-tabs { display: flex; gap: 0; border-bottom: 2px solid #f3f4f6; margin-bottom: 1.25rem; }
		.sb-tab { padding: 0.6rem 1.2rem; font-size: 0.88rem; font-weight: 600; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s; background: none; border-top: none; border-left: none; border-right: none; }
		.sb-tab.active { color: #1a1a2e; border-bottom-color: #c9a84c; }
		.sb-tab-panel { display: none; }
		.sb-tab-panel.active { display: block; }
		.sb-import-drop { border: 2px dashed #d1d5db; border-radius: 8px; padding: 2.5rem; text-align: center; cursor: pointer; transition: all 0.2s; background: #f9fafb; }
		.sb-import-drop:hover, .sb-import-drop.dragover { border-color: #c9a84c; background: #fefce8; }
		.sb-import-drop .icon { font-size: 2rem; margin-bottom: 0.5rem; }
		.sb-import-drop p { margin: 0.25rem 0 0; color: #6b7280; font-size: 0.88rem; }
		.sb-import-drop strong { color: #1a1a2e; }
		.sb-import-drop input[type="file"] { display: none; }
		.sb-json-textarea { width: 100%; box-sizing: border-box; font-family: "SF Mono", "Fira Code", monospace; font-size: 0.82rem; border: 1.5px solid #e5e7eb; border-radius: 7px; padding: 0.85rem; color: #1a1a2e; background: #f9fafb; resize: vertical; outline: none; transition: border-color 0.18s; }
		.sb-json-textarea:focus { border-color: #c9a84c; background: #fff; }
		.sb-btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1.1rem; font-size: 0.88rem; font-weight: 600; border-radius: 6px; cursor: pointer; border: none; transition: all 0.15s; text-decoration: none; }
		.sb-btn-primary { background: #1a1a2e; color: #c9a84c !important; }
		.sb-btn-primary:hover { background: #252545; color: #c9a84c !important; }
		.sb-btn-outline { background: #fff; color: #374151 !important; border: 1.5px solid #e5e7eb !important; }
		.sb-btn-outline:hover { border-color: #c9a84c !important; color: #1a1a2e !important; }
		.sb-btn-danger { background: #fef2f2; color: #dc2626 !important; border: 1.5px solid #fecaca !important; }
		.sb-btn-danger:hover { background: #fee2e2; }
		.sb-btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8rem; }
		.sb-bp-table { width: 100%; border-collapse: collapse; }
		.sb-bp-table th { text-align: left; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; padding: 0.6rem 1rem; border-bottom: 2px solid #f3f4f6; background: #f9fafb; }
		.sb-bp-table td { padding: 1rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; font-size: 0.9rem; }
		.sb-bp-table tr:last-child td { border-bottom: none; }
		.sb-bp-table tr:hover td { background: #fafafa; }
		.sb-badge { display: inline-flex; align-items: center; padding: 0.2rem 0.65rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; }
		.sb-badge-active { background: #ecfdf5; color: #059669; }
		.sb-badge-installed { background: #eff6ff; color: #2563eb; }
		.sb-badge-inactive { background: #f9fafb; color: #6b7280; }
		.sb-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
		.sb-empty { text-align: center; padding: 3rem; color: #9ca3af; }
		.sb-empty .icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
		.sb-notice { padding: 0.85rem 1.1rem; border-radius: 7px; margin-bottom: 1rem; font-size: 0.9rem; }
		.sb-notice-success { background: #ecfdf5; border-left: 4px solid #059669; color: #065f46; }
		.sb-notice-error { background: #fef2f2; border-left: 4px solid #dc2626; color: #7f1d1d; }
		</style>

		<div class="sb-cockpit wrap">
			<h1>⚙ Blueprint Cockpit</h1>
			<p class="sb-subtitle">Install, activate, and export application blueprints. Each blueprint deploys a complete business application.</p>

			<?php if ( isset( $_GET['installed'] ) ) : ?>
				<div class="sb-notice sb-notice-success">✓ Blueprint installed successfully. Activate it below to deploy.</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['activated'] ) ) : ?>
				<div class="sb-notice sb-notice-success">✓ Blueprint activated. Pages and forms have been deployed.</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deactivated'] ) ) : ?>
				<div class="sb-notice sb-notice-success">Blueprint deactivated.</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="sb-notice sb-notice-error">✗ <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></div>
			<?php endif; ?>

			<!-- Import Panel -->
			<div class="sb-panel">
				<div class="sb-panel-header">
					<h2>Import Blueprint</h2>
				</div>
				<div class="sb-panel-body">
					<div class="sb-import-tabs">
						<button class="sb-tab active" onclick="sbSwitchTab('file', this)">Upload JSON File</button>
						<button class="sb-tab" onclick="sbSwitchTab('paste', this)">Paste JSON</button>
					</div>

					<!-- File upload tab -->
					<div class="sb-tab-panel active" id="sb-tab-file">
						<div class="sb-import-drop" id="sb-drop-zone" onclick="document.getElementById('sb-file-input').click()">
							<div class="icon">📄</div>
							<strong>Click to choose a blueprint JSON file</strong>
							<p>or drag and drop it here</p>
							<input type="file" id="sb-file-input" accept=".json,application/json">
						</div>
						<div id="sb-file-preview" style="display:none;margin-top:1rem;">
							<p id="sb-file-name" style="font-weight:600;color:#1a1a2e;margin-bottom:0.75rem;"></p>
							<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sb-file-form">
								<?php wp_nonce_field( 'sb_blueprint_install' ); ?>
								<input type="hidden" name="action" value="sb_blueprint_install">
								<textarea name="blueprint_json" id="sb-file-json" class="sb-json-textarea" rows="6" readonly></textarea>
								<br><br>
								<button type="submit" class="sb-btn sb-btn-primary">Install Blueprint →</button>
								<button type="button" class="sb-btn sb-btn-outline" onclick="document.getElementById('sb-file-preview').style.display='none';document.getElementById('sb-drop-zone').style.display='block';">Clear</button>
							</form>
						</div>
					</div>

					<!-- Paste tab -->
					<div class="sb-tab-panel" id="sb-tab-paste">
						<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'sb_blueprint_install' ); ?>
							<input type="hidden" name="action" value="sb_blueprint_install">
							<textarea name="blueprint_json" class="sb-json-textarea" rows="10"
								placeholder='{"slug":"my-blueprint","version":"1.0.0","label":"My Blueprint",...}'></textarea>
							<br><br>
							<button type="submit" class="sb-btn sb-btn-primary">Install Blueprint →</button>
						</form>
					</div>
				</div>
			</div>

			<!-- Blueprints List -->
			<div class="sb-panel">
				<div class="sb-panel-header">
					<h2>Installed Blueprints</h2>
					<span style="color:#9ca3af;font-size:0.82rem;"><?php echo count( $blueprints ); ?> blueprint<?php echo count( $blueprints ) !== 1 ? 's' : ''; ?></span>
				</div>
				<div style="padding:0;">
					<?php if ( $blueprints ) : ?>
					<table class="sb-bp-table">
						<thead>
							<tr>
								<th>Blueprint</th>
								<th>Version</th>
								<th>Status</th>
								<th>Activated</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $blueprints as $bp ) : ?>
							<tr>
								<td>
									<strong style="color:#1a1a2e;"><?php echo esc_html( $bp->label ); ?></strong><br>
									<code style="font-size:0.75rem;color:#6b7280;"><?php echo esc_html( $bp->slug ); ?></code>
								</td>
								<td><?php echo esc_html( $bp->version ); ?></td>
								<td>
									<span class="sb-badge sb-badge-<?php echo esc_attr( $bp->status ); ?>">
										<?php echo esc_html( strtoupper( $bp->status ) ); ?>
									</span>
								</td>
								<td style="color:#6b7280;font-size:0.85rem;">
									<?php echo $bp->activated_at ? esc_html( date( 'M j, Y', strtotime( $bp->activated_at ) ) ) : '—'; ?>
								</td>
								<td>
									<div class="sb-actions">
										<?php if ( 'active' !== $bp->status ) : ?>
											<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
												<?php wp_nonce_field( 'sb_blueprint_activate' ); ?>
												<input type="hidden" name="action" value="sb_blueprint_activate">
												<input type="hidden" name="blueprint_id" value="<?php echo esc_attr( $bp->id ); ?>">
												<button class="sb-btn sb-btn-primary sb-btn-sm">▶ Activate</button>
											</form>
										<?php else : ?>
											<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
												<?php wp_nonce_field( 'sb_blueprint_deactivate' ); ?>
												<input type="hidden" name="action" value="sb_blueprint_deactivate">
												<input type="hidden" name="blueprint_id" value="<?php echo esc_attr( $bp->id ); ?>">
												<button class="sb-btn sb-btn-outline sb-btn-sm">⏸ Deactivate</button>
											</form>
										<?php endif; ?>
										<button class="sb-btn sb-btn-outline sb-btn-sm" onclick="sbExportBlueprint(<?php echo (int) $bp->id; ?>, '<?php echo esc_js( $bp->slug ); ?>')">⬇ Export</button>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=sb-blueprint-detail&id=' . $bp->id ) ); ?>" class="sb-btn sb-btn-outline sb-btn-sm">🔍 Inspect</a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<div class="sb-empty">
							<div class="icon">📦</div>
							<p><strong>No blueprints installed yet.</strong></p>
							<p>Import a blueprint JSON file above to get started.</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<script>
		function sbSwitchTab(tab, btn) {
			document.querySelectorAll('.sb-tab').forEach(function(t){ t.classList.remove('active'); });
			document.querySelectorAll('.sb-tab-panel').forEach(function(p){ p.classList.remove('active'); });
			btn.classList.add('active');
			document.getElementById('sb-tab-' + tab).classList.add('active');
		}

		// File drag and drop
		var dropZone = document.getElementById('sb-drop-zone');
		var fileInput = document.getElementById('sb-file-input');

		if (dropZone) {
			dropZone.addEventListener('dragover', function(e){ e.preventDefault(); dropZone.classList.add('dragover'); });
			dropZone.addEventListener('dragleave', function(){ dropZone.classList.remove('dragover'); });
			dropZone.addEventListener('drop', function(e){
				e.preventDefault();
				dropZone.classList.remove('dragover');
				var file = e.dataTransfer.files[0];
				if (file) { sbLoadFile(file); }
			});
		}

		if (fileInput) {
			fileInput.addEventListener('change', function(){
				if (fileInput.files[0]) { sbLoadFile(fileInput.files[0]); }
			});
		}

		function sbLoadFile(file) {
			var reader = new FileReader();
			reader.onload = function(e) {
				try {
					var parsed = JSON.parse(e.target.result);
					document.getElementById('sb-file-json').value = JSON.stringify(parsed, null, 2);
					document.getElementById('sb-file-name').textContent = '📄 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
					document.getElementById('sb-drop-zone').style.display = 'none';
					document.getElementById('sb-file-preview').style.display = 'block';
				} catch(err) {
					alert('Invalid JSON file. Please check the file and try again.');
				}
			};
			reader.readAsText(file);
		}

		// Blueprint export
		function sbExportBlueprint(id, slug) {
			var url = '<?php echo esc_js( get_rest_url( null, 'sovereign-builder/v1/blueprint/export/' ) ); ?>' + id;
			var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
			fetch(url, { headers: { 'X-WP-Nonce': nonce } })
				.then(function(r){ return r.json(); })
				.then(function(data) {
					var json = JSON.stringify(data, null, 2);
					var blob = new Blob([json], { type: 'application/json' });
					var a = document.createElement('a');
					a.href = URL.createObjectURL(blob);
					a.download = slug + '.json';
					a.click();
					URL.revokeObjectURL(a.href);
				})
				.catch(function(){ alert('Export failed. Please try again.'); });
		}
		</script>
		<?php
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