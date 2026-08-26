<?php
/**
 * Decides which of several rendering opportunities gets to draw a badge.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

/**
 * One availability badge per product card — not per product per page.
 *
 * THE PROBLEM
 *
 * A product card offers several places a badge could be attached, and more
 * than one of them fires. On the block catalogue the badge can hang off the
 * product image or the product price; on classic templates a loop action fires
 * as well. Left unguarded, one card renders two or three identical badges.
 *
 * The first guard here was "one badge per product per request". That is right
 * for the case it was written against and wrong the moment a product appears
 * on a page twice — a homepage that features a machine at the top and then
 * lists it again in a grid below. The featured one claimed the badge and the
 * grid card silently rendered without any availability statement at all.
 *
 * That is the precise failure this whole subsystem exists to prevent, arriving
 * through a layout change and raising no error. It was found by counting
 * badges against cards on a real rendered page: seven cards, six badges.
 *
 * THE RULE
 *
 * A card is not directly observable — nothing tells the badge code where one
 * ends and the next begins. But the sequence does, because within a single
 * card each anchor fires at most once. So:
 *
 *   an anchor repeating for a product means a new card has started.
 *
 * Measured on the real page, a card emits `product-image` then `product-price`
 * and the featured product's second appearance opens with `product-image`
 * again — the repeat is the boundary.
 *
 * WHICH WAY IT FAILS
 *
 * If a template ever renders the same anchor twice inside one card, this draws
 * two badges. That is the deliberate direction: a duplicated badge is visible
 * and someone fixes it, whereas a missing one looks exactly like a product
 * nobody has said anything about.
 *
 * Pure by design — no WordPress — so the rule is unit-tested directly instead
 * of being inferred from a rendered page.
 */
final class BadgeLedger {

	/**
	 * Anchors seen for each product since it last drew a badge.
	 *
	 * @var array<int, array<string, true>>
	 */
	private array $seen = array();

	/**
	 * Ask whether this opportunity should render the badge.
	 *
	 * @param int    $product_id Product being rendered.
	 * @param string $anchor     Identifier of the rendering opportunity —
	 *                           a block name, or a hook name.
	 * @return bool True if the caller should draw the badge.
	 */
	public function claim( int $product_id, string $anchor ): bool {
		$seen = $this->seen[ $product_id ] ?? array();

		// A repeat means the previous card has ended and a new one has begun,
		// so the ledger for this product starts over and the badge is granted.
		if ( isset( $seen[ $anchor ] ) ) {
			$this->seen[ $product_id ] = array( $anchor => true );

			return true;
		}

		// Anchor not seen before. Either this is the product's first card, in
		// which case it draws, or it is a later anchor within a card that has
		// already drawn, in which case it must not.
		$this->seen[ $product_id ] = $seen + array( $anchor => true );

		return array() === $seen;
	}

	/**
	 * Forget everything.
	 *
	 * Present for tests and for any caller that renders more than one document
	 * in a single process, where carrying a ledger across would suppress the
	 * first badge of the second document.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->seen = array();
	}
}
