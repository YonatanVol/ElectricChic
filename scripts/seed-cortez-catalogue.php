<?php
/**
 * Replace the invented demo catalogue with Cortez's real one.
 *
 *   ./scripts/wp eval-file scripts/seed-cortez-catalogue.php
 *
 * WHY THIS EXISTS
 *
 * The demo shipped with eighteen invented products at invented prices. That was
 * fine while only the developer was looking at it. It stops being fine the
 * moment Eli forwards the link to Cortez, who know their own catalogue and
 * their own prices — a made-up model name is the fastest way to make everything
 * else on the page look made up too.
 *
 * The data comes from scripts/data/cortez-catalogue.json, captured from
 * cortez.co.il's public WooCommerce Store API and product pages. It is kept as
 * a separate reviewable file on purpose: prices move, and when they do this
 * should be a data change somebody can read in a diff, not a hunt through PHP.
 *
 * WHAT IS REAL AND WHAT IS NOT
 *
 *   Real   — model names, list prices, sale prices, categories, technical
 *            specifications, colour options. All captured 2026-08-13.
 *   Ours   — the availability facts. Electric Chic's stock levels are not
 *            public and Cortez's stock is not ours to state, so store stock,
 *            supplier figures and lead times are illustrative. They are chosen
 *            to exercise all nine availability states, not to describe reality.
 *
 * Descriptions are assembled from the specification table only. Cortez's
 * marketing prose is their copyright and is deliberately not copied.
 *
 * Safe to re-run: it trashes previously seeded products and rebuilds.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Marks a product as created by this script, so a re-run can clean up after
 * itself without touching anything a human added by hand.
 */
const EC_SEED_FLAG = '_ec_seeded_catalogue';

/**
 * Turn Cortez's SEO page titles into product names.
 *
 * Their titles carry a keyword tail — "Cortez SPOT אופניים חשמליים מתקפלים עם
 * סוללת 48V/10A לניידות עירונית" — which is right for their search ranking and
 * wrong as a product name in a catalogue that already says which category it is
 * in. The model name is what a customer says out loud in the shop.
 *
 * @param string $raw Cortez's product title.
 * @return string
 */
function ec_model_name( string $raw ): string {
	$name = trim( $raw );

	// Cut everything from the first Hebrew word onward; the model name is Latin
	// and digits. "14 COPPER" is Cortez's own ordering and is normalised below.
	$name = preg_split( '/\s+(?=[\x{0590}-\x{05FF}])/u', $name )[0] ?? $name;
	$name = trim( $name );

	$fixes = array(
		'14 COPPER' => 'COPPER 14',
		'+MAX 2'    => 'MAX 2+',
	);

	$name = $fixes[ $name ] ?? $name;

	// Every one of these is a Cortez model; say so once, consistently.
	if ( ! str_starts_with( strtoupper( $name ), 'CORTEZ' ) ) {
		$name = 'Cortez ' . $name;
	}

	return preg_replace( '/\s+/', ' ', $name );
}

/**
 * Build a description from the specification table.
 *
 * Specs are facts and safe to restate. Cortez's marketing copy is not, and is
 * not copied — which also means nothing here claims a benefit nobody measured.
 *
 * @param array<string, string> $specs Specification pairs.
 * @return string
 */
function ec_description( array $specs ): string {
	if ( array() === $specs ) {
		return '';
	}

	$rows = '';

	foreach ( $specs as $key => $value ) {
		$rows .= sprintf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $key ),
			esc_html( $value )
		);
	}

	return '<h3>מפרט טכני</h3><table class="ec-spec-table"><tbody>' . $rows . '</tbody></table>';
}

/**
 * The short line under the title: the three things a buyer actually compares.
 *
 * @param array<string, string> $specs Specification pairs.
 * @return string
 */
function ec_short_description( array $specs ): string {
	$wanted = array( 'סוללה', 'טווח נסיעה', 'מהירות מירבית', 'מנוע' );
	$parts  = array();

	foreach ( $wanted as $key ) {
		if ( ! empty( $specs[ $key ] ) ) {
			$parts[] = $key . ': ' . $specs[ $key ];
		}
	}

	return implode( ' · ', array_slice( $parts, 0, 3 ) );
}

/**
 * Translate an availability plan into the facts the resolver reads.
 *
 * Note what this returns: FACTS, never a state. Even the seeder cannot choose
 * what the customer is told — it records what is true and the resolver decides.
 * If that produced the wrong badge, either these facts are wrong or the model
 * is, and both are worth finding out.
 *
 * @param string   $plan        Plan key from the data file.
 * @param int|null $store_stock Units in the shop, where the plan specifies one.
 * @param int      $supplier_id Cortez's supplier post ID.
 * @return array<string, mixed>
 */
