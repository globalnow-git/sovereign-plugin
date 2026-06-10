<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SB_Journey — Module 2: Channel Orchestrator
 * Manages multi-channel user journeys driven by sb_channel_actions rows.
 */
class SB_Journey {

	public static function init() {
		add_action( 'sb_modules_register',    [ __CLASS__, 'self_register' ] );
		add_action( 'sb_road_entered',        [ __CLASS__, 'on_road_entered' ], 10, 3 );
		add_action( 'sb_signal_triggered',    [ __CLASS__, 'on_signal_triggered' ], 10, 3 );
		add_filter( 'sb_road_email_sequence', [ __CLASS__, 'filter_email_sequence' ], 10, 3 );
		add_action( 'sb_fire_journey_step',   [ __CLASS__, 'fire_step_by_id' ], 10, 1 );
		add_action( 'rest_api_init',          [ __CLASS__, 'register_routes' ] );
		add_filter( 'sb_admin_menu_items',    [ __CLASS__, 'add_menu_items' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'journey', '1.0.0', 'SB_Journey' );
		}
	}

	// ── Core hooks ──────────────────────────────────────────────────────────

	public static function on_road_entered( $user_id, $road_key, $previous_road ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		// Close previous active journeys
		$wpdb->update(
			"{$wpdb->prefix}sb_journeys",
			[ 'status' => 'completed', 'updated_at' => current_time( 'mysql' ) ],
			[ 'user_id' => absint( $user_id ), 'status' => 'active' ]
		);

		// Create new journey
		$wpdb->insert( "{$wpdb->prefix}sb_journeys", [
			'user_id'    => absint( $user_id ),
			'road_key'   => sanitize_key( $road_key ),
			'status'     => 'active',
			'started_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
		$journey_id = $wpdb->insert_id;

		// Upsert user ruleset row
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_user_rulesets WHERE user_id = %d LIMIT 1",
			absint( $user_id )
		) );
		if ( $existing ) {
			$wpdb->update( "{$wpdb->prefix}sb_user_rulesets",
				[ 'road_key' => sanitize_key( $road_key ), 'seq_index' => 0, 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $existing ]
			);
		} else {
			// R2-014: Resolve actual active ruleset for this user's campaign
			$active_ruleset = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ruleset_id FROM {$wpdb->prefix}sb_campaign_rulesets WHERE status = 'active' LIMIT 1"
			) );
			$wpdb->insert( "{$wpdb->prefix}sb_user_rulesets", [
				'user_id'    => absint( $user_id ),
				'ruleset_id' => $active_ruleset,
				'road_key'   => sanitize_key( $road_key ),
				'seq_index'  => 0,
				'entered_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			] );
		}

		// Create journey steps from channel actions
		$actions = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_channel_actions WHERE road_key = %s AND is_active = 1 ORDER BY delay_days ASC",
			sanitize_key( $road_key )
		) );
		foreach ( $actions as $act ) {
			$scheduled = date( 'Y-m-d H:i:s', time() + ( absint( $act->delay_days ) * DAY_IN_SECONDS ) );
			$wpdb->insert( "{$wpdb->prefix}sb_journey_steps", [
				'journey_id'   => $journey_id,
				'step_type'    => sanitize_key( $act->action_type ),
				'channel'      => sanitize_key( $act->channel ),
				'template_key' => sanitize_key( $act->template_key ),
				'payload_json' => $act->config_json,
				'status'       => 'queued',
				'scheduled_at' => $scheduled,
			] );
			// Schedule via Action Scheduler
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					strtotime( $scheduled ),
					'sb_fire_journey_step',
					[ (int) $wpdb->insert_id ]  // flat positional array for AS
				);
			}
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_ROAD_ENTERED, "Journey {$journey_id} created for user {$user_id} on road {$road_key}", $user_id, [], 'verbose' );
	}

	public static function on_signal_triggered( $signal_type, $value, $user_id ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		// R2-015: Scope signal rules to user's active journey and scenario
		$journey_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_journeys WHERE user_id = %d AND status = 'active' ORDER BY started_at DESC LIMIT 1",
			absint( $user_id )
		) );
		$active_rules = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_v2_signal_rules WHERE is_active = 1 AND (journey_id = 0 OR journey_id = %d)",
			$journey_id
		) );
		foreach ( $active_rules as $rule ) {
			$conditions    = json_decode( $rule->conditions_json, true );
			if ( ! is_array( $conditions ) ) { continue; }
			$conditions_met = 0;
			$total          = count( $conditions );

			foreach ( $conditions as $cond ) {
				$logged = (float) $wpdb->get_var( $wpdb->prepare(
					"SELECT SUM(current_value) FROM {$wpdb->prefix}sb_signals WHERE signal_type = %s AND campaign_id = (
					   SELECT campaign_id FROM {$wpdb->prefix}sb_journeys WHERE user_id = %d AND status = 'active' LIMIT 1
					 )",
					sanitize_key( $cond['signal_type'] ?? '' ),
					absint( $user_id )
				) );
				$threshold = (float) ( $cond['threshold'] ?? 0 );
				$op        = $cond['operator'] ?? '>=';
				// R2-016: Use epsilon for all float comparisons to prevent decimal drift
				$epsilon = 0.001;
				if ( '>=' === $op && ( $logged >= $threshold || abs( $logged - $threshold ) < $epsilon ) ) { $conditions_met++; }
				if ( '==' === $op && abs( $logged - $threshold ) < $epsilon ) { $conditions_met++; }
				if ( '<=' === $op && ( $logged <= $threshold || abs( $logged - $threshold ) < $epsilon ) ) { $conditions_met++; }
			}

			$fire = ( 'AND' === $rule->logical_operator && $conditions_met === $total )
			     || ( 'OR'  === $rule->logical_operator && $conditions_met > 0 );

			SB_Event_Logger::log_audit( SB_Event_Keys::EV_SIGNAL_EVALUATED, "Rule {$rule->rule_slug}: {$conditions_met}/{$total} met. Fire: " . ( $fire ? 'yes' : 'no' ), $user_id, [], 'verbose' );

			if ( $fire ) {
				self::execute_routing_action( $rule->action_target_type, $rule->action_target_id, $user_id );
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_SIGNAL_TRIGGERED, "Signal rule {$rule->rule_slug} fired action {$rule->action_target_type}", $user_id, [], 'info' );
			}
		}
	}

	public static function filter_email_sequence( $default, $road_key, $user_id ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return $default; }
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_channel_actions WHERE road_key = %s AND channel = 'email' AND is_active = 1 ORDER BY delay_days ASC",
			sanitize_key( $road_key )
		) );
		if ( empty( $rows ) ) { return $default; }
		$seq = [];
		foreach ( $rows as $r ) {
			$seq[] = [
				'delay_days'  => (int) $r->delay_days,
				'subject'     => sanitize_text_field( $r->label ),
				'template'    => sanitize_key( $r->template_key ),
				'channel'     => 'email',
			];
		}
		return [ $road_key => array_column( $seq, 'template' ) ];
	}

	// ── Routing + step execution ────────────────────────────────────────────

	public static function execute_routing_action( $type, $target_id, $user_id ) {
		switch ( $type ) {
			case 'advance_journey':
				self::advance_journey( absint( $user_id ) );
				break;
			case 'switch_road':
				SB_Many_Roads::enter_road( absint( $user_id ), sanitize_key( $target_id ) );
				break;
			case 'trigger_pipeline':
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + 5, 'sb_run_factory_pipeline', [ 'campaign_id' => absint( $target_id ), 'user_id' => absint( $user_id ) ] );
				}
				break;
		}
	}

	public static function advance_journey( $user_id ) {
		global $wpdb;
		$journey = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_journeys WHERE user_id = %d AND status = 'active' ORDER BY started_at DESC LIMIT 1",
			absint( $user_id )
		) );
		if ( ! $journey ) { return; }

		$next_step = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_journey_steps WHERE journey_id = %d AND status = 'queued' ORDER BY scheduled_at ASC LIMIT 1",
			$journey->id
		) );
		if ( $next_step ) {
			self::fire_step_by_id( $next_step->id );
		}
	}

	public static function fire_step_by_id( $step_id ) {
		global $wpdb;
		$step = $wpdb->get_row( $wpdb->prepare(
			"SELECT js.*, j.user_id FROM {$wpdb->prefix}sb_journey_steps js JOIN {$wpdb->prefix}sb_journeys j ON j.id = js.journey_id WHERE js.id = %d",
			absint( $step_id )
		) );
		if ( ! $step || 'queued' !== $step->status ) { return; }

		$user_id = absint( $step->user_id );
		$channel = $step->channel;

		switch ( $channel ) {
			case 'email':
				if ( class_exists( 'SB_Many_Roads' ) && method_exists( 'SB_Many_Roads', 'send_road_email' ) ) {
					// R2-017: $step->channel is 'email' not road_key — derive road from journey
				$road_key_from_journey = $wpdb->get_var( $wpdb->prepare(
					"SELECT road_key FROM {$wpdb->prefix}sb_journeys WHERE id = %d LIMIT 1",
					$step->journey_id
				) ) ?: '';
				SB_Many_Roads::send_road_email( $user_id, $step->template_key, $road_key_from_journey );
				}
				break;
			case 'sms':
			case 'webhook':
				$cfg      = json_decode( $step->payload_json, true ) ?: [];
				$endpoint = $cfg['endpoint'] ?? '';
				if ( $endpoint ) {
					wp_remote_post( esc_url_raw( $endpoint ), [
						'body'    => wp_json_encode( [ 'user_id' => $user_id, 'template' => $step->template_key ] ),
						'headers' => [ 'Content-Type' => 'application/json' ],
					] );
				}
				break;
			case 'dashboard_notice':
				add_user_meta( $user_id, '_sb_dashboard_notice', sanitize_text_field( $step->template_key ) );
				break;
			case 'jetpack_social':
				// GAP2 FIX: attempt Jetpack Social publish if available; fall back to audit log.
				if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && Jetpack::is_module_active( 'publicize' ) ) {
					$post_id = absint( $step->payload_json ? ( json_decode( $step->payload_json, true )['wp_post_id'] ?? 0 ) : 0 );
					if ( $post_id ) {
						update_post_meta( $post_id, '_wpas_mess', sanitize_text_field( json_decode( $step->payload_json, true )['message'] ?? '' ) );
						update_post_meta( $post_id, '_wpas_done_all', '0' );
						do_action( 'publicize_post', $post_id );
						SB_Event_Logger::log_audit( SB_Event_Keys::EV_JETPACK_SOCIAL_DISPATCHED, "Jetpack publicize triggered for post {$post_id}.", 0, [ 'post_id' => $post_id ], 'info' );
					} else {
						SB_Event_Logger::log_audit( SB_Event_Keys::EV_JETPACK_SOCIAL_SKIPPED, 'Jetpack Social step: no wp_post_id in payload.', 0, [], 'warning' );
					}
				} else {
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_JETPACK_SOCIAL_SKIPPED, 'Jetpack Social step skipped — Jetpack publicize module not active.', 0, [], 'verbose' );
				}
				break;
			default:
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_STEP_CHANNEL_UNHANDLED, "Step {$step_id} has unhandled channel: '{$channel}'.", 0, [ 'step_id' => $step_id ], 'error' );
				return; // Stay 'queued' — do not mark as sent
		}

		$wpdb->update( "{$wpdb->prefix}sb_journey_steps",
			[ 'status' => 'sent', 'fired_at' => current_time( 'mysql' ) ],
			[ 'id' => absint( $step_id ) ]
		);
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_EMAIL_QUEUED, "Journey step {$step_id} fired. Channel: {$channel}", $user_id, [], 'verbose' );
	}

	// ── Admin screens ───────────────────────────────────────────────────────

	public static function add_menu_items( $items ) {
		$items[] = [ 'title' => 'Journeys',        'menu_title' => 'Journeys',        'capability' => 'manage_sovereign_journeys', 'slug' => 'sb-journeys',        'callback' => [ __CLASS__, 'render_journeys_screen' ] ];
		$items[] = [ 'title' => 'Channel Actions', 'menu_title' => 'Channel Actions', 'capability' => 'manage_sovereign_journeys', 'slug' => 'sb-channel-actions', 'callback' => [ __CLASS__, 'render_channel_actions_screen' ] ];
		$items[] = [ 'title' => 'Agent Registry',  'menu_title' => 'Agent Registry',  'capability' => 'manage_sovereign_journeys', 'slug' => 'sb-agents',          'callback' => [ __CLASS__, 'render_agent_registry_screen' ] ];
		$items[] = [ 'title' => 'Pipeline Builder','menu_title' => 'Pipeline Builder','capability' => 'manage_sovereign_journeys', 'slug' => 'sb-pipeline',        'callback' => [ __CLASS__, 'render_pipeline_builder_screen' ] ];
		return $items;
	}

	public static function render_journeys_screen() {
		global $wpdb;
		$filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		echo '<div class="wrap sb-wrap"><h1>Journeys</h1>';
		echo '<ul class="subsubsub"><li><a href="?page=sb-journeys">All</a> | </li><li><a href="?page=sb-journeys&status=active">Active</a> | </li><li><a href="?page=sb-journeys&status=completed">Completed</a></li></ul>';
		$where = $filter ? $wpdb->prepare( "WHERE j.status = %s", $filter ) : '';
		$rows  = $wpdb->get_results( "SELECT j.*, u.user_email FROM {$wpdb->prefix}sb_journeys j LEFT JOIN {$wpdb->users} u ON u.ID = j.user_id {$where} ORDER BY j.id DESC LIMIT 100" );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Road</th><th>Status</th><th>Started</th><th>Updated</th></tr></thead><tbody>';
		if ( $rows ) {
			foreach ( $rows as $j ) {
				echo '<tr><td>' . $j->id . '</td><td>' . esc_html( $j->user_email ?: $j->user_id ) . '</td>';
				echo '<td>' . esc_html( $j->road_key ) . '</td>';
				echo '<td><span class="sb-badge sb-badge-' . esc_attr( $j->status ) . '">' . esc_html( $j->status ) . '</span></td>';
				echo '<td>' . esc_html( $j->started_at ) . '</td><td>' . esc_html( $j->updated_at ) . '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="6">No journeys found.</td></tr>';
		}
		echo '</tbody></table></div>';
	}


	// ── REST routes ─────────────────────────────────────────────────────────

	public static function register_routes() {
		$cap = fn() => current_user_can( 'manage_sovereign_journeys' );
		register_rest_route( 'sovereign-builder/v1', '/journey/(?P<user_id>\d+)', [
			'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_get_journeys' ], 'permission_callback' => $cap,
		] );
		register_rest_route( 'sovereign-builder/v1', '/journey/(?P<id>\d+)/advance', [
			'methods' => 'POST', 'callback' => fn( $r ) => rest_ensure_response( self::advance_journey( absint( $r['id'] ) ) ?: [] ), 'permission_callback' => $cap,
		] );
		register_rest_route( 'sovereign-builder/v1', '/channel-actions', [
			'methods' => 'GET', 'callback' => fn() => rest_ensure_response( ( function() { global $wpdb; return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_channel_actions ORDER BY road_key, delay_days" ); } )() ), 'permission_callback' => $cap,
		] );
		register_rest_route( 'sovereign-builder/v1', '/agents', [
			'methods' => 'GET', 'callback' => fn() => rest_ensure_response( ( function() { global $wpdb; return $wpdb->get_results( "SELECT id, agent_slug, agent_name, model_routing, temperature, max_tokens FROM {$wpdb->prefix}sb_v2_agents" ); } )() ), 'permission_callback' => $cap,
		] );
	}

	public static function rest_get_journeys( $request ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT j.*, COUNT(s.id) AS step_count FROM {$wpdb->prefix}sb_journeys j LEFT JOIN {$wpdb->prefix}sb_journey_steps s ON s.journey_id = j.id WHERE j.user_id = %d GROUP BY j.id ORDER BY j.id DESC",
			absint( $request['user_id'] )
		) );
		return rest_ensure_response( $rows );
	}



	// ── Journey Detail ────────────────────────────────────────────────────
	public static function render_journey_detail_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$id      = absint( $_GET['id'] ?? 0 );
		$journey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_journeys WHERE id = %d", $id ) );
		if ( ! $journey ) { wp_die( 'Journey not found.' ); }
		$steps = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_journey_steps WHERE journey_id = %d ORDER BY scheduled_at ASC",
			$id
		) );
		$user  = get_user_by( 'id', $journey->user_id );

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Journey #' . absint( $id ) . ' <span class="sb-badge sb-badge-' . esc_attr( $journey->status ) . '">' . esc_html( $journey->status ) . '</span></h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=sb-journeys' ) ) . '">&larr; Back to Journeys</a></p>';

		echo '<div class="sb-card" style="max-width:700px;margin-bottom:20px;">';
		echo '<table class="form-table">';
		echo '<tr><th>User</th><td>' . esc_html( $user ? $user->user_email : "User #{$journey->user_id}" ) . '</td></tr>';
		echo '<tr><th>Road</th><td><strong>' . esc_html( $journey->road_key ) . '</strong></td></tr>';
		echo '<tr><th>Started</th><td>' . esc_html( $journey->started_at ) . '</td></tr>';
		echo '<tr><th>Scenario ID</th><td>' . absint( $journey->scenario_id ) . '</td></tr>';
		echo '</table></div>';

		// Steps timeline
		echo '<h3>Journey Steps</h3>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>#</th><th>Type</th><th>Label</th><th>Status</th><th>Scheduled</th><th>Fired</th><th>Actions</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $steps ) ) {
			echo '<tr><td colspan="7"><em>No steps recorded for this journey.</em></td></tr>';
		}
		foreach ( $steps as $s ) {
			$cfg = json_decode( $s->config_json ?? '{}', true );
			echo '<tr>';
			echo '<td>' . absint( $s->step_order ) . '</td>';
			echo '<td><code>' . esc_html( $s->step_type ) . '</code></td>';
			echo '<td>' . esc_html( $s->step_label ) . '</td>';
			echo '<td><span class="sb-badge sb-badge-' . esc_attr( $s->status ) . '">' . esc_html( $s->status ) . '</span></td>';
			echo '<td>' . esc_html( $s->scheduled_at ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $s->completed_at ?? '—' ) . '</td>';
			echo '<td>';
			if ( 'queued' === $s->status ) {
				$replay_url = wp_nonce_url( admin_url( 'admin-post.php?action=sb_replay_step&step_id=' . $s->id ), 'sb_replay_step_' . $s->id );
				echo '<a href="' . esc_url( $replay_url ) . '" class="button button-small" onclick="return confirm(\'Replay this step?\')">Replay</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Agent CRUD (extended from read-only registry) ─────────────────────
	public static function render_agent_registry_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$agents  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_v2_agents ORDER BY id ASC" ); // phpcs:ignore
		$edit_id = absint( $_GET['edit'] ?? 0 );
		$editing = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_v2_agents WHERE id = %d", $edit_id ) ) : null;
		$saved   = isset( $_GET['saved'] );

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Agent Registry</h1>';
		if ( $saved ) echo '<div class="notice notice-success"><p>Agent saved.</p></div>';

		// Edit / Create form
		echo '<div class="sb-card" style="max-width:760px;margin-bottom:24px;">';
		echo '<h3>' . ( $editing ? 'Edit Agent #' . esc_html( $editing->id ) : 'New Agent' ) . '</h3>';
		echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_agent' );
		echo '<input type="hidden" name="action" value="sb_save_agent" />';
		if ( $editing ) echo '<input type="hidden" name="agent_id" value="' . absint( $editing->id ) . '" />';
		echo '<table class="form-table">';
		foreach ( [
			[ 'slug',               'Slug',               'text', $editing->slug ?? '' ],
			[ 'display_name',       'Display Name',       'text', $editing->display_name ?? '' ],
			[ 'model_slug',         'Model Slug',         'text', $editing->model_slug ?? 'claude-sonnet-4-20250514' ],
			[ 'temperature',        'Temperature',        'text', $editing->temperature ?? '0.7' ],
			[ 'max_tokens',         'Max Tokens',         'text', $editing->max_tokens ?? '2000' ],
		] as [ $key, $label, $type, $val ] ) {
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" class="regular-text" /></td></tr>';
		}
		echo '<tr><th><label for="system_instruction">System Instruction</label></th>';
		echo '<td><textarea id="system_instruction" name="system_instruction" rows="8" style="width:100%;" class="large-text">' . esc_textarea( $editing->system_instruction ?? '' ) . '</textarea></td></tr>';
		echo '</table>';
		submit_button( $editing ? 'Update Agent' : 'Create Agent' );
		if ( $editing ) echo ' <a href="' . esc_url( admin_url( 'admin.php?page=sb-agents' ) ) . '" class="button">Cancel</a>';
		echo '</form></div>';

		// Agents list
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>ID</th><th>Slug</th><th>Name</th><th>Model</th><th>Temp</th><th>Tokens</th><th>Actions</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $agents ) ) {
			echo '<tr><td colspan="7"><em>No agents registered. Create one above.</em></td></tr>';
		}
		foreach ( $agents as $a ) {
			$edit_url   = esc_url( admin_url( 'admin.php?page=sb-agents&edit=' . $a->id ) );
			$delete_url = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_delete_row&table=sb_v2_agents&id=' . $a->id ), 'sb_delete_sb_v2_agents_' . $a->id ) );
			echo '<tr>';
			echo '<td>' . absint( $a->id ) . '</td>';
			echo '<td><code>' . esc_html( $a->slug ) . '</code></td>';
			echo '<td>' . esc_html( $a->display_name ) . '</td>';
			echo '<td><code>' . esc_html( $a->model_slug ) . '</code></td>';
			echo '<td>' . esc_html( $a->temperature ) . '</td>';
			echo '<td>' . absint( $a->max_tokens ) . '</td>';
			echo '<td><a href="' . $edit_url . '" class="button button-small">Edit</a> ';
			echo '<a href="' . $delete_url . '" class="button button-small" onclick="return confirm(\'Delete this agent?\')">Delete</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Pipeline Builder (from viewer → CRUD) ─────────────────────────────
	public static function render_pipeline_builder_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$steps   = $wpdb->get_results( "SELECT p.*, a.agent_name AS agent_name FROM {$wpdb->prefix}sb_v2_pipeline_configs p LEFT JOIN {$wpdb->prefix}sb_v2_agents a ON a.agent_slug = p.agent_slug ORDER BY p.pipeline_slug ASC, p.step_order ASC" ); // phpcs:ignore
		$agents  = $wpdb->get_results( "SELECT agent_slug AS slug, agent_name AS display_name FROM {$wpdb->prefix}sb_v2_agents ORDER BY agent_name ASC" ); // phpcs:ignore
		$edit_id = absint( $_GET['edit'] ?? 0 );
		$editing = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_v2_pipeline_configs WHERE id = %d", $edit_id ) ) : null;
		$saved   = isset( $_GET['saved'] );

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Pipeline Builder</h1>';
		if ( $saved ) echo '<div class="notice notice-success"><p>Pipeline step saved.</p></div>';

		// Add / edit form
		echo '<div class="sb-card" style="max-width:760px;margin-bottom:24px;">';
		echo '<h3>' . ( $editing ? 'Edit Step #' . esc_html( $editing->id ) : 'Add Pipeline Step' ) . '</h3>';
		echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_pipeline_step' );
		echo '<input type="hidden" name="action" value="sb_save_pipeline_step" />';
		if ( $editing ) echo '<input type="hidden" name="step_id" value="' . absint( $editing->id ) . '" />';
		echo '<table class="form-table">';
		echo '<tr><th>Pipeline Slug</th><td><input type="text" name="pipeline_slug" value="' . esc_attr( $editing->pipeline_slug ?? 'default' ) . '" class="regular-text" /></td></tr>';
		echo '<tr><th>Step Order</th><td><input type="number" name="step_order" value="' . esc_attr( $editing->step_order ?? 1 ) . '" style="width:80px;" /></td></tr>';
		echo '<tr><th>Step Label</th><td><input type="text" name="step_label" value="' . esc_attr( $editing->step_label ?? '' ) . '" class="regular-text" /></td></tr>';
		echo '<tr><th>Agent</th><td><select name="agent_slug"><option value="">— none —</option>';
		foreach ( $agents as $ag ) {
			$sel = selected( $editing->agent_slug ?? '', $ag->slug, false );
			echo '<option value="' . esc_attr( $ag->slug ) . '"' . $sel . '>' . esc_html( $ag->display_name ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>Required?</th><td><label><input type="checkbox" name="is_required" value="1"' . checked( $editing->is_required ?? 1, 1, false ) . ' /> Required (stops pipeline on failure)</label></td></tr>';
		echo '<tr><th>System Instruction</th><td><textarea name="system_instruction" rows="5" style="width:100%;">' . esc_textarea( $editing->system_instruction ?? '' ) . '</textarea></td></tr>';
		echo '</table>';
		submit_button( $editing ? 'Update Step' : 'Add Step' );
		if ( $editing ) echo ' <a href="' . esc_url( admin_url( 'admin.php?page=sb-pipelines' ) ) . '" class="button">Cancel</a>';
		echo '</form></div>';

		// Steps table grouped by pipeline
		$by_pipeline = [];
		foreach ( $steps as $s ) { $by_pipeline[ $s->pipeline_slug ][] = $s; }
		foreach ( $by_pipeline as $slug => $slug_steps ) {
			echo '<h3>Pipeline: <code>' . esc_html( $slug ) . '</code></h3>';
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			echo '<th>#</th><th>Label</th><th>Agent</th><th>Required</th><th>Actions</th>';
			echo '</tr></thead><tbody>';
			foreach ( $slug_steps as $s ) {
				$edit_url   = esc_url( admin_url( 'admin.php?page=sb-pipelines&edit=' . $s->id ) );
				$delete_url = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_delete_row&table=sb_v2_pipeline_configs&id=' . $s->id ), 'sb_delete_sb_v2_pipeline_configs_' . $s->id ) );
				echo '<tr>';
				echo '<td>' . absint( $s->step_order ) . '</td>';
				echo '<td>' . esc_html( $s->step_label ) . '</td>';
				echo '<td>' . esc_html( $s->agent_name ?: $s->agent_slug ) . '</td>';
				echo '<td>' . ( $s->is_required ? '&#10003; Yes' : 'No' ) . '</td>';
				echo '<td><a href="' . $edit_url . '" class="button button-small">Edit</a> ';
				echo '<a href="' . $delete_url . '" class="button button-small" onclick="return confirm(\'Delete step?\')">Delete</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		if ( empty( $steps ) ) echo '<div class="sb-card"><em>No pipeline steps yet. Add one above.</em></div>';
		echo '</div>';
	}

	// ── Channel Actions CRUD ──────────────────────────────────────────────
	public static function render_channel_actions_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$actions = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_channel_actions ORDER BY road_key ASC, channel ASC" ); // phpcs:ignore
		$edit_id = absint( $_GET['edit'] ?? 0 );
		$editing = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_channel_actions WHERE id = %d", $edit_id ) ) : null;
		$saved   = isset( $_GET['saved'] );

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Channel Actions</h1>';
		if ( $saved ) echo '<div class="notice notice-success"><p>Channel action saved.</p></div>';

		// Add / edit form
		echo '<div class="sb-card" style="max-width:700px;margin-bottom:24px;">';
		echo '<h3>' . ( $editing ? 'Edit Action #' . absint( $editing->id ) : 'Add Channel Action' ) . '</h3>';
		echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_channel_action' );
		echo '<input type="hidden" name="action" value="sb_save_channel_action" />';
		if ( $editing ) echo '<input type="hidden" name="action_id" value="' . absint( $editing->id ) . '" />';
		echo '<table class="form-table">';
		echo '<tr><th>Road Key</th><td><input type="text" name="road_key" value="' . esc_attr( $editing->road_key ?? 'A' ) . '" style="width:60px;" maxlength="10" /></td></tr>';
		echo '<tr><th>Channel</th><td><select name="channel"><option value="email"' . selected( $editing->channel ?? 'email', 'email', false ) . '>Email</option><option value="sms"' . selected( $editing->channel ?? '', 'sms', false ) . '>SMS</option><option value="webhook"' . selected( $editing->channel ?? '', 'webhook', false ) . '>Webhook</option></select></td></tr>';
		echo '<tr><th>Template Key</th><td><input type="text" name="template_key" value="' . esc_attr( $editing->template_key ?? '' ) . '" class="regular-text" /></td></tr>';
		echo '<tr><th>Delay (minutes)</th><td><input type="number" name="delay_minutes" value="' . absint( $editing->delay_minutes ?? 0 ) . '" style="width:100px;" /></td></tr>';
		echo '<tr><th>Active</th><td><label><input type="checkbox" name="is_active" value="1"' . checked( $editing->is_active ?? 1, 1, false ) . ' /> Active</label></td></tr>';
		echo '</table>';
		submit_button( $editing ? 'Update Action' : 'Add Action' );
		if ( $editing ) echo ' <a href="' . esc_url( admin_url( 'admin.php?page=sb-channel-actions' ) ) . '" class="button">Cancel</a>';
		echo '</form></div>';

		// Actions table
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>ID</th><th>Road</th><th>Channel</th><th>Template</th><th>Delay</th><th>Active</th><th>Actions</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $actions ) ) {
			echo '<tr><td colspan="7"><em>No channel actions configured. Add one above.</em></td></tr>';
		}
		foreach ( $actions as $a ) {
			$edit_url   = esc_url( admin_url( 'admin.php?page=sb-channel-actions&edit=' . $a->id ) );
			$delete_url = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_delete_row&table=sb_channel_actions&id=' . $a->id ), 'sb_delete_sb_channel_actions_' . $a->id ) );
			echo '<tr>';
			echo '<td>' . absint( $a->id ) . '</td>';
			echo '<td><strong>' . esc_html( $a->road_key ) . '</strong></td>';
			echo '<td><code>' . esc_html( $a->channel ) . '</code></td>';
			echo '<td><code>' . esc_html( $a->template_key ) . '</code></td>';
			echo '<td>' . absint( $a->delay_minutes ) . 'm</td>';
			echo '<td>' . ( $a->is_active ? '<span style="color:green;">&#10003;</span>' : '<span style="color:#999;">&#10007;</span>' ) . '</td>';
			echo '<td><a href="' . $edit_url . '" class="button button-small">Edit</a> ';
			echo '<a href="' . $delete_url . '" class="button button-small" onclick="return confirm(\'Delete?\')">Delete</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}

SB_Journey::init();