<?php
/**
 * Cinematic full-page template for the managed Hyperlane page.
 *
 * @package Avenra_Hyperlane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#ffffff">
	<?php wp_head(); ?>
</head>
<body <?php body_class( array( 'avenra-hyperlane-page', 'avenra-site-managed' ) ); ?>>
<?php wp_body_open(); ?>
<a class="ahl-skip-link" href="#ahl-main">Skip to Hyperlane</a>
<?php echo Avenra_Hyperlane::render_landing(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted plugin template. ?>
<?php wp_footer(); ?>
</body>
</html>
