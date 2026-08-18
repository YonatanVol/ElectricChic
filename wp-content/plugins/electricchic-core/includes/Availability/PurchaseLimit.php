<?php
/**
 * How many units a customer may actually buy.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

/**
 * The quantity ceiling, derived from the same facts as the state.
 *
 * WHY THIS EXISTS
 *
 * The project claimed overselling was structurally impossible, and it was not.
 * The claim rested on one true fact — supplier figures are never written to
 * WooCommerce's _stock — and on a false inference from it. Selling something the
 * shop does not hold goes through WooCommerce's backorder mechanism, and
 * backorders are deliberately unbounded. Cortez reported six units; the cart
 * accepted fifty.
 *
 * Never writing the quantity and never exceeding it are different guarantees.
 * Only the second prevents an oversell, and only this class provides it.
 *
 * Returns null for "no ceiling", an int for a hard maximum, and 0 for "cannot
 * be bought at all". Every state is answered explicitly rather than falling
 * through to a default, because an unlisted state defaulting to unlimited is
 * exactly how the original bug happened.
 */
final class PurchaseLimit {

	/**
	 * Units allowed for a product nobody has confirmed the existence of.
	 *
	 * A special order means the supplier has not been asked, or answered too
	 * long ago to trust. Unlimited would be the same bug wearing a different
	 * label: the shop taking money for forty units of something unverified. A
	 * small number keeps the order plausible enough for a human to chase.
	 *
	 * This is a business decision and belongs to the shop owner, not here.
	 */
	public const UNCONFIRMED_CAP = 2;

	/**
	 * The maximum a customer may add for a given state.
	 *
	 * @param AvailabilityState $state The resolved state.
	 * @param StockFacts        $facts The recorded facts.
	 * @return int|null Hard maximum, 0 for none at all, or null for no ceiling.
	 */
	public static function for_state( AvailabilityState $state, StockFacts $facts ): ?int {
		if ( ! $state->is_purchasable() ) {
			return 0;
		}

		return match ( $state ) {
			// The shelf is the ceiling, and WooCommerce enforces it too.
			AvailabilityState::IN_STOCK_STORE => max( 0, $facts->store_stock ),

			// Never promise more than the importer said they hold.
			AvailabilityState::SUPPLIER_AVAILABLE => max( 0, (int) $facts->supplier_stock ),

			// Nobody has confirmed anything. Keep it small and human-sized.
			AvailabilityState::SPECIAL_ORDER => self::UNCONFIRMED_CAP,

			/*
			 * The only unlimited state, and deliberately so: a backorder is the
			 * shop explicitly choosing to sell ahead of stock it does not have.
			 * That is a decision someone made, not a gap in the data.
			 */
			AvailabilityState::BACKORDER => null,

			// Unreachable — is_purchasable() covered these above. Listed so a
			// new case is a compile-time problem rather than a silent unlimited.
			AvailabilityState::DISCONTINUED,
			AvailabilityState::ENQUIRY_ONLY,
			AvailabilityState::CONFIRMATION_REQUIRED,
			AvailabilityState::TEMP_OUT_OF_STOCK,
			AvailabilityState::OUT_OF_STOCK => 0,
		};
	}
}
