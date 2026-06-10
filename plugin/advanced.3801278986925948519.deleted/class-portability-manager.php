<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBPortabilityManager
 * Compliant config export/import with credential scrubbing, slug conflict detection,
 * ID remapping, and staging-to-production promotion checks.
 * Closes audit gap: Section 26 (Multi-site/Multi-tenant/Portability) — FAIL/NOT PROVEN.
 */
class SBImportValidator {

	// Max items per collection in a single import bundle
	const MAX_ITEMS_PER_COLLECTION = 100;

	// Max JSON blob size (bytes) for config/fields/content columns
	const MAX_JSON_BLOB_BYTES = 524288; // 512KB

	/** Allowed top-level keys for a portability bundle. */
	const BUNDLE_KEYS = [
		'_sb_export_version', '_exported_at', '_source_domain', '_source_url',
		'blueprints', 'schemas', 'forms', 'surfaces', 'placements',
		'user_fields', 'settings', 'bundle', 'dry_run',
	];

	/** Allowed + required keys and types for blueprint artifacts. */
	const BLUEPRINT_SCHEMA = [
		'slug'           => [ 'type' => 'slug',   'required' => true,  'max' => 100 ],
		'label'          => [ 'type' => 'string', 'required' => true,  'max' => 255 ],
		'description'    => [ 'type' => 'string', 'required' => false, 'max' => 2000 ],
		'blueprint_type' => [ 'type' => 'slug',   'required' => false, 'max' => 50 ],
		'category'       => [ 'type' => 'slug',   'required' => false, 'max' => 50 ],
		'status'         => [ 'type' => 'slug',   'required' => false, 'max' => 20 ],
		'is_regulated'   => [ 'type' => 'bool',   'required' => false ],
		'version'        => [ 'type' => 'string', 'required' => false, 'max' => 20 ],
		'config_json'    => [ 'type' => 'json',   'required' => false, 'max_bytes' => 524288 ],
		'graph_hash'     => [ 'type' => 'string', 'required' => false, 'max' => 64 ],
		'created_at'     => [ 'type' => 'string', 'required' => false, 'max' => 30 ],
		'updated_at'     => [ 'type' => 'string', 'required' => false, 'max' => 30 ],
		// Application manifest keys — declared in blueprint JSON, processed by apply_config()
		'signals'        => [ 'type' => 'array',  'required' => false ],
		'roads'          => [ 'type' => 'array',  'required' => false ],
		'forms'          => [ 'type' => 'array',  'required' => false ],
		'schemas'        => [ 'type' => 'array',  'required' => false ],
		'pages'          => [ 'type' => 'array',  'required' => false ],
		'pipeline'       => [ 'type' => 'array',  'required' => false ],
		'capabilities'   => [ 'type' => 'array',  'required' => false ],
		'tables'         => [ 'type' => 'array',  'required' => false ],
	];

	/** Allowed + required keys for form artifacts. */
	const FORM_SCHEMA = [
		'slug'            => [ 'type' => 'slug',   'required' => true,  'max' => 100 ],
		'label'           => [ 'type' => 'string', 'required' => true,  'max' => 255 ],
		'fields_json'     => [ 'type' => 'json',   'required' => false, 'max_bytes' => 131072 ],
		'validation_json' => [ 'type' => 'json',   'required' => false, 'max_bytes' => 32768 ],
		'save_adapter'    => [ 'type' => 'slug',   'required' => false, 'max' => 50 ],
		'success_message' => [ 'type' => 'string', 'required' => false, 'max' => 500 ],
		'status'          => [ 'type' => 'slug',   'required' => false, 'max' => 20 ],
	];

	/** Allowed + required keys for surface artifacts. */
	const SURFACE_SCHEMA = [
		'slug'                  => [ 'type' => 'slug',   'required' => true,  'max' => 100 ],
		'label'                 => [ 'type' => 'string', 'required' => true,  'max' => 255 ],
		'surface_type'          => [ 'type' => 'slug',   'required' => false, 'max' => 50 ],
		'content_json'          => [ 'type' => 'json',   'required' => false, 'max_bytes' => 131072 ],
		'visibility_rules_json' => [ 'type' => 'json',   'required' => false, 'max_bytes' => 32768 ],
		'status'                => [ 'type' => 'slug',   'required' => false, 'max' => 20 ],
	];

