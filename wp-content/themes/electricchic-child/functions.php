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
 * The marker a block or shortcode carries to mean "only what we really have".
 */
const ELECTRICCHIC_AVAILABLE_NOW = 'ec-available-now';

/**
 * Restrict a listing to products that can genuinely be collected today.
 *
 * The conditions themselves are NOT here. They mirror the availability
 * resolver's path to IN_STOCK_STORE — units on the shelf, and none of the
 * flags that outrank stock — which makes them a business rule, and business
 * rules live in the plugin where they can be unit-tested.
 *
 * They used to be written out inline in this file. That was survivable while
 * one shortcode consumed them; it stopped being survivable the moment the
 * homepage needed the same question answered by a block, because the second
 * copy is where the two quietly stop agreeing.
 *
 * @param array<string, mixed> $query_args WP_Query arguments.
 * @return array<string, mixed>
 */
function electricchic_available_now( array $query_args ): array {
	if ( ! class_exists( \ElectricChic\Core\Integration\AvailableNowQuery::class ) ) {
		return $query_args;
	}

	return ( new \ElectricChic\Core\Integration\AvailableNowQuery() )->apply_to( $query_args );
}

/**
 * The shortcode path: [products class="ec-available-now"].
 *
 * Applies only to shortcodes carrying the marker, so ordinary [products] usage
 * is untouched. Out-of-stock products stay live and indexed everywhere else,
 * which is deliberate — the page has accumulated value and the product will
 * usually come back.
 *
 * @param array<string, mixed> $query_args WP_Query arguments.
 * @param array<string, mixed> $attributes Shortcode attributes.
 * @return array<string, mixed>
 */
function electricchic_available_now_query( $query_args, $attributes ): array {
	$class = $attributes['class'] ?? '';

	if ( ! is_string( $class ) || ! str_contains( $class, ELECTRICCHIC_AVAILABLE_NOW ) ) {
		return $query_args;
	}

	return electricchic_available_now( (array) $query_args );
}
add_filter( 'woocommerce_shortcode_products_query', 'electricchic_available_now_query', 10, 2 );

/**
 * The block path: a Product Collection carrying the ec-available-now class.
 *
 * Runs on query_loop_block_query_vars — the hook Product Collection itself
 * builds its query on — see WooCommerce's ProductCollection\Controller — so this runs at
 * a later priority to add conditions to what it produced rather than fighting
 * it.
 *
 * Identified by class name rather than by queryId. A numeric id has to be kept
 * unique by hand across every template, and renumbering one is a silent way to
 * point this filter at the wrong section; a class says what the section is for
 * and survives being moved.
 *
 * @param array<string, mixed> $query Query variables.
 * @param \WP_Block            $block The block being rendered.
 * @return array<string, mixed>
 */
function electricchic_available_now_block( $query, $block ): array {
	$query = (array) $query;

	if ( ! $block instanceof \WP_Block ) {
		return $query;
	}

	$class = $block->parsed_block['attrs']['className'] ?? '';

	if ( ! is_string( $class ) || ! str_contains( $class, ELECTRICCHIC_AVAILABLE_NOW ) ) {
		return $query;
	}

	return electricchic_available_now( $query );
}
add_filter( 'query_loop_block_query_vars', 'electricchic_available_now_block', 20, 2 );

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

	/*
	 * `sizes` has to be rewritten along with `loading`, because the value
	 * WordPress emits stops being valid the moment this filter runs.
	 *
	 * WordPress writes sizes="auto, (max-width: 796px) 100vw, 796px". The
	 * `auto` keyword is defined ONLY for lazily-loaded images, so setting
	 * loading="eager" makes the browser discard the whole attribute and assume
	 * 100vw.
	 *
	 * WHAT THAT ACTUALLY COSTS — measured against the real candidate list
	 * rather than estimated, after a first attempt got this wrong:
	 *
	 *   1440px @2x   no change      (both pick the largest candidate)
	 *   1440px @1x   72KB -> 52KB
	 *    390px @2x   54KB -> 52KB
	 *
	 * So this is a correctness fix worth about a quarter of the image on a
	 * non-retina desktop, and nothing at all on a retina one. The weight of
	 * these images was solved by converting them to WebP, not here — see
	 * scripts/webp-product-images.php. Saying otherwise in this comment would
	 * send the next person to tune the wrong thing.
	 *
	 * A caution for whoever measures this next: with a `w`-descriptor srcset,
	 * `naturalWidth` is reported AFTER density correction, so it returns the
	 * `sizes` value and not the file's pixel width. Comparing it against
	 * width x devicePixelRatio produces a number that looks like waste and is
	 * an artefact. Read the selected candidate's descriptor instead.
	 *
	 * The value below describes the hero's real upper bound: capped at 48vw
	 * beside the copy, roughly 78vw once the layout stacks. It tracks
	 * .ec-hero__stage in design-system.css and has to move with it.
	 */
	$attr['sizes'] = '(max-width: 860px) 78vw, 48vw';

	// wp_get_attachment_image() adds decoding="async" by default, which tells
	// the browser it may paint the page without waiting. For the LCP image
	// that is the wrong instruction.
	unset( $attr['decoding'] );

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'electricchic_eager_first_product_image' );

