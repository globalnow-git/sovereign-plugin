<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Export_Generator {

	/**
	 * Generate a structured HTML export (importable by Word/LibreOffice as rich document).
	 * Class renamed from SB_Docx_Generator: this is HTML export, not native DOCX.
	 * Native DOCX requires a library (PHPWord etc.) outside this plugin's scope.
	 */
	public static function generate_artifact( $sections, $idea_context ) {
		return self::generate_html_export( $sections, $idea_context );
	}

	private static function generate_html_export( $sections, $idea ) {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/sovereign/exports/';
		wp_mkdir_p( $export_dir );

		$filename = 'sovereign-export-' . date( 'Ymd' ) . '-' . uniqid() . '.html'; // HTML export — importable by Word/LibreOffice as a rich document
		$destination_path = $export_dir . $filename;

		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
		$html .= '<title>' . esc_html( $idea ) . '</title>';
		$html .= '<style>body{font-family:Georgia,serif;max-width:800px;margin:40px auto;line-height:1.7}';
		$html .= 'h1{font-size:24px}h2{font-size:18px;margin-top:2em}pre{white-space:pre-wrap;font-family:inherit}</style>';
		$html .= '</head><body><h1>' . esc_html( $idea ) . '</h1>';
		
		foreach ( $sections as $layer => $content ) {
			$html .= '<h2>' . esc_html( strtoupper( $layer ) ) . '</h2>';
			$html .= '<pre>' . esc_html( $content ) . '</pre>';
		}
		$html .= '</body></html>';

		file_put_contents( $destination_path, $html );
		$bytes_payload = file_get_contents( $destination_path );

		return [
			'docx_base64' => base64_encode( $bytes_payload ),
			'filename'    => $filename,
			'source'      => 'html_fallback',
			'local_path'  => $destination_path
		];
	}

	public static function handle_rest_generate( $request ) {
		$params   = $request->get_json_params();
		$sections = isset( $params['sections'] ) ? (array) $params['sections'] : [];
		$idea     = isset( $params['idea'] ) ? sanitize_text_field( $params['idea'] ) : 'Default Architecture Spec Payload Entry';

		if ( empty( $sections ) ) {
			return new WP_Error( 'empty_data', 'Missing dictionary context sections compilation data layout target.', [ 'status' => 400 ] );
		}

		$result = self::generate_artifact( $sections, $idea );
		return rest_ensure_response( $result );
	}
}