<?php
/**
 * SBLicensingMatrix — PMPro-based license enforcement with graceful degradation.
 *
 * Model: client sites ping griannaproductions.com PMPro REST API daily.
 * License key = encrypted {member_id}:{site_url} token issued at purchase.
 * No site freezing. Watermark only. You control the lock manually via PMPro.
 * 7-day grace period on ping failure before any degradation.
 *
 * @package SovereignBuilder
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBLicensingMatrix {

	const OPTION_LICENSE_KEY     = 'sb_license_key';
	const OPTION_LICENSE_STATUS  = 'sb_license_status';
	const OPTION_LICENSE_TIER    = 'sb_license_tier';
	const OPTION_LAST_PING       = 'sb_license_last_ping';
	const OPTION_GRACE_START     = 'sb_license_grace_start';
	const OPTION_TELEMETRY_OPT   = 'sb_telemetry_opted_in';
	const OPTION_WATERMARK       = 'sb_license_watermark_active';
	const GRACE_PERIOD_DAYS      = 7;
	const PING_INTERVAL_HOURS    = 24;
	// TOS agreement at purchase covers: site URL, admin email, PMPro member ID, anon usage stats.
	const HUB_ENDPOINT           = 'https://griannaproductions.com/wp-json/sovereign-hub/v1/license-check';
	const HUB_HEALTH_ENDPOINT    = 'https://griannaproductions.com/wp-json/sovereign-hub/v1/health-report';

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'sb_modules_register',         [ __CLASS__, 'self_register' ] );
		add_action( 'sb_daily_license_ping',        [ __CLASS__, 'run_daily_ping' ] );
		add_action( 'admin_notices',                [ __CLASS__, 'render_license_notice' ] );
		add_action( 'sb_render_watermark',          [ __CLASS__, 'render_watermark' ] );
		add_filter( 'sb_feature_allowed',           [ __CLASS__, 'gate_feature' ], 10, 2 );
		add_action( 'rest_api_init',                [ __CLASS__, 'register_routes' ] );

		// Schedule daily ping if not already scheduled
		if ( ! wp_next_scheduled( 'sb_daily_license_ping' ) ) {
			wp_schedule_event( time(), 'daily', 'sb_daily_license_ping' );
		}
	}

	public static function self_register( $loader ): void {
		$loader->register( 'licensing-matrix', '1.0.0', __CLASS__ );
	}

	// ── License key generation (run on your hub, not client sites) ───────────

	public static function generate_license_key( int $member_id, string $site_url ): string {
		$salt    = wp_generate_password( 16, false );
		$payload = base64_encode( wp_json_encode( [
			'member_id' => $member_id,
			'site_url'  => trailingslashit( esc_url_raw( $site_url ) ),
			'salt'      => $salt,
			'issued'    => time(),
		] ) );
		$sig = hash_hmac( 'sha256', $payload, AUTH_KEY );
		return $payload . '.' . $sig;
	}

	public static function verify_license_key( string $key ): array|false {
		$parts = explode( '.', $key, 2 );
		if ( count( $parts ) !== 2 ) { return false; }
		[ $payload, $sig ] = $parts;
		$expected = hash_hmac( 'sha256', $payload, AUTH_KEY );
		if ( ! hash_equals( $expected, $sig ) ) { return false; }
		$data = json_decode( base64_decode( $payload ), true );
		return is_array( $data ) ? $data : false;
	}

	// ── Daily ping ───────────────────────────────────────────────────────────

	public static function run_daily_ping(): void {
		$key = get_option( self::OPTION_LICENSE_KEY, '' );
		if ( ! $key ) {
			// No key installed — start grace if not already started
			self::maybe_start_grace();
			return;
		}

		$health   = self::build_health_payload();
		$telemetry = self::build_telemetry_payload();

		$response = wp_remote_post( self::HUB_ENDPOINT, [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'license_key' => $key,
				'site_url'    => home_url(),
				'admin_email' => get_option( 'admin_email' ),
				'health'      => $health,
				'telemetry'   => ( get_option( self::OPTION_TELEMETRY_OPT, '0' ) === '1' ) ? $telemetry : [ 'opted_out' => true ],
				'sb_version'  => SB_VERSION,
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			// Ping failed — start/continue grace period, do not immediately degrade
			self::maybe_start_grace();
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_LICENSE_PING_FAILED, 'Hub unreachable: ' . $response->get_error_message(), 0, [], 'warning' );
			return;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = sanitize_key( $body['status'] ?? 'unknown' );
		$tier   = sanitize_key( $body['tier'] ?? 'none' );
		$watermark = (bool) ( $body['watermark'] ?? false ); // YOU control this via PMPro on hub

		update_option( self::OPTION_LICENSE_STATUS, $status );
		update_option( self::OPTION_LICENSE_TIER,   $tier );
		update_option( self::OPTION_LAST_PING,       time() );
		update_option( self::OPTION_WATERMARK,       $watermark ? '1' : '0' );

		// Clear grace period on successful valid ping
		if ( 'active' === $status ) {
			delete_option( self::OPTION_GRACE_START );
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_LICENSE_PING_OK, "License active. Tier: {$tier}.", 0, [], 'info' );
		} else {
			self::maybe_start_grace();
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_LICENSE_PING_INACTIVE, "License status: {$status}.", 0, [], 'warning' );
		}

		// Process any hub-pushed notices (support alerts, update notices)
		if ( ! empty( $body['hub_notice'] ) ) {
			update_option( 'sb_hub_notice', sanitize_textarea_field( $body['hub_notice'] ) );
		}

		// Process outbound health alerts — hub emails operator if health is critical
		if ( ! empty( $body['send_health_alert'] ) && ! empty( $body['alert_message'] ) ) {
			self::send_local_health_alert( sanitize_textarea_field( $body['alert_message'] ) );
		}
	}

	// ── Grace period ─────────────────────────────────────────────────────────

	private static function maybe_start_grace(): void {
		if ( ! get_option( self::OPTION_GRACE_START ) ) {
			update_option( self::OPTION_GRACE_START, time() );
		}
	}

	public static function is_in_grace_period(): bool {
		$grace_start = (int) get_option( self::OPTION_GRACE_START, 0 );
		if ( ! $grace_start ) { return false; }
		return ( time() - $grace_start ) < ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );
	}

	public static function is_license_valid(): bool {
		$status = get_option( self::OPTION_LICENSE_STATUS, 'unknown' );
		if ( 'active' === $status ) { return true; }
		// Grace period counts as valid for feature access
		return self::is_in_grace_period();
	}

	// ── Feature gating — graceful degradation ────────────────────────────────
	// Called via apply_filters( 'sb_feature_allowed', true, 'feature_slug' )

	public static function gate_feature( bool $allowed, string $feature ): bool {
		if ( ! $allowed ) { return false; } // already blocked upstream

		// During grace period — everything on, watermark appears
		if ( self::is_in_grace_period() ) { return true; }

		$status = get_option( self::OPTION_LICENSE_STATUS, 'unknown' );
		if ( 'active' === $status ) { return true; }

		// Lapsed — watermark always on, specific features locked
		$locked_when_lapsed = [
			'ai_cap_invoke',       // No API calls
			'blueprint_activate',  // No new activations
			'form_deploy',         // No new deployments
			'surface_deploy',
		];

		// These always work regardless of license
		$always_allowed = [
			'audit_log',
			'approval_process',
			'existing_pages_render',
			'repair_system',
			'health_check',
		];

		if ( in_array( $feature, $always_allowed, true ) ) { return true; }
		if ( in_array( $feature, $locked_when_lapsed, true ) ) { return false; }

		return true; // default allow for unlisted features
	}

	// ── Watermark ─────────────────────────────────────────────────────────────
	// You toggle watermark=true in your PMPro hub response. Never automated.

	public static function is_watermark_active(): bool {
		// SB_DEV_MODE constant or localhost suppresses watermark on dev/staging installs.
		// Define SB_DEV_MODE = true in wp-config.php to suppress on non-production.
		if ( defined( 'SB_DEV_MODE' ) && SB_DEV_MODE ) { return false; }
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) { return false; }
		if ( str_contains( $host, '.local' ) || str_contains( $host, '.test' ) || str_contains( $host, '.dev' ) ) { return false; }
		// Grace period: watermark on as gentle reminder
		if ( self::is_in_grace_period() ) { return true; }
		// Lapsed: watermark on
		if ( ! self::is_license_valid() ) { return true; }
		// Active: only if you manually set it via hub response
		return get_option( self::OPTION_WATERMARK, '0' ) === '1';
	}

	public static function render_watermark(): void {
		if ( ! self::is_watermark_active() ) { return; }
		echo '<div style="position:fixed;bottom:12px;right:12px;background:rgba(0,0,0,0.7);color:#fff;font-size:11px;padding:6px 12px;border-radius:4px;z-index:99999;font-family:sans-serif;">';
		echo 'Powered by <strong>Sovereign Builder</strong> — ';
		echo '<a href="https://griannaproductions.com/sovereign-builder" target="_blank" style="color:#9b6dff;">License Required</a>';
		echo '</div>';
	}

	// ── Admin notice ─────────────────────────────────────────────────────────

	public static function render_license_notice(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) { return; }

		$hub_notice = get_option( 'sb_hub_notice', '' );
		if ( $hub_notice ) {
			echo '<div class="notice notice-info is-dismissible"><p><strong>Sovereign Builder:</strong> ' . esc_html( $hub_notice ) . '</p></div>';
		}

		$status = get_option( self::OPTION_LICENSE_STATUS, 'unknown' );
		if ( 'active' === $status && ! self::is_watermark_active() ) { return; }

		if ( self::is_in_grace_period() ) {
			$grace_start = (int) get_option( self::OPTION_GRACE_START, time() );
			$days_left   = self::GRACE_PERIOD_DAYS - (int) floor( ( time() - $grace_start ) / DAY_IN_SECONDS );
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>Sovereign Builder:</strong> License check failed. ';
			echo esc_html( $days_left ) . ' day(s) remaining in grace period. ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=sb-license' ) ) . '">Manage License</a>';
			echo '</p></div>';
			return;
		}

		if ( 'active' !== $status ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Sovereign Builder:</strong> License inactive. AI routing, blueprint activation, and new deployments are paused. ';
			echo '<a href="https://griannaproductions.com/sovereign-builder" target="_blank">Renew</a> or ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=sb-license' ) ) . '">Enter License Key</a>';
			echo '</p></div>';
		}
	}

	// ── Health payload ────────────────────────────────────────────────────────
	// Sent to hub so concierge can see install health before operator files a ticket.

	private static function build_health_payload(): array {
		$snapshot = [];
		try {
			$snapshot = SB_Debugger::scan_system( 'health' );
		} catch ( \Throwable $e ) {
			$snapshot['scan_error'] = $e->getMessage();
		}
		// Strip any PII — health data is structural only
		unset( $snapshot['users'], $snapshot['emails'] );
		return [
			'missing_tables'  => $snapshot['missing_tables'] ?? [],
			'errors_logged'   => $snapshot['errors_logged'] ?? 0,
			'stuck_steps'     => $snapshot['stuck_steps'] ?? 0,
			'cron_health'     => $snapshot['cron_health'] ?? 'unknown',
			'db_version'      => $snapshot['db_version'] ?? 'unknown',
			'php_version'     => PHP_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'memory_limit'    => ini_get( 'memory_limit' ),
			'approval_queue'  => (int) ( $snapshot['approval_queue_depth'] ?? 0 ),
			'connector_fails' => (int) ( $snapshot['connector_failures'] ?? 0 ),
		];
	}

	// ── Telemetry payload (TOS-covered, anon usage stats) ────────────────────

	private static function build_telemetry_payload(): array {
		global $wpdb;
		return [
			// Aggregate counts only — no content, no user data
			'blueprint_count'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_app_blueprints" ),
			'form_count'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_tiny_forms" ),
			'surface_count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_ui_surfaces" ),
			'schema_count'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_view_schemas" ),
			'submission_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_submissions" ),
			'active_journeys'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_journeys WHERE status = 'active'" ),
			'modules_active'   => SB_Module_Loader::get_active_module_slugs(),
			'sb_version'       => SB_VERSION,
			'site_country'     => get_option( 'timezone_string', 'unknown' ), // timezone proxy for region, not exact location
		];
	}

	// ── Outbound health alert → operator email ────────────────────────────────
	// Hub triggers this by returning send_health_alert=true in ping response.

	private static function send_local_health_alert( string $message ): void {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) { return; }

		$subject = '[Sovereign Builder] Site Health Alert — Action Required';
		$body    = "Hello,\n\n";
		$body   .= "Our monitoring detected an issue with your Sovereign Builder installation:\n\n";
		$body   .= $message . "\n\n";
		$body   .= "Your Sovereign Builder concierge has been notified.\n\n";
		$body   .= "Take action now:\n";
		$body   .= admin_url( 'admin.php?page=sovereign-builder' ) . "\n\n";
		$body   .= "Run System Repair:\n";
		$body   .= rest_url( 'sovereign-builder/v1/repair-system' ) . "\n\n";
		$body   .= "— The Sovereign Builder Team\n";
		$body   .= "https://griannaproductions.com/sovereign-builder\n";

		wp_mail( $admin_email, $subject, $body );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_HEALTH_ALERT_SENT, 'Health alert email dispatched to ' . $admin_email, 0, [], 'info' );
	}

	// ── License settings screen ───────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( 'sovereign-builder/v1', '/license/activate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_rest_activate' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
		register_rest_route( 'sovereign-builder/v1', '/license/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_rest_status' ],
			'permission_callback' => fn() => current_user_can( 'manage_sovereign' ),
		] );
	}

	public static function handle_rest_activate( WP_REST_Request $request ): WP_REST_Response {
		$key = sanitize_text_field( $request->get_param( 'license_key' ) ?? '' );
		if ( ! $key ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'License key required.' ], 400 );
		}
		update_option( self::OPTION_LICENSE_KEY, $key );
		delete_option( self::OPTION_GRACE_START );
		// Trigger immediate ping to validate
		self::run_daily_ping();
		$status = get_option( self::OPTION_LICENSE_STATUS, 'unknown' );
		return new WP_REST_Response( [
			'success' => 'active' === $status,
			'status'  => $status,
			'tier'    => get_option( self::OPTION_LICENSE_TIER, 'none' ),
		], 200 );
	}

	public static function handle_rest_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( [
			'status'         => get_option( self::OPTION_LICENSE_STATUS, 'unknown' ),
			'tier'           => get_option( self::OPTION_LICENSE_TIER, 'none' ),
			'watermark'      => self::is_watermark_active(),
			'grace_period'   => self::is_in_grace_period(),
			'license_valid'  => self::is_license_valid(),
			'last_ping'      => (int) get_option( self::OPTION_LAST_PING, 0 ),
			'telemetry_opted'=> get_option( self::OPTION_TELEMETRY_OPT, '0' ),
		], 200 );
	}

	public static function render_license_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }
		$status    = get_option( self::OPTION_LICENSE_STATUS, 'unknown' );
		$tier      = get_option( self::OPTION_LICENSE_TIER, 'none' );
		$last_ping = (int) get_option( self::OPTION_LAST_PING, 0 );
		$key       = get_option( self::OPTION_LICENSE_KEY, '' );

		echo '<div class="wrap">';
		echo '<h1>Sovereign Builder — License</h1>';

		$color = 'active' === $status ? '#46b450' : ( self::is_in_grace_period() ? '#d67400' : '#d63638' );
		echo '<p><strong>Status:</strong> <span style="color:' . esc_attr( $color ) . ';font-weight:bold;">' . esc_html( strtoupper( $status ) ) . '</span>';
		if ( $tier ) { echo ' &mdash; Tier: <strong>' . esc_html( $tier ) . '</strong>'; }
		if ( $last_ping ) { echo ' &mdash; Last verified: ' . esc_html( human_time_diff( $last_ping ) ) . ' ago'; }
		echo '</p>';

		echo '<h2>Activate License Key</h2>';
		echo '<p>Your license key was delivered with your purchase confirmation from <a href="https://griannaproductions.com" target="_blank">griannaproductions.com</a>.</p>';
		echo '<input type="text" id="sb-license-key" value="' . esc_attr( $key ) . '" style="width:420px;" placeholder="Paste your license key here" />';
		echo ' <button class="button button-primary" id="sb-license-activate">Activate</button>';
		echo '<p id="sb-license-msg" style="margin-top:8px;"></p>';

		// Telemetry opt-in
		$opted = get_option( self::OPTION_TELEMETRY_OPT, '0' );
		echo '<h2>Anonymous Usage Telemetry</h2>';
		echo '<p>Help improve Sovereign Builder by sharing anonymous usage stats (blueprint count, feature flags, WP/PHP version). No personal data, no content, no user data. Covered by your TOS agreement.</p>';
		echo '<label><input type="checkbox" id="sb-telemetry-opt" ' . checked( '1', $opted, false ) . ' /> Share anonymous usage stats</label>';
		echo '<button class="button" id="sb-telemetry-save" style="margin-left:8px;">Save</button>';

		echo '<script>
		jQuery(function($){
			$("#sb-license-activate").on("click", function(){
				var key = $("#sb-license-key").val().trim();
				$.post(sbAdminContext.restBase + "sovereign-builder/v1/license/activate",
					JSON.stringify({license_key: key}),
					function(r){ $("#sb-license-msg").text(r.success ? "✓ License activated. Status: " + r.status : "✗ " + (r.message || "Activation failed.")); },
					"json"
				).fail(function(){ $("#sb-license-msg").text("✗ Request failed."); });
			});
			$("#sb-telemetry-save").on("click", function(){
				var val = $("#sb-telemetry-opt").is(":checked") ? "1" : "0";
				$.post(ajaxurl, {action:"sb_save_telemetry_opt", opted: val, _wpnonce: sbAdminContext.nonce});
			});
		});
		</script>';
		echo '</div>';
	}
}
SBLicensingMatrix::init();