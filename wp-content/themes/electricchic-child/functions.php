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
	$relative = '/assets/css/design-system.css';
	$path     = get_stylesheet_directory() . $relative;

	// Version from the file's modification time, not the theme version.
	//
	// Using the theme version means every CSS change ships under a version
	// string that did not change, so returning visitors keep the stylesheet
	// their browser already cached. That is invisible in a fresh incognito
	// window and very visible to everyone else — it cost a confused round of
	// "the palette did not apply" during development, and in production it would
	// mean a fix reaching only new visitors.
	$version = is_readable( $path ) ? (string) filemtime( $path ) : null;

	wp_enqueue_style(
		'electricchic-design-system',
		get_stylesheet_directory_uri() . $relative,
		array(),
		$version
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
	$fonts = array( 'heebo-hebrew.woff2' );

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
 * Restrict a [products] shortcode to items actually in stock.
 *
 * The homepage section is headed "זמין עכשיו בחנות". Before this, it used
 * visibility="visible", which includes out-of-stock products — so a section
 * promising immediate availability was listing things the shop did not have.
 * On a site whose entire premise is honest availability, that is the worst
 * possible place for the bug to be.
 *
 * Applies only to shortcodes carrying the ec-available-now class, so ordinary
 * [products] usage is untouched. Out-of-stock products stay live and indexed
 * everywhere else, which is deliberate — the page has accumulated value and the
 * product will usually come back.
 *
 * The first version excluded WooCommerce's "outofstock" visibility term, which
 * was not enough: a backorder product is not out of stock, so Cortez GMAX —
 * orderable, arriving in 14–30 days — appeared under a heading promising
 * immediate collection. Caught by reading the rendered homepage after the real
 * catalogue landed.
 *
 * The conditions below mirror AvailabilityResolver's path to IN_STOCK_STORE:
 * units on the shelf, and none of the flags that outrank stock. That mirroring
 * is a known duplication and the one weak point here — if the resolver's rules
 * change, this query does not follow. It is survivable because each card still
 * renders its badge from the resolver itself, so a divergence shows up on the
 * page as a card whose badge contradicts the heading, rather than hiding.
 *
 * The durable fix is a derived-state cache written on save and queried here.
 * That is a real piece of work — invalidation on supplier edits, on stock
 * changes, and on the daily staleness rollover — and it is not being sneaked in
 * under a demo-content commit.
 *
 * @param array $query_args WP_Query arguments.
 * @param array $attributes Shortcode attributes.
 * @return array
 */
function electricchic_available_now_query( $query_args, $attributes ): array {
	$class = $attributes['class'] ?? '';

	if ( ! is_string( $class ) || ! str_contains( $class, 'ec-available-now' ) ) {
		return $query_args;
	}

	$query_args['meta_query'] = array_merge(
		$query_args['meta_query'] ?? array(),
		array(
			'relation' => 'AND',
			// On the shelf, right now.
			array(
				'key'     => '_stock',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
			// Discontinued and enquiry-only both outrank stock in the resolver,
			// so a product carrying either is not "available now" whatever the
			// shelf says. NOT EXISTS keeps products predating these fields.
			array(
				'relation' => 'OR',
				array(
					'key'     => '_ec_discontinued',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_ec_discontinued',
					'value'   => 'yes',
					'compare' => '!=',
				),
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_ec_enquiry_only',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_ec_enquiry_only',
					'value'   => 'yes',
					'compare' => '!=',
				),
			),
		)
	);

	return $query_args;
}
add_filter( 'woocommerce_shortcode_products_query', 'electricchic_available_now_query', 10, 2 );

/*
 * Removed: electricchic_loop_columns() and electricchic_products_per_page().
 *
 * loop_shop_columns and loop_shop_per_page only affect the LEGACY WooCommerce
 * templates. This is a block theme, where the catalog is rendered by the
 * Product Collection block and neither filter is consulted. They were dead code
 * that looked like configuration — the archive kept rendering three columns
 * while the filter confidently returned four.
 *
 * Controlling the block catalog needs a Product Catalog template override in
 * the child theme, which lands with the design work rather than being faked here.
 */
