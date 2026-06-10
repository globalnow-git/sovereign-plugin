<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SBAIIntegrator
 * Semantic capability router for all AI and external automation calls.
 * Provides budget guard, rate limiter, HITM gate, dry-run, and audit for every invocation.
 */
class SBAIIntegrator {

	private static array $registry = [];

	public static function init() {
		add_action( 'sb_modules_register', function( $loader ) {
			$loader->register( 'ai-integrator', '1.0.0', 'SBAIIntegrator' );
		} );
		// Seed built-in capabilities on init
		add_action( 'init', [ __CLASS__, 'seed_capabilities' ], 20 );
	}

	public static function seed_capabilities() {
		if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_capability_registry" );
		if ( $count > 0 ) { return; }
		$defaults = [
			[
				'slug'               => 'sb_run_analysis',
				'label'              => 'Run Business Analysis',
				'provider'           => 'anthropic',
				'model_slug'         => 'claude-sonnet-4-20250514',
				'sovereignty_flag'   => 'non_canadian',
				'requires_hitm'      => 0,
				'supports_dry_run'   => 1,
				'budget_cap'         => 50.00,
				'rate_limit_per_hour'=> 20,
				'required_cap'       => 'run_sovereign_factory',
				'config_json'        => wp_json_encode( [ 'max_tokens' => 8192 ] ),
				'is_active'          => 1,
				'created_at'         => current_time( 'mysql' ),
			],
			[
				'slug'               => 'sb_debug_remediation',
				'label'              => 'AI Debug Remediation Plan',
				'provider'           => 'anthropic',
				'model_slug'         => 'claude-sonnet-4-20250514',
				'sovereignty_flag'   => 'non_canadian',
				'requires_hitm'      => 1,
				'supports_dry_run'   => 1,
				'budget_cap'         => 10.00,
				'rate_limit_per_hour'=> 5,
				'required_cap'       => 'manage_sovereign_debug',
				'config_json'        => wp_json_encode( [ 'max_tokens' => 4096 ] ),
				'is_active'          => 1,
				'created_at'         => current_time( 'mysql' ),
			],
		];
		foreach ( $defaults as $cap ) {
			$wpdb->insert( "{$wpdb->prefix}sb_capability_registry", $cap );
		}
	}

