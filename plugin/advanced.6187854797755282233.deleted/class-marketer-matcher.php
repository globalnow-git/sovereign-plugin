<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SB_Marketer_Matcher — Framework scoring and adaptive pivot engine.
 * Scores active campaigns against famous marketer signal matrices.
 * Never auto-switches — all pivots go through HITM approval queue.
 */
class SB_Marketer_Matcher {

	public static function init() {
		add_action( 'sb_modules_register',    [ __CLASS__, 'self_register' ] );
		add_action( 'sb_check_signals_cron',  [ __CLASS__, 'score_all_frameworks' ] );
		add_filter( 'sb_admin_menu_items',    [ __CLASS__, 'add_menu_items' ] );
		add_filter( 'sb_dashboard_stat_cards',[ __CLASS__, 'add_stat_cards' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'marketer-matcher', '1.0.0', 'SB_Marketer_Matcher' );
			self::seed_personas();
		}
	}

	// ── Scoring engine ──────────────────────────────────────────────────────

	public static function score_all_frameworks() {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }

		$campaigns = $wpdb->get_results( "SELECT id FROM {$wpdb->prefix}sb_campaigns WHERE status = 'active'" );
		$personas  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_marketer_personas" );

		foreach ( $campaigns as $campaign ) {
			$scores = [];
			foreach ( $personas as $persona ) {
				$score = self::score_framework( $persona->id, $campaign->id );
				$scores[ $persona->id ] = $score;
			}
			// Rank and store
			arsort( $scores );
			$rank = 1;
			foreach ( $scores as $persona_id => $total_score ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}sb_ruleset_matchscores WHERE ruleset_id = %d AND campaign_id = %d",
					$persona_id, $campaign->id
				) );
				$row = [
					'ruleset_id'       => $persona_id,
					'campaign_id'      => $campaign->id,
					'total_score'      => round( $total_score, 2 ),
					'rank'             => $rank,
					'users_in_segment' => 0,
					'calculated_at'    => current_time( 'mysql' ),
				];
				if ( $existing ) {
					$wpdb->update( "{$wpdb->prefix}sb_ruleset_matchscores", $row, [ 'id' => $existing ] );
				} else {
					$wpdb->insert( "{$wpdb->prefix}sb_ruleset_matchscores", $row );
				}
				$rank++;
			}
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_RULESET_MATCH_SCORE, "Framework scoring complete for campaign {$campaign->id}.", 0, [], 'verbose' );

			// Check if top scorer is not current active — queue recommendation if gap > 20 points
			if ( ! empty( $scores ) ) {
				self::maybe_recommend_switch( $campaign->id, $scores );
			}
		}
	}

	public static function score_framework( $persona_id, $campaign_id ) {
		global $wpdb;
		$persona_id  = absint( $persona_id );
		$campaign_id = absint( $campaign_id );

		$signal_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_marketer_signals WHERE ruleset_prompt_id = %d",
			$persona_id
		) );

		if ( empty( $signal_rows ) ) {
			// No signal weights — return neutral score
			return 50.0;
		}

		$weighted_sum = 0.0;
		$weight_total = 0.0;

		foreach ( $signal_rows as $sig ) {
			$weight    = (float) $sig->weight;
			$expected  = (float) $sig->expected_value;
			$window    = absint( $sig->expected_window_days );
			$tolerance = (float) $sig->tolerance_pct / 100;
			$direction = $sig->direction;
			$since     = date( 'Y-m-d H:i:s', time() - ( $window * DAY_IN_SECONDS ) );

			// Get actual value from signals table
			$actual = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM(current_value) FROM {$wpdb->prefix}sb_signals WHERE signal_type = %s AND campaign_id = %d",
				sanitize_key( $sig->signal_type ), $campaign_id
			) );

			// Score: 100 if within tolerance, scales down proportionally
			if ( $expected == 0 ) {
				$match = $actual == 0 ? 100.0 : max( 0, 100 - abs( $actual ) * 10 );
			} else {
				$deviation = abs( $actual - $expected ) / $expected;
				$match     = max( 0, 100 - ( $deviation / max( $tolerance, 0.01 ) ) * 100 );
				// For lower_better signals flip the scoring
				if ( 'lower_better' === $direction && $actual < $expected ) { $match = 100.0; }
			}

			$weighted_sum += $weight * min( 100, $match );
			$weight_total += $weight;

			// Log per-signal in verbose mode
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_MARKETER_FRAMEWORK_EVALUATED,
				"Persona {$persona_id} signal {$sig->signal_type}: actual={$actual} expected={$expected} match={$match}",
				0, [], 'verbose'
			);

			// Store per-signal performance row
			$wpdb->insert( "{$wpdb->prefix}sb_ruleset_performance", [
				'ruleset_id'    => $persona_id,
				'campaign_id'   => $campaign_id,
				'signal_type'   => sanitize_key( $sig->signal_type ),
				'expected_value'=> $expected,
				'actual_value'  => $actual,
				'match_score'   => round( $match, 2 ),
				'weight'        => $weight,
				'snapshot_at'   => current_time( 'mysql' ),
			] );
		}

		return $weight_total > 0 ? $weighted_sum / $weight_total : 50.0;
	}

	private static function maybe_recommend_switch( $campaign_id, array $scores ) {
		global $wpdb;
		if ( empty( $scores ) ) { return; }

		arsort( $scores );
		reset( $scores );
		$top_persona_id = (int) key( $scores );
		$top_score      = (float) current( $scores );

		// Get current active ruleset
		$current_ruleset_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ruleset_id FROM {$wpdb->prefix}sb_campaign_rulesets WHERE campaign_id = %d AND status = 'active' LIMIT 1",
			$campaign_id
		) );

		if ( $top_persona_id === $current_ruleset_id ) { return; }

		// Check gap is significant (>20 points) before recommending
		$current_score = $scores[ $current_ruleset_id ] ?? 0;
		$gap           = $top_score - $current_score;
		if ( $gap < 20 ) { return; }

		$persona = $wpdb->get_row( $wpdb->prepare(
			"SELECT persona_name FROM {$wpdb->prefix}sb_marketer_personas WHERE id = %d", $top_persona_id
		) );

		SB_Approval_Engine::create_approval( $campaign_id, 'analyst_recommendation', [
			'action_type'     => 'switch_ruleset',
			'target'          => (string) $top_persona_id,
			'rationale'       => ( $persona ? $persona->persona_name : "Persona #{$top_persona_id}" ) . " scores {$gap} points higher than current active framework for campaign {$campaign_id}.",
			'expected_impact' => 'Improved signal match rate. Estimated conversion improvement based on framework score gap.',
			'confidence'      => min( 95, (int) ( $gap * 2 ) ),
		] );
	}

	// ── Persona seeding ─────────────────────────────────────────────────────

	private static function seed_personas() {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_marketer_personas" );
		if ( $count > 0 ) { return; }

		$personas = [
			[
				'slug'     => 'dan-kennedy-direct-response',
				'name'     => 'Dan Kennedy Direct Response',
				'summary'  => 'Urgency-driven, deadline-based direct response. Fast conversion expected within 7 days. High importance on time_to_first_conversion and deadline_response signals.',
				'weights'  => [ 'time_to_first_conversion' => 0.35, 'wc_purchase' => 0.30, 'deadline_response' => 0.25, 'email_opened' => 0.10 ],
				'expected' => [ 'time_to_first_conversion' => 5, 'wc_purchase' => 0.05, 'deadline_response' => 0.40, 'email_opened' => 0.35 ],
			],
			[
				'slug'     => 'jeff-walker-product-launch-formula',
				'name'     => 'Jeff Walker Product Launch Formula',
				'summary'  => 'Three-phase pre-launch build, rising engagement, cart-open conversion spike. Pre-launch content engagement is the key leading indicator.',
				'weights'  => [ 'prelaunch_engaged' => 0.35, 'wc_purchase' => 0.30, 'content_consumed' => 0.20, 'social_shared' => 0.15 ],
				'expected' => [ 'prelaunch_engaged' => 0.60, 'wc_purchase' => 0.08, 'content_consumed' => 0.70, 'social_shared' => 0.20 ],
			],
			[
				'slug'     => 'andre-chaperon-soap-opera-sequence',
				'name'     => 'Andre Chaperon Soap Opera Sequence',
				'summary'  => 'Very high email open rates via story-driven sequences. Completion rate and reply rate are primary signals. Slow conversion build is normal.',
				'weights'  => [ 'email_opened' => 0.35, 'email_replied' => 0.30, 'sequence_completed' => 0.25, 'pmpro_cancel' => 0.10 ],
				'expected' => [ 'email_opened' => 0.60, 'email_replied' => 0.10, 'sequence_completed' => 0.80, 'pmpro_cancel' => 0.05 ],
			],
			[
				'slug'     => 'russell-brunson-value-ladder',
				'name'     => 'Russell Brunson Value Ladder',
				'summary'  => 'Tripwire conversion, OTO acceptance chain, incremental upsell. Micro-conversion sequence is the primary signal — not single big sale.',
				'weights'  => [ 'upsell_accepted' => 0.40, 'wc_purchase' => 0.30, 'lead_magnet_downloaded' => 0.20, 'email_clicked' => 0.10 ],
				'expected' => [ 'upsell_accepted' => 0.25, 'wc_purchase' => 0.03, 'lead_magnet_downloaded' => 0.50, 'email_clicked' => 0.30 ],
			],
			[
				'slug'     => 'seth-godin-permission-marketing',
				'name'     => 'Seth Godin Permission Marketing',
				'summary'  => 'Slow trust build, very low unsubscribes, high long-term retention, referral and testimonial signals. Conversion happens months later.',
				'weights'  => [ 'pmpro_cancel' => 0.30, 'referral_sent' => 0.25, 'testimonial_submitted' => 0.25, 'content_consumed' => 0.20 ],
				'expected' => [ 'pmpro_cancel' => 0.02, 'referral_sent' => 0.15, 'testimonial_submitted' => 0.10, 'content_consumed' => 0.80 ],
			],
			[
				'slug'     => 'gary-halbert-copywriting',
				'name'     => 'Gary Halbert Copywriting',
				'summary'  => 'Headline-driven click response, fast open-to-purchase conversion. Email click rate and speed of purchase are primary indicators.',
				'weights'  => [ 'email_clicked' => 0.40, 'wc_purchase' => 0.30, 'deadline_response' => 0.20, 'email_opened' => 0.10 ],
				'expected' => [ 'email_clicked' => 0.25, 'wc_purchase' => 0.06, 'deadline_response' => 0.35, 'email_opened' => 0.40 ],
			],
			[
				'slug'     => 'jay-abraham-preeminence',
				'name'     => 'Jay Abraham Strategy of Preeminence',
				'summary'  => 'Authority and trust via high-value content. Long buying cycle. Testimonials, referrals, and content engagement are primary signals.',
				'weights'  => [ 'testimonial_submitted' => 0.30, 'referral_sent' => 0.30, 'content_consumed' => 0.25, 'email_replied' => 0.15 ],
				'expected' => [ 'testimonial_submitted' => 0.15, 'referral_sent' => 0.20, 'content_consumed' => 0.75, 'email_replied' => 0.12 ],
			],
			[
				'slug'     => 'dean-jackson-9-word-email',
				'name'     => 'Dean Jackson 9-Word Email',
				'summary'  => 'Short conversational re-engagement. High reply rate to simple prompts. Email reply is the dominant success signal.',
				'weights'  => [ 'email_replied' => 0.50, 'email_opened' => 0.30, 'sequence_completed' => 0.20 ],
				'expected' => [ 'email_replied' => 0.20, 'email_opened' => 0.55, 'sequence_completed' => 0.70 ],
			],
		];

		foreach ( $personas as $p ) {
			$wpdb->insert( "{$wpdb->prefix}sb_marketer_personas", [
				'persona_slug'          => $p['slug'],
				'persona_name'          => $p['name'],
				'methodology_summary'   => $p['summary'],
				'expected_signals_json' => wp_json_encode( $p['expected'] ),
				'signal_weights_json'   => wp_json_encode( $p['weights'] ),
				'pipeline_slug'         => 'default',
				'created_at'            => current_time( 'mysql' ),
			] );
			$persona_id = $wpdb->insert_id;

			// Seed sb_marketer_signals rows
			foreach ( $p['weights'] as $signal_type => $weight ) {
				$expected = $p['expected'][ $signal_type ] ?? 0;
				$wpdb->insert( "{$wpdb->prefix}sb_marketer_signals", [
					'ruleset_prompt_id'   => $persona_id,
					'signal_type'         => sanitize_key( $signal_type ),
					'weight'              => $weight,
					'expected_value'      => $expected,
					'expected_window_days'=> 30,
					'tolerance_pct'       => 25.00,
					'is_primary'          => ( $weight >= 0.30 ) ? 1 : 0,
					'direction'           => in_array( $signal_type, [ 'pmpro_cancel', 'upsell_declined', 'support_ticket' ], true ) ? 'lower_better' : 'higher_better',
					'created_at'          => current_time( 'mysql' ),
				] );
			}
		}
	}

	// ── Admin screens ───────────────────────────────────────────────────────

	public static function add_menu_items( $items ) {
		return $items;
	}

	public static function render_matcher_screen() {
		if ( ! current_user_can( 'manage_sovereign_rulesets' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'sovereign-builder' ) ); }
		global $wpdb;
		echo '<div class="wrap sb-wrap"><h1>Marketer Framework Matcher</h1>';

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			$first = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}sb_campaigns WHERE status = 'active' LIMIT 1" );
			$campaign_id = (int) $first;
		}

		if ( ! $campaign_id ) { echo '<p>No active campaigns found.</p></div>'; return; }

		echo '<p>Showing scores for campaign <strong>#' . $campaign_id . '</strong>.</p>';

		$scores = $wpdb->get_results( $wpdb->prepare(
			"SELECT ms.*, mp.persona_name, mp.methodology_summary
			 FROM {$wpdb->prefix}sb_ruleset_matchscores ms
			 JOIN {$wpdb->prefix}sb_marketer_personas mp ON mp.id = ms.ruleset_id
			 WHERE ms.campaign_id = %d ORDER BY ms.total_score DESC",
			$campaign_id
		) );

		if ( $scores ) {
			echo '<table class="widefat striped"><thead><tr><th>Rank</th><th>Framework</th><th>Score</th><th>Summary</th><th>Calculated</th></tr></thead><tbody>';
			foreach ( $scores as $s ) {
				$bar_pct = min( 100, (float) $s->total_score );
				echo '<tr>';
				echo '<td>#' . absint( $s->rank ) . '</td>';
				echo '<td><strong>' . esc_html( $s->persona_name ) . '</strong></td>';
				echo '<td><div class="sb-progress-wrap" style="width:120px;display:inline-block"><div class="sb-progress-bar" style="width:' . $bar_pct . '%;background:' . ( $s->rank == 1 ? '#00a32a' : '#2271b1' ) . '"></div></div> <strong>' . esc_html( $s->total_score ) . '</strong></td>';
				echo '<td style="max-width:300px;font-size:12px">' . esc_html( wp_trim_words( $s->methodology_summary, 15 ) ) . '</td>';
				echo '<td>' . esc_html( $s->calculated_at ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No scores calculated yet. Scores are generated on the <code>sb_check_signals_cron</code> schedule.</p>';
			$run_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=sb_run_matcher_now&campaign_id=' . $campaign_id ), 'sb_run_matcher' );
			echo '<p><a class="button" href="' . esc_url( $run_url ) . '">Score frameworks now</a></p>';
		}

		echo '</div>';
	}

	public static function add_stat_cards( $cards ) {
		global $wpdb;
		$top = $wpdb->get_row( "SELECT mp.persona_name, ms.total_score FROM {$wpdb->prefix}sb_ruleset_matchscores ms JOIN {$wpdb->prefix}sb_marketer_personas mp ON mp.id = ms.ruleset_id WHERE ms.rank = 1 ORDER BY ms.calculated_at DESC LIMIT 1" );
		if ( $top ) {
			$cards[] = [ 'label' => 'Top framework: ' . $top->persona_name, 'value' => round( $top->total_score ) . '%', 'color' => '#00a32a' ];
		}
		return $cards;
	}
}
SB_Marketer_Matcher::init();