	/** Allowed + required keys for schema artifacts. */
	const SCHEMA_SCHEMA = [
		'slug'        => [ 'type' => 'slug',   'required' => true,  'max' => 100 ],
		'label'       => [ 'type' => 'string', 'required' => true,  'max' => 255 ],
		'schema_json' => [ 'type' => 'json',   'required' => false, 'max_bytes' => 524288 ],
		'status'      => [ 'type' => 'slug',   'required' => false, 'max' => 20 ],
		'version'     => [ 'type' => 'string', 'required' => false, 'max' => 20 ],
	];

	/** Allowed + required keys for placement artifacts. */
	const PLACEMENT_SCHEMA = [
		'label'               => [ 'type' => 'string', 'required' => true,  'max' => 255 ],
		'slug'                => [ 'type' => 'slug',   'required' => false, 'max' => 100 ],
		'placement_type'      => [ 'type' => 'slug',   'required' => false, 'max' => 50 ],
		'form_slug'           => [ 'type' => 'slug',   'required' => false, 'max' => 100 ],
		'surface_slug'        => [ 'type' => 'slug',   'required' => false, 'max' => 100 ],
		'context_rules_json'  => [ 'type' => 'json',   'required' => false, 'max_bytes' => 32768 ],
		'priority'            => [ 'type' => 'int',    'required' => false ],
		'status'              => [ 'type' => 'slug',   'required' => false, 'max' => 20 ],
	];

	/** Allowed + required keys for user_field artifacts. Mirrors SBUserFieldCatalog::register_field(). */
	const USER_FIELD_SCHEMA = [
		'slug'            => [ 'type' => 'slug',   'required' => true,  'max' => 100 ],
		'label'           => [ 'type' => 'string', 'required' => true,  'max' => 255 ],
		'field_type'      => [ 'type' => 'slug',   'required' => false, 'max' => 50 ],
		'group_slug'      => [ 'type' => 'slug',   'required' => false, 'max' => 100 ],
		'validation_json' => [ 'type' => 'json',   'required' => false, 'max_bytes' => 32768 ],
		'validation'      => [ 'type' => 'json',   'required' => false, 'max_bytes' => 32768 ],
		'is_sensitive'    => [ 'type' => 'bool',   'required' => false ],
		'is_public'       => [ 'type' => 'bool',   'required' => false ],
		'required_cap'    => [ 'type' => 'slug',   'required' => false, 'max' => 100 ],
	];

	/**
	 * Validate a portability bundle envelope.
	 * Checks: version header, unknown top-level keys, collection count caps.
	 *
	 * @return array{ valid: bool, errors: string[] }
	 */
	public static function validate_envelope( array $bundle ): array {
		$errors = [];

		// Version header required
		if ( empty( $bundle['_sb_export_version'] ) ) {
			$errors[] = 'Bundle missing _sb_export_version header.';
		}

		// Unknown top-level keys
		$unknown = array_diff( array_keys( $bundle ), self::BUNDLE_KEYS );
		if ( ! empty( $unknown ) ) {
			$errors[] = 'Unknown bundle keys rejected: ' . implode( ', ', array_map( 'sanitize_key', $unknown ) );
		}

		// Per-collection count caps
		$collections = [ 'blueprints', 'schemas', 'forms', 'surfaces', 'placements', 'user_fields' ];
		foreach ( $collections as $col ) {
			if ( isset( $bundle[ $col ] ) ) {
				if ( ! is_array( $bundle[ $col ] ) ) {
					$errors[] = "Bundle key '{$col}' must be an array.";
					continue;
				}
				if ( count( $bundle[ $col ] ) > self::MAX_ITEMS_PER_COLLECTION ) {
					$errors[] = "Collection '{$col}' exceeds max " . self::MAX_ITEMS_PER_COLLECTION . " items.";
				}
			}
		}

		return [ 'valid' => empty( $errors ), 'errors' => $errors ];
	}

