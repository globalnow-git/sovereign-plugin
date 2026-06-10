<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SB_Game {

	public static function init() {
		add_action( 'sb_modules_register', [ __CLASS__, 'self_register' ] );
		add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'enqueue_phaser' ] );
		add_action( 'sb_signal_triggered', [ __CLASS__, 'on_signal_triggered' ], 10, 3 );
		add_action( 'init',                [ __CLASS__, 'register_shortcode' ] );
	}

	public static function self_register( $loader ) {
		if ( SB_Module_Loader::is_schema_ready() ) {
			$loader->register( 'game', '1.0.0', 'SB_Game' );
		}
	}

	public static function register_shortcode() {
		add_shortcode( 'sb_game', [ __CLASS__, 'render_game_shortcode' ] );
	}

	public static function enqueue_phaser() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'sb_game' ) ) {
			// Phaser Engine delivery layers mapping context cleanly using local asset cache configurations
			wp_enqueue_script( 'phaser-cdn', 'https://cdnjs.cloudflare.com/ajax/libs/phaser/3.60.0/phaser.min.js', [], '3.60.0', false );
			wp_enqueue_script( 'howler-cdn', 'https://cdnjs.cloudflare.com/ajax/libs/howler/2.2.3/howler.min.js', [], '2.2.3', false );
		}
	}

	public static function render_game_shortcode( $atts ) {
		$args = shortcode_atts( [ 'world_id' => 0 ], $atts );
		
		// Return hardcoded container block targets for the phaser rendering canvas loop mounts
		$html = '<div id="sb-phaser-game-container" data-world="' . absint( $args['world_id'] ) . '" style="width:800px; height:600px; margin:20px auto; background:#000; border-radius:4px; box-shadow:0 2px 8px rgba(0,0,0,0.3);"></div>';
		return $html;
	}

	public static function on_signal_triggered( $signal_type, $value, $user_id ) {
		if ( 'game_level_completed' === $signal_type ) {
			// Seamlessly connect physical engagement triggers directly into Many Roads routing models
			SB_Many_Roads::enter_road( $user_id, 'B' );
		}
	}
}
SB_Game::init();