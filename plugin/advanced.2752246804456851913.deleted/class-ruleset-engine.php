<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SB_Ruleset_Engine — Module 3: Adaptive Switching + Ruleset Generator
 */
class SB_Ruleset_Engine {

	public static function init() {
		add_action( 'sb_modules_register',    [ __CLASS__, 'self_register' ] );
		add_action( 'sb_factory_run_complete',[ __CLASS__, 'on_factory_complete' ], 20, 3 );
		add_action( 'sb_check_signals_cron',  [ __CLASS__, 'evaluate_performance_thresholds' ] );
		add_action( 'rest_api_init',          [ __CLASS__, 'register_routes' ] );
		add_filter( 'sb_admin_menu_items',    [ __CLASS__, 'add_menu_items' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'ruleset-engine', '1.0.0', 'SB_Ruleset_Engine' );
		}
	}

	// ── Hooks ──────────────────────────────────────────────────────────────

	public static function on_factory_complete( $run_id, $outputs, $campaign_id ) {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		$prompt_id = (int) get_post_meta( $campaign_id, '_sb_ruleset_prompt_id', true );
		if ( ! $prompt_id ) { return; }

		$slug = 'ruleset-' . absint( $run_id ) . '-' . time();
		$wpdb->insert( "{$wpdb->prefix}sb_rulesets", [
			'slug'             => $slug,
			'name'             => 'Generated Ruleset — Run #' . absint( $run_id ),
			'version'          => '1.0.0',
			'source_prompt_id' => $prompt_id,
			'config_json'      => is_array( $outputs ) ? wp_json_encode( $outputs ) : $outputs,
			'domain_key'       => defined( 'SB_ACTIVE_DOMAIN' ) ? SB_ACTIVE_DOMAIN : '',
			'status'           => 'draft',
			'created_at'       => current_time( 'mysql' ),
		] );
		$ruleset_id = $wpdb->insert_id;

		// Decompose into sb_ruleset_items
		if ( is_array( $outputs ) ) {
			foreach ( $outputs as $layer_key => $content ) {
				$wpdb->insert( "{$wpdb->prefix}sb_ruleset_items", [
					'ruleset_id'  => $ruleset_id,
					'item_type'   => 'pipeline_layer',
					'item_key'    => sanitize_key( $layer_key ),
					'config_json' => wp_json_encode( [ 'content' => $content ] ),
					'status'      => 'pending',
				] );
			}
		}

		do_action( 'sb_ruleset_generated', $ruleset_id, $outputs, $prompt_id );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_JOB_QUEUED, "Ruleset {$ruleset_id} generated from factory run {$run_id}.", 0, [], 'info' );
	}

	public static function evaluate_performance_thresholds() {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		$thresholds = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_performance_thresholds WHERE is_active = 1" );
		foreach ( $thresholds as $th ) {
			$actual = self::measure_metric( $th->metric_type, $th->campaign_id, $th->window_hours );
			if ( $actual < (float) $th->expected_value && $th->fallback_ruleset_id ) {
				// R2-022: skip if already on fallback
				$cur_rs = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ruleset_id FROM {$wpdb->prefix}sb_campaign_rulesets WHERE campaign_id = %d AND status = 'active' LIMIT 1",
					$th->campaign_id ) );
				if ( $cur_rs === (int) $th->fallback_ruleset_id ) { continue; } // already on fallback
				$vetoed = apply_filters( 'sb_pre_ruleset_switch', $th->fallback_ruleset_id, $th->campaign_id );
				if ( $vetoed ) { continue; }
				$from = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ruleset_id FROM {$wpdb->prefix}sb_campaign_rulesets WHERE campaign_id = %d AND status = 'active' LIMIT 1",
					$th->campaign_id
				) );
				self::apply_ruleset( $th->fallback_ruleset_id, $th->campaign_id );
				$wpdb->insert( "{$wpdb->prefix}sb_ruleset_switches", [
					'campaign_id'     => absint( $th->campaign_id ),
					'from_ruleset_id' => $from,
					'to_ruleset_id'   => absint( $th->fallback_ruleset_id ),
					'trigger_metric'  => sanitize_key( $th->metric_type ),
					'trigger_value'   => (float) $actual,
					'switched_at'     => current_time( 'mysql' ),
				] );
				do_action( 'sb_ruleset_switched', $th->campaign_id, $from, $th->fallback_ruleset_id );
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_RULESET_SWITCHED, "Campaign {$th->campaign_id}: switched from ruleset {$from} to {$th->fallback_ruleset_id}. Metric {$th->metric_type}: actual={$actual} expected={$th->expected_value}", 0, [], 'info' );
			}
		}
	}

	// ── Business logic ──────────────────────────────────────────────────────

	private static function measure_metric( $metric_type, $campaign_id, $window_hours ) {
		global $wpdb;
		$since = date( 'Y-m-d H:i:s', time() - ( absint( $window_hours ) * HOUR_IN_SECONDS ) );
		switch ( $metric_type ) {
			case 'signal_velocity':
				return (float) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}sb_funnel_events WHERE campaign_id = %d AND occurred_at >= %s",
					absint( $campaign_id ), $since
				) );
			case 'email_open_rate':
				$sent   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'sent' AND occurred_at >= %s", absint( $campaign_id ), $since ) );
				$opened = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_email_events WHERE campaign_id = %d AND event_type = 'open' AND occurred_at >= %s", absint( $campaign_id ), $since ) );
				return $sent > 0 ? round( $opened / $sent, 4 ) : 0.0;
			default:
				return 0.0;
		}
	}

	public static function apply_ruleset( $ruleset_id, $campaign_id ) {
		global $wpdb;
		$ruleset_id  = absint( $ruleset_id );
		$campaign_id = absint( $campaign_id );

		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_ruleset_items WHERE ruleset_id = %d", $ruleset_id
		) );

		foreach ( $items as $item ) {
			$cfg = json_decode( $item->config_json, true ) ?: [];
			switch ( $item->item_type ) {
				case 'email':
					$existing = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}sb_channel_actions WHERE road_key = %s AND template_key = %s",
						sanitize_key( $cfg['road_key'] ?? '' ), sanitize_key( $item->item_key )
					) );
					if ( $existing ) {
						$wpdb->update( "{$wpdb->prefix}sb_channel_actions", [ 'label' => sanitize_text_field( $cfg['label'] ?? '' ), 'delay_days' => absint( $cfg['delay_days'] ?? 0 ) ], [ 'id' => $existing ] );
					} else {
						$wpdb->insert( "{$wpdb->prefix}sb_channel_actions", [
							'road_key'     => sanitize_key( $cfg['road_key'] ?? 'A' ),
							'channel'      => 'email',
							'template_key' => sanitize_key( $item->item_key ),
							'label'        => sanitize_text_field( $cfg['label'] ?? $item->item_key ),
							'delay_days'   => absint( $cfg['delay_days'] ?? 0 ),
							'is_active'    => 1,
						] );
					}
					break;
				case 'token':
					$wpdb->query( $wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}sb_design_tokens (campaign_id, token_key, token_value, token_group) VALUES (%d, %s, %s, 'ruleset')
						 ON DUPLICATE KEY UPDATE token_value = VALUES(token_value)",
						$campaign_id, sanitize_key( $item->item_key ), sanitize_text_field( $cfg['value'] ?? '' )
					) );
					break;
				case 'pipeline':
					// upsert pipeline step — never overwrite core LAYERS
					if ( ! empty( $cfg['pipeline_slug'] ) && 'default' !== $cfg['pipeline_slug'] ) {
						$wpdb->insert( "{$wpdb->prefix}sb_v2_pipeline_configs", [
							'pipeline_slug' => sanitize_key( $cfg['pipeline_slug'] ),
							'step_order'    => absint( $cfg['step_order'] ?? 0 ),
							'agent_id'      => absint( $cfg['agent_id'] ?? 0 ),
							'step_label'    => sanitize_text_field( $cfg['label'] ?? '' ),
							'is_required'   => 1,
						] );
					}
					break;
			}
			$wpdb->update( "{$wpdb->prefix}sb_ruleset_items",
				[ 'status' => 'applied', 'applied_at' => current_time( 'mysql' ) ],
				[ 'id' => $item->id ]
			);
		}

		// Deactivate current campaign ruleset, activate new one
		$wpdb->update( "{$wpdb->prefix}sb_campaign_rulesets", [ 'status' => 'inactive' ], [ 'campaign_id' => $campaign_id ] );
		$wpdb->insert( "{$wpdb->prefix}sb_campaign_rulesets", [
			'campaign_id'      => $campaign_id,
			'ruleset_id'       => $ruleset_id,
			'priority'         => 10,
			'overlap_strategy' => 'priority',
			'status'           => 'active',
			'activated_at'     => current_time( 'mysql' ),
		] );

		$wpdb->update( "{$wpdb->prefix}sb_rulesets", [ 'status' => 'active' ], [ 'id' => $ruleset_id ] );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_RULESET_SWITCHED, "Ruleset {$ruleset_id} applied to campaign {$campaign_id}.", 0, [], 'info' );
	}

	public static function diff_ruleset( $ruleset_id, $campaign_id ) {
		global $wpdb;
		$items   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_ruleset_items WHERE ruleset_id = %d", absint( $ruleset_id ) ) );
		$added   = [];
		$changed = [];
		$same    = [];
		foreach ( $items as $item ) {
			$cfg = json_decode( $item->config_json, true ) ?: [];
			if ( 'email' === $item->item_type ) {
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}sb_channel_actions WHERE template_key = %s",
					sanitize_key( $item->item_key )
				) );
				if ( ! $existing ) { $added[]   = $item->item_key; }
				elseif ( (int) $existing->delay_days !== (int) ( $cfg['delay_days'] ?? 0 ) ) { $changed[] = $item->item_key; }
				else { $same[] = $item->item_key; }
			}
		}
		return [ 'added' => $added, 'changed' => $changed, 'unchanged' => $same ];
	}

	// ── Admin screens ───────────────────────────────────────────────────────

	public static function add_menu_items( $items ) {
		$items[] = [ 'title' => 'Rulesets', 'menu_title' => 'Rulesets', 'capability' => 'manage_sovereign_rulesets', 'slug' => 'sb-rulesets', 'callback' => [ __CLASS__, 'render_rulesets_screen' ] ];
		return $items;
	}

	public static function render_rulesets_screen() {
		global $wpdb;
		echo '<div class="wrap sb-wrap"><h1>Rulesets</h1>';

		// Action: apply
		if ( isset( $_GET['action'], $_GET['id'] ) && 'apply' === $_GET['action'] ) {
			check_admin_referer( 'sb_apply_ruleset_' . absint( $_GET['id'] ) );
			$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
			self::apply_ruleset( absint( $_GET['id'] ), $campaign_id );
			echo '<div class="notice notice-success"><p>Ruleset applied.</p></div>';
		}

		// Ruleset prompts
		echo '<h2>Ruleset Prompts</h2>';
		$prompts = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_ruleset_prompts ORDER BY persona_slug" );
		if ( $prompts ) {
			echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Category</th><th>Version</th><th>Last run</th></tr></thead><tbody>';
			foreach ( $prompts as $p ) {
				echo '<tr><td><code>' . esc_html( $p->slug ) . '</code></td><td>' . esc_html( $p->category ) . '</td><td>' . esc_html( $p->version ) . '</td><td>' . absint( $p->last_run_id ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No ruleset prompts yet. Prompts are added via the factory pipeline.</p>';
		}

		// Generated rulesets
		echo '<h2>Generated Rulesets</h2>';
		$rulesets = $wpdb->get_results( "SELECT r.*, COUNT(i.id) AS item_count FROM {$wpdb->prefix}sb_rulesets r LEFT JOIN {$wpdb->prefix}sb_ruleset_items i ON i.ruleset_id = r.id GROUP BY r.id ORDER BY r.id DESC" );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Items</th><th>Domain</th><th>Action</th></tr></thead><tbody>';
		if ( $rulesets ) {
			foreach ( $rulesets as $r ) {
				$apply_url = wp_nonce_url( admin_url( 'admin.php?page=sb-rulesets&action=apply&id=' . $r->id . '&campaign_id=1' ), 'sb_apply_ruleset_' . $r->id );
				echo '<tr><td>' . $r->id . '</td><td>' . esc_html( $r->name ) . '</td>';
				echo '<td><span class="sb-badge sb-badge-' . esc_attr( $r->status ) . '">' . esc_html( $r->status ) . '</span></td>';
				echo '<td>' . absint( $r->item_count ) . '</td><td>' . esc_html( $r->domain_key ?: '—' ) . '</td>';
				echo '<td>' . ( 'draft' === $r->status ? '<a class="button" href="' . esc_url( $apply_url ) . '">Apply</a>' : '—' ) . '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="6">No rulesets generated yet.</td></tr>';
		}
		echo '</tbody></table>';

		// Switch log
		echo '<h2>Switch Log</h2>';
		$switches = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_ruleset_switches ORDER BY switched_at DESC LIMIT 50" );
		echo '<table class="widefat striped"><thead><tr><th>Campaign</th><th>From</th><th>To</th><th>Metric</th><th>Value</th><th>Switched</th></tr></thead><tbody>';
		if ( $switches ) {
			foreach ( $switches as $s ) {
				echo '<tr><td>' . absint( $s->campaign_id ) . '</td><td>' . absint( $s->from_ruleset_id ) . '</td><td>' . absint( $s->to_ruleset_id ) . '</td>';
				echo '<td>' . esc_html( $s->trigger_metric ) . '</td><td>' . esc_html( $s->trigger_value ) . '</td><td>' . esc_html( $s->switched_at ) . '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="6">No switches logged yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── REST routes ─────────────────────────────────────────────────────────

	public static function register_routes() {
		$cap = fn() => current_user_can( 'manage_sovereign_rulesets' );
		register_rest_route( 'sovereign-builder/v1', '/rulesets', [
			'methods' => 'GET', 'permission_callback' => $cap,
			'callback' => fn() => rest_ensure_response( ( function() { global $wpdb; return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_rulesets ORDER BY id DESC" ); } )() ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/ruleset/(?P<id>\d+)/apply', [
			'methods' => 'POST', 'permission_callback' => $cap,
			'callback' => function( $r ) {
				$campaign_id = absint( $r->get_param( 'campaign_id' ) ?? 0 );
				self::apply_ruleset( absint( $r['id'] ), $campaign_id );
				return rest_ensure_response( [ 'success' => true ] );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/ruleset/(?P<id>\d+)/diff', [
			'methods' => 'GET', 'permission_callback' => $cap,
			'callback' => function( $r ) {
				$campaign_id = absint( $r->get_param( 'campaign_id' ) ?? 0 );
				return rest_ensure_response( self::diff_ruleset( absint( $r['id'] ), $campaign_id ) );
			},
		] );
	}
}
SB_Ruleset_Engine::init();