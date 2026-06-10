<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SB_Scenario — Module 1: Blueprint Planner
 * Translates factory output into an ordered scenario blueprint with executable steps.
 */
class SB_Scenario {

	public static function init() {
		add_action( 'sb_modules_register',    [ __CLASS__, 'self_register' ] );
		add_action( 'sb_factory_run_complete',[ __CLASS__, 'on_factory_complete' ], 10, 3 );
		add_action( 'sb_approval_processed',  [ __CLASS__, 'on_approval_processed' ], 10, 3 );
		add_action( 'rest_api_init',          [ __CLASS__, 'register_routes' ] );
		add_filter( 'sb_admin_menu_items',    [ __CLASS__, 'add_menu_items' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'scenario', '1.0.0', 'SB_Scenario' );
		}
	}

	// ── Hooks ──────────────────────────────────────────────────────────────

	public static function on_factory_complete( $run_id, $outputs, $campaign_id ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		$run = $wpdb->get_row( $wpdb->prepare(
			"SELECT input_text FROM {$wpdb->prefix}sb_factory_runs WHERE id = %d", absint( $run_id )
		) );
		$idea_input = $run ? $run->input_text : '';

		// Parse road strategy from layer outputs
		$road_strategy = self::extract_road_strategy( $outputs );

		$wpdb->insert( "{$wpdb->prefix}sb_scenarios", [
			'campaign_id'    => absint( $campaign_id ),
			'factory_run_id' => absint( $run_id ),
			'title'          => 'Blueprint — Run #' . absint( $run_id ),
			'idea_input'     => sanitize_textarea_field( $idea_input ),
			'blueprint_json' => wp_json_encode( $outputs ),
			'road_strategy'  => $road_strategy,
			'status'         => 'draft',
			'created_by'     => get_current_user_id(),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		] );
		$scenario_id = $wpdb->insert_id;

		// Decompose into ordered blueprint steps
		$steps = self::decompose_blueprint( $outputs );
		foreach ( $steps as $i => $step ) {
			$wpdb->insert( "{$wpdb->prefix}sb_blueprint_steps", [
				'scenario_id' => $scenario_id,
				'step_order'  => $i + 1,
				'step_type'   => sanitize_key( $step['type'] ),
				'step_label'  => sanitize_text_field( $step['label'] ),
				'config_json' => wp_json_encode( $step['config'] ),
				'status'      => 'pending',
			] );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SCENARIO_CREATED, "Scenario created. ID: {$scenario_id}", 0, [ 'run_id' => $run_id ], 'info' );
		do_action( 'sb_scenario_created', $scenario_id, $outputs );
	}

