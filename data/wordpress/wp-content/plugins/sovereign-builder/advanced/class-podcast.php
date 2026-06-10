<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SB_Podcast
 * Episode management, RSS feed, audio on Canadian soil.
 * Sovereignty: Audio files in wp_upload_dir() — Canadian soil.
 * RSS feed served from Canadian hosting. Apple/Spotify pull RSS — acceptable.
 */
class SB_Podcast {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		add_action( 'rest_api_init',        [ __CLASS__, 'register_routes' ] );
		add_action( 'sb_factory_run_complete', [ __CLASS__, 'generate_show_notes' ], 25, 3 );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'podcast', '1.0.0', 'SB_Podcast' );
		}
	}

	public static function register_routes() {
		register_rest_route( 'sovereign-builder/v1', '/podcast/feed.rss', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'serve_rss_feed' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** Create a podcast episode from an uploaded audio file. */
	public static function create_episode( $audio_file_path, $title, $campaign_id = 0 ) {
		global $wpdb;

		$upload_base = wp_upload_dir()['basedir'];
		if ( 0 !== strpos( realpath( $audio_file_path ), realpath( $upload_base ) ) ) {
			return new WP_Error( 'sovereignty_violation', 'Audio file must reside in wp_upload_dir() on Canadian hosting.' );
		}

		// R2-032: Use wp_check_filetype_and_ext() — more reliable than mime_content_type() across hosts
		$allowed_ext = [ 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav' ];
		$check = wp_check_filetype_and_ext( $audio_file_path, basename( $audio_file_path ) );
		if ( empty( $check['ext'] ) || ! array_key_exists( $check['ext'], $allowed_ext ) ) {
			return new WP_Error( 'invalid_audio', 'Only MP3, M4A, and WAV files are accepted.' );
		}

		$dest_dir = $upload_base . '/sovereign/podcasts/';
		wp_mkdir_p( $dest_dir );
		$filename = 'episode-' . uniqid() . '-' . sanitize_file_name( basename( $audio_file_path ) );
		$dest     = $dest_dir . $filename;
		copy( $audio_file_path, $dest );

		// Get episode number
		$ep_num = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sb_podcast_episodes" ) + 1;

			delete_transient( 'sb_podcast_rss_feed' ); // Bust RSS cache so new episode appears immediately
		$wpdb->insert( "{$wpdb->prefix}sb_podcast_episodes", [
			'title'            => sanitize_text_field( $title ),
			'description'      => '',
			'audio_path'       => $dest,
			'audio_url'        => wp_upload_dir()['baseurl'] . '/sovereign/podcasts/' . $filename,
			'duration_seconds' => self::get_duration( $dest ),
			'file_size_bytes'  => filesize( $dest ),
			'season'           => 1,
			'episode_number'   => $ep_num,
			'status'           => 'draft',
			'created_at'       => current_time( 'mysql' ),
		] );
		$episode_id = $wpdb->insert_id;

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_JOB_QUEUED, "Podcast episode created. ID: {$episode_id}", 0, [], 'info' );
		return $episode_id;
	}

	/** Generate show notes for an episode via factory. */
	public static function generate_show_notes( $run_id, $outputs, $campaign_id ) {
		if ( ! get_post_meta( $campaign_id, '_sb_podcast_episode_id', true ) ) {
			return;
		}
		$episode_id = (int) get_post_meta( $campaign_id, '_sb_podcast_episode_id', true );

		$prompt = 'You are a podcast producer. Based on the content analysis below, write: ' .
		          '1. Show notes (300 words, plain text). ' .
		          '2. Three social media teaser clips (one sentence each). ' .
		          '3. An email announcement subject line and body (150 words). ' .
		          'Output ONLY valid JSON: {"show_notes":"...","social_clips":["...","...","..."],' .
		          '"email_subject":"...","email_body":"..."}';

		$result = SB_WP_AI_Client::call( $prompt, $outputs );
		if ( is_wp_error( $result ) ) {
			return;
		}

		$data = json_decode( $result, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		global $wpdb;
		if ( ! empty( $data['show_notes'] ) ) {
			$wpdb->update(
				"{$wpdb->prefix}sb_podcast_episodes",
				[ 'description' => sanitize_textarea_field( $data['show_notes'] ) ],
				[ 'id' => $episode_id ]
			);
		}

		// Queue approval for email announcement
		if ( ! empty( $data['email_subject'] ) ) {
			SB_Approval_Engine::create_approval( $campaign_id, 'factory_output', [
				'type'          => 'podcast_announcement',
				'episode_id'    => $episode_id,
				'email_subject' => sanitize_text_field( $data['email_subject'] ),
				'email_body'    => sanitize_textarea_field( $data['email_body'] ),
				'social_clips'  => array_map( 'sanitize_text_field', $data['social_clips'] ?? [] ),
				'note'          => 'Approve podcast episode announcement before publishing or sending.',
			] );
		}
	}

	/** Publish episode (update status to published). */
	public static function publish_episode( $episode_id ) {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}sb_podcast_episodes",
			[ 'status' => 'published', 'published_at' => current_time( 'mysql' ) ],
			[ 'id' => absint( $episode_id ) ]
		);
		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED, "Podcast episode published. ID: {$episode_id}", 0, [], 'info' );
	}

	/** Serve RSS 2.0 + iTunes feed — all URLs point to Canadian hosting. */
	public static function serve_rss_feed( $request ) {
		// Cache RSS output for 1 hour — prevents DB hammering on repeated hits
		$cache_key = 'sb_podcast_rss_feed';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			header( 'Content-Type: application/rss+xml; charset=UTF-8' );
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built XML
			exit;
		}

		global $wpdb;

		$episodes = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}sb_podcast_episodes
			 WHERE status = 'published' ORDER BY episode_number DESC"
		);

		$title       = esc_xml( SB_Extension_API::get_setting( 'sb_podcast_title', get_bloginfo( 'name' ) ) );
		$description = esc_xml( SB_Extension_API::get_setting( 'sb_podcast_description', get_bloginfo( 'description' ) ) );
		$author      = esc_xml( SB_Extension_API::get_setting( 'sb_podcast_author', get_bloginfo( 'name' ) ) );
		$language    = esc_xml( SB_Extension_API::get_setting( 'sb_podcast_language', 'en-ca' ) );
		$feed_url    = esc_url( home_url( '/wp-json/sovereign-builder/v1/podcast/feed.rss' ) );
		$image_url   = '';
		$image_id    = (int) SB_Extension_API::get_setting( 'sb_podcast_image_id', 0 );
		if ( $image_id ) {
			$image_url = esc_url( wp_get_attachment_url( $image_id ) );
		}

		header( 'Content-Type: application/rss+xml; charset=UTF-8' );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
		$xml .= '<channel>' . "\n";
		$xml .= "<title>{$title}</title>\n";
		$xml .= "<description>{$description}</description>\n";
		$xml .= '<link>' . esc_url( home_url() ) . "</link>\n";
		$xml .= "<language>{$language}</language>\n";
		$xml .= "<itunes:author>{$author}</itunes:author>\n";
		$xml .= "<itunes:summary>{$description}</itunes:summary>\n";
		$xml .= "<itunes:explicit>no</itunes:explicit>\n";
		$xml .= "<atom:link href=\"{$feed_url}\" rel=\"self\" type=\"application/rss+xml\" xmlns:atom=\"http://www.w3.org/2005/Atom\"/>\n";
		if ( $image_url ) {
			$xml .= "<itunes:image href=\"{$image_url}\"/>\n";
			$xml .= "<image><url>{$image_url}</url><title>{$title}</title><link>" . esc_url( home_url() ) . "</link></image>\n";
		}

		foreach ( $episodes as $ep ) {
			$pub_date = $ep->published_at ? date( 'r', strtotime( $ep->published_at ) ) : date( 'r' );
			$xml .= "<item>\n";
			$xml .= '<title>' . esc_xml( $ep->title ) . "</title>\n";
			$xml .= '<description><![CDATA[' . $ep->description . ']]></description>' . "\n";
			$xml .= "<itunes:summary><![CDATA[{$ep->description}]]></itunes:summary>\n";
			$xml .= "<itunes:duration>{$ep->duration_seconds}</itunes:duration>\n";
			$xml .= "<itunes:episode>{$ep->episode_number}</itunes:episode>\n";
			$xml .= "<pubDate>{$pub_date}</pubDate>\n";
			$xml .= '<enclosure url="' . esc_url( $ep->audio_url ) . '" length="' . absint( $ep->file_size_bytes ) . '" type="audio/mpeg"/>' . "\n";
			$xml .= '<guid isPermaLink="false">' . esc_url( $ep->audio_url ) . "</guid>\n";
			$xml .= "</item>\n";
		}

		$xml .= "</channel>\n</rss>";

		// Store in transient for 1 hour; bust cache when an episode is saved
		set_transient( $cache_key, $xml, HOUR_IN_SECONDS );

		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built XML
		exit;
	}

	/** Estimate audio duration from file size (rough estimate for MP3 at 128kbps). */
	private static function get_duration( $path ) {
		if ( ! file_exists( $path ) ) {
			return 0;
		}
		$size_bytes = filesize( $path );
		return (int) ( $size_bytes / 16000 ); // 128kbps = 16KB/s approx
	}
}
SB_Podcast::init();