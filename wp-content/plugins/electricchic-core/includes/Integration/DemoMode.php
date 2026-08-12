<?php
/**
 * Marks a demo site as a demo, and stops it taking real orders.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Integration;

/**
 * Makes a demo installation say so, everywhere, and refuse to take money.
 *
 * This site is going to be forwarded. Eli shows it to Cortez, Cortez shows it
 * to somebody else, and by the third forward nobody remembers it was a preview.
 * At that point the real shop name, the real address and the real phone number
 * are sitting next to invented products and prices nobody has confirmed.
 *
 * DEFAULTS TO ON. A site is a demo until someone declares otherwise:
 *
 *     define( 'EC_DEMO_MODE', false );   // in wp-config.php, on production only
 *
 * That direction is deliberate. Forgetting the flag on a demo means unconfirmed
 * prices are presented to an investor as real, and nobody finds out until it
 * matters. Forgetting it on production means the live shop wears a demo banner
 * — embarrassing, obvious within minutes, and fixed by one line. Between a
 * silent failure and a loud one, take the loud one.
 */
final class DemoMode {

	/**
	 * Whether this installation is a demo.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'EC_DEMO_MODE' ) ? (bool) constant( 'EC_DEMO_MODE' ) : true;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_action( 'wp_body_open', array( $this, 'render_banner' ) );
		add_action( 'wp_head', array( $this, 'render_banner_styles' ) );

		// Refuse the order itself, on both the classic and block checkout.
		add_action( 'woocommerce_checkout_process', array( $this, 'block_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'block_store_api_checkout' ) );

		// Keep a demo out of search results whatever the site settings say.
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
	}

	/**
	 * The banner itself.
	 *
	 * Rendered server-side and never dismissible. A dismiss button would mean the
	 * one person who most needs to see it — whoever the link was forwarded to —
	 * arrives at a page that looks exactly like a live shop.
	 *
	 * @return void
	 */
	public function render_banner(): void {
		/*
		 * The warning icon is applied in CSS, not concatenated into the string.
		 * Inline in an RTL sentence the emoji's variation selector broke apart
		 * on screen — the triangle rendered at one end and a stray "!" at the
		 * other. Keeping the icon out of the translatable text also means a
		 * translator cannot accidentally drop or reorder it.
		 */
		printf(
			'<div class="ec-demo-banner" role="note"><span class="ec-demo-banner__text">%s</span></div>',
			esc_html__(
				'אתר הדגמה — מחירים ומלאי אינם סופיים ואינם מחייבים. לא ניתן לבצע הזמנות.',
				'electricchic'
			)
		);
	}

	/**
	 * Inline the banner's styles.
	 *
	 * Deliberately not in the theme stylesheet. A demo warning that disappears
	 * because a stylesheet failed to load, or because someone switched themes,
	 * is not a warning. This is the one piece of presentation in the project
	 * that must not depend on anything.
	 *
	 * @return void
	 */
	public function render_banner_styles(): void {
		echo '<style id="ec-demo-banner-css">
			.ec-demo-banner{position:sticky;top:0;z-index:99999;
			display:flex;align-items:center;justify-content:center;gap:8px;
			background:#fbbf24;color:#1a1200;
			font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
			font-size:14px;font-weight:700;line-height:1.4;
			text-align:center;padding:10px 16px;
			border-block-end:2px solid #1a1200;}
			.ec-demo-banner::before{content:"\\26A0";
			direction:ltr;unicode-bidi:isolate;
			font-size:16px;flex:none;}
			@media print{.ec-demo-banner{position:static;}}
		</style>';
	}

	/**
	 * Refuse to place an order on the classic checkout.
	 *
	 * The banner claims orders cannot be placed. Without this it would be a
	 * claim rather than a control — and a stray real order against unconfirmed
	 * prices is a genuine problem, not a cosmetic one.
	 *
	 * @return void
	 */
	public function block_checkout(): void {
		wc_add_notice(
			esc_html__( 'זהו אתר הדגמה ולא ניתן לבצע בו הזמנות. לרכישה אמיתית צרו קשר עם החנות.', 'electricchic' ),
			'error'
		);
	}

	/**
	 * The same, for the Store API checkout used by the block cart.
	 *
	 * @return void
	 * @throws \Exception Rejected and shown to the customer.
	 */
	public function block_store_api_checkout(): void {
		throw new \Exception(
			esc_html__( 'זהו אתר הדגמה ולא ניתן לבצע בו הזמנות. לרכישה אמיתית צרו קשר עם החנות.', 'electricchic' )
		);
	}

	/**
	 * Keep the demo out of search engines.
	 *
	 * A demo carrying the real shop name and address must not be indexable. If
	 * it outranks — or merely competes with — the real listing later, that is a
	 * commercial problem that takes months to undo.
	 *
	 * @param array<string, mixed> $robots Existing directives.
	 * @return array<string, mixed>
	 */
	public function filter_robots( array $robots ): array {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}
}
