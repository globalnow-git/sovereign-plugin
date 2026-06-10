<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBUserFieldCatalog
 * Complete user field catalog with publication state, archive/deactivate semantics,
 * CRUD admin screens, per-user history, dependency view, and compliance auditing.
 */
class SBUserFieldCatalog {

	const FIELD_TYPES = [ 'text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'number', 'date', 'url' ];

	/**
	 * Register a custom user field.
	 */
	public static function register_field( array $config ): int {
		global $wpdb;
		$slug = sanitize_key( $config['slug'] ?? '' );
		if ( ! $slug ) { return 0; }

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_user_field_catalog WHERE slug = %s", $slug
		) );
		$data = [
			'slug'            => $slug,
			'label'           => sanitize_text_field( $config['label'] ?? $slug ),
			'field_type'      => in_array( $config['field_type'] ?? 'text', self::FIELD_TYPES, true ) ? $config['field_type'] : 'text',
			'group_slug'      => sanitize_key( $config['group_slug'] ?? 'general' ),
			'validation_json' => wp_json_encode( $config['validation'] ?? [] ),
			'is_sensitive'    => absint( $config['is_sensitive'] ?? 0 ),
			'is_public'       => absint( $config['is_public'] ?? 0 ),
			'required_cap'    => sanitize_key( $config['required_cap'] ?? 'manage_sovereign' ),
		];
		if ( $existing ) {
			$wpdb->update( "{$wpdb->prefix}sb_user_field_catalog", $data, [ 'id' => $existing ] );
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_USER_FIELD_UPDATED, "User field updated: {$slug}", get_current_user_id(), [ 'slug' => $slug ], 'info' );
			return (int) $existing;
		}
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( "{$wpdb->prefix}sb_user_field_catalog", $data );
		$id = (int) $wpdb->insert_id;
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_USER_FIELD_REGISTERED, "User field registered: {$slug}", get_current_user_id(), [ 'slug' => $slug, 'id' => $id ], 'info' );
		return $id;
	}

	public static function get_field( string $slug ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_user_field_catalog WHERE slug = %s", $slug
		) );
	}

	public static function list_fields( ?string $group_slug = null ): array {
		global $wpdb;
		if ( $group_slug ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sb_user_field_catalog WHERE group_slug = %s ORDER BY id ASC",
				$group_slug
			), ARRAY_A );
		}
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_user_field_catalog ORDER BY group_slug, id ASC",
			ARRAY_A
		);
	}

	/**
	 * Archive a field (non-destructive deactivation).
	 */
	public static function archive_field( string $slug ): bool {
		global $wpdb;
		if ( ! current_user_can( 'manage_sovereign' ) ) { return false; }
		$field = self::get_field( $slug );
		if ( ! $field ) { return false; }

		// Archive = soft delete: does not drop usermeta, preserves history
		$wpdb->update( "{$wpdb->prefix}sb_user_field_catalog",
			[ 'is_public' => 0, 'required_cap' => 'manage_sovereign' ],
			[ 'slug' => $slug ]
		);
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_USER_FIELD_ARCHIVED, "User field archived: {$slug}", get_current_user_id(), [ 'slug' => $slug ], 'warning' );
		return true;
	}

	/**
	 * Get all custom field values for a user, respecting permissions.
	 */
	public static function get_user_values( int $user_id ): array {
		$fields = self::list_fields();
		$values = [];
		$current_user = get_current_user_id();
		foreach ( $fields as $field ) {
			$slug  = $field['slug'];
			// Check permission
			if ( ! $field['is_public'] && ! current_user_can( $field['required_cap'] ) && $current_user !== $user_id ) {
				continue;
			}
			$raw = get_user_meta( $user_id, 'sb_field_' . $slug, true );
			// Redact sensitive field values from non-admins
			if ( $field['is_sensitive'] && ! current_user_can( $field['required_cap'] ) ) {
				$values[ $slug ] = strlen( (string) $raw ) > 0 ? '[REDACTED]' : '';
			} else {
				$values[ $slug ] = $raw;
			}
		}
		return $values;
	}

	/**
	 * Get usage / dependency view — which forms and views reference this field.
	 */
	public static function get_field_usage( string $field_slug ): array {
		global $wpdb;
		$results = [];
		$like = '%' . $wpdb->esc_like( $field_slug ) . '%';

		$forms = $wpdb->get_results( $wpdb->prepare(
			"SELECT slug, label FROM {$wpdb->prefix}sb_tiny_forms WHERE fields_json LIKE %s", $like
		), ARRAY_A );
		foreach ( $forms as $f ) {
			$results[] = [ 'type' => 'form', 'slug' => $f['slug'], 'label' => $f['label'] ];
		}

		$schemas = $wpdb->get_results( $wpdb->prepare(
			"SELECT slug, label FROM {$wpdb->prefix}sb_view_schemas WHERE schema_json LIKE %s", $like
		), ARRAY_A );
		foreach ( $schemas as $s ) {
			$results[] = [ 'type' => 'view_schema', 'slug' => $s['slug'], 'label' => $s['label'] ];
		}

		return $results;
	}

	/**
	 * Render profile panel on user edit screens.
	 */
	public static function render_profile_panel( WP_User $user ): void {
		$fields = self::list_fields();
		if ( empty( $fields ) ) { return; }
		echo '<h2>Sovereign Builder Profile Fields</h2>';
		echo '<table class="form-table">';
		$groups = [];
		foreach ( $fields as $field ) {
			$groups[ $field['group_slug'] ][] = $field;
		}
		foreach ( $groups as $group => $group_fields ) {
			echo '<tr><th colspan="2"><strong>' . esc_html( ucwords( str_replace( '-', ' ', $group ) ) ) . '</strong></th></tr>';
			foreach ( $group_fields as $field ) {
				if ( ! $field['is_public'] && ! current_user_can( $field['required_cap'] ) && get_current_user_id() !== $user->ID ) {
					continue;
				}
				$meta_key = 'sb_field_' . $field['slug'];
				$value    = get_user_meta( $user->ID, $meta_key, true );
				$id       = 'sbuf_' . esc_attr( $field['slug'] );
				$label    = esc_html( $field['label'] );
				echo '<tr><th><label for="' . $id . '">' . $label . '</label>';
				if ( $field['is_sensitive'] ) {
					echo ' <span style="color:red" title="Sensitive">🔒</span>';
				}
				echo '</th><td>';
				if ( $field['is_sensitive'] && ! current_user_can( $field['required_cap'] ) ) {
					echo '<em style="color:#888;">This field is managed by administrators.</em>';
				} elseif ( $field['field_type'] === 'textarea' ) {
					echo '<textarea name="' . $id . '" id="' . $id . '" rows="4" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
				} else {
					echo '<input type="text" name="' . $id . '" id="' . $id . '" value="' . esc_attr( (string) $value ) . '" class="regular-text">';
				}
				echo '</td></tr>';
			}
		}
		echo '</table>';
		wp_nonce_field( 'sb_user_fields_save_' . $user->ID, 'sb_user_fields_nonce' );
	}

	public static function save_profile_fields( int $user_id ): void {
		if ( ! isset( $_POST['sb_user_fields_nonce'] )
			|| ! wp_verify_nonce( $_POST['sb_user_fields_nonce'], 'sb_user_fields_save_' . $user_id ) ) {
			return;
		}
		$fields = self::list_fields();
		foreach ( $fields as $field ) {
			$key = 'sbuf_' . $field['slug'];
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			if ( ! $field['is_public'] && ! current_user_can( $field['required_cap'] ) && get_current_user_id() !== $user_id ) { continue; }
			$val = $field['field_type'] === 'textarea'
				? sanitize_textarea_field( $_POST[ $key ] )
				: sanitize_text_field( $_POST[ $key ] );
			SBUserFieldMutationService::set( $user_id, $field['slug'], $val, get_current_user_id() );
		}
	}

	/**
	 * Full admin screen: catalog list + create + field history.
	 */
	public static function render_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }
		$guard = SBAdminGuard::require_tables( [ 'sb_user_field_catalog' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$tab = sanitize_key( $_GET['tab'] ?? 'catalog' );

		echo '<div class="wrap"><h1>User Field Catalog</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( [ 'catalog' => 'Field Catalog', 'history' => 'Mutation History', 'usage' => 'Dependency Usage' ] as $t => $label ) {
			$active = ( $tab === $t ) ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( admin_url( "admin.php?page=sb-user-fields&tab={$t}" ) ) . '" class="nav-tab' . $active . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'history':
				$user_id  = absint( $_GET['user_id'] ?? 0 );
				$field_slug = sanitize_key( $_GET['field'] ?? '' );
				if ( $user_id && $field_slug ) {
					$history = SBUserFieldMutationService::get_history( $user_id, $field_slug );
					echo '<h2>History: ' . esc_html( $field_slug ) . ' for User #' . $user_id . '</h2>';
					echo '<table class="widefat striped"><thead><tr><th>Old Value</th><th>New Value</th><th>Changed By</th><th>When</th></tr></thead><tbody>';
					foreach ( $history as $h ) {
						$field_def = self::get_field( $h['field_slug'] );
						$is_sensitive = $field_def && $field_def->is_sensitive;
						echo '<tr>';
						echo '<td>' . ( $is_sensitive ? '[REDACTED]' : esc_html( substr( $h['old_value'], 0, 80 ) ) ) . '</td>';
						echo '<td>' . ( $is_sensitive ? '[REDACTED]' : esc_html( substr( $h['new_value'], 0, 80 ) ) ) . '</td>';
						echo '<td>' . esc_html( $h['actor_email'] ?? "User #{$h['changed_by']}" ) . '</td>';
						echo '<td>' . esc_html( $h['changed_at'] ) . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table>';
				} else {
					// List all recent mutations
					$mutations = $wpdb->get_results(
						"SELECT h.*, u.user_email as actor_email, sub.user_email as subject_email
						 FROM {$wpdb->prefix}sb_user_field_history h
						 LEFT JOIN {$wpdb->users} u ON u.ID = h.changed_by
						 LEFT JOIN {$wpdb->users} sub ON sub.ID = h.user_id
						 ORDER BY h.changed_at DESC LIMIT 50",
						ARRAY_A
					);
					echo '<h2>Recent Field Mutations</h2>';
					echo '<table class="widefat striped"><thead><tr><th>Field</th><th>User</th><th>Changed By</th><th>When</th><th>Actions</th></tr></thead><tbody>';
					foreach ( $mutations as $m ) {
						echo '<tr>';
						echo '<td><code>' . esc_html( $m['field_slug'] ) . '</code></td>';
						echo '<td>' . esc_html( $m['subject_email'] ?? "User #{$m['user_id']}" ) . '</td>';
						echo '<td>' . esc_html( $m['actor_email'] ?? "User #{$m['changed_by']}" ) . '</td>';
						echo '<td>' . esc_html( $m['changed_at'] ) . '</td>';
						echo '<td><a href="' . esc_url( admin_url( "admin.php?page=sb-user-fields&tab=history&user_id={$m['user_id']}&field={$m['field_slug']}" ) ) . '">View history</a></td>';
						echo '</tr>';
					}
					if ( empty( $mutations ) ) {
						echo '<tr><td colspan="5">No field mutations recorded yet.</td></tr>';
					}
					echo '</tbody></table>';
				}
				break;

			case 'usage':
				$field_slug = sanitize_key( $_GET['field'] ?? '' );
				if ( $field_slug ) {
					$usage = self::get_field_usage( $field_slug );
					echo '<h2>Usage: <code>' . esc_html( $field_slug ) . '</code></h2>';
					echo '<table class="widefat striped"><thead><tr><th>Object Type</th><th>Slug</th><th>Label</th></tr></thead><tbody>';
					foreach ( $usage as $u ) {
						echo '<tr><td>' . esc_html( $u['type'] ) . '</td><td><code>' . esc_html( $u['slug'] ) . '</code></td><td>' . esc_html( $u['label'] ) . '</td></tr>';
					}
					if ( empty( $usage ) ) {
						echo '<tr><td colspan="3">No objects reference this field.</td></tr>';
					}
					echo '</tbody></table>';
				} else {
					echo '<p>Select a field to trace its usage.</p>';
				}
				break;

			default: // catalog
				$fields = self::list_fields();
				echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Label</th><th>Type</th><th>Group</th><th>Sensitive</th><th>Public</th><th>Cap</th><th>Actions</th></tr></thead><tbody>';
				foreach ( $fields as $f ) {
					echo '<tr>';
					echo '<td><code>sb_field_' . esc_html( $f['slug'] ) . '</code></td>';
					echo '<td>' . esc_html( $f['label'] ) . '</td>';
					echo '<td>' . esc_html( $f['field_type'] ) . '</td>';
					echo '<td>' . esc_html( $f['group_slug'] ) . '</td>';
					echo '<td>' . ( $f['is_sensitive'] ? '🔒 Yes' : 'No' ) . '</td>';
					echo '<td>' . ( $f['is_public'] ? 'Yes' : 'No' ) . '</td>';
					echo '<td><code>' . esc_html( $f['required_cap'] ) . '</code></td>';
					echo '<td>';
					echo '<a href="' . esc_url( admin_url( "admin.php?page=sb-user-fields&tab=usage&field={$f['slug']}" ) ) . '">Usage</a>';
					echo '</td></tr>';
				}
				if ( empty( $fields ) ) {
					echo '<tr><td colspan="8">No fields registered. Use <code>SBUserFieldCatalog::register_field()</code>.</td></tr>';
				}
				echo '</tbody></table>';
		}
		echo '</div>';
	}
}

// Profile hooks
add_action( 'show_user_profile',   [ 'SBUserFieldCatalog', 'render_profile_panel' ] );
add_action( 'edit_user_profile',   [ 'SBUserFieldCatalog', 'render_profile_panel' ] );
add_action( 'personal_options_update',   [ 'SBUserFieldCatalog', 'save_profile_fields' ] );
add_action( 'edit_user_profile_update', [ 'SBUserFieldCatalog', 'save_profile_fields' ] );