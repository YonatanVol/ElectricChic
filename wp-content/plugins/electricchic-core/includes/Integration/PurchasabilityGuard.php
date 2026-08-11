<?php
/**
 * Stops non-purchasable products from being bought.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Integration;

use ElectricChic\Core\Availability\AvailabilityLabels;
use Exception;
use WC_Product;

/**
 * Enforces the resolver's verdict against WooCommerce.
 *
 * The resolver decides whether a product can be sold. This makes WooCommerce
 * agree — everywhere, not just where a customer would normally click.
 *
 * Three layers, because one is not enough:
 *
 * 1. woocommerce_is_purchasable removes the add-to-cart button.
 * 2. woocommerce_add_to_cart_validation rejects a direct ?add-to-cart= URL,
 *    which bypasses the button entirely.
 * 3. woocommerce_store_api_validate_add_to_cart rejects the REST path used by
 *    the Cart and Checkout blocks.
 *
 * Layer 1 alone is presentation. Anyone who has ever kept an old add-to-cart
 * URL in a bookmark, or any script hitting the Store API, walks straight past
 * it — and the failure is silent, because the order looks completely normal
 * until someone tries to fulfil it.
 *
 * The final layer is checkout: a state can change between adding to a cart and
 * paying, and a cart that sat open overnight must not be allowed to complete a
 * purchase the shop can no longer honour.
 */
final class PurchasabilityGuard {

	/**
	 * Build a guard over the availability model.
	 *
	 * @param ProductStockFactsReader $reader Resolves a product to a state.
	 * @param AvailabilityLabels      $labels Supplies the customer-facing reason.
	 */
	public function __construct(
		private readonly ProductStockFactsReader $reader = new ProductStockFactsReader(),
		private readonly AvailabilityLabels $labels = new AvailabilityLabels(),
	) {}

	/**
	 * Re-entrancy latch for the stock-status filter.
	 *
	 * Resolving a state reads the product, and reading the product asks
	 * WooCommerce whether it is in stock — which is the very filter below. Left
	 * unguarded that recurses until PHP runs out of stack. The latch lets the
	 * inner read see WooCommerce's own untouched answer, which is exactly what
	 * ProductStockFactsReader wants anyway: the raw fact, not our conclusion.
	 *
	 * @var bool
	 */
	private bool $resolving = false;

	/**
	 * Attach to WooCommerce.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'filter_is_in_stock' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_in_stock', array( $this, 'filter_is_in_stock' ), 10, 2 );
		add_filter( 'woocommerce_product_backorders_allowed', array( $this, 'filter_backorders_allowed' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'validate_store_api_add_to_cart' ), 10, 1 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'revalidate_cart' ) );
	}

	/**
	 * Remove the add-to-cart button where the model says no.
	 *
	 * @param bool       $purchasable What WooCommerce concluded.
	 * @param WC_Product $product     The product.
	 * @return bool
	 */
	public function filter_is_purchasable( bool $purchasable, WC_Product $product ): bool {
		// Only ever tightens. A product WooCommerce already refuses to sell —
		// no price, draft status — stays refused; this never argues it back.
		if ( ! $purchasable ) {
			return false;
		}

		return $this->reader->state_for( $product )->is_purchasable();
	}

	/**
	 * Make WooCommerce's idea of "in stock" agree with the model.
	 *
	 * Without this the two disagree in both directions, and both are wrong on a
	 * real page — verified by rendering the shop:
	 *
	 *   supplier_available  model: sellable   WooCommerce: outofstock  no button
	 *   special_order       model: sellable   WooCommerce: outofstock  no button
	 *   discontinued        model: blocked    WooCommerce: instock     button!
	 *   enquiry_only        model: blocked    WooCommerce: instock     button!
	 *
	 * The guard's other layers meant none of those buttons could complete a
	 * purchase, so nothing was ever oversold. But a customer offered a button
	 * that errors, or refused a bike the shop is perfectly willing to order, is
	 * a customer lost — and the catalogue block decides what to draw from
	 * is_in_stock(), not from is_purchasable().
	 *
	 * Note what this does NOT do: it does not write to _stock, and it does not
	 * change any quantity. It answers a question. Supplier availability still
	 * cannot inflate the number of units WooCommerce believes it can sell, so
	 * the property that makes overselling structurally impossible is intact.
	 *
	 * @param bool       $in_stock WooCommerce's own conclusion.
	 * @param WC_Product $product  The product.
	 * @return bool
	 */
	public function filter_is_in_stock( bool $in_stock, WC_Product $product ): bool {
		if ( $this->resolving ) {
			return $in_stock;
		}

		$this->resolving = true;

		try {
			return $this->reader->state_for( $product )->is_purchasable();
		} finally {
			$this->resolving = false;
		}
	}

