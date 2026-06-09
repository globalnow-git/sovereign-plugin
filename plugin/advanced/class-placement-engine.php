<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBPlacementEngine
 * Context resolution, placement matching, admin and front-end mounting.
 */
class SBPlacementEngine {

	/**
	 * Register a placement.
	 *
	 * @param array $config
	 * @return int  placement ID
	 */
	public static function register_placement( array $config ): int {
		global $wpdb;

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_placements WHERE surface_slug = %s AND context_type = %s AND context_key = %s",
			$config['surface_slug'] ?? '',
			$config['context_type'] ?? 'page',
			$config['context_key'] ?? ''
		) );

		if ( $existing ) {
			$wpdb->update( "{$wpdb->prefix}sb_placements", [
				'label'           => sanitize_text_field( $config['label'] ?? '' ),
				'form_slug'       => sanitize_key( $config['form_slug'] ?? '' ),
				'road_key'        => sanitize_key( $config['road_key'] ?? '' ),
				'required_cap'    => sanitize_key( $config['required_cap'] ?? '' ),
				'pmpro_level'     => absint( $config['pmpro_level'] ?? 0 ),
				'url_param_match' => sanitize_text_field( $config['url_param_match'] ?? '' ),
				'priority'        => absint( $config['priority'] ?? 10 ),
				'status'          => sanitize_key( $config['status'] ?? 'active' ),
			], [ 'id' => $existing ] );
			return (int) $existing;
		}

		$wpdb->insert( "{$wpdb->prefix}sb_placements", [
			'label'           => sanitize_text_field( $config['label'] ?? '' ),
			'surface_slug'    => sanitize_key( $config['surface_slug'] ?? '' ),
			'form_slug'       => sanitize_key( $config['form_slug'] ?? '' ),
			'context_type'    => sanitize_key( $config['context_type'] ?? 'page' ),
			'context_key'     => sanitize_text_field( $config['context_key'] ?? '' ),
			'road_key'        => sanitize_key( $config['road_key'] ?? '' ),
			'required_cap'    => sanitize_key( $config['required_cap'] ?? '' ),
			'pmpro_level'     => absint( $config['pmpro_level'] ?? 0 ),
			'url_param_match' => sanitize_text_field( $config['url_param_match'] ?? '' ),
			'priority'        => absint( $config['priority'] ?? 10 ),
			'status'          => 'active',
			'created_at'      => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Resolve the current request context.
	 *
	 * @param WP_REST_Request|null $request
	 * @return array
	 */
	public static function resolve_context( $request = null ): array {
		$user_id  = get_current_user_id();
		$road_key = $user_id ? ( get_user_meta( $user_id, 'sb_road_key', true ) ?: '' ) : '';

		$page_type = 'general';
		if ( is_singular() ) {
			$page_type = 'post_type:' . get_post_type();
		} elseif ( is_archive() ) {
			$page_type = 'archive';
		} elseif ( is_front_page() ) {
			$page_type = 'front_page';
		} elseif ( is_admin() ) {
			$page_type = 'admin';
		}

		$url_params = [];
		if ( $request ) {
			$url_params = (array) $request->get_query_params();
		} elseif ( isset( $_GET ) ) {
			$url_params = array_map( 'sanitize_text_field', $_GET );
		}

		return [
			'user_id'    => $user_id,
			'road_key'   => $road_key,
			'page_type'  => $page_type,
			'post_slug'  => is_singular() ? get_post_field( 'post_name' ) : '',
			'url_params' => $url_params,
			'caps'       => [], // Evaluated per-placement via current_user_can()
			'timestamp'  => time(),
		];
	}

	/**
	 * Return all active placements that match the given context.
	 *
	 * @param array $context
	 * @return array
	 */
	public static function get_placements_for_context( array $context ): array {
		global $wpdb;

		$road_key   = sanitize_key( $context['road_key'] ?? '' );
		$page_type  = sanitize_text_field( $context['page_type'] ?? '' );
		$post_slug  = sanitize_key( $context['post_slug'] ?? '' );

		$placements = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_placements
			 WHERE status = 'active'
			 ORDER BY priority ASC, id ASC"
		);

		$matched = [];
		foreach ( $placements as $p ) {
			if ( ! self::placement_matches_context( $p, $context ) ) {
				continue;
			}
			$matched[] = $p;
		}

		return $matched;
	}

	/**
	 * Evaluate whether a single placement matches the given context.
	 *
	 * @param object $placement
	 * @param array  $context
	 * @return bool
	 */
	private static function placement_matches_context( object $placement, array $context ): bool {
		// Road key filter
		if ( $placement->road_key && $placement->road_key !== ( $context['road_key'] ?? '' ) ) {
			return false;
		}

		// Capability check
		if ( $placement->required_cap && ! current_user_can( $placement->required_cap ) ) {
			return false;
		}

		// PMPro level check
		if ( $placement->pmpro_level && function_exists( 'pmpro_hasMembershipLevel' ) ) {
			if ( ! pmpro_hasMembershipLevel( (int) $placement->pmpro_level ) ) {
				return false;
			}
		}

		// URL param match
		if ( $placement->url_param_match ) {
			parse_str( $placement->url_param_match, $required_params );
			foreach ( $required_params as $key => $val ) {
				if ( ( $context['url_params'][ $key ] ?? null ) !== $val ) {
					return false;
				}
			}
		}

		// Context type match
		switch ( $placement->context_type ) {
			case 'global':
				return true;

			case 'page':
				return ( $context['post_slug'] ?? '' ) === $placement->context_key;

			case 'post_type':
				return str_starts_with( $context['page_type'] ?? '', 'post_type:' . $placement->context_key );

			case 'road_key':
				return ( $context['road_key'] ?? '' ) === $placement->context_key;

			case 'after_factory_run':
			case 'checkout_confirm':
			case 'pmpro_level':
				// These are action-triggered, not page-context-matched
				return false;

			default:
				return false;
		}
	}

	/**
	 * Mount: render all matched surfaces and forms for context.
	 *
	 * @param array $context
	 * @return string
	 */
	public static function mount( $context = [] ): string {
		if ( ! is_array( $context ) ) { $context = []; }
		$placements = self::get_placements_for_context( $context );
		$html       = '';

		foreach ( $placements as $p ) {
			if ( $p->surface_slug ) {
				$html .= SBUISurfaceEngine::render( $p->surface_slug, $context );
			}
			if ( $p->form_slug ) {
				$html .= SBTinyFormEngine::render( $p->form_slug, $context );
			}
		}

		return $html;
	}

	/**
	 * Get placements configured for an admin screen slug.
	 *
	 * @param string $screen_slug
	 * @return array
	 */
	public static function get_admin_placements( string $screen_slug ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_placements
			 WHERE context_type = 'admin_screen'
			   AND context_key = %s
			   AND status = 'active'
			 ORDER BY priority ASC",
			$screen_slug
		) );
	}

	/**
	 * Mount admin placements for a screen.
	 *
	 * @param string $screen_slug
	 * @return string
	 */
	public static function mount_admin( string $screen_slug ): string {
		$placements = self::get_admin_placements( $screen_slug );
		$html       = '';
		$context    = [ 'user_id' => get_current_user_id(), 'road_key' => '' ];

		foreach ( $placements as $p ) {
			if ( $p->surface_slug ) {
				$html .= SBUISurfaceEngine::render( $p->surface_slug, $context );
			}
		}

		return $html;
	}

	/**
	 * Render admin screen — placements list.
	 */
	public static function render_placements_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_placements' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$placements = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_placements ORDER BY priority ASC, id ASC" );

		echo '<div class="wrap"><h1>Placements</h1>';
		echo '<table class="widefat striped"><thead><tr><th>Label</th><th>Surface</th><th>Form</th><th>Context</th><th>Road</th><th>Priority</th><th>Status</th></tr></thead><tbody>';
		foreach ( $placements as $p ) {
			echo '<tr>';
			echo '<td>' . esc_html( $p->label ) . '</td>';
			echo '<td>' . esc_html( $p->surface_slug ) . '</td>';
			echo '<td>' . esc_html( $p->form_slug ) . '</td>';
			echo '<td>' . esc_html( $p->context_type ) . ':' . esc_html( $p->context_key ) . '</td>';
			echo '<td>' . esc_html( $p->road_key ) . '</td>';
			echo '<td>' . (int) $p->priority . '</td>';
			echo '<td>' . esc_html( $p->status ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $placements ) ) {
			echo '<tr><td colspan="7">No placements configured.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}