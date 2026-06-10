<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Marketing_Dashboard {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'marketing-dashboard', '1.0.0', 'SB_Marketing_Dashboard' );
			add_action( 'admin_menu', [ __CLASS__, 'register_marketing_hq_menu' ] );
		}
	}

	public static function register_marketing_hq_menu() {
		add_menu_page(
			'Marketing HQ Command Suite',
			'Marketing HQ',
			'manage_sovereign',
			'marketing-hq',
			[ __CLASS__, 'render_dashboard_view' ],
			'dashicons-chart-bar',
			3
		);
	}

	public static function render_dashboard_view() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'sovereign-builder' ) ); }
		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Sovereign Platform Marketing HQ Command Suite Center</h1>';
		
		// Build analytical stat cards mapping data elements dynamically
		echo '<div class="sb-card-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-top:20px;">';
		
		$cards = apply_filters( 'sb_dashboard_stat_cards', [
			[ 'title' => 'Active Pipeline Conversion Paths', 'value' => '3 Roads Active' ],
			[ 'title' => 'Sovereign Network Growth Metrics', 'value' => '100% Local data validation' ]
		] );

		foreach ( $cards as $card ) {
			echo '<div class="card" style="background:#fff; padding:20px; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1); border-top:4px solid #7227cb;">';
			echo '<h4>' . esc_html( $card['title'] ) . '</h4>';
			echo '<p style="font-size:24px; font-weight:bold; margin:10px 0;">' . esc_html( $card['value'] ) . '</p>';
			echo '</div>';
		}

		echo '</div>';
		
		// Display the explicit read-only data sovereignty matrix compliance board cleanly
		echo '<div style="background:#fff; padding:25px; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1); margin-top:30px;">';
		echo '<h3>Sovereign Builder System Infrastructure Compliance Registry</h3>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Service Component Layer Identity</th><th>Sovereign Soil Verified Status</th><th>Operational Location Infrastructure Bounds</th></tr></thead><tbody>';
		
		$rows = SB_Extension_API::get_sovereignty_status();
		foreach ( $rows as $r ) {
			$color = ( 'Yes' === $r['sovereign'] ) ? '#46b450' : ( ( 'Partial' === $r['sovereign'] ) ? '#f0b849' : '#0073aa' );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['service'] ) . '</strong></td>';
			echo '<td><span style="color:' . $color . '; font-weight:bold;">' . esc_html( strtoupper( $r['sovereign'] ) ) . '</span></td>';
			echo '<td><code>' . esc_html( $r['jurisdiction'] ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
	}
}
SB_Marketing_Dashboard::init();