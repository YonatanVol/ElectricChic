<?php
/**
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Integration;

use ElectricChic\Core\Integration\AvailableNowQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "זמין עכשיו בחנות" is a promise, so the query behind it is tested.
 *
 * These assertions are about the SHAPE of the query rather than its results —
 * there is no database here, deliberately. What they protect is the reasoning:
 * that stock alone is not enough, that the overriding flags are both checked,
 * and that a product predating those fields is not silently excluded from the
 * shop's own shelf.
 */
final class AvailableNowQueryTest extends TestCase {

	private AvailableNowQuery $query;

	protected function setUp(): void {
		$this->query = new AvailableNowQuery();
	}

	public function test_it_requires_units_on_the_shelf(): void {
		$clauses = $this->query->meta_query();

		$stock = array_values(
			array_filter(
				$clauses,
				static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === AvailableNowQuery::STOCK_KEY
			)
		);

		$this->assertCount( 1, $stock );
		$this->assertSame( '>', $stock[0]['compare'] );
		$this->assertSame( 0, $stock[0]['value'] );
		$this->assertSame( 'NUMERIC', $stock[0]['type'], 'A string comparison would rank "9" above "10".' );
	}

	public function test_every_clause_must_hold(): void {
		$this->assertSame( 'AND', $this->query->meta_query()['relation'] );
	}

	/**
	 * The regression this whole class exists for: stock alone is not the test.
	 */
	#[DataProvider( 'overriding_flags' )]
	public function test_a_flag_that_outranks_stock_is_checked( string $key ): void {
		$found = false;

		foreach ( $this->query->meta_query() as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}

			foreach ( $clause as $inner ) {
				if ( is_array( $inner ) && ( $inner['key'] ?? '' ) === $key ) {
					$found = true;
				}
			}
		}

		$this->assertTrue(
			$found,
			sprintf( '%s outranks stock in the resolver, so it must outrank stock here too.', $key )
		);
	}

	/**
	 * A meta comparison against a key a product does not carry matches nothing.
	 * Without NOT EXISTS, every product predating these fields disappears from
	 * the shop's own shelf.
	 */
	#[DataProvider( 'overriding_flags' )]
	public function test_a_product_without_the_flag_is_not_excluded( string $key ): void {
		$pair = null;

		foreach ( $this->query->meta_query() as $clause ) {
			if ( is_array( $clause ) && ( $clause['relation'] ?? '' ) === 'OR' ) {
				foreach ( $clause as $inner ) {
					if ( is_array( $inner ) && ( $inner['key'] ?? '' ) === $key ) {
						$pair = $clause;
					}
				}
			}
		}

		$this->assertNotNull( $pair, sprintf( '%s must be an OR pair, not a bare comparison.', $key ) );

		$compares = array();

		foreach ( $pair as $inner ) {
			if ( is_array( $inner ) ) {
				$compares[] = $inner['compare'] ?? '=';
			}
		}

		$this->assertContains( 'NOT EXISTS', $compares );
		$this->assertContains( '!=', $compares );
	}

	public function test_it_merges_rather_than_replaces(): void {
		$theirs = array( array( 'key' => '_ec_supplier_id', 'compare' => 'EXISTS' ) );

		$result = $this->query->apply_to( array( 'meta_query' => $theirs ) );

		$this->assertContains(
			$theirs[0],
			$result['meta_query'],
			'A block arrives with a meta_query of its own; replacing it drops what the template asked for.'
		);
	}

	public function test_it_copes_with_no_existing_meta_query(): void {
		$result = $this->query->apply_to( array( 'post_type' => 'product' ) );

		$this->assertSame( 'product', $result['post_type'], 'Unrelated arguments must survive.' );
		$this->assertSame( 'AND', $result['meta_query']['relation'] );
	}

	public function test_it_ignores_a_malformed_meta_query(): void {
		$result = $this->query->apply_to( array( 'meta_query' => 'nonsense' ) );

		$this->assertIsArray( $result['meta_query'] );
		$this->assertSame( 'AND', $result['meta_query']['relation'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function overriding_flags(): array {
		return array(
			'discontinued'  => array( AvailableNowQuery::DISCONTINUED_KEY ),
			'enquiry only'  => array( AvailableNowQuery::ENQUIRY_ONLY_KEY ),
		);
	}
}
