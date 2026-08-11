<?php
/**
 * Shows the availability badge to customers.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Integration;

use ElectricChic\Core\Availability\AvailabilityLabels;
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
	 * Build the display over the availability model.
	 *
	 * @param ProductStockFactsReader $reader Resolves a product to a state.
	 * @param AvailabilityLabels      $labels Hebrew wording and CSS classes.
	 */
	public function __construct(
		private readonly ProductStockFactsReader $reader = new ProductStockFactsReader(),
		private readonly AvailabilityLabels $labels = new AvailabilityLabels(),
	) {}

	/**
	 * Product IDs that have already had a catalogue badge this request.
	 *
	 * Two loop paths are registered below and BOTH fire inside a Product
	 * Collection block — verified by rendering the shop page and counting: 16
	 * products produced 32 badges, one after the title and one after the price.
	 * Registering only one path is not an option either, because which of them
	 * fires depends on the template, and a missing availability badge is a
	 * worse failure than a duplicated one.
	 *
	 * So both stay registered for coverage and the first to fire wins.
	 *
	 * @var array<int, true>
	 */
	private array $rendered = array();

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

		// Expose the state as a body/product class so CSS can react to it.
		add_filter( 'woocommerce_post_class', array( $this, 'filter_product_classes' ), 10, 2 );
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

		return $this->badge_markup( $state, $product, true );
	}

	/**
	 * Badge beneath a catalogue card title, classic template path.
	 *
	 * @return void
	 */
	public function render_loop_badge(): void {
		global $product;

		if ( ! $product instanceof WC_Product || ! $this->claim( $product ) ) {
			return;
		}

		echo wp_kses_post( $this->badge_markup( $this->reader->state_for( $product ), $product, false ) );
	}

	/**
	 * Badge inside a Product Collection block card.
	 *
	 * The classic hook above does fire inside a Product Collection block, but
	 * only where WooCommerce renders that compatibility layer. This covers the
	 * block path directly so a card is never left without a badge; claim()
	 * stops the two from both rendering on the same product.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string
	 */
	public function append_badge_to_product_block( string $content, array $block ): string {
		if ( 'woocommerce/product-price' !== ( $block['blockName'] ?? '' ) ) {
			return $content;
		}

		global $product;

		if ( ! $product instanceof WC_Product || ! $this->claim( $product ) ) {
			return $content;
		}

		return $content . $this->badge_markup( $this->reader->state_for( $product ), $product, false );
	}

	/**
	 * Take the right to render this product's catalogue badge, once.
	 *
	 * Scoped to the two loop paths only. The product page renders through
	 * filter_stock_html(), which is a different question — that one replaces
	 * WooCommerce's own stock line and must always answer.
	 *
	 * @param WC_Product $product The product.
	 * @return bool True if the caller should render.
	 */
	private function claim( WC_Product $product ): bool {
		$id = $product->get_id();

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
		if ( is_product() && get_queried_object_id() === $id ) {
			return false;
		}

		if ( isset( $this->rendered[ $id ] ) ) {
			return false;
		}

		$this->rendered[ $id ] = true;

		return true;
	}

	/**
	 * Add the state as a class on the product wrapper.
	 *
	 * @param string[]   $classes Existing classes.
	 * @param WC_Product $product The product.
	 * @return string[]
	 */
	public function filter_product_classes( array $classes, WC_Product $product ): array {
		$classes[] = $this->labels->css_class( $this->reader->state_for( $product ) );

		return $classes;
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
