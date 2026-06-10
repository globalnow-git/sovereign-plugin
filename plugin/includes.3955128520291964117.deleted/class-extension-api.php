<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Extension_API {

	public static function log( $action, $message, $user_id = 0, $context = [], $level = 'info' ) {
		SB_Event_Logger::log_audit( $action, $message, $user_id, $context, $level );
	}

	public static function is_verbose() {
		return SB_Event_Logger::is_verbose();
	}

	public static function is_debug() {
		return SB_Event_Logger::is_debug();
	}

	public static function get_setting( $key, $default = '' ) {
		global $wpdb;
		$sanitized_key = sanitize_key( $key );
		if ( ! SB_Module_Loader::is_schema_ready() ) {
			return get_option( $sanitized_key, $default );
		}
		$db_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT setting_value FROM {$wpdb->prefix}sb_settings WHERE setting_key = %s",
			$sanitized_key
		) );
		if ( null !== $db_value ) {
			return $db_value;
		}
		return get_option( $sanitized_key, $default );
	}

	public static function set_setting( $key, $value ) {
		global $wpdb;
		$sanitized_key = sanitize_key( $key );
		if ( SB_Module_Loader::is_schema_ready() ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}sb_settings WHERE setting_key = %s",
				$sanitized_key
			) );
			if ( $exists ) {
				$wpdb->update(
					"{$wpdb->prefix}sb_settings",
					[ 'setting_value' => $value, 'updated_at' => current_time( 'mysql' ) ],
					[ 'setting_key' => $sanitized_key ]
				);
			} else {
				$wpdb->insert( "{$wpdb->prefix}sb_settings", [
					'setting_key'   => $sanitized_key,
					'setting_value' => $value,
					'setting_type'  => 'string',
					'setting_group' => 'general',
					'updated_at'    => current_time( 'mysql' ),
				] );
			}
		}
		update_option( $sanitized_key, $value );
	}

	public static function has_wp_ai_client() {
		return function_exists( 'wp_ai_client' );
	}

	// DEFECT-003 fix: standardized REST error helper — always includes HTTP status code
	public static function rest_error( string $code, string $message, int $status = 400, array $extra = [] ): WP_Error {
		$data           = array_merge( $extra, [ 'status' => $status ] );
		return new WP_Error( $code, $message, $data );
	}

	public static function get_sovereignty_status() {
		return [
			[ 'service' => 'All user PII residency',           'sovereign' => 'Yes',     'jurisdiction' => 'Canadian soil host' ],
			[ 'service' => 'wp_mail() processing route',        'sovereign' => 'Yes',     'jurisdiction' => 'Canadian SMTP gateway' ],
			[ 'service' => 'Analytics processing',              'sovereign' => 'Yes',     'jurisdiction' => 'Local matrix tables' ],
			[ 'service' => 'Generated image/video storage',     'sovereign' => 'Yes',     'jurisdiction' => 'Downloaded to local uploads' ],
			[ 'service' => 'Anthropic Claude LLM',              'sovereign' => 'No',      'jurisdiction' => 'US Cloud — prompts only, no PII' ],
			[ 'service' => 'OpenAI DALL-E',                     'sovereign' => 'No',      'jurisdiction' => 'US Cloud — descriptions only, no PII' ],
			[ 'service' => 'Facebook/Google Ads',               'sovereign' => 'No',      'jurisdiction' => 'US Cloud — creative only, no PII' ],
			[ 'service' => 'Hedra/Runway video rendering',      'sovereign' => 'No',      'jurisdiction' => 'US Cloud — script only, file downloaded' ],
			[ 'service' => 'Ask5 connectors (webhook/Slack)',   'sovereign' => 'Partial', 'jurisdiction' => 'Depends on endpoint — no PII by default' ],
		];
	}
}