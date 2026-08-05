<?php
/**
 * TEMPORARY — proves CI catches an HPOS violation. Deleted immediately after.
 *
 * @package ElectricChic
 */

/**
 * Reads order meta the wrong way on purpose.
 *
 * @param int $order_id Order id.
 * @return mixed
 */
function electricchic_deliberate_violation( $order_id ) {
	return get_post_meta( $order_id, '_ec_supplier_id', true );
}