/**
 * The five departments, in the order a customer should meet them.
 *
 * Slugs only. Names, descriptions and counts all come from the taxonomy, so
 * renaming a category in the admin renames it on the homepage — this list
 * decides sequence and nothing else.
 *
 * @return string[]
 */
function electricchic_departments(): array {
	return array( 'regular-bikes', 'e-bikes', 'scooters', 'accessories', 'parts' );
}

/**
 * Render the shop's five departments.
 *
 * WHY THIS IS A BLOCK AND NOT FIVE HAND-WRITTEN CARDS
 *
 * Three of the five have no stock yet — the catalogue is 24 Cortez electric
 * machines and nothing else — and the site says so on the card rather than
 * offering a link to an empty page.
 *
 * Written by hand, "empty" would be a sentence somebody typed, and it would go
 * on saying it after the first box of helmets arrives. Derived from the count,
 * the card opens itself the moment the owner adds a product from the admin,
 * with nobody touching a template. That is the same rule the rest of this
 * project runs on: the shop records facts, and what the customer is told is
 * computed from them.
 *
 * KNOWN LIMITATION
 *
 * Registered in PHP with a render callback and no editor script, so the Site
 * Editor shows it as an unsupported block. It renders correctly on the front
 * end. An editor script needs a build step, and npm is unreachable on this
 * machine — see the blocked items in CLAUDE.md.
 *
 * @return string
 */
function electricchic_render_category_nav(): string {
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return '';
	}

	$stocked = '';
	$soon    = array();

	foreach ( electricchic_departments() as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );

		if ( ! $term instanceof WP_Term ) {
			// A department that does not exist is a deployment that has not
			// run seed-categories.php. Skipping is quieter than a fatal and
			// leaves the rest of the page usable.
			continue;
		}

		$count = (int) $term->count;
		$name  = sprintf( '<span class="ec-dept__name">%s</span>', esc_html( $term->name ) );
		$blurb = '' !== $term->description
			? sprintf( '<span class="ec-dept__blurb">%s</span>', esc_html( $term->description ) )
			: '';

		if ( $count < 1 ) {
			$soon[] = esc_html( $term->name );
			continue;
		}

		/* translators: %d: number of products in this department. */
		$phrase = _n( '%d דגם', '%d דגמים', $count, 'electricchic' );

		$stocked .= sprintf(
			'<a class="ec-dept" href="%s">%s%s<span class="ec-dept__count">%s</span><span class="ec-dept__go" aria-hidden="true">←</span></a>',
			esc_url( (string) get_term_link( $term ) ),
			$name,
			$blurb,
			esc_html( sprintf( $phrase, $count ) )
		);
	}

	if ( '' === $stocked && array() === $soon ) {
		return '';
	}

	$out = '';

	if ( '' !== $stocked ) {
		$out .= sprintf(
			'<nav class="ec-depts" aria-label="%s">%s</nav>',
			esc_attr__( 'מחלקות החנות', 'electricchic' ),
			$stocked
		);
	}

	/*
	 * The empty departments used to sit in the same row, at the same size, as
	 * the ones that have stock. Measured on the rendered page, three of five
	 * cards were not links: 60% of the width of the shop's primary way in was
	 * dead. Honest, and unusable.
	 *
	 * One line instead. It still says exactly what it said before — these
	 * departments exist and have no stock yet — and it stops them taking the
	 * space of the two a customer can actually act on. Nothing is hidden; the
	 * emphasis just matches what is true.
	 */
	if ( array() !== $soon ) {
		$out .= sprintf(
			'<p class="ec-depts__soon"><span class="ec-depts__soon-label">בקרוב</span>%s<span class="ec-depts__soon-note">טרם התקבל מלאי</span></p>',
			implode( '<span class="ec-depts__sep" aria-hidden="true">·</span>', $soon )
		);
	}

	return $out;
}

/**
 * Register the departments block.
 */
function electricchic_register_blocks(): void {
	register_block_type(
		'electricchic/category-nav',
		array(
			'api_version'     => '3',
			'title'           => __( 'מחלקות החנות', 'electricchic' ),
			'category'        => 'woocommerce',
			'render_callback' => 'electricchic_render_category_nav',
			'supports'        => array( 'html' => false ),
		)
	);
}
add_action( 'init', 'electricchic_register_blocks' );

