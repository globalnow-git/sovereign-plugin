<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Prompt_Generator {

	public static function compile_recovery_prompt( $failing_layer_slug, $error_context_string ) {
		return sprintf(
			"CONTEXT: System Architecture Operational Execution Layer Exception Encountered.\nFAILING SUB-COMPONENT MODULE: %s\nRAW LOGS CONTEXT RECORD: %s\nINSTRUCTION: Propose localized fallback matrix correction structures state strings immediately.",
			sanitize_key( $failing_layer_slug ),
			sanitize_textarea_field( $error_context_string )
		);
	}
}