<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_UI_Router {

	public static function paint_menus() {
		if ( SB_Debugger::is_safe_mode() ) {
			add_menu_page(
				'Sovereign Builder — Safe Mode Active',
				'SB Safe Mode',
				'manage_sovereign',
				'sovereign-builder',
				[ 'SB_Debugger', 'render_safe_mode' ],
				'dashicons-shield-alt',
				2
			);
			return;
		}

		add_menu_page(
			'Sovereign Builder Central Console',
			'Sovereign Builder',
			'manage_sovereign',
			'sovereign-builder',
			[ 'SB_UI_Router', 'render_primary_console_dashboard' ],
			'dashicons-block-default',
			2
		);

		add_submenu_page(
			'sovereign-builder',
			'Audit Footprint Logs',
			'Audit Trail Logs',
			'view_sovereign_audit_logs',
			'sb-audit-logs',
			[ 'SB_Event_Logger', 'render_audit_screen' ]
		);

		add_submenu_page(
			'sovereign-builder',
			'SRE Hardware Debugger',
			'SRE Diagnostics',
			'manage_sovereign_debug',
			'sb-debugger',
			[ 'SB_Debugger', 'render_debugger_screen' ]
		);

		// Dynamic table parsing rendering layout loops pulling from the data settings matrix configurations
		global $wpdb;
		$menu_settings = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_settings 
			WHERE setting_group = 'menu' 
			ORDER BY id ASC"
		);

		if ( $menu_settings ) {
			foreach ( $menu_settings as $menu ) {
				$config = json_decode( $menu->options_json, true );
				if ( is_array( $config ) ) {
					add_submenu_page(
						'sovereign-builder',
						esc_html( $menu->description ),
						esc_html( $menu->setting_value ),
						sanitize_key( $config['capability'] ?? 'manage_sovereign' ),
						sanitize_key( $menu->setting_key ),
						[ $config['callback_class'], $config['callback_method'] ]
					);
				}
			}
		}
	}

	public static function render_primary_console_dashboard() {
		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>' . esc_html__( 'Sovereign Builder Version 1.0.3 Control Console Center' ) . '</h1>';
		echo '<p>' . esc_html__( 'Active Runtime Platform Matrix Identity Root Configuration Profile Architecture Layer.' ) . '</p>';
		
		echo '<div style="background:#fff; padding:25px; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1); margin-top:20px;">';
		echo '<h3>Core Ecosystem Blueprint Identity Definition State Settings</h3>';
		global $wpdb;
		$fields = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_settings WHERE setting_group = 'general' ORDER BY id ASC"
		);
		// Fallback if no DB rows seeded yet
		if ( empty( $fields ) ) {
			$defaults = [
				[ 'sb_anthropic_key', get_option( 'sb_anthropic_key', '' ),                          'password', 'Anthropic API Key', '' ],
				[ 'sb_model_slug',    get_option( 'sb_model_slug', 'claude-sonnet-4-20250514' ),     'text',     'Model Slug', '' ],
				[ 'sb_from_name',     get_option( 'sb_from_name', get_bloginfo( 'name' ) ),          'text',     'From Name', '' ],
				[ 'sb_from_email',    get_option( 'sb_from_email', get_option( 'admin_email' ) ),    'text',     'From Email', '' ],
				[ 'sb_log_mode',      get_option( 'sb_log_mode', 'terse' ),                          'select',   'Log Mode', '{"terse":"Terse","verbose":"Verbose","debug":"Debug"}' ],
			];
			$fields = array_map( function( $d ) {
				return (object) [ 'setting_key' => $d[0], 'setting_value' => $d[1], 'setting_type' => $d[2], 'description' => $d[3], 'options_json' => $d[4] ];
			}, $defaults );
		}
		echo '<form id="sb-settings-form" method="POST" action="options.php" style="max-width:800px;">';
		settings_fields( 'sb_core_settings_group' );
		echo '<table class="form-table">';
		echo '<tr><th>Active Domain</th><td><code>' . esc_html( defined( 'SB_ACTIVE_DOMAIN' ) ? SB_ACTIVE_DOMAIN : 'not set' ) . '</code></td></tr>';
		foreach ( $fields as $field ) {
			self::render_field( $field );
		}
		echo '</table>';
		submit_button( esc_html__( 'Save Settings', 'sovereign-builder' ) );
		echo '<span id="sb-settings-saved" style="display:none;color:#00a32a;margin-left:10px">Settings saved.</span>';
		echo '</form></div></div>';
	}

	public static function render_field( $field ) {
		$key   = esc_attr( $field->setting_key );
		$value = esc_attr( $field->setting_value ?? '' );
		$label = esc_html( $field->description ?: $field->setting_key );
		$opts  = json_decode( $field->options_json ?? '[]', true );
		$cfg   = json_decode( $field->config_json  ?? '{}', true );

		echo '<tr><th scope="row"><label for="' . $key . '">' . $label . '</label></th><td>';

		switch ( $field->setting_type ) {
			case 'password':
				echo '<input type="password" id="' . $key . '" name="sb_settings[' . $key . ']" value="' . $value . '" class="regular-text" autocomplete="new-password">';
				break;
			case 'select':
				echo '<select id="' . $key . '" name="sb_settings[' . $key . ']">';
				if ( is_array( $opts ) ) {
					foreach ( $opts as $opt_val => $opt_label ) {
						$sel = selected( $field->setting_value, $opt_val, false );
						echo '<option value="' . esc_attr( $opt_val ) . '"' . $sel . '>' . esc_html( $opt_label ) . '</option>';
					}
				}
				echo '</select>';
				break;
			case 'toggle':
				$checked = checked( $field->setting_value, '1', false );
				echo '<input type="checkbox" id="' . $key . '" name="sb_settings[' . $key . ']" value="1"' . $checked . '>';
				break;
			case 'number':
				$min = isset( $cfg['min'] ) ? ' min="' . (int) $cfg['min'] . '"' : '';
				$max = isset( $cfg['max'] ) ? ' max="' . (int) $cfg['max'] . '"' : '';
				echo '<input type="number" id="' . $key . '" name="sb_settings[' . $key . ']" value="' . $value . '" class="small-text"' . $min . $max . '>';
				break;
			case 'media':
				echo '<input type="text" id="' . $key . '" name="sb_settings[' . $key . ']" value="' . $value . '" class="regular-text sb-media-url">';
				echo '<button class="button sb-media-select" data-target="' . $key . '">' . esc_html__( 'Select', 'sovereign-builder' ) . '</button>';
				break;
			case 'textarea':
				echo '<textarea id="' . $key . '" name="sb_settings[' . $key . ']" rows="4" class="large-text">' . esc_textarea( $field->setting_value ?? '' ) . '</textarea>';
				break;
			default: // text, colour, etc.
				$type = in_array( $field->setting_type, [ 'colour', 'color' ], true ) ? 'color' : 'text';
				echo '<input type="' . $type . '" id="' . $key . '" name="sb_settings[' . $key . ']" value="' . $value . '" class="regular-text">';
				break;
		}

		echo '</td></tr>';
	}

}