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
 * Load the motion script.
 *
 * Deferred, and deliberately not a dependency of anything. Every element it
 * touches renders in its final readable state without it; the only thing lost
 * when it fails to load is the movement. That ordering is the point — the
 * reveal class is added BY the script rather than sitting in the markup, so a
 * blocked or broken file can never leave the page blank.
 */
function electricchic_enqueue_scripts(): void {
	$relative = '/assets/js/ec-motion.js';
	$path     = get_stylesheet_directory() . $relative;

	if ( ! is_readable( $path ) ) {
		return;
	}

	wp_enqueue_script(
		'electricchic-motion',
		get_stylesheet_directory_uri() . $relative,
		array(),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'electricchic_enqueue_scripts', 20 );

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
 * Register the image sizes an editorial layout actually needs.
 *
 * WordPress and WooCommerce ship sizes that are square-biased and small: the
 * largest is 2048x2048, and a product page gets `woocommerce_single` at 600px
 * wide, which is visibly soft on any retina screen. There is no landscape size
 * at all, so a full-bleed hero had nothing to load.
 *
 * These must exist BEFORE images are imported. WordPress generates crops on
 * upload and does not retroactively create them, so registering afterwards
 * means re-importing every file.
 */
function electricchic_image_sizes(): void {
	add_image_size( 'ec_hero', 2560, 1440, true );      // 16:9, full-bleed
	add_image_size( 'ec_editorial', 1600, 1200, true ); // 4:3, one product per row
	add_image_size( 'ec_card', 900, 675, true );        // 4:3, grid card
}
add_action( 'after_setup_theme', 'electricchic_image_sizes' );

/**
 * Make the new sizes selectable in the editor's image controls.
 *
 * @param array<string, string> $sizes Existing choices.
 * @return array<string, string>
 */
function electricchic_image_size_names( array $sizes ): array {
	return array_merge(
		$sizes,
		array(
			'ec_hero'      => __( 'רקע מלא־מסך', 'electricchic' ),
			'ec_editorial' => __( 'מוצר גדול', 'electricchic' ),
			'ec_card'      => __( 'כרטיס', 'electricchic' ),
		)
	);
}
add_filter( 'image_size_names_choose', 'electricchic_image_size_names' );

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

/**
 * Load the first product image on a page eagerly.
 *
 * The homepage hero is the largest thing above the fold and therefore the
 * page's Largest Contentful Paint. WooCommerce's product-image block renders
 * it through wp_get_attachment_image(), which marks it loading="lazy" — so the
 * browser deliberately delays the one image the visitor is waiting for, and
 * only starts fetching it after layout.
 *
 * Only the first one. Making them all eager would have the browser fetch two
 * dozen product photographs at once and starve the one that matters, which is
 * the failure this is meant to fix, inverted.
 *
 * @param array<string, string> $attr Attributes for the image markup.
 * @return array<string, string>
 */
function electricchic_eager_first_product_image( array $attr ): array {
	static $done = false;

	if ( $done || is_admin() ) {
		return $attr;
	}

	if ( ! str_contains( $attr['class'] ?? '', 'attachment-' ) ) {
		return $attr;
	}

	$done = true;

	$attr['loading']       = 'eager';
	$attr['fetchpriority'] = 'high';

	// wp_get_attachment_image() adds decoding="async" by default, which tells
	// the browser it may paint the page without waiting. For the LCP image
	// that is the wrong instruction.
	unset( $attr['decoding'] );

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'electricchic_eager_first_product_image' );
