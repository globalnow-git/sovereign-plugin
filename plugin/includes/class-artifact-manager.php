<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Artifact_Manager {

	public static function persist_file_payload( $campaign_id, $relative_filename, $raw_contents, $mime_type ) {
		$upload_dir = wp_upload_dir();
		$sovereign_dir = $upload_dir['basedir'] . '/sovereign/artifacts/';
		wp_mkdir_p( $sovereign_dir );

		$destination_absolute_path = $sovereign_dir . sanitize_file_name( $relative_filename );
		file_put_contents( $destination_absolute_path, $raw_contents );

		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}sb_artifacts", [
			'campaign_id' => absint( $campaign_id ),
			'file_path'   => $destination_absolute_path,
			'file_type'   => sanitize_text_field( $mime_type ),
			'created_at'  => current_time( 'mysql' )
		] );

		return $destination_absolute_path;
	}




	// ── Artifact Review Screen ────────────────────────────────────────────
	public static function render_artifacts_screen() {
		if ( ! current_user_can( 'manage_sovereign' ) ) { wp_die( 'Insufficient permissions.' ); }
		global $wpdb;
		$tab    = sanitize_key( $_GET['artifact_tab'] ?? 'images' );
		$tabs   = [
			'images'   => [ 'table' => 'sb_generated_images',  'label' => 'Images',   'key_col' => 'image_url',     'status_col' => 'status' ],
			'videos'   => [ 'table' => 'sb_generated_videos',  'label' => 'Videos',   'key_col' => 'video_url',     'status_col' => 'status' ],
			'social'   => [ 'table' => 'sb_social_posts',      'label' => 'Social',   'key_col' => 'post_content',  'status_col' => 'status' ],
			'ads'      => [ 'table' => 'sb_ad_creatives',      'label' => 'Ad Copy',  'key_col' => 'headline',      'status_col' => 'status' ],
			'podcasts' => [ 'table' => 'sb_podcast_episodes',  'label' => 'Podcasts', 'key_col' => 'episode_title', 'status_col' => 'status' ],
			'general'  => [ 'table' => 'sb_artifacts',         'label' => 'General',  'key_col' => 'artifact_type', 'status_col' => 'status' ],
		];

		echo '<div class="wrap sb-admin-v103-wrapper">';
		echo '<h1>Artifacts</h1>';

		// Tab nav
		echo '<nav class="sb-tab-nav" style="margin-bottom:20px;border-bottom:1px solid #c3c4c7;">';
		foreach ( $tabs as $key => $info ) {
			$active = ( $tab === $key ) ? ' style="border-bottom:3px solid #0073aa;font-weight:600;"' : '';
			$url    = esc_url( admin_url( "admin.php?page=sb-artifacts&artifact_tab={$key}" ) );
			echo '<a href="' . $url . '" style="display:inline-block;padding:8px 16px;text-decoration:none;"' . $active . '>' . esc_html( $info['label'] ) . '</a>';
		}
		echo '</nav>';

		$cfg   = $tabs[ $tab ] ?? $tabs['general'];
		$rows  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$cfg['table']} ORDER BY id DESC LIMIT 50" ); // phpcs:ignore
		$count = count( $rows );
		echo '<p>' . $count . ' records</p>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>ID</th><th>' . esc_html( ucfirst( str_replace( '_', ' ', $cfg['key_col'] ) ) ) . '</th><th>Status</th><th>Campaign</th><th>Created</th>';
		if ( in_array( $tab, [ 'images', 'videos' ], true ) ) echo '<th>Preview / Approve</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6"><em>No ' . esc_html( $cfg['label'] ) . ' records yet.</em></td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . absint( $r->id ) . '</td>';
			$key_val = $r->{$cfg['key_col']} ?? '—';
			echo '<td>' . esc_html( mb_substr( $key_val, 0, 80 ) ) . '</td>';
			$status = $r->{$cfg['status_col']} ?? 'unknown';
			echo '<td><span class="sb-badge sb-badge-' . esc_attr( $status ) . '">' . esc_html( $status ) . '</span></td>';
			echo '<td>' . absint( $r->campaign_id ?? 0 ) . '</td>';
			echo '<td>' . esc_html( $r->created_at ?? '' ) . '</td>';
			if ( in_array( $tab, [ 'images', 'videos' ], true ) ) {
				$url_field = ( 'images' === $tab ) ? 'image_url' : 'video_url';
				$media_url = esc_url( $r->$url_field ?? '' );
				if ( $media_url ) {
					if ( 'images' === $tab ) {
						echo '<td><a href="' . $media_url . '" target="_blank"><img src="' . $media_url . '" style="max-width:80px;max-height:60px;border-radius:4px;" /></a>';
					} else {
						echo '<td><a href="' . $media_url . '" target="_blank" class="button button-small">&#9654; View</a>';
					}
					if ( 'pending' === $status ) {
						$ap_url = esc_url( admin_url( 'admin.php?page=sb-approvals&type=' . ( 'images' === $tab ? 'image_brief' : 'video_brief' ) ) );
						echo ' <a href="' . $ap_url . '" class="button button-small">Approvals</a>';
					}
					echo '</td>';
				} else {
					echo '<td>—</td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}