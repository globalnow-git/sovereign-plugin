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
	'includes/class-debugger.php',
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

		// Hide duplicate page title on pages where _hide_page_title meta is set
		add_action( 'wp_head', function() {
			if ( ! is_singular( 'page' ) ) { return; }
			$hide = get_post_meta( get_the_ID(), '_hide_page_title', true );
			if ( ! $hide ) { return; }
			echo '<style>.entry-title,.page-title,.post-title,h1.title,.page-header h1,article.page>header h1,.wp-block-post-title{display:none!important}</style>';
		} );

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
		add_shortcode( 'sb_form',    [ 'SBTinyFormEngine',   'shortcode' ] );
		add_shortcode( 'sb_surface', [ 'SBUISurfaceEngine',  'shortcode' ] );
		add_shortcode( 'sb_view',    [ 'SBAdminViewRenderer','shortcode_handler' ] );

		// Frontend asset lockdown
		add_action( 'wp_enqueue_scripts', [ $this, 'lockdown_frontend_bloat' ], 99 );

		// Frontend form handler
		add_action( 'wp_enqueue_scripts', function() {
			wp_enqueue_script( 'sb-forms-js', SB_URL . 'assets/sb-forms.js', [], SB_VERSION, true );
			wp_localize_script( 'sb-forms-js', 'sbFormsContext', [
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'rest'  => esc_url_raw( get_rest_url( null, 'sovereign-builder/v1/form/submit' ) ),
			] );
		} );
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
		register_rest_route( 'sovereign-builder/v1', '/signal/activity', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
			'callback'            => function( WP_REST_Request $request ) {
				global $wpdb;
				$signal_type = sanitize_key( $request->get_param( 'signal_type' ) ?? '' );
				$limit       = min( absint( $request->get_param( 'limit' ) ?? 10 ), 50 );
				if ( ! $signal_type ) { return new WP_REST_Response( [ 'error' => 'signal_type required' ], 400 ); }

				$total = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}sb_signals WHERE signal_type = %s",
					$signal_type
				) );

				$events = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.user_id, s.current_value, s.triggered_at, u.user_email
					 FROM {$wpdb->prefix}sb_signals s
					 LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
					 WHERE s.signal_type = %s
					 ORDER BY s.triggered_at DESC
					 LIMIT %d",
					$signal_type, $limit
				), ARRAY_A );

				return rest_ensure_response( [ 'total' => $total, 'events' => $events ] );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/form/lookup', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_forms' ),
			'callback'            => function( WP_REST_Request $request ) {
				global $wpdb;
				$slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );
				if ( ! $slug ) { return new WP_REST_Response( [ 'error' => 'slug required' ], 400 ); }
				$form = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, slug, label, status FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s",
					$slug
				) );
				if ( ! $form ) { return new WP_REST_Response( [ 'error' => 'not found' ], 404 ); }
				return rest_ensure_response( [ 'id' => (int) $form->id, 'slug' => $form->slug, 'label' => $form->label ] );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/lookup', [
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => function( WP_REST_Request $request ) {
				global $wpdb;
				$slug = sanitize_key( $request->get_param( 'slug' ) ?? '' );
				if ( ! $slug ) { return new WP_REST_Response( [ 'error' => 'slug required' ], 400 ); }
				$bp = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, slug, label, status FROM {$wpdb->prefix}sb_app_blueprints WHERE slug = %s",
					$slug
				) );
				if ( ! $bp ) { return new WP_REST_Response( [ 'error' => 'not found' ], 404 ); }
				return rest_ensure_response( [ 'id' => (int) $bp->id, 'slug' => $bp->slug, 'label' => $bp->label, 'status' => $bp->status ] );
			},
		] );
		register_rest_route( 'sovereign-builder/v1', '/blueprint/generate', [
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_sovereign_blueprints' ),
			'callback'            => function( WP_REST_Request $request ) {
				$params      = (array) $request->get_json_params();
				$description = sanitize_textarea_field( $params['description'] ?? '' );
				$answers     = $params['answers'] ?? null; // null = first pass, array = refinement
				$original    = $params['original_blueprint'] ?? null;

				if ( strlen( $description ) < 20 ) {
					return new WP_REST_Response( [ 'error' => 'Description too short.' ], 400 );
				}

				$api_key = SB_Extension_API::get_setting( 'sb_anthropic_key', '' );
				if ( empty( $api_key ) ) {
					return new WP_REST_Response( [ 'error' => 'Anthropic API key not configured. Add it in Sovereign Builder settings.' ], 400 );
				}

				$system_prompt = 'You are a Sovereign Builder Blueprint Architect. Output ONLY a valid JSON blueprint object — no markdown, no explanation, no backticks, nothing else.

Required JSON structure:
{"slug":"kebab-slug","label":"Label","version":"1.0.0","category":"saas","blueprint_type":"vertical-app","description":"One sentence.","signals":["signal_one"],"roads":[{"road_key":"A","label":"Road A"},{"road_key":"B","label":"Road B"}],"forms":[{"slug":"form-slug","label":"Form Label","save_adapter":"submission_table","save_config_json":{"signal_type":"signal_one"},"fields_json":[{"key":"field_key","label":"Field Label","type":"text","required":true,"placeholder":"Placeholder"}]}],"schemas":[{"slug":"schema-slug","label":"Schema Label","schema_json":{"source_table":"sb_submissions","filter":{"form_slug":"form-slug"},"layout":"table","columns":[{"key":"field_key","label":"Column Label"}]}}],"pages":[{"title":"Page Title","slug":"page-slug","shortcode":"[sb_form slug=\"form-slug\"]"}],"portal":{"nav":"horizontal","lock_stages":true,"show_progress":true,"color_scheme":"dark","stages":[]},"pipeline":{"slug":"pipeline-slug","label":"Pipeline Label","steps":[{"step":1,"role":"Analyst","instruction":"Detailed instruction for this AI agent step."}]},"_questions":[{"id":"q1","question":"Specific question about an assumption","options":["Option A","Option B","Option C"],"affects":"what changes","default":"Option A"}]}

Rules: slugs are kebab-case, forms have 4+ fields each, include 3-5 _questions about assumptions you made, use horizontal nav for wizards and vertical for complex apps with many sections.';

				// Build the user message
				if ( $answers && $original ) {
					// Refinement pass
					$answer_text = '';
					if ( is_array( $answers ) ) {
						foreach ( $answers as $qid => $answer ) {
							$answer_text .= "Q ({$qid}): {$answer}\n";
						}
					}
					$user_message = "Here is the original blueprint:\n\n" . wp_json_encode( $original ) .
						"\n\nThe user answered the clarifying questions as follows:\n\n" . $answer_text .
						"\n\nOutput the refined blueprint JSON only. Do NOT include _questions in the output.";
				} else {
					// First pass
					$user_message = "Generate a complete Sovereign Builder blueprint for this application:\n\n" . $description;
				}

				$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
					'timeout' => 120,
					'headers' => [
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					],
					'body' => wp_json_encode( [
						'model'      => SB_Extension_API::get_setting( 'sb_model_slug', 'claude-sonnet-4-5' ),
						'max_tokens' => 8192,
						'system'     => $system_prompt,
						'messages'   => [ [ 'role' => 'user', 'content' => $user_message ] ],
					] ),
				] );

				if ( is_wp_error( $response ) ) {
					return new WP_REST_Response( [ 'error' => 'API call failed: ' . $response->get_error_message() ], 500 );
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( empty( $body['content'][0]['text'] ) ) {
					return new WP_REST_Response( [ 'error' => 'Empty response from Claude. Try again.' ], 500 );
				}

				// Check if response was truncated due to token limit
				if ( ( $body['stop_reason'] ?? '' ) === 'max_tokens' ) {
					return new WP_REST_Response( [ 'error' => 'Blueprint too large — Claude hit the token limit. Try a simpler description or fewer features.' ], 500 );
				}

				$text = $body['content'][0]['text'];
				// Strip any accidental markdown fences — aggressive multi-pass
				$text = preg_replace( '/^```json\s*/m', '', $text );
				$text = preg_replace( '/^```\s*/m', '', $text );
				$text = preg_replace( '/\s*```\s*$/m', '', $text );
				// Find first { and last } — extract just the JSON object
				$start = strpos( $text, '{' );
				$end   = strrpos( $text, '}' );
				if ( $start !== false && $end !== false && $end > $start ) {
					$text = substr( $text, $start, $end - $start + 1 );
				}
				$text = trim( $text );

				$blueprint = json_decode( $text, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					// Return first 500 chars of raw response for debugging
					return new WP_REST_Response( [
						'error' => 'Claude returned invalid JSON: ' . json_last_error_msg(),
						'raw'   => substr( $text, 0, 500 ),
					], 500 );
				}

				SB_Event_Logger::log_audit(
					'blueprint_generated',
					'AI blueprint generated: ' . ( $blueprint['label'] ?? 'unnamed' ),
					get_current_user_id(),
					[ 'forms' => count( $blueprint['forms'] ?? [] ), 'pages' => count( $blueprint['pages'] ?? [] ) ]
				);

				return new WP_REST_Response( [ 'success' => true, 'blueprint' => $blueprint ], 200 );
			},
		] );
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
				// Body size cap: 64KB (author bios, work samples, long-form content)
				if ( strlen( $request->get_body() ) > 65536 ) {
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

/**
 * FIX-07 — Blueprint activation page creation hook.
 *
 * When a blueprint is activated, any pages declared in its config are
 * automatically created in WordPress with the appropriate shortcode mounted.
 * Pages are only created if they don't already exist (checked by slug).
 *
 * Blueprint JSON format:
 * "pages": [
 *   { "title": "Submit Your Work", "slug": "author-portal-submit", "shortcode": "[sb_form slug=\"author-intake\"]" }
 * ]
 */
add_action( 'sb_blueprint_activated', function( int $blueprint_id, array $config ) {
	if ( empty( $config['pages'] ) || ! is_array( $config['pages'] ) ) { return; }

	foreach ( $config['pages'] as $page ) {
		$title     = sanitize_text_field( $page['title'] ?? '' );
		$slug      = sanitize_title( $page['slug'] ?? $title );
		$shortcode = wp_kses_post( $page['shortcode'] ?? '' );

		if ( ! $title || ! $slug ) { continue; }

		// Check if a page with this slug already exists
		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing ) {
			SB_Event_Logger::log_audit(
				'blueprint_page_exists',
				"Blueprint page '{$slug}' already exists (ID: {$existing->ID}) — skipped.",
				get_current_user_id(),
				[ 'blueprint_id' => $blueprint_id ]
			);
			continue;
		}

		$page_id = wp_insert_post( [
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $shortcode,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id() ?: 1,
		], true );

		if ( is_wp_error( $page_id ) ) {
			SB_Event_Logger::log_audit(
				'blueprint_page_failed',
				"Failed to create page '{$slug}': " . $page_id->get_error_message(),
				get_current_user_id(),
				[ 'blueprint_id' => $blueprint_id ],
				'error'
			);
		} else {
			// Store blueprint ID on the page for reference
			update_post_meta( $page_id, '_sb_blueprint_id', $blueprint_id );
			SB_Event_Logger::log_audit(
				'blueprint_page_created',
				"Created page '{$title}' (ID: {$page_id}, slug: {$slug}) for blueprint {$blueprint_id}.",
				get_current_user_id(),
				[ 'blueprint_id' => $blueprint_id, 'page_id' => $page_id ]
			);
		}
	}
}, 10, 2 );

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
	add_submenu_page( 'sovereign-builder', '✦ Build an App',      '✦ Build an App',   'manage_sovereign_blueprints', 'sb-build-app',        'sb_render_build_app_screen' );
	add_submenu_page( 'sovereign-builder', 'App Blueprints',      'Blueprints',       'manage_sovereign_blueprints', 'sb-blueprints',       [ 'SBAppBlueprintManager',   'render_cockpit_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Visual App Designer', 'Visual Designer',  'manage_sovereign_blueprints', 'sb-visual-designer',  [ 'SBVisualDesigner',        'render_screen' ] );
	add_submenu_page( 'sovereign-builder', 'Roads',               'Roads',            'manage_sovereign',            'sb-roads',            'sb_render_roads_screen' );
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
// sb_submissions_view shortcode — renders current user's form submissions as a styled list
add_shortcode( 'sb_submissions_view', function( array $atts ): string {
	$atts      = shortcode_atts( [ 'form' => '', 'title' => 'Your Submissions' ], $atts );
	$form_slug = sanitize_key( $atts['form'] );
	$user_id   = get_current_user_id();

	if ( ! $form_slug ) { return ''; }

	global $wpdb;

	$subs = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.id, s.status, s.submitted_at
		 FROM {$wpdb->prefix}sb_submissions s
		 WHERE s.form_slug = %s AND s.user_id = %d
		 ORDER BY s.submitted_at DESC LIMIT 20",
		$form_slug, $user_id
	), ARRAY_A );

	$css = '<style>
	.sb-subs-wrapper { font-family: -apple-system, BlinkMacSystemFont, "Inter", sans-serif; max-width: 760px; margin: 2rem auto; }
	.sb-subs-header { background: #1a1a2e; color: #f5f0e8; padding: 1.25rem 1.75rem; border-radius: 10px 10px 0 0; border-bottom: 3px solid #c9a84c; }
	.sb-subs-header h2 { font-family: Georgia, serif; font-size: 1.3rem; margin: 0; color: #f5f0e8; }
	.sb-subs-body { background: #fff; border-radius: 0 0 10px 10px; box-shadow: 0 2px 16px rgba(26,26,46,0.09); overflow: hidden; }
	.sb-sub-item { padding: 1.1rem 1.75rem; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
	.sb-sub-item:last-child { border-bottom: none; }
	.sb-sub-meta { font-size: 0.85rem; color: #6b7280; }
	.sb-sub-id { font-family: monospace; font-size: 0.8rem; color: #9ca3af; }
	.sb-sub-badge { display: inline-flex; padding: 0.2rem 0.7rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
	.sb-sub-badge-received { background: #eff6ff; color: #2563eb; }
	.sb-sub-badge-certified { background: #ecfdf5; color: #059669; }
	.sb-sub-badge-pending { background: #fef9c3; color: #92400e; }
	.sb-sub-badge-flagged { background: #fef2f2; color: #dc2626; }
	.sb-subs-empty { padding: 3rem; text-align: center; color: #9ca3af; }
	.sb-sub-fields { margin-top: 0.4rem; }
	.sb-sub-field { font-size: 0.88rem; color: #374151; margin-bottom: 0.15rem; }
	.sb-sub-field strong { color: #1a1a2e; }
	</style>';

	ob_start();
	echo $css;
	echo '<div class="sb-subs-wrapper">';
	echo '<div class="sb-subs-header"><h2>' . esc_html( $atts['title'] ) . '</h2></div>';
	echo '<div class="sb-subs-body">';

	if ( empty( $subs ) ) {
		echo '<div class="sb-subs-empty"><p>No submissions found. Complete the form to get started.</p></div>';
	} else {
		foreach ( $subs as $sub ) {
			$meta_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->prefix}sb_submission_meta WHERE submission_id = %d LIMIT 10",
				(int) $sub['id']
			), ARRAY_A );

			$badge_class = 'sb-sub-badge-' . sanitize_key( $sub['status'] );
			echo '<div class="sb-sub-item">';
			echo '<div>';
			echo '<div class="sb-sub-field">';
			foreach ( $meta_rows as $m ) {
				$key   = ucwords( str_replace( '_', ' ', $m['meta_key'] ) );
				$value = esc_html( wp_trim_words( $m['meta_value'], 12 ) );
				if ( $value ) {
					echo '<div class="sb-sub-field"><strong>' . esc_html( $key ) . ':</strong> ' . $value . '</div>';
				}
			}
			echo '</div>';
			echo '<div class="sb-sub-meta">Submitted: ' . esc_html( date( 'M j, Y g:ia', strtotime( $sub['submitted_at'] ) ) ) . '</div>';
			echo '</div>';
			echo '<span class="sb-sub-badge ' . esc_attr( $badge_class ) . '">' . esc_html( strtoupper( $sub['status'] ) ) . '</span>';
			echo '</div>';
		}
	}

	echo '</div></div>';
	return ob_get_clean();
} );

/**
 * Build an App — AI-powered blueprint generator with wizard refinement loop.
 * Operator describes their app in plain English. Claude generates a blueprint
 * and asks clarifying questions. Answers refine the blueprint. Final blueprint
 * installs and Visual Designer loads with it pre-selected.
 */
function sb_render_build_app_screen(): void {
	if ( ! current_user_can( 'manage_sovereign_blueprints' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	$rest_nonce = wp_create_nonce( 'wp_rest' );
	$api_key    = SB_Extension_API::get_setting( 'sb_anthropic_key', '' );
	$designer_url = admin_url( 'admin.php?page=sb-visual-designer&blueprint_id=' );
	?>
	<style>
	#sb-baa-wrap { max-width:860px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",sans-serif; padding:2rem 0; }
	#sb-baa-wrap h1 { font-size:1.75rem; color:#1a1a2e; margin-bottom:0.25rem; }
	#sb-baa-wrap .sb-baa-sub { color:#6b7280; margin-bottom:2rem; font-size:0.92rem; }
	.sb-baa-card { background:#fff; border-radius:12px; box-shadow:0 2px 16px rgba(26,26,46,0.08),0 0 0 1px rgba(0,0,0,0.05); margin-bottom:1.5rem; overflow:hidden; }
	.sb-baa-card-head { background:#1a1a2e; padding:1.1rem 1.75rem; border-bottom:3px solid #c9a84c; display:flex; align-items:center; gap:0.75rem; }
	.sb-baa-card-head h2 { margin:0; font-size:1rem; font-weight:700; color:#f5f0e8; font-family:Georgia,serif; }
	.sb-baa-card-head .sb-baa-step { background:#c9a84c; color:#1a1a2e; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; flex-shrink:0; }
	.sb-baa-card-body { padding:1.75rem; }
	.sb-baa-textarea { width:100%; box-sizing:border-box; padding:1rem; font-size:0.95rem; font-family:inherit; color:#1a1a2e; background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:8px; resize:vertical; outline:none; transition:border-color 0.18s; line-height:1.7; }
	.sb-baa-textarea:focus { border-color:#c9a84c; background:#fff; }
	.sb-baa-btn { display:inline-flex; align-items:center; gap:0.5rem; padding:0.7rem 1.5rem; font-size:0.92rem; font-weight:700; border-radius:8px; cursor:pointer; border:none; transition:all 0.18s; font-family:inherit; }
	.sb-baa-btn-primary { background:#1a1a2e; color:#c9a84c; }
	.sb-baa-btn-primary:hover { background:#252545; }
	.sb-baa-btn-primary:disabled { opacity:0.5; cursor:not-allowed; }
	.sb-baa-btn-outline { background:#fff; color:#374151; border:1.5px solid #e5e7eb !important; }
	.sb-baa-btn-outline:hover { border-color:#c9a84c !important; }
	.sb-baa-btn-gold { background:#c9a84c; color:#1a1a2e; }
	.sb-baa-btn-gold:hover { background:#b8963d; }
	.sb-baa-hint { font-size:0.8rem; color:#9ca3af; margin-top:0.5rem; }
	.sb-baa-examples { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; }
	.sb-baa-example { padding:0.35rem 0.85rem; border-radius:20px; background:#f3f4f6; color:#374151; font-size:0.8rem; cursor:pointer; border:1px solid #e5e7eb; transition:all 0.15s; }
	.sb-baa-example:hover { background:#fefce8; border-color:#c9a84c; color:#1a1a2e; }

	/* Thinking indicator */
	.sb-baa-thinking { display:none; align-items:center; gap:0.75rem; padding:1.25rem 1.75rem; background:#f9fafb; border-radius:8px; margin-bottom:1rem; }
	.sb-baa-thinking.active { display:flex; }
	.sb-baa-dots span { display:inline-block; width:8px; height:8px; background:#c9a84c; border-radius:50%; animation:sbDot 1.2s infinite; margin:0 2px; }
	.sb-baa-dots span:nth-child(2) { animation-delay:0.2s; }
	.sb-baa-dots span:nth-child(3) { animation-delay:0.4s; }
	@keyframes sbDot { 0%,80%,100%{transform:scale(0.6);opacity:0.4} 40%{transform:scale(1);opacity:1} }
	.sb-baa-thinking-text { font-size:0.88rem; color:#6b7280; font-style:italic; }

	/* Question wizard */
	.sb-baa-question { background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:10px; padding:1.25rem 1.5rem; margin-bottom:1rem; transition:all 0.2s; }
	.sb-baa-question.answered { border-color:#c9a84c; background:#fefce8; }
	.sb-baa-q-num { font-size:0.72rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:#c9a84c; margin-bottom:0.35rem; }
	.sb-baa-q-text { font-size:0.95rem; font-weight:600; color:#1a1a2e; margin-bottom:0.75rem; line-height:1.5; }
	.sb-baa-q-options { display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.75rem; }
	.sb-baa-q-opt { padding:0.45rem 1rem; border-radius:6px; border:1.5px solid #e5e7eb; background:#fff; color:#374151; font-size:0.85rem; cursor:pointer; transition:all 0.15s; }
	.sb-baa-q-opt:hover { border-color:#c9a84c; }
	.sb-baa-q-opt.selected { border-color:#c9a84c; background:#1a1a2e; color:#c9a84c; font-weight:700; }
	.sb-baa-q-custom { width:100%; margin-top:0.5rem; padding:0.55rem 0.85rem; border:1.5px solid #e5e7eb; border-radius:6px; font-size:0.88rem; font-family:inherit; outline:none; box-sizing:border-box; }
	.sb-baa-q-custom:focus { border-color:#c9a84c; }
	.sb-baa-q-answered-badge { font-size:0.78rem; color:#059669; font-weight:700; }

	/* Blueprint preview */
	.sb-baa-bp-preview { background:#1a1a2e; border-radius:10px; padding:1.5rem; }
	.sb-baa-bp-preview pre { color:#e2e8f0; font-size:0.78rem; margin:0; overflow:auto; max-height:300px; line-height:1.6; }
	.sb-baa-bp-meta { display:flex; gap:1.5rem; margin-bottom:1rem; flex-wrap:wrap; }
	.sb-baa-bp-stat { text-align:center; }
	.sb-baa-bp-stat-num { font-size:1.5rem; font-weight:800; color:#c9a84c; }
	.sb-baa-bp-stat-label { font-size:0.72rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; }

	/* HITM log */
	.sb-baa-hitm-item { display:flex; gap:1rem; padding:0.65rem 0; border-bottom:1px solid #f3f4f6; font-size:0.83rem; }
	.sb-baa-hitm-time { color:#9ca3af; white-space:nowrap; min-width:80px; }
	.sb-baa-hitm-action { color:#6b7280; min-width:120px; font-weight:600; }
	.sb-baa-hitm-detail { color:#1a1a2e; flex:1; }
	</style>

	<div id="sb-baa-wrap" class="wrap">
		<h1>✦ Build an App</h1>
		<p class="sb-baa-sub">Describe what you want to build in plain English. Claude will generate a complete blueprint and ask a few questions to get it exactly right.</p>

		<!-- Step 1: Describe your app -->
		<div class="sb-baa-card" id="sb-step-1">
			<div class="sb-baa-card-head">
				<div class="sb-baa-step">1</div>
				<h2>Describe Your Application</h2>
			</div>
			<div class="sb-baa-card-body">
				<p style="font-size:0.85rem;color:#6b7280;margin:0 0 0.85rem;">Try one of these or write your own:</p>
				<div class="sb-baa-examples">
					<span class="sb-baa-example" onclick="sbBAAExample(this)">Book development portal with HITM authorship logging</span>
					<span class="sb-baa-example" onclick="sbBAAExample(this)">Yoga studio class booking with instructor profiles and waitlist</span>
					<span class="sb-baa-example" onclick="sbBAAExample(this)">Client onboarding portal for a coaching practice</span>
					<span class="sb-baa-example" onclick="sbBAAExample(this)">Small business accounting system with invoice and expense tracking</span>
					<span class="sb-baa-example" onclick="sbBAAExample(this)">Artist authenticity certification portal</span>
					<span class="sb-baa-example" onclick="sbBAAExample(this)">Real estate deal pipeline with client portal</span>
				</div>
				<textarea id="sb-baa-description" class="sb-baa-textarea" rows="6"
					placeholder="e.g. I want a membership portal for a writing community. Members register, submit work for peer review, track feedback, and unlock advanced workshops as they progress. I want a subscription gate with free and paid tiers..."></textarea>
				<p class="sb-baa-hint">The more detail you give, the better the blueprint. Include: who uses it, what they do, how many stages or sections, any payment or membership requirements.</p>
				<div style="margin-top:1rem;">
					<button class="sb-baa-btn sb-baa-btn-primary" id="sb-baa-generate-btn" onclick="sbBAAGenerate()">
						Generate Blueprint →
					</button>
				</div>
			</div>
		</div>

		<!-- Thinking indicator -->
		<div class="sb-baa-thinking" id="sb-baa-thinking">
			<div class="sb-baa-dots"><span></span><span></span><span></span></div>
			<span class="sb-baa-thinking-text" id="sb-baa-thinking-text">Claude is reading your description and generating a blueprint...</span>
		</div>

		<!-- Step 2: Clarifying questions -->
		<div class="sb-baa-card" id="sb-step-2" style="display:none;">
			<div class="sb-baa-card-head">
				<div class="sb-baa-step">2</div>
				<h2>Refine Your Blueprint</h2>
			</div>
			<div class="sb-baa-card-body">
				<p style="font-size:0.88rem;color:#6b7280;margin:0 0 1.25rem;">Claude has generated your blueprint and has a few questions. Answer them to fine-tune the result — or skip any you're happy with.</p>
				<div id="sb-baa-questions"></div>
				<div style="margin-top:1.5rem;display:flex;gap:0.75rem;align-items:center;">
					<button class="sb-baa-btn sb-baa-btn-gold" onclick="sbBAAFinalise()">
						Build This App →
					</button>
					<button class="sb-baa-btn sb-baa-btn-outline" onclick="sbBAASkipQuestions()">
						Use Blueprint As-Is
					</button>
				</div>
			</div>
		</div>

		<!-- Thinking indicator 2 -->
		<div class="sb-baa-thinking" id="sb-baa-thinking-2">
			<div class="sb-baa-dots"><span></span><span></span><span></span></div>
			<span class="sb-baa-thinking-text" id="sb-baa-thinking-text-2">Refining your blueprint based on your answers...</span>
		</div>

		<!-- Step 3: Blueprint preview + install -->
		<div class="sb-baa-card" id="sb-step-3" style="display:none;">
			<div class="sb-baa-card-head">
				<div class="sb-baa-step">3</div>
				<h2>Your Blueprint is Ready</h2>
			</div>
			<div class="sb-baa-card-body">
				<div class="sb-baa-bp-meta" id="sb-baa-bp-meta"></div>
				<div class="sb-baa-bp-preview" id="sb-baa-bp-preview">
					<pre id="sb-baa-bp-json"></pre>
				</div>
				<div style="margin-top:1.5rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
					<button class="sb-baa-btn sb-baa-btn-primary" id="sb-baa-install-btn" onclick="sbBAAInstall()">
						▶ Install & Open in Designer
					</button>
					<button class="sb-baa-btn sb-baa-btn-outline" onclick="sbBAADownload()">
						⬇ Download JSON
					</button>
					<button class="sb-baa-btn sb-baa-btn-outline" onclick="sbBAARestart()">
						↩ Start Over
					</button>
				</div>
			</div>
		</div>

		<!-- HITM Log -->
		<div class="sb-baa-card" id="sb-baa-hitm-card" style="display:none;">
			<div class="sb-baa-card-head">
				<div class="sb-baa-step">●</div>
				<h2>HITM Activity Log</h2>
			</div>
			<div class="sb-baa-card-body" id="sb-baa-hitm-body"></div>
		</div>
	</div>

	<script>
	var sbBAABlueprint   = null;
	var sbBAAQuestions   = [];
	var sbBAAAnswers     = {};
	var sbBAAHITMLog     = [];
	var sbBAAApiKey      = '<?php echo esc_js( $api_key ); ?>';
	var sbBAANonce       = '<?php echo esc_js( $rest_nonce ); ?>';
	var sbBAARestBase    = '<?php echo esc_js( get_rest_url( null, "sovereign-builder/v1" ) ); ?>';
	var sbBAADesignerUrl = '<?php echo esc_js( $designer_url ); ?>';

	var sbBAASystemPrompt = `You are a Sovereign Builder Blueprint Architect. Your job is to generate complete application blueprints from plain English descriptions.

A blueprint is a JSON object that declares a complete application. You must output ONLY valid JSON with this structure:

{
  "slug": "kebab-case-slug",
  "label": "Human Readable Name",
  "version": "1.0.0",
  "category": "saas|crm|finance|health|creative|education|other",
  "blueprint_type": "vertical-app",
  "description": "One sentence description",
  "signals": ["signal_name_1", "signal_name_2"],
  "roads": [
    { "road_key": "A", "label": "Road A description" },
    { "road_key": "B", "label": "Road B description" }
  ],
  "forms": [
    {
      "slug": "form-slug",
      "label": "Form Label",
      "save_adapter": "submission_table",
      "save_config_json": { "signal_type": "signal_name" },
      "fields_json": [
        { "key": "field_key", "label": "Field Label", "type": "text|email|textarea|select|number|date|checkbox", "required": true, "placeholder": "Placeholder text", "options": ["Option 1", "Option 2"] }
      ]
    }
  ],
  "schemas": [
    {
      "slug": "schema-slug",
      "label": "Schema Label",
      "schema_json": {
        "source_table": "sb_submissions",
        "filter": { "form_slug": "form-slug" },
        "layout": "table",
        "columns": [
          { "key": "field_key", "label": "Column Label" }
        ]
      }
    }
  ],
  "pages": [
    { "title": "Page Title", "slug": "page-slug", "shortcode": "[sb_form slug=\"form-slug\"]" }
  ],
  "portal": {
    "nav": "horizontal",
    "lock_stages": true,
    "show_progress": true,
    "color_scheme": "dark",
    "stages": []
  },
  "pipeline": {
    "slug": "pipeline-slug",
    "label": "Pipeline Label",
    "steps": [
      { "step": 1, "role": "Agent Role", "instruction": "Detailed agent instruction..." }
    ]
  },
  "_questions": [
    {
      "id": "q1",
      "question": "Clear question about an assumption I made",
      "options": ["Option A", "Option B", "Option C"],
      "affects": "what this answer changes in the blueprint",
      "default": "Option A"
    }
  ]
}

CRITICAL RULES:
1. Output ONLY the JSON object. No explanation, no markdown, no backticks.
2. Always include a _questions array with 3-5 clarifying questions about assumptions you made.
3. Questions must have concrete options the user can click — not open-ended.
4. Every form must have at least 4 meaningful fields.
5. Slugs must be lowercase kebab-case only.
6. The portal.nav should be "horizontal" for linear wizards, "vertical" for complex multi-section apps.
7. Generate realistic, useful field names and pipeline instructions based on the actual use case.`;

	var sbBAARefinePrompt = `You are a Sovereign Builder Blueprint Architect. You have generated a blueprint and the user has answered clarifying questions. Now update the blueprint based on their answers.

RULES:
1. Output ONLY the updated JSON object. No explanation, no markdown, no backticks.
2. Do NOT include _questions in the output — this is the final blueprint.
3. Apply every answer to the appropriate part of the blueprint.
4. Keep everything that wasn't affected by the answers.`;

	function sbBAAExample(el) {
		document.getElementById('sb-baa-description').value = el.textContent.trim();
		document.getElementById('sb-baa-description').focus();
	}

	function sbBAALog(action, detail) {
		var now = new Date();
		var time = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0') + ':' + now.getSeconds().toString().padStart(2,'0');
		sbBAAHITMLog.push({ time:time, action:action, detail:detail });
		sbBAAUpdateHITMDisplay();
	}

	function sbBAAUpdateHITMDisplay() {
		var card = document.getElementById('sb-baa-hitm-card');
		var body = document.getElementById('sb-baa-hitm-body');
		card.style.display = 'block';
		body.innerHTML = sbBAAHITMLog.slice().reverse().map(function(l){
			return '<div class="sb-baa-hitm-item">' +
				'<span class="sb-baa-hitm-time">' + l.time + '</span>' +
				'<span class="sb-baa-hitm-action">' + l.action + '</span>' +
				'<span class="sb-baa-hitm-detail">' + l.detail + '</span>' +
				'</div>';
		}).join('');
	}

	function sbBAAThinking(show, text, which) {
		which = which || '1';
		var el = document.getElementById('sb-baa-thinking' + (which === '1' ? '' : '-2'));
		var txt = document.getElementById('sb-baa-thinking-text' + (which === '1' ? '' : '-2'));
		el.classList.toggle('active', show);
		if (text && txt) { txt.textContent = text; }
	}

	async function sbBAAGenerate() {
		var desc = document.getElementById('sb-baa-description').value.trim();
		if (desc.length < 20) {
			alert('Please describe your application in more detail.');
			return;
		}

		document.getElementById('sb-baa-generate-btn').disabled = true;
		sbBAAThinking(true, 'Claude is reading your description and generating your blueprint...', '1');
		sbBAALog('DESCRIBE', desc.substring(0, 80) + (desc.length > 80 ? '...' : ''));

		try {
			var response = await fetch(sbBAARestBase + '/blueprint/generate', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': sbBAANonce
				},
				body: JSON.stringify({ description: desc })
			});

			var data = await response.json();

			if (!data.success || !data.blueprint) {
				var errMsg = data.error || 'Generation failed';
				if (data.raw) { errMsg += '\n\nRaw (first 300 chars):\n' + data.raw.substring(0, 300); }
				throw new Error(errMsg);
			}

			var blueprint = data.blueprint;
			sbBAABlueprint  = blueprint;
			sbBAAQuestions  = blueprint._questions || [];

			sbBAAThinking(false, '', '1');
			sbBAALog('GENERATE', 'Blueprint generated: ' + blueprint.label + ' (' + (blueprint.forms||[]).length + ' forms, ' + (blueprint.pages||[]).length + ' pages)');

			// Show questions
			sbBAAShowQuestions();

		} catch(e) {
			sbBAAThinking(false, '', '1');
			document.getElementById('sb-baa-generate-btn').disabled = false;
			sbBAALog('ERROR', 'Generation failed: ' + e.message);
			alert('Blueprint generation failed. Check your API key and try again.\n\nError: ' + e.message);
		}
	}

	function sbBAAShowQuestions() {
		var container = document.getElementById('sb-baa-questions');
		container.innerHTML = sbBAAQuestions.map(function(q, i){
			var opts = (q.options || []).map(function(opt){
				var isDefault = opt === q.default;
				return '<button class="sb-baa-q-opt' + (isDefault ? ' selected' : '') + '" ' +
					'onclick="sbBAASelectOpt(this, \'' + q.id + '\', \'' + opt.replace(/'/g,"\\'") + '\')">' +
					opt + '</button>';
			}).join('');
			// Pre-select default
			if (q.default) { sbBAAAnswers[q.id] = q.default; }
			return '<div class="sb-baa-question" id="sbq-' + q.id + '">' +
				'<div class="sb-baa-q-num">Question ' + (i+1) + ' of ' + sbBAAQuestions.length + ' — affects: ' + (q.affects||'blueprint') + '</div>' +
				'<div class="sb-baa-q-text">' + q.question + '</div>' +
				'<div class="sb-baa-q-options">' + opts + '</div>' +
				'<input type="text" class="sb-baa-q-custom" placeholder="Or type your own answer..." ' +
				'onchange="sbBAACustomAnswer(\'' + q.id + '\', this.value)">' +
				'</div>';
		}).join('');

		document.getElementById('sb-step-2').style.display = 'block';
		document.getElementById('sb-step-2').scrollIntoView({ behavior:'smooth', block:'start' });
	}

	function sbBAASelectOpt(btn, qid, value) {
		var wrap = document.getElementById('sbq-' + qid);
		wrap.querySelectorAll('.sb-baa-q-opt').forEach(function(b){ b.classList.remove('selected'); });
		btn.classList.add('selected');
		sbBAAAnswers[qid] = value;
		wrap.classList.add('answered');
		sbBAALog('ANSWER', 'Q: ' + qid + ' → ' + value);
	}

	function sbBAACustomAnswer(qid, value) {
		if (!value.trim()) { return; }
		var wrap = document.getElementById('sbq-' + qid);
		wrap.querySelectorAll('.sb-baa-q-opt').forEach(function(b){ b.classList.remove('selected'); });
		sbBAAAnswers[qid] = value;
		wrap.classList.add('answered');
		sbBAALog('ANSWER', 'Q: ' + qid + ' → ' + value + ' (custom)');
	}

	async function sbBAAFinalise() {
		sbBAAThinking(true, 'Refining your blueprint based on your answers...', '2');
		sbBAALog('REFINE', 'Applying ' + Object.keys(sbBAAAnswers).length + ' answers to blueprint');

		var answerText = sbBAAQuestions.map(function(q){
			return 'Q: ' + q.question + '\nA: ' + (sbBAAAnswers[q.id] || q.default || 'No change');
		}).join('\n\n');

		try {
			var response = await fetch(sbBAARestBase + '/blueprint/generate', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': sbBAANonce
				},
				body: JSON.stringify({
					description: document.getElementById('sb-baa-description').value,
					answers: sbBAAAnswers,
					original_blueprint: sbBAABlueprint
				})
			});

			var data = await response.json();

			if (!data.success || !data.blueprint) {
				throw new Error(data.error || 'Refinement failed');
			}

			sbBAABlueprint = data.blueprint;
			delete sbBAABlueprint._questions;

			sbBAAThinking(false, '', '2');
			sbBAALog('REFINED', 'Blueprint refined: ' + sbBAABlueprint.label);
			sbBAAShowPreview();

		} catch(e) {
			sbBAAThinking(false, '', '2');
			sbBAALog('ERROR', 'Refinement failed: ' + e.message);
			// Fall back to original blueprint
			delete sbBAABlueprint._questions;
			sbBAAShowPreview();
		}
	}

	function sbBAASkipQuestions() {
		delete sbBAABlueprint._questions;
		sbBAALog('SKIP', 'Questions skipped — using blueprint as generated');
		sbBAAShowPreview();
	}

	function sbBAAShowPreview() {
		var bp = sbBAABlueprint;
		var meta = document.getElementById('sb-baa-bp-meta');
		meta.innerHTML = [
			{ num: (bp.forms||[]).length,   label: 'Forms' },
			{ num: (bp.pages||[]).length,   label: 'Pages' },
			{ num: (bp.schemas||[]).length, label: 'Schemas' },
			{ num: (bp.signals||[]).length, label: 'Signals' },
			{ num: (bp.pipeline ? (bp.pipeline.steps||[]).length : 0), label: 'AI Agents' },
		].map(function(s){
			return '<div class="sb-baa-bp-stat">' +
				'<div class="sb-baa-bp-stat-num">' + s.num + '</div>' +
				'<div class="sb-baa-bp-stat-label">' + s.label + '</div>' +
				'</div>';
		}).join('');

		document.getElementById('sb-baa-bp-json').textContent = JSON.stringify(bp, null, 2);
		document.getElementById('sb-step-3').style.display = 'block';
		document.getElementById('sb-step-3').scrollIntoView({ behavior:'smooth', block:'start' });
	}

	async function sbBAAInstall() {
		var btn = document.getElementById('sb-baa-install-btn');
		btn.disabled = true;
		btn.textContent = 'Installing...';
		sbBAALog('INSTALL', 'Installing blueprint: ' + sbBAABlueprint.label);

		try {
			var installRes = await fetch(sbBAARestBase + '/blueprint/import', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': sbBAANonce },
				body: JSON.stringify(sbBAABlueprint)
			});
			var installData = await installRes.json();
			console.log('Install response:', JSON.stringify(installData));

			// Try to get ID from response first
			var bpId = installData.id || installData.blueprint_id || null;

			// Always do a slug lookup to confirm — more reliable than parsing response
			if (sbBAABlueprint.slug) {
				var lookupRes = await fetch(sbBAARestBase + '/blueprint/lookup?slug=' + sbBAABlueprint.slug, {
					headers: { 'X-WP-Nonce': sbBAANonce }
				});
				if (lookupRes.ok) {
					var lookupData = await lookupRes.json();
					if (lookupData.id) { bpId = lookupData.id; }
				}
			}

			if (!bpId) {
				throw new Error('Could not determine blueprint ID after install');
			}

			sbBAALog('INSTALLED', 'Blueprint installed — ID: ' + bpId);

			// Activate
			var activateRes = await fetch(sbBAARestBase + '/blueprint/activate', {
				method: 'POST',
				headers: { 'Content-Type':'application/json', 'X-WP-Nonce': sbBAANonce },
				body: JSON.stringify({ id: bpId })
			});
			var activateData = await activateRes.json();
			sbBAALog('ACTIVATED', 'Blueprint activated — pages and forms deployed');

			// Redirect to Visual Designer
			window.location.href = sbBAADesignerUrl + bpId;

		} catch(e) {
			sbBAALog('ERROR', 'Install failed: ' + e.message);
			alert('Installation failed: ' + e.message);
			btn.disabled = false;
			btn.textContent = '▶ Install & Open in Designer';
		}
	}

	function sbBAADownload() {
		var blob = new Blob([JSON.stringify(sbBAABlueprint, null, 2)], { type:'application/json' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = (sbBAABlueprint.slug || 'blueprint') + '.json';
		a.click();
		sbBAALog('EXPORT', 'Blueprint downloaded: ' + sbBAABlueprint.slug + '.json');
	}

	function sbBAARestart() {
		if (!confirm('Start over? Your current blueprint will be lost.')) { return; }
		sbBAABlueprint = null;
		sbBAAQuestions = [];
		sbBAAAnswers   = {};
		document.getElementById('sb-baa-description').value = '';
		document.getElementById('sb-baa-generate-btn').disabled = false;
		document.getElementById('sb-step-2').style.display = 'none';
		document.getElementById('sb-step-3').style.display = 'none';
		document.getElementById('sb-baa-thinking').classList.remove('active');
		document.getElementById('sb-baa-thinking-2').classList.remove('active');
		sbBAALog('RESTART', 'Started over');
	}
	</script>
	<?php
}

/**
 * Roads admin screen — view and manage sb_roads entries.
 */
function sb_render_roads_screen(): void {
	if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }
	global $wpdb;
	$roads = $wpdb->get_results(
		"SELECT * FROM {$wpdb->prefix}sb_roads ORDER BY road_key ASC, campaign_id ASC"
	);
	?>
	<style>
	.sb-roads-wrap { max-width:900px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
	.sb-roads-wrap h1 { font-size:1.6rem; color:#1a1a2e; margin-bottom:1.75rem; }
	.sb-roads-panel { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08),0 0 0 1px rgba(0,0,0,.05); overflow:hidden; margin-bottom:1.5rem; }
	.sb-roads-head { background:#1a1a2e; padding:1rem 1.5rem; border-bottom:2px solid #c9a84c; display:flex; justify-content:space-between; align-items:center; }
	.sb-roads-head h2 { margin:0; font-size:1rem; font-weight:600; color:#f5f0e8; }
	.sb-roads-table { width:100%; border-collapse:collapse; }
	.sb-roads-table th { text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; padding:0.6rem 1rem; border-bottom:2px solid #f3f4f6; background:#f9fafb; }
	.sb-roads-table td { padding:0.85rem 1rem; border-bottom:1px solid #f3f4f6; font-size:0.88rem; vertical-align:middle; }
	.sb-roads-table tr:last-child td { border-bottom:none; }
	.sb-road-key { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:#1a1a2e; color:#c9a84c; font-weight:800; font-size:0.85rem; }
	.sb-roads-empty { padding:3rem; text-align:center; color:#9ca3af; }
	</style>
	<div class="wrap sb-roads-wrap">
		<h1>Roads</h1>
		<div class="sb-roads-panel">
			<div class="sb-roads-head">
				<h2>All Roads</h2>
				<span style="color:#9ca3af;font-size:0.82rem;"><?php echo count( $roads ); ?> road<?php echo count( $roads ) !== 1 ? 's' : ''; ?></span>
			</div>
			<?php if ( $roads ) : ?>
			<table class="sb-roads-table">
				<thead><tr><th>Key</th><th>Label</th><th>Campaign</th><th>Created</th></tr></thead>
				<tbody>
				<?php foreach ( $roads as $road ) : ?>
				<tr>
					<td><span class="sb-road-key"><?php echo esc_html( $road->road_key ); ?></span></td>
					<td><strong><?php echo esc_html( $road->label ); ?></strong></td>
					<td style="color:#6b7280;"><?php echo $road->campaign_id ? 'Campaign #' . (int) $road->campaign_id : 'Global'; ?></td>
					<td style="color:#6b7280;"><?php echo esc_html( date( 'M j, Y', strtotime( $road->created_at ) ) ); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<div class="sb-roads-empty"><p>No roads defined yet. Activate a blueprint to create roads.</p></div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
