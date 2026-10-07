<?php
/**
 * Shows the availability badge to customers.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Integration;

use ElectricChic\Core\Availability\AvailabilityLabels;
use ElectricChic\Core\Availability\BadgeLedger;
use ElectricChic\Core\Availability\AvailabilityState;
use WC_Product;

/**
 * Renders the state as a badge, and stops WooCommerce contradicting it.
 *
 * WooCommerce prints its own stock line — "במלאי" — from the same _stock number
 * for every product. Left in place it sits directly beneath our badge saying
 * something different about the same item, which is worse than having no badge
 * at all: the customer cannot tell which one to believe. So the badge replaces
 * that line rather than joining it.
 *
 * Presentation would normally belong in the child theme. It lives here because
 * it is the enforcement of a business rule — the customer must never be shown a
 * claim the model does not support — and because the guard and the badge have
 * to agree. Splitting them across two repositories is how they drift apart.
 */
final class AvailabilityDisplay {

	/**
	 * Blocks the catalogue badge may attach itself to.
	 *
	 * More than one on purpose. The badge used to hang off the price block
	 * alone, which quietly coupled the single most important thing on the card
	 * to a design decision: any template that moved the price — or dropped it,
	 * as a minimal product card reasonably might — would have removed every
	 * availability badge on the site without raising a single error.
	 *
	 * That is the exact failure this project exists to prevent, arriving
	 * through the back door of a redesign. Listing both anchors means a
	 * template can arrange the card however it likes and still get the badge;
	 * claim() below keeps it to one per card.
	 *
	 * @var string[]
	 */
	private const CARD_ANCHORS = array(
		'woocommerce/product-price',
		'woocommerce/product-image',
	);

	/**
	 * Blocks that occupy the place where the buy button would be.
	 *
	 * Both names are listed because WooCommerce is mid-migration between the
	 * classic form block and its block-native replacement, and a template may
	 * legitimately use either.
	 *
	 * @var string[]
	 */
	private const BUY_BLOCKS = array(
		'woocommerce/add-to-cart-form',
		'woocommerce/add-to-cart-with-options',
	);

	/**
	 * Build the display over the availability model.
	 *
	 * @param ProductStockFactsReader $reader Resolves a product to a state.
	 * @param AvailabilityLabels      $labels Hebrew wording and CSS classes.
	 */
	public function __construct(
		private readonly ProductStockFactsReader $reader = new ProductStockFactsReader(),
		private readonly AvailabilityLabels $labels = new AvailabilityLabels(),
	) {
		$this->ledger = new BadgeLedger();
	}

	/**
	 * Decides which rendering opportunity draws the badge.
	 *
	 * A product card offers several — the block catalogue can attach to the
	 * image or the price, and classic templates fire a loop action as well.
	 * All of them are registered on purpose, because which one is available
	 * depends on the template and a missing badge is worse than a repeated
	 * one. The ledger keeps it to one per CARD.
	 *
	 * Per card, not per product: a homepage may feature a machine at the top
	 * and list it again in a grid below, and both are cards that must state
	 * their availability. See BadgeLedger for how the boundary is detected.
	 *
	 * @var BadgeLedger
	 */
	private BadgeLedger $ledger;

	/**
	 * Products whose state has actually been stated on this page.
	 *
	 * Recorded rather than inferred. The product page normally carries its
	 * state inside the add-to-cart form, but the form is only rendered for a
	 * product that can be bought — and the products that CANNOT be bought are
	 * exactly the ones a customer most needs an explanation for.
	 *
	 * @var array<int, true>
	 */
	private array $stated = array();

	/**
	 * Attach to WooCommerce.
	 *
	 * @return void
	 */
	public function register(): void {
		// Replace WooCommerce's stock line on the product page.
		add_filter( 'woocommerce_get_stock_html', array( $this, 'filter_stock_html' ), 10, 2 );

		// Badge under the title on catalogue cards (classic templates).
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_badge' ), 15 );

		// The same, for the Product Collection block this site actually uses.
		add_filter( 'render_block', array( $this, 'append_badge_to_product_block' ), 10, 2 );
	}

	/**
	 * Replace WooCommerce's stock line with the derived badge.
	 *
	 * @param string     $html    WooCommerce's markup.
	 * @param WC_Product $product The product.
	 * @return string
	 */
	public function filter_stock_html( string $html, WC_Product $product ): string {
		unset( $html );

		$state = $this->reader->state_for( $product );

		$this->stated[ $product->get_id() ] = true;

		return $this->badge_markup( $state, $product, true );
	}

	/**
	 * Badge beneath a catalogue card title, classic template path.
	 *
	 * @return void
	 */
	public function render_loop_badge(): void {
		global $product;

		if ( ! $product instanceof WC_Product || ! $this->claim( $product, 'woocommerce_after_shop_loop_item_title' ) ) {
			return;
		}

		echo wp_kses_post( $this->badge_markup( $this->reader->state_for( $product ), $product, false ) );
	}

