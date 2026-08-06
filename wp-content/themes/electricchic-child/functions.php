<?php
/**
 * ElectricChic child theme.
 *
 * Presentation only. Every business rule — availability, pricing, suppliers,
 * returns — lives in the electricchic-core plugin, so it can be unit-tested and
 * survives a theme change.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the parent and child stylesheets, and the design system.
 */
function electricchic_enqueue_styles(): void {
	$theme   = wp_get_theme();
	$version = $theme->get( 'Version' );

	wp_enqueue_style(
		'electricchic-design-system',
		get_stylesheet_directory_uri() . '/assets/css/design-system.css',
		array(),
		is_string( $version ) ? $version : null
	);
}
add_action( 'wp_enqueue_scripts', 'electricchic_enqueue_styles', 20 );

/**
 * Preload the two font files above the fold.
 *
 * Hebrew body text and headings both render immediately; without the preload the
 * first paint swaps fonts visibly, which reads as cheap on a retail page.
 */
function electricchic_preload_fonts(): void {
	$fonts = array( 'heebo-hebrew.woff2', 'frank-hebrew.woff2' );

	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_stylesheet_directory_uri() . '/assets/fonts/' . $font )
		);
	}
}
add_action( 'wp_head', 'electricchic_preload_fonts', 1 );

/**
 * Declare WooCommerce support so product templates render inside the theme.
 */
function electricchic_woocommerce_support(): void {
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
}
add_action( 'after_setup_theme', 'electricchic_woocommerce_support' );

/**
 * Show four products per row on the shop archive.
 *
 * @param int $columns Incoming column count.
 * @return int
 */
function electricchic_loop_columns( $columns ): int {
	unset( $columns ); // Fixed layout: the incoming value is deliberately ignored.

	return 4;
}
add_filter( 'loop_shop_columns', 'electricchic_loop_columns', 20 );

/**
 * Show 16 products per page, a whole number of rows at four columns.
 *
 * @param int $per_page Incoming per-page count.
 * @return int
 */
function electricchic_products_per_page( $per_page ): int {
	unset( $per_page ); // Fixed layout: four columns, four rows.

	return 16;
}
add_filter( 'loop_shop_per_page', 'electricchic_products_per_page', 20 );
