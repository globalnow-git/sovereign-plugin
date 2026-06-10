<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBUserFieldMutationService
 * Audited, validated user field reads and writes with history for sensitive fields.
 */
class SBUserFieldMutationService {

	/**
	 * Set a custom user field value.
	 *
	 * @param int    $user_id
	 * @param string $field_slug
	 * @param mixed  $value
	 * @param int    $actor_id
	 * @return bool
	 */
	public static function set( int $user_id, string $field_slug, $value, int $actor_id = 0 ): bool {
		global $wpdb;

		$field = SBUserFieldCatalog::get_field( $field_slug );
		if ( ! $field ) {
			return false;
		}

		$meta_key  = 'sb_field_' . sanitize_key( $field_slug );
		$old_value = get_user_meta( $user_id, $meta_key, true );
		$new_value = $field->field_type === 'textarea'
			? sanitize_textarea_field( (string) $value )
			: sanitize_text_field( (string) $value );

		// Write to usermeta
		update_user_meta( $user_id, $meta_key, $new_value );

		// Record history for sensitive fields or always if history retention is configured
		if ( $field->is_sensitive || (int) SB_Extension_API::get_setting( 'sb_user_field_history_days', 0 ) > 0 ) {
			$wpdb->insert( "{$wpdb->prefix}sb_user_field_history", [
				'user_id'    => $user_id,
				'field_slug' => $field_slug,
				'old_value'  => (string) $old_value,
				'new_value'  => $new_value,
				'changed_by' => $actor_id ?: get_current_user_id(),
				'changed_at' => current_time( 'mysql' ),
			] );
		}

		if ( $field->is_sensitive ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_USER_FIELD_SENSITIVE_CHANGED,
				"Sensitive user field '{$field_slug}' changed for user #{$user_id}",
				$actor_id ?: get_current_user_id(),
				[ 'user_id' => $user_id, 'field' => $field_slug ],
				'info'
			);
		} else {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_USER_FIELD_SET,
				"User field '{$field_slug}' updated for user #{$user_id}",
				$actor_id ?: get_current_user_id(),
				[ 'user_id' => $user_id, 'field' => $field_slug ],
				'info'
			);
		}

		return true;
	}

	/**
	 * Get a custom user field value.
	 *
	 * @param int    $user_id
	 * @param string $field_slug
	 * @return mixed
	 */
	public static function get( int $user_id, string $field_slug ) {
		return get_user_meta( $user_id, 'sb_field_' . sanitize_key( $field_slug ), true );
	}

	/**
	 * Get change history for a user field.
	 *
	 * @param int    $user_id
	 * @param string $field_slug
	 * @param int    $limit
	 * @return array
	 */
	public static function get_history( int $user_id, string $field_slug, int $limit = 25 ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT h.*, u.user_email as actor_email
			 FROM {$wpdb->prefix}sb_user_field_history h
			 LEFT JOIN {$wpdb->users} u ON u.ID = h.changed_by
			 WHERE h.user_id = %d AND h.field_slug = %s
			 ORDER BY h.changed_at DESC
			 LIMIT %d",
			$user_id,
			$field_slug,
			$limit
		), ARRAY_A );
	}

	/**
	 * Purge history older than retention days.
	 * Called by daily cron or repair-system.
	 */
	public static function purge_old_history(): void {
		global $wpdb;

		$days = (int) SB_Extension_API::get_setting( 'sb_user_field_history_days', 90 );
		if ( $days <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}sb_user_field_history WHERE changed_at < %s",
			$cutoff
		) );
	}
}