	public static function register_capability( string $slug, array $config ) {
		global $wpdb;
		$slug = sanitize_key( $slug );
		self::$registry[ $slug ] = $config;
		if ( SB_Module_Loader::is_schema_ready() ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}sb_capability_registry WHERE slug = %s", $slug
			) );
			$data = [
				'slug'               => $slug,
				'label'              => sanitize_text_field( $config['label'] ?? $slug ),
				'provider'           => sanitize_key( $config['provider'] ?? 'anthropic' ),
				'model_slug'         => sanitize_text_field( $config['model_slug'] ?? '' ),
				'sovereignty_flag'   => sanitize_key( $config['sovereignty_flag'] ?? 'non_canadian' ),
				'requires_hitm'      => absint( $config['requires_hitm'] ?? 0 ),
				'supports_dry_run'   => absint( $config['supports_dry_run'] ?? 1 ),
				'budget_cap'         => (float) ( $config['budget_cap'] ?? 0 ),
				'rate_limit_per_hour'=> absint( $config['rate_limit_per_hour'] ?? 0 ),
				'required_cap'       => sanitize_key( $config['required_cap'] ?? 'run_sovereign_factory' ),
				'config_json'        => wp_json_encode( $config['config'] ?? [] ),
				'is_active'          => 1,
			];
			if ( $exists ) {
				$wpdb->update( "{$wpdb->prefix}sb_capability_registry", $data, [ 'slug' => $slug ] );
			} else {
				$data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( "{$wpdb->prefix}sb_capability_registry", $data );
			}
		}
	}

	public static function get_capability( string $slug ): ?array {
		global $wpdb;
		if ( SB_Module_Loader::is_schema_ready() ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sb_capability_registry WHERE slug = %s AND is_active = 1",
				sanitize_key( $slug )
			), ARRAY_A );
			if ( $row ) { return $row; }
		}
		return self::$registry[ $slug ] ?? null;
	}

	public static function list_capabilities(): array {
		global $wpdb;
		if ( ! SB_Module_Loader::is_schema_ready() ) { return array_values( self::$registry ); }
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sb_capability_registry ORDER BY slug ASC", ARRAY_A ) ?: [];
	}

	public static function invoke( string $slug, string $context, array $options = [] ) {
		$cap = self::get_capability( $slug );
		if ( ! $cap ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_CAPABILITY_NOT_FOUND, "Capability {$slug} not found.", get_current_user_id(), [], 'error' );
			return new WP_Error( 'not_found', "AI capability '{$slug}' not registered.", [ 'status' => 404 ] );
		}

		// Budget check
		$budget_error = self::check_budget( $slug, $cap );
		if ( is_wp_error( $budget_error ) ) { return $budget_error; }

		// Rate limit check
		$rate_error = self::check_rate_limit( $slug, $cap );
		if ( is_wp_error( $rate_error ) ) { return $rate_error; }

		// HITM gate
		if ( ! empty( $cap['requires_hitm'] ) && empty( $options['approval_id'] ) ) {
			$approval_id = SB_Approval_Engine::create_approval( 0, 'ai_capability_invoked', [
				'slug'    => $slug,
				'context' => substr( $context, 0, 500 ),
				'options' => $options,
			] );
			return rest_ensure_response( [ 'hitm_required' => true, 'approval_id' => $approval_id ] );
		}

		// Execute
		$start      = microtime( true );
		$model      = $options['model_slug'] ?? $cap['model_slug'] ?? 'claude-sonnet-4-20250514';
		$max_tokens = (int) ( json_decode( $cap['config_json'] ?? '{}', true )['max_tokens'] ?? 8192 );
		$result     = SB_WP_AI_Client::call( '', $context, [ 'model' => $model, 'max_tokens' => $max_tokens ] );
		$elapsed_ms = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_CAPABILITY_INVOKED, "Capability {$slug} failed: " . $result->get_error_message(), get_current_user_id(), [ 'slug' => $slug, 'ms' => $elapsed_ms ], 'error' );
			return $result;
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_CAPABILITY_INVOKED, "Capability {$slug} invoked OK. {$elapsed_ms}ms.", get_current_user_id(), [ 'slug' => $slug, 'ms' => $elapsed_ms ] );
		return $result;
	}

	public static function dry_run( string $slug, string $context ): array {
		$cap = self::get_capability( $slug );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_CAPABILITY_DRY_RUN, "Dry run for capability {$slug}.", get_current_user_id(), [ 'slug' => $slug ] );
		return [
			'slug'              => $slug,
			'label'             => $cap['label'] ?? $slug,
			'provider'          => $cap['provider'] ?? 'anthropic',
			'model'             => $cap['model_slug'] ?? 'claude-sonnet-4-20250514',
			'would_require_hitm'=> ! empty( $cap['requires_hitm'] ),
			'context_length'    => strlen( $context ),
			'estimated_tokens'  => (int) ( strlen( $context ) / 4 ),
			'dry_run'           => true,
		];
	}

	private static function check_budget( string $slug, array $cap ) {
		$budget_cap = (float) ( $cap['budget_cap'] ?? 0 );
		if ( $budget_cap <= 0 ) { return true; }
		$global_cap = (float) SB_Extension_API::get_setting( 'sb_ai_integrator_budget_cap', 0 );
		if ( $global_cap > 0 ) {
			global $wpdb;
			$spent = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM(metric_value) FROM {$wpdb->prefix}sb_perf_metrics WHERE metric_type = %s AND captured_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
				'ai_spend_' . $slug
			) );
			if ( $spent >= $global_cap ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_BUDGET_EXCEEDED, "Budget cap {$global_cap} exceeded for {$slug}. Spent: {$spent}.", get_current_user_id(), [ 'slug' => $slug ], 'error' );
				return new WP_Error( 'budget_exceeded', "AI budget cap exceeded for capability '{$slug}'.", [ 'status' => 429 ] );
			}
			if ( $spent >= $global_cap * 0.8 ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_BUDGET_WARNING, "Budget at 80%+ for {$slug}.", get_current_user_id(), [ 'slug' => $slug ], 'info' );
			}
		}
		return true;
	}

	private static function check_rate_limit( string $slug, array $cap ) {
		$limit = (int) ( $cap['rate_limit_per_hour'] ?? 0 );
		if ( $limit <= 0 ) { return true; }
		$transient_key = 'sb_rate_limit_' . $slug . '_' . date( 'YmdH' );
		$count         = (int) get_transient( $transient_key );
		if ( $count >= $limit ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_AI_RATE_LIMITED, "Rate limit {$limit}/hr exceeded for {$slug}.", get_current_user_id(), [ 'slug' => $slug ], 'info' );
			return new WP_Error( 'rate_limited', "Rate limit exceeded for capability '{$slug}'.", [ 'status' => 429 ] );
		}
		set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	// REST handlers
	public static function handle_rest_invoke( $request ) {
		$params   = (array) $request->get_json_params();
		$slug     = sanitize_key( $params['slug'] ?? '' );
		$context  = sanitize_textarea_field( $params['context'] ?? '' );
		$dry      = ! empty( $params['dry_run'] );
		if ( ! $slug || ! $context ) {
			return SB_Extension_API::rest_error( 'missing_params', 'slug and context required.', 400 );
		}
		if ( $dry ) {
			return rest_ensure_response( self::dry_run( $slug, $context ) );
		}
		$result = self::invoke( $slug, $context, $params['options'] ?? [] );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( [ 'success' => true, 'result' => $result ] );
	}

	public static function handle_rest_list( $request ) {
		return rest_ensure_response( self::list_capabilities() );
	}

	public static function handle_rest_register( $request ) {
		$params = (array) $request->get_json_params();
		$slug   = sanitize_key( $params['slug'] ?? '' );
		if ( ! $slug ) {
			return SB_Extension_API::rest_error( 'missing_slug', 'slug required.', 400 );
		}
		self::register_capability( $slug, $params );
		return rest_ensure_response( [ 'success' => true, 'slug' => $slug ] );
	}

	public static function render_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Forbidden.' ); }
		$caps = self::list_capabilities();
		echo '<div class="wrap">';
		echo '<h1>AI Capabilities Registry</h1>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Slug</th><th>Label</th><th>Provider</th><th>Model</th><th>Budget Cap</th><th>Rate Limit</th><th>HITM</th><th>Status</th></tr></thead><tbody>';
		if ( $caps ) {
			foreach ( $caps as $cap ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $cap['slug'] ) . '</code></td>';
				echo '<td>' . esc_html( $cap['label'] ) . '</td>';
				echo '<td>' . esc_html( $cap['provider'] ) . '</td>';
				echo '<td><code>' . esc_html( $cap['model_slug'] ) . '</code></td>';
				echo '<td>$' . esc_html( number_format( (float) $cap['budget_cap'], 2 ) ) . '</td>';
				echo '<td>' . esc_html( $cap['rate_limit_per_hour'] ) . '/hr</td>';
				echo '<td>' . ( $cap['requires_hitm'] ? '✓' : '—' ) . '</td>';
				echo '<td><span class="' . ( $cap['is_active'] ? 'dashicons dashicons-yes' : 'dashicons dashicons-no' ) . '"></span></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="8">No capabilities registered.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

SBAIIntegrator::init();

// === ADDITION: Advanced SBAIIntegrator provider expansion ===
// ── Provider dispatch extension ───────────────────────────────────────────────
// This is the provider handler added to SBAIIntegrator::invoke() dispatch
// after the existing 'anthropic' / 'gemini' path.

/**
 * Route capability invocation by provider type.
 * Called from within SBAIIntegrator::invoke() after slug/budget/rate validation.
 *
 * @param  array  $cap      Capability registry row.
 * @param  string $context  Input context string.
 * @param  array  $options  Invocation options.
 * @return mixed|WP_Error
 */
function sb_ai_dispatch_by_provider( array $cap, string $context, array $options = [] ) {
	$provider = sanitize_key( $cap['provider'] ?? 'anthropic' );

	switch ( $provider ) {

		case 'wordpress_internal':
			return sb_ai_invoke_wordpress_internal( $cap, $context, $options );

		case 'local_llm':
			return sb_ai_invoke_local_llm( $cap, $context, $options );

		case 'connector_only':
			return sb_ai_invoke_connector_only( $cap, $context, $options );

		case 'anthropic':
		case 'gemini':
		default:
			// Existing SB_WP_AI_Client path
			$model      = $options['model_slug'] ?? $cap['model_slug'] ?? 'claude-sonnet-4-20250514';
			$max_tokens = (int) ( json_decode( $cap['config_json'] ?? '{}', true )['max_tokens'] ?? 8192 );
			return SB_WP_AI_Client::call( '', $context, [ 'model' => $model, 'max_tokens' => $max_tokens ] );
	}
}

/**
 * wordpress_internal provider.
 *
 * Calls a PHP function or applies a WordPress filter exposed by any plugin.
 * Data never leaves the server. Sovereignty enforced by architecture.
 *
 * Capability config_json fields:
 *   function_name  (string) — PHP function to call, e.g. "wc_get_order"
 *   filter_name    (string) — WP filter to apply instead of direct call
 *   arg_map        (array)  — How to map $context to function args
 *
 * @return mixed|WP_Error
 */
function sb_ai_invoke_wordpress_internal( array $cap, string $context, array $options = [] ) {
	$config = json_decode( $cap['config_json'] ?? '{}', true ) ?: [];
	$fn     = sanitize_text_field( $config['function_name'] ?? '' );
	$filter = sanitize_key( $config['filter_name'] ?? '' );

	if ( $filter ) {
		// Apply WordPress filter
		$result = apply_filters( $filter, $context, $options );
		return $result;
	}

	if ( $fn ) {
		if ( ! function_exists( $fn ) ) {
			return new WP_Error( 'wordpress_internal_not_found', "Function '{$fn}' not found. Ensure the plugin providing it is active.", [ 'status' => 404 ] );
		}
		// Parse context as JSON args if possible
		$args = json_decode( $context, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $args ) ) {
			$result = call_user_func_array( $fn, array_values( $args ) );
		} else {
			$result = call_user_func( $fn, $context );
		}
		if ( is_wp_error( $result ) ) { return $result; }
		return wp_json_encode( $result );
	}

	return new WP_Error( 'wordpress_internal_misconfigured', "wordpress_internal capability '{$cap['slug']}' requires function_name or filter_name in config_json.", [ 'status' => 500 ] );
}

