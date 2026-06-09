<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SBTinyFormEngine
 * Operator-defined forms: definitions, rendering, validation, submission, save adapters.
 */
class SBTinyFormEngine {

	/**
	 * Retrieve form config from DB.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function get_form( string $slug ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_tiny_forms WHERE slug = %s AND status = 'active'",
			$slug
		) );
	}

	/**
	 * Render form HTML.
	 *
	 * @param string $slug
	 * @param array  $context  [ 'user_id' => int, 'road_key' => string ]
	 * @return string
	 */
	public static function render( string $slug, array $context = [] ): string {
		$form = self::get_form( $slug );
		if ( ! $form ) {
			return '';
		}

		// Visibility check
		if ( ! self::check_visibility( $form, $context ) ) {
			return '';
		}

		$fields  = json_decode( $form->fields_json ?? '[]', true );
		$nonce   = wp_create_nonce( 'sb_form_submit_' . $slug );
		$rest    = esc_url_raw( get_rest_url( null, 'sovereign-builder/v1/form/submit' ) );

		ob_start();
		?>
		<div class="sb-form-wrapper" id="sb-form-<?php echo esc_attr( $slug ); ?>">
			<div id="sb-form-messages-<?php echo esc_attr( $slug ); ?>"></div>
			<div id="sb-form-fields-<?php echo esc_attr( $slug ); ?>">
				<?php foreach ( $fields as $field ) : ?>
					<?php echo self::render_field( $field ); ?>
				<?php endforeach; ?>
				<input type="hidden" name="form_slug" value="<?php echo esc_attr( $slug ); ?>">
				<input type="hidden" name="_sbfnonce" value="<?php echo esc_attr( $nonce ); ?>">
				<button type="button" class="button button-primary sb-form-submit"
					data-slug="<?php echo esc_attr( $slug ); ?>"
					data-rest="<?php echo esc_attr( $rest ); ?>">
					Submit
				</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single field.
	 *
	 * @param array $field
	 * @return string
	 */
	private static function render_field( array $field ): string {
		$key      = esc_attr( $field['key'] ?? '' );
		$label    = esc_html( $field['label'] ?? $key );
		$type     = $field['type'] ?? 'text';
		$required = ! empty( $field['required'] ) ? 'required' : '';
		$id       = 'sbf_' . $key;

		$html = "<div class='sb-field-wrap' id='wrap_{$id}'><label for='{$id}'>{$label}</label>";

		switch ( $type ) {
			case 'textarea':
				$html .= "<textarea name='{$key}' id='{$id}' {$required}></textarea>";
				break;
			case 'select':
				$options = $field['options'] ?? [];
				$html   .= "<select name='{$key}' id='{$id}' {$required}>";
				foreach ( $options as $opt ) {
					$html .= "<option value='" . esc_attr( $opt ) . "'>" . esc_html( $opt ) . '</option>';
				}
				$html .= '</select>';
				break;
			case 'checkbox':
				$html .= "<input type='checkbox' name='{$key}' id='{$id}' value='1' {$required}>";
				break;
			case 'radio':
				$options = $field['options'] ?? [];
				foreach ( $options as $opt ) {
					$html .= "<label><input type='radio' name='{$key}' value='" . esc_attr( $opt ) . "' {$required}>" . esc_html( $opt ) . '</label> ';
				}
				break;
			case 'hidden':
				$html .= "<input type='hidden' name='{$key}' id='{$id}' value='" . esc_attr( $field['value'] ?? '' ) . "'>";
				break;
			case 'email':
				$html .= "<input type='email' name='{$key}' id='{$id}' {$required}>";
				break;
			default:
				$html .= "<input type='text' name='{$key}' id='{$id}' {$required}>";
		}

		$html .= "<span class='sb-field-error' id='err_{$id}'></span></div>";
		return $html;
	}

	/**
	 * Validate submitted data against form rules.
	 *
	 * @param string $slug
	 * @param array  $submitted_data
	 * @return true|array  true on pass; array of field errors on fail
	 */
	public static function validate( string $slug, array $submitted_data ) {
		$form = self::get_form( $slug );
		if ( ! $form ) {
			return [ '_form' => 'Form not found.' ];
		}

		$fields     = json_decode( $form->fields_json ?? '[]', true );
		$validation = json_decode( $form->validation_json ?? '{}', true );
		$errors     = [];

		foreach ( $fields as $field ) {
			$key   = $field['key'] ?? '';
			$value = $submitted_data[ $key ] ?? '';
			$rules = $validation[ $key ] ?? [];

			if ( ! empty( $field['required'] ) && '' === trim( (string) $value ) ) {
				$errors[ $key ] = ( $field['label'] ?? $key ) . ' is required.';
				continue;
			}

			if ( $field['type'] === 'email' && $value && ! is_email( $value ) ) {
				$errors[ $key ] = 'Please enter a valid email address.';
				continue;
			}

			if ( ! empty( $rules['min_length'] ) && strlen( (string) $value ) < (int) $rules['min_length'] ) {
				$errors[ $key ] = ( $field['label'] ?? $key ) . " must be at least {$rules['min_length']} characters.";
				continue;
			}

			if ( ! empty( $rules['max_length'] ) && strlen( (string) $value ) > (int) $rules['max_length'] ) {
				$errors[ $key ] = ( $field['label'] ?? $key ) . " must not exceed {$rules['max_length']} characters.";
				continue;
			}

			if ( ! empty( $rules['regex'] ) && $value && ! preg_match( $rules['regex'], (string) $value ) ) {
				$errors[ $key ] = $rules['regex_message'] ?? ( $field['label'] ?? $key ) . ' format is invalid.';
			}
		}

		return empty( $errors ) ? true : $errors;
	}

	/**
	 * Process a form submission.
	 *
	 * @param string $slug
	 * @param array  $submitted_data
	 * @param int    $user_id
	 * @return array|WP_Error
	 */
	public static function submit( string $slug, array $submitted_data, int $user_id = 0 ) {
		$form = self::get_form( $slug );
		if ( ! $form ) {
			return new WP_Error( 'not_found', 'Form not found.', [ 'status' => 404 ] );
		}

		// Sanitize submitted data
		$fields = json_decode( $form->fields_json ?? '[]', true );
		$clean  = [];
		foreach ( $fields as $field ) {
			$key = $field['key'] ?? '';
			if ( ! array_key_exists( $key, $submitted_data ) ) {
				continue;
			}
			$val = $submitted_data[ $key ];
			$clean[ $key ] = match( $field['type'] ?? 'text' ) {
				'email'    => sanitize_email( (string) $val ),
				'textarea' => sanitize_textarea_field( (string) $val ),
				'checkbox' => (int) (bool) $val,
				default    => sanitize_text_field( (string) $val ),
			};
		}

		// Validate
		$validation_result = self::validate( $slug, $clean );
		if ( $validation_result !== true ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FORM_SUBMISSION_FAILED, "Form '{$slug}' validation failed", $user_id, [ 'errors' => $validation_result ], 'warning' );
			return new WP_Error( 'validation_failed', 'Validation failed.', [ 'status' => 422, 'errors' => $validation_result ] );
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_FORM_SUBMISSION_RECEIVED, "Form submission received: {$slug}", $user_id, [], 'info' );

		// Save via adapter
		$adapter     = $form->save_adapter ?? 'submission_table';
		$save_config = json_decode( $form->save_config_json ?? '{}', true );
		$save_result = self::run_save_adapter( $adapter, $slug, $clean, $user_id, $save_config, $form );

		if ( is_wp_error( $save_result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FORM_SUBMISSION_FAILED, "Form '{$slug}' save failed: " . $save_result->get_error_message(), $user_id, [], 'error' );
			return $save_result;
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_FORM_SUBMISSION_SAVED, "Form '{$slug}' saved successfully", $user_id, [ 'submission_id' => $save_result ], 'info' );

		// Fire signal if configured
		$signal_type = $save_config['signal_type'] ?? '';
		if ( $signal_type ) {
			SB_Signal_Engine::record_signal( 0, sanitize_key( $signal_type ), 1.0, $user_id );
		}

		return [
			'success'        => true,
			'submission_id'  => $save_result,
			'message'        => $form->success_message ?: 'Thank you for your submission.',
		];
	}

