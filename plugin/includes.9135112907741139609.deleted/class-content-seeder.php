<?php
/**
 * SB_Content_Seeder — Seeds email templates, pipeline agents, and pipeline step configs.
 *
 * DROP THIS FILE INTO: sovereign-builder/includes/class-content-seeder.php
 *
 * Then add to the main plugin bootstrap (sovereign-builder.php) after other includes:
 *   require_once SB_PATH . 'includes/class-content-seeder.php';
 *   add_action( 'sb_modules_register', [ 'SB_Content_Seeder', 'init' ] );
 *
 * Or trigger manually via WP-CLI:
 *   wp eval "SB_Content_Seeder::seed_all();"
 *
 * @package SovereignBuilder
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SB_Content_Seeder {

	const SEEDER_VERSION = '1.0.0';
	const OPTION_KEY     = 'sb_content_seeder_version';

	public static function init(): void {
		if ( get_option( self::OPTION_KEY ) === self::SEEDER_VERSION ) { return; }
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		self::seed_all();
	}

	public static function seed_all(): void {
		self::seed_email_templates();
		self::seed_pipeline_agents();
		self::seed_pipeline_configs();
		update_option( self::OPTION_KEY, self::SEEDER_VERSION );
		if ( class_exists( 'SB_Event_Logger' ) && class_exists( 'SB_Event_Keys' ) ) {
			SB_Event_Logger::log_audit(
				'content_seeder_complete',
				'Email templates, pipeline agents, and pipeline configs seeded.',
				0, [], 'info'
			);
		}
	}

	// ── EMAIL TEMPLATES ───────────────────────────────────────────────────────
	// These populate sb_templates so Many Roads can deliver road sequences.
	// Road A = Welcome / Onboarding
	// Road B = Upsell Path
	// Road C = Deadline / Close

	private static function seed_email_templates(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_templates';

		$templates = [

			// ── Road A: Welcome Sequence ──────────────────────────────────────

			[
				'template_key' => 'welcome_sequence_1',
				'from_name'    => get_option( 'sb_from_name', get_bloginfo( 'name' ) ),
				'from_email'   => get_option( 'sb_from_email', get_option( 'admin_email' ) ),
				'subject'      => 'You\'re in, {{first_name}} — here\'s where to start',
				'content_type' => 'text/html',
				'body'         => '
<p>Hi {{first_name}},</p>

<p>Welcome. You made a good decision.</p>

<p>A lot of people talk about getting results. You\'re the kind of person who takes the step. That matters — and it\'s exactly why what you\'ve just joined works for people like you.</p>

<p>Here\'s what happens next:</p>

<p><strong>Step 1 — Get your bearings.</strong><br>
Head to your account dashboard: <a href="{{account_url}}">{{account_url}}</a><br>
Everything you need is organized and waiting.</p>

<p><strong>Step 2 — Start with the foundation.</strong><br>
Don\'t skip ahead. The people who get results fastest are the ones who build on solid ground first. Your next email will show you exactly where that is.</p>

<p><strong>Step 3 — Reply to this email.</strong><br>
Tell me one thing: what\'s the single biggest outcome you\'re hoping for? I read every reply. It helps me make sure you get what you came for.</p>

<p>Talk soon,<br>
The Team at {{site_url}}</p>

<p style="font-size:11px;color:#999;">
You\'re receiving this because you joined us at {{site_url}}.<br>
<a href="{{unsubscribe_url}}">Unsubscribe</a>
</p>
				',
			],

			[
				'template_key' => 'welcome_sequence_2',
				'from_name'    => get_option( 'sb_from_name', get_bloginfo( 'name' ) ),
				'from_email'   => get_option( 'sb_from_email', get_option( 'admin_email' ) ),
				'subject'      => '{{first_name}}, the one thing most people miss',
				'content_type' => 'text/html',
				'body'         => '
<p>Hi {{first_name}},</p>

<p>Most people who join something like this make the same mistake.</p>

<p>They consume everything — they read, they watch, they take notes — but they don\'t act until they feel "ready." And ready never comes.</p>

<p>The ones who actually get results? They pick one thing and move on it within 48 hours of getting access.</p>

<p><strong>Your one thing right now:</strong></p>

<p>Log into your dashboard at <a href="{{account_url}}">{{account_url}}</a> and complete the setup that\'s waiting for you. It takes less than 10 minutes. Don\'t skip it — it\'s the foundation everything else builds on.</p>

<p>Once that\'s done, your next step becomes obvious. That\'s by design.</p>

<p>Questions? Hit reply. I\'m here.</p>

<p>Talk soon,<br>
The Team at {{site_url}}</p>

<p style="font-size:11px;color:#999;">
<a href="{{unsubscribe_url}}">Unsubscribe</a>
</p>
				',
			],

			// ── Road B: Upsell Path ───────────────────────────────────────────

			[
				'template_key' => 'upsell_path_sequence_1',
				'from_name'    => get_option( 'sb_from_name', get_bloginfo( 'name' ) ),
				'from_email'   => get_option( 'sb_from_email', get_option( 'admin_email' ) ),
				'subject'      => '{{first_name}}, you\'re getting results — here\'s the next level',
				'content_type' => 'text/html',
				'body'         => '
<p>Hi {{first_name}},</p>

<p>You\'ve been in the system long enough that I want to have a direct conversation with you.</p>

<p>You\'re doing the work. You\'re showing up. And based on what I can see, you\'re starting to get traction.</p>

<p>Which means you\'re probably bumping into the next set of problems — the ones that only show up once you\'ve actually made progress. That\'s a good sign. It means you\'ve outgrown where you started.</p>

<p>There\'s a next level designed specifically for where you are right now. It\'s not for beginners — it\'s for people who\'ve proven they can execute and are ready to go faster.</p>

<p><strong>Take a look here:</strong> <a href="{{factory_url}}">{{factory_url}}</a></p>

<p>I\'m not going to oversell it. Either it\'s right for you right now or it\'s not. If you have questions, reply to this email and let\'s talk through it.</p>

<p>Talk soon,<br>
The Team at {{site_url}}</p>

<p style="font-size:11px;color:#999;">
<a href="{{unsubscribe_url}}">Unsubscribe</a>
</p>
				',
			],

			[
				'template_key' => 'upsell_path_sequence_2',
				'from_name'    => get_option( 'sb_from_name', get_bloginfo( 'name' ) ),
				'from_email'   => get_option( 'sb_from_email', get_option( 'admin_email' ) ),
				'subject'      => 'A question for you, {{first_name}}',
				'content_type' => 'text/html',
				'body'         => '
<p>Hi {{first_name}},</p>

<p>Quick question — and I\'d genuinely like to know the answer.</p>

<p>What\'s the gap between where you are right now and where you want to be?</p>

<p>Not a big philosophical answer. Just: what\'s the one thing that if it were solved, would make the biggest difference in the next 90 days?</p>

<p>Reply and tell me. I read every one.</p>

<p>I ask because I want to make sure what we\'re offering next is actually the right fit for you — and that means knowing where you\'re stuck.</p>

<p>When you reply, I\'ll respond with something specific. Not a sales pitch. A real answer.</p>

<p>Talk soon,<br>
The Team at {{site_url}}</p>

<p style="font-size:11px;color:#999;">
<a href="{{unsubscribe_url}}">Unsubscribe</a>
</p>
				',
			],

			// ── Road C: Deadline / Close ──────────────────────────────────────

			[
				'template_key' => 'deadline_close_sequence_1',
				'from_name'    => get_option( 'sb_from_name', get_bloginfo( 'name' ) ),
				'from_email'   => get_option( 'sb_from_email', get_option( 'admin_email' ) ),
				'subject'      => '{{first_name}}, this closes tonight',
				'content_type' => 'text/html',
				'body'         => '
<p>Hi {{first_name}},</p>

<p>I\'ll keep this short because the deadline is real and I don\'t want you to miss it.</p>

<p>Tonight at midnight, the current offer closes. After that, it either goes away entirely or the price changes significantly — I\'m not going to dress that up.</p>

<p>If you\'ve been thinking about it, now is the time to decide.</p>

<p><strong><a href="{{factory_url}}">Get access before tonight\'s deadline →</a></strong></p>

<p>If it\'s not right for you, no problem. I\'d rather you make the right decision than a fast one. But if you\'ve been sitting on the fence, the fence goes away at midnight.</p>

<p>Questions? Reply now. I\'m monitoring this inbox today specifically for that reason.</p>

<p>Talk soon,<br>
The Team at {{site_url}}</p>

<p style="font-size:11px;color:#999;">
<a href="{{unsubscribe_url}}">Unsubscribe</a>
</p>
				',
			],

		];

		foreach ( $templates as $tpl ) {
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE template_key = %s LIMIT 1",
				$tpl['template_key']
			) );
			if ( $existing ) { continue; }
			$wpdb->insert( $table, [
				'template_key' => sanitize_key( $tpl['template_key'] ),
				'from_name'    => sanitize_text_field( $tpl['from_name'] ),
				'from_email'   => sanitize_email( $tpl['from_email'] ),
				'subject'      => sanitize_text_field( $tpl['subject'] ),
				'content_type' => 'text/html',
				'body'         => wp_kses_post( $tpl['body'] ),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			] );
		}
	}

	// ── PIPELINE AGENTS ───────────────────────────────────────────────────────
	// Three agents: Strategist, Copywriter, Optimizer.
	// Each has a system prompt that shapes how Claude responds at that pipeline step.

	private static function seed_pipeline_agents(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_v2_agents';

		$agents = [

			[
				'slug'        => 'sb-strategist',
				'name'        => 'Campaign Strategist',
				'routing'     => 'anthropic',
				'temperature' => 0.4,
				'max_tokens'  => 2048,
				'instruction' => 'You are a direct response marketing strategist with deep knowledge of proven funnel frameworks including value ladders, product launches, tripwire offers, and high-ticket application funnels.

Your role is to analyze the campaign brief provided and produce a clear strategic framework including:
1. The primary audience and their most acute pain point
2. The core promise and proof mechanism
3. The recommended funnel type and rationale
4. The key conversion milestones (micro-yes sequence)
5. The primary objections to address in copy

Be specific and direct. Avoid generic marketing language. Output structured text with clear section headers. Do not write copy — that is the next agent\'s job. Your output becomes the brief the Copywriter works from.',
			],

			[
				'slug'        => 'sb-copywriter',
				'name'        => 'Direct Response Copywriter',
				'routing'     => 'anthropic',
				'temperature' => 0.7,
				'max_tokens'  => 4096,
				'instruction' => 'You are a direct response copywriter trained in the traditions of Gary Halbert, Dan Kennedy, and Eugene Schwartz. You write copy that converts because it speaks to the reader\'s real desires and real fears — not corporate language, not hype.

You will receive a strategic brief from the Campaign Strategist. Your job is to produce:
1. A headline (and 3 alternatives)
2. An opening hook paragraph
3. The core body copy with story, proof, and offer
4. A clear call to action
5. A P.S. line

Write in a direct, conversational voice. Short sentences. Active verbs. Specific details over vague claims. The reader should feel like you are talking to them personally, not broadcasting to a crowd.

Use the token {{first_name}} where personalization would increase response. Format output clearly with section labels.',
			],

			[
				'slug'        => 'sb-optimizer',
				'name'        => 'Conversion Optimizer',
				'routing'     => 'anthropic',
				'temperature' => 0.3,
				'max_tokens'  => 1024,
				'instruction' => 'You are a conversion rate optimization specialist. You will receive copy produced by a direct response copywriter and a strategic brief.

Your job is to review the output and produce:
1. A conversion score (1-10) with brief rationale
2. The top 3 specific improvements ranked by likely impact
3. A revised headline if the current one can be improved
4. Any missing elements (guarantee, urgency, social proof, etc.)

Be specific. Say exactly what to change and why. Reference direct response principles when relevant. Your output goes directly to the operator — make it actionable, not theoretical.',
			],

		];

		foreach ( $agents as $agent ) {
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE agent_slug = %s LIMIT 1",
				$agent['slug']
			) );
			if ( $existing ) { continue; }
			$wpdb->insert( $table, [
				'agent_slug'        => sanitize_key( $agent['slug'] ),
				'agent_name'        => sanitize_text_field( $agent['name'] ),
				'model_routing'     => sanitize_key( $agent['routing'] ),
				'temperature'       => floatval( $agent['temperature'] ),
				'max_tokens'        => absint( $agent['max_tokens'] ),
				'system_instruction'=> sanitize_textarea_field( $agent['instruction'] ),
				'created_at'        => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			] );
		}
	}

	// ── PIPELINE CONFIGS ──────────────────────────────────────────────────────
	// Wires the three agents into the 'default' pipeline in correct step order.

	private static function seed_pipeline_configs(): void {
		global $wpdb;
		$agents_table   = $wpdb->prefix . 'sb_v2_agents';
		$pipeline_table = $wpdb->prefix . 'sb_v2_pipeline_configs';

		$steps = [
			[ 'slug' => 'sb-strategist',  'label' => 'Strategy Brief',        'order' => 1, 'required' => 1 ],
			[ 'slug' => 'sb-copywriter',  'label' => 'Copy Generation',        'order' => 2, 'required' => 1 ],
			[ 'slug' => 'sb-optimizer',   'label' => 'Conversion Review',      'order' => 3, 'required' => 0 ],
		];

		foreach ( $steps as $step ) {
			$agent_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$agents_table} WHERE agent_slug = %s LIMIT 1",
				$step['slug']
			) );
			if ( ! $agent_id ) { continue; }

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$pipeline_table} WHERE pipeline_slug = 'default' AND step_order = %d LIMIT 1",
				$step['order']
			) );
			if ( $existing ) { continue; }

			$wpdb->insert( $pipeline_table, [
				'pipeline_slug' => 'default',
				'step_order'    => $step['order'],
				'agent_slug'    => sanitize_key( $step['slug'] ),
				'step_label'    => sanitize_text_field( $step['label'] ),
				'is_required'   => $step['required'],
			] );
		}
	}
}
