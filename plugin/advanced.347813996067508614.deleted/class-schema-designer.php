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
		echo '<p style="color:#888;text-align:center;margin-top:40px" id="sb-canvas-placeholder">Drag field types here to build your schema</p>';
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

		echo '</div></div>';

		// Schema Designer JavaScript
		$current_slug = esc_js( $slug );
		// Schema Designer JS - inline
		ob_start();
		?>
		<style>
		.sb-palette-item { padding:8px 12px; border:1.5px solid #e5e7eb; border-radius:6px; margin-bottom:6px; cursor:grab; background:#f9fafb; font-size:0.85rem; transition:all 0.15s; user-select:none; }
		.sb-palette-item:hover { border-color:#c9a84c; background:#fefce8; }
		.sb-palette-item.dragging { opacity:0.5; cursor:grabbing; }
		.sb-field-row { display:flex; align-items:center; gap:8px; padding:8px 10px; background:#fff; border:1.5px solid #e5e7eb; border-radius:6px; margin-bottom:6px; cursor:move; }
		.sb-field-row:hover { border-color:#c9a84c; }
		.sb-field-row .sb-field-label { flex:1; font-size:0.85rem; font-weight:600; color:#1a1a2e; }
		.sb-field-row .sb-field-type { font-size:0.72rem; color:#6b7280; font-family:monospace; }
		.sb-field-row .sb-field-remove { color:#dc2626; cursor:pointer; font-size:1rem; line-height:1; padding:0 4px; }
		.sb-field-row input[type='text'] { border:1px solid #e5e7eb; border-radius:4px; padding:3px 6px; font-size:0.82rem; width:140px; }
		#sb-schema-canvas.drag-over { border-color:#c9a84c; background:#fefce8; }
		</style>
		<script>
		(function(){
			var canvas = document.getElementById('sb-schema-canvas');
			var placeholder = document.getElementById('sb-canvas-placeholder');
			var fields = [];
			var restBase = document.getElementById('sb-schema-designer').dataset.rest;
			var nonce = document.getElementById('sb-schema-designer').dataset.nonce;
			var slug = '<?php echo $current_slug; ?>';
			var existingSchema = JSON.parse(document.getElementById('sb-schema-designer').dataset.schema || '{}');
			if (existingSchema && existingSchema.fields && existingSchema.fields.length) {
				existingSchema.fields.forEach(function(f){ addField(f.type || 'text', f.label || f.key || ''); });
			} else if (existingSchema && existingSchema.columns && existingSchema.columns.length) {
				existingSchema.columns.forEach(function(c){ addField('text', c.label || c.key || ''); });
			}
			document.querySelectorAll('.sb-palette-item').forEach(function(item) {
				item.setAttribute('draggable', 'true');
				item.addEventListener('dragstart', function(e) { e.dataTransfer.setData('fieldType', item.dataset.type); item.classList.add('dragging'); });
				item.addEventListener('dragend', function() { item.classList.remove('dragging'); });
				item.addEventListener('click', function() { addField(item.dataset.type, ''); });
			});
			canvas.addEventListener('dragover', function(e) { e.preventDefault(); canvas.classList.add('drag-over'); });
			canvas.addEventListener('dragleave', function() { canvas.classList.remove('drag-over'); });
			canvas.addEventListener('drop', function(e) { e.preventDefault(); canvas.classList.remove('drag-over'); var type = e.dataTransfer.getData('fieldType'); if (type) { addField(type, ''); } });
			function addField(type, label) {
				if (placeholder) { placeholder.style.display = 'none'; }
				var id = 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2,5);
				var field = { id:id, type:type, label:label || '' };
				fields.push(field);
				renderField(field);
			}
			function renderField(field) {
				var row = document.createElement('div');
				row.className = 'sb-field-row';
				row.id = 'row_' + field.id;
				row.innerHTML = '<span class="sb-field-type">' + field.type + '</span><input type="text" class="sb-field-label-input" placeholder="Column label..." value="' + (field.label||'') + '" data-id="' + field.id + '"><span class="sb-field-remove" data-id="' + field.id + '" title="Remove">&#x2715;</span>';
				canvas.appendChild(row);
				row.querySelector('.sb-field-label-input').addEventListener('input', function(e) { var f = fields.find(function(x){ return x.id === e.target.dataset.id; }); if (f) { f.label = e.target.value; } });
				row.querySelector('.sb-field-remove').addEventListener('click', function(e) { var fid = e.target.dataset.id; fields = fields.filter(function(x){ return x.id !== fid; }); var r = document.getElementById('row_' + fid); if(r) r.remove(); if (!fields.length && placeholder) { placeholder.style.display = ''; } });
			}
			function buildSchemaData() { return { slug:slug, label:slug, source_table:'sb_submissions', fields:fields.map(function(f){ return { key:f.label.toLowerCase().replace(/\s+/g,'_')||f.id, label:f.label, type:f.type }; }), columns:fields.map(function(f){ return { key:f.label.toLowerCase().replace(/\s+/g,'_')||f.id, label:f.label }; }), permissions:{list:'manage_sovereign'} }; }
			document.getElementById('sb-schema-save-draft').addEventListener('click', function() { if(!slug){alert('No schema slug.');return;} fetch(restBase+'/schema/draft',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({slug:slug,schema:buildSchemaData()})}).then(function(r){return r.json();}).then(function(res){alert(res.success?'Schema saved!':'Save failed: '+(res.error||''));}).catch(function(){alert('Save failed.');}); });
			document.getElementById('sb-schema-preview').addEventListener('click', function() { var data = buildSchemaData(); var pane = document.getElementById('sb-schema-preview-pane'); pane.innerHTML = '<table style="width:100%;border-collapse:collapse;"><thead><tr>'+data.columns.map(function(c){return '<th style="text-align:left;padding:6px;border-bottom:2px solid #e5e7eb;font-size:0.78rem;color:#6b7280;text-transform:uppercase;">'+c.label+'</th>';}).join('')+'</tr></thead><tbody><tr>'+data.columns.map(function(){return '<td style="padding:6px;color:#9ca3af;font-size:0.78rem;">sample</td>';}).join('')+'</tr></tbody></table>'; });
			document.getElementById('sb-schema-publish').addEventListener('click', function() { if(!slug){alert('No schema slug.');return;} fetch(restBase+'/schema/publish',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({slug:slug})}).then(function(r){return r.json();}).then(function(res){alert(res.success?'Schema published!':'Publish failed.');}).catch(function(){alert('Publish failed.');}); });
		})();
		</script>
		</div>
		<?php
		echo ob_get_clean();
	}