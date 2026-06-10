<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Design_Contract {

	const FALLBACK_TOKENS = [
		'primary_color'   => '#0073aa',
		'secondary_color' => '#23282d',
		'font_family'     => 'Georgia, serif'
	];

	public static function get_token( $token_key, $campaign_id = 0 ) {
		global $wpdb;
		if ( SB_Module_Loader::is_schema_ready() ) {
			$db_token = $wpdb->get_var( $wpdb->prepare(
				"SELECT token_value FROM {$wpdb->prefix}sb_design_tokens WHERE token_key = %s AND campaign_id = %d LIMIT 1",
				sanitize_key( $token_key ), absint( $campaign_id )
			) );
			if ( $db_token ) {
				return $db_token;
			}
		}
		return self::FALLBACK_TOKENS[ $token_key ] ?? '';
	}
}