<?php
/**
 * The nine customer-facing availability states.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

/*
 * PHPCompatibility 9.3.5 was released in December 2019 — two years before PHP
 * 8.1 introduced enums. It has no concept of one, so it reads every method
 * below as a plain function and reports `$this` as a PHP 7.1 fatal. It is not:
 * an enum method is an object context like any other.
 *
 * Scoped to the one sniff, on the one file that trips it, rather than relaxed
 * globally — a real 7.1 violation elsewhere must still fail the build. Remove
 * this when PHPCompatibility ships an enum-aware release.
 *
 * phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
 */

/**
 * What a customer is told about getting hold of a product.
 *
 * These are DERIVED, never typed. Nobody picks "available from supplier" from a
 * dropdown — the shop owner records facts and AvailabilityResolver computes the
 * state. A field somebody has to remember to change is a field that will be
 * wrong, and being wrong here means promising something the shop cannot deliver.
 *
 * The case order is the resolution order: first match wins. Reordering these
 * changes behaviour, which is why AvailabilityResolverTest asserts every
 * overlapping pair rather than spot-checking.
 */
enum AvailabilityState {

	/** Manufacture ended. The page stays live; the product cannot be bought. */
	case DISCONTINUED;

	/** Price or fit needs a conversation before a sale makes sense. */
	case ENQUIRY_ONLY;

	/** The shop will not take money until a supplier confirms. */
	case CONFIRMATION_REQUIRED;

	/** Physically on the shelf. Collectable today. */
	case IN_STOCK_STORE;

	/** Not here, but the importer reports stock and the report is recent. */
	case SUPPLIER_AVAILABLE;

	/** Orderable, but nobody can vouch for current supplier stock. */
	case SPECIAL_ORDER;

	/** Orderable ahead of restocking, with no supplier information. */
	case BACKORDER;

	/** The supplier has explicitly reported none. Expected back. */
	case TEMP_OUT_OF_STOCK;

	/** No stock, no supplier, no route to getting one. */
	case OUT_OF_STOCK;

	/**
	 * Whether a customer may add this to the cart.
	 *
	 * PurchasabilityGuard enforces this against WooCommerce, including the Store
	 * API — blocking only the classic add-to-cart form would leave the REST path
	 * open, which is a hole rather than a gap.
	 */
	public function is_purchasable(): bool {
		return match ( $this ) {
			self::IN_STOCK_STORE,
			self::SUPPLIER_AVAILABLE,
			self::SPECIAL_ORDER,
			self::BACKORDER => true,

			self::DISCONTINUED,
			self::ENQUIRY_ONLY,
			self::CONFIRMATION_REQUIRED,
			self::TEMP_OUT_OF_STOCK,
			self::OUT_OF_STOCK => false,
		};
	}

	/**
	 * Whether the customer should be shown a delivery estimate.
	 *
	 * Only the states where the shop is actually promising a timescale. Showing
	 * "5–10 business days" next to "out of stock" is the kind of detail that
	 * makes a customer distrust everything else on the page.
	 */
	public function shows_lead_time(): bool {
		return match ( $this ) {
			self::SUPPLIER_AVAILABLE, self::SPECIAL_ORDER, self::BACKORDER => true,
			default => false,
		};
	}

	/**
	 * Whether the shop holds this item right now.
	 *
	 * Distinct from purchasability: a special order is purchasable but not here.
	 * The homepage "available now" section uses this, and it is the difference
	 * between a physical shop and a catalogue.
	 */
	public function is_held_in_store(): bool {
		return self::IN_STOCK_STORE === $this;
	}

	/**
	 * Stable key for CSS classes, meta storage and analytics.
	 */
	public function key(): string {
		return strtolower( $this->name );
	}
}
