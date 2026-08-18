<?php
/**
 * Derives a customer-facing availability state from recorded facts.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

use DateTimeImmutable;

/**
 * The core of the shop.
 *
 * WooCommerce has one word — "in stock" — for three different situations: goods
 * on the shelf, goods an importer holds, and goods that only get ordered once a
 * customer commits. Using one word for all three eventually means promising
 * something that cannot be delivered, and for a shop selling ₪7,000 scooters
 * that is not a cosmetic problem.
 *
 * Two properties are deliberate and load-bearing:
 *
 * PURE. Facts in, state out. No WordPress, no database, no clock of its own —
 * the current time is injected so staleness can be tested at its boundary. That
 * is why the whole model is covered in microseconds.
 *
 * RETURNS A STATE, NEVER A QUANTITY. Nothing here can influence what WooCommerce
 * believes is sellable. Supplier numbers change the promise and the label; they
 * never change stock. A wrong supplier feed therefore produces a wrong estimate,
 * never an oversell — which is the difference between an embarrassing bug and
 * one that costs money and a customer.
 */
final class AvailabilityResolver {

	/**
	 * How long a supplier stock figure stays trustworthy.
	 *
	 * Past this, SUPPLIER_AVAILABLE degrades to SPECIAL_ORDER: still orderable,
	 * but no longer presented as though someone has checked. This is the rule
	 * that encodes "supplier availability is never guaranteed stock" into the
	 * system rather than into somebody's memory.
	 */
	public const DEFAULT_FRESHNESS_DAYS = 7;

	/**
	 * Build a resolver with a supplier-freshness window.
	 *
	 * @param int $freshness_days Days a supplier figure remains trustworthy.
	 */
	public function __construct(
		private readonly int $freshness_days = self::DEFAULT_FRESHNESS_DAYS,
	) {}

	/**
	 * Resolve facts into the single state a customer should see.
	 *
	 * Evaluated in order; the first match wins. The order is the priority order,
	 * and it is not arbitrary — a product that is both "needs confirmation" and
	 * "supplier has stock" must resolve to confirmation, or the shop takes money
	 * for something nobody has verified. AvailabilityResolverTest asserts every
	 * overlapping pair rather than trusting the reading of this method.
	 *
	 * @param StockFacts             $facts What the shop owner recorded.
	 * @param DateTimeImmutable|null $now   Injected for testability; defaults to now.
	 * @return AvailabilityState
	 */
	public function resolve( StockFacts $facts, ?DateTimeImmutable $now = null ): AvailabilityState {
		$now ??= new DateTimeImmutable();

		// 1. Nothing else matters once a product is gone for good.
		if ( $facts->discontinued ) {
			return AvailabilityState::DISCONTINUED;
		}

		// 2. Sold by conversation, so never by cart — even with stock present.
		if ( $facts->enquiry_only ) {
			return AvailabilityState::ENQUIRY_ONLY;
		}

		/*
		 * 3. Confirmation only bites when the shelf is empty: if the item is
		 * here, there is nothing to confirm with anybody.
		 */
		if ( $facts->requires_confirmation && ! $facts->has_store_stock() ) {
			return AvailabilityState::CONFIRMATION_REQUIRED;
		}

		// 4. The shelf is the strongest claim the shop can make.
		if ( $facts->has_store_stock() ) {
			return AvailabilityState::IN_STOCK_STORE;
		}

		// 5–6. Supplier paths.
		if ( $facts->has_supplier ) {
			if ( ! $facts->has_supplier_report() ) {
				// Never reported. Orderable, but no one is claiming to know.
				return AvailabilityState::SPECIAL_ORDER;
			}

			if ( $facts->supplier_stock > 0 ) {
				return $this->is_fresh( $facts->supplier_updated_at, $now )
					? AvailabilityState::SUPPLIER_AVAILABLE
					: AvailabilityState::SPECIAL_ORDER;
			}

			// Supplier reported zero. Better information than a bare backorder,
			// unless the shop has explicitly chosen to sell past zero.
			if ( $facts->backorders_allowed ) {
				return AvailabilityState::BACKORDER;
			}

			return AvailabilityState::TEMP_OUT_OF_STOCK;
		}

		// 7. No supplier, but the shop will sell ahead of restocking.
		if ( $facts->backorders_allowed ) {
			return AvailabilityState::BACKORDER;
		}

		// 9. Nothing here, nobody to order from.
		return AvailabilityState::OUT_OF_STOCK;
	}

	/**
	 * Whether a supplier figure is recent enough to present as availability.
	 *
	 * A figure with no timestamp is treated as stale. Trusting an undated number
	 * is exactly the failure this guards against, and defaulting to optimism
	 * here would put the risk on the customer.
	 *
	 * @param DateTimeImmutable|null $updated_at When the figure was recorded.
	 * @param DateTimeImmutable      $now        Current time.
	 * @return bool
	 */
	private function is_fresh( ?DateTimeImmutable $updated_at, DateTimeImmutable $now ): bool {
		if ( null === $updated_at ) {
			return false;
		}

		$cutoff = $now->modify( sprintf( '-%d days', $this->freshness_days ) );

		// Inclusive: a figure exactly at the window is still trusted. One second
		// past is not. The boundary is asserted both ways in the test suite.
		return $updated_at >= $cutoff;
	}
}
