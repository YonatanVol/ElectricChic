<?php
/**
 * Which products may be listed under a promise of immediate availability.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Integration;

/**
 * The query behind "זמין עכשיו בחנות".
 *
 * WHY THIS IS NOT JUST `_stock > 0`
 *
 * A heading that promises a customer can collect today is a commitment, and
 * the shelf count alone does not support it. The resolver ranks several facts
 * above stock: a discontinued machine is not for sale whatever the shelf says,
 * and one flagged enquiry-only has not been priced or confirmed. Both would
 * otherwise appear under that heading with units in the building.
 *
 * The first version of this filtered on WooCommerce's own "outofstock"
 * visibility term, which was not enough — a backorder product is not out of
 * stock, so a machine arriving in 14–30 days was listed under a heading
 * promising immediate collection. It was found by reading the rendered
 * homepage after the real catalogue landed, not by a test.
 *
 * THE KNOWN WEAKNESS, STATED PLAINLY
 *
 * These conditions MIRROR AvailabilityResolver's path to IN_STOCK_STORE; they
 * do not call it. A resolver cannot run inside a WP_Query — it needs a product
 * object per row, and the point of a query is to avoid loading every product
 * to find six. So the rules exist in two places and can drift.
 *
 * Two things make that survivable rather than dangerous:
 *
 *   · Every card still renders its badge FROM the resolver. A divergence
 *     therefore shows up on the page as a card whose badge contradicts the
 *     heading above it — visible, not hidden.
 *   · The rules now live in ONE place instead of being retyped per consumer.
 *     This class exists because there were about to be two copies.
 *
 * The durable fix is a derived-state column written on save and queried here,
 * with invalidation on supplier edits, on stock changes and on the daily
 * staleness rollover. That is real work and is not being smuggled in behind a
 * homepage layout change.
 *
 * Pure by design — no WordPress — so the shape of the query is unit-tested
 * directly rather than inferred from a rendered page.
 */
final class AvailableNowQuery {

	/** Units physically on the shelf. */
	public const STOCK_KEY = '_stock';

	/** Set when the model has been withdrawn; outranks stock. */
	public const DISCONTINUED_KEY = '_ec_discontinued';

	/** Set when the product is not sold online; outranks stock. */
	public const ENQUIRY_ONLY_KEY = '_ec_enquiry_only';

	/**
	 * Flags that disqualify a product however full the shelf is.
	 *
	 * @var string[]
	 */
	private const OVERRIDING_FLAGS = array(
		self::DISCONTINUED_KEY,
		self::ENQUIRY_ONLY_KEY,
	);

	/**
	 * The meta_query for products that can genuinely be collected today.
	 *
	 * Mixed keys are WP_Query's own shape, not sloppiness: 'relation' is a
	 * named key and the clauses beside it are a numeric list.
	 *
	 * @return array<int|string, mixed>
	 */
	public function meta_query(): array {
		$clauses = array(
			'relation' => 'AND',
			array(
				'key'     => self::STOCK_KEY,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);

		foreach ( self::OVERRIDING_FLAGS as $flag ) {
			/*
			 * NOT EXISTS is half of each pair on purpose. A meta comparison
			 * against a key a product does not carry matches nothing at all,
			 * so without it every product predating these fields — which is
			 * most of them — would be excluded from the shop's own shelf.
			 */
			$clauses[] = array(
				'relation' => 'OR',
				array(
					'key'     => $flag,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $flag,
					'value'   => 'yes',
					'compare' => '!=',
				),
			);
		}

		return $clauses;
	}

	/**
	 * Merge the conditions into an existing set of query arguments.
	 *
	 * Merged rather than assigned: a Product Collection block arrives with a
	 * meta_query of its own, and replacing it would silently drop whatever the
	 * template asked for.
	 *
	 * @param array<string, mixed> $query_args Existing WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public function apply_to( array $query_args ): array {
		$existing = $query_args['meta_query'] ?? array();

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$query_args['meta_query'] = array_merge( $existing, $this->meta_query() );

		return $query_args;
	}
}
