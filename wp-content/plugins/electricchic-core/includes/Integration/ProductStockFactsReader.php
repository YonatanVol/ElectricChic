<?php
/**
 * Reads a WooCommerce product into the pure model's input.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Integration;

use DateTimeImmutable;
use ElectricChic\Core\Availability\AvailabilityResolver;
use ElectricChic\Core\Availability\AvailabilityState;
use ElectricChic\Core\Availability\StockFacts;
use ElectricChic\Core\Availability\VariationAggregator;
use Exception;
use WC_Product;
use WC_Product_Variable;

/**
 * The seam between WordPress and the availability model.
 *
 * This is the ONLY class that reads product meta. Everything downstream takes a
 * StockFacts and never touches a database, which is what keeps the model
 * testable in microseconds. Widen this boundary and that property goes with it.
 *
 * Note what is read and what is not: store stock and backorder policy come from
 * WooCommerce, because WooCommerce owns them and is the thing that decrements
 * them on a sale. Supplier information is ours. The two are never mixed — and
 * in particular nothing here ever writes to _stock. Supplier numbers change the
 * promise; they cannot change what WooCommerce believes is sellable, so a wrong
 * supplier file cannot produce an oversell.
 */
final class ProductStockFactsReader {

	public const META_SUPPLIER_ID      = '_ec_supplier_id';
	public const META_SUPPLIER_STOCK   = '_ec_supplier_stock';
	public const META_SUPPLIER_UPDATED = '_ec_supplier_updated_at';
	public const META_LEAD_TIME_MIN    = '_ec_lead_time_min_days';
	public const META_LEAD_TIME_MAX    = '_ec_lead_time_max_days';
	public const META_REQUIRES_CONFIRM = '_ec_requires_confirmation';
	public const META_ENQUIRY_ONLY     = '_ec_enquiry_only';
	public const META_DISCONTINUED     = '_ec_discontinued';

	/**
	 * Build a reader over the pure model.
	 *
	 * @param AvailabilityResolver $resolver   Pure resolver.
	 * @param VariationAggregator  $aggregator Rolls variations up to a parent.
	 */
	public function __construct(
		private readonly AvailabilityResolver $resolver = new AvailabilityResolver(),
		private readonly VariationAggregator $aggregator = new VariationAggregator(),
	) {}

	/**
	 * Resolve the state a product should display.
	 *
	 * @param WC_Product $product The product.
	 * @return AvailabilityState
	 */
	public function state_for( WC_Product $product ): AvailabilityState {
		if ( $product instanceof WC_Product_Variable ) {
			return $this->aggregator->aggregate( $this->child_states( $product ) );
		}

		return $this->resolver->resolve( $this->facts_for( $product ) );
	}

	/**
	 * Resolve every purchasable variation of a variable product.
	 *
	 * @param WC_Product_Variable $product The parent.
	 * @return AvailabilityState[]
	 */
	private function child_states( WC_Product_Variable $product ): array {
		$states = array();

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( $child instanceof WC_Product ) {
				$states[] = $this->resolver->resolve( $this->facts_for( $child ) );
			}
		}

		return $states;
	}

	/**
	 * Read the facts recorded against one product.
	 *
	 * Variations inherit any supplier field they do not define themselves. In
	 * practice a shop records lead time and supplier once on the parent and
	 * overrides it only where a particular colour genuinely differs; without
	 * inheritance every variation would need the same values typed again, and
	 * fields that must be retyped are fields that drift.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return StockFacts
	 */
	public function facts_for( WC_Product $product ): StockFacts {
		$parent_id = $product->get_parent_id();

		$supplier_id = $this->inherited( $product, $parent_id, self::META_SUPPLIER_ID );
		$raw_stock   = $this->inherited( $product, $parent_id, self::META_SUPPLIER_STOCK );

		/*
		 * store_stock is WooCommerce's own number, read and never written.
		 * supplier_stock is ours. The two stay separate: a supplier figure
		 * changes the promise the page makes, and can never change the quantity
		 * WooCommerce is willing to sell.
		 */
		return StockFacts::from_array(
			array(
				'store_stock'           => $this->store_stock( $product ),
				'supplier_stock'        => '' === $raw_stock ? null : (int) $raw_stock,
				'has_supplier'          => '' !== $supplier_id,
				'supplier_updated_at'   => $this->supplier_timestamp( $product, $parent_id ),
				'lead_time_min_days'    => (int) $this->inherited( $product, $parent_id, self::META_LEAD_TIME_MIN ),
				'lead_time_max_days'    => (int) $this->inherited( $product, $parent_id, self::META_LEAD_TIME_MAX ),
				'requires_confirmation' => 'yes' === $this->inherited( $product, $parent_id, self::META_REQUIRES_CONFIRM ),
				'enquiry_only'          => 'yes' === $this->inherited( $product, $parent_id, self::META_ENQUIRY_ONLY ),
				'discontinued'          => 'yes' === $this->inherited( $product, $parent_id, self::META_DISCONTINUED ),
				'backorders_allowed'    => $product->backorders_allowed(),
			)
		);
	}

	/**
	 * Units physically in the shop.
	 *
	 * @param WC_Product $product The product.
	 * @return int
	 */
	private function store_stock( WC_Product $product ): int {
		if ( $product->managing_stock() ) {
			return (int) $product->get_stock_quantity();
		}

		// Not counting units. Fall back to WooCommerce's coarse answer.
		return $product->is_in_stock() ? 1 : 0;
	}

	/**
	 * When the supplier figure was last refreshed.
	 *
	 * An unparseable timestamp is treated as absent, which the resolver in turn
	 * treats as stale. Guessing optimistically here would put the risk of a
	 * malformed supplier file onto the customer.
	 *
	 * @param WC_Product $product   The product.
	 * @param int        $parent_id Parent to inherit from, or 0.
	 * @return DateTimeImmutable|null
	 */
	private function supplier_timestamp( WC_Product $product, int $parent_id ): ?DateTimeImmutable {
		$raw = $this->inherited( $product, $parent_id, self::META_SUPPLIER_UPDATED );

		if ( '' === $raw ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $raw );
		} catch ( Exception ) {
			return null;
		}
	}

	/**
	 * A product's own meta value, falling back to its parent's.
	 *
	 * @param WC_Product $product   The product or variation.
	 * @param int        $parent_id Parent ID, or 0 for a top-level product.
	 * @param string     $key       Meta key.
	 * @return string
	 */
	private function inherited( WC_Product $product, int $parent_id, string $key ): string {
		$own = $product->get_meta( $key, true );

		if ( '' !== $own && null !== $own ) {
			return (string) $own;
		}

		if ( $parent_id > 0 ) {
			$parent = wc_get_product( $parent_id );

			if ( $parent instanceof WC_Product ) {
				return (string) $parent->get_meta( $key, true );
			}
		}

		return '';
	}
}