/*
 * Removed: add_filter( 'should_load_separate_core_block_assets', '__return_true' ).
 *
 * It is the textbook fix for a block theme loading one big core stylesheet,
 * and it was measured here rather than assumed: 314KB of CSS across 13 files
 * with it, and 314KB across 13 files without it. It works — only one core
 * block file survives, the rest are inlined — and it changes nothing, because
 * almost none of the weight is core's.
 *
 * The homepage's CSS is WooCommerce's:
 *
 *   woocommerce-rtl.css          86KB
 *   packages-style-rtl.css       47KB
 *   mini-cart-contents-rtl.css   43KB
 *   woocommerce-layout-rtl.css   20KB
 *   ...and seven more
 *
 * woocommerce-rtl.css looks like dead weight on a block theme and is not: the
 * single-product template renders the gallery and the details table through
 * WooCommerce's classic markup, and this theme's rules for
 * `.woocommerce div.product` and `table.shop_attributes` build on top of it.
 * Dropping it takes the product page's layout with it, on a different template
 * from the one where the change was made.
 *
 * The mini-cart drawer is a further 55KB. That is a real feature and it stays,
 * but it is the first thing to weigh if this budget ever starts to matter.
 *
 * Left as a note instead of a filter, because a line of configuration that
 * provably does nothing is worse than no line: the next person reads it as
 * "performance is handled here" and stops looking.
 */

/**
 * The arriving half of the product morph, and the render block that makes it
 * possible.
 *
 * WHY THIS IS INLINE IN <head> AND NOT IN ec-motion.js
 *
 * A cross-document view transition names the element that should fly on the
 * page being LEFT (ec-motion.js does that in `pageswap`, which fires long after
 * a deferred script has loaded) and on the page being ARRIVED AT. The arriving
 * side is announced by `pagereveal`, and that event fires at the new document's
 * first rendering opportunity — before a deferred script has attached
 * anything. Registered from ec-motion.js the listener was never present when
 * the event fired; measured on a real click, the morph completed on zero
 * navigations. It has to be in the head, inline, before any render.
 *
 * WHY THE PRODUCT PAGE RENDER-BLOCKS ON ITS OWN TOP BLOCK
 *
 * `pagereveal` can fire with the body only partly parsed. If the gallery image
 * is not in the DOM yet, there is nothing to name and the product flies into
 * nothing. `<link rel="expect" blocking="render">` holds the first render until
 * the element with that id has been fully parsed; the id is on the columns
 * block that holds the gallery, so by the time the page is revealed the image
 * exists. It costs nothing: that block is at the top of the page and is HTML,
 * not images.
 *
 * If a product is ever rendered through a per-product Site Editor template
 * that lacks the id, the browser waits for the parser to finish instead —
 * bounded by the page length, never indefinite, and the morph simply does
 * not run on that page.
 *
 * Only the product page is blocked. On the way BACK the list page is restored
 * from the back-forward cache with its DOM complete, so the card exists at
 * reveal time without any help. A fresh load of a list page may reveal before
 * the clicked card is parsed and then simply crossfades, which is the correct
 * fallback and is not worth render-blocking a 24-product grid for.
 *
 * The name and storage key here MUST match ec-motion.js.
 */
function electricchic_print_morph_landing(): void {
	if ( is_product() ) {
		echo '<link rel="expect" href="#ec-pdp" blocking="render">' . "\n";
	}

	$script = <<<'JS'
(function () {
	if (!('onpagereveal' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
	var NAME = 'ec-product', KEY = 'ec:morph';
	window.addEventListener('pagereveal', function (event) {
		var path = null;
		try { path = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY); } catch (e) {}
		// Recorded so a missing morph can be diagnosed from the DOM: did the
		// event fire, and did the browser actually start a transition?
		document.documentElement.setAttribute('data-ec-reveal', event.viewTransition ? 'transition' : 'plain');
		if (!event.viewTransition) { return; }
		var target = document.querySelector('.woocommerce-product-gallery__image img');
		if (!target && path) {
			var links = document.querySelectorAll('.wc-block-components-product-image > a');
			for (var i = 0; i < links.length; i++) {
				if (links[i].pathname === path) { target = links[i].querySelector('img'); break; }
			}
		}
		if (!target) { return; }
		target.style.viewTransitionName = NAME;
		document.documentElement.setAttribute('data-ec-morph', 'running');
		event.viewTransition.finished.then(function () {
			target.style.viewTransitionName = '';
			document.documentElement.setAttribute('data-ec-morph', 'done');
		});
	});
})();
JS;

	wp_print_inline_script_tag( $script, array( 'id' => 'electricchic-morph-landing' ) );
}
add_action( 'wp_head', 'electricchic_print_morph_landing', 1 );