function ec_availability_facts( string $plan, ?int $store_stock, int $supplier_id ): array {
	$today    = gmdate( 'Y-m-d' );
	$long_ago = gmdate( 'Y-m-d', strtotime( '-38 days' ) );

	/*
	 * Only the in_store plan puts units on the shelf. Every other plan means
	 * "not in the shop", and store stock must be zero for it — the resolver
	 * checks the shelf before it ever looks at a supplier, so a stray quantity
	 * here silently overrides the whole plan.
	 *
	 * It did exactly that on the first run: quantities intended as SUPPLIER
	 * figures were read as store stock and nineteen of twenty-four products
	 * came out "במלאי בחנות". The model was right; the data feeding it was not.
	 */
	$has_stock = ( 'in_store' === $plan && is_int( $store_stock ) ) ? max( 1, $store_stock ) : 0;

	$base = array(
		'stock'                     => $has_stock,
		'backorders'                => 'no',
		'_ec_supplier_id'           => '',
		'_ec_supplier_stock'        => '',
		'_ec_supplier_updated_at'   => '',
		'_ec_lead_time_min_days'    => '',
		'_ec_lead_time_max_days'    => '',
		'_ec_requires_confirmation' => 'no',
		'_ec_enquiry_only'          => 'no',
		'_ec_discontinued'          => 'no',
	);

	$supplier = array(
		'_ec_supplier_id'        => $supplier_id,
		'_ec_lead_time_min_days' => 3,
		'_ec_lead_time_max_days' => 10,
	);

	return match ( $plan ) {
		// On the shelf at Bar Lev. Nothing else matters.
		'in_store'          => $base,

		// Cortez confirmed stock this week.
		'supplier_fresh'    => array_merge(
			$base,
			$supplier,
			array( '_ec_supplier_stock' => 6, '_ec_supplier_updated_at' => $today )
		),

		// Cortez said yes, five weeks ago. Downgrades to a special order.
		'supplier_stale'    => array_merge(
			$base,
			$supplier,
			array( '_ec_supplier_stock' => 4, '_ec_supplier_updated_at' => $long_ago, '_ec_lead_time_max_days' => 21 )
		),

		// Cortez supplies it; nobody has asked how many they hold.
		'supplier_unknown'  => array_merge( $base, $supplier, array( '_ec_lead_time_max_days' => 21 ) ),

		// Cortez checked and has none.
		'supplier_zero'     => array_merge(
			$base,
			$supplier,
			array( '_ec_supplier_stock' => 0, '_ec_supplier_updated_at' => $today )
		),

		// Sold ahead of the next container.
		'backorder'         => array_merge( $base, array( 'backorders' => 'yes', '_ec_lead_time_min_days' => 14, '_ec_lead_time_max_days' => 30 ) ),

		// High value: the shop confirms with Cortez before taking money.
		'confirm'           => array_merge(
			$base,
			$supplier,
			array( '_ec_supplier_stock' => 2, '_ec_supplier_updated_at' => $today, '_ec_requires_confirmation' => 'yes' )
		),

		// Fleet and B2B enquiries — Cortez run a B2B line, so this is real.
		'enquiry'           => array_merge( $base, array( '_ec_enquiry_only' => 'yes' ) ),

		'discontinued'      => array_merge( $base, array( '_ec_discontinued' => 'yes' ) ),

		default             => $base,
	};
}

// ---------------------------------------------------------------------------

$ec_file = dirname( __DIR__ ) . '/scripts/data/cortez-catalogue.json';

if ( ! is_readable( $ec_file ) ) {
	WP_CLI::error( 'Catalogue data not found: ' . $ec_file );
}

$ec_data = json_decode( (string) file_get_contents( $ec_file ), true );

if ( ! is_array( $ec_data ) || empty( $ec_data['products'] ) ) {
	WP_CLI::error( 'Catalogue data is unreadable.' );
}

WP_CLI::log( '' );
WP_CLI::log( 'Source: ' . $ec_data['_source'] );
WP_CLI::log( 'Captured: ' . $ec_data['_captured'] );
WP_CLI::log( '' );

// 1. Remove everything the demo invented, plus anything a previous run seeded.
$ec_removed = 0;

foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $ec_old ) {
	wp_delete_post( $ec_old->get_id(), true );
	++$ec_removed;
}

WP_CLI::log( sprintf( 'Removed %d previous product(s), including the invented demo catalogue.', $ec_removed ) );

// 2. Cortez, as a supplier record.
$ec_supplier = get_posts(
	array( 'post_type' => 'ec_supplier', 'title' => 'Cortez', 'numberposts' => 1, 'post_status' => 'publish' )
);

$ec_supplier_id = $ec_supplier ? (int) $ec_supplier[0]->ID : (int) wp_insert_post(
	array( 'post_type' => 'ec_supplier', 'post_title' => 'Cortez', 'post_status' => 'publish' )
);

WP_CLI::log( sprintf( 'Supplier "Cortez" is post %d.', $ec_supplier_id ) );
WP_CLI::log( '' );

