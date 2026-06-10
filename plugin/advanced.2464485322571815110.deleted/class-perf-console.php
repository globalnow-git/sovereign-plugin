<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBPerfConsole
 * Performance telemetry, hotspot detection, regression tracking, threshold alerts.
 */
class SBPerfConsole {

	/**
	 * Take a performance snapshot.
	 *
	 * @return int  snapshot batch ID (row count inserted)
	 */
	public static function take_snapshot(): int {
		global $wpdb;

		$metrics = [
			[ 'memory_used_mb',  round( memory_get_usage( true ) / 1048576, 2 ) ],
			[ 'memory_peak_mb',  round( memory_get_peak_usage( true ) / 1048576, 2 ) ],
			[ 'query_count',     $wpdb->num_queries ],
			[ 'db_error_count',  $wpdb->num_queries > 0 ? 0 : 1 ],
		];

		$cron_depth  = count( (array) _get_cron_array() );
		$metrics[]   = [ 'cron_queue_depth', $cron_depth ];

		if ( class_exists( 'SB_Telemetry_Buffer' ) ) {
			// Approximate buffer depth via direct count
			$buffer_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)" );
			$metrics[]    = [ 'telemetry_1m_events', $buffer_count ];
		}

		$inserted = 0;
		foreach ( $metrics as [ $type, $value ] ) {
			$wpdb->insert( "{$wpdb->prefix}sb_perf_metrics", [
				'metric_type'  => $type,
				'metric_value' => $value,
				'context_json' => wp_json_encode( [ 'url' => ( $_SERVER['REQUEST_URI'] ?? '' ) ] ),
				'captured_at'  => current_time( 'mysql' ),
			] );
			$inserted++;
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_PERF_SNAPSHOT_TAKEN, "Performance snapshot taken ({$inserted} metrics)", 0, [], 'info' );

