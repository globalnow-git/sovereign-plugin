<?php
/**
 * Plugin Name: Sovereign Builder
 * Description: Version 1.1.0 — Complete operator platform with blueprint system, schema engine, event fabric, AI debugger, performance console, and full remediation of v1.0.3.
 * Version:     1.1.0
 * Author:      Sovereign Architect
 * Text Domain: sovereign-builder
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SB_VERSION',  '1.1.0' );
define( 'SB_PATH',     plugin_dir_path( __FILE__ ) );
define( 'SB_URL',      plugin_dir_url( __FILE__ ) );
define( 'SB_BASENAME', plugin_basename( __FILE__ ) );

$sb_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
if ( $sb_host && ! defined( 'SB_ACTIVE_DOMAIN' ) ) {
	define( 'SB_ACTIVE_DOMAIN', $sb_host );
}

// Priority load order — foundational before glob
$sb_load_first = [
	'includes/class-extension-api.php',
	'includes/class-installer.php',
	'includes/class-module-loader.php',
	'includes/class-event-logger.php',
	'includes/class-telemetry-buffer.php',
];
foreach ( $sb_load_first as $sb_priority_file ) {
	require_once SB_PATH . $sb_priority_file;
}

// Remaining core includes
foreach ( glob( SB_PATH . 'includes/class-*.php' ) as $sb_core_file ) {
	require_once $sb_core_file;
}

// Advanced modules (Ask3 + Ask4 + Ask5)
foreach ( glob( SB_PATH . 'advanced/class-*.php' ) as $sb_adv_file ) {
	require_once $sb_adv_file;
}


// ── SBRoutePolicy ─────────────────────────────────────────────────────────────
// Centralised permission and rate-limiting helpers for REST routes.
// Route classification reference:
//   admin-auth     : requires manage_sovereign or higher capability
//   operator-auth  : requires run_sovereign_factory or lower sovereign cap
//   public-signal  : __return_true — hardened via payload gates and rate limits
//   signed-webhook : __return_true — hardened via provider signature + timestamp + replay

class SBRoutePolicy {

	/**
	 * Transient-based per-IP rate limiter. Returns true if the caller is within limits,
	 * false if the limit has been exceeded. Identical pattern to telemetry ingest.
	 *
	 * @param string $action     Unique action key (e.g. 'form_submit', 'game_signal').
	 * @param int    $max        Max requests per window.
	 * @param int    $window     Window in seconds (default 3600 = 1 hour).
	 * @return bool              true = allow, false = rate-limited.
	 */
	public static function public_rate_limit( string $action, int $max = 60, int $window = 3600 ): bool {
		$ip  = sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		$ip  = explode( ',', $ip )[0]; // take first address only
		$key = 'sbrl_' . md5( $action . '|' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Validate a signed-webhook request: HMAC, timestamp tolerance, and replay protection.
	 * For generic SB connectors using X-SB-Signature + X-SB-Timestamp headers.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $secret          Pre-shared connector secret.
	 * @param string          $connector_slug  Used as part of the replay dedup key.
	 * @param int             $tolerance       Max clock skew in seconds (default 300 = 5 min).
	 * @return true|WP_Error
	 */
	public static function validate_signed_webhook(
		WP_REST_Request $request,
		string $secret,
		string $connector_slug,
		int $tolerance = 300
	): bool|WP_Error {
		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'X-SB-Signature' );
		$timestamp = (string) $request->get_header( 'X-SB-Timestamp' );

		// 1. HMAC
		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'sig_invalid', 'Webhook signature invalid.', [ 'status' => 401 ] );
		}

		// 2. Timestamp tolerance (only enforced when header is present)
		if ( $timestamp !== '' ) {
			$ts = (int) $timestamp;
			if ( $ts === 0 || abs( time() - $ts ) > $tolerance ) {
				return new WP_Error( 'ts_stale', 'Webhook timestamp out of tolerance window.', [ 'status' => 401 ] );
			}

			// 3. Replay protection: deduplicate on connector + body hash within tolerance window
			$replay_key = 'sbrp_' . md5( $connector_slug . '|' . hash( 'sha256', $body ) );
			if ( get_transient( $replay_key ) ) {
				return new WP_Error( 'replay_detected', 'Duplicate webhook payload rejected.', [ 'status' => 409 ] );
			}
			set_transient( $replay_key, 1, $tolerance );
		}

		return true;
	}
}