	/**
	 * Execute the save adapter.
	 *
	 * @param string $adapter
	 * @param string $form_slug
	 * @param array  $data
	 * @param int    $user_id
	 * @param array  $config
	 * @param object $form
	 * @return int|WP_Error  submission ID or 0 for non-table adapters
	 */
	private static function run_save_adapter( string $adapter, string $form_slug, array $data, int $user_id, array $config, object $form ) {
		global $wpdb;

		switch ( $adapter ) {
			case 'user_meta':
				if ( $user_id ) {
					foreach ( $data as $key => $val ) {
						update_user_meta( $user_id, 'sb_field_' . sanitize_key( $key ), $val );
					}
				}
				return 0;

			case 'signal':
				$signal_type = sanitize_key( $config['signal_type'] ?? 'form_submitted' );
				$value       = (float) ( $config['signal_value'] ?? 1.0 );
				SB_Signal_Engine::record_signal( 0, $signal_type, $value, $user_id );
				return 0;

			case 'many_roads':
				$road_key = sanitize_key( $config['road_key'] ?? '' );
				if ( $road_key && $user_id ) {
					SB_Many_Roads::enter_road( $user_id, $road_key );
				}
				return 0;

			case 'submission_table':
			default:
				$session_id = wp_generate_password( 32, false );
				$wpdb->insert( "{$wpdb->prefix}sb_submissions", [
					'form_slug'    => $form_slug,
					'user_id'      => $user_id,
					'session_id'   => $session_id,
					'status'       => 'received',
					'submitted_at' => current_time( 'mysql' ),
				] );
				$submission_id = (int) $wpdb->insert_id;

				if ( ! $submission_id ) {
					return new WP_Error( 'db_error', 'Failed to save submission.', [ 'status' => 500 ] );
				}

				foreach ( $data as $key => $val ) {
					$wpdb->insert( "{$wpdb->prefix}sb_submission_meta", [
						'submission_id' => $submission_id,
						'meta_key'      => sanitize_key( $key ),
						'meta_value'    => (string) $val,
					] );
				}

				return $submission_id;
		}
	}