	/**
	 * Let WooCommerce accept an order for a unit the shop does not hold.
	 *
	 * Saying "in stock" was not enough on its own: has_enough_stock() still
	 * refused, because the quantity is zero and backorders were off. Proven by
	 * actually adding each product to a cart — supplier_available and
	 * special_order were both rejected while the badge invited the customer to
	 * order them. Reading the filter list would not have caught that.
	 *
	 * Backorders are WooCommerce's own name for exactly this situation: an
	 * order accepted for something not currently on the shelf. Using the native
	 * concept means the cart, stock notes and order emails all behave normally
	 * instead of needing a parallel mechanism.
	 *
	 * Applies only where the model says purchasable AND the shop is not holding
	 * one, so it can never loosen a product that is genuinely in the shop.
	 * Again: no quantity is written anywhere.
	 *
	 * @param bool       $allowed    WooCommerce's own setting.
	 * @param int        $product_id Product ID.
	 * @param WC_Product $product    The product.
	 * @return bool
	 */
	public function filter_backorders_allowed( bool $allowed, int $product_id, $product ): bool {
		unset( $product_id );

		if ( $this->resolving || ! $product instanceof WC_Product ) {
			return $allowed;
		}

		$this->resolving = true;

		try {
			$state = $this->reader->state_for( $product );
		} finally {
			$this->resolving = false;
		}

		if ( $state->is_purchasable() && ! $state->is_held_in_store() ) {
			return true;
		}

		return $allowed;
	}

	/**
	 * Reject a direct ?add-to-cart= request.
	 *
	 * @param bool $passed     Whether validation has passed so far.
	 * @param int  $product_id The product being added.
	 * @param int  $quantity   Requested quantity.
	 * @return bool
	 */
	public function validate_add_to_cart( bool $passed, int $product_id, int $quantity ): bool {
		unset( $quantity );

		if ( ! $passed ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $passed;
		}

		$state = $this->reader->state_for( $product );

		if ( $state->is_purchasable() ) {
			return true;
		}

		wc_add_notice(
			sprintf(
				/* translators: 1: product name, 2: availability label. */
				esc_html__( '%1$s is not available for purchase at the moment (%2$s).', 'electricchic' ),
				esc_html( $product->get_name() ),
				esc_html( $this->labels->for_state( $state, $this->reader->facts_for( $product ) ) )
			),
			'error'
		);

		return false;
	}

	/**
	 * Reject the same request arriving through the Store API.
	 *
	 * The Cart and Checkout blocks never touch the filter above. Guarding only
	 * the classic form would leave a REST endpoint that happily sells a
	 * discontinued bike, which is a hole rather than a gap.
	 *
	 * @param WC_Product $product The product being added.
	 * @return void
	 * @throws Exception Rejected by the Store API and shown to the customer.
	 */
	public function validate_store_api_add_to_cart( WC_Product $product ): void {
		$state = $this->reader->state_for( $product );

		if ( $state->is_purchasable() ) {
			return;
		}

		throw new Exception(
			esc_html(
				sprintf(
					/* translators: 1: product name, 2: availability label. */
					__( '%1$s is not available for purchase at the moment (%2$s).', 'electricchic' ),
					$product->get_name(),
					$this->labels->for_state( $state, $this->reader->facts_for( $product ) )
				)
			)
		);
	}

	/**
	 * Re-check the cart before checkout.
	 *
	 * Availability is derived from facts that change — a supplier report goes
	 * stale, the last unit sells, a model is discontinued. A cart left open
	 * overnight must not be allowed to complete a purchase the shop can no
	 * longer honour, so the verdict is taken again rather than trusted from
	 * whenever the item was added.
	 *
	 * @return void
	 */
	public function revalidate_cart(): void {
		$cart = WC()->cart;

		if ( null === $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$state = $this->reader->state_for( $product );

			if ( $state->is_purchasable() ) {
				continue;
			}

			$cart->remove_cart_item( $key );

			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: availability label. */
					esc_html__( '%1$s was removed from your basket because it is no longer available (%2$s).', 'electricchic' ),
					esc_html( $product->get_name() ),
					esc_html( $this->labels->for_state( $state, $this->reader->facts_for( $product ) ) )
				),
				'error'
			);
		}
	}
}
