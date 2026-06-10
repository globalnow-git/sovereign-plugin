<?php
/**
 * SB_Library_Importer — Validates and imports blueprint JSON into Sovereign Builder.
 *
 * Install: sovereign-builder/includes/class-library-importer.php
 * Bootstrap: require_once SB_PATH . 'includes/class-library-importer.php';
 *            add_action( 'init', [ 'SB_Library_Importer', 'init' ] );
 *
 * REST endpoint: POST /wp-json/sovereign-builder/v1/import-blueprint
 * WP-CLI:        wp eval "SB_Library_Importer::import_file( '/path/to/blueprint.json' );"
 *
 * @package SovereignBuilder
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SB_Library_Importer {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		// Hook: fires after Approval Engine accepts a blueprint-builder approval
		add_action( 'sb_approval_accepted', [ __CLASS__, 'on_approval_accepted' ], 10, 2 );
	}

	// ── REST Route ────────────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( 'sovereign-builder/v1', '/import-blueprint', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_rest_import' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
		] );
	}

	public static function handle_rest_import( WP_REST_Request $request ): WP_REST_Response {
		$json = $request->get_body();
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Invalid JSON.' ], 400 );
		}

		$result = self::import( $data );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => $result->get_error_message() ], 422 );
		}

		return new WP_REST_Response( [ 'success' => true, 'blueprint_id' => $result, 'report' => self::$report ], 200 );
	}

	// ── Approval Hook ─────────────────────────────────────────────────────────

	public static function on_approval_accepted( $approval_id, $approval ): void {
		// Only handle blueprint-builder approvals
		if ( ( $approval->action_type ?? '' ) !== 'import_blueprint' ) { return; }

		$json_payload = $approval->payload ?? '';
		if ( empty( $json_payload ) ) { return; }

		$data = json_decode( $json_payload, true );
		if ( ! $data ) { return; }

		$result = self::import( $data );

		SB_Event_Logger::log_audit(
			'blueprint_imported_via_approval',
			is_wp_error( $result )
				? 'Blueprint import failed: ' . $result->get_error_message()
				: 'Blueprint imported successfully. ID: ' . $result,
			0,
			[ 'approval_id' => $approval_id ],
			is_wp_error( $result ) ? 'error' : 'info'
		);
	}

	// ── WP-CLI / Direct Import ────────────────────────────────────────────────

	public static function import_file( string $path ): void {
		if ( ! file_exists( $path ) ) {
			echo "File not found: {$path}\n";
			return;
		}
		$data   = json_decode( file_get_contents( $path ), true );
		$result = self::import( $data );
		echo is_wp_error( $result )
			? 'Import failed: ' . $result->get_error_message() . "\n"
			: "Imported blueprint ID {$result}\n" . implode( "\n", self::$report ) . "\n";
	}

	// ── Core Import ───────────────────────────────────────────────────────────

	private static array $report = [];

	public static function import( array $data ): int|WP_Error {
		self::$report = [];

		// Validate structure
		$validation = self::validate( $data );
		if ( is_wp_error( $validation ) ) { return $validation; }

		global $wpdb;

		// 1. Blueprint record
		$bp_id = self::upsert_blueprint( $data['blueprint'] );
		if ( ! $bp_id ) { return new WP_Error( 'blueprint_insert_failed', 'Failed to insert blueprint record.' ); }
		self::$report[] = "Blueprint: {$data['blueprint']['name']} (ID {$bp_id})";

		// 2. Forms + fields
		foreach ( $data['forms'] ?? [] as $form ) {
			$form_id = self::upsert_form( $bp_id, $form );
			self::$report[] = $form_id
				? "Form: {$form['name']} (" . count( $form['fields'] ?? [] ) . " fields)"
				: "Form SKIPPED (duplicate): {$form['name']}";
		}

		// 3. Schemas + columns
		foreach ( $data['schemas'] ?? [] as $schema ) {
			$schema_id = self::upsert_schema( $bp_id, $data['blueprint']['slug'], $schema );
			self::$report[] = $schema_id
				? "Schema: {$schema['name']} (" . count( $schema['columns'] ?? [] ) . " columns)"
				: "Schema SKIPPED (duplicate): {$schema['name']}";
		}

		// 4. Email templates
		foreach ( $data['email_templates'] ?? [] as $tpl ) {
			$inserted = self::upsert_template( $tpl );
			self::$report[] = $inserted
				? "Template: {$tpl['template_key']}"
				: "Template SKIPPED (duplicate): {$tpl['template_key']}";
		}

		// 5. Pipeline agent + config
		if ( ! empty( $data['pipeline_agent'] ) ) {
			$agent_id = self::upsert_agent( $data['pipeline_agent'] );
			if ( $agent_id ) {
				self::upsert_pipeline_config( $data['blueprint']['slug'], $agent_id );
				self::$report[] = "Agent: {$data['pipeline_agent']['name']} → pipeline: {$data['blueprint']['slug']}";
			}
		}

		SB_Event_Logger::log_audit(
			'blueprint_imported',
			"Blueprint '{$data['blueprint']['name']}' imported. ID: {$bp_id}",
			0,
			[ 'blueprint_id' => $bp_id, 'slug' => $data['blueprint']['slug'] ],
			'info'
		);

		return $bp_id;
	}

	// ── Validation ────────────────────────────────────────────────────────────

	private static function validate( array $data ): true|WP_Error {
		if ( empty( $data['blueprint']['slug'] ) ) {
			return new WP_Error( 'missing_slug', 'blueprint.slug is required.' );
		}
		if ( empty( $data['blueprint']['name'] ) ) {
			return new WP_Error( 'missing_name', 'blueprint.name is required.' );
		}

		// Validate schema field_keys exist in forms
		$all_field_keys = [];
		foreach ( $data['forms'] ?? [] as $form ) {
			foreach ( $form['fields'] ?? [] as $field ) {
				$all_field_keys[] = $field['field_key'] ?? '';
			}
		}
		foreach ( $data['schemas'] ?? [] as $schema ) {
			foreach ( $schema['columns'] ?? [] as $col ) {
				$key = $col['field_key'] ?? '';
				if ( $key && ! in_array( $key, $all_field_keys, true ) ) {
					return new WP_Error(
						'orphaned_column',
						"Schema '{$schema['name']}' column '{$key}' has no matching form field_key."
					);
				}
			}
		}

		// Validate email bodies are complete
		foreach ( $data['email_templates'] ?? [] as $tpl ) {
			if ( empty( $tpl['body'] ) || str_contains( $tpl['body'], '[INSERT' ) ) {
				return new WP_Error( 'incomplete_template', "Template '{$tpl['template_key']}' body is incomplete." );
			}
		}

		return true;
	}

	// ── DB Writers ────────────────────────────────────────────────────────────

	private static function upsert_blueprint( array $bp ): int|false {
		global $wpdb;
		$table    = $wpdb->prefix . 'sb_app_blueprints';
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $bp['slug'] ) );

		$data = [
			'slug'        => sanitize_key( $bp['slug'] ),
			'label'       => sanitize_text_field( $bp['name'] ?? $bp['slug'] ),
			'version'     => '1.0.0',
			'config_json' => wp_json_encode( [ 'imported' => true, 'category' => $bp['category'] ?? 'general', 'blueprint_type' => $bp['blueprint_type'] ?? 'marketing', 'description' => $bp['description'] ?? '' ] ),
			'status'      => 'installed',
			'updated_at'  => current_time( 'mysql' ),
		];

		if ( $existing ) {
			$wpdb->update( $table, $data, [ 'id' => $existing ] );
			return $existing;
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return $wpdb->insert_id ?: false;
	}

	private static function upsert_form( int $bp_id, array $form ): int|false {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_tiny_forms';
		$slug  = sanitize_key( $form['slug'] ?? str_replace( ' ', '-', strtolower( $form['name'] ) ) );

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) ) ) {
			return false;
		}

		$wpdb->insert( $table, [
			'slug'        => $slug,
			'label'       => sanitize_text_field( $form['name'] ),
			'fields_json' => wp_json_encode( $form['fields'] ?? [] ),
			'status'      => 'draft',
			'created_at'  => current_time( 'mysql' ),
		] );

		return $wpdb->insert_id ?: false;
	}

	private static function upsert_schema( int $bp_id, string $bp_slug, array $schema ): int|false {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_view_schemas';
		$slug  = sanitize_key( $schema['slug'] ?? str_replace( [ ' ', '&' ], [ '-', 'and' ], strtolower( $schema['name'] ) ) );

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) ) ) {
			return false;
		}

		$wpdb->insert( $table, [
			'slug'        => $slug,
			'label'       => sanitize_text_field( $schema['name'] ),
			'schema_json' => wp_json_encode( [ 'columns' => $schema['columns'] ?? [], 'layout_type' => $schema['layout_type'] ?? 'list' ] ),
			'status'      => 'draft',
			'version'     => 1,
			'created_at'  => current_time( 'mysql' ),
		] );

		return $wpdb->insert_id ?: false;
	}

	private static function upsert_template( array $tpl ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_templates';

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE template_key = %s LIMIT 1", $tpl['template_key'] ) ) ) {
			return false;
		}

		$wpdb->insert( $table, [
			'template_key' => sanitize_key( $tpl['template_key'] ),
			'from_name'    => sanitize_text_field( $tpl['from_name'] ?? get_bloginfo( 'name' ) ),
			'from_email'   => sanitize_email( $tpl['from_email'] ?? get_option( 'admin_email' ) ),
			'subject'      => sanitize_text_field( $tpl['subject'] ),
			'content_type' => 'text/html',
			'body'         => wp_kses_post( $tpl['body'] ),
			'created_at'   => current_time( 'mysql' ),
		] );

		return (bool) $wpdb->insert_id;
	}

	private static function upsert_agent( array $agent ): int|false {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_v2_agents';

		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE agent_slug = %s LIMIT 1", $agent['slug'] ) );
		if ( $existing ) { return $existing; }

		$wpdb->insert( $table, [
			'agent_slug'         => sanitize_key( $agent['slug'] ),
			'agent_name'         => sanitize_text_field( $agent['name'] ),
			'model_routing'      => 'anthropic',
			'temperature'        => floatval( $agent['temperature'] ?? 0.5 ),
			'max_tokens'         => absint( $agent['max_tokens'] ?? 2048 ),
			'system_instruction' => sanitize_textarea_field( $agent['system_instruction'] ),
			'created_at'         => current_time( 'mysql' ),
		] );

		return $wpdb->insert_id ?: false;
	}

	private static function upsert_pipeline_config( string $pipeline_slug, int $agent_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_v2_pipeline_configs';

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE pipeline_slug = %s LIMIT 1", $pipeline_slug ) ) ) {
			return;
		}

		$wpdb->insert( $table, [
			'pipeline_slug' => sanitize_key( $pipeline_slug ),
			'step_order'    => 1,
			'agent_slug'    => sanitize_key( $pipeline_slug ),
			'step_label'    => 'Blueprint Generation',
			'is_required'   => 1,
		] );
	}
}
