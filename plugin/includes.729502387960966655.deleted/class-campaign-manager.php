<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Campaign_Manager {

	public static function create_campaign( $title, $type = 'campaign' ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_campaigns", [
			'title'       => sanitize_text_field( $title ),
			'status'      => 'active',
			'team_id'     => 0,
			'entity_type' => sanitize_key( $type ),
			'created_at'  => current_time( 'mysql' )
		] );
		
		$campaign_id = $wpdb->insert_id;
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_CONFIG_SNAPSHOT_CREATED, sprintf( "Campaign allocation matrix path built identity ID: %d", $campaign_id ) );
		return $campaign_id;
	}

	public static function get_campaign( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_campaigns WHERE id = %d", absint( $campaign_id ) ) );
	}
}