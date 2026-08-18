<?php
/**
 * Tests for the quantity ceiling.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Availability;

use ElectricChic\Core\Availability\AvailabilityState;
use ElectricChic\Core\Availability\PurchaseLimit;
use ElectricChic\Core\Availability\StockFacts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How many units a customer may actually buy.
 *
 * This class exists because the project's central claim was false. "Supplier
 * stock can never oversell" was verified by checking that _stock is never
 * written — which is true, and beside the point. Selling a supplier-backed
 * product goes through WooCommerce's backorder mechanism, and backorders are
 * unbounded. Cortez reported six units; the cart accepted fifty.
 *
 * Never writing the quantity and never capping it are not the same guarantee.
 * The first was implemented; only the second prevents an oversell.
 */
#[CoversClass( PurchaseLimit::class )]
final class PurchaseLimitTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 */
	private function facts( array $overrides = array() ): StockFacts {
		return StockFacts::from_array(
			array_merge(
				array(
					'store_stock'    => 0,
					'supplier_stock' => null,
					'has_supplier'   => false,
				),
				$overrides
			)
		);
	}

	/**
	 * The regression. Six reported, six sellable.
	 */
	public function test_supplier_availability_is_capped_at_what_the_supplier_reported(): void {
		$limit = PurchaseLimit::for_state(
			AvailabilityState::SUPPLIER_AVAILABLE,
			$this->facts( array( 'has_supplier' => true, 'supplier_stock' => 6 ) )
		);

		$this->assertSame( 6, $limit, 'A supplier figure of 6 must not permit a seventh unit.' );
	}

	public function test_store_stock_caps_at_what_is_on_the_shelf(): void {
		$this->assertSame(
			3,
			PurchaseLimit::for_state( AvailabilityState::IN_STOCK_STORE, $this->facts( array( 'store_stock' => 3 ) ) )
		);
	}

	/**
	 * Nobody has confirmed a special order exists at all.
	 *
	 * Unlimited would be the same bug in a different state: the shop would be
	 * accepting money for forty units of something whose availability is
	 * explicitly unverified. A small default is the conservative reading, and
	 * it is a business decision Eli should confirm.
	 */
	public function test_an_unconfirmed_special_order_is_capped_conservatively(): void {
		$limit = PurchaseLimit::for_state(
			AvailabilityState::SPECIAL_ORDER,
			$this->facts( array( 'has_supplier' => true ) )
		);

		$this->assertIsInt( $limit );
		$this->assertGreaterThan( 0, $limit );
		$this->assertLessThanOrEqual( PurchaseLimit::UNCONFIRMED_CAP, $limit );
	}

	/**
	 * Backorder is the one state where the shop has explicitly chosen to sell
	 * ahead of stock it does not have. That is a decision, not an accident.
	 */
	public function test_backorder_is_the_only_unlimited_state(): void {
		$this->assertNull(
			PurchaseLimit::for_state( AvailabilityState::BACKORDER, $this->facts() )
		);
	}

	/**
	 * Nothing unpurchasable may be bought in any quantity, including one.
	 *
	 * @param AvailabilityState $state A blocked state.
	 */
	#[DataProvider( 'blocked_states' )]
	public function test_blocked_states_permit_nothing( AvailabilityState $state ): void {
		$this->assertSame( 0, PurchaseLimit::for_state( $state, $this->facts( array( 'store_stock' => 99 ) ) ) );
	}

	/**
	 * @return array<string, array{AvailabilityState}>
	 */
	public static function blocked_states(): array {
		$cases = array();

		foreach ( AvailabilityState::cases() as $state ) {
			if ( ! $state->is_purchasable() ) {
				$cases[ $state->name ] = array( $state );
			}
		}

		return $cases;
	}

	/**
	 * A supplier reporting zero cannot be bought even one of.
	 */
	public function test_a_zero_supplier_report_permits_nothing(): void {
		$this->assertSame(
			0,
			PurchaseLimit::for_state(
				AvailabilityState::SUPPLIER_AVAILABLE,
				$this->facts( array( 'has_supplier' => true, 'supplier_stock' => 0 ) )
			)
		);
	}

	/**
	 * Every state must yield an answer, so a tenth case cannot default to
	 * unlimited by omission — which is precisely how this bug happened.
	 */
	public function test_every_state_has_an_explicit_limit(): void {
		foreach ( AvailabilityState::cases() as $state ) {
			$limit = PurchaseLimit::for_state( $state, $this->facts( array( 'store_stock' => 5 ) ) );

			$this->assertTrue(
				null === $limit || is_int( $limit ),
				$state->name . ' has no defined purchase limit.'
			);

			if ( null === $limit ) {
				$this->assertSame(
					AvailabilityState::BACKORDER,
					$state,
					$state->name . ' is unlimited; only BACKORDER may be.'
				);
			}
		}
	}
}
