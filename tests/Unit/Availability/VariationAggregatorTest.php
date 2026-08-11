<?php
/**
 * Tests for rolling variation states up to a parent product.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Availability;

use ElectricChic\Core\Availability\AvailabilityState;
use ElectricChic\Core\Availability\VariationAggregator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One badge for a product that exists in several sizes, colours or batteries.
 *
 * A parent shows the BEST state among its variations, because that is what the
 * catalogue card is promising: "you can get one of these". Which specific one is
 * a question the product page answers.
 *
 * Note that "best" is not the resolver's order. The resolver runs blocking flags
 * FIRST — discontinued beats everything, because a discontinued product cannot
 * be sold. Here discontinued ranks LAST, because one discontinued colour must
 * not hide a product whose other colours are on the shelf. Two orderings, two
 * different questions; conflating them either hides sellable stock or advertises
 * a product nobody can buy.
 */
#[CoversClass( VariationAggregator::class )]
final class VariationAggregatorTest extends TestCase {

	private VariationAggregator $aggregator;

	protected function setUp(): void {
		$this->aggregator = new VariationAggregator();
	}

	public function test_one_variation_in_store_makes_the_parent_in_store(): void {
		$this->assertSame(
			AvailabilityState::IN_STOCK_STORE,
			$this->aggregator->aggregate(
				array(
					AvailabilityState::OUT_OF_STOCK,
					AvailabilityState::IN_STOCK_STORE,
					AvailabilityState::DISCONTINUED,
				)
			)
		);
	}

	/**
	 * The case that would quietly cost sales.
	 *
	 * A bike discontinued in black but on the shelf in white is a bike the shop
	 * can sell today. Letting the blocking flag win here would delist it.
	 */
	public function test_a_discontinued_variation_does_not_hide_available_ones(): void {
		$state = $this->aggregator->aggregate(
			array( AvailabilityState::DISCONTINUED, AvailabilityState::SUPPLIER_AVAILABLE )
		);

		$this->assertSame( AvailabilityState::SUPPLIER_AVAILABLE, $state );
		$this->assertTrue( $state->is_purchasable() );
	}

	/**
	 * The converse: nothing purchasable must not be dressed up as purchasable.
	 */
	public function test_all_unavailable_children_yield_an_unavailable_parent(): void {
		$state = $this->aggregator->aggregate(
			array(
				AvailabilityState::OUT_OF_STOCK,
				AvailabilityState::TEMP_OUT_OF_STOCK,
				AvailabilityState::DISCONTINUED,
			)
		);

		$this->assertFalse( $state->is_purchasable() );
	}

	public function test_every_variation_discontinued_makes_the_parent_discontinued(): void {
		$this->assertSame(
			AvailabilityState::DISCONTINUED,
			$this->aggregator->aggregate(
				array( AvailabilityState::DISCONTINUED, AvailabilityState::DISCONTINUED )
			)
		);
	}

	/**
	 * A variable product with no variations cannot be bought.
	 *
	 * This happens in practice — someone sets the product type before adding the
	 * variations. Defaulting to anything purchasable would put a working
	 * add-to-cart button on a product with nothing behind it.
	 */
	public function test_a_product_with_no_variations_is_out_of_stock(): void {
		$state = $this->aggregator->aggregate( array() );

		$this->assertSame( AvailabilityState::OUT_OF_STOCK, $state );
		$this->assertFalse( $state->is_purchasable() );
	}

	public function test_a_single_variation_passes_straight_through(): void {
		$this->assertSame(
			AvailabilityState::CONFIRMATION_REQUIRED,
			$this->aggregator->aggregate( array( AvailabilityState::CONFIRMATION_REQUIRED ) )
		);
	}

	// ── The ranking itself ───────────────────────────────────────────────────

	/**
	 * Assert the preference between every pair, not a sample.
	 *
	 * @param AvailabilityState $better The state that should win.
	 * @param AvailabilityState $worse  The state it should beat.
	 */
	#[DataProvider( 'ordered_pairs' )]
	public function test_the_better_state_wins_regardless_of_input_order(
		AvailabilityState $better,
		AvailabilityState $worse
	): void {
		$this->assertSame( $better, $this->aggregator->aggregate( array( $better, $worse ) ) );
		$this->assertSame( $better, $this->aggregator->aggregate( array( $worse, $better ) ) );
	}

	/**
	 * Best to worst. Purchasable states first — a customer can complete a
	 * purchase — then the states where a conversation might still lead to one,
	 * then the dead ends.
	 *
	 * @return array<string, array{AvailabilityState, AvailabilityState}>
	 */
	public static function ordered_pairs(): array {
		$ranked = array(
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

		$pairs = array();
		$count = count( $ranked );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$pairs[ $ranked[ $i ]->name . ' beats ' . $ranked[ $j ]->name ] = array( $ranked[ $i ], $ranked[ $j ] );
			}
		}

		return $pairs;
	}

	/**
	 * The ranking must cover the enum exhaustively.
	 *
	 * A tenth state added without a rank would sort arbitrarily, which is the
	 * sort of bug that shows up as one product behaving oddly and takes a day
	 * to trace.
	 */
	public function test_every_state_is_ranked(): void {
		foreach ( AvailabilityState::cases() as $state ) {
			$this->assertSame(
				$state,
				$this->aggregator->aggregate( array( $state ) ),
				$state->name . ' is missing from the preference order.'
			);
		}
	}
}