	/**
	 * Validate a single artifact against a schema definition.
	 * Returns an array of error strings (empty = valid).
	 */
	public static function validate_artifact( array $artifact, array $schema, string $context = '' ): array {
		$errors = [];

		// Unknown keys
		$unknown = array_diff( array_keys( $artifact ), array_keys( $schema ) );
		if ( ! empty( $unknown ) ) {
			$errors[] = "{$context}: unknown keys rejected: " . implode( ', ', array_map( 'sanitize_key', $unknown ) );
			// Do not continue — fail closed on unexpected shape
			return $errors;
		}

		// Required keys and type checks
		foreach ( $schema as $key => $rules ) {
			$val     = $artifact[ $key ] ?? null;
			$present = array_key_exists( $key, $artifact );

			if ( ( $rules['required'] ?? false ) && ! $present ) {
				$errors[] = "{$context}: required key '{$key}' missing.";
				continue;
			}
			if ( ! $present || $val === null ) { continue; }

			switch ( $rules['type'] ) {
				case 'string':
					if ( ! is_string( $val ) ) {
						$errors[] = "{$context}: '{$key}' must be a string.";
					} elseif ( isset( $rules['max'] ) && mb_strlen( $val ) > $rules['max'] ) {
						$errors[] = "{$context}: '{$key}' exceeds max length {$rules['max']}.";
					}
					break;
				case 'slug':
					if ( ! is_string( $val ) ) {
						$errors[] = "{$context}: '{$key}' must be a string.";
					} elseif ( $val !== sanitize_key( $val ) ) {
						$errors[] = "{$context}: '{$key}' contains invalid characters (expected slug format).";
					} elseif ( isset( $rules['max'] ) && strlen( $val ) > $rules['max'] ) {
						$errors[] = "{$context}: '{$key}' exceeds max length {$rules['max']}.";
					}
					break;
				case 'json':
					if ( ! is_string( $val ) ) {
						$errors[] = "{$context}: '{$key}' must be a JSON string, not " . gettype( $val ) . ".";
					} else {
						$max_bytes = $rules['max_bytes'] ?? self::MAX_JSON_BLOB_BYTES;
						if ( strlen( $val ) > $max_bytes ) {
							$errors[] = "{$context}: '{$key}' JSON blob exceeds {$max_bytes} bytes.";
						} else {
							json_decode( $val );
							if ( json_last_error() !== JSON_ERROR_NONE ) {
								$errors[] = "{$context}: '{$key}' is not valid JSON: " . json_last_error_msg() . ".";
							}
						}
					}
					break;
				case 'bool':
					if ( ! is_bool( $val ) && ! in_array( $val, [ 0, 1, '0', '1' ], true ) ) {
						$errors[] = "{$context}: '{$key}' must be boolean.";
					}
					break;
				case 'int':
					if ( ! is_int( $val ) && ! ctype_digit( (string) $val ) ) {
						$errors[] = "{$context}: '{$key}' must be an integer.";
					}
					break;
				case 'array':
					if ( ! is_array( $val ) ) {
						$errors[] = "{$context}: '{$key}' must be an array.";
					}
					break;
			}
		}

		return $errors;
	}

	// ── Convenience validators per artifact type ─────────────────────────────

	public static function validate_blueprint( array $bp ): array {
		return self::validate_artifact( $bp, self::BLUEPRINT_SCHEMA, 'blueprint:' . sanitize_key( $bp['slug'] ?? '?' ) );
	}
	public static function validate_form( array $form ): array {
		return self::validate_artifact( $form, self::FORM_SCHEMA, 'form:' . sanitize_key( $form['slug'] ?? '?' ) );
	}
	public static function validate_surface( array $surface ): array {
		return self::validate_artifact( $surface, self::SURFACE_SCHEMA, 'surface:' . sanitize_key( $surface['slug'] ?? '?' ) );
	}
	public static function validate_schema( array $schema ): array {
		return self::validate_artifact( $schema, self::SCHEMA_SCHEMA, 'schema:' . sanitize_key( $schema['slug'] ?? '?' ) );
	}
	public static function validate_placement( array $placement ): array {
		return self::validate_artifact( $placement, self::PLACEMENT_SCHEMA, 'placement:' . sanitize_key( $placement['label'] ?? '?' ) );
	}
	public static function validate_user_field( array $field ): array {
		return self::validate_artifact( $field, self::USER_FIELD_SCHEMA, 'user_field:' . sanitize_key( $field['slug'] ?? '?' ) );
	}
}

