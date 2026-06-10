<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── SBAdminGuard ─────────────────────────────────────────────────────────────
// Reusable defensive helper for admin screens.
// Call require_tables() at the top of any render method that queries SB tables.
// Returns a safe HTML repair notice if any table is missing; null if all present.

class SBAdminGuard {

	public static function require_tables( array $tables ): ?string {
		global $wpdb;
		$missing = [];
		foreach ( $tables as $bare ) {
			if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$bare}'" ) ) {
				$missing[] = $bare;
			}
		}
		if ( empty( $missing ) ) { return null; }
		return self::repair_notice(
			'Tables missing: <code>' . esc_html( implode( ', ', $missing ) ) . '</code>.'
		);
	}

	public static function repair_notice( string $message ): string {
		$nonce = wp_create_nonce( 'wp_rest' );
		$rest  = esc_js( rest_url( 'sovereign-builder/v1/repair-system' ) );
		$js    = "fetch('{$rest}',{method:'POST',headers:{'X-WP-Nonce':'{$nonce}'}}).then(r=>r.json()).then(d=>d.success?location.reload():alert(JSON.stringify(d.errors)))";
		return '<div class="wrap"><div class="notice notice-error"><p>'
			. '<strong>Sovereign Builder — Repair Required.</strong> ' . $message
			. ' <button class="button" onclick="' . esc_attr( $js ) . '">Run Repair System</button>'
			. '</p></div></div>';
	}
}


class SBAdminViewRenderer {

	private static array $schema_cache = [];

