<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBUISurfaceEngine
 * Operator-defined micro-surfaces: banners, widgets, panels, notifications.
 */
class SBUISurfaceEngine {

	/** @var array Registered surfaces (in-memory cache for current request) */
	private static array $registry = [];

	/**
	 * Register a surface config.
	 *
	 * @param array $surface_config
	 */
	public static function register( array $surface_config ): void {
		$slug = sanitize_key( $surface_config['slug'] ?? '' );
		if ( $slug ) {
			self::$registry[ $slug ] = $surface_config;
		}
	}

	/**
	 * Get a surface from DB.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function get_surface( string $slug ): ?object {
		// Check in-memory registry first
		if ( isset( self::$registry[ $slug ] ) ) {
			return (object) self::$registry[ $slug ];
		}

		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_ui_surfaces WHERE slug = %s AND status = 'active'",
			$slug
		) );
	}

	/**
	 * Render a surface.
	 *
	 * @param string $slug
	 * @param array  $context
	 * @return string
	 */
	public static function render( string $slug, array $context = [] ): string {
		$surface = self::get_surface( $slug );
		if ( ! $surface ) {
			return '';
		}

		if ( ! self::check_visibility( $surface, $context ) ) {
			return '';
		}

		$content      = json_decode( $surface->content_json ?? '{}', true );
		$surface_type = $surface->surface_type ?? 'banner';

		return self::render_by_type( $surface_type, $slug, $content );
	}

	/**
	 * Render surface HTML by type.
	 *
	 * @param string $type
	 * @param string $slug
	 * @param array  $content
	 * @return string
	 */
	private static function render_by_type( string $type, string $slug, array $content ): string {
		$id = 'sb-surface-' . esc_attr( $slug );

		switch ( $type ) {
			case 'banner':
				$text        = esc_html( $content['text'] ?? '' );
				$cta_label   = esc_html( $content['cta_label'] ?? '' );
				$cta_url     = esc_url( $content['cta_url'] ?? '#' );
				$dismissible = ! empty( $content['dismissible'] );
				$html  = "<div id='{$id}' class='sb-surface sb-banner' style='background:#1e3a5f;color:#fff;padding:12px 20px;text-align:center;'>";
				$html .= "<span>{$text}</span> ";
				if ( $cta_label ) {
					$html .= "<a href='{$cta_url}' style='color:#ffd700;font-weight:bold;margin-left:10px;'>{$cta_label}</a>";
				}
				if ( $dismissible ) {
					$html .= "<button onclick=\"document.getElementById('{$id}').style.display='none'\" style='float:right;background:none;border:none;color:#fff;cursor:pointer;'>✕</button>";
				}
				$html .= '</div>';
				return $html;

			case 'notification_bar':
				$text  = esc_html( $content['text'] ?? '' );
				$level = sanitize_key( $content['level'] ?? 'info' );
				$colors = [ 'info' => '#d1ecf1', 'warning' => '#fff3cd', 'error' => '#f8d7da', 'success' => '#d4edda' ];
				$bg    = $colors[ $level ] ?? $colors['info'];
				return "<div id='{$id}' class='sb-surface sb-notification-bar' style='background:{$bg};padding:10px 20px;border-radius:4px;margin:10px 0;'>{$text}</div>";

			case 'sidebar_widget':
				$title = esc_html( $content['title'] ?? '' );
				$body  = wp_kses_post( $content['body'] ?? '' );
				return "<div id='{$id}' class='sb-surface sb-sidebar-widget'><h3>{$title}</h3><div>{$body}</div></div>";

			case 'inline_panel':
				$title = esc_html( $content['title'] ?? '' );
				$body  = wp_kses_post( $content['body'] ?? '' );
				return "<div id='{$id}' class='sb-surface sb-inline-panel' style='border:1px solid #ddd;padding:15px;margin:15px 0;border-radius:4px;'><h4>{$title}</h4>{$body}</div>";

			case 'factory_output_panel':
				$sections = $content['sections'] ?? [];
				$html     = "<div id='{$id}' class='sb-surface sb-factory-output-panel'>";
				foreach ( $sections as $section ) {
					$html .= '<div class="sb-output-section">';
					$html .= '<h4>' . esc_html( $section['heading'] ?? '' ) . '</h4>';
					$html .= '<div>' . wp_kses_post( $section['content'] ?? '' ) . '</div>';
					$html .= '</div>';
				}
				$html .= '</div>';
				return $html;

			case 'modal_trigger':
				$btn_label   = esc_html( $content['btn_label'] ?? 'Open' );
				$modal_body  = wp_kses_post( $content['modal_body'] ?? '' );
				$modal_title = esc_html( $content['modal_title'] ?? '' );
				return "<div id='{$id}' class='sb-surface sb-modal-trigger'>
					<button class='button sb-open-modal' data-modal='modal_{$slug}'>{$btn_label}</button>
					<div id='modal_{$slug}' class='sb-modal' style='display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;'>
						<div style='background:#fff;max-width:600px;margin:80px auto;padding:30px;border-radius:8px;position:relative;'>
							<h3>{$modal_title}</h3>{$modal_body}
							<button class='button sb-close-modal' data-modal='modal_{$slug}'>Close</button>
						</div>
					</div>
				</div>";

			default:
				return "<div id='{$id}' class='sb-surface'></div>";
		}
	}

