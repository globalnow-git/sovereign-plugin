<?php
/**
 * SB_Blueprint_Builder — Seeds the Blueprint Builder blueprint.
 *
 * Install: sovereign-builder/includes/class-blueprint-builder.php
 * Bootstrap: require_once SB_PATH . 'includes/class-blueprint-builder.php';
 *            add_action( 'sb_modules_register', [ 'SB_Blueprint_Builder', 'init' ] );
 *
 * What this seeds:
 * - Blueprint record: blueprint-builder
 * - Intake form: 6 plain-English questions
 * - Pipeline agent: runs the full builder prompt
 * - Pipeline config: blueprint-builder pipeline (3 steps)
 * - Approval hook: proposal → confirm → import
 *
 * @package SovereignBuilder
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SB_Blueprint_Builder {

	const SEEDER_VERSION = '1.0.1';
	const OPTION_KEY     = 'sb_blueprint_builder_version';
	const BLUEPRINT_SLUG = 'blueprint-builder';
	const PIPELINE_SLUG  = 'blueprint-builder';

	public static function init(): void {
		if ( get_option( self::OPTION_KEY ) === self::SEEDER_VERSION ) { return; }
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		self::seed();
		// Hook: form submission triggers pipeline run
		add_action( 'sb_signal_triggered', [ __CLASS__, 'on_form_submitted' ], 10, 3 );
		// Hook: factory run complete → queue approval
		add_action( 'sb_factory_run_complete', [ __CLASS__, 'on_factory_complete' ], 10, 3 );
	}

	// ── Seed ─────────────────────────────────────────────────────────────────

	public static function seed(): void {
		global $wpdb;

		// 1. Blueprint record
		$bp_id = self::upsert_blueprint();
		if ( ! $bp_id ) { return; }

		// 2. Intake form
		self::seed_intake_form( $bp_id );

		// 3. Pipeline agent
		$agent_id = self::seed_agent();

		// 4. Pipeline config (3 steps: intake, generate, validate)
		if ( $agent_id ) {
			self::seed_pipeline( $agent_id );
		}

		update_option( self::OPTION_KEY, self::SEEDER_VERSION );

		SB_Event_Logger::log_audit(
			'blueprint_builder_seeded',
			'Blueprint Builder blueprint seeded.',
			0, [], 'info'
		);
	}

	private static function upsert_blueprint(): int|false {
		global $wpdb;
		$table    = $wpdb->prefix . 'sb_app_blueprints';
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", self::BLUEPRINT_SLUG ) );

		$data = [
			'slug'      => self::BLUEPRINT_SLUG,
			'label'     => 'Blueprint Builder',
			'version'   => self::SEEDER_VERSION,
			'config_json' => wp_json_encode( [ 'system' => true, 'category' => 'tools', 'blueprint_type' => 'system' ] ),
			'status'    => 'installed',
			'updated_at' => current_time( 'mysql' ),
		];

		if ( $existing ) {
			$wpdb->update( $table, $data, [ 'id' => $existing ] );
			return $existing;
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return $wpdb->insert_id ?: false;
	}

	private static function seed_intake_form( int $bp_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_tiny_forms';
		$slug  = 'blueprint-builder-intake';

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) ) ) {
			return;
		}

		$fields = [
			[
				'field_key'   => 'business_description',
				'label'       => 'Describe the business and what problem this system solves',
				'type'        => 'textarea',
				'required'    => true,
				'placeholder' => 'e.g. A physiotherapy clinic that needs to manage patient intake, appointments, and progress notes.',
			],
			[
				'field_key'   => 'primary_action',
				'label'       => 'What is the first thing a user does in this system?',
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'e.g. Submit a patient intake form',
			],
			[
				'field_key'   => 'data_to_capture',
				'label'       => 'What data needs to be captured? List anything that comes to mind.',
				'type'        => 'textarea',
				'required'    => true,
				'placeholder' => 'e.g. Name, date of birth, injury type, referring doctor, insurance provider, session notes',
			],
			[
				'field_key'   => 'dashboard_needs',
				'label'       => 'What does the operator need to see in their dashboard?',
				'type'        => 'textarea',
				'required'    => true,
				'placeholder' => 'e.g. Upcoming appointments, patient list, outstanding invoices',
			],
			[
				'field_key'   => 'compliance',
				'label'       => 'Any industry compliance requirements? (leave blank if none)',
				'type'        => 'text',
				'required'    => false,
				'placeholder' => 'e.g. HIPAA, GDPR, PIPEDA',
			],
			[
				'field_key'   => 'email_tone',
				'label'       => 'Tone for automated emails',
				'type'        => 'select',
				'required'    => true,
				'options'     => [ 'Professional and formal', 'Warm and conversational', 'Urgent and direct', 'Nurturing and supportive' ],
			],
		];

		$wpdb->insert( $table, [
			'slug'             => $slug,
			'label'            => 'Blueprint Intake Form',
			'status'           => 'active',
			'fields_json'      => wp_json_encode( $fields ),
			'save_adapter'     => 'submission_table',
			'save_config_json' => wp_json_encode( [ 'signal_type' => 'form_submitted' ] ),
			'created_at'       => current_time( 'mysql' ),
		] );
	}

	private static function seed_agent(): int|false {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_v2_agents';
		$slug  = 'sb-blueprint-generator';

		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE agent_slug = %s LIMIT 1", $slug ) );
		if ( $existing ) { return $existing; }

		$instruction = <<<'PROMPT'
You are a blueprint architect for Sovereign Builder, a WordPress operator platform.

A Blueprint is a complete deployable business application containing forms, schemas, email sequences, and AI pipelines. Every output must be production quality — real fields, real copy, real column definitions.

You will receive a structured intake from an operator describing their business. Your job is to generate a complete blueprint JSON in this exact structure:

{
  "blueprint": { "slug": "", "name": "", "category": "", "description": "", "blueprint_type": "vertical-app" },
  "forms": [ { "slug": "", "name": "", "fields": [ { "field_key": "", "label": "", "type": "", "required": true, "placeholder": "" } ] } ],
  "schemas": [ { "slug": "", "name": "", "layout_type": "list", "columns": [ { "field_key": "", "label": "", "type": "", "sortable": true } ] } ],
  "email_templates": [ { "template_key": "", "road": "A", "sequence": 1, "subject": "", "body": "" } ],
  "pipeline_agent": { "slug": "", "name": "", "temperature": 0.5, "max_tokens": 2048, "system_instruction": "" }
}

RULES:
- Minimum 4 fields per form, maximum 8. Types: text|email|textarea|select|date|number|checkbox|phone|url
- Schema field_keys MUST match form field_keys exactly — no orphaned columns
- Email bodies must be complete HTML — no placeholders, no [INSERT COPY HERE]
- Use tokens: {{first_name}}, {{site_url}}, {{account_url}}, {{unsubscribe_url}}
- Pipeline agent system_instruction must be specific to this blueprint type
- All slugs: lowercase, hyphens only
- Output raw JSON only — no preamble, no markdown fences, no explanation

PHASE 1 — PROPOSAL:
Before generating JSON, output a plain-English proposal summary prefixed with PROPOSAL:
List the blueprint name, forms, schemas, email approach, and agent focus.
End with: "Reply CONFIRM to generate the full blueprint JSON."

PHASE 2 — GENERATION:
When input contains CONFIRM, output the complete JSON only.

VALIDATION:
After generating, append a VALIDATION: block listing any issues found. If none, write VALIDATION: PASSED.
PROMPT;

		$wpdb->insert( $table, [
			'agent_slug'         => $slug,
			'agent_name'       => 'Blueprint Generator',
			'model_routing'      => 'anthropic',
			'temperature'        => 0.5,
			'max_tokens'         => 4096,
			'system_instruction' => $instruction,
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		] );

		return $wpdb->insert_id ?: false;
	}

	private static function seed_pipeline( int $agent_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_v2_pipeline_configs';
		$slug  = self::PIPELINE_SLUG;

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE pipeline_slug = %s LIMIT 1", $slug ) ) ) {
			return;
		}

		// Step 1: Proposal — agent generates PROPOSAL: block for human review
		$wpdb->insert( $table, [
			'pipeline_slug' => $slug,
			'step_order'    => 1,
			'agent_id'      => $agent_id,
			'step_label'    => 'Blueprint Proposal',
			'is_required'   => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		] );

		// Steps 2 + 3 (Generate + Validate) fire after approval via on_approval_accepted hook
		// They reuse the same agent with CONFIRM prepended to input
	}

	// ── Event Hooks ───────────────────────────────────────────────────────────

	/**
	 * When blueprint-builder-intake form is submitted, trigger a factory run.
	 */
	public static function on_form_submitted( string $signal_type, $value, int $user_id ): void {
		if ( $signal_type !== 'form_submitted' ) { return; }

		global $wpdb;

		// Find the blueprint-builder blueprint record directly — no campaign needed
		$bp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_app_blueprints WHERE slug = %s LIMIT 1",
			self::BLUEPRINT_SLUG
		) );
		if ( ! $bp_id ) { return; }

		// Pull latest form submission for this user and build input text
		$latest_sub = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_submissions WHERE user_id = %d ORDER BY submitted_at DESC LIMIT 1",
			$user_id
		) );
		if ( ! $latest_sub ) { return; }

		$meta_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->prefix}sb_submission_meta WHERE submission_id = %d",
			(int) $latest_sub->id
		) );

		$input_lines = [];
		foreach ( $meta_rows as $m ) {
			$input_lines[] = strtoupper( str_replace( '_', ' ', $m->meta_key ) ) . ': ' . $m->meta_value;
		}
		$input_text = implode( "\n", $input_lines );

		// Use blueprint ID as the campaign reference for the factory run
		SB_Factory_API::execute_pipeline( $bp_id, $input_text );
	}

	/**
	 * When factory run completes, check if it's a blueprint-builder run.
	 * If so, parse the PROPOSAL block and queue an approval for operator confirmation.
	 */
	public static function on_factory_complete( int $run_id, array $outputs, int $campaign_id ): void {
		global $wpdb;

		// Only handle blueprint-builder pipeline runs
		$pipeline_slug = get_post_meta( $campaign_id, '_sb_pipeline_slug', true );
		if ( $pipeline_slug !== self::PIPELINE_SLUG ) { return; }

		// Extract proposal from step 1 output
		$proposal_output = $outputs['Blueprint Proposal'] ?? array_values( $outputs )[0] ?? '';
		if ( empty( $proposal_output ) || ! str_contains( $proposal_output, 'PROPOSAL:' ) ) { return; }

		// Queue approval with full output as payload for generation step
		SB_Approval_Engine::create_approval( $campaign_id, 'import_blueprint_proposal', [
			'action_type'     => 'import_blueprint_proposal',
			'target'          => (string) $run_id,
			'rationale'       => $proposal_output,
			'expected_impact' => 'Confirms blueprint scope before JSON generation.',
			'confidence'      => 100,
			'payload'         => wp_json_encode( [
				'run_id'      => $run_id,
				'campaign_id' => $campaign_id,
				'input'       => $proposal_output,
			] ),
		] );
	}

	/**
	 * When operator approves the proposal, trigger generation run with CONFIRM.
	 * Wired via SB_Library_Importer::on_approval_accepted → action_type check.
	 */
	public static function on_proposal_approved( int $approval_id, object $approval ): void {
		if ( ( $approval->action_type ?? '' ) !== 'import_blueprint_proposal' ) { return; }

		$payload     = json_decode( $approval->payload ?? '{}', true );
		$campaign_id = absint( $payload['campaign_id'] ?? 0 );
		$prior_input = $payload['input'] ?? '';
		if ( ! $campaign_id || ! $prior_input ) { return; }

		// Re-run pipeline with CONFIRM prepended — agent generates full JSON
		$confirm_input = $prior_input . "\n\nCONFIRM";
		SB_Factory_API::execute_pipeline( $campaign_id, $confirm_input );
	}
}

// Wire proposal approval hook separately from importer
add_action( 'sb_approval_accepted', [ 'SB_Blueprint_Builder', 'on_proposal_approved' ], 10, 2 );
