<?php
/**
 * Put every availability state onto a real product, so the model can be seen.
 *
 *   ./scripts/wp eval-file scripts/seed-availability-states.php
 *
 * WHY THIS EXISTS
 *
 * The unit tests prove the resolver is correct. They do not prove the badge
 * reaches the page, that the guard actually blocks a purchase, or that nine
 * states are visually distinguishable from each other. Those are different
 * claims and they need a running site to check.
 *
 * One product is given supplier data deliberately dated five weeks back. The
 * staleness downgrade — "זמין בהזמנה" becoming "הזמנה מיוחדת" — is the rule
 * most likely to be quietly broken by a future change, and it is the one rule
 * that otherwise takes a week of waiting to observe.
 *
 * Safe to re-run: it assigns states to existing products and creates nothing.
 *
 * @package ElectricChic
 */

// This file is executed through WP-CLI's eval-file, which runs it inside a
// function via eval() — declare(strict_types=1) is rejected there, and top-level
// variables never reach true global scope.

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * The nine states, and the facts that produce each one.
 *
 * Written as FACTS, not as state names, on purpose: this file cannot assert a
 * product is "supplier available", it can only record what is true and let the
 * resolver decide. If a seeded product shows the wrong badge, either the facts
 * here are wrong or the model is — and both are worth knowing.
 *
 * @return array<string, array<string, mixed>>
 */
function ec_availability_fixtures(): array {
	$today       = gmdate( 'Y-m-d' );
	$five_weeks  = gmdate( 'Y-m-d', strtotime( '-35 days' ) );
	$supplier_id = ec_cortez_supplier_id();

	return array(
		'in_stock_store'        => array(
			'_stock'                    => 4,
			'_ec_supplier_id'           => '',
			'_ec_supplier_stock'        => '',
			'_ec_lead_time_min_days'    => '',
			'_ec_lead_time_max_days'    => '',
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		'supplier_available'    => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => $supplier_id,
			'_ec_supplier_stock'        => 6,
			'_ec_supplier_updated_at'   => $today,
			'_ec_lead_time_min_days'    => 3,
			'_ec_lead_time_max_days'    => 7,
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		// Same facts as above but five weeks old. Must downgrade.
		'special_order_stale'   => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => $supplier_id,
			'_ec_supplier_stock'        => 6,
			'_ec_supplier_updated_at'   => $five_weeks,
			'_ec_lead_time_min_days'    => 5,
			'_ec_lead_time_max_days'    => 14,
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		// Supplier assigned, but nobody has ever reported a number.
		'special_order_unknown' => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => $supplier_id,
			'_ec_supplier_stock'        => '',
			'_ec_supplier_updated_at'   => '',
			'_ec_lead_time_min_days'    => 7,
			'_ec_lead_time_max_days'    => 21,
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		'backorder'             => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => '',
			'_ec_supplier_stock'        => '',
			'_ec_lead_time_min_days'    => 10,
			'_ec_lead_time_max_days'    => 10,
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'yes',
		),
		'confirmation_required' => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => $supplier_id,
			'_ec_supplier_stock'        => 2,
			'_ec_supplier_updated_at'   => $today,
			'_ec_lead_time_min_days'    => 5,
			'_ec_lead_time_max_days'    => 10,
			'_ec_requires_confirmation' => 'yes',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		'enquiry_only'          => array(
			'_stock'                    => 1,
			'_ec_supplier_id'           => '',
			'_ec_supplier_stock'        => '',
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'yes',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		'temp_out_of_stock'     => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => $supplier_id,
			'_ec_supplier_stock'        => 0,
			'_ec_supplier_updated_at'   => $today,
			'_ec_lead_time_min_days'    => 14,
			'_ec_lead_time_max_days'    => 30,
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		'out_of_stock'          => array(
			'_stock'                    => 0,
			'_ec_supplier_id'           => '',
			'_ec_supplier_stock'        => '',
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'no',
			'_backorders'               => 'no',
		),
		'discontinued'          => array(
			'_stock'                    => 3,
			'_ec_supplier_id'           => '',
			'_ec_supplier_stock'        => '',
			'_ec_requires_confirmation' => 'no',
			'_ec_enquiry_only'          => 'no',
			'_ec_discontinued'          => 'yes',
			'_backorders'               => 'no',
		),
	);
}

/**
 * The Cortez supplier record, created once.
 *
 * @return int
 */
function ec_cortez_supplier_id(): int {
	$existing = get_posts(
		array(
			'post_type'   => 'ec_supplier',
			'title'       => 'Cortez',
			'numberposts' => 1,
			'post_status' => 'publish',
		)
	);

	if ( array() !== $existing ) {
		return (int) $existing[0]->ID;
	}

	return (int) wp_insert_post(
		array(
			'post_type'   => 'ec_supplier',
			'post_title'  => 'Cortez',
			'post_status' => 'publish',
		)
	);
}

// ---------------------------------------------------------------------------

$ec_products = wc_get_products(
	array(
		'limit'   => 20,
		'orderby' => 'ID',
		'order'   => 'ASC',
		'status'  => 'publish',
	)
);

if ( count( $ec_products ) < 10 ) {
	WP_CLI::error( 'Need at least 10 products to demonstrate nine states. Run the demo seeder first.' );
}

$ec_fixtures = ec_availability_fixtures();
$ec_index    = 0;

WP_CLI::log( '' );
WP_CLI::log( 'Recording facts. The resolver decides the state — this file cannot.' );
WP_CLI::log( str_repeat( '-', 78 ) );

foreach ( $ec_fixtures as $ec_name => $ec_facts ) {
	$ec_product = $ec_products[ $ec_index ];
	++$ec_index;

	$ec_backorders = $ec_facts['_backorders'];
	unset( $ec_facts['_backorders'] );

	$ec_stock = $ec_facts['_stock'];
	unset( $ec_facts['_stock'] );

	// Store stock goes through WooCommerce, because WooCommerce owns it.
	$ec_product->set_manage_stock( true );
	$ec_product->set_stock_quantity( $ec_stock );
	$ec_product->set_backorders( $ec_backorders );
	$ec_product->set_stock_status( $ec_stock > 0 ? 'instock' : ( 'yes' === $ec_backorders ? 'onbackorder' : 'outofstock' ) );

	foreach ( $ec_facts as $ec_key => $ec_value ) {
		$ec_product->update_meta_data( $ec_key, $ec_value );
	}

	$ec_product->save();

	// Read it back through the same path the front end uses.
	$ec_reader = new ElectricChic\Core\Integration\ProductStockFactsReader();
	$ec_labels = new ElectricChic\Core\Availability\AvailabilityLabels();
	$ec_fresh  = wc_get_product( $ec_product->get_id() );
	$ec_state  = $ec_reader->state_for( $ec_fresh );

	WP_CLI::log(
		sprintf(
			'  %-22s -> %-22s %s  %s',
			$ec_name,
			strtolower( $ec_state->name ),
			$ec_state->is_purchasable() ? 'SELLABLE    ' : 'not sellable',
			$ec_labels->for_state( $ec_state, $ec_reader->facts_for( $ec_fresh ) )
		)
	);
}

WP_CLI::log( str_repeat( '-', 78 ) );
WP_CLI::success( sprintf( 'Seeded %d products across every availability state.', count( $ec_fixtures ) ) );
