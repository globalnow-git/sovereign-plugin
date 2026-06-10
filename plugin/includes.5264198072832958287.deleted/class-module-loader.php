<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Module_Loader {

	private static $instance = null;
	private $modules = [];

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Private initialization constraint enforcing singleton pattern layout
	}

	public function register( $slug, $version, $class_name ) {
		$this->modules[ sanitize_key( $slug ) ] = [
			'version'    => sanitize_text_field( $version ),
			'class_name' => sanitize_text_field( $class_name ),
			'loaded_at'  => current_time( 'mysql' )
		];
	}

	public function is_loaded( $slug ) {
		return isset( $this->modules[ sanitize_key( $slug ) ] );
	}

	public static function is_schema_ready() {
		global $wpdb;
		$target_table = $wpdb->prefix . 'sb_scenarios';
		return ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $target_table ) ) === $target_table );
	}

	public function get_modules() {
		return $this->modules;
	}

	public function get_module_status() {
		$status = [];
		foreach ( $this->modules as $slug => $data ) {
			$status[] = [
				'slug'    => $slug,
				'version' => $data['version'],
				'status'  => 'active'
			];
		}
		return $status;
	}
}