	/**
	 * Badge inside a Product Collection block card.
	 *
	 * The classic hook above does fire inside a Product Collection block, but
	 * only where WooCommerce renders that compatibility layer. This covers the
	 * block path directly so a card is never left without a badge.
	 *
	 * Fires for any block in CARD_ANCHORS, so a custom template is free to
	 * arrange the card as it likes. claim() guarantees one badge per card
	 * however many of those blocks are present, and a product that appears on
	 * the page more than once gets a badge on each of its cards.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string
	 */
	public function append_badge_to_product_block( string $content, array $block ): string {
		$name = (string) ( $block['blockName'] ?? '' );

		if ( in_array( $name, self::BUY_BLOCKS, true ) ) {
			return $this->state_availability_in_place_of_buying( $content );
		}

		if ( ! in_array( $name, self::CARD_ANCHORS, true ) ) {
			return $content;
		}

		global $product;

		if ( ! $product instanceof WC_Product || ! $this->claim( $product, $name ) ) {
			return $content;
		}

		return $content . $this->badge_markup( $this->reader->state_for( $product ), $product, false );
	}

	/**
	 * Take the right to render this product's catalogue badge on this card.
	 *
	 * Scoped to the two loop paths only. The product page renders through
	 * filter_stock_html(), which is a different question — that one replaces
	 * WooCommerce's own stock line and must always answer.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $anchor  Which rendering opportunity is asking.
	 * @return bool True if the caller should render.
	 */
	private function claim( WC_Product $product, string $anchor ): bool {
		/*
		 * On a product's own page the catalogue badge is redundant: the stock
		 * line inside the add-to-cart form already carries the same state AND
		 * the explanatory notice, which is the part that matters most there —
		 * it is the sentence telling a customer that a special order has not
		 * been confirmed with the supplier yet. Rendering both showed the badge
		 * twice, once above the price and once below it.
		 *
		 * Suppressed here rather than by unregistering the loop hooks, because
		 * the same page also lists related products and those still need badges.
		 */
		if ( is_product() && get_queried_object_id() === $product->get_id() ) {
			return false;
		}

		return $this->ledger->claim( $product->get_id(), $anchor );
	}

	/**
	 * Say why a product cannot be bought, where the buy button would have been.
	 *
	 * A product page normally states its availability inside the add-to-cart
	 * form: WooCommerce prints a stock line there and filter_stock_html()
	 * above replaces it with the derived badge and its explanatory notice.
	 *
	 * But PurchasabilityGuard removes that form for anything the model says
	 * cannot be bought, and the form takes the stock line with it. The result
	 * was a discontinued machine rendering a title, a price, a full technical
	 * specification — and no button and no sentence anywhere on the page
	 * saying why. Found by counting availability statements on the rendered
	 * page for CORTEZ 10X: three badges, all of them belonging to the related
	 * products at the bottom.

	 * Silence is the worst of the available answers here. It looks like an
	 * oversight, it invites a phone call the shop has to answer by hand, and
	 * on a site whose entire premise is that it tells you what it has, it is
	 * the one place a customer will remember.
	 *
	 * Only fills a genuine gap: if the form did render, filter_stock_html()
	 * has already recorded the state and this adds nothing.
	 *
	 * @param string $content Rendered block HTML, empty when suppressed.
	 * @return string
	 */
	private function state_availability_in_place_of_buying( string $content ): string {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return $content;
		}

		if ( isset( $this->stated[ $product->get_id() ] ) ) {
			return $content;
		}

		$this->stated[ $product->get_id() ] = true;

		/*
		 * Wrapped rather than appended bare. WordPress stamps the block's
		 * identity onto the first tag its render produces, so with the form
		 * suppressed the badge itself came back carrying
		 * data-block-name="woocommerce/add-to-cart-form" — a paragraph
		 * announcing itself as the add-to-cart form. The wrapper takes that
		 * attribute instead, which is accurate: this element genuinely is what
		 * stands where the form would have been.
		 */
		return $content . sprintf(
			'<div class="ec-avail-instead">%s</div>',
			$this->badge_markup( $this->reader->state_for( $product ), $product, true )
		);
	}

	/**
	 * Build the badge.
	 *
	 * The state name is carried in a data attribute as well as a class, so that
	 * automated checks can assert what the page claims without parsing Hebrew.
	 *
	 * @param AvailabilityState $state       Resolved state.
	 * @param WC_Product        $product     The product.
	 * @param bool              $with_notice Whether to include the explanatory line.
	 * @return string
	 */
	private function badge_markup( AvailabilityState $state, WC_Product $product, bool $with_notice ): string {
		$facts = $this->reader->facts_for( $product );

		$markup = sprintf(
			'<p class="ec-avail %1$s" data-ec-state="%2$s"><span class="ec-avail__dot" aria-hidden="true"></span>%3$s</p>',
			esc_attr( $this->labels->css_class( $state ) ),
			esc_attr( $state->key() ),
			esc_html( $this->labels->for_state( $state, $facts ) )
		);

		if ( ! $with_notice ) {
			return $markup;
		}

		$notice = $this->labels->notice( $state );

		if ( null !== $notice ) {
			$markup .= sprintf( '<p class="ec-avail__notice">%s</p>', esc_html( $notice ) );
		}

		return $markup;
	}
}
