<?php
/**
 * SBEdgeCompiler — Atomic static page compilation with tenant sandboxing.
 * Writes compiled HTML to tenant-specific directories via WP_Filesystem.
 * Atomic write via temp+rename. Never throws PHP fatal on failure.
 *
 * @package SovereignBuilder
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBEdgeCompiler {

	const STATIC_BASE    = 'sovereign-static';
	const TELEMETRY_SCRIPT = '<script>if(window.sbTrack)window.sbTrack();</script>';

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		// Cache invalidation hooks
		add_action( 'save_post',            [ __CLASS__, 'handle_save_post' ], 20, 1 );
		add_action( 'update_option',        [ __CLASS__, 'handle_update_option' ], 20, 1 );
		add_action( 'sb_approval_approved', [ __CLASS__, 'handle_approval' ], 20, 1 );
	}

	public static function self_register( $loader ): void {
		$loader->register( 'edge-compiler', '1.0.0', __CLASS__ );
	}

	// ── Filesystem init ───────────────────────────────────────────────────────

	private static function get_filesystem(): WP_Filesystem_Base|false {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) { return false; }
		return $wp_filesystem;
	}

	// ── Tenant path ───────────────────────────────────────────────────────────

	public static function get_tenant_dir( ?int $site_id = null ): string {
		$site_id  = $site_id ?? ( is_multisite() ? get_current_blog_id() : 1 );
		$base_dir = wp_upload_dir()['basedir'];
		return trailingslashit( $base_dir . '/' . self::STATIC_BASE . '/tenant_' . $site_id );
	}

	public static function get_tenant_url( ?int $site_id = null ): string {
		$site_id  = $site_id ?? ( is_multisite() ? get_current_blog_id() : 1 );
		$base_url = wp_upload_dir()['baseurl'];
		return trailingslashit( $base_url . '/' . self::STATIC_BASE . '/tenant_' . $site_id );
	}

	// ── Core compilation ──────────────────────────────────────────────────────

	public static function compile_page_to_static( string $url_path, string $html_content ): bool|WP_Error {
		$fs = self::get_filesystem();
		if ( ! $fs ) {
			return new WP_Error( 'sb_edge_fs', 'WP_Filesystem unavailable.' );
		}

		$tenant_dir  = self::get_tenant_dir();
		$url_path    = trim( $url_path, '/' );
		$target_dir  = $tenant_dir . ( $url_path ? $url_path . '/' : '' );
		$target_file = $target_dir . 'index.html';
		$temp_file   = $target_dir . 'index.tmp.' . wp_generate_password( 8, false ) . '.html';

		// Ensure tenant directory exists with strict permissions
		if ( ! $fs->is_dir( $tenant_dir ) ) {
			if ( ! wp_mkdir_p( $tenant_dir ) ) {
				return new WP_Error( 'sb_edge_mkdir', 'Cannot create tenant directory: ' . $tenant_dir );
			}
		}
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'sb_edge_mkdir', 'Cannot create target directory: ' . $target_dir );
		}

		if ( ! $fs->is_writable( $target_dir ) ) {
			return new WP_Error( 'sb_edge_write', 'Target directory not writable: ' . $target_dir );
		}

		// Strip Gutenberg block comments and bloat
		$html_content = self::strip_gutenberg_bloat( $html_content );

		// Rewrite relative media paths to absolute tenant URLs
		$html_content = self::rewrite_media_paths( $html_content );

		// Append async tracking script before </body>
		$html_content = str_replace( '</body>', self::TELEMETRY_SCRIPT . '</body>', $html_content );

		// Inject watermark if active
		if ( class_exists( 'SBLicensingMatrix' ) && SBLicensingMatrix::is_watermark_active() ) {
			ob_start();
			SBLicensingMatrix::render_watermark();
			$watermark_html = ob_get_clean();
			$html_content   = str_replace( '</body>', $watermark_html . '</body>', $html_content );
		}

		// Write to temp file first — atomic operation
		if ( ! $fs->put_contents( $temp_file, $html_content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'sb_edge_write', 'Failed to write temp file: ' . $temp_file );
		}

		// Atomic rename — prevents partial-read corruption under high traffic
		if ( ! rename( $temp_file, $target_file ) ) {
			$fs->delete( $temp_file );
			return new WP_Error( 'sb_edge_rename', 'Atomic rename failed for: ' . $target_file );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_EDGE_COMPILED, "Static compiled: /{$url_path}", 0, [
			'target' => $target_file,
			'size'   => strlen( $html_content ),
		], 'info' );

		return true;
	}

	// ── HTML processing ───────────────────────────────────────────────────────

	private static function strip_gutenberg_bloat( string $html ): string {
		// Remove Gutenberg HTML comments
		$html = preg_replace( '/<!--\s*(wp:|\/wp:)[^>]*-->/i', '', $html );
		// Remove empty paragraphs left behind
		$html = preg_replace( '/<p>\s*<\/p>/i', '', $html );
		return $html;
	}

	private static function rewrite_media_paths( string $html ): string {
		$tenant_url  = self::get_tenant_url();
		$uploads_url = wp_upload_dir()['baseurl'];

		// Rewrite relative src and href attributes to absolute upload URLs
		$html = preg_replace_callback(
			'/\b(src|href)=["\'](?!https?:\/\/|\/\/|data:|#|mailto:)([^"\']+)["\']/i',
			function( $matches ) use ( $uploads_url ) {
				$attr  = $matches[1];
				$path  = ltrim( $matches[2], '/' );
				$abs   = $uploads_url . '/' . $path;
				return $attr . '="' . esc_url( $abs ) . '"';
			},
			$html
		);

		return $html;
	}

	// ── htaccess integration ──────────────────────────────────────────────────

	public static function write_edge_interception_rules( ?int $site_id = null ): bool|WP_Error {
		$fs = self::get_filesystem();
		if ( ! $fs ) {
			return new WP_Error( 'sb_edge_fs', 'WP_Filesystem unavailable.' );
		}

		$htaccess_path = ABSPATH . '.htaccess';
		if ( ! $fs->exists( $htaccess_path ) ) {
			return new WP_Error( 'sb_edge_htaccess', '.htaccess not found — may be Nginx server.' );
		}
		if ( ! $fs->is_writable( $htaccess_path ) ) {
			return new WP_Error( 'sb_edge_htaccess', '.htaccess not writable.' );
		}

		$site_id    = $site_id ?? ( is_multisite() ? get_current_blog_id() : 1 );
		$static_rel = 'wp-content/uploads/' . self::STATIC_BASE . '/tenant_' . $site_id;
		$marker     = '# BEGIN SovereignEdge';
		$end_marker = '# END SovereignEdge';

		$rules  = $marker . "\n";
		$rules .= "<IfModule mod_rewrite.c>\n";
		$rules .= "RewriteEngine On\n";
		// Never intercept WP core files, admin, or REST API
		$rules .= "RewriteCond %{REQUEST_URI} !^/wp-admin [NC]\n";
		$rules .= "RewriteCond %{REQUEST_URI} !^/wp-json [NC]\n";
		$rules .= "RewriteCond %{REQUEST_URI} !^/wp-login [NC]\n";
		$rules .= "RewriteCond %{REQUEST_URI} !wp-cron\.php [NC]\n";
		$rules .= "RewriteCond %{REQUEST_URI} !xmlrpc\.php [NC]\n";
		// Only serve static if file exists for this tenant
		$rules .= "RewriteCond %{DOCUMENT_ROOT}/{$static_rel}%{REQUEST_URI}/index.html -f\n";
		$rules .= "RewriteRule ^(.*)$ /{$static_rel}/$1/index.html [L,QSA]\n";
		$rules .= "</IfModule>\n";
		$rules .= $end_marker . "\n";

		// Read existing htaccess
		$existing = $fs->get_contents( $htaccess_path );

		// Remove old SovereignEdge block
		$existing = preg_replace(
			'/' . preg_quote( $marker, '/' ) . '.*?' . preg_quote( $end_marker, '/' ) . '\n?/s',
			'',
			$existing
		);

		// Inject at line zero (before everything else)
		$new_content = $rules . $existing;

		if ( ! $fs->put_contents( $htaccess_path, $new_content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'sb_edge_htaccess_write', 'Failed to write .htaccess.' );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_EDGE_HTACCESS_WRITTEN, "Edge rewrite rules written for tenant {$site_id}.", 0, [], 'info' );
		return true;
	}

	public static function clear_edge_rules( ?int $site_id = null ): bool|WP_Error {
		$fs = self::get_filesystem();
		if ( ! $fs ) { return new WP_Error( 'sb_edge_fs', 'WP_Filesystem unavailable.' ); }

		$htaccess_path = ABSPATH . '.htaccess';
		if ( ! $fs->exists( $htaccess_path ) || ! $fs->is_writable( $htaccess_path ) ) {
			return new WP_Error( 'sb_edge_htaccess', '.htaccess not found or not writable.' );
		}

		$marker     = '# BEGIN SovereignEdge';
		$end_marker = '# END SovereignEdge';
		$existing   = $fs->get_contents( $htaccess_path );

		$cleaned = preg_replace(
			'/' . preg_quote( $marker, '/' ) . '.*?' . preg_quote( $end_marker, '/' ) . '\n?/s',
			'',
			$existing
		);

		// Verify WordPress default rules are intact before writing
		if ( false === strpos( $cleaned, '# BEGIN WordPress' ) ) {
			// WordPress rules missing — restore them safely
			$wp_rules  = "# BEGIN WordPress\n";
			$wp_rules .= "<IfModule mod_rewrite.c>\n";
			$wp_rules .= "RewriteEngine On\n";
			$wp_rules .= "RewriteBase /\n";
			$wp_rules .= "RewriteRule ^index\.php$ - [L]\n";
			$wp_rules .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
			$wp_rules .= "RewriteCond %{REQUEST_FILENAME} !-d\n";
			$wp_rules .= "RewriteRule . /index.php [L]\n";
			$wp_rules .= "</IfModule>\n";
			$wp_rules .= "# END WordPress\n";
			$cleaned   = $wp_rules . $cleaned;
		}

		if ( ! $fs->put_contents( $htaccess_path, $cleaned, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'sb_edge_htaccess_write', 'Failed to restore .htaccess.' );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_EDGE_RULES_CLEARED, 'Edge rewrite rules removed, WordPress rules verified.', 0, [], 'info' );
		return true;
	}

	// ── Cache invalidation — debounced ────────────────────────────────────────

	private static array $invalidation_queue = [];

	public static function handle_save_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			self::queue_invalidation( wp_make_link_relative( $permalink ) );
		}
	}

	public static function handle_update_option( string $option ): void {
		// Only invalidate on sovereign-relevant option changes
		if ( strpos( $option, 'sb_' ) === 0 ) {
			self::queue_invalidation( '/' ); // invalidate home as fallback
		}
	}

	public static function handle_approval( $approval_id ): void {
		self::queue_invalidation( '/' );
	}

	public static function queue_invalidation( string $path ): void {
		self::$invalidation_queue[] = $path;
		// Debounce — only register shutdown once
		static $registered = false;
		if ( ! $registered ) {
			register_shutdown_function( [ __CLASS__, 'flush_invalidation_queue' ] );
			$registered = true;
		}
	}

	public static function flush_invalidation_queue(): void {
		if ( empty( self::$invalidation_queue ) ) { return; }
		$paths = array_unique( self::$invalidation_queue );
		foreach ( $paths as $path ) {
			self::purge_static_path( $path );
		}
		self::$invalidation_queue = [];
	}

	public static function purge_static_path( string $path ): bool {
		$fs = self::get_filesystem();
		if ( ! $fs ) { return false; }

		$tenant_dir  = self::get_tenant_dir();
		$path        = trim( $path, '/' );
		$target_dir  = $tenant_dir . ( $path ? $path : '' );
		$target_file = trailingslashit( $target_dir ) . 'index.html';

		if ( $fs->exists( $target_file ) ) {
			$fs->delete( $target_file );
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_EDGE_CACHE_PURGED, "Static cache purged: /{$path}", 0, [], 'verbose' );
		}
		return true;
	}

	// ── Nginx notice ──────────────────────────────────────────────────────────

	public static function get_nginx_config_notice( ?int $site_id = null ): string {
		$site_id    = $site_id ?? 1;
		$static_rel = 'wp-content/uploads/' . self::STATIC_BASE . '/tenant_' . $site_id;
		return "# Add inside your server {} block:\n"
			 . "location / {\n"
			 . "    set \$static_file \$document_root/{$static_rel}\$uri/index.html;\n"
			 . "    if (-f \$static_file) {\n"
			 . "        rewrite ^ /{$static_rel}\$uri/index.html last;\n"
			 . "    }\n"
			 . "    try_files \$uri \$uri/ /index.php\$is_args\$args;\n"
			 . "}\n";
	}
}
SBEdgeCompiler::init();