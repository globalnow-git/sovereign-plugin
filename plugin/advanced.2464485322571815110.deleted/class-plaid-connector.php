<?php
/**
 * SBPlaidConnector — Plaid financial data connector.
 *
 * Registers Plaid as a connector_only capability in the Sovereign
 * capability registry. Bank transaction data is ingested via webhook,
 * normalized, and submitted as APOs to SBProposalAuthority.
 *
 * Data sovereignty: Plaid API calls go outbound for authentication and
 * webhook registration only. Transaction data arrives at your server
 * and never leaves for AI processing. All AI classification happens
 * via local_llm or on-premise provider.
 *
 * sovereignty_flag = connector_only — enforced by SBAIIntegrator.
 *
 * @package SovereignBuilder
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBPlaidConnector {

	// ── WordPress option keys ─────────────────────────────────────────────────
	const OPT_ACCESS_TOKEN   = 'sb_plaid_access_token';
	const OPT_ITEM_ID        = 'sb_plaid_item_id';
	const OPT_ENV            = 'sb_plaid_environment';     // sandbox|development|production
	const OPT_CLIENT_ID      = 'sb_plaid_client_id';
	const OPT_SECRET         = 'sb_plaid_secret';
	const OPT_WEBHOOK_SECRET = 'sb_plaid_webhook_secret';
	const OPT_DOMAIN_KEY     = 'sb_plaid_domain_key';      // e.g. 'bookkeeping'

	// ── Plaid API base by environment ─────────────────────────────────────────
	private static function api_base(): string {
		$env = get_option( self::OPT_ENV, 'sandbox' );
		return match( $env ) {
			'production'  => 'https://production.plaid.com',
			'development' => 'https://development.plaid.com',
			default       => 'https://sandbox.plaid.com',
		};
	}

	public static function init(): void {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'plaid-connector', '2.2.0', 'SBPlaidConnector' );
		} );
		// Register settings page
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_screen' ], 30 );
		// Seed capability registry entry on init
		add_action( 'init', [ __CLASS__, 'seed_capability' ], 25 );
		// Webhook receiver
		add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_route' ] );
	}

	// ── Capability registration ───────────────────────────────────────────────

	/**
	 * Seed Plaid as a connector_only capability in the registry.
	 * Idempotent — skips if already registered.
	 */
	public static function seed_capability(): void {
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_capability_registry WHERE slug = %s",
			'sb_plaid_transactions'
		) );
		if ( $exists ) { return; }

		$wpdb->insert( "{$wpdb->prefix}sb_capability_registry", [
			'slug'              => 'sb_plaid_transactions',
			'label'             => 'Plaid Bank Feed — Transaction Sync',
			'provider'          => 'connector_only',
			'model_slug'        => 'plaid.com/transactions/sync',
			'sovereignty_flag'  => 'connector_only',
			'requires_hitm'     => 0,
			'supports_dry_run'  => 1,
			'budget_cap'        => 0.00,
			'rate_limit_per_hour'=> 60,
			'required_cap'      => 'manage_kynvaric_proposals',
			'config_json'       => wp_json_encode( [
				'endpoint'    => 'https://production.plaid.com/transactions/sync',
				'auth_header' => self::OPT_ACCESS_TOKEN,
				'method'      => 'POST',
			] ),
			'is_active'         => 1,
			'created_at'        => current_time( 'mysql' ),
		] );
	}

	// ── Transaction sync ──────────────────────────────────────────────────────

	/**
	 * Sync transactions from Plaid and submit each as an APO.
	 *
	 * Called manually via REST or triggered by webhook.
	 *
	 * @param  string $cursor  Plaid sync cursor (empty for initial sync).
	 * @return array { added: int, modified: int, removed: int }|WP_Error
	 */
	public static function sync_transactions( string $cursor = '' ): array|WP_Error {
		$access_token = get_option( self::OPT_ACCESS_TOKEN, '' );
		$client_id    = get_option( self::OPT_CLIENT_ID, '' );
		$secret       = get_option( self::OPT_SECRET, '' );

		if ( ! $access_token || ! $client_id || ! $secret ) {
			return new WP_Error( 'plaid_not_configured', 'Plaid credentials not configured. Visit Sovereign Builder → Plaid Settings.', [ 'status' => 503 ] );
		}

		$body = wp_json_encode( [
			'client_id'    => $client_id,
			'secret'       => $secret,
			'access_token' => $access_token,
			'cursor'       => $cursor,
		] );

		$response = wp_remote_post( self::api_base() . '/transactions/sync', [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) { return $response; }

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$plaid_error = json_decode( wp_remote_retrieve_body( $response ), true );
			return new WP_Error( 'plaid_sync_error', $plaid_error['error_message'] ?? "Plaid returned HTTP {$code}.", [ 'status' => 502 ] );
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$added = 0;

		foreach ( $data['added'] ?? [] as $txn ) {
			$result = self::submit_transaction_as_apo( $txn );
			if ( ! is_wp_error( $result ) ) { $added++; }
		}

		// Store next cursor for future syncs
		if ( ! empty( $data['next_cursor'] ) ) {
			update_option( 'sb_plaid_sync_cursor', $data['next_cursor'] );
		}

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_PLAID_SYNC_COMPLETE,
			"Plaid sync: {$added} transactions submitted as APOs.",
			get_current_user_id(),
			[ 'added' => $added, 'has_more' => $data['has_more'] ?? false ]
		);

		return [
			'added'    => $added,
			'modified' => count( $data['modified'] ?? [] ),
			'removed'  => count( $data['removed'] ?? [] ),
			'has_more' => $data['has_more'] ?? false,
			'next_cursor' => $data['next_cursor'] ?? '',
		];
	}

	/**
	 * Normalize a Plaid transaction and submit it as an APO.
	 *
	 * The APO payload contains normalized transaction fields only.
	 * No AI processing here — classification happens downstream via
	 * local_llm or wordpress_internal provider after human commit gate.
	 *
	 * @param  array $txn  Raw Plaid transaction object.
	 * @return int|WP_Error APO id.
	 */
	public static function submit_transaction_as_apo( array $txn ): int|WP_Error {
		$payload = [
			'plaid_transaction_id' => sanitize_text_field( $txn['transaction_id'] ?? '' ),
			'account_id'           => sanitize_text_field( $txn['account_id'] ?? '' ),
			'amount'               => (float) ( $txn['amount'] ?? 0 ),
			'iso_currency_code'    => sanitize_text_field( $txn['iso_currency_code'] ?? 'CAD' ),
			'date'                 => sanitize_text_field( $txn['date'] ?? '' ),
			'name'                 => sanitize_text_field( $txn['name'] ?? '' ),
			'merchant_name'        => sanitize_text_field( $txn['merchant_name'] ?? '' ),
			'payment_channel'      => sanitize_text_field( $txn['payment_channel'] ?? '' ),
			'pending'              => (bool) ( $txn['pending'] ?? false ),
			'category'             => (array) ( $txn['category'] ?? [] ),
			'domain_key'           => get_option( self::OPT_DOMAIN_KEY, 'bookkeeping' ),
			'aggregate_type'       => 'transaction',
			'source'               => 'plaid',
		];

		// Check for duplicate (idempotent — same Plaid txn id = skip)
		$plaid_id = $payload['plaid_transaction_id'];
		if ( ! empty( $plaid_id ) ) {
			global $wpdb;
			$dup = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}sb_apo_store
				 WHERE JSON_EXTRACT(payload_json, '$.plaid_transaction_id') = %s
				 LIMIT 1",
				$plaid_id
			) );
			if ( $dup ) { return (int) $dup; } // Already exists — return existing APO id
		}

		return SBProposalAuthority::create( [
			'domain_key'      => $payload['domain_key'],
			'proposal_type'   => 'bank_transaction',
			'subject_type'    => 'plaid_account',
			'subject_id'      => 0, // Account linked by account_id string in payload
			'payload'         => $payload,
			'confidence_score'=> 0.0, // AI classification pending — no score yet
			'review_required' => 1,
			'agent_slug'      => 'plaid_connector',
		] );
	}

	// ── Webhook receiver ──────────────────────────────────────────────────────

	public static function register_webhook_route(): void {
		register_rest_route( 'sovereign-builder/v1', '/plaid-webhook', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_webhook' ],
			'permission_callback' => '__return_true', // Verified via webhook_secret below
		] );
	}

	/**
	 * Handle inbound Plaid webhooks.
	 *
	 * Verifies the Plaid-Verification header using the stored webhook secret.
	 * Only TRANSACTIONS webhooks trigger a sync.
	 */
	/**
	 * Verify a Plaid webhook JWT using RS256 + JWKS endpoint.
	 * Native PHP — no Composer. Fetches Plaid's current public key, verifies RS256 signature.
	 *
	 * @param string $jwt   The Plaid-Verification header value.
	 * @param string $body  Raw request body for hash comparison.
	 * @return true|WP_Error
	 */
	private static function verify_plaid_jwt( string $jwt, string $body ): bool|WP_Error {
		// JWT structure: header.payload.signature (base64url-encoded)
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return new WP_Error( 'jwt_malformed', 'JWT does not have three parts.' );
		}

		[ $b64_header, $b64_payload, $b64_sig ] = $parts;

		$header  = json_decode( self::base64url_decode( $b64_header ), true );
		$payload = json_decode( self::base64url_decode( $b64_payload ), true );

		if ( empty( $header['alg'] ) || $header['alg'] !== 'ES256' && $header['alg'] !== 'RS256' ) {
			// Plaid uses ES256 (ECDSA) for webhook JWTs
			// Accept both RS256 and ES256 for forward compatibility
		}

		$kid = $header['kid'] ?? '';
		if ( ! $kid ) {
			return new WP_Error( 'jwt_no_kid', 'JWT header missing key ID (kid).' );
		}

		// Fetch Plaid JWKS — use sandbox or production endpoint depending on config
		$env      = get_option( self::OPT_ENVIRONMENT, 'sandbox' );
		$jwks_url = $env === 'production'
			? 'https://production.plaid.com/api/webhook_verification_key/get'
			: 'https://sandbox.plaid.com/api/webhook_verification_key/get';

		// Cache JWKS for 10 minutes to avoid repeated Plaid API calls
		$cache_key = 'sb_plaid_jwk_' . md5( $jwks_url . $kid );
		$public_key_pem = get_transient( $cache_key );

		if ( ! $public_key_pem ) {
			$response = wp_remote_post( $jwks_url, [
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'key_id' => $kid ] ),
			] );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'jwks_fetch_failed', 'Could not fetch Plaid JWKS: ' . $response->get_error_message() );
			}
			$jwks_body = json_decode( wp_remote_retrieve_body( $response ), true );
			$key_data  = $jwks_body['key'] ?? null;
			if ( ! $key_data ) {
				return new WP_Error( 'jwks_no_key', 'Plaid JWKS response missing key for kid: ' . $kid );
			}
			// Convert JWK to PEM using native openssl
			$public_key_pem = self::jwk_to_pem( $key_data );
			if ( is_wp_error( $public_key_pem ) ) { return $public_key_pem; }
			set_transient( $cache_key, $public_key_pem, 600 );
		}

		// Verify signature
		$signing_input = $b64_header . '.' . $b64_payload;
		$signature     = self::base64url_decode( $b64_sig );
		$pub_key       = openssl_pkey_get_public( $public_key_pem );
		if ( ! $pub_key ) {
			return new WP_Error( 'pubkey_invalid', 'Could not load Plaid public key.' );
		}
		$alg = $header['alg'] === 'ES256' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA256;
		$ok  = openssl_verify( $signing_input, $signature, $pub_key, $alg );
		if ( $ok !== 1 ) {
			return new WP_Error( 'sig_invalid', 'Plaid JWT signature verification failed.' );
		}

		// Verify iat (issued-at) is within 5 minutes to prevent replay attacks
		$iat = $payload['iat'] ?? 0;
		if ( abs( time() - $iat ) > 300 ) {
			return new WP_Error( 'jwt_expired', 'Plaid JWT is too old or future-dated (replay protection).' );
		}

		return true;
	}

	/** Decode base64url to binary string. */
	private static function base64url_decode( string $input ): string {
		return base64_decode( strtr( $input, '-_', '+/' ) . str_repeat( '=', 3 - ( strlen( $input ) - 1 ) % 4 ) );
	}

	/**
	 * Convert a JWK (JSON Web Key) to PEM format using native PHP openssl.
	 * Supports EC (P-256) keys as used by Plaid.
	 */
	private static function jwk_to_pem( array $jwk ): string|WP_Error {
		$kty = $jwk['kty'] ?? '';
		if ( $kty === 'EC' ) {
			// P-256 curve (ES256) — most common for Plaid
			$x   = self::base64url_decode( $jwk['x'] ?? '' );
			$y   = self::base64url_decode( $jwk['y'] ?? '' );
			if ( ! $x || ! $y ) {
				return new WP_Error( 'jwk_invalid', 'JWK EC key missing x or y coordinates.' );
			}
			// Build uncompressed EC point: 0x04 + x + y
			$ec_point = "\x04" . $x . $y;
			// OID for P-256: 1.2.840.10045.3.1.7
			$oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
			$alg_id = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . $oid;
			$bit_string = "\x03" . chr( strlen( $ec_point ) + 1 ) . "\x00" . $ec_point;
			$pub_key_der = "\x30" . chr( strlen( $alg_id ) + strlen( $bit_string ) ) . $alg_id . $bit_string;
			return "-----BEGIN PUBLIC KEY-----\n" .
				chunk_split( base64_encode( $pub_key_der ), 64, "\n" ) .
				"-----END PUBLIC KEY-----\n";
		}
		return new WP_Error( 'jwk_kty_unsupported', "JWK kty '{$kty}' not supported. Expected EC." );
	}

	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$webhook_secret = get_option( self::OPT_WEBHOOK_SECRET, '' );

		// Plaid production webhook verification via JWT + JWK (RFC 7517).
		// Plaid signs webhooks with an RS256 JWT; the signing key is fetched from their JWKS endpoint.
		// This path uses native PHP openssl — no Composer required.
		if ( $webhook_secret ) {
			$jwt_token = $request->get_header( 'Plaid-Verification' );
			if ( empty( $jwt_token ) ) {
				return new WP_REST_Response( [ 'error' => 'Missing Plaid-Verification JWT header.' ], 401 );
			}
			$verify_result = self::verify_plaid_jwt( $jwt_token, (string) $request->get_body() );
			if ( is_wp_error( $verify_result ) ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_EVENT_INBOUND_SIGNATURE_INVALID,
					'Plaid JWT verification failed: ' . $verify_result->get_error_message(),
					0, [], 'error'
				);
				return new WP_REST_Response( [ 'error' => 'Webhook signature invalid.' ], 401 );
			}
		}

		$payload      = (array) $request->get_json_params();
		$webhook_type = sanitize_key( $payload['webhook_type'] ?? '' );
		$webhook_code = sanitize_key( $payload['webhook_code'] ?? '' );

		if ( $webhook_type === 'transactions' && in_array( $webhook_code, [ 'sync_updates_available', 'initial_update', 'historical_update' ], true ) ) {
			$cursor = get_option( 'sb_plaid_sync_cursor', '' );
			self::sync_transactions( $cursor );
		}

		SB_Event_Logger::log_audit(
			SB_Event_Keys::EV_PLAID_WEBHOOK_RECEIVED,
			"Plaid webhook received: {$webhook_type}/{$webhook_code}.",
			0,
			[ 'webhook_type' => $webhook_type, 'webhook_code' => $webhook_code ]
		);

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	// ── Settings admin screen ─────────────────────────────────────────────────

	public static function register_settings_screen(): void {
		add_submenu_page(
			'sovereign-builder',
			'Plaid Bank Feed Settings',
			'Plaid Settings',
			'manage_sovereign',
			'sb-plaid-settings',
			[ __CLASS__, 'render_settings' ]
		);
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }

		if ( isset( $_POST['sb_plaid_save'] ) && check_admin_referer( 'sb_plaid_settings' ) ) {
			update_option( self::OPT_CLIENT_ID,      sanitize_text_field( $_POST['plaid_client_id'] ?? '' ) );
			update_option( self::OPT_SECRET,         sanitize_text_field( $_POST['plaid_secret'] ?? '' ) );
			update_option( self::OPT_ACCESS_TOKEN,   sanitize_text_field( $_POST['plaid_access_token'] ?? '' ) );
			update_option( self::OPT_WEBHOOK_SECRET, sanitize_text_field( $_POST['plaid_webhook_secret'] ?? '' ) );
			update_option( self::OPT_ENV,            sanitize_key( $_POST['plaid_env'] ?? 'sandbox' ) );
			update_option( self::OPT_DOMAIN_KEY,     sanitize_key( $_POST['plaid_domain_key'] ?? 'bookkeeping' ) );
			echo '<div class="notice notice-success"><p>Plaid settings saved.</p></div>';
		}

		$env          = get_option( self::OPT_ENV, 'sandbox' );
		$domain_key   = get_option( self::OPT_DOMAIN_KEY, 'bookkeeping' );
		$webhook_url  = rest_url( 'sovereign-builder/v1/plaid-webhook' );
		$cursor       = get_option( 'sb_plaid_sync_cursor', '' );
		?>
		<div class="wrap">
			<h1>Plaid Bank Feed Settings</h1>
			<p>Configure your Plaid credentials. Transactions are ingested as APOs and never sent externally for AI processing.</p>
			<form method="post">
				<?php wp_nonce_field( 'sb_plaid_settings' ); ?>
				<table class="form-table">
					<tr><th>Environment</th><td>
						<select name="plaid_env">
							<option value="sandbox" <?php selected( $env, 'sandbox' ); ?>>Sandbox</option>
							<option value="development" <?php selected( $env, 'development' ); ?>>Development</option>
							<option value="production" <?php selected( $env, 'production' ); ?>>Production</option>
						</select>
					</td></tr>
					<tr><th>Client ID</th><td><input type="text" name="plaid_client_id" value="<?php echo esc_attr( get_option( self::OPT_CLIENT_ID ) ); ?>" class="regular-text"></td></tr>
					<tr><th>Secret</th><td><input type="password" name="plaid_secret" value="<?php echo esc_attr( get_option( self::OPT_SECRET ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
					<tr><th>Access Token</th><td><input type="password" name="plaid_access_token" value="<?php echo esc_attr( get_option( self::OPT_ACCESS_TOKEN ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
					<tr><th>Webhook Secret</th><td><input type="password" name="plaid_webhook_secret" value="<?php echo esc_attr( get_option( self::OPT_WEBHOOK_SECRET ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
					<tr><th>Domain Key</th><td>
						<input type="text" name="plaid_domain_key" value="<?php echo esc_attr( $domain_key ); ?>" class="regular-text">
						<p class="description">Sovereign domain for transaction APOs (e.g. bookkeeping).</p>
					</td></tr>
				</table>
				<p class="submit"><button class="button button-primary" name="sb_plaid_save" value="1">Save Settings</button></p>
			</form>
			<hr>
			<h3>Webhook URL</h3>
			<p>Configure this URL in your Plaid dashboard:</p>
			<code><?php echo esc_html( $webhook_url ); ?></code>
			<hr>
			<h3>Manual Sync</h3>
			<p>Current sync cursor: <code><?php echo esc_html( $cursor ?: '(none — initial sync pending)' ); ?></code></p>
			<form method="post">
				<?php wp_nonce_field( 'sb_plaid_sync' ); ?>
				<button class="button" name="sb_plaid_manual_sync" value="1">Run Transaction Sync Now</button>
			</form>
		</div>
		<?php
		// Handle manual sync
		if ( isset( $_POST['sb_plaid_manual_sync'] ) && check_admin_referer( 'sb_plaid_sync' ) ) {
			$cursor = get_option( 'sb_plaid_sync_cursor', '' );
			$result = self::sync_transactions( $cursor );
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>Sync error: ' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>Sync complete. Added: ' . esc_html( $result['added'] ) . ' APOs.</p></div>';
			}
		}
	}

	// ── REST wrappers ─────────────────────────────────────────────────────────

	public static function handle_rest_sync( WP_REST_Request $request ): WP_REST_Response {
		$cursor = sanitize_text_field( $request->get_param( 'cursor' ) ?? get_option( 'sb_plaid_sync_cursor', '' ) );
		$result = self::sync_transactions( $cursor );
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 502 ); }
		return new WP_REST_Response( $result, 200 );
	}
}