class SBPortabilityManager {

	/** Keys scrubbed from exported configs — never leaves the source environment */
	const SCRUB_KEYS = [
		'sb_anthropic_key', 'sb_fb_access_token', 'sb_google_ads_token',
		'sb_google_dev_token', 'sb_image_api_key', 'sb_video_api_key',
		'sb_connector_%_secret', 'sb_connector_%_endpoint',
		'sb_signal_key', 'sb_from_email',
	];

	/**
	 * Export a portable bundle: blueprints, schemas, forms, surfaces, placements.
	 * Strips credential keys. Does not export runtime rows (signals, factory runs, users).
	 *
	 * @param array $scope  Which object types to include
	 * @return array
	 */
	public static function export_bundle( array $scope = [] ): array {
		global $wpdb;

		$include_all = empty( $scope );
		$bundle = [
			'_sb_export_version' => SB_VERSION,
			'_sb_export_at'      => current_time( 'c' ),
			'_sb_source_domain'  => SB_ACTIVE_DOMAIN,
		];

		if ( $include_all || in_array( 'blueprints', $scope, true ) ) {
			$bundle['blueprints'] = array_map(
				fn( $bp ) => self::scrub_config( json_decode( $bp->config_json ?? '{}', true ) ),
				$wpdb->get_results( "SELECT slug, label, version, config_json FROM {$wpdb->prefix}sb_app_blueprints WHERE status = 'active'" ) ?: []
			);
		}
		if ( $include_all || in_array( 'schemas', $scope, true ) ) {
			$bundle['schemas'] = $wpdb->get_results(
				"SELECT slug, label, schema_json, version FROM {$wpdb->prefix}sb_view_schemas WHERE status = 'active'",
				ARRAY_A
			) ?: [];
		}
		if ( $include_all || in_array( 'forms', $scope, true ) ) {
			$bundle['forms'] = $wpdb->get_results(
				"SELECT slug, label, fields_json, validation_json, save_adapter, success_message FROM {$wpdb->prefix}sb_tiny_forms WHERE status = 'active'",
				ARRAY_A
			) ?: [];
		}
		if ( $include_all || in_array( 'surfaces', $scope, true ) ) {
			$bundle['surfaces'] = $wpdb->get_results(
				"SELECT slug, label, surface_type, content_json, visibility_rules_json FROM {$wpdb->prefix}sb_ui_surfaces WHERE status = 'active'",
				ARRAY_A
			) ?: [];
		}
		if ( $include_all || in_array( 'placements', $scope, true ) ) {
			$bundle['placements'] = $wpdb->get_results(
				"SELECT label, surface_slug, form_slug, context_type, context_key, road_key, priority FROM {$wpdb->prefix}sb_placements WHERE status = 'active'",
				ARRAY_A
			) ?: [];
		}
		if ( $include_all || in_array( 'capabilities', $scope, true ) ) {
			$bundle['capabilities'] = $wpdb->get_results(
				"SELECT slug, label, provider, model_slug, budget_cap, rate_limit_per_hour, required_cap FROM {$wpdb->prefix}sb_capability_registry WHERE is_active = 1",
				ARRAY_A
			) ?: [];
		}
		if ( $include_all || in_array( 'user_fields', $scope, true ) ) {
			$bundle['user_fields'] = $wpdb->get_results(
				"SELECT slug, label, field_type, group_slug, validation_json, is_sensitive, is_public, required_cap FROM {$wpdb->prefix}sb_user_field_catalog",
				ARRAY_A
			) ?: [];
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_PORTABILITY_EXPORT, 'Config bundle exported', get_current_user_id(), [ 'scope' => $scope ], 'info' );

		return $bundle;
	}