	public static function get_schema( string $slug ): ?array {
		if ( isset( self::$schema_cache[ $slug ] ) ) {
			return self::$schema_cache[ $slug ];
		}
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_view_schemas WHERE slug = %s AND status = 'active'",
			sanitize_key( $slug )
		) );
		if ( ! $row ) { return null; }
		$schema = json_decode( $row->schema_json, true );
		if ( ! is_array( $schema ) ) { return null; }
		self::$schema_cache[ $slug ] = $schema;
		return $schema;
	}

	public static function render_list( string $slug, array $query_args = [] ): void {
		$schema = self::get_schema( $slug );
		if ( ! $schema ) {
			echo '<div class="notice notice-warning"><p>Schema <code>' . esc_html( $slug ) . '</code> not found or not active.</p></div>';
			return;
		}
		$cap = $schema['permissions']['list'] ?? 'manage_sovereign';
		if ( ! current_user_can( $cap ) ) {
			echo '<p>Access denied.</p>';
			return;
		}
		global $wpdb;
		$table   = $wpdb->prefix . $schema['source_table'];
		$per_page= (int) ( $schema['per_page'] ?? 25 );
		$page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset  = ( $page - 1 ) * $per_page;
		$order_by= sanitize_key( $schema['order_by'] ?? 'id' );
		$order   = in_array( strtoupper( $schema['order'] ?? 'DESC' ), [ 'ASC', 'DESC' ] ) ? strtoupper( $schema['order'] ) : 'DESC';

		// Filter WHERE clause
		$where   = 'WHERE 1=1';
		$params  = [];
		$filters = $schema['filters'] ?? [];
		foreach ( $filters as $filter ) {
			$filter_key = sanitize_key( $filter['key'] );
			// BUG1 FIX: validate column name against known safe identifiers before interpolating into SQL.
			// Column name is sourced from blueprint definition — must never go directly into query string.
			$allowed_columns = preg_split( '/[\s,]+/', $wpdb->get_var(
				$wpdb->prepare( "SELECT GROUP_CONCAT(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
					DB_NAME, $wpdb->prefix . $table_name
				)
			) ?? '' );
			if ( ! in_array( $filter_key, $allowed_columns, true ) ) {
				continue; // reject unknown column — not in actual schema
			}
			if ( isset( $_GET[ 'filter_' . $filter_key ] ) && '' !== $_GET[ 'filter_' . $filter_key ] ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column validated against live schema above
				$where   .= " AND `{$filter_key}` = %s";
				$params[] = sanitize_text_field( $_GET[ 'filter_' . $filter_key ] );
			}
		}

		$total = (int) $wpdb->get_var( ! empty( $params )
			? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params )
			: "SELECT COUNT(*) FROM {$table} {$where}"
		);

		$sql  = "SELECT * FROM {$table} {$where} ORDER BY {$order_by} {$order} LIMIT {$per_page} OFFSET {$offset}";
		$rows = $wpdb->get_results( ! empty( $params ) ? $wpdb->prepare( $sql, ...$params ) : $sql, ARRAY_A );

		// List fields
		$list_fields = array_filter( $schema['fields'] ?? [], fn( $f ) => ! empty( $f['list'] ) );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach ( $list_fields as $field ) {
			echo '<th>' . esc_html( $field['label'] ?? $field['key'] ) . '</th>';
		}
		if ( ! empty( $schema['actions'] ) ) { echo '<th>Actions</th>'; }
		echo '</tr></thead><tbody>';

		if ( $rows ) {
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $list_fields as $field ) {
					echo '<td>' . self::render_field_value( $field, $row[ $field['key'] ] ?? '' ) . '</td>';
				}
				if ( ! empty( $schema['actions'] ) ) {
					echo '<td>';
					foreach ( $schema['actions'] as $action ) {
						if ( ! empty( $action['route'] ) ) {
							$url = admin_url( 'admin.php?page=' . $action['route'] . '&' . ( $schema['primary_key'] ?? 'id' ) . '=' . esc_attr( $row[ $schema['primary_key'] ?? 'id' ] ) );
							echo '<a href="' . esc_url( $url ) . '" class="button button-small">' . esc_html( $action['label'] ) . '</a> ';
						}
					}
					echo '</td>';
				}
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="' . ( count( $list_fields ) + ( ! empty( $schema['actions'] ) ? 1 : 0 ) ) . '">No records found.</td></tr>';
		}
		echo '</tbody></table>';

		// Pagination
		$total_pages = ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => $page,
			] );
			echo '</div></div>';
		}
	}

	public static function render_detail( string $slug, int $row_id ): void {
		$schema = self::get_schema( $slug );
		if ( ! $schema ) { echo '<p>Schema not found.</p>'; return; }
		$cap = $schema['permissions']['detail'] ?? 'manage_sovereign';
		if ( ! current_user_can( $cap ) ) { echo '<p>Access denied.</p>'; return; }
		global $wpdb;
		$table = $wpdb->prefix . $schema['source_table'];
		$pk    = sanitize_key( $schema['primary_key'] ?? 'id' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$pk} = %d", $row_id ), ARRAY_A );
		if ( ! $row ) { echo '<p>Record not found.</p>'; return; }

		$detail_fields = array_filter( $schema['fields'] ?? [], fn( $f ) => ! empty( $f['detail'] ) );
		echo '<table class="form-table">';
		foreach ( $detail_fields as $field ) {
			echo '<tr><th>' . esc_html( $field['label'] ?? $field['key'] ) . '</th>';
			echo '<td>' . self::render_field_value( $field, $row[ $field['key'] ] ?? '' ) . '</td></tr>';
		}
		echo '</table>';
	}

	private static function render_field_value( array $field, $value ): string {
		$type = $field['type'] ?? 'text';
		switch ( $type ) {
			case 'badge':
				$map   = $field['badge_map'] ?? [];
				$color = [ 'grey' => '#888', 'blue' => '#0073aa', 'green' => '#46b450', 'red' => '#dc3232', 'orange' => '#f0b849' ][ $map[ $value ] ?? 'grey' ] ?? '#888';
				return '<span style="background:' . esc_attr( $color ) . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px">' . esc_html( $value ) . '</span>';
			case 'progress_bar':
				$pct = min( 100, (int) $value );
				return '<div style="background:#eee;border-radius:4px;height:12px;width:100px"><div style="background:#0073aa;height:12px;border-radius:4px;width:' . $pct . '%"></div></div> <small>' . $pct . '%</small>';
			case 'datetime':
				return '<code>' . esc_html( $value ) . '</code>';
			case 'longtext':
				$truncate = (int) ( $field['truncate'] ?? 200 );
				$display  = strlen( $value ) > $truncate ? substr( $value, 0, $truncate ) . '…' : $value;
				return '<span title="' . esc_attr( $value ) . '">' . esc_html( $display ) . '</span>';
			case 'json_preview':
				$decoded = json_decode( $value, true );
				return '<pre style="font-size:11px;max-height:80px;overflow:auto;background:#f4f4f4;padding:5px;margin:0">' . esc_html( wp_json_encode( $decoded, JSON_PRETTY_PRINT ) ) . '</pre>';
			case 'boolean':
				return $value ? '<span style="color:#46b450">✓ Yes</span>' : '<span style="color:#888">— No</span>';
			case 'integer':
				return esc_html( number_format( (int) $value ) );
			default:
				return esc_html( $value );
		}
	}

	public static function render_schema_list_screen() {
		if ( ! current_user_can( 'manage_sovereign_schemas' ) ) { wp_die( 'Forbidden.' ); }
		global $wpdb;
		$schemas = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_view_schemas ORDER BY slug ASC" );
		echo '<div class="wrap">';
		echo '<h1>View Schemas <a href="' . esc_url( admin_url( 'admin.php?page=sb-schema-designer' ) ) . '" class="page-title-action">New Schema</a></h1>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Slug</th><th>Label</th><th>Status</th><th>Version</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
		if ( $schemas ) {
			foreach ( $schemas as $s ) {
				echo '<tr><td><code>' . esc_html( $s->slug ) . '</code></td><td>' . esc_html( $s->label ) . '</td>';
				echo '<td>' . esc_html( $s->status ) . '</td><td>' . esc_html( $s->version ) . '</td>';
				echo '<td>' . esc_html( $s->updated_at ) . '</td>';
				echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=sb-schema-designer&slug=' . $s->slug ) ) . '" class="button button-small">Edit</a></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="6">No schemas yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// Shortcode handler
	public static function shortcode_handler( array $atts ): string {
		$atts  = shortcode_atts( [ 'schema' => '', 'context' => 'user' ], $atts );
		$slug  = sanitize_key( $atts['schema'] );
		if ( ! $slug ) { return ''; }
		$schema = self::get_schema( $slug );
		if ( ! $schema ) { return ''; }
		// Only render public fields on front-end
		$front_fields = array_filter( $schema['fields'] ?? [], fn( $f ) => ! empty( $f['front_end'] ) );
		if ( empty( $front_fields ) ) { return ''; }
		ob_start();
		echo '<div class="sb-view-schema">';
		// Minimal front-end render
		global $wpdb;
		$user_id = get_current_user_id();
		$table   = $wpdb->prefix . $schema['source_table'];
		$where   = $user_id ? "WHERE user_id = {$user_id}" : 'WHERE 1=0';
		$rows    = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT 10", ARRAY_A );
		if ( $rows ) {
			echo '<table class="sb-schema-table"><tbody>';
			foreach ( $rows as $row ) {
				foreach ( $front_fields as $field ) {
					echo '<tr><th>' . esc_html( $field['label'] ?? $field['key'] ) . '</th><td>' . self::render_field_value( $field, $row[ $field['key'] ] ?? '' ) . '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	// REST handlers
	public static function handle_rest_list_schemas( $request ) {
		global $wpdb;
		$schemas = $wpdb->get_results( "SELECT slug, label, status, version, updated_at FROM {$wpdb->prefix}sb_view_schemas ORDER BY slug ASC", ARRAY_A );
		return rest_ensure_response( $schemas );
	}

	public static function handle_rest_get_schema( $request ) {
		$slug   = sanitize_key( $request->get_param( 'slug' ) );
		$schema = self::get_schema( $slug );
		if ( ! $schema ) { return SB_Extension_API::rest_error( 'not_found', 'Schema not found.', 404 ); }
		return rest_ensure_response( $schema );
	}

	public static function handle_rest_create_schema( $request ) {
		$params = (array) $request->get_json_params();
		$slug   = sanitize_key( $params['slug'] ?? '' );
		if ( ! $slug ) { return SB_Extension_API::rest_error( 'missing_slug', 'slug required.', 400 ); }
		global $wpdb;
		$data = [
			'slug'        => $slug,
			'label'       => sanitize_text_field( $params['label'] ?? $slug ),
			'schema_json' => wp_json_encode( $params ),
			'status'      => 'draft',
			'version'     => 1,
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		];
		$wpdb->insert( "{$wpdb->prefix}sb_view_schemas", $data );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SCHEMA_DRAFT_SAVED, "Schema {$slug} created.", get_current_user_id() );
		return rest_ensure_response( [ 'success' => true, 'slug' => $slug ] );
	}

	public static function handle_rest_render( $request ) {
		$slug = sanitize_key( $request->get_param( 'slug' ) );
		ob_start();
		self::render_list( $slug );
		$html = ob_get_clean();
		return rest_ensure_response( [ 'html' => $html ] );
	}
}