class SovereignBuilder {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->bootstrap_hooks();
	}

	private function bootstrap_hooks() {
		add_action( 'init',                  [ $this, 'init' ] );
		add_action( 'admin_menu',            [ $this, 'admin_menu' ] );
		add_action( 'admin_notices',         [ 'SB_Installer', 'maybe_show_repair_notice' ] ); // Startup health check
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'rest_api_init',         [ $this, 'register_rest_routes' ] );

		// WooCommerce signal
		add_action( 'woocommerce_order_status_completed', [ 'SB_Signal_Engine', 'on_wc_order_complete' ], 10, 1 );

		// Ask5 debugger cron handler (BLOCKER-004 fix)
		// Canonical health check owner: SBDebuggerConsole (has CRON_HOOKS validation + full snapshot)
		add_action( 'sb_debug_health_check', [ 'SBDebuggerConsole', 'run_scheduled_health_check' ] );

		// Ask5 connector retry cron
		add_action( 'sb_connector_retry_cron', [ 'SovereignEvents', 'run_retry_queue' ] );

		// Ask5 performance snapshot cron
		add_action( 'sb_perf_snapshot_cron', [ 'SBPerfConsole', 'run_daily_snapshot' ] );

		// Ask5 shell init
		add_action( 'admin_init', [ 'SBOperatorShell', 'init' ] );

		// ASK5.5 Phase A — regulated workflow substrate
		add_action( 'init', [ 'SBStorePolicy',       'init' ], 5  );
		add_action( 'init', [ 'SBProposalAuthority',  'init' ], 15 );
		add_action( 'init', [ 'SBAuditLedgerPlus',    'init' ], 15 );
		add_action( 'init', [ 'SBCommitGate',         'init' ], 15 );

		// ASK5.5 Phase B — evidence and review workspace
		add_action( 'init', [ 'SBEvidenceVault',      'init' ], 15 );
		add_action( 'init', [ 'SBKynvaricWorkspace',  'init' ], 15 );

		// ASK5.5 Phase C — entitlement and connectors
		add_action( 'init', [ 'SBEntitlementEngine',  'init' ], 15 );
		add_action( 'init', [ 'SBPlaidConnector',      'init' ], 15 );

		// ASK5.5 v2.3 — build map materializer
		add_action( 'init', [ 'SBBuildMapMaterializer', 'init' ], 15 );

		// Ask5 front-end placement mounting
		add_action( 'wp_footer', [ 'SBPlacementEngine', 'mount' ] );

		// Licensing watermark on frontend
		add_action( 'wp_footer', [ 'SBLicensingMatrix', 'render_watermark' ], 100 );

		// License daily ping cron handler
		add_action( 'sb_daily_license_ping', [ 'SBLicensingMatrix', 'run_daily_ping' ] );

		// Telemetry opt-in AJAX
		add_action( 'wp_ajax_sb_save_telemetry_opt', function() {
			if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }
			check_ajax_referer( 'wp_rest', '_wpnonce' ); // CSRF protection — nonce verified before mutation
			$val = sanitize_key( $_POST['opted'] ?? '0' );
			update_option( SBLicensingMatrix::OPTION_TELEMETRY_OPT, $val === '1' ? '1' : '0' );
			wp_send_json_success( [ 'opted' => $val ] );
		} );

		// Ask5 shortcodes
		add_shortcode( 'sb_form',    [ 'SBTinyFormEngine',   'shortcode_handler' ] );
		add_shortcode( 'sb_surface', [ 'SBUISurfaceEngine',  'shortcode_handler' ] );
		add_shortcode( 'sb_view',    [ 'SBAdminViewRenderer','shortcode_handler' ] );

		// Frontend asset lockdown
		add_action( 'wp_enqueue_scripts', [ $this, 'lockdown_frontend_bloat' ], 99 );
	}

	public function init() {
		SB_Installer::maybe_update();
		do_action( 'sb_modules_register', SB_Module_Loader::get_instance() );
		SB_Many_Roads::register_as_listener();
	}

	public function admin_menu() {
		SB_UI_Router::paint_menus();
		$extra_items = apply_filters( 'sb_admin_menu_items', [] );
		foreach ( $extra_items as $item ) {
			if ( isset( $item['callback'] ) && is_callable( $item['callback'] ) ) {
				add_submenu_page(
					'sovereign-builder',
					$item['title'],
					$item['menu_title'],
					$item['capability'],
					$item['slug'],
					$item['callback']
				);
			}
		}
	}

	public function admin_assets( $hook ) {
		// Tightened: 'sb-' must appear after 'page_' to avoid loading on unrelated plugins with 'sb-' prefix
		$is_sb_page = false !== strpos( $hook, 'sovereign-builder' )
		           || false !== strpos( $hook, 'marketing-hq' )
		           || false !== strpos( $hook, '_page_sb-' )   // submenu pages: sovereign-builder_page_sb-*
		           || false !== strpos( $hook, 'toplevel_page_sb-' ); // top-level sb-* menus
		if ( ! $is_sb_page ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'sb-admin-css', SB_URL . 'assets/admin.css', [], SB_VERSION );
		wp_enqueue_script( 'sb-admin-js', SB_URL . 'assets/admin.js', [ 'jquery' ], SB_VERSION, true );
		wp_enqueue_script( 'sb-shell-js', SB_URL . 'assets/shell.js', [ 'jquery', 'sb-admin-js' ], SB_VERSION, true );

		wp_localize_script( 'sb-admin-js', 'sbAdminContext', [
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'restBase'   => esc_url_raw( get_rest_url( null ) ),
			'adminEmail' => get_option( 'admin_email' ),
			'postId'     => absint( $_GET['post'] ?? 0 ),
			'version'    => SB_VERSION,
		] );
	}

	public function register_rest_routes() {
		// ── Ask4 routes (preserved) ──────────────────────────────────────────
		register_rest_route( 'sovereign-builder/v1', '/run-factory', [
			'methods'             => 'POST',
			'callback'            => [ 'SB_Factory_API', 'handle_rest_run_factory' ],
			'permission_callback' => fn() => current_user_can( 'run_sovereign_factory' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/generate-docx', [
			'methods'             => 'POST',
			'callback'            => [ 'SB_Export_Generator', 'handle_rest_generate' ],
			'permission_callback' => fn() => current_user_can( 'review_sovereign_outputs' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/signal', [
			'methods'             => 'POST',
			'callback'            => [ 'SB_Signal_Engine', 'handle_rest_signal' ],
			'permission_callback' => function( $request ) {
				$nonce   = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );
				if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) { return true; }
				$public_ok = [ 'content_consumed', 'video_played', 'podcast_listened' ];
				$event     = sanitize_key( ( (array) $request->get_json_params() )['signal_type'] ?? '' );
				return in_array( $event, $public_ok, true );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/factory-progress/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ 'SB_Factory_API', 'handle_rest_progress' ],
			'permission_callback' => fn() => current_user_can( 'review_sovereign_outputs' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/approve', [
			'methods'             => 'POST',
			'callback'            => [ 'SB_Approval_Engine', 'handle_rest_approve' ],
			'permission_callback' => fn() => current_user_can( 'review_sovereign_outputs' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/track/open/(?P<token>[a-zA-Z0-9]{32})', [
			'methods'             => 'GET',
			'callback'            => [ 'SB_Many_Roads', 'handle_email_open_tracking' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( 'sovereign-builder/v1', '/track/click/(?P<token>[a-zA-Z0-9]{32})/(?P<url>.+)', [
			'methods'             => 'GET',
			'callback'            => [ 'SB_Many_Roads', 'handle_email_click_tracking' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( 'sovereign-builder/v1', '/preview-email', [
			'methods'             => 'POST',
			'callback'            => [ 'SB_Many_Roads', 'handle_rest_preview_email' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/settings', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'callback'            => function( $request ) {
				$allowed = SB_Installer::get_settings_allowlist();
				$params  = (array) $request->get_json_params();
				$updated = [];
				foreach ( $allowed as $key => $sanitizer ) {
					if ( array_key_exists( $key, $params ) ) {
						$clean = call_user_func( $sanitizer, $params[ $key ] );
						SB_Extension_API::set_setting( $key, $clean );
						$updated[] = $key;
					}
				}
				$unknown = array_diff( array_keys( $params ), array_keys( $allowed ), [ '_wpnonce' ] );
				if ( ! empty( $unknown ) ) {
					return SB_Extension_API::rest_error( 'unknown_keys', 'Unknown settings keys rejected.', 400, [ 'keys' => array_values( $unknown ) ] );
				}
				return rest_ensure_response( [ 'success' => true, 'updated' => $updated ] );
			},
		] );
		// ── Route classifications ────────────────────────────────────────────────
		// public-signal  : game/signal, telemetry/ingest, track/open, track/click,
		//                  placement/for-context, podcast/feed.rss, form/submit
		// signed-webhook : events/inbound (X-SB-Signature), plaid-webhook (JWT/JWK)
		// operator-auth  : run-factory, approve, audit-stream, ai-capability/*
		// admin-auth     : repair-system, settings, seed-blueprints, build-map-materialize
		// ─────────────────────────────────────────────────────────────────────────

		// [public-signal] game/signal — 30/hr per-IP, 1KB cap, event_type allowlist
		register_rest_route( 'sovereign-builder/v1', '/game/signal', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function( WP_REST_Request $request ) {
				// Body size cap: 1KB
				if ( strlen( $request->get_body() ) > 1024 ) {
					return new WP_REST_Response( [ 'error' => 'Payload too large.' ], 413 );
				}
				// Rate limit: 30 signals/hr per IP
				if ( ! SBRoutePolicy::public_rate_limit( 'game_signal', 30 ) ) {
					return new WP_REST_Response( [ 'error' => 'Rate limit exceeded.' ], 429 );
				}
				// Event type allowlist — only known signal types
				$allowed_events = [
					'game_event', 'game_start', 'game_end', 'game_score',
					'game_level', 'game_achievement', 'game_error',
				];
				$params = (array) $request->get_json_params();
				$event  = sanitize_key( $params['event_type'] ?? 'game_event' );
				if ( ! in_array( $event, $allowed_events, true ) ) {
					$event = 'game_event'; // normalise unknown types rather than reject
				}
				if ( class_exists( 'SB_Game' ) && method_exists( 'SB_Game', 'handle_telemetry' ) ) {
					return SB_Game::handle_telemetry( $request );
				}
				if ( class_exists( 'SB_Signal_Engine' ) ) {
					SB_Signal_Engine::record_signal( 0, $event, 1.0, get_current_user_id() );
				}
				return rest_ensure_response( [ 'success' => true ] );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/repair-system', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => function() {
				$operator = get_current_user_id();
				$report   = [];
				$errors   = [];

				// Capture before-state
				$before = SBFixRegistry::snapshot();

				// Run all repair operations via the registry
				$repair_sequence = [
					'repair_tables',
					'repair_capabilities',
					'reschedule_cron',
					'seed_signal_definitions',
					'repair_ask55_tables',
				];
				foreach ( $repair_sequence as $fix_id ) {
					$result = SBFixRegistry::execute( $fix_id );
					if ( is_wp_error( $result ) ) {
						$errors[]           = $fix_id . ': ' . $result->get_error_message();
						$report[ $fix_id ]  = 'ERROR: ' . $result->get_error_message();
					} else {
						$report[ $fix_id ] = 'OK';
					}
				}

				// Update DB version stamp
				update_option( SB_Installer::DB_VERSION_OPTION, SB_Installer::DB_VERSION );
				$report['version'] = SB_Installer::DB_VERSION;

				// Capture after-state
				$after = SBFixRegistry::snapshot();

				// Audit log — repair-system is a privileged operation
				SB_Event_Logger::log_audit(
					SB_Event_Keys::EV_REPAIR_SYSTEM_RAN,
					'repair-system executed by operator ' . $operator,
					$operator,
					[ 'report' => $report, 'errors' => $errors, 'before' => $before, 'after' => $after ],
					empty( $errors ) ? 'info' : 'warning'
				);

				return rest_ensure_response( [
					'success'      => empty( $errors ),
					'report'       => $report,
					'errors'       => $errors,
					'state_before' => $before,
					'state_after'  => $after,
				] );
			},
		] );

		// Telemetry ingest REST route — 2KB hard gate (Section 4 security)
		// Public telemetry ingest — intentionally unauthenticated for front-end JS signal tracking.
		// Rate-limited (60/hr per IP), 2KB hard gate, key allowlist, and context depth cap prevent abuse.
		// If you add a shared secret/token scheme, set permission_callback here instead.
		register_rest_route( 'sovereign-builder/v1', '/telemetry/ingest', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // Intentional: public endpoint, hardened via payload gates below
			'callback'            => function( WP_REST_Request $request ) {
				// 2KB size gate + rate limit + key allowlist + context depth cap
				$max_bytes = (int) get_option( 'sb_telemetry_max_payload_bytes', 2048 );

				// 1. Size gate
				if ( strlen( $request->get_body() ) > $max_bytes ) {
					return new WP_REST_Response( [ 'error' => 'Payload exceeds 2KB limit.' ], 413 );
				}

				// 2. Rate limit — max 60 requests per IP per hour via transient
				// X-Forwarded-For checked first for reverse-proxy/load-balancer installs
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
				$ip        = $forwarded ? strtok( $forwarded, ',' ) : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
				$ip        = trim( $ip );
				$rate_key   = 'sb_tel_rate_' . md5( $ip );
				$rate_count = (int) get_transient( $rate_key );
				if ( $rate_count >= 60 ) {
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_TELEMETRY_RATE_LIMITED, "Telemetry rate limit hit for IP hash: " . substr( md5( $ip ), 0, 8 ), 0, [], 'warning' );
					return new WP_REST_Response( [ 'error' => 'Rate limit exceeded.' ], 429 );
				}
				set_transient( $rate_key, $rate_count + 1, HOUR_IN_SECONDS );

				// 3. JSON validation
				$data = $request->get_json_params();
				if ( ! is_array( $data ) ) {
					return new WP_REST_Response( [ 'error' => 'Invalid JSON.' ], 400 );
				}

				// 4. Key allowlist — reject unknown keys
				$allowed_keys = [ 'action', 'message', 'context', 'user_id', 'timestamp' ];
				$unknown_keys = array_diff( array_keys( $data ), $allowed_keys );
				if ( ! empty( $unknown_keys ) ) {
					return new WP_REST_Response( [ 'error' => 'Unknown keys: ' . implode( ', ', $unknown_keys ) ], 400 );
				}

				// 5. Context depth cap — max 2 levels, max 10 keys, no nested arrays
				$context = $data['context'] ?? [];
				if ( ! is_array( $context ) || count( $context ) > 10 ) {
					return new WP_REST_Response( [ 'error' => 'Context must be flat array with max 10 keys.' ], 400 );
				}
				foreach ( $context as $v ) {
					if ( is_array( $v ) ) {
						return new WP_REST_Response( [ 'error' => 'Context values must be scalar — no nested arrays.' ], 400 );
					}
					// Cast to string first — integers from json_decode would bypass string length check otherwise
					$v_str = (string) $v;
					if ( strlen( $v_str ) > 255 ) {
						return new WP_REST_Response( [ 'error' => 'Context values max 255 chars.' ], 400 );
					}
				}

				// 6. Sanitize and push
				SB_Telemetry_Buffer::push(
					sanitize_key( $data['action'] ?? 'telemetry_ingest' ),
					sanitize_textarea_field( $data['message'] ?? '' ),
					absint( $data['user_id'] ?? 0 ),
					array_map( 'sanitize_text_field', $context ),
					'verbose'
				);
				return new WP_REST_Response( [ 'received' => true ], 200 );
			},
			'permission_callback' => '__return_true',
		] );

				// Ask5 Portability routes (Section 26 — SBPortabilityManager)
		register_rest_route( 'sovereign-builder/v1', '/portability/export', [
			'methods'             => 'POST',
			'callback'            => [ 'SBPortabilityManager', 'handle_rest_export' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/portability/validate', [
			'methods'             => 'POST',
			'callback'            => [ 'SBPortabilityManager', 'handle_rest_validate' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/portability/import', [
			'methods'             => 'POST',
			'callback'            => [ 'SBPortabilityManager', 'handle_rest_import' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/audit-stream', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'view_sovereign_audit_logs' ),
			'callback'            => function( $request ) {
				global $wpdb;
				$since   = absint( $request->get_param( 'since' ) );
				$results = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, created_at, log_level, action, message
					 FROM {$wpdb->prefix}sb_audit_log
					 WHERE id > %d ORDER BY id ASC LIMIT 50",
					$since
				) );
				return rest_ensure_response( $results );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/podcast/feed.rss', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function() {
				if ( class_exists( 'SB_Podcast' ) ) { return SB_Podcast::generate_rss_feed(); }
				return SB_Extension_API::rest_error( 'module_not_loaded', 'Podcast module not loaded.', 503 );
			},
		] );

		// ── Ask5 routes ──────────────────────────────────────────────────────
		// AI Integrator
		register_rest_route( 'sovereign-builder/v1', '/ai-capability/invoke', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'run_sovereign_factory' ),
			'callback'            => [ 'SBAIIntegrator', 'handle_rest_invoke' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/ai-capability/list', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAIIntegrator', 'handle_rest_list' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/ai-capability/register', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAIIntegrator', 'handle_rest_register' ],
		] );

		// Blueprints
		register_rest_route( 'sovereign-builder/v1', '/blueprint/install', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_install' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/activate', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_activate' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/deactivate', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_deactivate' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/upgrade', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_upgrade' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/inspect/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_inspect' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/export/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_export' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/import', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => [ 'SBAppBlueprintManager', 'handle_rest_import' ],
		] );

		// Visual Designer
		register_rest_route( 'sovereign-builder/v1', '/visual-designer/graph/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBVisualDesigner', 'handle_rest_graph' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/visual-designer/runtime-map/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBVisualDesigner', 'handle_rest_runtime_map' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/visual-designer/placement-map', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBVisualDesigner', 'handle_rest_placement_map' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/visual-designer/edit', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => [ 'SBVisualDesigner', 'handle_rest_edit' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/visual-designer/impact-preview', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBVisualDesigner', 'handle_rest_impact_preview' ],
		] );

		// View Schemas
		register_rest_route( 'sovereign-builder/v1', '/view-schema/list', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAdminViewRenderer', 'handle_rest_list_schemas' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/view-schema/(?P<slug>[a-z0-9\-_]+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAdminViewRenderer', 'handle_rest_get_schema' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/view-schema', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBAdminViewRenderer', 'handle_rest_create_schema' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/view-schema/(?P<slug>[a-z0-9\-_]+)/render', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBAdminViewRenderer', 'handle_rest_render' ],
		] );

		// Schema Designer
		register_rest_route( 'sovereign-builder/v1', '/schema-designer/palette', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBSchemaDesigner', 'handle_rest_palette' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/schema-designer/draft', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBSchemaDesigner', 'handle_rest_draft' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/schema-designer/preview', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBSchemaDesigner', 'handle_rest_preview' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/schema-designer/publish', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBSchemaDesigner', 'handle_rest_publish' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/schema-designer/archive', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBSchemaDesigner', 'handle_rest_archive' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/schema-designer/versions/(?P<slug>[a-z0-9\-_]+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_schemas' ),
			'callback'            => [ 'SBSchemaDesigner', 'handle_rest_versions' ],
		] );

		// Sovereign Events
		register_rest_route( 'sovereign-builder/v1', '/events/inbound/(?P<connector>[a-z0-9\-_]+)', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ 'SovereignEvents', 'handle_inbound' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/events/list', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SovereignEvents', 'handle_rest_list' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/events/emit', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SovereignEvents', 'emit' ],
		] );

		// Connector Health
		register_rest_route( 'sovereign-builder/v1', '/connector/status', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBConnectorHealthConsole', 'handle_rest_status' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/connector/(?P<slug>[a-z0-9\-_]+)/events', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBConnectorHealthConsole', 'get_recent_events' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/connector/(?P<slug>[a-z0-9\-_]+)/failures', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBConnectorHealthConsole', 'get_failures' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/connector/replay', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBConnectorHealthConsole', 'replay_event' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/connector/rotate-credentials', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBConnectorHealthConsole', 'rotate_credentials' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/connector/(?P<slug>[a-z0-9\-_]+)/impact', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBConnectorHealthConsole', 'get_dependency_impact' ],
		] );

		// Tiny Forms
		// [public-signal] form/submit — 60/hr per-IP, 4KB cap; field validation inside SBTinyFormEngine::submit()
		register_rest_route( 'sovereign-builder/v1', '/form/submit', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function( WP_REST_Request $request ) {
				// Body size cap: 4KB
				if ( strlen( $request->get_body() ) > 4096 ) {
					return new WP_REST_Response( [ 'error' => 'Payload too large.' ], 413 );
				}
				// Rate limit: 60 submissions/hr per IP (matches telemetry ingest)
				if ( ! SBRoutePolicy::public_rate_limit( 'form_submit', 60 ) ) {
					return new WP_REST_Response( [ 'error' => 'Rate limit exceeded. Please wait before resubmitting.' ], 429 );
				}
				$params = (array) $request->get_json_params();
				$slug   = sanitize_key( $params['slug'] ?? '' );
				if ( ! $slug ) {
					return new WP_REST_Response( [ 'error' => 'Form slug required.' ], 400 );
				}
				$data = (array) ( $params['data'] ?? [] );
				$uid  = get_current_user_id();
				$result = SBTinyFormEngine::submit( $slug, $data, $uid );
				if ( is_wp_error( $result ) ) {
					return new WP_REST_Response(
						[ 'error' => $result->get_error_message() ],
						$result->get_error_data( 'status' ) ?? 422
					);
				}
				return rest_ensure_response( $result );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/form/preview', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_forms' ),
			'callback'            => [ 'SBTinyFormEngine', 'handle_rest_preview' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/form/validate', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_forms' ),
			'callback'            => [ 'SBTinyFormEngine', 'handle_rest_validate' ],
		] );

		// Release Manager
		register_rest_route( 'sovereign-builder/v1', '/release/stage/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBReleaseManager', 'stage' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/release/activate/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBReleaseManager', 'handle_rest_activate' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/release/rollback/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBReleaseManager', 'rollback' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/release/history/(?P<type>[a-z_]+)/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBReleaseManager', 'get_release_history' ],
		] );

		// Simulation
		register_rest_route( 'sovereign-builder/v1', '/sim/(?P<type>[a-z_]+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBSimulationEngine', 'handle_rest_simulate' ],
		] );

		// Dependency Graph
		register_rest_route( 'sovereign-builder/v1', '/dep-graph/build', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBDependencyGraph', 'handle_rest_build' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/dep-graph/upstream/(?P<type>[a-z_]+)/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBDependencyGraph', 'handle_rest_upstream' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/dep-graph/downstream/(?P<type>[a-z_]+)/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBDependencyGraph', 'handle_rest_downstream' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/dep-graph/impact', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBDependencyGraph', 'handle_rest_impact' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/dep-graph/trace/(?P<slug>[a-z0-9\-_]+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBDependencyGraph', 'handle_rest_trace' ],
		] );

		// Debugger Console
		register_rest_route( 'sovereign-builder/v1', '/debugger/scan/(?P<scope>[a-z_]+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => [ 'SBDebuggerConsole', 'handle_rest_scan' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/debugger/findings', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => [ 'SBDebuggerConsole', 'handle_rest_findings' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/debugger/finding/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => [ 'SBDebuggerConsole', 'handle_rest_finding_detail' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/debugger/remediate/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => [ 'SBDebuggerConsole', 'handle_rest_remediate' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/debugger/apply-fix', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => [ 'SBDebuggerConsole', 'handle_rest_apply_fix' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/debugger/verify/(?P<id>\d+)', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_debug' ),
			'callback'            => [ 'SBDebuggerConsole', 'handle_rest_verify' ],
		] );

		// Performance Console
		register_rest_route( 'sovereign-builder/v1', '/perf/snapshot', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBPerfConsole', 'take_snapshot' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/perf/hotspots', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBPerfConsole', 'get_query_hotspots' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/perf/plugin-impact', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBPerfConsole', 'get_plugin_impact' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/perf/set-threshold', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBPerfConsole', 'set_threshold' ],
		] );
		register_rest_route( 'sovereign-builder/v1', '/perf/regressions', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => [ 'SBPerfConsole', 'detect_regressions' ],
		] );

		// Placement
		register_rest_route( 'sovereign-builder/v1', '/placement/for-context', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ 'SBPlacementEngine', 'get_placements_for_context' ],
		] );
	}

	public function register_settings() {
		foreach ( SB_Installer::get_settings_allowlist() as $key => $sanitizer ) {
			register_setting( 'sb_core_settings_group', $key, [
				'sanitize_callback' => $sanitizer,
			] );
		}
	}

	public function lockdown_frontend_bloat() {
		if ( ! apply_filters( 'sb_lockdown_wc_assets', true ) ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && function_exists( 'is_account_page' ) ) {
			$is_commerce = is_checkout() || is_account_page()
				|| ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_product' ) && is_product() )
				|| ( function_exists( 'is_shop' ) && is_shop() );
			if ( ! $is_commerce ) {
				wp_dequeue_script( 'wc-cart-fragments' );
				wp_dequeue_style( 'woocommerce-general' );
				wp_dequeue_style( 'woocommerce-layout' );
				wp_dequeue_style( 'woocommerce-smallscreen' );
			}
		}
	}
}

