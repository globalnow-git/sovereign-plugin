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
		$label   = esc_html( $form->label ?? '' );
		$desc    = esc_html( $form->description ?? '' );

		// Inline styles — self-contained, no external dependency
		$css = '
		<style>
		.sb-form-wrapper {
			font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
			max-width: 640px;
			margin: 2.5rem auto;
			background: #ffffff;
			border-radius: 12px;
			box-shadow: 0 4px 32px rgba(26,26,46,0.10), 0 1px 4px rgba(26,26,46,0.06);
			overflow: hidden;
		}
		.sb-form-header {
			background: #1a1a2e;
			padding: 2.25rem 2.5rem 2rem;
			border-bottom: 3px solid #c9a84c;
		}
		.sb-form-header h2 {
			font-family: Georgia, "Times New Roman", serif;
			font-size: 1.65rem;
			font-weight: 700;
			color: #f5f0e8;
			margin: 0 0 0.4rem;
			letter-spacing: -0.01em;
			line-height: 1.2;
		}
		.sb-form-header p {
			font-size: 0.9rem;
			color: #9ca3af;
			margin: 0;
			line-height: 1.5;
		}
		.sb-form-body {
			padding: 2rem 2.5rem 2.5rem;
			background: #ffffff;
		}
		.sb-field-wrap {
			margin-bottom: 1.5rem;
			position: relative;
		}
		.sb-field-wrap label {
			display: block;
			font-size: 0.82rem;
			font-weight: 600;
			color: #374151;
			margin-bottom: 0.45rem;
			letter-spacing: 0.04em;
			text-transform: uppercase;
		}
		.sb-field-wrap input[type="text"],
		.sb-field-wrap input[type="email"],
		.sb-field-wrap input[type="number"],
		.sb-field-wrap input[type="date"],
		.sb-field-wrap select,
		.sb-field-wrap textarea {
			display: block;
			width: 100%;
			box-sizing: border-box;
			padding: 0.7rem 0.95rem;
			font-size: 0.97rem;
			font-family: inherit;
			color: #1a1a2e;
			background: #f9fafb;
			border: 1.5px solid #e5e7eb;
			border-radius: 7px;
			outline: none;
			transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
			-webkit-appearance: none;
			appearance: none;
		}
		.sb-field-wrap select {
			background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\' viewBox=\'0 0 12 8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%236b7280\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E");
			background-repeat: no-repeat;
			background-position: right 0.9rem center;
			padding-right: 2.5rem;
			cursor: pointer;
		}
		.sb-field-wrap textarea {
			min-height: 120px;
			resize: vertical;
			line-height: 1.6;
		}
		.sb-field-wrap input:focus,
		.sb-field-wrap select:focus,
		.sb-field-wrap textarea:focus {
			border-color: #c9a84c;
			background: #ffffff;
			box-shadow: 0 0 0 3px rgba(201,168,76,0.13), inset 3px 0 0 #c9a84c;
		}
		.sb-field-wrap input[type="checkbox"] {
			width: 1.1rem;
			height: 1.1rem;
			accent-color: #c9a84c;
			margin-right: 0.5rem;
			cursor: pointer;
			vertical-align: middle;
		}
		.sb-checkbox-wrap {
			display: flex;
			align-items: flex-start;
			gap: 0.6rem;
			padding: 1rem 1.1rem;
			background: #f5f0e8;
			border-radius: 7px;
			border: 1.5px solid #e9e0cc;
		}
		.sb-checkbox-wrap label {
			font-size: 0.88rem;
			font-weight: 400;
			text-transform: none;
			letter-spacing: 0;
			color: #374151;
			line-height: 1.5;
			margin: 0;
			cursor: pointer;
		}
		.sb-field-error {
			display: block;
			font-size: 0.8rem;
			color: #dc2626;
			margin-top: 0.35rem;
			min-height: 1rem;
		}
		.sb-word-count {
			display: block;
			font-size: 0.78rem;
			color: #9ca3af;
			margin-top: 0.35rem;
			text-align: right;
		}
		.sb-word-count.sb-wc-low { color: #dc2626; }
		.sb-word-count.sb-wc-mid { color: #d97706; }
		.sb-word-count.sb-wc-good { color: #059669; }
		.sb-form-divider {
			border: none;
			border-top: 1px solid #f3f4f6;
			margin: 1.75rem 0;
		}
		.sb-submit-wrap {
			margin-top: 2rem;
		}
		.sb-form-submit {
			display: block;
			width: 100%;
			padding: 0.9rem 1.5rem;
			font-size: 1rem;
			font-weight: 600;
			font-family: inherit;
			letter-spacing: 0.02em;
			color: #c9a84c !important;
			background: #1a1a2e !important;
			border: none !important;
			border-radius: 8px;
			cursor: pointer;
			transition: background 0.18s ease, transform 0.1s ease, box-shadow 0.18s ease;
			box-shadow: 0 2px 8px rgba(26,26,46,0.18);
			height: auto !important;
			line-height: 1.4 !important;
		}
		.sb-form-submit:hover {
			background: #252545 !important;
			box-shadow: 0 4px 16px rgba(26,26,46,0.22);
			transform: translateY(-1px);
		}
		.sb-form-submit:active {
			transform: translateY(0);
		}
		.sb-form-submit:disabled {
			opacity: 0.6;
			cursor: not-allowed;
			transform: none;
		}
		.sb-form-messages {
			margin-bottom: 1.25rem;
		}
		.sb-msg-success {
			background: #ecfdf5;
			border: 1.5px solid #6ee7b7;
			border-left: 4px solid #059669;
			color: #065f46;
			padding: 0.85rem 1rem;
			border-radius: 7px;
			font-size: 0.92rem;
		}
		.sb-msg-error {
			background: #fef2f2;
			border: 1.5px solid #fca5a5;
			border-left: 4px solid #dc2626;
			color: #7f1d1d;
			padding: 0.85rem 1rem;
			border-radius: 7px;
			font-size: 0.92rem;
		}
		.sb-required-note {
			font-size: 0.78rem;
			color: #9ca3af;
			margin-bottom: 1.5rem;
			margin-top: -0.25rem;
		}
		.sb-required-star { color: #dc2626; }
		@media (max-width: 680px) {
			.sb-form-wrapper { margin: 1rem; border-radius: 10px; }
			.sb-form-header { padding: 1.5rem 1.5rem 1.25rem; }
			.sb-form-body { padding: 1.5rem; }
		}
		</style>';

		ob_start();
		echo $css;
		?>
		<div class="sb-form-wrapper" id="sb-form-<?php echo esc_attr( $slug ); ?>">
			<?php if ( $label ) : ?>
			<div class="sb-form-header">
				<h2><?php echo $label; ?></h2>
				<?php if ( $desc ) : ?><p><?php echo $desc; ?></p><?php endif; ?>
			</div>
			<?php endif; ?>
			<div class="sb-form-body">
				<div class="sb-form-messages" id="sb-form-messages-<?php echo esc_attr( $slug ); ?>"></div>
				<p class="sb-required-note">Fields marked <span class="sb-required-star">*</span> are required.</p>
				<div id="sb-form-fields-<?php echo esc_attr( $slug ); ?>">
					<?php foreach ( $fields as $field ) : ?>
						<?php echo self::render_field( $field ); ?>
					<?php endforeach; ?>
					<input type="hidden" name="form_slug" value="<?php echo esc_attr( $slug ); ?>">
					<input type="hidden" name="_sbfnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div class="sb-submit-wrap">
						<button type="button" class="sb-form-submit"
							data-slug="<?php echo esc_attr( $slug ); ?>"
							data-rest="<?php echo esc_attr( $rest ); ?>">
							Save &amp; Continue →
						</button>
					</div>
				</div>
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
		$key         = esc_attr( $field['field_key'] ?? $field['key'] ?? '' );
		$label       = esc_html( $field['label'] ?? $key );
		$type        = $field['type'] ?? 'text';
		$required    = ! empty( $field['required'] );
		$req_attr    = $required ? 'required' : '';
		$req_mark    = $required ? ' <span class="sb-required-star">*</span>' : '';
		$id          = 'sbf_' . $key;
		$placeholder = esc_attr( $field['placeholder'] ?? '' );
		$min_words   = (int) ( $field['min_words'] ?? 0 );

		// Checkbox gets special layout
		if ( $type === 'checkbox' ) {
			return "
			<div class='sb-field-wrap'>
				<div class='sb-checkbox-wrap'>
					<input type='checkbox' name='{$key}' id='{$id}' value='1' {$req_attr}>
					<label for='{$id}'>{$label}</label>
				</div>
				<span class='sb-field-error' id='err_{$id}'></span>
			</div>";
		}

		$html = "<div class='sb-field-wrap' id='wrap_{$id}'><label for='{$id}'>{$label}{$req_mark}</label>";

		switch ( $type ) {
			case 'textarea':
				$rows  = (int) ( $field['rows'] ?? 6 );
				$wc_id = 'wc_' . $key;
				$html .= "<textarea name='{$key}' id='{$id}' rows='{$rows}' placeholder='{$placeholder}' {$req_attr}></textarea>";
				if ( $min_words > 0 ) {
					$html .= "<span class='sb-word-count sb-wc-low' id='{$wc_id}'>0 / {$min_words} words minimum</span>
					<script>
					(function(){
						var ta = document.getElementById('{$id}');
						var wc = document.getElementById('{$wc_id}');
						if(!ta||!wc) return;
						ta.addEventListener('input', function(){
							var words = ta.value.trim().split(/\s+/).filter(function(w){return w.length>0;}).length;
							wc.textContent = words + ' / {$min_words} words minimum';
							wc.className = 'sb-word-count ' + (words < {$min_words} * 0.5 ? 'sb-wc-low' : words < {$min_words} ? 'sb-wc-mid' : 'sb-wc-good');
						});
					})();
					</script>";
				} else {
					$html .= "<span class='sb-word-count' id='wc_{$key}'></span>
					<script>
					(function(){
						var ta = document.getElementById('{$id}');
						var wc = document.getElementById('wc_{$key}');
						if(!ta||!wc) return;
						ta.addEventListener('input', function(){
							var words = ta.value.trim().split(/\s+/).filter(function(w){return w.length>0;}).length;
							wc.textContent = words > 0 ? words + ' words' : '';
						});
					})();
					</script>";
				}
				break;
			case 'select':
				$options = $field['options'] ?? [];
				$html   .= "<select name='{$key}' id='{$id}' {$req_attr}>";
				$html   .= "<option value=''>— Select —</option>";
				foreach ( $options as $opt ) {
					$html .= "<option value='" . esc_attr( $opt ) . "'>" . esc_html( $opt ) . '</option>';
				}
				$html .= '</select>';
				break;
			case 'radio':
				$options = $field['options'] ?? [];
				foreach ( $options as $opt ) {
					$html .= "<label style='display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem;font-weight:400;text-transform:none;letter-spacing:0;'><input type='radio' name='{$key}' value='" . esc_attr( $opt ) . "' {$req_attr}>" . esc_html( $opt ) . '</label>';
				}
				break;
			case 'hidden':
				$html .= "<input type='hidden' name='{$key}' id='{$id}' value='" . esc_attr( $field['value'] ?? '' ) . "'>";
				break;
			case 'email':
				$html .= "<input type='email' name='{$key}' id='{$id}' placeholder='{$placeholder}' {$req_attr}>";
				break;
			case 'number':
				$html .= "<input type='number' name='{$key}' id='{$id}' placeholder='{$placeholder}' step='" . esc_attr( $field['step'] ?? '1' ) . "' {$req_attr}>";
				break;
			case 'currency':
				$html .= "<input type='number' name='{$key}' id='{$id}' placeholder='{$placeholder}' step='0.01' min='0' {$req_attr}>";
				break;
			case 'date':
				$html .= "<input type='date' name='{$key}' id='{$id}' {$req_attr}>";
				break;
			default:
				$html .= "<input type='text' name='{$key}' id='{$id}' placeholder='{$placeholder}' {$req_attr}>";
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
			$key   = $field['field_key'] ?? $field['key'] ?? '';
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
			$key = $field['field_key'] ?? $field['key'] ?? '';
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


		// Handle edit action
		$action = sanitize_key( $_GET["action"] ?? "" );
		$edit_id = absint( $_GET["id"] ?? 0 );
		if ( $action === "edit" && $edit_id ) {
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sb_tiny_forms WHERE id = %d", $edit_id ) );
			if ( ! $form ) { wp_die( "Form not found." ); }
			$fields = json_decode( $form->fields_json, true ) ?? [];
			echo "<div class=wrap><h1>Form: " . esc_html($form->label) . "</h1>";
			echo "<p><strong>Slug:</strong> <code>" . esc_html($form->slug) . "</code> &nbsp; <strong>Status:</strong> " . esc_html($form->status) . " &nbsp; <strong>Fields:</strong> " . count($fields) . "</p>";
			echo "<table class=widefat striped><thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th></tr></thead><tbody>";
			foreach($fields as $f) { echo "<tr><td><code>" . esc_html($f["key"] ?? $f["field_key"] ?? "") . "</code></td><td>" . esc_html($f["label"] ?? "") . "</td><td>" . esc_html($f["type"] ?? "text") . "</td><td>" . (!empty($f["required"]) ? "Yes" : "No") . "</td></tr>"; }
			echo "</tbody></table>";
			echo "<p><a href=" . admin_url("admin.php?page=sb-tiny-forms") . " class=button>&larr; Back</a> <a href=" . admin_url("admin.php?page=sb-submissions&form=" . $form->slug) . " class=button button-primary style=margin-left:8px>View Submissions</a></p>";
			echo "</div>";
			return;
		}
		$forms = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_tiny_forms ORDER BY created_at DESC"
		);
		?>
		<style>
		.sb-forms-wrap { max-width:1100px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
		.sb-forms-wrap h1 { font-size:1.6rem; color:#1a1a2e; margin-bottom:1.75rem; }
		.sb-forms-panel { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08),0 0 0 1px rgba(0,0,0,.05); overflow:hidden; }
		.sb-forms-panel-head { background:#1a1a2e; padding:1rem 1.5rem; display:flex; align-items:center; justify-content:space-between; border-bottom:2px solid #c9a84c; }
		.sb-forms-panel-head h2 { margin:0; font-size:1rem; font-weight:600; color:#f5f0e8; }
		.sb-forms-panel-head span { color:#9ca3af; font-size:0.82rem; }
		.sb-forms-table { width:100%; border-collapse:collapse; }
		.sb-forms-table th { text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; padding:0.6rem 1rem; border-bottom:2px solid #f3f4f6; background:#f9fafb; }
		.sb-forms-table td { padding:0.85rem 1rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; font-size:0.88rem; }
		.sb-forms-table tr:last-child td { border-bottom:none; }
		.sb-forms-table tr:hover td { background:#fafafa; }
		.sb-badge { display:inline-flex; padding:0.2rem 0.65rem; border-radius:20px; font-size:0.72rem; font-weight:700; }
		.sb-badge-active { background:#ecfdf5; color:#059669; }
		.sb-badge-draft { background:#fef9c3; color:#92400e; }
		.sb-badge-inactive { background:#f3f4f6; color:#6b7280; }
		.sb-forms-actions { display:flex; gap:0.5rem; }
		.sb-forms-btn { padding:0.3rem 0.75rem; border-radius:5px; font-size:0.78rem; font-weight:600; text-decoration:none; border:1.5px solid #e5e7eb; color:#374151; background:#fff; transition:all 0.15s; }
		.sb-forms-btn:hover { border-color:#c9a84c; color:#1a1a2e; }
		.sb-forms-btn-primary { background:#1a1a2e; color:#c9a84c !important; border-color:#1a1a2e; }
		.sb-forms-btn-primary:hover { background:#252545; border-color:#252545; }
		.sb-forms-empty { text-align:center; padding:3rem; color:#9ca3af; }
		</style>

		<div class="wrap sb-forms-wrap">
			<h1>Tiny Forms</h1>
			<div class="sb-forms-panel">
				<div class="sb-forms-panel-head">
					<h2>All Forms</h2>
					<span><?php echo count( $forms ); ?> form<?php echo count( $forms ) !== 1 ? 's' : ''; ?></span>
				</div>
				<?php if ( $forms ) : ?>
				<table class="sb-forms-table">
					<thead>
						<tr>
							<th>Form</th>
							<th>Save Adapter</th>
							<th>Status</th>
							<th>Submissions</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $forms as $form ) :
						$sub_count = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}sb_submissions WHERE form_slug = %s",
							$form->slug
						) );
						$badge = 'sb-badge-' . ( $form->status === 'active' ? 'active' : ( $form->status === 'draft' ? 'draft' : 'inactive' ) );
					?>
					<tr>
						<td>
							<strong style="color:#1a1a2e;"><?php echo esc_html( $form->label ); ?></strong><br>
							<code style="font-size:0.73rem;color:#6b7280;"><?php echo esc_html( $form->slug ); ?></code>
						</td>
						<td style="color:#6b7280;"><?php echo esc_html( $form->save_adapter ?? 'submission_table' ); ?></td>
						<td><span class="sb-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( strtoupper( $form->status ) ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( admin_url( "admin.php?page=sb-submissions&form={$form->slug}" ) ); ?>" style="color:#1a1a2e;font-weight:600;">
								<?php echo (int) $sub_count; ?> submission<?php echo $sub_count !== 1 ? 's' : ''; ?>
							</a>
						</td>
						<td>
							<div class="sb-forms-actions">
								<a href="<?php echo esc_url( admin_url( "admin.php?page=sb-submissions&form={$form->slug}" ) ); ?>" class="sb-forms-btn sb-forms-btn-primary">Submissions</a>
								<?php
								// Find the WordPress page that uses this form
								$pages_with_form = get_posts([
									'post_type'      => 'page',
									'post_status'    => 'publish',
									'posts_per_page' => 1,
									's'              => $form->slug,
								]);
								if ( $pages_with_form ) :
								?>
								<a href="<?php echo esc_url( get_permalink( $pages_with_form[0]->ID ) ); ?>" target="_blank" class="sb-forms-btn">View Form ↗</a>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else : ?>
				<div class="sb-forms-empty">
					<p><strong>No forms defined yet.</strong></p>
					<p>Activate a blueprint to deploy forms automatically.</p>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
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