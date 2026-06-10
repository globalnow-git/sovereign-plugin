<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBSimulationEngine
 * Preview effects of actions before committing them — all without side effects.
 */
class SBSimulationEngine {

	/**
	 * Simulate blueprint activation.
	 *
	 * @param int   $blueprint_id
	 * @param array $options
	 * @return array
	 */
	public static function simulate_blueprint_activation( int $blueprint_id, array $options = [] ): array {
		global $wpdb;

		$blueprint = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d",
			$blueprint_id
		) );

		if ( ! $blueprint ) {
			return [ 'error' => 'Blueprint not found.', 'impact' => [] ];
		}

		$config  = (array) json_decode( $blueprint->config_json ?? '{}', true );
		$impact  = [];
		$issues  = [];

		// Check forms
		foreach ( $config['forms'] ?? [] as $form_slug ) {
			$form = $wpdb->get_row( $wpdb->prepare(
				"SELECT slug, status FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s",
				$form_slug
			) );
			$impact[] = [
				'object_type'   => 'form',
				'slug'          => $form_slug,
				'action'        => 'register',
				'current_state' => $form ? $form->status : 'missing',
				'outcome'       => $form ? 'will_activate' : 'MISSING — will fail',
			];
			if ( ! $form ) {
				$issues[] = "Form '{$form_slug}' not found in database.";
			}
		}

		// Check surfaces
		foreach ( $config['surfaces'] ?? [] as $surface_slug ) {
			$surface = $wpdb->get_row( $wpdb->prepare(
				"SELECT slug, status FROM {$wpdb->prefix}sb_ui_surfaces WHERE slug = %s",
				$surface_slug
			) );
			$impact[] = [
				'object_type'   => 'surface',
				'slug'          => $surface_slug,
				'action'        => 'mount',
				'current_state' => $surface ? $surface->status : 'missing',
				'outcome'       => $surface ? 'will_mount' : 'MISSING — will fail',
			];
			if ( ! $surface ) {
				$issues[] = "Surface '{$surface_slug}' not found in database.";
			}
		}

		// Check capabilities
		foreach ( $config['capabilities'] ?? [] as $cap ) {
			$cap_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT slug, is_active FROM {$wpdb->prefix}sb_capability_registry WHERE slug = %s",
				$cap['slug']
			) );
			$impact[] = [
				'object_type'   => 'capability',
				'slug'          => $cap['slug'],
				'action'        => 'invoke',
				'current_state' => $cap_row ? ( $cap_row->is_active ? 'active' : 'inactive' ) : 'unregistered',
				'outcome'       => $cap_row ? 'will_use_existing' : 'will_register_new',
			];
		}

		// Check roads
		foreach ( $config['roads'] ?? [] as $road ) {
			$impact[] = [
				'object_type'   => 'road',
				'slug'          => $road['road_key'],
				'action'        => 'create_if_missing',
				'current_state' => 'unknown',
				'outcome'       => 'will_create_or_skip',
			];
		}

		$risk_score  = min( 100, count( $issues ) * 20 + count( $impact ) * 2 );
		$requires_hitm = ! empty( $blueprint->config_json ) && str_contains( $blueprint->config_json, '"requires_hitm_on_activate":true' );

		$result = [
			'blueprint_id'     => $blueprint_id,
			'blueprint_slug'   => $blueprint->slug,
			'current_status'   => $blueprint->status,
			'impact'           => $impact,
			'issues'           => $issues,
			'risk_score'       => $risk_score,
			'requires_hitm'    => $requires_hitm,
			'safe_to_activate' => empty( $issues ),
		];

		self::store_run( 'blueprint_activation', [ 'blueprint_id' => $blueprint_id ], $result );

		return $result;
	}

	/**
	 * Simulate a journey execution.
	 *
	 * @param int   $scenario_id
	 * @param int   $user_id
	 * @param array $steps
	 * @return array
	 */
	public static function simulate_journey( int $scenario_id, int $user_id, array $steps = [] ): array {
		global $wpdb;

		$scenario = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_scenarios WHERE id = %d",
			$scenario_id
		) );

		if ( ! $scenario ) {
			return [ 'error' => 'Scenario not found.', 'trace' => [] ];
		}

		$trace = [];

		// Get channel actions for each road in scenario
		$blueprint = (array) json_decode( $scenario->blueprint_json ?? '{}', true );
		$road_keys = array_column( $blueprint['roads'] ?? [], 'road_key' );

		foreach ( $road_keys as $road_key ) {
			$actions = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sb_channel_actions WHERE road_key = %s AND is_active = 1 ORDER BY delay_days ASC",
				$road_key
			) );

			foreach ( $actions as $action ) {
				$trace[] = [
					'road_key'     => $road_key,
					'day'          => (int) $action->delay_days,
					'channel'      => $action->channel,
					'template_key' => $action->template_key,
					'action_type'  => $action->action_type,
					'would_fire'   => true,
					'note'         => "Would send {$action->channel} via template '{$action->template_key}' on day {$action->delay_days}",
				];
			}
		}

		$result = [
			'scenario_id' => $scenario_id,
			'user_id'     => $user_id,
			'trace'       => $trace,
			'step_count'  => count( $trace ),
		];

		self::store_run( 'journey', [ 'scenario_id' => $scenario_id, 'user_id' => $user_id ], $result );

		return $result;
	}

	/**
	 * Simulate signal firing — trace which rules would fire.
	 *
	 * @param string $signal_type
	 * @param float  $value
	 * @param int    $user_id
	 * @return array
	 */
	public static function simulate_signal( string $signal_type, float $value, int $user_id = 0 ): array {
		global $wpdb;

		$rules = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_v2_signal_rules WHERE is_active = 1",
		) );

		$would_fire = [];

		foreach ( $rules as $rule ) {
			$conditions = (array) json_decode( $rule->conditions_json ?? '[]', true );
			$matches    = false;

			foreach ( $conditions as $condition ) {
				if ( ( $condition['signal_type'] ?? '' ) === $signal_type ) {
					$op  = $condition['operator'] ?? '>=';
					$thr = (float) ( $condition['threshold'] ?? 0 );
					$matches = match( $op ) {
						'>='    => $value >= $thr,
						'<='    => $value <= $thr,
						'>'     => $value > $thr,
						'<'     => $value < $thr,
						'=='    => abs( $value - $thr ) < 0.0001,
						default => false,
					};
					if ( $matches ) {
						break;
					}
				}
			}

			if ( $matches ) {
				$would_fire[] = [
					'rule_slug'         => $rule->rule_slug,
					'action_target_type'=> $rule->action_target_type,
					'action_target_id'  => $rule->action_target_id,
					'note'              => "Rule '{$rule->rule_slug}' would fire → {$rule->action_target_type}: {$rule->action_target_id}",
				];
			}
		}

		$result = [
			'signal_type' => $signal_type,
			'value'       => $value,
			'user_id'     => $user_id,
			'would_fire'  => $would_fire,
			'rule_count'  => count( $would_fire ),
		];

		self::store_run( 'signal', [ 'signal_type' => $signal_type, 'value' => $value ], $result );

		return $result;
	}

	/**
	 * Simulate connector dispatch — dry run without sending.
	 *
	 * @param string $connector_slug
	 * @param string $event_type
	 * @param array  $payload
	 * @return array
	 */
	public static function simulate_connector( string $connector_slug, string $event_type, array $payload ): array {
		$endpoint = SB_Extension_API::get_setting( "sb_connector_{$connector_slug}_endpoint", '' );
		$has_secret = (bool) SB_Extension_API::get_setting( "sb_connector_{$connector_slug}_secret", '' );

		$body = wp_json_encode( array_merge( $payload, [ 'event_type' => $event_type ] ) );

		$result = [
			'connector_slug'   => $connector_slug,
			'event_type'       => $event_type,
			'endpoint'         => $endpoint ?: '(not configured)',
			'has_secret'       => $has_secret,
			'payload_size'     => strlen( $body ),
			'would_dispatch'   => ! empty( $endpoint ),
			'would_sign'       => $has_secret,
			'headers_preview'  => [
				'Content-Type'   => 'application/json',
				'X-SB-Signature' => $has_secret ? 'sha256=(computed)' : '(none)',
				'X-SB-Event'     => $event_type,
			],
			'body_preview'     => substr( $body, 0, 200 ),
			'note'             => $endpoint ? "Would POST to {$endpoint}" : 'No endpoint configured — dispatch would fail',
		];

		self::store_run( 'connector', [ 'connector_slug' => $connector_slug, 'event_type' => $event_type ], $result );

		return $result;
	}

	/**
	 * Simulate form submission.
	 *
	 * @param string $form_slug
	 * @param array  $data
	 * @return array
	 */
	public static function simulate_form_submission( string $form_slug, array $data ): array {
		$validation = SBTinyFormEngine::validate( $form_slug, $data );

		$form = SBTinyFormEngine::get_form( $form_slug );

		$result = [
			'form_slug'        => $form_slug,
			'validation_pass'  => $validation === true,
			'validation_errors'=> $validation !== true ? $validation : [],
			'adapter'          => $form ? $form->save_adapter : 'form_not_found',
			'would_save_to'    => $form ? $form->save_adapter : 'N/A',
			'field_count'      => count( $data ),
			'note'             => $validation === true ? 'Submission would succeed.' : 'Submission would fail validation.',
		];

		self::store_run( 'form_submission', [ 'form_slug' => $form_slug ], $result );

		return $result;
	}

	/**
	 * Simulate approval materialization.
	 *
	 * @param int $approval_id
	 * @return array
	 */
	public static function simulate_approval( int $approval_id ): array {
		global $wpdb;

		$approval = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d",
			$approval_id
		) );

		if ( ! $approval ) {
			return [ 'error' => 'Approval not found.' ];
		}

		$payload = (array) json_decode( $approval->payload, true );

		$result = [
			'approval_id'   => $approval_id,
			'type'          => $approval->approval_type,
			'status'        => $approval->status,
			'payload'       => $payload,
			'would_materialize' => SB_Approval_Engine::APPROVAL_CAP_MAP[ $approval->approval_type ] ?? 'manage_sovereign',
			'can_approve'   => current_user_can( SB_Approval_Engine::APPROVAL_CAP_MAP[ $approval->approval_type ] ?? 'manage_sovereign' ),
			'note'          => "Approving this would call SBReleaseManager::materialize({$approval_id})",
		];

		self::store_run( 'approval', [ 'approval_id' => $approval_id ], $result );

		return $result;
	}

	/**
	 * Store a simulation run.
	 *
	 * @param string $type
	 * @param array  $params
	 * @param array  $results
	 * @return int  run ID
	 */
	public static function store_run( string $type, array $params, array $results ): int {
		global $wpdb;

		$wpdb->insert( "{$wpdb->prefix}sb_sim_runs", [
			'sim_type'      => sanitize_key( $type ),
			'params_json'   => wp_json_encode( $params ),
			'results_json'  => wp_json_encode( $results ),
			'run_by'        => get_current_user_id(),
			'created_at'    => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Render simulation studio admin screen.
	 */
	public static function render_simulation_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_sim_runs' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$recent_runs = $wpdb->get_results(
			"SELECT r.*, u.user_email FROM {$wpdb->prefix}sb_sim_runs r
			 LEFT JOIN {$wpdb->users} u ON u.ID = r.run_by
			 ORDER BY r.created_at DESC LIMIT 20"
		);

		echo '<div class="wrap"><h1>Simulation Studio</h1>';
		echo '<p>Run simulations via the REST API: <code>POST /sovereign-builder/v1/sim/{type}</code></p>';
		echo '<h2>Recent Simulation Runs</h2>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Type</th><th>Run By</th><th>Created</th></tr></thead><tbody>';
		foreach ( $recent_runs as $r ) {
			echo '<tr>';
			echo '<td>' . (int) $r->id . '</td>';
			echo '<td>' . esc_html( $r->sim_type ) . '</td>';
			echo '<td>' . esc_html( $r->user_email ?? 'System' ) . '</td>';
			echo '<td>' . esc_html( $r->created_at ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $recent_runs ) ) {
			echo '<tr><td colspan="4">No simulations run yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── REST wrapper ─────────────────────────────────────────────────────────────

	public static function handle_rest_simulate( WP_REST_Request $request ): WP_REST_Response {
		$params  = (array) $request->get_json_params();
		$type    = sanitize_key( $params['type'] ?? 'blueprint' );
		$target  = absint( $params['target_id'] ?? 0 );
		$dry_run = (bool) ( $params['dry_run'] ?? true );
		$result  = match( $type ) {
			'blueprint' => self::simulate_blueprint_activation( $target, $dry_run ),
			'journey'   => self::simulate_journey( $target, $params['payload'] ?? [] ),
			'signal'    => self::simulate_signal( sanitize_key( $params['signal_type'] ?? '' ), $params['payload'] ?? [] ),
			'connector' => self::simulate_connector( $target, $params['payload'] ?? [] ),
			'form'      => self::simulate_form_submission( $target, $params['data'] ?? [] ),
			default     => [ 'error' => 'Unknown simulation type.' ],
		};
		return new WP_REST_Response( $result, 200 );
	}

}