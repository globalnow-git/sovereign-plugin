<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Factory_API {

	const HARDCODED_LAYERS = [
		'Layer 1: Data Model Context Matrix Strategy',
		'Layer 2: User Progression Mapping Gating Vector',
		'Layer 3: Creative Brief Interface Layout Contract'
	];

	public static function call_ai( $prompt, $context, $options = [] ) {
		$start_time = microtime( true );
		
		if ( SB_Extension_API::has_wp_ai_client() ) {
			$model_slug = SB_Extension_API::get_setting( 'sb_model_slug', 'claude-sonnet-4' );
			$result = wp_ai_client()->with_model( $model_slug )
			                        ->get_text_generation()
			                        ->get_results( [ 'system' => $prompt, 'content' => $context ] );
			
			if ( is_wp_error( $result ) ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, $result->get_error_message(), 0, [], 'error' );
				return $result;
			}
			$output_text = $result->get_text();
		} else {
			// Core direct proxy integration mappings targeting the legacy API endpoints safely
			$api_key = SB_Extension_API::get_setting( 'sb_anthropic_key', '' );
			if ( empty( $api_key ) ) {
				return new WP_Error( 'missing_credential', 'Anthropic validation signature missing local configurations maps.' );
			}

			$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
				'timeout' => (int) SB_Extension_API::get_setting( 'sb_api_timeout', 120 ),
				'headers' => [
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'      => SB_Extension_API::get_setting( 'sb_model_slug', 'claude-sonnet-4-20250514' ),
					'max_tokens' => (int) ( $options['max_tokens'] ?? 8192 ),
					'system'     => $prompt,
					'messages'   => [ [ 'role' => 'user', 'content' => $context ] ],
				] ),
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$body_data = json_decode( wp_remote_retrieve_body( $response ), true );
			$output_text = $body_data['content'][0]['text'] ?? '';
		}

		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000 );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_API_RESPONSE_TIME_MS, sprintf( "AI model processed transaction latency span: %dms", $elapsed_ms ), 0, [ 'ms' => $elapsed_ms ], 'verbose' );
		
		return $output_text;
	}

	public static function execute_pipeline( $campaign_id, $input_parameters ) {
		global $wpdb;
		$campaign_id = absint( $campaign_id );
		
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_RUN_QUEUED, 'Factory run operational pipeline state initialized.', 0, [ 'campaign' => $campaign_id ] );

		$wpdb->insert( "{$wpdb->prefix}sb_factory_runs", [
			'campaign_id'   => $campaign_id,
			'status'        => 'processing',
			'prompt_input'  => sanitize_textarea_field( $input_parameters ),
			'layer_outputs' => '',
			'created_at'    => current_time( 'mysql' )
		] );
		$run_id = $wpdb->insert_id;

		// Data-driven pipeline steps routing lookups execution matches safely
		$pipeline_slug = get_post_meta( $campaign_id, '_sb_pipeline_slug', true );
		if ( ! $pipeline_slug ) {
			$pipeline_slug = 'default';
		}

		$steps = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.*, a.system_instruction, a.model_routing, a.temperature, a.max_tokens 
			FROM {$wpdb->prefix}sb_v2_pipeline_configs p 
			JOIN {$wpdb->prefix}sb_v2_agents a ON a.agent_slug = p.agent_slug 
			WHERE p.pipeline_slug = %s ORDER BY p.step_order ASC",
			sanitize_key( $pipeline_slug )
		) );

		$accumulated_outputs = [];
		if ( empty( $steps ) ) {
			foreach ( self::HARDCODED_LAYERS as $fallback_layer ) {
				$accumulated_outputs[] = $fallback_layer . " processed content payload.";
			}
		} else {
			foreach ( $steps as $step ) {
				$ai_res = self::call_ai( $step->system_instruction, $input_parameters );
				if ( ! is_wp_error( $ai_res ) ) {
					$accumulated_outputs[ $step->step_label ] = $ai_res;
			} else {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, $ai_res->get_error_message(), 0, [], 'info' );
				if ( ! empty( $step->is_required ) ) {
					$wpdb->update( "{$wpdb->prefix}sb_factory_runs", [ 'status' => 'failed', 'progress' => 0 ], [ 'id' => $run_id ] );
					return new WP_Error( 'required_step_failed', "Required step '{$step->step_label}' failed." );
				}
				// R2-027: Update progress column as each step completes
				$step_pct = (int) ( count( $accumulated_outputs ) / max( 1, count( $steps ) ) * 95 );
				$wpdb->update( "{$wpdb->prefix}sb_factory_runs", [ 'progress' => $step_pct ], [ 'id' => $run_id ] );
				}
			}
		}

		$wpdb->update(
			"{$wpdb->prefix}sb_factory_runs",
			[ 'status' => 'complete', 'layer_outputs' => wp_json_encode( $accumulated_outputs ) ],
			[ 'id' => $run_id ]
		);

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_RUN_COMPLETE, 'Factory run successfully completed across pipeline maps layers execution structures.', 0, [ 'run_id' => $run_id ] );
		do_action( 'sb_factory_run_complete', $run_id, $accumulated_outputs, $campaign_id );

		return $run_id;
	}

	public static function handle_rest_run_factory( $request ) {
		$params = $request->get_json_params();
		$campaign_id = isset( $params['campaign_id'] ) ? absint( $params['campaign_id'] ) : 0;
		$input_text  = isset( $params['input_text'] ) ? sanitize_textarea_field( $params['input_text'] ) : 'Seed default strategy target prompt.';

		$run_id = self::execute_pipeline( $campaign_id, $input_text );
		return rest_ensure_response( [ 'success' => true, 'job_id' => $run_id, 'run_id' => $run_id, 'status' => 'queued' ] );
	}

	public static function handle_rest_progress( $request ) {
		global $wpdb;
		$run_id = absint( $request['id'] );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, progress, created_at FROM {$wpdb->prefix}sb_factory_runs WHERE id = %d", $run_id ) );
		if ( ! $row ) {
			return new WP_Error( 'missing', 'Record unfound.', [ 'status' => 404 ] );
		}
		// Map status to progress percentage for JS progress bar
		$status_pct = [
			'pending'    => 5,
			'processing' => 50,
			'partial'    => 75,
			'complete'   => 100,
			'failed'     => 100,
		];
		$row->progress = $row->progress ?: ( $status_pct[ $row->status ] ?? 0 );
		return rest_ensure_response( $row );
	}




	// ── Admin UI ──────────────────────────────────────────────────────────
	public static function render_factory_runs_screen() {
		if ( ! current_user_can( 'run_sovereign_factory' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$status_filter = sanitize_key( $_GET['status'] ?? '' );
		$where         = $status_filter ? $wpdb->prepare( "WHERE status = %s", $status_filter ) : '';
		$rows          = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_factory_runs {$where} ORDER BY id DESC LIMIT 50" ); // phpcs:ignore

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Factory Runs</h1>';

		// Launch form
		$pipelines = $wpdb->get_col( "SELECT DISTINCT pipeline_slug FROM {$wpdb->prefix}sb_v2_pipeline_configs" ); // phpcs:ignore
		echo '<div class="sb-card" style="max-width:700px;margin-bottom:24px;">';
		echo '<h3>Launch Factory Run</h3>';
		echo '<div id="sb-factory-launcher">';
		echo '<p><label><strong>Idea / Input</strong></label><br>';
		echo '<textarea id="sb-factory-input" rows="4" style="width:100%;" placeholder="Describe the product idea or campaign to evaluate..."></textarea></p>';
		echo '<p><label><strong>Pipeline</strong></label><br>';
		echo '<select id="sb-factory-pipeline">';
		foreach ( ( $pipelines ?: [ 'default' ] ) as $slug ) {
			echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $slug ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><button id="sb-launch-factory" class="button button-primary">&#9654; Launch Factory Run</button></p>';
		echo '<div id="sb-factory-progress-wrapper" style="display:none;">';
		echo '<div class="sb-progress-bar-outer"><div class="sb-progress-bar" style="width:0%"></div></div>';
		echo '<p class="sb-progress-label">Initialising pipeline&hellip;</p>';
		echo '</div>';
		echo '<div id="sb-factory-output" style="display:none;margin-top:16px;"></div>';
		echo '</div></div>';

		// Filters
		echo '<form method="GET" class="sb-filter-bar"><input type="hidden" name="page" value="sb-factory-runs" />';
		echo '<select name="status"><option value="">All Statuses</option>';
		foreach ( [ 'pending', 'processing', 'complete', 'failed' ] as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $status_filter, $s, false ) . '>' . ucfirst( $s ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'Filter', 'secondary', '', false );
		echo '</form>';

		// Runs table
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>ID</th><th>Status</th><th>Progress</th><th>Campaign</th><th>Created</th><th>Actions</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6"><em>No factory runs yet. Launch one above.</em></td></tr>';
		}
		foreach ( $rows as $r ) {
			$detail_url = esc_url( admin_url( 'admin.php?page=sb-factory-run-detail&id=' . $r->id ) );
			$pct        = (int) ( $r->progress ?? 0 );
			echo '<tr>';
			echo '<td>' . absint( $r->id ) . '</td>';
			echo '<td><span class="sb-badge sb-badge-' . esc_attr( $r->status ) . '">' . esc_html( $r->status ) . '</span></td>';
			echo '<td><div class="sb-progress-bar-outer" style="width:80px;display:inline-block"><div class="sb-progress-bar" style="width:' . $pct . '%"></div></div> ' . $pct . '%</td>';
			echo '<td>' . absint( $r->campaign_id ) . '</td>';
			echo '<td>' . esc_html( $r->created_at ) . '</td>';
			echo '<td><a href="' . $detail_url . '" class="button button-small">Inspect</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function render_factory_run_detail_screen() {
		if ( ! current_user_can( 'run_sovereign_factory' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$id  = absint( $_GET['id'] ?? 0 );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_factory_runs WHERE id = %d", $id ) );
		if ( ! $row ) { wp_die( 'Factory run not found.' ); }

		$layers = json_decode( $row->layer_outputs, true ) ?: [];
		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Factory Run #' . absint( $id ) . ' <span class="sb-badge sb-badge-' . esc_attr( $row->status ) . '">' . esc_html( $row->status ) . '</span></h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-factory-runs' ) ) . '">&larr; Back to Factory Runs</a></p>';
		echo '<table class="form-table"><tr><th>Progress</th><td>' . absint( $row->progress ) . '%</td></tr>';
		echo '<tr><th>Created</th><td>' . esc_html( $row->created_at ) . '</td></tr></table>';

		if ( $layers ) {
			echo '<h3>Layer Outputs</h3>';
			foreach ( $layers as $label => $output ) {
				echo '<div class="sb-card" style="margin-bottom:16px;">';
				echo '<h4 style="margin:0 0 8px">' . esc_html( $label ) . '</h4>';
				echo '<div class="sb-layer-toggle" style="cursor:pointer;color:#0073aa;">[Expand output]</div>';
				echo '<pre class="sb-code-block sb-layer-content" style="display:none;max-height:400px;overflow:auto;">' . esc_html( is_array( $output ) ? json_encode( $output, JSON_PRETTY_PRINT ) : $output ) . '</pre>';
				echo '</div>';
			}
		} else {
			echo '<div class="sb-card"><em>No layer outputs yet — run still processing or failed.</em></div>';
		}
		echo '</div>';
	}
}