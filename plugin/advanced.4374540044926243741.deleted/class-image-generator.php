<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SB_Image_Generator
 * AI image generation with two HITM gates.
 * Gate 1: Brief approval before API call.
 * Gate 2: Image approval before attachment.
 * Sovereignty: DALL-E/Stability AI are US-hosted. Image prompt only — no PII.
 * Images downloaded to wp_upload_dir() immediately on generation.
 */
class SB_Image_Generator {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		add_action( 'sb_approval_processed', [ __CLASS__, 'on_approval_processed' ], 10, 3 );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'image-generator', '1.0.0', 'SB_Image_Generator' );
		}
	}

	/** Gate 1: Generate image brief from factory output. Queues approval before any API call. */
	public static function generate_brief( $campaign_id, $factory_run_id, $layer_content ) {
		global $wpdb;

		$system_prompt =
			'You are a professional creative director. Based on the product/marketing text below, ' .
			'produce a detailed image generation brief. ' .
			'Output ONLY valid JSON, no preamble: ' .
			'{"subject":"(what the image shows)","style":"(e.g. photorealistic, flat illustration)",' .
			'"mood":"(e.g. professional, energetic)","dimensions":"1024x1024",' .
			'"negative_prompt":"(what to avoid: blurry, text, watermark, low quality)"}';

		$result = SB_WP_AI_Client::call( $system_prompt, $layer_content );
		if ( is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'Brief generation failed: ' . $result->get_error_message(), 0, [], 'info' );
			return;
		}

		$brief_data = json_decode( $result, true );
		if ( ! is_array( $brief_data ) ) {
			return;
		}

		$wpdb->insert( "{$wpdb->prefix}sb_image_briefs", [
			'campaign_id'    => absint( $campaign_id ),
			'factory_run_id' => absint( $factory_run_id ),
			'brief_json'     => wp_json_encode( $brief_data ),
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql' ),
		] );
		$brief_id = $wpdb->insert_id;

		// Gate 1 HITM: brief approval
		SB_Approval_Engine::create_approval( $campaign_id, 'image_brief', [
			'brief_id' => $brief_id,
			'brief'    => $brief_data,
			'note'     => 'Approve this image brief before generation is requested from the AI image API.',
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Image brief queued for HITM review. Brief ID: {$brief_id}", 0, [], 'info' );
	}

	/** Gate 2: Request image from API after brief is approved. Downloads to Canadian soil immediately. */
	public static function request_image( $brief_id ) {
		global $wpdb;

		$brief_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_image_briefs WHERE id = %d",
			absint( $brief_id )
		) );
		if ( ! $brief_row ) {
			return new WP_Error( 'brief_not_found', 'Image brief not found.' );
		}

		$brief    = json_decode( $brief_row->brief_json, true );
		$provider = SB_Extension_API::get_setting( 'sb_image_provider', 'dalle3' );
		$api_key  = SB_Extension_API::get_setting( 'sb_image_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_image_key', 'Image generation API key not configured.' );
		}

		$prompt    = self::build_prompt( $brief );
		$image_url = '';
		$cost_usd  = 0.04; // DALL-E 3 standard

		if ( 'dalle3' === $provider ) {
			$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'           => 'dall-e-3',
					'prompt'          => $prompt,
					'n'               => 1,
					'size'            => $brief['dimensions'] ?? '1024x1024',
					'response_format' => 'url',
				] ),
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$image_url = $body['data'][0]['url'] ?? '';
		} elseif ( 'stability-ai' === $provider ) {
			$response = wp_remote_post( 'https://api.stability.ai/v1/generation/stable-diffusion-xl-1024-v1-0/text-to-image', [
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body' => wp_json_encode( [
					'text_prompts'   => [ [ 'text' => $prompt, 'weight' => 1 ] ],
					'cfg_scale'      => 7,
					'samples'        => 1,
					'steps'          => 30,
				] ),
			] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$image_url = $body['artifacts'][0]['base64'] ?? '';
			$cost_usd  = 0.01;
		}

		if ( empty( $image_url ) ) {
			return new WP_Error( 'no_image_url', 'Image API returned empty URL.' );
		}

		// Download immediately to Canadian soil
		$attachment_id = self::download_and_import( $image_url, $brief['subject'] ?? 'sb-generated' );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$wpdb->insert( "{$wpdb->prefix}sb_generated_images", [
			'brief_id'      => absint( $brief_id ),
			'attachment_id' => absint( $attachment_id ),
			'provider'      => sanitize_key( $provider ),
			'prompt_used'   => $prompt,
			'cost_usd'      => (float) $cost_usd,
			'status'        => 'generated',
			'created_at'    => current_time( 'mysql' ),
		] );
		$image_record_id = $wpdb->insert_id;

		$wpdb->update(
			"{$wpdb->prefix}sb_image_briefs",
			[ 'status' => 'generated' ],
			[ 'id' => absint( $brief_id ) ]
		);

		// Gate 2 HITM: image approval before attaching anywhere
		SB_Approval_Engine::create_approval( $brief_row->campaign_id, 'generated_image', [
			'image_record_id' => $image_record_id,
			'attachment_id'   => $attachment_id,
			'provider'        => $provider,
			'cost_usd'        => $cost_usd,
			'note'            => 'Review and approve the generated image before it is attached to any content.',
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Image generated and queued for HITM review. Attachment: {$attachment_id}", 0, [], 'info' );
		return $image_record_id;
	}

	/** Download image from URL and import into WP Media Library. Canadian soil guarantee. */
	private static function download_and_import( $image_url_or_base64, $title ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload_dir = wp_upload_dir();
		$dest_dir   = $upload_dir['basedir'] . '/sovereign/generated/';
		wp_mkdir_p( $dest_dir );

		$filename = 'sb-gen-' . uniqid() . '.png';
		$dest     = $dest_dir . $filename;

		// Handle base64 (Stability AI) vs URL (DALL-E)
		if ( 0 === strpos( $image_url_or_base64, 'http' ) ) {
			$tmp = download_url( $image_url_or_base64, 60 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			copy( $tmp, $dest );
			@unlink( $tmp );
		} else {
			file_put_contents( $dest, base64_decode( $image_url_or_base64 ) );
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $dest,
		];

		$attachment_id = media_handle_sideload( $file_array, 0, sanitize_text_field( $title ) );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $dest );
			return $attachment_id;
		}

		return $attachment_id;
	}

	/** Build final image prompt from brief array. */
	private static function build_prompt( array $brief ) {
		$parts = [];
		if ( ! empty( $brief['subject'] ) )  { $parts[] = $brief['subject']; }
		if ( ! empty( $brief['style'] ) )    { $parts[] = $brief['style'] . ' style'; }
		if ( ! empty( $brief['mood'] ) )     { $parts[] = $brief['mood'] . ' mood'; }
		if ( ! empty( $brief['negative_prompt'] ) ) {
			$parts[] = 'Avoid: ' . $brief['negative_prompt'];
		}
		return implode( '. ', $parts );
	}

	/** Handle approvals for image-related types. */
	public static function on_approval_processed( $approval_id, $action, $campaign_id ) {
		global $wpdb;
		$approval = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d",
			absint( $approval_id )
		) );
		if ( ! $approval ) {
			return;
		}

		if ( 'approved' !== $action ) {
			return;
		}

		$payload = json_decode( $approval->payload, true );

		if ( 'image_brief' === $approval->approval_type && ! empty( $payload['brief_id'] ) ) {
			self::request_image( $payload['brief_id'] );
		}

		if ( 'generated_image' === $approval->approval_type ) {
			$wpdb->update(
				"{$wpdb->prefix}sb_generated_images",
				[ 'status' => 'approved', 'approved_at' => current_time( 'mysql' ) ],
				[ 'id' => absint( $payload['image_record_id'] ?? 0 ) ]
			);
		}
	}
}
SB_Image_Generator::init();