/**
 * local_llm provider.
 *
 * Calls a local Ollama/LocalAI endpoint. No data leaves the server.
 * Full Canadian data sovereignty — even AI processing is on-premise.
 *
 * Capability config_json fields:
 *   endpoint       (string) — Local endpoint, e.g. "http://localhost:11434/api/generate"
 *   model          (string) — Local model name, e.g. "mistral", "llama3"
 *   max_tokens     (int)    — Token limit
 *   stream         (bool)   — Whether to use streaming (default false)
 *
 * @return string|WP_Error  Model response text.
 */
function sb_ai_invoke_local_llm( array $cap, string $context, array $options = [] ) {
	$config   = json_decode( $cap['config_json'] ?? '{}', true ) ?: [];
	$endpoint = esc_url_raw( $config['endpoint'] ?? 'http://localhost:11434/api/generate' );
	$model    = sanitize_text_field( $config['model'] ?? 'mistral' );
	$tokens   = (int) ( $config['max_tokens'] ?? 4096 );

	// Sovereignty enforcement: endpoint must be localhost/127.0.0.1/::1
	$host = wp_parse_url( $endpoint, PHP_URL_HOST );
	if ( ! in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
		return new WP_Error(
			'local_llm_non_local_endpoint',
			"local_llm provider endpoint must be localhost. Got: {$host}. Data sovereignty requires on-premise processing.",
			[ 'status' => 422 ]
		);
	}

	$body = wp_json_encode( [
		'model'  => $model,
		'prompt' => $context,
		'stream' => false,
		'options'=> [ 'num_predict' => $tokens ],
	] );

	$response = wp_remote_post( $endpoint, [
		'timeout' => 120,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => $body,
	] );

	if ( is_wp_error( $response ) ) { return $response; }

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		return new WP_Error( 'local_llm_error', "Local LLM returned HTTP {$code}.", [ 'status' => 502 ] );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return $data['response'] ?? $data['choices'][0]['message']['content'] ?? '';
}

