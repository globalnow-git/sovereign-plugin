<?php
/**
 * SBBlueprintSeeder — Seeds 20 marketing blueprints + 10 vertical app blueprints on activation.
 * Blueprint E3 ($1,997 Platform Sales Engine) ships as a separate importable JSON file.
 * Triggered once on plugin activation via sb_seeder_version option guard.
 *
 * @package SovereignBuilder
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBBlueprintSeeder {

	const SEEDER_VERSION = '1.1.0';
	const OPTION_KEY     = 'sb_seeder_version';

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		register_activation_hook( SB_PATH . 'sovereign-builder.php', [ __CLASS__, 'run_on_activation' ] );
		// REST endpoint to manually trigger seeding (operator tool)
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function self_register( $loader ): void {
		$loader->register( 'blueprint-seeder', '1.0.0', __CLASS__ );
	}

	public static function run_on_activation(): void {
		if ( get_option( self::OPTION_KEY ) === self::SEEDER_VERSION ) { return; }
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		self::seed_all();
	}

	public static function seed_all(): void {
		self::seed_marketing_blueprints();
		self::seed_vertical_apps();
		// Trigger edge compiler htaccess rules and telemetry defaults
		if ( class_exists( 'SBEdgeCompiler' ) ) {
			SBEdgeCompiler::write_edge_interception_rules();
		}
		// Set 2KB telemetry size limit
		update_option( 'sb_telemetry_max_payload_bytes', 2048 );
		update_option( self::OPTION_KEY, self::SEEDER_VERSION );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SEEDER_COMPLETE, '20 marketing + 10 vertical app blueprints seeded.', 0, [], 'info' );
	}

	// ── 20 Marketing Blueprints ───────────────────────────────────────────────

	public static function seed_marketing_blueprints(): void {
		$blueprints = [
			[ 'slug' => 'high-velocity-squeeze',    'name' => 'High-Velocity Squeeze Page',         'category' => 'lead-gen',   'description' => 'Single opt-in page with headline, subhead, benefit bullets, and PMPro free-tier gate.' ],
			[ 'slug' => 'quiz-matrix',               'name' => 'Quiz Matrix Funnel',                 'category' => 'engagement', 'description' => 'Multi-step quiz with conditional branching, lead capture, and segmented follow-up roads.' ],
			[ 'slug' => 'webinar-engine',            'name' => 'Webinar Registration Engine',        'category' => 'events',     'description' => 'Registration form, confirmation email, reminder sequence, and replay gate.' ],
			[ 'slug' => 'product-launch-tunnel',     'name' => 'Product Launch Tunnel',              'category' => 'launch',     'description' => 'Pre-launch squeeze, launch day sales page, cart open/close signals, and post-launch debrief sequence.' ],
			[ 'slug' => 'tripwire-funnel',           'name' => 'Tripwire Offer Funnel',              'category' => 'sales',      'description' => 'Low-friction $7-$27 front-end offer with OTO upsell road and buyer journey activation.' ],
			[ 'slug' => 'membership-onboarding',     'name' => 'Membership Onboarding Sequence',     'category' => 'retention',  'description' => 'PMPro level-gated onboarding: welcome email, quick-win milestone sequence, and 30-day check-in.' ],
			[ 'slug' => 'affiliate-recruitment',     'name' => 'Affiliate Recruitment Engine',       'category' => 'partners',   'description' => 'Application form, approval workflow, commission briefing sequence, and promo asset delivery.' ],
			[ 'slug' => 'podcast-listener-magnet',   'name' => 'Podcast Listener Lead Magnet',       'category' => 'content',    'description' => 'Episode-specific lead magnet gate, subscriber opt-in, and listener journey activation.' ],
			[ 'slug' => 'video-sales-letter',        'name' => 'Video Sales Letter (VSL) Page',      'category' => 'sales',      'description' => 'Full-width VSL container, timed CTA reveal, and cart integration signal.' ],
			[ 'slug' => 'challenge-funnel',          'name' => '5-Day Challenge Funnel',             'category' => 'engagement', 'description' => 'Day-by-day email delivery, community invite, daily task signals, and upgrade CTA on Day 5.' ],
			[ 'slug' => 'free-plus-shipping',        'name' => 'Free Plus Shipping Offer',           'category' => 'sales',      'description' => 'Physical product offer form, order bump, shipping confirmation, and post-purchase upsell road.' ],
			[ 'slug' => 'application-funnel',        'name' => 'High-Ticket Application Funnel',     'category' => 'sales',      'description' => 'Multi-step application form, conditional qualifier logic, and calendar booking integration signal.' ],
			[ 'slug' => 'book-funnel',               'name' => 'Book Funnel (Free + Shipping)',      'category' => 'lead-gen',   'description' => 'Book landing page, shipping form, reader journey, and ascension sequence to digital products.' ],
			[ 'slug' => 'summit-engine',             'name' => 'Virtual Summit Engine',              'category' => 'events',     'description' => 'Speaker registration, attendee opt-in, daily access signals, and post-summit replay upsell.' ],
			[ 'slug' => 'continuity-program',        'name' => 'Continuity Membership Program',      'category' => 'retention',  'description' => 'Monthly billing via PMPro, content drip schedule, cancellation save sequence, and reactivation road.' ],
			[ 'slug' => 'referral-engine',           'name' => 'Referral Reward Engine',             'category' => 'growth',     'description' => 'Unique referral link generation, tracking signal, tiered reward trigger, and thank-you sequence.' ],
			[ 'slug' => 'authority-content-hub',     'name' => 'Authority Content Hub',              'category' => 'content',    'description' => 'Pillar content gate, category opt-in by interest, and topic-specific nurture roads.' ],
			[ 'slug' => 'event-countdown-engine',    'name' => 'Event Countdown & Urgency Engine',   'category' => 'launch',     'description' => 'Deadline signal integration, countdown timer surface, and cart-close redirect blueprint.' ],
			[ 'slug' => 'reactivation-campaign',     'name' => 'Subscriber Reactivation Campaign',   'category' => 'retention',  'description' => 'Lapsed subscriber signal, win-back email sequence, and segmented re-opt-in form.' ],
			[ 'slug' => 'social-proof-wall',         'name' => 'Social Proof & Testimonial Wall',    'category' => 'conversion', 'description' => 'Testimonial submission form, moderation approval workflow, and public surface render.' ],
		];

		foreach ( $blueprints as $bp ) {
			self::upsert_blueprint( $bp, 'marketing' );
		}
	}

	// ── 10 Vertical App Blueprints ────────────────────────────────────────────

	public static function seed_vertical_apps(): void {
		$apps = [
			[
				'slug'        => 'enterprise-bookkeeping',
				'name'        => 'Enterprise Bookkeeping Engine',
				'category'    => 'finance',
				'description' => 'Chart of accounts form, transaction entry surface, monthly report schema view, and export blueprint.',
				'forms'       => [ 'Transaction Entry', 'Expense Claim', 'Invoice Generator' ],
				'schemas'     => [ 'Monthly P&L View', 'Transaction Ledger', 'Expense Report' ],
			],
			[
				'slug'        => 'local-crm-lead-catalog',
				'name'        => 'Local CRM & Lead Catalog',
				'category'    => 'crm',
				'description' => 'Contact intake form, lead status pipeline surface, follow-up road, and activity log schema view.',
				'forms'       => [ 'Lead Intake', 'Contact Update', 'Meeting Notes' ],
				'schemas'     => [ 'Lead Pipeline View', 'Contact Directory', 'Activity Log' ],
			],
			[
				'slug'        => 'multi-tenant-course-academy',
				'name'        => 'Multi-Tenant Course Academy',
				'category'    => 'education',
				'description' => 'PMPro-gated course enrollment, lesson progress signals, quiz form, certificate surface, and completion road.',
				'forms'       => [ 'Enrollment Form', 'Lesson Quiz', 'Course Feedback' ],
				'schemas'     => [ 'Student Progress View', 'Course Catalog', 'Completion Registry' ],
			],
			[
				'slug'        => 'scheduling-matrix',
				'name'        => 'Scheduling Matrix',
				'category'    => 'operations',
				'description' => 'Appointment request form, availability surface, confirmation road, reminder signal, and calendar schema view.',
				'forms'       => [ 'Appointment Request', 'Reschedule Form', 'Cancellation Form' ],
				'schemas'     => [ 'Calendar View', 'Appointment Queue', 'Provider Schedule' ],
			],
			[
				'slug'        => 'real-estate-pipeline',
				'name'        => 'Real Estate Deal Pipeline',
				'category'    => 'real-estate',
				'description' => 'Property intake form, deal stage surface, offer tracking schema, and closing sequence road.',
				'forms'       => [ 'Property Intake', 'Offer Submission', 'Inspection Checklist' ],
				'schemas'     => [ 'Deal Pipeline View', 'Property Registry', 'Offer Tracker' ],
			],
			[
				'slug'        => 'ecommerce-ops-dashboard',
				'name'        => 'E-Commerce Operations Dashboard',
				'category'    => 'ecommerce',
				'description' => 'Order intake signal, fulfillment status surface, returns form, and customer reorder road.',
				'forms'       => [ 'Return Request', 'Wholesale Inquiry', 'Product Review' ],
				'schemas'     => [ 'Order Queue View', 'Fulfillment Tracker', 'Returns Log' ],
			],
			[
				'slug'        => 'coaching-client-portal',
				'name'        => 'Coaching Client Portal',
				'category'    => 'coaching',
				'description' => 'Client intake form, session notes surface, goal tracking schema, and milestone celebration road.',
				'forms'       => [ 'Client Intake', 'Session Notes', 'Goal Setting Form' ],
				'schemas'     => [ 'Client Dashboard View', 'Session Log', 'Goal Tracker' ],
			],
			[
				'slug'        => 'restaurant-ops-engine',
				'name'        => 'Restaurant Operations Engine',
				'category'    => 'hospitality',
				'description' => 'Reservation form, table surface, inventory signal, staff schedule schema, and feedback road.',
				'forms'       => [ 'Reservation Form', 'Staff Availability', 'Customer Feedback' ],
				'schemas'     => [ 'Reservation Queue', 'Table Map View', 'Staff Schedule' ],
			],
			[
				'slug'        => 'nonprofit-donor-engine',
				'name'        => 'Nonprofit Donor Management Engine',
				'category'    => 'nonprofit',
				'description' => 'Donation form, donor record surface, campaign tracking schema, and thank-you sequence road.',
				'forms'       => [ 'Donation Form', 'Volunteer Sign-Up', 'Event RSVP' ],
				'schemas'     => [ 'Donor Registry View', 'Campaign Tracker', 'Volunteer Log' ],
			],
			[
				'slug'        => 'saas-onboarding-engine',
				'name'        => 'SaaS Customer Onboarding Engine',
				'category'    => 'saas',
				'description' => 'Trial activation signal, onboarding checklist surface, feature adoption road, and churn prevention sequence.',
				'forms'       => [ 'Onboarding Profile', 'Feature Feedback', 'Cancellation Survey' ],
				'schemas'     => [ 'Onboarding Progress View', 'Feature Adoption Tracker', 'Churn Risk Dashboard' ],
			],
		];

		foreach ( $apps as $app ) {
			$bp_id = self::upsert_blueprint( $app, 'vertical-app' );
			if ( $bp_id && ! empty( $app['forms'] ) ) {
				self::seed_forms_for_blueprint( $bp_id, $app['forms'] );
			}
			if ( $bp_id && ! empty( $app['schemas'] ) ) {
				self::seed_schemas_for_blueprint( $bp_id, $app['schemas'], $app['slug'] );
			}
		}
	}

	// ── Core upsert ───────────────────────────────────────────────────────────

	private static function upsert_blueprint( array $data, string $type ): int|false {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_app_blueprints';

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
			$data['slug']
		) );

		if ( $existing ) { return (int) $existing; }

		$wpdb->insert( $table, [
			'slug'           => sanitize_key( $data['slug'] ),
			'name'           => sanitize_text_field( $data['name'] ),
			'description'    => sanitize_textarea_field( $data['description'] ),
			'category'       => sanitize_key( $data['category'] ),
			'blueprint_type' => $type,
			'status'         => 'installed',
			'version'        => '1.0.0',
			'definition'     => wp_json_encode( [
				'forms'   => $data['forms'] ?? [],
				'schemas' => $data['schemas'] ?? [],
				'seeded'  => true,
			] ),
			'created_by'     => 0,
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		] );

		return $wpdb->insert_id ?: false;
	}

	private static function seed_forms_for_blueprint( int $bp_id, array $form_names ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_tiny_forms';
		foreach ( $form_names as $name ) {
			$slug = sanitize_key( str_replace( ' ', '-', strtolower( $name ) ) );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) );
			if ( $exists ) { continue; }
			$wpdb->insert( $table, [
				'blueprint_id' => $bp_id,
				'slug'         => $slug,
				'name'         => sanitize_text_field( $name ),
				'status'       => 'draft',
				'definition'   => wp_json_encode( [ 'fields' => [], 'seeded' => true ] ),
				'created_by'   => 0,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			] );
		}
	}

	private static function seed_schemas_for_blueprint( int $bp_id, array $schema_names, string $bp_slug ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sb_view_schemas';
		foreach ( $schema_names as $name ) {
			$slug = sanitize_key( str_replace( [ ' ', '&' ], [ '-', 'and' ], strtolower( $name ) ) );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) );
			if ( $exists ) { continue; }
			$wpdb->insert( $table, [
				'blueprint_id'  => $bp_id,
				'slug'          => $slug,
				'name'          => sanitize_text_field( $name ),
				'layout_type'   => 'list',
				'target_object' => $bp_slug,
				'status'        => 'draft',
				'definition'    => wp_json_encode( [ 'columns' => [], 'seeded' => true ] ),
				'created_by'    => 0,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			] );
		}
	}

	// ── REST endpoint — manual trigger ────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( 'sovereign-builder/v1', '/seed-blueprints', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_rest_seed' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
		] );
	}

	public static function handle_rest_seed( WP_REST_Request $request ): WP_REST_Response {
		// Allow re-seeding by clearing version guard
		delete_option( self::OPTION_KEY );
		self::seed_all();
		return new WP_REST_Response( [
			'success' => true,
			'message' => '20 marketing + 10 vertical app blueprints seeded.',
		], 200 );
	}
}
SBBlueprintSeeder::init();