	/**
	 * Check visibility rules for a form.
	 *
	 * @param object $form
	 * @param array  $context
	 * @return bool
	 */
	private static function check_visibility( object $form, array $context ): bool {
		$rules = json_decode( $form->visibility_rules_json ?? '{}', true );
		if ( empty( $rules ) ) {
			return true;
		}

		// road_key check
		if ( ! empty( $rules['road_key'] ) ) {
			$user_road = $context['road_key'] ?? '';
			if ( $user_road !== $rules['road_key'] ) {
				return false;
			}
		}

		// Login check
		if ( ! empty( $rules['user_logged_in'] ) && ! is_user_logged_in() ) {
			return false;
		}

		// Capability check
		if ( ! empty( $rules['capability'] ) && ! current_user_can( $rules['capability'] ) ) {
			return false;
		}

		// PMPro level check
		if ( ! empty( $rules['pmpro_level'] ) && function_exists( 'pmpro_hasMembershipLevel' ) ) {
			if ( ! pmpro_hasMembershipLevel( (int) $rules['pmpro_level'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get submissions for a form.
	 *
	 * @param string $slug
	 * @param array  $query_args
	 * @return array
	 */
	public static function get_submissions( string $slug, array $query_args = [] ): array {
		global $wpdb;

		$per_page = absint( $query_args['per_page'] ?? 25 );
		$page     = absint( $query_args['page'] ?? 1 );
		$offset   = ( $page - 1 ) * $per_page;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, u.user_email
			 FROM {$wpdb->prefix}sb_submissions s
			 LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
			 WHERE s.form_slug = %s
			 ORDER BY s.submitted_at DESC
			 LIMIT %d OFFSET %d",
			$slug,
			$per_page,
			$offset
		), ARRAY_A );
	}

	/**
	 * Shortcode handler: [sb_form slug="slug"]
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function shortcode( array $atts ): string {
		$atts = shortcode_atts( [ 'slug' => '' ], $atts );
		if ( ! $atts['slug'] ) {
			return '';
		}
		$context = [
			'user_id'  => get_current_user_id(),
			'road_key' => get_user_meta( get_current_user_id(), 'sb_road_key', true ) ?: '',
		];
		return self::render( sanitize_key( $atts['slug'] ), $context );
	}

	/**
	 * Render admin screen — form list.
	 */
	public static function render_forms_screen(): void {
		if ( ! current_user_can( 'manage_sovereign_forms' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_tiny_forms', 'sb_submissions' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$forms = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_tiny_forms ORDER BY created_at DESC"
		);

		echo '<div class="wrap"><h1>Tiny Forms</h1>';
		echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>Label</th><th>Adapter</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $forms as $form ) {
			$sub_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}sb_submissions WHERE form_slug = %s",
				$form->slug
			) );
			echo '<tr>';
			echo '<td><code>' . esc_html( $form->slug ) . '</code></td>';
			echo '<td>' . esc_html( $form->label ) . '</td>';
			echo '<td>' . esc_html( $form->save_adapter ) . '</td>';
			echo '<td>' . esc_html( $form->status ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( admin_url( "admin.php?page=sb-tiny-forms&action=edit&id={$form->id}" ) ) . '">Edit</a> | ';
			echo '<a href="' . esc_url( admin_url( "admin.php?page=sb-submissions&form={$form->slug}" ) ) . '">' . (int) $sub_count . ' Submissions</a>';
			echo '</td></tr>';
		}
		if ( empty( $forms ) ) {
			echo '<tr><td colspan="5">No forms defined yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Render submissions screen.
	 */
	public static function render_submissions_screen(): void {
		if ( ! current_user_can( 'manage_sovereign' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$guard = SBAdminGuard::require_tables( [ 'sb_submissions', 'sb_tiny_forms' ] );
		if ( $guard ) { echo $guard; return; }
		global $wpdb;

		$form_slug = sanitize_key( $_GET['form'] ?? '' );
		$subs      = $form_slug ? self::get_submissions( $form_slug ) : [];

		echo '<div class="wrap"><h1>Submissions' . ( $form_slug ? ': ' . esc_html( $form_slug ) : '' ) . '</h1>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Status</th><th>Submitted</th></tr></thead><tbody>';
		foreach ( $subs as $s ) {
			echo '<tr>';
			echo '<td>' . (int) $s['id'] . '</td>';
			echo '<td>' . esc_html( $s['user_email'] ?? 'Guest' ) . '</td>';
			echo '<td>' . esc_html( $s['status'] ) . '</td>';
			echo '<td>' . esc_html( $s['submitted_at'] ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $subs ) ) {
			echo '<tr><td colspan="4">No submissions found.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}