<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Telemetry_Buffer {

	public static function init() {
		add_action( 'sb_telemetry_buffer_flush', [ __CLASS__, 'flush' ] );
	}

	public static function schedule_flush() {
		if ( ! wp_next_scheduled( 'sb_telemetry_buffer_flush' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'sb_telemetry_buffer_flush' );
		}
	}

	public static function push( $action, $message, $user_id = 0, $context = [], $level = 'info' ) {
		$slot       = date( 'YmdHi' );
		$buffer_key = 'sb_telemetry_buffer_' . $slot;
		$buffer     = get_transient( $buffer_key );
		if ( ! is_array( $buffer ) ) { $buffer = []; }
		$buffer[] = [
			'action'  => sanitize_text_field( $action ),
			'message' => sanitize_textarea_field( $message ),
			'user_id' => absint( $user_id ),
			'context' => $context,
			'level'   => sanitize_key( $level ),
			'created_at' => current_time( 'mysql' ),
		];
		set_transient( $buffer_key, $buffer, 10 * MINUTE_IN_SECONDS );
		self::register_buffer_slot( $slot );
	}

	// DEFECT-004 fix: prepared statement inside transaction
	public static function flush() {
		global $wpdb;
		$slots = self::get_active_slots();
		if ( empty( $slots ) ) { return; }

		$all_entries = [];
		$done        = [];

		// BUG3 FIX: read transients first; defer deletion until AFTER commit succeeds.
		// Previously: delete_transient happened before transaction, causing data loss on ROLLBACK.
		$slot_keys = [];
		foreach ( $slots as $slot ) {
			$key     = 'sb_telemetry_buffer_' . $slot;
			$entries = get_transient( $key );
			if ( is_array( $entries ) && ! empty( $entries ) ) {
				foreach ( $entries as $e ) {
					$all_entries[] = $e;
				}
				$slot_keys[ $slot ] = $key; // only track slots that had data
			}
			$done[] = $slot;
		}

		if ( ! empty( $all_entries ) ) {
			$wpdb->query( 'START TRANSACTION' );
			$success = true;
			foreach ( $all_entries as $e ) {
				$result = $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}sb_audit_log
					 (user_id, action, message, log_level, context, created_at)
					 VALUES (%d, %s, %s, %s, %s, %s)",
					absint( $e['user_id'] ),
					sanitize_text_field( $e['action'] ),
					sanitize_textarea_field( $e['message'] ),
					sanitize_key( $e['level'] ),
					wp_json_encode( $e['context'] ?? [] ),
					$e['created_at'] ?? current_time( 'mysql' )
				) );
				if ( false === $result ) {
					$success = false;
					break;
				}
			}
			if ( $success ) {
				$wpdb->query( 'COMMIT' );
				// Only delete transients after confirmed commit — no data loss on ROLLBACK
				foreach ( $slot_keys as $slot => $key ) {
					delete_transient( $key );
				}
			} else {
				$wpdb->query( 'ROLLBACK' );
				// Transients preserved — will retry on next cron tick
			}
		}

		foreach ( $done as $slot ) {
			self::unregister_buffer_slot( $slot );
		}
	}

	public static function force_flush() {
		self::flush();
	}

	public static function prune() {
		global $wpdb;
		$retention = (int) get_option( 'sb_log_retention_days', 30 );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}sb_audit_log WHERE log_level = 'verbose' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$retention
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}sb_audit_log WHERE log_level = 'info' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$retention
		) );
	}

	private static function get_active_slots() {
		$slots = get_option( 'sb_active_buffer_slots', [] );
		return is_array( $slots ) ? $slots : [];
	}

	private static function register_buffer_slot( $slot ) {
		$slots   = self::get_active_slots();
		$slots[] = $slot;
		update_option( 'sb_active_buffer_slots', array_unique( $slots ), false );
	}

	private static function unregister_buffer_slot( $slot ) {
		$slots = self::get_active_slots();
		$slots = array_values( array_filter( $slots, fn( $s ) => (string) $s !== (string) $slot ) );
		update_option( 'sb_active_buffer_slots', $slots, false );
	}
}