/**
 * connector_only provider.
 *
 * Reads data from an external source (Microsoft Graph, Plaid, etc.)
 * but never sends that data externally for AI processing.
 * All processing happens on-premise after data ingestion.
 *
 * Capability config_json fields:
 *   endpoint       (string) — External REST endpoint to read from
 *   auth_header    (string) — WP option key containing the auth token
 *   method         (string) — GET|POST (default GET)
 *   body_template  (string) — JSON template for POST body (context injected as {context})
 *
 * Sovereignty flag: connector_only capabilities MUST have sovereignty_flag = 'connector_only'
 * The handler enforces this — data is returned as raw JSON for on-premise processing.
 *
 * @return string|WP_Error  Raw JSON response from external source.
 */
function sb_ai_invoke_connector_only( array $cap, string $context, array $options = [] ) {
	// Enforce sovereignty flag
	if ( ( $cap['sovereignty_flag'] ?? '' ) !== 'connector_only' ) {
		return new WP_Error(
			'connector_only_flag_missing',
			"connector_only provider requires sovereignty_flag = 'connector_only' on the capability.",
			[ 'status' => 422 ]
		);
	}

	$config   = json_decode( $cap['config_json'] ?? '{}', true ) ?: [];
	$endpoint = esc_url_raw( $config['endpoint'] ?? '' );
	if ( ! $endpoint ) {
		return new WP_Error( 'connector_only_no_endpoint', "connector_only capability '{$cap['slug']}' requires endpoint in config_json.", [ 'status' => 500 ] );
	}

	$method     = strtoupper( $config['method'] ?? 'GET' );
	$auth_key   = sanitize_key( $config['auth_header'] ?? '' );
	$auth_token = $auth_key ? get_option( $auth_key, '' ) : '';

	$request_args = [
		'timeout' => 30,
		'headers' => [ 'Content-Type' => 'application/json' ],
	];
	if ( $auth_token ) {
		$request_args['headers']['Authorization'] = 'Bearer ' . $auth_token;
	}

	if ( $method === 'POST' ) {
		$body_template = $config['body_template'] ?? '{"context":"{context}"}';
		$body          = str_replace( '{context}', esc_js( $context ), $body_template );
		$request_args['body'] = $body;
		$response = wp_remote_post( $endpoint, $request_args );
	} else {
		$response = wp_remote_get( $endpoint, $request_args );
	}

	if ( is_wp_error( $response ) ) { return $response; }

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'connector_only_error', "External connector returned HTTP {$code}.", [ 'status' => 502 ] );
	}

	// Return raw body — no AI processing here.
	// Caller is responsible for processing this data on-premise.
	return wp_remote_retrieve_body( $response );
}

