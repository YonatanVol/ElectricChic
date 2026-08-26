<?php
/**
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Availability;

use ElectricChic\Core\Availability\BadgeLedger;
use PHPUnit\Framework\TestCase;

/**
 * One badge per card, however many places offer to draw it.
 *
 * The sequences below are not invented. They were recorded from the real
 * rendered homepage by logging every claim opportunity in firing order, which
 * is what exposed the bug this class fixes.
 */
final class BadgeLedgerTest extends TestCase {

	private const IMAGE = 'woocommerce/product-image';
	private const PRICE = 'woocommerce/product-price';
	private const LOOP  = 'woocommerce_after_shop_loop_item_title';

	public function test_the_first_opportunity_on_a_card_draws_the_badge(): void {
		$ledger = new BadgeLedger();

		$this->assertTrue( $ledger->claim( 117, self::IMAGE ) );
	}

	public function test_a_second_anchor_in_the_same_card_does_not_draw_again(): void {
		$ledger = new BadgeLedger();
		$ledger->claim( 117, self::IMAGE );

		$this->assertFalse(
			$ledger->claim( 117, self::PRICE ),
			'A card offering both an image and a price must still show one badge.'
		);
	}

	public function test_three_anchors_in_one_card_still_draw_exactly_one(): void {
		$ledger = new BadgeLedger();

		$drawn = array_filter(
			array(
				$ledger->claim( 117, self::LOOP ),
				$ledger->claim( 117, self::IMAGE ),
				$ledger->claim( 117, self::PRICE ),
			)
		);

		$this->assertCount( 1, $drawn );
	}

	/**
	 * The regression. A homepage that features a product at the top and lists
	 * it again below rendered seven cards and six badges: the featured one
	 * claimed the badge for the whole request and the grid card fell silent.
	 */
	public function test_the_same_product_twice_on_a_page_draws_twice(): void {
		$ledger = new BadgeLedger();

		// The hero: an image and nothing else.
		$this->assertTrue( $ledger->claim( 117, self::IMAGE ) );

		// Other cards in between.
		$ledger->claim( 108, self::IMAGE );
		$ledger->claim( 108, self::PRICE );

		// The same product again, further down the page.
		$this->assertTrue(
			$ledger->claim( 117, self::IMAGE ),
			'A second card for the same product must carry its own badge.'
		);
		$this->assertFalse( $ledger->claim( 117, self::PRICE ) );
	}

	public function test_a_card_may_open_on_any_anchor(): void {
		$ledger = new BadgeLedger();

		// A minimal card that drops the image entirely.
		$this->assertTrue( $ledger->claim( 900, self::PRICE ) );

		// And the same product again, in a card that has an image first.
		$this->assertTrue( $ledger->claim( 900, self::PRICE ) );
	}

	public function test_products_do_not_interfere_with_each_other(): void {
		$ledger = new BadgeLedger();

		$this->assertTrue( $ledger->claim( 1, self::IMAGE ) );
		$this->assertTrue( $ledger->claim( 2, self::IMAGE ) );
		$this->assertFalse( $ledger->claim( 1, self::PRICE ) );
		$this->assertFalse( $ledger->claim( 2, self::PRICE ) );
	}

	/**
	 * The exact firing order recorded from the rendered homepage: seven cards
	 * across a featured hero, three bikes and three scooters, with the hero
	 * product repeating in the grid.
	 */
	public function test_the_recorded_homepage_sequence_draws_one_badge_per_card(): void {
		$recorded = array(
			array( 117, self::IMAGE ),
			array( 108, self::IMAGE ), array( 108, self::PRICE ),
			array( 105, self::IMAGE ), array( 105, self::PRICE ),
			array( 109, self::IMAGE ), array( 109, self::PRICE ),
			array( 117, self::IMAGE ), array( 117, self::PRICE ),
			array( 112, self::IMAGE ), array( 112, self::PRICE ),
			array( 114, self::IMAGE ), array( 114, self::PRICE ),
		);

		$ledger = new BadgeLedger();
		$badges = 0;

		foreach ( $recorded as $claim ) {
			if ( $ledger->claim( $claim[0], $claim[1] ) ) {
				++$badges;
			}
		}

		$this->assertSame( 7, $badges, 'Seven cards must produce seven badges.' );
	}

	public function test_reset_forgets_everything(): void {
		$ledger = new BadgeLedger();
		$ledger->claim( 117, self::IMAGE );
		$ledger->reset();

		$this->assertTrue( $ledger->claim( 117, self::IMAGE ) );
	}
}
