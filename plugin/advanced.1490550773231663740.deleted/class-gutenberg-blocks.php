<?php
/**
 * SBGutenbergBlocks — Native Gutenberg block registration with shortcode fallback.
 * Hooks save_post to compile sovereign block pages to static edge cache.
 * Capability-gated simplified admin dashboard for non-developer operators.
 *
 * @package SovereignBuilder
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBGutenbergBlocks {

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		add_action( 'init',                [ __CLASS__, 'register_blocks' ] );
		add_action( 'save_post',           [ __CLASS__, 'on_save_post' ], 30, 2 );
		add_action( 'admin_init',          [ __CLASS__, 'maybe_simplified_dashboard' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_assets' ] );
	}

	public static function self_register( $loader ): void {
		$loader->register( 'gutenberg-blocks', '1.0.0', __CLASS__ );
	}

	// ── Block registration ────────────────────────────────────────────────────

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) { return; }

		// sovereign/form block
		register_block_type( 'sovereign/form', [
			'attributes'      => [
				'formId'  => [ 'type' => 'integer', 'default' => 0 ],
				'display' => [ 'type' => 'string', 'default' => 'inline' ],
			],
			'render_callback' => [ __CLASS__, 'render_form_block' ],
			'editor_script'   => 'sb-blocks-editor',
		] );

		// sovereign/surface block
		register_block_type( 'sovereign/surface', [
			'attributes'      => [
				'surfaceId' => [ 'type' => 'integer', 'default' => 0 ],
				'context'   => [ 'type' => 'string', 'default' => '' ],
			],
			'render_callback' => [ __CLASS__, 'render_surface_block' ],
			'editor_script'   => 'sb-blocks-editor',
		] );
	}

	public static function enqueue_block_assets(): void {
		// Register a minimal block editor script for block UI in Gutenberg
		wp_register_script(
			'sb-blocks-editor',
			SB_URL . 'assets/blocks-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ],
			SB_VERSION,
			true
		);
		wp_localize_script( 'sb-blocks-editor', 'sbBlocksContext', [
			'restBase' => esc_url_raw( get_rest_url( null, 'sovereign-builder/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		] );
	}

	// ── Block render callbacks ────────────────────────────────────────────────

	public static function render_form_block( array $attrs ): string {
		$form_id = absint( $attrs['formId'] ?? 0 );
		if ( ! $form_id ) {
			return '<p class="sb-block-empty">No form selected.</p>';
		}
		// Fall back cleanly to shortcode renderer
		if ( class_exists( 'SBTinyFormEngine' ) ) {
			return SBTinyFormEngine::shortcode_handler( [ 'id' => $form_id ] );
		}
		return do_shortcode( '[sb_form id="' . $form_id . '"]' );
	}

	public static function render_surface_block( array $attrs ): string {
		$surface_id = absint( $attrs['surfaceId'] ?? 0 );
		if ( ! $surface_id ) {
			return '<p class="sb-block-empty">No surface selected.</p>';
		}
		if ( class_exists( 'SBUISurfaceEngine' ) ) {
			return SBUISurfaceEngine::shortcode_handler( [ 'id' => $surface_id ] );
		}
		return do_shortcode( '[sb_surface id="' . $surface_id . '"]' );
	}

	// ── save_post → edge compile ──────────────────────────────────────────────

	public static function on_save_post( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
		if ( 'publish' !== $post->post_status ) { return; }
		if ( ! class_exists( 'SBEdgeCompiler' ) ) { return; }

		// Only compile posts that contain sovereign blocks
		if ( ! has_block( 'sovereign/form', $post ) && ! has_block( 'sovereign/surface', $post ) ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) { return; }

		// Fetch rendered HTML — use output buffering against the post content
		$url_path = wp_make_link_relative( $permalink );

		// Render the post content through WP's content pipeline
		$rendered = apply_filters( 'the_content', $post->post_content );
		if ( ! $rendered ) { return; }

		// Wrap in minimal HTML shell
		$html  = '<!DOCTYPE html><html><head>';
		$html .= '<meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<title>' . esc_html( $post->post_title ) . '</title>';
		$html .= '</head><body>';
		$html .= $rendered;
		$html .= '</body></html>';

		$result = SBEdgeCompiler::compile_page_to_static( $url_path, $html );
		if ( is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_EDGE_COMPILE_FAILED, $result->get_error_message(), 0, [ 'post_id' => $post_id ], 'warning' );
		}
	}

	// ── Simplified dashboard for non-developer operators ─────────────────────

	public static function maybe_simplified_dashboard(): void {
		if ( ! is_admin() ) { return; }
		// Only simplify for users who have sovereign access but NOT developer clearance
		if ( ! current_user_can( 'manage_sovereign' ) ) { return; }
		if ( current_user_can( 'manage_sovereign_schemas' ) ) { return; } // developer — full access

		// Non-developer operator: hide advanced menus via CSS
		add_action( 'admin_head', [ __CLASS__, 'inject_simplified_css' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_operator_welcome' ] );
	}

	public static function inject_simplified_css(): void {
		// Hide developer-only screens from simplified operator view
		$hidden_slugs = [
			'sb-schema-designer', 'sb-view-schemas', 'sb-dep-graph',
			'sb-simulation', 'sb-visual-designer', 'sb-ai-capabilities',
			'sb-debugger-console', 'sb-perf-console', 'sb-addon-logs',
		];
		echo '<style>';
		foreach ( $hidden_slugs as $slug ) {
			echo '#adminmenu a[href*="page=' . esc_attr( $slug ) . '"] { display:none !important; }';
		}
		echo '</style>';
	}

	public static function render_operator_welcome(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'sovereign' ) === false ) { return; }
		echo '<div class="notice notice-info"><p>';
		echo '<strong>Sovereign Builder Operator View</strong> — ';
		echo 'You\'re in simplified mode. Developer tools are hidden. ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=sb-blueprints' ) ) . '">Manage Blueprints</a> | ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=sb-tiny-forms' ) ) . '">Forms</a> | ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=sb-submissions' ) ) . '">Submissions</a>';
		echo '</p></div>';
	}
}
SBGutenbergBlocks::init();