// Cron interval
add_filter( 'cron_schedules', function( $schedules ) {
	if ( ! isset( $schedules['every_5_minutes'] ) ) {
		$schedules['every_5_minutes'] = [ 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 Minutes' ];
	}
	if ( ! isset( $schedules['every_15_minutes'] ) ) {
		$schedules['every_15_minutes'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 Minutes' ];
	}
	return $schedules;
} );

register_activation_hook( __FILE__, function() {
	require_once SB_PATH . 'includes/class-extension-api.php';
	require_once SB_PATH . 'includes/class-installer.php';
	SB_Installer::run_on_activation();
} );

register_uninstall_hook( __FILE__, [ 'SB_Installer', 'run_on_uninstall' ] );

register_deactivation_hook( __FILE__, [ 'SB_Installer', 'run_on_deactivation' ] );

// ── Admin-post handlers ──────────────────────────────────────────────────────

// Approval processing
add_action( 'admin_post_sb_process_approval', function() {
	if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'sb_process_approval' );
	$id     = absint( $_POST['approval_id'] ?? 0 );
	$action = sanitize_key( $_POST['approval_action'] ?? '' );
	$note   = sanitize_textarea_field( $_POST['operator_note'] ?? '' );
	if ( $id && in_array( $action, [ 'approved', 'rejected' ], true ) ) {
		SB_Approval_Engine::process_approval( $id, $action, $note );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sb-approvals&processed=1' ) );
	exit;
} );

// Agent CRUD
add_action( 'admin_post_sb_save_agent', function() {
	if ( ! current_user_can( 'manage_sovereign_prompts' ) ) { wp_die( 'Insufficient permissions — manage_sovereign_prompts required.' ); }
	check_admin_referer( 'sb_save_agent' );
	global $wpdb;
	$id   = absint( $_POST['agent_id'] ?? 0 );
	$data = [
		'agent_slug'         => sanitize_key( $_POST['slug'] ?? '' ),
		'agent_name'         => sanitize_text_field( $_POST['display_name'] ?? '' ),
		'model_routing'      => sanitize_text_field( $_POST['model_slug'] ?? '' ),
		'system_instruction' => sanitize_textarea_field( $_POST['system_instruction'] ?? '' ),
		'temperature'        => (float) ( $_POST['temperature'] ?? 0.7 ),
		'max_tokens'         => absint( $_POST['max_tokens'] ?? 2000 ),
	];
	if ( $id ) {
		$wpdb->update( "{$wpdb->prefix}sb_v2_agents", $data, [ 'id' => $id ] );
	} else {
		$wpdb->insert( "{$wpdb->prefix}sb_v2_agents", $data );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sb-agents&saved=1' ) );
	exit;
} );

// Pipeline step CRUD — BLOCKER-002 fix: handler now matches schema columns
add_action( 'admin_post_sb_save_pipeline_step', function() {
	if ( ! current_user_can( 'manage_sovereign_prompts' ) ) { wp_die( 'Insufficient permissions — manage_sovereign_prompts required.' ); }
	check_admin_referer( 'sb_save_pipeline_step' );
	global $wpdb;
	$id   = absint( $_POST['step_id'] ?? 0 );
	$data = [
		'pipeline_slug'      => sanitize_key( $_POST['pipeline_slug'] ?? 'default' ),
		'step_order'         => absint( $_POST['step_order'] ?? 1 ),
		'agent_slug'         => sanitize_key( $_POST['agent_slug'] ?? '' ),
		'step_label'         => sanitize_text_field( $_POST['step_label'] ?? '' ),
		'system_instruction' => sanitize_textarea_field( $_POST['system_instruction'] ?? '' ),
		'is_required'        => absint( $_POST['is_required'] ?? 1 ),
	];
	if ( $id ) {
		$wpdb->update( "{$wpdb->prefix}sb_v2_pipeline_configs", $data, [ 'id' => $id ] );
	} else {
		$wpdb->insert( "{$wpdb->prefix}sb_v2_pipeline_configs", $data );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sb-pipelines&saved=1' ) );
	exit;
} );

// Channel action CRUD
add_action( 'admin_post_sb_save_channel_action', function() {
	if ( ! current_user_can( 'manage_sovereign_scenarios' ) ) { wp_die( 'Insufficient permissions — manage_sovereign_scenarios required.' ); }
	check_admin_referer( 'sb_save_channel_action' );
	global $wpdb;
	$id   = absint( $_POST['action_id'] ?? 0 );
	$data = [
		'road_key'     => sanitize_key( $_POST['road_key'] ?? '' ),
		'channel'      => sanitize_key( $_POST['channel'] ?? 'email' ),
		'template_key' => sanitize_key( $_POST['template_key'] ?? '' ),
		'delay_days'   => absint( $_POST['delay_days'] ?? 0 ),
		'is_active'    => absint( $_POST['is_active'] ?? 1 ),
	];
	if ( $id ) {
		$wpdb->update( "{$wpdb->prefix}sb_channel_actions", $data, [ 'id' => $id ] );
	} else {
		$wpdb->insert( "{$wpdb->prefix}sb_channel_actions", $data );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sb-channel-actions&saved=1' ) );
	exit;
} );

// Ruleset CRUD
add_action( 'admin_post_sb_save_ruleset', function() {
	if ( ! current_user_can( 'manage_sovereign_rulesets' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'sb_save_ruleset' );
	global $wpdb;
	$id   = absint( $_POST['ruleset_id'] ?? 0 );
	$data = [
		'name'       => sanitize_text_field( $_POST['name'] ?? '' ),
		'slug'       => sanitize_key( $_POST['slug'] ?? '' ),
		'domain_key' => sanitize_key( $_POST['domain_key'] ?? ( defined( 'SB_ACTIVE_DOMAIN' ) ? SB_ACTIVE_DOMAIN : '' ) ),
		'status'     => sanitize_key( $_POST['status'] ?? 'draft' ),
	];
	if ( $id ) {
		$wpdb->update( "{$wpdb->prefix}sb_rulesets", $data, [ 'id' => $id ] );
	} else {
		$data['version']    = '1.0.0';
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( "{$wpdb->prefix}sb_rulesets", $data );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sb-rulesets&saved=1' ) );
	exit;
} );

// Generic delete — BLOCKER-003 fix: expanded allowlist with per-table capability map
add_action( 'admin_post_sb_delete_row', function() {
	$table   = sanitize_key( $_GET['table'] ?? '' );
	$id      = absint( $_GET['id'] ?? 0 );
	// ISSUE4 FIX: nonce verified FIRST — before any DB reads or capability lookups.
	// Prevents timing attacks and ensures browser-originated requests only.
	check_admin_referer( 'sb_delete_' . $table . '_' . $id );
	$cap_map = SB_Installer::get_delete_capability_map();
	if ( ! array_key_exists( $table, $cap_map ) ) {
		wp_die( 'Table not in delete allowlist.' );
	}
	if ( ! current_user_can( $cap_map[ $table ] ) ) {
		wp_die( 'Insufficient permissions for this table.' );
	}
	if ( $id ) {
		global $wpdb;
		// BUG2 FIX: verify row exists before deleting — prevents blind cross-table cascade attacks.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}{$table} WHERE id = %d LIMIT 1",
			$id
		) );
		if ( ! $row ) {
			wp_die( 'Row not found.' );
		}
		$wpdb->delete( "{$wpdb->prefix}{$table}", [ 'id' => $id ] );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_ROW_DELETED, "Deleted row {$id} from {$table}", get_current_user_id(), [ 'table' => $table, 'id' => $id ] );
	}
	wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=sovereign-builder' ) );
	exit;
} );

// Ask5 blueprint admin-post actions
add_action( 'admin_post_sb_blueprint_install', function() {
	if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'sb_blueprint_install' );
	$json = sanitize_textarea_field( $_POST['blueprint_json'] ?? '' );
	$result = SBAppBlueprintManager::install( $json );
	$status = is_wp_error( $result ) ? '&error=' . urlencode( $result->get_error_message() ) : '&installed=1';
	wp_safe_redirect( admin_url( 'admin.php?page=sb-blueprints' . $status ) );
	exit;
} );

add_action( 'admin_post_sb_blueprint_activate', function() {
	if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'sb_blueprint_activate' );
	$id = absint( $_POST['blueprint_id'] ?? 0 );
	SBAppBlueprintManager::activate( $id );
	wp_safe_redirect( admin_url( 'admin.php?page=sb-blueprints&activated=1' ) );
	exit;
} );

add_action( 'admin_post_sb_blueprint_deactivate', function() {
	if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'sb_blueprint_deactivate' );
	$id = absint( $_POST['blueprint_id'] ?? 0 );
	SBAppBlueprintManager::deactivate( $id );
	wp_safe_redirect( admin_url( 'admin.php?page=sb-blueprints&deactivated=1' ) );
	exit;
} );

// Site config export
add_action( 'admin_post_sb_export_site_config', function() {
	if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'sb_export_site_config' );
	$export = SBAppBlueprintManager::export_site_config();
	$filename = 'sb-site-config-' . date( 'Y-m-d' ) . '.json';
	header( 'Content-Type: application/json' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	echo wp_json_encode( $export, JSON_PRETTY_PRINT );
	exit;
} );

// Ask5 submenu registration — hooked at priority 20 to run after SB_UI_Router
add_action( 'admin_menu', function() {
	// Blueprint & Design
	add_submenu_page( 'sovereign-builder', 'App Blueprints',      'Blueprints',       'manage_sovereign_blueprints', 'sb-blueprints',       [ 'SBAppBlueprintManager',   'render_cockpit_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Visual App Designer', 'Visual Designer',  'manage_sovereign_blueprints', 'sb-visual-designer',  [ 'SBVisualDesigner',        'render_screen' ] );
	add_submenu_page( 'sovereign-builder', 'View Schemas',        'View Schemas',     'manage_sovereign_schemas',    'sb-view-schemas',     [ 'SBAdminViewRenderer',     'render_schema_list_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Schema Designer',     'Schema Designer',  'manage_sovereign_schemas',    'sb-schema-designer',  [ 'SBSchemaDesigner',        'render_screen' ] );
	// Forms & Surfaces
	add_submenu_page( 'sovereign-builder', 'Tiny Forms',          'Tiny Forms',       'manage_sovereign_forms',      'sb-tiny-forms',       [ 'SBTinyFormEngine',        'render_forms_screen' ] );
	add_submenu_page( 'sovereign-builder', 'UI Surfaces',         'UI Surfaces',      'manage_sovereign_surfaces',   'sb-ui-surfaces',      [ 'SBUISurfaceEngine',       'render_surfaces_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Placements',          'Placements',       'manage_sovereign',            'sb-placements',       [ 'SBPlacementEngine',       'render_placements_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Submissions',         'Submissions',      'manage_sovereign',            'sb-submissions',      [ 'SBTinyFormEngine',        'render_submissions_screen' ] );
	add_submenu_page( 'sovereign-builder', 'User Fields',         'User Fields',      'manage_sovereign',            'sb-user-fields',      [ 'SBUserFieldCatalog',      'render_screen' ] );
	// Connectors & Events
	add_submenu_page( 'sovereign-builder', 'Connector Health',    'Connectors',       'manage_sovereign',            'sb-connector-health', [ 'SBConnectorHealthConsole','render_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Release Dashboard',   'Releases',         'manage_sovereign',            'sb-release-dashboard',[ 'SBReleaseManager',        'render_release_dashboard' ] );
	// Operations
	add_submenu_page( 'sovereign-builder', 'Simulation Studio',   'Simulation',       'manage_sovereign',            'sb-simulation',       [ 'SBSimulationEngine',      'render_simulation_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Dependency Graph',    'Dep Graph',        'manage_sovereign',            'sb-dep-graph',        [ 'SBDependencyGraph',       'render_screen' ] );
	add_submenu_page( 'sovereign-builder', 'AI Capabilities',     'AI Capabilities',  'manage_sovereign',            'sb-ai-capabilities',  [ 'SBAIIntegrator',          'render_screen' ] );
	// Debug & Performance
	add_submenu_page( 'sovereign-builder', 'AI Debugger Console', 'AI Debugger',      'manage_sovereign_debug',      'sb-debugger-console', [ 'SBDebuggerConsole',       'render_debugger_console_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Performance Console', 'Performance',      'manage_sovereign',            'sb-perf-console',     [ 'SBPerfConsole',           'render_perf_console_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Add-On Logs',         'Add-On Logs',      'view_sovereign_audit_logs',   'sb-addon-logs',       [ 'SBOperatorShell',         'render_addon_logs_screen' ] );
	add_submenu_page( 'sovereign-builder', 'License',             'License',          'manage_sovereign',            'sb-license',          [ 'SBLicensingMatrix',       'render_license_screen' ] );
	// Hidden detail screens
	add_submenu_page( null, 'Blueprint Detail',   '', 'manage_sovereign_blueprints', 'sb-blueprint-detail',    [ 'SBAppBlueprintManager', 'render_detail_screen' ] );
	add_submenu_page( null, 'Form Detail',        '', 'manage_sovereign_forms',      'sb-form-detail',         [ 'SBTinyFormEngine',      'render_detail_screen' ] );
	add_submenu_page( null, 'Surface Detail',     '', 'manage_sovereign_surfaces',   'sb-surface-detail',      [ 'SBUISurfaceEngine',     'render_detail_screen' ] );
	add_submenu_page( null, 'Submission Detail',  '', 'manage_sovereign',            'sb-submission-detail',   [ 'SBTinyFormEngine',      'render_submission_detail_screen' ] );
}, 20 );

// Boot
add_filter( 'cron_schedules', function( $s ) { return $s; } ); // ensure filter registered before schedule

// === Library Extension Bootstrap ===
require_once SB_PATH . 'includes/class-content-seeder.php';
require_once SB_PATH . 'includes/class-library-importer.php';
require_once SB_PATH . 'includes/class-blueprint-builder.php';
add_action( 'init', [ 'SB_Content_Seeder', 'init' ], 15 );
add_action( 'init', [ 'SB_Library_Importer', 'init' ], 16 );
add_action( 'sb_modules_register', [ 'SB_Blueprint_Builder', 'init' ] );

add_action( 'plugins_loaded', [ 'SB_Telemetry_Buffer', 'init' ], 5 );
add_action( 'plugins_loaded', function() {
	SovereignBuilder::get_instance();
}, 10 );

// === ADDITION: Phase A REST routes (register_rest_routes additions) ===
function sb_55_register_phase_a_routes(): void {

	$ns = 'sovereign-builder/v1';

	// ── APO routes ────────────────────────────────────────────────────────────

	register_rest_route( $ns, '/apo-create', [
		'methods'             => 'POST',
		'callback'            => [ 'SBProposalAuthority', 'handle_rest_create' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_proposals' ),
	] );

	register_rest_route( $ns, '/apo-transition', [
		'methods'             => 'POST',
		'callback'            => [ 'SBProposalAuthority', 'handle_rest_transition' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_proposals' ),
	] );

	register_rest_route( $ns, '/apo-list', [
		'methods'             => 'GET',
		'callback'            => [ 'SBProposalAuthority', 'handle_rest_list' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_proposals' ),
	] );

	register_rest_route( $ns, '/apo-detail', [
		'methods'             => 'GET',
		'callback'            => [ 'SBProposalAuthority', 'handle_rest_detail' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_proposals' ),
	] );

	// ── Commit routes ─────────────────────────────────────────────────────────

	register_rest_route( $ns, '/commit-request', [
		'methods'             => 'POST',
		'callback'            => [ 'SBCommitGate', 'handle_rest_commit_request' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_proposals' ),
	] );

	register_rest_route( $ns, '/commit-approve', [
		'methods'             => 'POST',
		'callback'            => [ 'SBCommitGate', 'handle_rest_commit_approve' ],
		'permission_callback' => fn() => current_user_can( 'approve_kynvaric_commits' ),
	] );

	register_rest_route( $ns, '/commit-reject', [
		'methods'             => 'POST',
		'callback'            => [ 'SBCommitGate', 'handle_rest_commit_reject' ],
		'permission_callback' => fn() => current_user_can( 'approve_kynvaric_commits' ),
	] );

	register_rest_route( $ns, '/commit-execute', [
		'methods'             => 'POST',
		'callback'            => [ 'SBCommitGate', 'handle_rest_commit_execute' ],
		'permission_callback' => fn() => current_user_can( 'approve_kynvaric_commits' ),
	] );

	register_rest_route( $ns, '/commit-history', [
		'methods'             => 'GET',
		'callback'            => [ 'SBCommitGate', 'handle_rest_commit_history' ],
		'permission_callback' => fn() => current_user_can( 'view_kynvaric_ledger' ),
	] );

	// ── Authority ledger routes ───────────────────────────────────────────────

	register_rest_route( $ns, '/authority-events', [
		'methods'             => 'GET',
		'callback'            => [ 'SBAuditLedgerPlus', 'handle_rest_events' ],
		'permission_callback' => fn() => current_user_can( 'view_kynvaric_ledger' ),
	] );

	register_rest_route( $ns, '/compensate-event', [
		'methods'             => 'POST',
		'callback'            => [ 'SBAuditLedgerPlus', 'handle_rest_compensate' ],
		'permission_callback' => fn() => current_user_can( 'approve_kynvaric_commits' ),
	] );

	register_rest_route( $ns, '/ledger-integrity-check', [
		'methods'             => 'GET',
		'callback'            => [ 'SBAuditLedgerPlus', 'handle_rest_integrity_check' ],
		'permission_callback' => fn() => current_user_can( 'view_kynvaric_ledger' ),
	] );
}

add_action( 'rest_api_init', 'sb_55_register_phase_a_routes' );

// === ADDITION: bootstrap_hooks additions for Phase A ===
/*
		// ASK5.5 Phase A module init
		add_action( 'init', [ 'SBStorePolicy',        'init' ], 5  ); // Store policy before everything
		add_action( 'init', [ 'SBProposalAuthority',  'init' ], 15 );
		add_action( 'init', [ 'SBAuditLedgerPlus',    'init' ], 15 );
		add_action( 'init', [ 'SBCommitGate',         'init' ], 15 );
*/

// === ADDITION: Phase B REST routes ===
function sb_55_register_phase_b_routes(): void {
	$ns = 'sovereign-builder/v1';

	// ── Evidence routes ───────────────────────────────────────────────────────

	register_rest_route( $ns, '/evidence-list', [
		'methods'             => 'GET',
		'callback'            => [ 'SBEvidenceVault', 'handle_rest_list' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_evidence' ),
	] );

	register_rest_route( $ns, '/evidence-detail', [
		'methods'             => 'GET',
		'callback'            => [ 'SBEvidenceVault', 'handle_rest_detail' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_evidence' ),
	] );

	register_rest_route( $ns, '/evidence-link', [
		'methods'             => 'POST',
		'callback'            => [ 'SBEvidenceVault', 'handle_rest_link' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_evidence' ),
	] );

	register_rest_route( $ns, '/evidence-export-package', [
		'methods'             => 'GET',
		'callback'            => [ 'SBEvidenceVault', 'handle_rest_export_package' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_evidence' ),
	] );

	// ── Review session routes ─────────────────────────────────────────────────

	register_rest_route( $ns, '/review-session-open', [
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $r ): WP_REST_Response {
			$result = SBKynvaricWorkspace::open_session( (array) $r->get_json_params() );
			if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
			return new WP_REST_Response( [ 'session_id' => $result ], 201 );
		},
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_review_sessions' ),
	] );

	register_rest_route( $ns, '/review-session-transition', [
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $r ): WP_REST_Response {
			$p      = (array) $r->get_json_params();
			$result = SBKynvaricWorkspace::transition_session( absint( $p['session_id'] ?? 0 ), sanitize_key( $p['to_status'] ?? '' ), sanitize_textarea_field( $p['note'] ?? '' ) );
			if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
			return new WP_REST_Response( [ 'success' => true ], 200 );
		},
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_review_sessions' ),
	] );

	register_rest_route( $ns, '/review-queue-enqueue', [
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $r ): WP_REST_Response {
			$result = SBKynvaricWorkspace::enqueue_item( (array) $r->get_json_params() );
			if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
			return new WP_REST_Response( [ 'item_id' => $result ], 201 );
		},
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_review_sessions' ),
	] );

	register_rest_route( $ns, '/signoff-record', [
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $r ): WP_REST_Response {
			$result = SBKynvaricWorkspace::record_signoff( (array) $r->get_json_params() );
			if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
			return new WP_REST_Response( [ 'signoff_id' => $result ], 201 );
		},
		'permission_callback' => fn() => current_user_can( 'sign_off_kynvaric' ),
	] );

	register_rest_route( $ns, '/signoff-void', [
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $r ): WP_REST_Response {
			$p      = (array) $r->get_json_params();
			$result = SBKynvaricWorkspace::void_signoff( absint( $p['signoff_id'] ?? 0 ), sanitize_textarea_field( $p['reason'] ?? '' ) );
			if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 422 ); }
			return new WP_REST_Response( [ 'void_record_id' => $result ], 201 );
		},
		'permission_callback' => fn() => current_user_can( 'sign_off_kynvaric' ),
	] );
}

add_action( 'rest_api_init', 'sb_55_register_phase_b_routes' );

// === ADDITION: Phase C REST routes ===
function sb_55_register_phase_c_routes(): void {
	$ns = 'sovereign-builder/v1';

	// ── Entitlement map routes ────────────────────────────────────────────────

	register_rest_route( $ns, '/entitlement-map-create', [
		'methods'             => 'POST',
		'callback'            => [ 'SBEntitlementEngine', 'handle_rest_create_map' ],
		'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
	] );

	register_rest_route( $ns, '/entitlement-map-list', [
		'methods'             => 'GET',
		'callback'            => [ 'SBEntitlementEngine', 'handle_rest_list_maps' ],
		'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
	] );

	register_rest_route( $ns, '/entitlement-provision', [
		'methods'             => 'POST',
		'callback'            => [ 'SBEntitlementEngine', 'handle_rest_provision' ],
		'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
	] );

	// ── Plaid routes ──────────────────────────────────────────────────────────

	register_rest_route( $ns, '/plaid-sync', [
		'methods'             => 'POST',
		'callback'            => [ 'SBPlaidConnector', 'handle_rest_sync' ],
		'permission_callback' => fn() => current_user_can( 'manage_kynvaric_proposals' ),
	] );

	// ── Constraint check endpoint ─────────────────────────────────────────────

	register_rest_route( $ns, '/constraint-check-blueprint', [
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ): WP_REST_Response {
			$blueprint_id = absint( ( (array) $request->get_json_params() )['blueprint_id'] ?? 0 );
			if ( ! $blueprint_id ) { return new WP_REST_Response( [ 'error' => 'blueprint_id required.' ], 400 ); }
			global $wpdb;
			$blueprint = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_app_blueprints WHERE id = %d", $blueprint_id ), ARRAY_A );
			if ( ! $blueprint ) { return new WP_REST_Response( [ 'error' => 'Blueprint not found.' ], 404 ); }
			$result = SBConstraintGuard::validate_blueprint( $blueprint );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( [ 'valid' => false, 'rule' => $result->get_error_code(), 'message' => $result->get_error_message() ], 200 );
			}
			return new WP_REST_Response( [ 'valid' => true ], 200 );
		},
		'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
	] );
}

add_action( 'rest_api_init', 'sb_55_register_phase_c_routes' );

// === ADDITION: v2.3 additions — event keys, bootstrap, repair-system hook ===
// ── Add to class SB_Event_Keys: ───────────────────────────────────────────────
/*
	// ── v2.3 — Build Map Materializer ────────────────────────────────────────
	const EV_BUILD_MAP_MATERIALIZED                               = 'build_map_materialized';
	const EV_BUILD_MAP_MATERIALIZATION_FAILED                     = 'build_map_materialization_failed';
	const EV_BUILD_MAP_STALE                                      = 'build_map_stale';
	const EV_BLUEPRINT_GRAPH_HASH_UPDATED                         = 'blueprint_graph_hash_updated';
*/

// ── Add to SovereignBuilder::bootstrap_hooks(): ───────────────────────────────
/*
		// v2.3 — Build Map Materializer
		add_action( 'init', [ 'SBBuildMapMaterializer', 'init' ], 15 );
*/

// ── Add to SBBlueprintManager::activate() after apply_config() call: ──────────
/*
		// Materialize build map after blueprint activation
		SBBuildMapMaterializer::run( $id );
*/

// ── Add to repair-system REST handler: ────────────────────────────────────────
/*
		// Materialize all active blueprints into runtime table
		$mat_result = SBBuildMapMaterializer::run_all();
		$results['build_map_materialization'] = $mat_result;
*/

// ── Add to REST routes (sovereign-builder/v1): ────────────────────────────────
/*
		register_rest_route( 'sovereign-builder/v1', '/build-map-materialize', [
			'methods'             => 'POST',
			'callback'            => [ 'SBBuildMapMaterializer', 'handle_rest_run_all' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
*/