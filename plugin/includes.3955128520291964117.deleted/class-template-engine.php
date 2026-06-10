<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Template_Engine {

	public static function compile_docx_template( $source_file, $destination_file, $token_replacement_matrix ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'ZipArchive PHP compilation module missing on host container environment loops.', 0, [], 'error' );
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $source_file ) ) {
			return false;
		}

		// Read document structure layout data stream safely
		$document_content = $zip->getFromName( 'word/document.xml' );
		if ( ! $document_content ) {
			$zip->close();
			return false;
		}

		foreach ( $token_replacement_matrix as $key => $val ) {
			$document_content = str_replace( $key, esc_xml( $val ), $document_content );
		}

		// Clone primary source into target output directory arrays securely
		copy( $source_file, $destination_file );

		$target_zip = new ZipArchive();
		if ( true === $target_zip->open( $destination_file ) ) {
			$target_zip->addFromString( 'word/document.xml', $document_content );
			$target_zip->close();
		}

		$zip->close();
		return true;
	}
}