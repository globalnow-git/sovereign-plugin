<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBSchemaDesigner {

	public static function get_field_palette(): array {
		return [
			[ 'type' => 'text',          'label' => 'Text',          'icon' => '📝', 'config' => [ 'truncate' ] ],
			[ 'type' => 'integer',       'label' => 'Integer',       'icon' => '🔢', 'config' => [] ],
			[ 'type' => 'longtext',      'label' => 'Long Text',     'icon' => '📄', 'config' => [ 'truncate' ] ],
			[ 'type' => 'datetime',      'label' => 'Date/Time',     'icon' => '🕐', 'config' => [] ],
			[ 'type' => 'badge',         'label' => 'Status Badge',  'icon' => '🏷️', 'config' => [ 'badge_map' ] ],
			[ 'type' => 'progress_bar',  'label' => 'Progress Bar',  'icon' => '📊', 'config' => [] ],
			[ 'type' => 'boolean',       'label' => 'Boolean',       'icon' => '✓', 'config' => [] ],
			[ 'type' => 'json_preview',  'label' => 'JSON Preview',  'icon' => '{ }', 'config' => [] ],
			[ 'type' => 'email',         'label' => 'Email',         'icon' => '✉️', 'config' => [] ],
			[ 'type' => 'url',           'label' => 'URL',           'icon' => '🔗', 'config' => [] ],
		];
	}

	public static function save_draft( string $slug, array $schema ): bool {
		global $wpdb;
		$slug = sanitize_key( $slug );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sb_view_schemas WHERE slug = %s", $slug ) );
		$data   = [
			'label'       => sanitize_text_field( $schema['label'] ?? $slug ),
			'schema_json' => wp_json_encode( $schema ),
			'status'      => 'draft',
			'updated_at'  => current_time( 'mysql' ),
		];
		if ( $exists ) {
			$wpdb->update( "{$wpdb->prefix}sb_view_schemas", $data, [ 'slug' => $slug ] );
		} else {
			$data['slug']       = $slug;
			$data['version']    = 1;
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( "{$wpdb->prefix}sb_view_schemas", $data );
		}
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SCHEMA_DRAFT_SAVED, "Schema draft {$slug} saved.", get_current_user_id() );
		return true;
	}

	public static function preview( string $slug ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_view_schemas WHERE slug = %s", sanitize_key( $slug ) ) );
		if ( ! $row ) { return [ 'error' => 'Schema not found.' ]; }
		$schema  = json_decode( $row->schema_json, true );
		$table   = $wpdb->prefix . ( $schema['source_table'] ?? '' );
		$samples = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 5", ARRAY_A );
		return [ 'schema' => $schema, 'sample_rows' => $samples ];
	}

	public static function publish( string $slug ): bool|WP_Error {
		global $wpdb;
		$slug    = sanitize_key( $slug );
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_view_schemas WHERE slug = %s", $slug ) );
		if ( ! $row ) { return SB_Extension_API::rest_error( 'not_found', 'Schema not found.', 404 ); }
		if ( 'active' === $row->status ) {
			// Replacing active schema — requires HITM
			$approval_id = SB_Approval_Engine::create_approval( 0, 'schema_publish', [ 'slug' => $slug ] );
			return rest_ensure_response( [ 'hitm_required' => true, 'approval_id' => $approval_id ] );
		}
		// New publish — no approval needed
		$new_version = (int) $row->version + 1;
		$wpdb->update( "{$wpdb->prefix}sb_view_schemas", [
			'status'     => 'active',
			'version'    => $new_version,
			'updated_at' => current_time( 'mysql' ),
		], [ 'slug' => $slug ] );
		self::create_version( $slug, (int) $row->id, json_decode( $row->schema_json, true ), $new_version );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SCHEMA_PUBLISHED, "Schema {$slug} v{$new_version} published.", get_current_user_id() );
		return true;
	}

	public static function archive( string $slug ): bool {
		global $wpdb;
		$slug = sanitize_key( $slug );
		$wpdb->update( "{$wpdb->prefix}sb_view_schemas", [ 'status' => 'archived', 'updated_at' => current_time( 'mysql' ) ], [ 'slug' => $slug ] );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SCHEMA_ARCHIVED, "Schema {$slug} archived.", get_current_user_id() );
		return true;
	}

	public static function get_version_history( string $slug ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_definition_versions WHERE entity_type = 'view_schema' AND entity_slug = %s ORDER BY version_number DESC",
			sanitize_key( $slug )
		), ARRAY_A ) ?: [];
	}

	private static function create_version( string $slug, int $id, array $schema, int $version ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_definition_versions", [
			'entity_type'     => 'view_schema',
			'entity_slug'     => $slug,
			'entity_id'       => $id,
			'version_number'  => $version,
			'definition_json' => wp_json_encode( $schema ),
			'status'          => 'active',
			'created_by'      => get_current_user_id(),
			'created_at'      => current_time( 'mysql' ),
			'activated_at'    => current_time( 'mysql' ),
		] );
	}

	// REST handlers
	public static function handle_rest_palette( $request ) {
		return rest_ensure_response( self::get_field_palette() );
	}

	public static function handle_rest_draft( $request ) {
		$params = (array) $request->get_json_params();
		$slug   = sanitize_key( $params['slug'] ?? '' );
		if ( ! $slug ) { return SB_Extension_API::rest_error( 'missing_slug', 'slug required.', 400 ); }
		self::save_draft( $slug, $params );
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_rest_preview( $request ) {
		$params = (array) $request->get_json_params();
		$slug   = sanitize_key( $params['slug'] ?? '' );
		return rest_ensure_response( self::preview( $slug ) );
	}

	public static function handle_rest_publish( $request ) {
		$slug = sanitize_key( $request->get_json_params()['slug'] ?? '' );
		if ( ! $slug ) { return SB_Extension_API::rest_error( 'missing_slug', 'slug required.', 400 ); }
		$result = self::publish( $slug );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_rest_archive( $request ) {
		$slug = sanitize_key( $request->get_json_params()['slug'] ?? '' );
		if ( ! $slug ) { return SB_Extension_API::rest_error( 'missing_slug', 'slug required.', 400 ); }
		self::archive( $slug );
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_rest_versions( $request ) {
		$slug = sanitize_key( $request->get_param( 'slug' ) );
		return rest_ensure_response( self::get_version_history( $slug ) );
	}

	public static function render_screen() {
		if ( ! current_user_can( 'manage_sovereign_schemas' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$slug   = sanitize_key( $_GET['slug'] ?? '' );

		// Show schema picker if no slug or slug not found
		$all_schemas = $wpdb->get_results( "SELECT slug, label, status FROM {$wpdb->prefix}sb_view_schemas ORDER BY slug ASC" );

		$schema = null;
		if ( $slug ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_view_schemas WHERE slug = %s", $slug ) );
			if ( $row ) {
				$schema = json_decode( $row->schema_json, true );
			}
		}

		// If no slug given or not found, show picker
		if ( ! $slug || ! $schema ) {
			echo '<div class="wrap"><h1>Schema Designer</h1>';
			echo '<div style="background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:2rem;max-width:700px;margin-top:1rem;">';
			echo '<h2 style="margin-top:0;color:#1a1a2e;">Select a Schema</h2>';
			echo '<table class="widefat striped"><thead><tr><th>Schema</th><th>Status</th><th>Action</th></tr></thead><tbody>';
			foreach ( $all_schemas as $s ) {
				$url = admin_url( 'admin.php?page=sb-schema-designer&slug=' . $s->slug );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $s->label ) . '</strong><br><code style="font-size:0.75rem;color:#6b7280;">' . esc_html( $s->slug ) . '</code></td>';
				echo '<td>' . esc_html( $s->status ) . '</td>';
				echo '<td><a href="' . esc_url( $url ) . '" class="button button-primary">Open in Designer</a></td>';
				echo '</tr>';
			}
			if ( empty( $all_schemas ) ) {
				echo '<tr><td colspan="3">No schemas found. Activate a blueprint to create schemas.</td></tr>';
			}
			echo '</tbody></table></div></div>';
			return;
		}

		$palette_json = esc_js( wp_json_encode( self::get_field_palette() ) );
		$schema_json  = esc_js( wp_json_encode( $schema ?? [ 'slug' => '', 'label' => '', 'source_table' => '', 'fields' => [], 'permissions' => [ 'list' => 'manage_sovereign' ] ] ) );
		$rest_base    = esc_url( get_rest_url( null, 'sovereign-builder/v1' ) );
		$nonce        = wp_create_nonce( 'wp_rest' );

		echo '<div class="wrap"><h1>Schema Designer</h1>';
		echo '<div id="sb-schema-designer" data-palette=\'' . $palette_json . '\' data-schema=\'' . $schema_json . '\' data-rest=\'' . $rest_base . '\' data-nonce=\'' . $nonce . '\'>';
		echo '<div style="display:grid;grid-template-columns:200px 1fr 300px;gap:20px;margin-top:20px">';

		// Left: palette
		echo '<div style="background:#fff;padding:15px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1)">';
		echo '<h3 style="margin-top:0">Field Types</h3>';
		foreach ( self::get_field_palette() as $ft ) {
			echo '<div class="sb-palette-item" data-type="' . esc_attr( $ft['type'] ) . '" style="padding:8px;border:1px solid #ddd;border-radius:3px;margin-bottom:5px;cursor:pointer;background:#f9f9f9">';
			echo esc_html( $ft['icon'] ) . ' ' . esc_html( $ft['label'] );
			echo '</div>';
		}
		echo '</div>';

		// Center: canvas
		echo '<div style="background:#fff;padding:15px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1)">';
		echo '<h3 style="margin-top:0">Schema Fields</h3>';
		echo '<div id="sb-schema-canvas" style="min-height:300px;border:2px dashed #ddd;padding:10px;border-radius:4px">';
		echo '<p style="color:#888;text-align:center;margin-top:40px">Drag field types here to build your schema</p>';
		echo '</div>';
		echo '<div style="margin-top:15px">';
		echo '<button class="button button-primary" id="sb-schema-save-draft">Save Draft</button> ';
		echo '<button class="button" id="sb-schema-preview">Preview</button> ';
		echo '<button class="button button-hero" id="sb-schema-publish">Publish</button>';
		echo '</div></div>';

		// Right: live preview
		echo '<div style="background:#fff;padding:15px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1)">';
		echo '<h3 style="margin-top:0">Live Preview</h3>';
		echo '<div id="sb-schema-preview-pane" style="font-size:12px;color:#555">Preview will appear here after saving a draft.</div>';
		echo '</div>';

		echo '</div></div></div>';
	}
}