// 3. Build the catalogue.
$ec_reader = new ElectricChic\Core\Integration\ProductStockFactsReader();
$ec_labels = new ElectricChic\Core\Availability\AvailabilityLabels();
$ec_counts = array();

WP_CLI::log( sprintf( '  %-26s %8s %8s  %s', 'model', 'price', 'sale', 'what the customer is told' ) );
WP_CLI::log( str_repeat( '-', 96 ) );

foreach ( $ec_data['products'] as $ec_row ) {
	$ec_term = term_exists( $ec_row['category'], 'product_cat' );

	if ( ! $ec_term ) {
		$ec_term = wp_insert_term( $ec_row['category'], 'product_cat' );
	}

	$ec_product = new WC_Product_Simple();
	$ec_product->set_name( ec_model_name( $ec_row['name'] ) );
	$ec_product->set_status( 'publish' );
	$ec_product->set_catalog_visibility( 'visible' );
	$ec_product->set_sku( $ec_row['sku'] );
	$ec_product->set_regular_price( (string) $ec_row['regular_price'] );

	if ( ! empty( $ec_row['sale_price'] ) ) {
		$ec_product->set_sale_price( (string) $ec_row['sale_price'] );
	}

	$ec_product->set_short_description( ec_short_description( $ec_row['specs'] ) );
	$ec_product->set_description( ec_description( $ec_row['specs'] ) );

	if ( ! is_wp_error( $ec_term ) && isset( $ec_term['term_id'] ) ) {
		$ec_product->set_category_ids( array( (int) $ec_term['term_id'] ) );
	}

	$ec_facts = ec_availability_facts( $ec_row['availability'], $ec_row['store_stock'], $ec_supplier_id );

	$ec_product->set_manage_stock( true );
	$ec_product->set_stock_quantity( (int) $ec_facts['stock'] );
	$ec_product->set_backorders( $ec_facts['backorders'] );
	$ec_product->set_stock_status( $ec_facts['stock'] > 0 ? 'instock' : ( 'yes' === $ec_facts['backorders'] ? 'onbackorder' : 'outofstock' ) );

	unset( $ec_facts['stock'], $ec_facts['backorders'] );

	foreach ( $ec_facts as $ec_key => $ec_value ) {
		$ec_product->update_meta_data( $ec_key, $ec_value );
	}

	/*
	 * Image provenance is a REQUIRED field, and it is recorded as unresolved
	 * rather than left blank. Cortez's product photography belongs to Cortez;
	 * the correct route is their official dealer asset pack, which is free and
	 * is an easy ask given they are already sponsoring the shop. What must not
	 * happen is images used, provenance never recorded, and the question
	 * resurfacing in a letter a year later.
	 */
	$ec_product->update_meta_data( '_ec_image_rights', 'pending' );
	$ec_product->update_meta_data( '_ec_source_url', $ec_row['source_url'] );
	$ec_product->update_meta_data( EC_SEED_FLAG, 'yes' );

	if ( ! empty( $ec_row['colours'] ) ) {
		$ec_attribute = new WC_Product_Attribute();
		$ec_attribute->set_name( 'צבע' );
		$ec_attribute->set_options( $ec_row['colours'] );
		$ec_attribute->set_visible( true );
		$ec_attribute->set_variation( false );
		$ec_product->set_attributes( array( $ec_attribute ) );
	}

	$ec_product->save();

	// Read the state back through exactly the path the front end uses.
	$ec_fresh = wc_get_product( $ec_product->get_id() );
	$ec_state = $ec_reader->state_for( $ec_fresh );

	$ec_counts[ strtolower( $ec_state->name ) ] = ( $ec_counts[ strtolower( $ec_state->name ) ] ?? 0 ) + 1;

	WP_CLI::log(
		sprintf(
			'  %-26s %8s %8s  %s',
			ec_model_name( $ec_row['name'] ),
			'₪' . number_format( (float) $ec_row['regular_price'] ),
			$ec_row['sale_price'] ? '₪' . number_format( (float) $ec_row['sale_price'] ) : '',
			$ec_labels->for_state( $ec_state, $ec_reader->facts_for( $ec_fresh ) )
		)
	);
}

WP_CLI::log( str_repeat( '-', 96 ) );
WP_CLI::log( '' );
WP_CLI::log( 'Availability spread across the catalogue:' );

ksort( $ec_counts );

foreach ( $ec_counts as $ec_state_name => $ec_count ) {
	WP_CLI::log( sprintf( '  %-24s %d', $ec_state_name, $ec_count ) );
}

WP_CLI::log( '' );
WP_CLI::warning( 'Prices and specs are Cortez list values captured once. Confirm with Eli before this is customer-facing.' );
WP_CLI::warning( 'Every product has _ec_image_rights = pending. Request the Cortez dealer asset pack.' );
WP_CLI::success( sprintf( 'Seeded %d real Cortez products.', count( $ec_data['products'] ) ) );
