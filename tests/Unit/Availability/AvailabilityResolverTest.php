<?php
/**
 * Tests for the availability resolver.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Availability;

use ElectricChic\Core\Availability\AvailabilityResolver;
use ElectricChic\Core\Availability\AvailabilityState;
use ElectricChic\Core\Availability\StockFacts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The resolver turns facts the shop owner records into a customer-facing state.
 *
 * This is the piece the whole project exists for. WooCommerce has one word —
 * "in stock" — for three different situations: goods on the shelf, goods an
 * importer holds, and goods that only get ordered once a customer commits.
 * Using one word for all three eventually means promising something the shop
 * cannot deliver.
 *
 * Two properties matter more than any individual state:
 *
 * 1. It is PURE. Facts in, state out, no WordPress. That is why every case
 *    below runs in microseconds and why the logic can be trusted without a
 *    database.
 *
 * 2. It returns a STATE, never a quantity. Nothing here can influence what
 *    WooCommerce believes is sellable. A wrong supplier feed can therefore
 *    produce a wrong promise — never a wrong stock count, and never an oversell.
 */
#[CoversClass( AvailabilityResolver::class )]
final class AvailabilityResolverTest extends TestCase {

	private AvailabilityResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new AvailabilityResolver();
	}

	/**
	 * Build facts with everything defaulted to the ordinary case.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return StockFacts
	 */
	private function facts( array $overrides = array() ): StockFacts {
		return StockFacts::from_array(
			array_merge(
				array(
					'store_stock'           => 0,
					'supplier_stock'        => null,
					'has_supplier'          => false,
					'supplier_updated_at'   => null,
					'lead_time_min_days'    => 5,
					'lead_time_max_days'    => 10,
					'requires_confirmation' => false,
					'enquiry_only'          => false,
					'discontinued'          => false,
					'backorders_allowed'    => false,
				),
				$overrides
			)
		);
	}

	// ── The two poles ────────────────────────────────────────────────────────

	public function test_stock_on_the_shelf_is_in_store(): void {
		$this->assertSame(
			AvailabilityState::IN_STOCK_STORE,
			$this->resolver->resolve( $this->facts( array( 'store_stock' => 3 ) ) )
		);
	}

	public function test_no_stock_and_no_supplier_is_out_of_stock(): void {
		$this->assertSame(
			AvailabilityState::OUT_OF_STOCK,
			$this->resolver->resolve( $this->facts() )
		);
	}

	// ── Blocking flags, and their precedence over stock ──────────────────────

	public function test_discontinued_wins_even_with_stock_on_the_shelf(): void {
		$this->assertSame(
			AvailabilityState::DISCONTINUED,
			$this->resolver->resolve( $this->facts( array( 'discontinued' => true, 'store_stock' => 99 ) ) )
		);
	}

	public function test_enquiry_only_wins_even_with_stock_on_the_shelf(): void {
		$this->assertSame(
			AvailabilityState::ENQUIRY_ONLY,
			$this->resolver->resolve( $this->facts( array( 'enquiry_only' => true, 'store_stock' => 99 ) ) )
		);
	}

	/**
	 * Confirmation only bites when the shelf is empty.
	 *
	 * If the item is physically here, there is nothing to confirm with anyone —
	 * the customer can walk in and take it.
	 */
	public function test_confirmation_required_applies_only_when_the_shelf_is_empty(): void {
		$this->assertSame(
			AvailabilityState::IN_STOCK_STORE,
			$this->resolver->resolve( $this->facts( array( 'requires_confirmation' => true, 'store_stock' => 2 ) ) )
		);

		$this->assertSame(
			AvailabilityState::CONFIRMATION_REQUIRED,
			$this->resolver->resolve( $this->facts( array( 'requires_confirmation' => true, 'store_stock' => 0 ) ) )
		);
	}

	/**
	 * The case that would cost money if precedence were wrong.
	 *
	 * A product needing confirmation, where the supplier also reports stock,
	 * must NOT become purchasable on the strength of the supplier number.
	 */
	public function test_confirmation_beats_supplier_stock(): void {
		$state = $this->resolver->resolve(
			$this->facts(
				array(
					'requires_confirmation' => true,
					'store_stock'           => 0,
					'has_supplier'          => true,
					'supplier_stock'        => 25,
					'supplier_updated_at'   => '2026-08-10 09:00:00',
				)
			)
		);

		$this->assertSame( AvailabilityState::CONFIRMATION_REQUIRED, $state );
		$this->assertFalse( $state->is_purchasable(), 'A product awaiting confirmation must never be purchasable.' );
	}

	// ── Supplier paths ───────────────────────────────────────────────────────

	public function test_fresh_supplier_stock_is_orderable(): void {
		$this->assertSame(
			AvailabilityState::SUPPLIER_AVAILABLE,
			$this->resolver->resolve(
				$this->facts(
					array(
						'has_supplier'        => true,
						'supplier_stock'      => 4,
						'supplier_updated_at' => '2026-08-10 09:00:00',
					)
				),
				new \DateTimeImmutable( '2026-08-12 09:00:00' )
			)
		);
	}

	/**
	 * Unknown is not the same as zero.
	 *
	 * A supplier who has never reported a number is a special order. A supplier
	 * who reported zero is temporarily out. Collapsing the two either promises
	 * stock that may not exist or hides a product that could be ordered.
	 */
	public function test_unknown_supplier_stock_is_a_special_order(): void {
		$this->assertSame(
			AvailabilityState::SPECIAL_ORDER,
			$this->resolver->resolve( $this->facts( array( 'has_supplier' => true, 'supplier_stock' => null ) ) )
		);
	}

	public function test_supplier_reporting_zero_is_temporarily_out(): void {
		$this->assertSame(
			AvailabilityState::TEMP_OUT_OF_STOCK,
			$this->resolver->resolve(
				$this->facts(
					array(
						'has_supplier'        => true,
						'supplier_stock'      => 0,
						'supplier_updated_at' => '2026-08-10 09:00:00',
					)
				),
				new \DateTimeImmutable( '2026-08-12 09:00:00' )
			)
		);
	}

	// ── Staleness ────────────────────────────────────────────────────────────

	/**
	 * Stale supplier data downgrades a promise rather than keeping it.
	 *
	 * This is the rule that encodes "supplier availability is never guaranteed
	 * stock" into the system instead of into somebody's memory. Tested at the
	 * boundary because an off-by-one here means the shop keeps promising
	 * two-day delivery off a month-old spreadsheet.
	 *
	 * @param string $updated Supplier update timestamp.
	 * @param string $now     The moment of resolution.
	 * @param string $expect  Expected state name.
	 */
	#[DataProvider( 'staleness_boundary' )]
	public function test_stale_supplier_stock_downgrades_to_special_order( string $updated, string $now, string $expect ): void {
		$state = $this->resolver->resolve(
			$this->facts(
				array(
					'has_supplier'        => true,
					'supplier_stock'      => 12,
					'supplier_updated_at' => $updated,
				)
			),
			new \DateTimeImmutable( $now )
		);

		$this->assertSame( $expect, $state->name );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function staleness_boundary(): array {
		// Default window is 7 days.
		return array(
			'one hour old'            => array( '2026-08-12 08:00:00', '2026-08-12 09:00:00', 'SUPPLIER_AVAILABLE' ),
			'six days old'            => array( '2026-08-06 09:00:00', '2026-08-12 09:00:00', 'SUPPLIER_AVAILABLE' ),
			'exactly at the window'   => array( '2026-08-05 09:00:00', '2026-08-12 09:00:00', 'SUPPLIER_AVAILABLE' ),
			'one second past'         => array( '2026-08-05 08:59:59', '2026-08-12 09:00:00', 'SPECIAL_ORDER' ),
			'a month old'             => array( '2026-07-12 09:00:00', '2026-08-12 09:00:00', 'SPECIAL_ORDER' ),
		);
	}

	/**
	 * Stock the shop physically holds is never downgraded by supplier staleness.
	 * The shelf is the shelf.
	 */
	public function test_stale_supplier_data_does_not_affect_stock_on_the_shelf(): void {
		$this->assertSame(
			AvailabilityState::IN_STOCK_STORE,
			$this->resolver->resolve(
				$this->facts(
					array(
						'store_stock'         => 2,
						'has_supplier'        => true,
						'supplier_stock'      => 0,
						'supplier_updated_at' => '2020-01-01 00:00:00',
					)
				),
				new \DateTimeImmutable( '2026-08-12 09:00:00' )
			)
		);
	}

	// ── Backorder ────────────────────────────────────────────────────────────

	public function test_backorders_allowed_with_no_supplier_is_backorder(): void {
		$this->assertSame(
			AvailabilityState::BACKORDER,
			$this->resolver->resolve( $this->facts( array( 'backorders_allowed' => true ) ) )
		);
	}

	/**
	 * A known supplier is better information than a generic backorder, so it
	 * takes precedence — the customer gets a lead time instead of a shrug.
	 */
	public function test_supplier_information_beats_a_generic_backorder(): void {
		$this->assertSame(
			AvailabilityState::SUPPLIER_AVAILABLE,
			$this->resolver->resolve(
				$this->facts(
					array(
						'backorders_allowed'  => true,
						'has_supplier'        => true,
						'supplier_stock'      => 6,
						'supplier_updated_at' => '2026-08-11 09:00:00',
					)
				),
				new \DateTimeImmutable( '2026-08-12 09:00:00' )
			)
		);
	}

	// ── Precedence sweep ─────────────────────────────────────────────────────

	/**
	 * Where several conditions are true at once, the lowest-numbered state wins.
	 *
	 * Asserted exhaustively rather than spot-checked, because the expensive
	 * failures in this model are all precedence failures: a product that is both
	 * "needs confirmation" and "supplier has stock" selling without confirmation,
	 * or a discontinued product still showing a delivery promise.
	 *
	 * @param array<string, mixed> $overrides Facts that make several rules true.
	 * @param string               $expect    The state that must win.
	 */
	#[DataProvider( 'overlapping_conditions' )]
	public function test_lowest_matching_state_wins( array $overrides, string $expect ): void {
		$this->assertSame(
			$expect,
			$this->resolver->resolve( $this->facts( $overrides ), new \DateTimeImmutable( '2026-08-12 09:00:00' ) )->name
		);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function overlapping_conditions(): array {
		$fresh_supplier = array(
			'has_supplier'        => true,
			'supplier_stock'      => 10,
			'supplier_updated_at' => '2026-08-11 09:00:00',
		);

		return array
		(
			'discontinued over everything' => array(
				array_merge( $fresh_supplier, array( 'discontinued' => true, 'enquiry_only' => true, 'requires_confirmation' => true, 'store_stock' => 5, 'backorders_allowed' => true ) ),
				'DISCONTINUED',
			),
			'enquiry over confirmation' => array(
				array_merge( $fresh_supplier, array( 'enquiry_only' => true, 'requires_confirmation' => true ) ),
				'ENQUIRY_ONLY',
			),
			'enquiry over store stock' => array(
				array( 'enquiry_only' => true, 'store_stock' => 8 ),
				'ENQUIRY_ONLY',
			),
			'confirmation over supplier stock' => array(
				array_merge( $fresh_supplier, array( 'requires_confirmation' => true ) ),
				'CONFIRMATION_REQUIRED',
			),
			'confirmation over backorder' => array(
				array( 'requires_confirmation' => true, 'backorders_allowed' => true ),
				'CONFIRMATION_REQUIRED',
			),
			'store stock over supplier stock' => array(
				array_merge( $fresh_supplier, array( 'store_stock' => 1 ) ),
				'IN_STOCK_STORE',
			),
			'store stock over backorder' => array(
				array( 'store_stock' => 1, 'backorders_allowed' => true ),
				'IN_STOCK_STORE',
			),
			'supplier over backorder' => array(
				array_merge( $fresh_supplier, array( 'backorders_allowed' => true ) ),
				'SUPPLIER_AVAILABLE',
			),
			'special order over backorder' => array(
				array( 'has_supplier' => true, 'supplier_stock' => null, 'backorders_allowed' => true ),
				'SPECIAL_ORDER',
			),
			'backorder over temporarily out' => array(
				array( 'has_supplier' => true, 'supplier_stock' => 0, 'supplier_updated_at' => '2026-08-11 09:00:00', 'backorders_allowed' => true ),
				'BACKORDER',
			),
		);
	}

	// ── The safeguard ────────────────────────────────────────────────────────

	/**
	 * Supplier stock must never make a product look more available than the
	 * shelf allows in a way that changes a sellable quantity.
	 *
	 * The resolver has no way to express a quantity at all, which is the design:
	 * a wrong supplier feed can produce a wrong promise, never an oversell.
	 */
	public function test_the_resolver_returns_only_a_state_and_never_a_quantity(): void {
		$state = $this->resolver->resolve(
			$this->facts(
				array(
					'has_supplier'        => true,
					'supplier_stock'      => 9999,
					'supplier_updated_at' => '2026-08-11 09:00:00',
				)
			),
			new \DateTimeImmutable( '2026-08-12 09:00:00' )
		);

		$this->assertInstanceOf( AvailabilityState::class, $state );
		$this->assertNotInstanceOf( StockFacts::class, $state );
		$this->assertFalse( method_exists( $state, 'quantity' ), 'A state must not carry a sellable quantity.' );
	}

	/**
	 * Every state answers whether it can be bought, with no gaps.
	 */
	public function test_every_state_declares_purchasability(): void {
		$purchasable = array(
			'IN_STOCK_STORE'        => true,
			'SUPPLIER_AVAILABLE'    => true,
			'SPECIAL_ORDER'         => true,
			'BACKORDER'             => true,
			'DISCONTINUED'          => false,
			'ENQUIRY_ONLY'          => false,
			'CONFIRMATION_REQUIRED' => false,
			'TEMP_OUT_OF_STOCK'     => false,
			'OUT_OF_STOCK'          => false,
		);

		$this->assertCount( count( $purchasable ), AvailabilityState::cases(), 'Nine states, no more and no fewer.' );

		foreach ( AvailabilityState::cases() as $state ) {
			$this->assertArrayHasKey( $state->name, $purchasable );
			$this->assertSame( $purchasable[ $state->name ], $state->is_purchasable(), $state->name );
		}
	}
}
