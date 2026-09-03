<?php
/**
 * Plugin Name:       Avenrà Hyperlane
 * Plugin URI:        https://rideavenra.com/
 * Description:       The next-generation three-route Avenrà EVO browser game with photographic 2.5D Living Roads, Rider Dynamics 2.0, Weekly Works Runs and spatial audio.
 * Version:           3.3.15
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Avenrà
 * Author URI:        https://rideavenra.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       avenra-hyperlane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AVENRA_HYPERLANE_VERSION', '3.3.15' );
define( 'AVENRA_HYPERLANE_FILE', __FILE__ );
define( 'AVENRA_HYPERLANE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AVENRA_HYPERLANE_URL', plugin_dir_url( __FILE__ ) );

require_once AVENRA_HYPERLANE_PATH . 'includes/class-avenra-hyperlane-leaderboard.php';

final class Avenra_Hyperlane {
	const PAGE_OPTION = 'avenra_hyperlane_page_id';
	const PAGE_META   = '_avenra_hyperlane_managed';
	const VERSION_KEY = 'avenra_hyperlane_version';

	/**
	 * Register front-end hooks.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'plugin_action_links_' . plugin_basename( AVENRA_HYPERLANE_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_network_site' ), 100, 1 );
	}

	/**
	 * Install the managed landing page on activation.
	 *
	 * @param bool $network_wide Whether this is a network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$page_id = self::install_for_current_site();
				self::flush_runtime_caches( $page_id );
				restore_current_blog();
			}
		} else {
			$page_id = self::install_for_current_site();
			self::flush_runtime_caches( $page_id );
		}
	}

	/**
	 * Keep user content intact on deactivation.
	 */
	public static function deactivate() {
		// The generated page and every leaderboard score are deliberately preserved.
	}

	/**
	 * Install on sites created after a network activation.
	 *
	 * @param WP_Site $new_site Newly created site object.
	 */
	public static function initialize_network_site( $new_site ) {
		if ( ! is_multisite() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( AVENRA_HYPERLANE_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::install_for_current_site();
		restore_current_blog();
	}

	/**
	 * Run lightweight migrations during ordinary plugin updates.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_KEY ) === AVENRA_HYPERLANE_VERSION ) {
			return;
		}

		$page_id = self::install_for_current_site();
		self::flush_runtime_caches( $page_id );
	}

	/**
	 * Create or recover the plugin-owned page without touching unrelated pages.
	 *
	 * @return int Landing page ID, or zero on failure.
	 */
	private static function install_for_current_site() {
		Avenra_Hyperlane_Leaderboard::install_schema();

		$page_id = absint( get_option( self::PAGE_OPTION ) );

		if ( $page_id && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			self::maybe_update_managed_page_title( $page_id );
			update_post_meta( $page_id, self::PAGE_META, '1' );
			update_option( self::VERSION_KEY, AVENRA_HYPERLANE_VERSION, false );
			return $page_id;
		}

		$managed_pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'meta_key'         => self::PAGE_META,
				'meta_value'       => '1',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		if ( ! empty( $managed_pages ) ) {
			$page_id = absint( $managed_pages[0] );
		} else {
			$result = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => 'Avenrà Hyperlane: The Game',
					'post_name'      => 'avenra-hyperlane',
					'post_content'   => "<!-- wp:shortcode -->\n[avenra_hyperlane]\n<!-- /wp:shortcode -->",
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				set_transient(
					'avenra_hyperlane_install_error',
					$result->get_error_message(),
					5 * MINUTE_IN_SECONDS
				);
				return 0;
			}

			$page_id = absint( $result );
		}

		self::maybe_update_managed_page_title( $page_id );
		update_post_meta( $page_id, self::PAGE_META, '1' );
		update_option( self::PAGE_OPTION, $page_id, false );
		update_option( self::VERSION_KEY, AVENRA_HYPERLANE_VERSION, false );

		return $page_id;
	}

	/**
	 * Clear page caches after an upgrade so the landing page exposes the new
	 * physical game-entry URL immediately.
	 *
	 * @param int $page_id Managed landing page ID.
	 */
	private static function flush_runtime_caches( $page_id ) {
		if ( $page_id ) {
			clean_post_cache( absint( $page_id ) );
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * Upgrade only the untouched plugin-managed page title.
	 *
	 * @param int $page_id Managed landing page ID.
	 */
	private static function maybe_update_managed_page_title( $page_id ) {
		if ( 'Avenrà Hyperlane' !== get_the_title( $page_id ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'         => absint( $page_id ),
				'post_title' => 'Avenrà Hyperlane: The Game',
			)
		);
	}

	/**
	 * Register reusable shortcodes.
	 */
	public static function register_shortcodes() {
		add_shortcode( 'avenra_hyperlane', array( __CLASS__, 'landing_shortcode' ) );
		add_shortcode( 'avenra_hyperlane_game', array( __CLASS__, 'game_shortcode' ) );
	}

	/**
	 * Render the complete landing experience.
	 *
	 * @return string
	 */
	public static function landing_shortcode() {
		self::enqueue_assets();
		return self::render_landing();
	}

	/**
	 * Render only the game frame for use on another page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function game_shortcode( $atts = array() ) {
		self::enqueue_assets();
		$atts = shortcode_atts(
			array(
				'height' => '760',
			),
			$atts,
			'avenra_hyperlane_game'
		);
		$height = max( 420, min( 1000, absint( $atts['height'] ) ) );

		return sprintf(
			'<div class="ahl ahl-embed-only"><div class="ahl-game-shell" style="--ahl-frame-height:%1$dpx"><iframe class="ahl-game-frame" src="%2$s" title="Play Avenrà Hyperlane" allow="fullscreen; accelerometer; gyroscope; screen-wake-lock" allowfullscreen loading="eager"></iframe></div></div>',
			$height,
			esc_url( self::game_url() )
		);
	}

	/**
	 * Enqueue assets only where Hyperlane is rendered.
	 */
	public static function maybe_enqueue_assets() {
		if ( self::is_managed_landing_page() ) {
			self::enqueue_assets();
			return;
		}

		global $post;
		if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'avenra_hyperlane' ) || has_shortcode( $post->post_content, 'avenra_hyperlane_game' ) ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Register and enqueue landing assets.
	 */
	private static function enqueue_assets() {
		$style_path = AVENRA_HYPERLANE_PATH . 'assets/css/landing.css';
		$script_path = AVENRA_HYPERLANE_PATH . 'assets/js/landing.js';
		$style_ver = file_exists( $style_path ) ? (string) filemtime( $style_path ) : AVENRA_HYPERLANE_VERSION;
		$script_ver = file_exists( $script_path ) ? (string) filemtime( $script_path ) : AVENRA_HYPERLANE_VERSION;

		wp_enqueue_style( 'avenra-hyperlane-landing', AVENRA_HYPERLANE_URL . 'assets/css/landing.css', array(), $style_ver );
		wp_enqueue_script( 'avenra-hyperlane-landing', AVENRA_HYPERLANE_URL . 'assets/js/landing.js', array(), $script_ver, true );
		wp_script_add_data( 'avenra-hyperlane-landing', 'strategy', 'defer' );
	}

	/**
	 * Use the cinematic template only for the page still carrying our shortcode.
	 *
	 * @param string $template Theme template path.
	 * @return string
	 */
	public static function template_include( $template ) {
		if ( ! self::is_managed_landing_page() ) {
			return $template;
		}

		$page = get_post();
		if ( ! $page instanceof WP_Post || ! has_shortcode( $page->post_content, 'avenra_hyperlane' ) ) {
			return $template;
		}

		$plugin_template = AVENRA_HYPERLANE_PATH . 'templates/landing-page.php';
		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	/**
	 * Determine whether the queried page is the plugin-managed page.
	 *
	 * @return bool
	 */
	private static function is_managed_landing_page() {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		return $page_id && is_page( $page_id ) && '1' === get_post_meta( $page_id, self::PAGE_META, true );
	}

	/**
	 * Build the portable static-game URL.
	 *
	 * @return string
	 */
	private static function game_url() {
		return add_query_arg(
			array(
				'build' => AVENRA_HYPERLANE_VERSION,
				'api'   => Avenra_Hyperlane_Leaderboard::get_config_url(),
			),
			AVENRA_HYPERLANE_URL . 'game/index-' . AVENRA_HYPERLANE_VERSION . '.html'
		);
	}

	/**
	 * Render the premium player-facing landing page.
	 *
	 * @return string
	 */
	public static function render_landing() {
		$game_url = self::game_url();
		$leaderboard_url = add_query_arg( 'leaderboard', '1', $game_url );
		$leaderboard_routes = array(
			'city'     => Avenra_Hyperlane_Leaderboard::get_top_scores( 10, 'city' ),
			'rural'    => Avenra_Hyperlane_Leaderboard::get_top_scores( 10, 'rural' ),
			'motorway' => Avenra_Hyperlane_Leaderboard::get_top_scores( 10, 'motorway' ),
		);
		$shot_base = AVENRA_HYPERLANE_URL . 'assets/screenshots/';
		$asset_version = AVENRA_HYPERLANE_VERSION;
		$wordmark_url = add_query_arg( 'v', $asset_version, AVENRA_HYPERLANE_URL . 'assets/brand/avenra-wordmark-graphite.png' );
		$city_shot = add_query_arg( 'v', $asset_version, $shot_base . 'hyperlane-district-v150.jpg' );
		$desktop_hero_shot = add_query_arg( 'v', $asset_version, $shot_base . 'hyperlane-hero-desktop-v150.jpg' );
		$mobile_hero_shot = add_query_arg( 'v', $asset_version, $shot_base . 'hyperlane-hero-mobile-v150.jpg' );
		$conditions_shot = add_query_arg( 'v', $asset_version, $shot_base . 'hyperlane-setup-v150.jpg' );
		$halo_shot = add_query_arg( 'v', $asset_version, $shot_base . 'hyperlane-halo-v160.jpg' );

		ob_start();
		include AVENRA_HYPERLANE_PATH . 'templates/landing-content.php';
		return (string) ob_get_clean();
	}

	/**
	 * Add a direct front-end link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( $page_id && 'page' === get_post_type( $page_id ) ) {
			array_unshift( $links, '<a href="' . esc_url( get_permalink( $page_id ) ) . '">View Hyperlane</a>' );
		}
		return $links;
	}

	/**
	 * Surface a page-creation failure to administrators.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = get_transient( 'avenra_hyperlane_install_error' );
		if ( ! $message ) {
			return;
		}

		delete_transient( 'avenra_hyperlane_install_error' );
		printf(
			'<div class="notice notice-error"><p><strong>Avenrà Hyperlane:</strong> %s</p></div>',
			esc_html( $message )
		);
	}
}

register_activation_hook( AVENRA_HYPERLANE_FILE, array( 'Avenra_Hyperlane', 'activate' ) );
register_deactivation_hook( AVENRA_HYPERLANE_FILE, array( 'Avenra_Hyperlane', 'deactivate' ) );
Avenra_Hyperlane_Leaderboard::boot();
Avenra_Hyperlane::boot();
