<?php
/**
 * FIXTURE — deliberately correct. Not production code.
 *
 * Everything below is either the right way to do it under HPOS, or a case that
 * superficially resembles a violation but is legitimate. The sniff must stay
 * silent on all of it. A sniff that only proves it fires is half-tested; false
 * positives are what make people disable a rule.
 *
 * Expected: 0 errors.
 *
 * @package ElectricChic\Tests\Fixtures
 */


/**
 * The correct CRUD equivalents of every violation in violations.php.
 *
 * @param WC_Order $order Order object.
 * @return void
 */
function ec_fixture_crud_correct( $order ) {

	$a = $order->get_meta( '_ec_supplier_id' );

	$order->update_meta_data( '_ec_supplier_po_ref', 'PO-123' );
	$order->add_meta_data( '_ec_item_cost', 42.00 );
	$order->delete_meta_data( '_ec_pickup_ready_at' );
	$order->save();

	$orders = wc_get_orders(
		array(
			'limit'  => 10,
			'status' => 'processing',
		)
	);

	return array( $a, $orders );
}

/**
 * Post meta on genuine posts is entirely fine — orders are the special case,
 * not post meta itself.
 *
 * @param int $post_id    Post id.
 * @param int $product_id Product id.
 * @param int $supplier_id Supplier CPT id.
 * @return array
 */
function ec_fixture_legitimate_post_meta( $post_id, $product_id, $supplier_id ) {

	// Products remain in wp_posts under HPOS. This is correct.
	$sku = get_post_meta( $product_id, '_sku', true );

	// The supplier CPT is ours and lives in wp_posts.
	$code = get_post_meta( $supplier_id, '_ec_sup_code', true );

	// An ordinary post.
	update_post_meta( $post_id, '_ec_page_variant', 'b' );

	return array( $sku, $code );
}

/**
 * Comparisons against the order post type string are legitimate — the sniff
 * only flags it as a query argument.
 *
 * @param WC_Order $order Order object.
 * @return bool
 */
function ec_fixture_type_comparison( $order ) {

	if ( 'shop_order' === $order->get_type() ) {
		return true;
	}

	$allowed_types = array( 'shop_order', 'shop_order_refund' );

	return in_array( $order->get_type(), $allowed_types, true );
}

/**
 * Querying a non-order post type is fine, including in the same array shape.
 *
 * @return array
 */
function ec_fixture_other_post_type_query() {

	return get_posts(
		array(
			'post_type'      => 'ec_supplier',
			'posts_per_page' => 50,
		)
	);
}

/**
 * A method named get_post_meta on our own class is not the global function.
 */
class EC_Fixture_Repository {

	/**
	 * Deliberately shares a name with the global function.
	 *
	 * @param int $order_id Order id.
	 * @return mixed
	 */
	public function get_post_meta( $order_id ) {
		return $order_id;
	}

	/**
	 * Calling our own method must not be flagged.
	 *
	 * @param int $order_id Order id.
	 * @return mixed
	 */
	public function read( $order_id ) {
		return $this->get_post_meta( $order_id );
	}
}
