<?php
/**
 * Rolls variation states up into one state for the parent product.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

/**
 * One badge for a product that exists in several sizes, colours or batteries.
 *
 * A parent shows the BEST state among its variations, because that is what a
 * catalogue card promises: "you can get one of these". Which specific one is a
 * question the product page answers.
 *
 * The ordering here is NOT the resolver's. The resolver runs blocking flags
 * first — discontinued beats everything, because a discontinued product cannot
 * be sold. Here discontinued ranks last, because one discontinued colour must
 * not hide a bike that is on the shelf in white. Two orderings answering two
 * different questions; conflating them either hides sellable stock or
 * advertises a product nobody can buy.
 */
final class VariationAggregator {

	/**
	 * Preference order, best first.
	 *
	 * Purchasable states lead — a customer can complete a purchase today. Then
	 * the states where a conversation might still produce a sale. Then the dead
	 * ends, with discontinued last: it is the only one that will never improve.
	 *
	 * @return list<AvailabilityState>
	 */
	private function ranking(): array {
		return array(
			AvailabilityState::IN_STOCK_STORE,
			AvailabilityState::SUPPLIER_AVAILABLE,
			AvailabilityState::SPECIAL_ORDER,
			AvailabilityState::BACKORDER,
			AvailabilityState::CONFIRMATION_REQUIRED,
			AvailabilityState::ENQUIRY_ONLY,
			AvailabilityState::TEMP_OUT_OF_STOCK,
			AvailabilityState::OUT_OF_STOCK,
			AvailabilityState::DISCONTINUED,
		);
	}

	/**
	 * Reduce child states to the one the parent should display.
	 *
	 * @param AvailabilityState[] $children Resolved states, one per variation.
	 * @return AvailabilityState
	 */
	public function aggregate( array $children ): AvailabilityState {
		/*
		 * A variable product with no variations cannot be bought. This happens
		 * in practice — someone sets the product type before adding variations —
		 * and defaulting to anything purchasable would put a working add-to-cart
		 * button on a product with nothing behind it.
		 */
		if ( array() === $children ) {
			return AvailabilityState::OUT_OF_STOCK;
		}

		$best      = null;
		$best_rank = PHP_INT_MAX;

		foreach ( $children as $child ) {
			$rank = $this->rank_of( $child );

			if ( $rank < $best_rank ) {
				$best_rank = $rank;
				$best      = $child;
			}
		}

		return $best ?? AvailabilityState::OUT_OF_STOCK;
	}

	/**
	 * Position of a state in the preference order.
	 *
	 * An unranked state sorts last rather than arbitrarily, so a tenth case
	 * added to the enum degrades to "worst" instead of silently winning. The
	 * test suite asserts every case is ranked, so this is a floor, not a
	 * substitute for keeping the list complete.
	 *
	 * @param AvailabilityState $state The state to rank.
	 * @return int
	 */
	private function rank_of( AvailabilityState $state ): int {
		$position = array_search( $state, $this->ranking(), true );

		return false === $position ? PHP_INT_MAX - 1 : $position;
	}
}
