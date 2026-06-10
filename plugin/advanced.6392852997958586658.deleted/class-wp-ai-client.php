<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SB_WP_AI_Client
 * Wraps all AI calls — uses WP 7.0 AI Client when available,
 * falls back to direct Anthropic API call on WP 6.x.
 * Sovereignty: prompts leave Canada to reach Anthropic US.
 * Zero user PII included in any prompt. Flag for replacement
 * when Canadian LLM inference becomes available.
 */
class SB_WP_AI_Client {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		if ( function_exists( 'wp_register_abilities' ) ) {
			add_action( 'wp_register_abilities', [ __CLASS__, 'register_abilities' ] );
		}
	}

	public static function self_register( $loader ) {
		$loader->register( 'wp-ai-client', '1.0.0', 'SB_WP_AI_Client' );
	}

	/**
	 * Main AI call — provider-agnostic entry point.
	 *
	 * @param string $system_prompt
	 * @param string $context
	 * @param array  $options  Supports: max_tokens, temperature
	 * @return string|WP_Error
	 */
	public static function call( $system_prompt, $context, $options = [] ) {
		$start     = microtime( true );
		$max_tok   = (int) ( $options['max_tokens'] ?? SB_Extension_API::get_setting( 'sb_max_tokens', 8192 ) );
		$model     = SB_Extension_API::get_setting( 'sb_model_slug', 'claude-sonnet-4-5' );

		// WP 7.0+ AI Client path
		if ( self::is_available() ) {
			try {
				$client = wp_ai_client()->with_model( $model )->get_text_generation();
				$result = $client->get_results( [
					'system'  => $system_prompt,
					'content' => $context,
				] );
				if ( is_wp_error( $result ) ) {
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'WP AI Client error: ' . $result->get_error_message(), 0, [], 'info' );
					return $result;
				}
				$text = $result->get_text();
			} catch ( Exception $e ) {
				return new WP_Error( 'wp_ai_client_exception', $e->getMessage() );
			}
		} else {
			// WP 6.x direct API fallback
			$result = self::call_direct( $system_prompt, $context, $model, $max_tok );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$text = $result;
		}

		$elapsed_ms = round( ( microtime( true ) - $start ) * 1000 );
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_API_RESPONSE_TIME_MS,
			"AI call completed in {$elapsed_ms}ms via " . ( self::is_available() ? 'WP AI Client' : 'direct API' ),
			0,
			[ 'ms' => $elapsed_ms, 'model' => $model ],
			'verbose'
		);

		return $text;
	}

	/**
	 * Direct Anthropic API call — WP 6.x fallback.
	 * Sovereignty: Non-Canadian US endpoint. Prompts only — no PII.
	 */
	private static function call_direct( $system_prompt, $context, $model, $max_tokens ) {
		$api_key = SB_Extension_API::get_setting( 'sb_anthropic_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key not configured. Add it in Sovereign Builder → Settings → API.' );
		}

		$timeout = (int) SB_Extension_API::get_setting( 'sb_api_timeout', 120 );
		$retries = (int) SB_Extension_API::get_setting( 'sb_retry_count', 2 );

		for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_PROMPT_RETRY, "Retry attempt {$attempt}/{$retries}", 0, [], 'verbose' );
				sleep( $attempt * 2 );
			}

			$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
				'timeout' => $timeout,
				'headers' => [
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'      => $model,
					'max_tokens' => $max_tokens,
					'system'     => $system_prompt,
					'messages'   => [ [ 'role' => 'user', 'content' => $context ] ],
				] ),
			] );

			if ( is_wp_error( $response ) ) {
				if ( $attempt === $retries ) {
					SB_Event_Logger::log_audit( SB_Event_Keys::EV_PROMPT_FAILED, 'API call failed: ' . $response->get_error_message(), 0, [], 'info' );
					return $response;
				}
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== (int) $code ) {
				$err_msg = $body['error']['message'] ?? "HTTP {$code}";
				if ( $attempt === $retries ) {
					return new WP_Error( 'api_error', $err_msg );
				}
				continue;
			}

			$text = $body['content'][0]['text'] ?? '';
			if ( empty( $text ) ) {
				return new WP_Error( 'empty_response', 'AI returned empty response.' );
			}

			SB_Event_Logger::log_audit( SB_Event_Keys::EV_PROMPT_FETCHED, 'API call successful.', 0, [], 'verbose' );
			return $text;
		}

		return new WP_Error( 'api_exhausted', 'All retry attempts failed.' );
	}

	/** Returns true if WP 7.0+ AI Client is available. */
	public static function is_available() {
		return function_exists( 'wp_ai_client' );
	}

	/** Returns the configured provider slug from WP Connectors or 'anthropic' as fallback. */
	public static function get_configured_provider() {
		if ( self::is_available() && function_exists( 'wp_get_ai_provider' ) ) {
			return wp_get_ai_provider();
		}
		return 'anthropic';
	}

	/**
	 * WP 7.0 Abilities API registration.
	 * HITM enforced: every Ability creates an approval record before acting.
	 */
	public static function register_abilities( $registry ) {
		$registry->register( 'sb_run_factory', [
			'label'       => 'Run Sovereign Builder factory pipeline',
			'capability'  => 'run_sovereign_factory',
			'callback'    => function( $params ) {
				$campaign_id = absint( $params['campaign_id'] ?? 0 );
				if ( ! $campaign_id ) {
					return new WP_Error( 'missing_campaign', 'campaign_id required.' );
				}
				// HITM: queue approval — never fire directly
				return SB_Approval_Engine::create_approval( $campaign_id, 'factory_ability_request', $params );
			},
		] );

		$registry->register( 'sb_apply_ruleset', [
			'label'       => 'Apply a Sovereign Builder ruleset to a campaign',
			'capability'  => 'manage_sovereign_rulesets',
			'callback'    => function( $params ) {
				$ruleset_id  = absint( $params['ruleset_id'] ?? 0 );
				$campaign_id = absint( $params['campaign_id'] ?? 0 );
				if ( ! $ruleset_id || ! $campaign_id ) {
					return new WP_Error( 'missing_params', 'ruleset_id and campaign_id required.' );
				}
				return SB_Approval_Engine::create_approval( $campaign_id, 'ruleset_ability_apply', $params );
			},
		] );
	}

	/** Settings section render — WP 7.0 notice OR API key field depending on version. */
	public static function render_settings_section() {
		if ( self::is_available() ) {
			echo '<div class="notice notice-info inline"><p>';
			echo '<strong>WP 7.0 AI Client detected.</strong> ';
			echo 'API key managed via <a href="' . esc_url( admin_url( 'options-general.php?page=ai-services' ) ) . '">Settings → Connectors</a>. ';
			echo 'Sovereignty note: Prompts processed on non-Canadian infrastructure (Anthropic US). No user PII included.';
			echo '</p></div>';
		} else {
			echo '<p class="description">WP 6.x detected. Enter your Anthropic API key below.<br>';
			echo '<em>Sovereignty: Prompts leave Canadian soil to reach Anthropic US servers. No user PII is ever included in prompts.</em></p>';
		}
	}
}
SB_WP_AI_Client::init();