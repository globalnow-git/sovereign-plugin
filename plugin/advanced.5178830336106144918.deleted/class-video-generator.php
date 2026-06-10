<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SB_Video_Generator
 * AI video generation with two HITM gates.
 * Gate 1: Script + brief approval before API call.
 * Gate 2: Video approval before attachment to any content.
 * Sovereignty: Hedra/Runway/HeyGen are US-hosted. Script only — no PII.
 * Videos downloaded to wp_upload_dir() immediately on completion.
 */
class SB_Video_Generator {

	public static function init() {
		add_action( 'sb_modules_register',   [ __CLASS__, 'self_register' ] );
		add_action( 'sb_approval_processed', [ __CLASS__, 'on_approval_processed' ], 10, 3 );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'video-generator', '1.0.0', 'SB_Video_Generator' );
		}
	}

	/** Gate 1: Generate script from factory output. Queues approval before any API call. */
	public static function generate_script( $campaign_id, $factory_run_id, $duration_secs = 60 ) {
		global $wpdb;

		$run = $wpdb->get_row( $wpdb->prepare(
			"SELECT layer_outputs FROM {$wpdb->prefix}sb_factory_runs WHERE id = %d",
			absint( $factory_run_id )
		) );
		if ( ! $run ) {
			return;
		}

		$system_prompt = sprintf(
			'You are a professional video scriptwriter. Based on the product analysis below, ' .
			'write a %d-second marketing video script. ' .
			'Output ONLY valid JSON, no preamble: ' .
			'{"script_text":"(full spoken script)","talking_points":["point1","point2","point3"],' .
			'"visual_directions":"(brief description of visuals)","cta":"(closing call to action)"}',
			absint( $duration_secs )
		);

		$result = SB_WP_AI_Client::call( $system_prompt, $run->layer_outputs );
		if ( is_wp_error( $result ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'Script generation failed: ' . $result->get_error_message(), 0, [], 'info' );
			return;
		}

		$script_data = json_decode( $result, true );
		if ( ! is_array( $script_data ) ) {
			return;
		}

		$wpdb->insert( "{$wpdb->prefix}sb_video_briefs", [
			'campaign_id'    => absint( $campaign_id ),
			'factory_run_id' => absint( $factory_run_id ),
			'script_text'    => sanitize_textarea_field( $script_data['script_text'] ?? '' ),
			'brief_json'     => wp_json_encode( $script_data ),
			'duration_secs'  => absint( $duration_secs ),
			'provider'       => sanitize_key( SB_Extension_API::get_setting( 'sb_video_provider', 'hedra' ) ),
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql' ),
		] );
		$brief_id = $wpdb->insert_id;

		// Gate 1 HITM
		SB_Approval_Engine::create_approval( $campaign_id, 'video_brief', [
			'brief_id' => $brief_id,
			'script'   => $script_data,
			'duration' => $duration_secs,
			'note'     => 'Review script before video generation is requested. API costs apply once approved.',
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Video brief queued for HITM review. Brief ID: {$brief_id}", 0, [], 'info' );
	}

	/** Gate 2: Request video after script approved. Downloads to Canadian soil on completion. */
	public static function request_video( $brief_id ) {
		global $wpdb;

		$brief_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_video_briefs WHERE id = %d",
			absint( $brief_id )
		) );
		if ( ! $brief_row ) {
			return new WP_Error( 'brief_not_found', 'Video brief not found.' );
		}

		$provider = $brief_row->provider ?: 'hedra';
		$api_key  = SB_Extension_API::get_setting( 'sb_video_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_video_key', 'Video API key not configured.' );
		}

		$script = $brief_row->script_text;
		$job_id = '';

		if ( 'hedra' === $provider ) {
			$response = wp_remote_post( 'https://api.hedra.com/v1/generate', [
				'timeout' => 30,
				'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'script' => $script, 'duration' => $brief_row->duration_secs ] ),
			] );
			if ( is_wp_error( $response ) ) { return $response; }
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'hedra_api', 'Hedra API error: HTTP ' . wp_remote_retrieve_response_code( $response ) );
			}
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$job_id = $body['job_id'] ?? '';
		} elseif ( 'runway' === $provider ) {
			$response = wp_remote_post( 'https://api.runwayml.com/v1/text-to-video', [
				'timeout' => 30,
				'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'prompt' => $script ] ),
			] );
			if ( is_wp_error( $response ) ) { return $response; }
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'runway_api', 'Runway API error: HTTP ' . wp_remote_retrieve_response_code( $response ) );
			}
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$job_id = $body['id'] ?? '';
		}

		if ( empty( $job_id ) ) {
			return new WP_Error( 'no_job_id', 'Video API did not return a job ID.' );
		}

		// Update brief with job_id for polling
		$brief_data           = json_decode( $brief_row->brief_json, true ) ?: [];
		$brief_data['job_id'] = $job_id;
		$wpdb->update(
			"{$wpdb->prefix}sb_video_briefs",
			[ 'status' => 'processing', 'brief_json' => wp_json_encode( $brief_data ) ],
			[ 'id' => absint( $brief_id ) ]
		);

		// Schedule polling via Action Scheduler
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 120,
				'sb_poll_video_job',
				[ 'brief_id' => $brief_id, 'job_id' => $job_id, 'provider' => $provider, 'attempt' => 1 ]
			);
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_JOB_QUEUED, "Video job queued. Provider: {$provider}. Job: {$job_id}", 0, [], 'info' );
		return $job_id;
	}

	/** Poll video job — called by Action Scheduler. Downloads on completion. */
	public static function poll_job( $brief_id, $job_id, $provider, $attempt ) {
		if ( $attempt > 20 ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, "Video job timed out after 20 attempts. Brief: {$brief_id}", 0, [], 'info' );
			return;
		}

		$api_key    = SB_Extension_API::get_setting( 'sb_video_api_key', '' );
		$video_url  = '';
		$is_done    = false;

		if ( 'hedra' === $provider ) {
			$response = wp_remote_get( "https://api.hedra.com/v1/jobs/{$job_id}", [
				'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
			] );
			if ( is_wp_error( $response ) ) { return; }
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$is_done   = ( 'completed' === ( $body['status'] ?? '' ) );
			$video_url = $body['video_url'] ?? '';
		} elseif ( 'runway' === $provider ) {
			$response = wp_remote_get( "https://api.runwayml.com/v1/tasks/{$job_id}", [
				'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
			] );
			if ( is_wp_error( $response ) ) { return; }
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$is_done   = ( 'SUCCEEDED' === ( $body['status'] ?? '' ) );
			$video_url = $body['output'][0] ?? '';
		}

		if ( ! $is_done ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 120,
					'sb_poll_video_job',
					[ 'brief_id' => $brief_id, 'job_id' => $job_id, 'provider' => $provider, 'attempt' => $attempt + 1 ]
				);
			}
			return;
		}

		// Download to Canadian soil
		$file_data = self::download_video( $video_url );
		if ( is_wp_error( $file_data ) ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_FACTORY_LAYER_ERROR, 'Video download failed: ' . $file_data->get_error_message(), 0, [], 'info' );
			return;
		}

		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_generated_videos", [
			'brief_id'    => absint( $brief_id ),
			'file_path'   => $file_data['path'],
			'file_url'    => $file_data['url'],
			'file_size'   => $file_data['size'],
			'provider'    => sanitize_key( $provider ),
			'cost_usd'    => 0.50,
			'status'      => 'generated',
			'created_at'  => current_time( 'mysql' ),
		] );
		$video_record_id = $wpdb->insert_id;

		$brief_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT campaign_id FROM {$wpdb->prefix}sb_video_briefs WHERE id = %d", absint( $brief_id )
		) );

		// Gate 2 HITM
		SB_Approval_Engine::create_approval( $brief_row->campaign_id ?? 0, 'generated_video', [
			'video_record_id' => $video_record_id,
			'file_url'        => $file_data['url'],
			'note'            => 'Review generated video before attaching to any page, post, email, or ad.',
		] );

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Video ready for HITM review. Record: {$video_record_id}", 0, [], 'info' );
	}

	/** Download video file to wp_upload_dir (Canadian soil). */
	private static function download_video( $video_url ) {
		$upload_dir = wp_upload_dir();
		$dest_dir   = $upload_dir['basedir'] . '/sovereign/videos/';
		wp_mkdir_p( $dest_dir );

		$filename = 'sb-video-' . uniqid() . '.mp4';
		$dest     = $dest_dir . $filename;

		$tmp = download_url( $video_url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		rename( $tmp, $dest );
		return [
			'path' => $dest,
			'url'  => $upload_dir['baseurl'] . '/sovereign/videos/' . $filename,
			'size' => filesize( $dest ),
		];
	}

	public static function on_approval_processed( $approval_id, $action, $campaign_id ) {
		if ( 'approved' !== $action ) {
			return;
		}
		global $wpdb;
		$approval = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d", absint( $approval_id )
		) );
		if ( ! $approval ) {
			return;
		}
		$payload = json_decode( $approval->payload, true );

		if ( 'video_brief' === $approval->approval_type && ! empty( $payload['brief_id'] ) ) {
			self::request_video( $payload['brief_id'] );
		}
		if ( 'generated_video' === $approval->approval_type && ! empty( $payload['video_record_id'] ) ) {
			$wpdb->update(
				"{$wpdb->prefix}sb_generated_videos",
				[ 'status' => 'approved', 'approved_at' => current_time( 'mysql' ) ],
				[ 'id' => absint( $payload['video_record_id'] ) ]
			);
		}
	}
}
SB_Video_Generator::init();
add_action( 'sb_poll_video_job', [ 'SB_Video_Generator', 'poll_job' ], 10, 4 );