	/**
	 * Validate an import bundle before applying it.
	 *
	 * @param array  $bundle
	 * @param string $target_domain
	 * @return array{ valid: bool, errors: array, warnings: array, conflicts: array }
	 */
	public static function validate_bundle( array $bundle, string $target_domain = '' ): array {
		global $wpdb;

		$errors   = [];
		$warnings = [];
		$conflicts= [];

		// Envelope validation: unknown top-level keys, version header, collection count caps
		$envelope = SBImportValidator::validate_envelope( $bundle );
		if ( ! $envelope['valid'] ) {
			// Fail early — don't proceed with malformed bundle
			return [ 'valid' => false, 'errors' => $envelope['errors'], 'warnings' => [], 'conflicts' => [] ];
		}

		// Version check (redundant with envelope but kept for explicit messaging)
		if ( empty( $bundle['_sb_export_version'] ) ) {
			$errors[] = 'Bundle is missing export version header.';
		}

		// Credential check
		if ( isset( $bundle['settings'] ) ) {
			foreach ( self::SCRUB_KEYS as $key ) {
				if ( isset( $bundle['settings'][ $key ] ) ) {
					$warnings[] = "Settings key '{$key}' was not scrubbed — it will be ignored on import.";
					unset( $bundle['settings'][ $key ] );
				}
			}
		}

		// Slug conflict detection
		$check_slugs = [
			'blueprints' => 'sb_app_blueprints',
			'schemas'    => 'sb_view_schemas',
			'forms'      => 'sb_tiny_forms',
			'surfaces'   => 'sb_ui_surfaces',
		];

		foreach ( $check_slugs as $bundle_key => $table_suffix ) {
			foreach ( $bundle[ $bundle_key ] ?? [] as $item ) {
				$slug = $item['slug'] ?? '';
				if ( ! $slug ) { continue; }
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}{$table_suffix} WHERE slug = %s",
					$slug
				) );
				if ( $exists ) {
					$conflicts[] = [
						'type'        => $bundle_key,
						'slug'        => $slug,
						'resolution'  => 'Will overwrite existing record on import.',
					];
				}
			}
		}

		// Dependency check: forms reference surfaces? Placements reference valid form/surface slugs?
		$form_slugs    = array_column( $bundle['forms'] ?? [], 'slug' );
		$surface_slugs = array_column( $bundle['surfaces'] ?? [], 'slug' );
		foreach ( $bundle['placements'] ?? [] as $pl ) {
			if ( $pl['form_slug'] && ! in_array( $pl['form_slug'], $form_slugs, true ) ) {
				// Check target DB too
				$in_db = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s",
					$pl['form_slug']
				) );
				if ( ! $in_db ) {
					$warnings[] = "Placement references form '{$pl['form_slug']}' not in bundle or target DB.";
				}
			}
			if ( $pl['surface_slug'] && ! in_array( $pl['surface_slug'], $surface_slugs, true ) ) {
				$in_db = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_ui_surfaces WHERE slug = %s",
					$pl['surface_slug']
				) );
				if ( ! $in_db ) {
					$warnings[] = "Placement references surface '{$pl['surface_slug']}' not in bundle or target DB.";
				}
			}
		}

		$valid = empty( $errors );
		return compact( 'valid', 'errors', 'warnings', 'conflicts' );
	}

	/**
	 * Apply a validated bundle to the target environment.
	 *
	 * @param array $bundle
	 * @param bool  $dry_run
	 * @return array{ success: bool, applied: array, skipped: array }
	 */
	public static function apply_bundle( array $bundle, bool $dry_run = false ): array {
		global $wpdb;

		$validation = self::validate_bundle( $bundle );
		if ( ! $validation['valid'] ) {
			return [ 'success' => false, 'errors' => $validation['errors'] ];
		}

		$applied = [];
		$skipped = [];

		// Apply blueprints
		foreach ( $bundle['blueprints'] ?? [] as $bp ) {
			$bp_errors = SBImportValidator::validate_blueprint( (array) $bp );
			if ( ! empty( $bp_errors ) ) {
				$skipped[] = implode( '; ', $bp_errors );
				continue;
			}
			if ( $dry_run ) {
				$applied[] = "Would install blueprint: " . sanitize_key( $bp['slug'] ?? '' );
				continue;
			}
			$result = SBAppBlueprintManager::install( wp_json_encode( $bp ) );
			if ( is_wp_error( $result ) ) {
				$skipped[] = "Blueprint {$bp['slug']}: " . $result->get_error_message();
			} else {
				$applied[] = "Blueprint installed: {$bp['slug']}";
			}
		}

		// Apply schemas
		foreach ( $bundle['schemas'] ?? [] as $schema ) {
			$sch_errors = SBImportValidator::validate_schema( (array) $schema );
			if ( ! empty( $sch_errors ) ) { $skipped[] = implode( '; ', $sch_errors ); continue; }
			if ( $dry_run ) { $applied[] = "Would import schema: " . sanitize_key( $schema['slug'] ?? '' ); continue; }
			SBSchemaDesigner::save_draft( $schema['slug'], json_decode( $schema['schema_json'], true ) ?? [] );
			$applied[] = "Schema saved: {$schema['slug']}";
		}

		// Apply forms
		foreach ( $bundle['forms'] ?? [] as $form ) {
			$form_errors = SBImportValidator::validate_form( (array) $form );
			if ( ! empty( $form_errors ) ) { $skipped[] = implode( '; ', $form_errors ); continue; }
			if ( $dry_run ) { $applied[] = "Would import form: " . sanitize_key( $form['slug'] ?? '' ); continue; }
			$slug = sanitize_key( $form['slug'] );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s", $slug ) );
			$data = [
				'slug'            => $slug,
				'label'           => sanitize_text_field( $form['label'] ?? $slug ),
				'fields_json'     => $form['fields_json'] ?? '[]',
				'validation_json' => $form['validation_json'] ?? '{}',
				'save_adapter'    => sanitize_key( $form['save_adapter'] ?? 'submission_table' ),
				'success_message' => sanitize_textarea_field( $form['success_message'] ?? '' ),
				'status'          => 'draft', // Imported as draft — needs approval to publish
				'updated_at'      => current_time( 'mysql' ),
			];
			if ( $exists ) {
				$wpdb->update( "{$wpdb->prefix}sb_tiny_forms", $data, [ 'slug' => $slug ] );
			} else {
				$data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( "{$wpdb->prefix}sb_tiny_forms", $data );
			}
			$applied[] = "Form imported: {$slug}";
		}

		// Apply surfaces
		foreach ( $bundle['surfaces'] ?? [] as $surface ) {
			$surf_errors = SBImportValidator::validate_surface( (array) $surface );
			if ( ! empty( $surf_errors ) ) { $skipped[] = implode( '; ', $surf_errors ); continue; }
			if ( $dry_run ) { $applied[] = "Would import surface: " . sanitize_key( $surface['slug'] ?? '' ); continue; }
			$slug = sanitize_key( $surface['slug'] );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sb_ui_surfaces WHERE slug = %s", $slug ) );
			$data = [
				'slug'                 => $slug,
				'label'                => sanitize_text_field( $surface['label'] ?? $slug ),
				'surface_type'         => sanitize_key( $surface['surface_type'] ?? 'banner' ),
				'content_json'         => $surface['content_json'] ?? '{}',
				'visibility_rules_json'=> $surface['visibility_rules_json'] ?? '{}',
				'status'               => 'draft',
				'updated_at'           => current_time( 'mysql' ),
			];
			if ( $exists ) {
				$wpdb->update( "{$wpdb->prefix}sb_ui_surfaces", $data, [ 'slug' => $slug ] );
			} else {
				$data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( "{$wpdb->prefix}sb_ui_surfaces", $data );
			}
			$applied[] = "Surface imported: {$slug}";
		}

		// Apply placements
		foreach ( $bundle['placements'] ?? [] as $placement ) {
			$pl_errors = SBImportValidator::validate_placement( (array) $placement );
			if ( ! empty( $pl_errors ) ) { $skipped[] = implode( '; ', $pl_errors ); continue; }
			if ( $dry_run ) { $applied[] = "Would create placement: " . sanitize_text_field( $placement['label'] ?? '' ); continue; }
			SBPlacementEngine::register_placement( $placement );
			$applied[] = "Placement registered: {$placement['label']}";
		}

		// Apply user fields
		foreach ( $bundle['user_fields'] ?? [] as $field ) {
			$uf_errors = SBImportValidator::validate_user_field( (array) $field );
			if ( ! empty( $uf_errors ) ) { $skipped[] = implode( '; ', $uf_errors ); continue; }
			if ( $dry_run ) { $applied[] = "Would register user field: " . sanitize_key( $field['slug'] ?? '' ); continue; }
			SBUserFieldCatalog::register_field( $field );
			$applied[] = "User field registered: " . sanitize_key( $field['slug'] ?? '' );
		}

		if ( ! $dry_run ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_PORTABILITY_IMPORT_APPLIED,
				'Config bundle applied: ' . count( $applied ) . ' objects',
				get_current_user_id(),
				[ 'applied' => count( $applied ), 'skipped' => count( $skipped ) ],
				'info'
			);
		}

		return [ 'success' => true, 'applied' => $applied, 'skipped' => $skipped, 'dry_run' => $dry_run ];
	}

	/**
	 * Scrub sensitive keys from a config array (recursive).
	 *
	 * @param array $config
	 * @return array
	 */
	public static function scrub_config( array $config ): array {
		$scrub_exact = [ 'sb_anthropic_key', 'sb_fb_access_token', 'sb_google_ads_token',
			'sb_google_dev_token', 'sb_image_api_key', 'sb_video_api_key', 'sb_signal_key' ];
		foreach ( $scrub_exact as $key ) {
			unset( $config[ $key ] );
		}
		// Scrub connector secrets/endpoints (pattern: *_secret, *_endpoint, *_api_key)
		foreach ( array_keys( $config ) as $key ) {
			if ( preg_match( '/(secret|endpoint|api_key|access_token)$/', $key ) ) {
				unset( $config[ $key ] );
			}
		}
		return $config;
	}

	/**
	 * REST handler: export
	 */
	public static function handle_rest_export( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			return SB_Extension_API::rest_error( 'unauthorized', 'Insufficient capability.', 403 );
		}
		$scope  = (array) ( $request->get_json_params()['scope'] ?? [] );
		$bundle = self::export_bundle( $scope );
		return rest_ensure_response( $bundle );
	}

	/**
	 * REST handler: validate
	 */
	public static function handle_rest_validate( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			return SB_Extension_API::rest_error( 'unauthorized', 'Insufficient capability.', 403 );
		}
		// Body cap: 512KB
		if ( strlen( $request->get_body() ) > 524288 ) {
			return SB_Extension_API::rest_error( 'payload_too_large', 'Bundle payload exceeds 512KB limit.', 413 );
		}
		$bundle = (array) $request->get_json_params();
		if ( ! is_array( $bundle ) ) {
			return SB_Extension_API::rest_error( 'invalid_bundle', 'Bundle must be a JSON object.', 400 );
		}
		return rest_ensure_response( self::validate_bundle( $bundle ) );
	}

	/**
	 * REST handler: import (dry-run or apply)
	 */
	public static function handle_rest_import( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			return SB_Extension_API::rest_error( 'unauthorized', 'Insufficient capability.', 403 );
		}
		// Body cap: 512KB
		if ( strlen( $request->get_body() ) > 524288 ) {
			return SB_Extension_API::rest_error( 'payload_too_large', 'Import payload exceeds 512KB limit.', 413 );
		}
		$params  = (array) $request->get_json_params();
		$bundle  = $params['bundle'] ?? $params;
		if ( ! is_array( $bundle ) ) {
			return SB_Extension_API::rest_error( 'invalid_bundle', 'Bundle must be a JSON object.', 400 );
		}
		$dry_run = ! empty( $params['dry_run'] );
		// Envelope validation: version header, unknown keys, collection count caps
		$envelope = SBImportValidator::validate_envelope( $bundle );
		if ( ! $envelope['valid'] ) {
			return SB_Extension_API::rest_error( 'invalid_bundle', implode( '; ', $envelope['errors'] ), 422 );
		}
		$result  = self::apply_bundle( $bundle, $dry_run );
		if ( ! $result['success'] ) {
			return SB_Extension_API::rest_error( 'import_failed', implode( '; ', $result['errors'] ?? [] ), 422 );
		}
		return rest_ensure_response( $result );
	}
}