		return $inserted;
	}

	/**
	 * Get slowest DB queries.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function get_query_hotspots( int $limit = 10 ): array {
		global $wpdb;

		if ( empty( $wpdb->queries ) ) {
			return [ 'note' => 'Enable SAVEQUERIES in wp-config.php to capture query data.' ];
		}

		$queries = array_map( fn( $q ) => [
			'sql'      => substr( $q[0], 0, 200 ),
			'time_ms'  => round( $q[1] * 1000, 2 ),
			'caller'   => $q[2],
		], $wpdb->queries );

		usort( $queries, fn( $a, $b ) => $b['time_ms'] <=> $a['time_ms'] );

		return array_slice( $queries, 0, $limit );
	}

	/**
	 * Measure per-plugin timing contribution.
	 *
	 * @return array
	 */
	public static function get_plugin_impact(): array {
		// Native WP measurement — no external tooling required.
		// Measures memory consumed and DB queries since WordPress loaded.
		global $wpdb;

		$active_plugins  = get_option( 'active_plugins', [] );
		$memory_usage_mb = round( memory_get_usage( true ) / 1024 / 1024, 2 );
		$memory_peak_mb  = round( memory_get_peak_usage( true ) / 1024 / 1024, 2 );
		$total_queries   = $wpdb->num_queries;
		$memory_limit_mb = (int) ini_get( 'memory_limit' );

		// Approximate per-plugin memory: divide consumed memory by active plugin count
		$plugin_count       = max( 1, count( $active_plugins ) );
		$approx_per_plugin  = round( $memory_usage_mb / $plugin_count, 2 );

		// Detect plugins that registered many hooks (coarse signal of hook weight)
		global $wp_filter;
		$hook_counts = [];
		foreach ( $active_plugins as $plugin_file ) {
			$plugin_slug = dirname( $plugin_file );
			$count = 0;
			foreach ( $wp_filter as $hook_name => $hook ) {
				foreach ( $hook->callbacks ?? [] as $priority => $cbs ) {
					foreach ( $cbs as $cb ) {
						if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) ) {
							$class = get_class( $cb['function'][0] );
						} elseif ( is_array( $cb['function'] ) ) {
							$class = (string) $cb['function'][0];
						} else {
							$class = (string) $cb['function'];
						}
						// Coarse heuristic: plugin slug appears in plugin path
						if ( $plugin_slug !== '.' && str_contains( strtolower( $class ), strtolower( $plugin_slug ) ) ) {
							$count++;
						}
					}
				}
			}
			if ( $count > 0 ) {
				$hook_counts[ $plugin_slug ] = $count;
			}
		}
		arsort( $hook_counts );

		return [
			'active_plugins'         => $plugin_count,
			'memory_usage_mb'        => $memory_usage_mb,
			'memory_peak_mb'         => $memory_peak_mb,
			'memory_limit_mb'        => $memory_limit_mb,
			'memory_usage_pct'       => $memory_limit_mb > 0 ? round( $memory_usage_mb / $memory_limit_mb * 100, 1 ) : null,
			'total_db_queries'       => $total_queries,
			'approx_memory_per_plugin_mb' => $approx_per_plugin,
			'hook_weight_by_plugin'  => array_slice( $hook_counts, 0, 10, true ),
			'measurement_method'     => 'wp-native',
			'note'                   => 'Hook counts are heuristic (class-name match). Memory and query counts are accurate WP globals.',
		];
	}

	/**
	 * Detect regressions vs a baseline date.
	 *
	 * @param string $baseline_date  Y-m-d
	 * @return array
	 */
	public static function detect_regressions( string $baseline_date ): array {
		global $wpdb;

		$regressions = [];
		$metric_types = [ 'memory_used_mb', 'memory_peak_mb', 'query_count', 'cron_queue_depth' ];

		foreach ( $metric_types as $type ) {
			$baseline = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(metric_value) FROM {$wpdb->prefix}sb_perf_metrics
				 WHERE metric_type = %s AND DATE(captured_at) = %s",
				$type,
				$baseline_date
			) );

			$current = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(metric_value) FROM {$wpdb->prefix}sb_perf_metrics
				 WHERE metric_type = %s AND captured_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
				$type
			) );

			if ( $baseline > 0 ) {
				$delta_pct = round( ( ( $current - $baseline ) / $baseline ) * 100, 1 );
				if ( $delta_pct > 20 ) {
					$regressions[] = [
						'metric'      => $type,
						'baseline'    => $baseline,
						'current'     => $current,
						'delta_pct'   => $delta_pct,
						'severity'    => $delta_pct > 50 ? 'critical' : 'warning',
					];
				}
			}
		}

		if ( ! empty( $regressions ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_PERF_REGRESSION_DETECTED, 'Performance regression detected: ' . count( $regressions ) . ' metrics degraded', 0, [ 'regressions' => count( $regressions ) ], 'warning' );
		}

		return $regressions;
	}

	/**
	 * Set a threshold for a metric.
	 *
	 * @param string $metric
	 * @param float  $value
	 * @return bool
	 */
	public static function set_threshold( string $metric, float $value ): bool {
		return (bool) SB_Extension_API::set_setting( 'sb_perf_threshold_' . sanitize_key( $metric ), $value );
	}

	/**
	 * Check all thresholds — fires event if exceeded.
	 * Called by sb_perf_snapshot_cron.
	 */
	public static function check_thresholds(): void {
		global $wpdb;

		$metric_types = [ 'memory_used_mb', 'memory_peak_mb', 'query_count', 'cron_queue_depth' ];

		foreach ( $metric_types as $type ) {
			$threshold = (float) SB_Extension_API::get_setting( 'sb_perf_threshold_' . $type, 0 );
			if ( $threshold <= 0 ) {
				continue;
			}

			$current = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT metric_value FROM {$wpdb->prefix}sb_perf_metrics
				 WHERE metric_type = %s ORDER BY captured_at DESC LIMIT 1",
				$type
			) );

			if ( $current > $threshold ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_PERF_THRESHOLD_EXCEEDED,
					"Threshold exceeded: {$type} = {$current} (threshold: {$threshold})",
					0,
					[ 'metric' => $type, 'value' => $current, 'threshold' => $threshold ],
					'warning'
				);
				do_action( 'sb_perf_threshold_exceeded', $type, $current, $threshold );
			}
		}
	}

	/**
	 * Get suggested remediation for a metric.
	 *
	 * @param string $metric
	 * @return array
	 */
	public static function get_remediation_actions( string $metric ): array {
		return match( $metric ) {
			'memory_used_mb', 'memory_peak_mb' => [
				'Increase PHP memory_limit in php.ini or wp-config.php.',
				'Disable unnecessary plugins.',
				'Enable object caching (Redis/Memcached).',
			],
			'query_count' => [
				'Enable query caching.',
				'Add database indexes for slow queries.',
				'Review plugins making excessive DB calls.',
			],
			'cron_queue_depth' => [
				'Check for stalled cron events via Cron Inspector.',
				'Verify WP-Cron is running (server cron recommended).',
				'Run SB_Installer::schedule_cron() via /repair-system.',
			],
			default => [ 'Monitor the metric and check recent plugin updates.' ],
		};
	}

	/**
	 * Daily cron: snapshot + threshold check.
	 */
	public static function run_daily_snapshot(): void {
		self::take_snapshot();
		self::check_thresholds();
	}

	/**
	 * Render performance console admin screen.
	 */
	public static function render_perf_console_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_perf_metrics', 'sb_performance_thresholds' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$metrics = $wpdb->get_results(
			"SELECT metric_type, AVG(metric_value) as avg_value, MAX(metric_value) as max_value,
			        MAX(captured_at) as last_captured
			 FROM {$wpdb->prefix}sb_perf_metrics
			 WHERE captured_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			 GROUP BY metric_type
			 ORDER BY metric_type"
		);

		echo '<div class="wrap"><h1>Performance Operations Console</h1>';
		echo '<table class="widefat striped"><thead><tr><th>Metric</th><th>7-Day Avg</th><th>7-Day Max</th><th>Last Captured</th></tr></thead><tbody>';
		foreach ( $metrics as $m ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $m->metric_type ) . '</code></td>';
			echo '<td>' . esc_html( round( $m->avg_value, 2 ) ) . '</td>';
			echo '<td>' . esc_html( round( $m->max_value, 2 ) ) . '</td>';
			echo '<td>' . esc_html( $m->last_captured ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $metrics ) ) {
			echo '<tr><td colspan="4">No metrics captured yet. Metrics are collected daily via cron.</td></tr>';
		}
		echo '</tbody></table>';

		// Manual snapshot button
		echo '<p style="margin-top:20px;"><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_perf_snapshot' ), 'sb_perf_snapshot' ) ) . '" class="button">Take Snapshot Now</a></p>';
		echo '</div>';
	}
}