	public static function on_approval_processed( $approval_id, $action, $campaign_id ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() || 'approved' !== $action ) { return; }
		$approval = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d", absint( $approval_id ) ) );
		if ( ! $approval ) { return; }
		$payload = json_decode( $approval->payload, true );
		if ( ! empty( $payload['scenario_id'] ) && 'scenario_approval' === ( $approval->approval_type ?? '' ) ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}sb_blueprint_steps SET status = 'complete', completed_at = NOW()
				 WHERE scenario_id = %d AND step_type = 'approval_checkpoint'",
				absint( $payload['scenario_id'] )
			) );
		}
	}

	// ── Business logic ─────────────────────────────────────────────────────

	private static function extract_road_strategy( $outputs ) {
		if ( is_array( $outputs ) ) {
			$text = implode( ' ', array_values( $outputs ) );
		} else {
			$text = (string) $outputs;
		}
		$matched = '';
		if ( preg_match( '/road[:\s]+([A-E])/i', $text, $m ) ) {
			$matched = 'Recommended road: ' . strtoupper( $m[1] );
		}
		return $matched ?: 'Review factory output to determine road assignment.';
	}

	private static function decompose_blueprint( $outputs ) {
		return [
			[ 'type' => 'road_assign',          'label' => 'Assign user to recommended road',   'config' => [ 'road' => 'A' ] ],
			[ 'type' => 'approval_checkpoint',  'label' => 'Human review of blueprint strategy','config' => [] ],
			[ 'type' => 'campaign_create',      'label' => 'Create campaign from blueprint',     'config' => [] ],
			[ 'type' => 'signal_configure',     'label' => 'Configure signals and thresholds',   'config' => [] ],
		];
	}

	public static function deploy_scenario( $scenario_id ) {
		global $wpdb;
		$scenario_id = absint( $scenario_id );
		$steps = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_blueprint_steps WHERE scenario_id = %d ORDER BY step_order ASC",
			$scenario_id
		) );
		$results = [];
		foreach ( $steps as $step ) {
			$cfg = json_decode( $step->config_json, true ) ?: [];
			switch ( $step->step_type ) {
				case 'road_assign':
					$road = sanitize_key( $cfg['road'] ?? 'A' );
					$results[] = [ 'step' => $step->id, 'type' => 'road_assign', 'road' => $road ];
					break;
				case 'campaign_create':
					$campaign_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT campaign_id FROM {$wpdb->prefix}sb_scenarios WHERE id = %d", $scenario_id
					) );
					$results[] = [ 'step' => $step->id, 'type' => 'campaign_create', 'campaign_id' => $campaign_id ];
					break;
				default:
					$results[] = [ 'step' => $step->id, 'type' => $step->step_type ];
					break;
			}
			$wpdb->update(
				"{$wpdb->prefix}sb_blueprint_steps",
				[ 'status' => 'complete', 'completed_at' => current_time( 'mysql' ) ],
				[ 'id' => $step->id ]
			);
		}
		$wpdb->update( "{$wpdb->prefix}sb_scenarios", [ 'status' => 'deployed', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $scenario_id ] );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Scenario {$scenario_id} deployed.", 0, [ 'steps' => count( $results ) ], 'info' );
		return $results;
	}

	// ── Admin screen ────────────────────────────────────────────────────────

	public static function add_menu_item( $items ) {
		$items[] = [
			'title'       => 'Scenarios',
			'menu_title'  => 'Scenarios',
			'capability'  => 'manage_sovereign_scenarios',
			'slug'        => 'sb-scenarios',
			'callback'    => [ __CLASS__, 'render_scenarios_screen' ],
		];
		return $items;
	}

	public static function render_scenarios_screen() {
		global $wpdb;
		$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		$id      = isset( $_GET['scenario_id'] ) ? absint( $_GET['scenario_id'] ) : 0;
		echo '<div class="wrap sb-wrap"><h1>Scenarios</h1>';
		if ( 'deploy' === $action && $id && check_admin_referer( 'sb_deploy_scenario_' . $id ) ) {
			$result = self::deploy_scenario( $id );
			echo '<div class="notice notice-success"><p>Scenario deployed — ' . count( $result ) . ' steps executed.</p></div>';
		}
		$scenarios = $wpdb->get_results( "SELECT s.*, COUNT(b.id) AS step_count FROM {$wpdb->prefix}sb_scenarios s LEFT JOIN {$wpdb->prefix}sb_blueprint_steps b ON b.scenario_id = s.id GROUP BY s.id ORDER BY s.id DESC" );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Title</th><th>Campaign</th><th>Steps</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>';
		if ( $scenarios ) {
			foreach ( $scenarios as $sc ) {
				$deploy_url = wp_nonce_url( admin_url( 'admin.php?page=sb-scenarios&action=deploy&id=' . $sc->id ), 'sb_deploy_scenario_' . $sc->id );
				echo '<tr>';
				echo '<td>' . $sc->id . '</td>';
				echo '<td>' . esc_html( $sc->title ) . '</td>';
				echo '<td>' . absint( $sc->campaign_id ) . '</td>';
				echo '<td>' . absint( $sc->step_count ) . '</td>';
				echo '<td><span class="sb-badge sb-badge-' . esc_attr( $sc->status ) . '">' . esc_html( $sc->status ) . '</span></td>';
				echo '<td>' . esc_html( $sc->created_at ) . '</td>';
				echo '<td>' . ( 'deployed' !== $sc->status ? '<a class="button button-primary" href="' . esc_url( $deploy_url ) . '">Deploy</a>' : '—' ) . '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="7">No scenarios yet. Run the factory to generate your first blueprint.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── REST routes ─────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( 'sovereign-builder/v1', '/scenario', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_list' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_scenarios' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/scenario/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_get' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_scenarios' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/scenario/(?P<id>\d+)/deploy', [
			'methods'             => 'POST',
			'callback'            => fn( $r ) => rest_ensure_response( self::deploy_scenario( $r['id'] ) ),
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_scenarios' ),
		] );
	}

	public static function rest_list() {
		global $wpdb;
		return rest_ensure_response( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_scenarios ORDER BY id DESC" ) );
	}

	public static function rest_get( $request ) {
		global $wpdb;
		$id       = absint( $request['id'] );
		$scenario = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_scenarios WHERE id = %d", $id ) );
		if ( ! $scenario ) { return new WP_Error( 'not_found', 'Scenario not found.', [ 'status' => 404 ] ); }
		$steps    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_blueprint_steps WHERE scenario_id = %d ORDER BY step_order", $id ) );
		return rest_ensure_response( [ 'scenario' => $scenario, 'steps' => $steps ] );
	}



	// ── Scenario Detail Screen ────────────────────────────────────────────
	public static function render_scenario_detail_screen() {
		if ( ! current_user_can( 'manage_sovereign_scenarios' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$id       = absint( $_GET['id'] ?? 0 );
		$scenario = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_scenarios WHERE id = %d", $id ) );
		if ( ! $scenario ) { wp_die( 'Scenario not found.' ); }
		$steps = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_blueprint_steps WHERE scenario_id = %d ORDER BY step_order ASC",
			$id
		) );

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Scenario #' . absint( $id ) . ': ' . esc_html( $scenario->name ?? "Scenario {$id}" ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-scenarios' ) ) . '">&larr; Back to Scenarios</a></p>';

		// Summary
		echo '<div class="sb-card" style="max-width:700px;margin-bottom:20px;">';
		echo '<table class="form-table">';
		echo '<tr><th>Status</th><td><span class="sb-badge sb-badge-' . esc_attr( $scenario->status ) . '">' . esc_html( $scenario->status ) . '</span></td></tr>';
		echo '<tr><th>Campaign</th><td>' . absint( $scenario->campaign_id ) . '</td></tr>';
		echo '<tr><th>Created</th><td>' . esc_html( $scenario->created_at ) . '</td></tr>';
		if ( $scenario->description ) {
			echo '<tr><th>Description</th><td>' . esc_html( $scenario->description ) . '</td></tr>';
		}
		echo '</table></div>';

		// Blueprint steps
		echo '<h3>Blueprint Steps</h3>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>#</th><th>Type</th><th>Label</th><th>Status</th><th>Config</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $steps ) ) {
			echo '<tr><td colspan="5"><em>No blueprint steps generated yet.</em></td></tr>';
		}
		foreach ( $steps as $s ) {
			$cfg = json_decode( $s->config_json ?? '{}', true );
			echo '<tr>';
			echo '<td>' . absint( $s->step_order ) . '</td>';
			echo '<td><code>' . esc_html( $s->step_type ) . '</code></td>';
			echo '<td>' . esc_html( $s->step_label ) . '</td>';
			echo '<td><span class="sb-badge sb-badge-' . esc_attr( $s->status ) . '">' . esc_html( $s->status ) . '</span></td>';
			echo '<td><code style="font-size:11px;">' . esc_html( json_encode( $cfg ) ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Pending approvals for this scenario
		$pending = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE approval_type = 'scenario_approval' AND campaign_id = %d AND status = 'pending'",
			$scenario->campaign_id
		) );
		if ( $pending ) {
			echo '<h3>Pending Approvals</h3>';
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			echo '<th>ID</th><th>Type</th><th>Created</th><th>Actions</th>';
			echo '</tr></thead><tbody>';
			foreach ( $pending as $ap ) {
				$detail_url = esc_url( admin_url( 'admin.php?page=sb-approval-detail&id=' . $ap->id ) );
				echo '<tr><td>' . absint( $ap->id ) . '</td><td><code>' . esc_html( $ap->approval_type ) . '</code></td>';
				echo '<td>' . esc_html( $ap->created_at ) . '</td>';
				echo '<td><a href="' . $detail_url . '" class="button button-small">Review</a></td></tr>';
			}
			echo '</tbody></table>';
		}

		// Deploy button (if ready)
		if ( 'ready' === $scenario->status || 'draft' === $scenario->status ) {
			$deploy_url = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_deploy_scenario&scenario_id=' . $id ), 'sb_deploy_scenario_' . $id ) );
			echo '<p style="margin-top:20px;"><a href="' . $deploy_url . '" class="button button-primary" onclick="return confirm(\'Deploy this scenario? This queues all steps for execution.\')">&#9654; Deploy Scenario</a></p>';
		}
		echo '</div>';
	}

	public static function rest_create_scenario( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$params = $request->get_json_params();
		$wpdb->insert( $wpdb->prefix . 'sb_scenarios', [
			'title'      => sanitize_text_field( $params['title'] ?? '' ),
			'idea_input' => sanitize_textarea_field( $params['idea_input'] ?? '' ),
			'status'     => 'draft',
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
		return rest_ensure_response( [ 'id' => $wpdb->insert_id ] );
	}

	public static function rest_update_scenario( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$id     = absint( $request['id'] );
		$params = $request->get_json_params();
		$wpdb->update(
			$wpdb->prefix . 'sb_scenarios',
			[
				'title'      => sanitize_text_field( $params['title'] ?? '' ),
				'status'     => sanitize_key( $params['status'] ?? 'draft' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
		return rest_ensure_response( [ 'updated' => true ] );
	}

	/**
	 * Register scenario admin menu items.
	 */
	public static function add_menu_items( array $items ): array {
		return $items;
	}
}

SB_Scenario::init();