	/**
	 * Inject all surfaces registered for a region.
	 *
	 * @param string $region_slug
	 * @return string
	 */
	public static function inject_region( string $region_slug ): string {
		global $wpdb;

		$surfaces = $wpdb->get_results( $wpdb->prepare(
			"SELECT slug FROM {$wpdb->prefix}sb_ui_surfaces
			 WHERE placement_region = %s AND status = 'active'
			 ORDER BY id ASC",
			$region_slug
		) );

		$html = '';
		foreach ( $surfaces as $s ) {
			$html .= self::render( $s->slug );
		}
		return $html;
	}

	/**
	 * Check visibility rules for a surface.
	 *
	 * @param object $surface
	 * @param array  $context
	 * @return bool
	 */
	private static function check_visibility( object $surface, array $context ): bool {
		$rules = json_decode( $surface->visibility_rules_json ?? '{}', true );
		if ( empty( $rules ) ) {
			return true;
		}

		if ( ! empty( $rules['user_logged_in'] ) && ! is_user_logged_in() ) {
			return false;
		}

		if ( ! empty( $rules['capability'] ) && ! current_user_can( $rules['capability'] ) ) {
			return false;
		}

		if ( ! empty( $rules['road_key'] ) ) {
			$user_road = $context['road_key'] ?? '';
			if ( $user_road !== $rules['road_key'] ) {
				return false;
			}
		}

		if ( ! empty( $rules['pmpro_level'] ) && function_exists( 'pmpro_hasMembershipLevel' ) ) {
			if ( ! pmpro_hasMembershipLevel( (int) $rules['pmpro_level'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Shortcode handler: [sb_surface slug="slug"]
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function shortcode( array $atts ): string {
		$atts = shortcode_atts( [ 'slug' => '' ], $atts );
		if ( ! $atts['slug'] ) {
			return '';
		}
		$context = [
			'user_id'  => get_current_user_id(),
			'road_key' => get_user_meta( get_current_user_id(), 'sb_road_key', true ) ?: '',
		];
		return self::render( sanitize_key( $atts['slug'] ), $context );
	}

	/**
	 * wp_footer hook — inject global surfaces.
	 */
	public static function inject_footer_surfaces(): void {
		echo self::inject_region( 'wp_footer' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Publish a surface — creates approval record.
	 *
	 * @param int $surface_id
	 * @return int  approval ID
	 */
	public static function publish( int $surface_id ): int {
		global $wpdb;
		$surface = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_ui_surfaces WHERE id = %d",
			$surface_id
		) );

		if ( ! $surface ) {
			return 0;
		}

		$approval_id = SB_Approval_Engine::create_approval( [
			'approval_type' => 'surface_publish',
			'payload'       => wp_json_encode( [ 'surface_id' => $surface_id, 'slug' => $surface->slug ] ),
			'campaign_id'   => 0,
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_SURFACE_PUBLISHED,
			"Surface publish queued for approval: {$surface->slug}",
			get_current_user_id(),
			[ 'surface_id' => $surface_id, 'approval_id' => $approval_id ],
			'info'
		);

		return $approval_id;
	}

	/**
	 * Render admin screen — surface list.
	 */
	public static function render_surfaces_screen(): void {
		if ( ! current_user_can( 'manage_sovereign_surfaces' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_ui_surfaces' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$surfaces = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_ui_surfaces ORDER BY created_at DESC" );

		echo '<div class="wrap"><h1>UI Surfaces</h1>';
		echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Label</th><th>Type</th><th>Region</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $surfaces as $s ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $s->slug ) . '</code></td>';
			echo '<td>' . esc_html( $s->label ) . '</td>';
			echo '<td>' . esc_html( $s->surface_type ) . '</td>';
			echo '<td>' . esc_html( $s->placement_region ) . '</td>';
			echo '<td>' . esc_html( $s->status ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( "admin.php?page=sb-ui-surfaces&action=edit&id={$s->id}" ) ) . '">Edit</a></td>';
			echo '</tr>';
		}
		if ( empty( $surfaces ) ) {
			echo '<tr><td colspan="6">No surfaces defined yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}