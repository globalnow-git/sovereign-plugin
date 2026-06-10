<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SB_Social_Broadcaster
 * Social media posting via third-party plugin integration only.
 * Never calls platform APIs directly — uses installed social plugins.
 * Sovereignty: Social plugins handle platform API calls — not our code.
 * Content only sent — zero user PII.
 */
class SB_Social_Broadcaster {

	public static function init() {
		add_action( 'sb_modules_register',   [ __CLASS__, 'self_register' ] );
		add_action( 'sb_approval_processed', [ __CLASS__, 'on_approval_processed' ], 10, 3 );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'social-broadcaster', '1.0.0', 'SB_Social_Broadcaster' );
		}
	}

	/** Detect which social plugin is installed. Returns slug or null. */
	public static function detect_plugin() {
		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) ) {
			return 'jetpack';
		}
		if ( class_exists( 'Rop_Posts_Queue' ) ) {
			return 'revive_old_posts';
		}
		if ( function_exists( 'wpscp_post_now' ) ) {
			return 'social_auto_poster';
		}
		if ( class_exists( 'nxs_snap' ) ) {
			return 'nextscripts_snap';
		}
		return null;
	}

	/**
	 * Generate platform-specific post copy from factory output.
	 * Returns array keyed by platform slug.
	 */
	public static function generate_post_content( $factory_outputs, $platforms = [ 'facebook', 'instagram', 'linkedin', 'x' ] ) {
		$limits = [
			'facebook'  => [ 'chars' => 500,  'hashtags' => '2-3',   'tone' => 'conversational and engaging' ],
			'instagram' => [ 'chars' => 2200, 'hashtags' => '10-15', 'tone' => 'visual-first, punchy hook' ],
			'linkedin'  => [ 'chars' => 3000, 'hashtags' => '3-5',   'tone' => 'professional and insightful' ],
			'x'         => [ 'chars' => 280,  'hashtags' => '1-2',   'tone' => 'concise and punchy' ],
		];

		$results = [];
		foreach ( $platforms as $platform ) {
			if ( ! isset( $limits[ $platform ] ) ) {
				continue;
			}
			$spec = $limits[ $platform ];

			$system_prompt = sprintf(
				'You are a social media copywriter for %s. Write one post from the product analysis below. ' .
				'Max %d characters. Use %s hashtags. Tone: %s. ' .
				'Output ONLY the post text — no preamble, no quotes, no JSON.',
				strtoupper( $platform ),
				$spec['chars'],
				$spec['hashtags'],
				$spec['tone']
			);

			$result = SB_WP_AI_Client::call( $system_prompt, $factory_outputs );
			if ( ! is_wp_error( $result ) ) {
				$results[ $platform ] = sanitize_textarea_field( $result );
			}
		}
		return $results;
	}

	/**
	 * Create WP draft post from social content. Returns post_id.
	 * HITM: does not publish — only creates draft for approval.
	 */
	public static function create_draft_post( $content, $platform, $campaign_id, $image_id = 0 ) {
		$post_id = wp_insert_post( [
			'post_title'   => '[SB Social] ' . ucfirst( $platform ) . ' — ' . date( 'Y-m-d' ),
			'post_content' => wp_kses_post( $content ),
			'post_status'  => 'draft',
			'post_type'    => 'post',
		] );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $image_id ) {
			set_post_thumbnail( $post_id, $image_id );
		}

		update_post_meta( $post_id, '_sb_social_platform', sanitize_key( $platform ) );
		update_post_meta( $post_id, '_sb_campaign_id', absint( $campaign_id ) );

		return $post_id;
	}

	/**
	 * Queue social post for HITM approval.
	 * Nothing publishes until human approves.
	 */
	public static function queue_for_approval( $campaign_id, $platform_contents, $image_id = 0, $schedule_at = 0 ) {
		global $wpdb;

		foreach ( $platform_contents as $platform => $content ) {
			$post_id = self::create_draft_post( $content, $platform, $campaign_id, $image_id );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			$wpdb->insert( "{$wpdb->prefix}sb_social_posts", [
				'approval_id'   => 0,
				'wp_post_id'    => absint( $post_id ),
				'platform'      => sanitize_key( $platform ),
				'content'       => sanitize_textarea_field( $content ),
				'image_id'      => absint( $image_id ),
				'social_plugin' => sanitize_key( self::detect_plugin() ?? 'none' ),
				'status'        => 'draft',
				'scheduled_at'  => $schedule_at ? date( 'Y-m-d H:i:s', absint( $schedule_at ) ) : null,
				'created_at'    => current_time( 'mysql' ),
			] );
			$social_post_id = $wpdb->insert_id;

			$approval_id = SB_Approval_Engine::create_approval( $campaign_id, 'social_post', [
				'social_post_id' => $social_post_id,
				'wp_post_id'     => $post_id,
				'platform'       => $platform,
				'content'        => $content,
				'image_id'       => $image_id,
				'schedule_at'    => $schedule_at,
				'note'           => "Review {$platform} post before publishing via social plugin.",
			] );

			// Update social post with approval_id
			$wpdb->update(
				"{$wpdb->prefix}sb_social_posts",
				[ 'approval_id' => absint( $approval_id ) ],
				[ 'id' => $social_post_id ]
			);
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_JOB_QUEUED,
			'Social posts queued for HITM approval. Platforms: ' . implode( ', ', array_keys( $platform_contents ) ),
			0, [], 'info'
		);
	}

	/**
	 * After human approval: publish via detected social plugin.
	 * Never calls platform APIs directly.
	 */
	public static function publish_via_plugin( $wp_post_id, $platform ) {
		$detected = self::detect_plugin();

		if ( ! $detected ) {
			SB_Event_Logger::log_audit( SB_Event_Keys::EV_SOCIAL_PLUGIN_NOT_FOUND,
				'No social plugin detected. Post not published. Install Jetpack, Revive Old Posts, or Social Auto Poster.',
				0, [], 'info'
			);
			return false;
		}

		wp_publish_post( absint( $wp_post_id ) );

		switch ( $detected ) {
			case 'jetpack':
				do_action( 'jetpack_sync_post', absint( $wp_post_id ) );
				break;
			case 'revive_old_posts':
				do_action( 'rop_post_published', absint( $wp_post_id ) );
				break;
			case 'social_auto_poster':
				if ( function_exists( 'wpscp_post_now' ) ) {
					wpscp_post_now( absint( $wp_post_id ), [ $platform ] );
				}
				break;
			case 'nextscripts_snap':
				do_action( 'nxs_snax_on_publish', absint( $wp_post_id ) );
				break;
		}

		SB_Event_Logger::log_audit( SB_Event_Keys::EV_APPROVAL_PROCESSED,
			"Social post published via {$detected}. WP Post: {$wp_post_id}. Platform: {$platform}",
			0, [ 'plugin' => $detected, 'platform' => $platform ], 'info'
		);
		return true;
	}

	public static function on_approval_processed( $approval_id, $action, $campaign_id ) {
		if ( 'approved' !== $action ) {
			return;
		}
		global $wpdb;
		$approval = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_approvals WHERE id = %d", absint( $approval_id )
		) );
		if ( ! $approval || 'social_post' !== $approval->approval_type ) {
			return;
		}
		$payload = json_decode( $approval->payload, true );
		if ( empty( $payload['wp_post_id'] ) || empty( $payload['platform'] ) ) {
			return;
		}

		self::publish_via_plugin( $payload['wp_post_id'], $payload['platform'] );

		$social_post_id = absint( $payload['social_post_id'] ?? 0 );
		if ( $social_post_id > 0 ) { // R2-029: guard against id=0 silent no-op
			$wpdb->update(
				"{$wpdb->prefix}sb_social_posts",
				[ 'status' => 'published', 'published_at' => current_time( 'mysql' ) ],
				[ 'id' => $social_post_id ]
			);
			if ( ! $wpdb->rows_affected ) {
				SB_Event_Logger::log_audit( SB_Event_Keys::EV_EMAIL_FAILED, "Social post update affected 0 rows for id={$social_post_id}", 0, [], 'info' );
			}
		}
	}
}
SB_Social_Broadcaster::init();