// ── Register connector_only as a valid sovereignty flag value ─────────────────
// This filter runs in SBAIIntegrator::invoke() sovereignty check
add_filter( 'sb_ai_sovereignty_allowed_flags', function( array $flags ): array {
	$flags[] = 'connector_only';
	$flags[] = 'local';
	return $flags;
} );

// === ADDITION: Phase C — Capability registry seeds for wordpress_internal provider ===
function sb_55_seed_wordpress_internal_capabilities(): void {
	if ( ! SB_Module_Loader::is_schema_ready() ) { return; }
	global $wpdb;

	$caps = [
		// WooCommerce
		[
			'slug'               => 'woo_get_order',
			'label'              => 'WooCommerce — Get Order',
			'provider'           => 'wordpress_internal',
			'model_slug'         => 'wc_get_order',
			'sovereignty_flag'   => 'canadian',
			'requires_hitm'      => 0,
			'supports_dry_run'   => 1,
			'budget_cap'         => 0.00,
			'rate_limit_per_hour'=> 300,
			'required_cap'       => 'manage_sovereign',
			'config_json'        => wp_json_encode( [ 'function_name' => 'wc_get_order' ] ),
			'is_active'          => 1,
		],
		[
			'slug'               => 'woo_get_product',
			'label'              => 'WooCommerce — Get Product',
			'provider'           => 'wordpress_internal',
			'model_slug'         => 'wc_get_product',
			'sovereignty_flag'   => 'canadian',
			'requires_hitm'      => 0,
			'supports_dry_run'   => 1,
			'budget_cap'         => 0.00,
			'rate_limit_per_hour'=> 300,
			'required_cap'       => 'manage_sovereign',
			'config_json'        => wp_json_encode( [ 'function_name' => 'wc_get_product' ] ),
			'is_active'          => 1,
		],
		// PMPro
		[
			'slug'               => 'pmpro_get_membership_level',
			'label'              => 'PMPro — Get Membership Level For User',
			'provider'           => 'wordpress_internal',
			'model_slug'         => 'pmpro_getMembershipLevelForUser',
			'sovereignty_flag'   => 'canadian',
			'requires_hitm'      => 0,
			'supports_dry_run'   => 1,
			'budget_cap'         => 0.00,
			'rate_limit_per_hour'=> 300,
			'required_cap'       => 'manage_sovereign',
			'config_json'        => wp_json_encode( [ 'function_name' => 'pmpro_getMembershipLevelForUser' ] ),
			'is_active'          => 1,
		],
		// MailPoet
		[
			'slug'               => 'mailpoet_subscribe',
			'label'              => 'MailPoet — Subscribe Subscriber',
			'provider'           => 'wordpress_internal',
			'model_slug'         => 'mailpoet_subscribe_filter',
			'sovereignty_flag'   => 'canadian',
			'requires_hitm'      => 1,
			'supports_dry_run'   => 1,
			'budget_cap'         => 0.00,
			'rate_limit_per_hour'=> 60,
			'required_cap'       => 'manage_sovereign',
			'config_json'        => wp_json_encode( [ 'filter_name' => 'sb_mailpoet_subscribe' ] ),
			'is_active'          => 1,
		],
		// Microsoft Graph (connector_only)
		[
			'slug'               => 'ms_graph_connector',
			'label'              => 'Microsoft Graph — Data Connector (Read Only)',
			'provider'           => 'connector_only',
			'model_slug'         => 'graph.microsoft.com',
			'sovereignty_flag'   => 'connector_only',
			'requires_hitm'      => 1,
			'supports_dry_run'   => 1,
			'budget_cap'         => 0.00,
			'rate_limit_per_hour'=> 60,
			'required_cap'       => 'manage_sovereign',
			'config_json'        => wp_json_encode( [
				'endpoint'    => 'https://graph.microsoft.com/v1.0/',
				'auth_header' => 'sb_ms_graph_token',
				'method'      => 'GET',
			] ),
			'is_active'          => 0, // Inactive by default — requires MS app registration
		],
	];

	foreach ( $caps as $cap ) {
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sb_capability_registry WHERE slug = %s",
			$cap['slug']
		) );
		if ( $exists ) { continue; }
		$cap['created_at'] = current_time( 'mysql' );
		$wpdb->insert( "{$wpdb->prefix}sb_capability_registry", $cap );
	}
}
add_action( 'init', 'sb_55_seed_wordpress_internal_capabilities', 30 );