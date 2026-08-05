<?php
/**
 * FIXTURE — deliberately wrong. Not production code.
 *
 * Every numbered case below must be caught by the HPOS sniff. This file is
 * excluded from the main PHPCS run and exists only so the sniff's behaviour is
 * verified rather than assumed. If you "fix" this file, the self-test breaks.
 *
 * Expected: 9 errors.
 *
 * @package ElectricChic\Tests\Fixtures
 */


/**
 * Cases that must be flagged as PostMeta.
 *
 * @param int      $order_id     Order id.
 * @param WC_Order $order        Order object.
 * @param object   $item         Order line item.
 * @param int      $refund_id    Refund id.
 * @return void
 */
function ec_fixture_meta_violations( $order_id, $order, $item, $refund_id ) {

	// 1. Plain read with an order id.
	$a = get_post_meta( $order_id, '_ec_supplier_id', true );

	// 2. Write with an order id.
	update_post_meta( $order_id, '_ec_supplier_po_ref', 'PO-123' );

	// 3. Add with an order id.
	add_post_meta( $order_id, '_ec_item_cost', 42.00 );

	// 4. Delete with an order id.
	delete_post_meta( $order_id, '_ec_pickup_ready_at' );

	// 5. Object accessor form.
	$b = get_post_meta( $order->get_id(), '_ec_promised_ready_date', true );

	// 6. camelCase variable.
	$orderId = 7;
	$c       = get_post_meta( $orderId, '_ec_stock_source', true );

	// 7. Line item accessor.
	$d = get_post_meta( $item->get_order_id(), '_ec_item_supplier_id', true );

	// 8. Refunds live in the same storage and break the same way.
	$e = get_post_meta( $refund_id, '_ec_rma_number', true );

	return array( $a, $b, $c, $d, $e );
}

/**
 * Case that must be flagged as OrderQuery.
 *
 * @return array
 */
function ec_fixture_query_violation() {

	// 9. Querying the order post type misses everything under HPOS.
	return get_posts(
		array(
			'post_type'      => 'shop_order',
			'posts_per_page' => 10,
		)
	);
}
