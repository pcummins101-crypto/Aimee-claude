<?php
/**
 * Remove plugin-owned settings and leaderboard data.
 *
 * Deactivation and ordinary updates preserve every score. Choosing Delete in
 * WordPress permanently drops each site's Hyperlane leaderboard table. The
 * administrator's generated landing page is deliberately left intact.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete options, temporary API state and scores for the currently selected site.
 */
function avenra_hyperlane_uninstall_current_site() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'avenra_hyperlane_scores';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table prefix and fixed suffix.

	delete_option( 'avenra_hyperlane_page_id' );
	delete_option( 'avenra_hyperlane_version' );
	delete_option( 'avenra_hyperlane_db_version' );
	delete_option( 'avenra_hyperlane_leaderboard_season' );
	delete_option( 'avenra_hyperlane_leaderboard_cache_generation' );
	delete_transient( 'avenra_hyperlane_install_error' );

	// Expire DB-backed run tokens, rate limits and old leaderboard page caches.
	$patterns = array(
		$wpdb->esc_like( '_transient_ahl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ahl_' ) . '%',
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$patterns[0],
			$patterns[1]
		)
	);
}

$current_site_id = get_current_blog_id();
avenra_hyperlane_uninstall_current_site();

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		if ( (int) $site_id === (int) $current_site_id ) {
			continue;
		}
		switch_to_blog( (int) $site_id );
		avenra_hyperlane_uninstall_current_site();
		restore_current_blog();
	}
}
