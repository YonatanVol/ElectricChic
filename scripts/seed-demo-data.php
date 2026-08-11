<?php
/**
 * Seed the shop with demo categories and products.
 *
 * Run through WP-CLI, which loads WordPress first:
 *
 *   ./scripts/wp eval-file scripts/seed-demo-data.php
 *
 * FOR DEMONSTRATION AND LOCAL DEVELOPMENT ONLY. Everything here is invented —
 * prices, stock levels and specifications are plausible but not the client's
 * real data. Never run this against production.
 *
 * Idempotent: products are matched by SKU, so re-running updates rather than
 * duplicating.
 *
 * No declare(strict_types=1): WP-CLI's eval-file runs this through eval(),
 * which rejects the declaration.
 *
 * @package ElectricChic
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI: ./scripts/wp eval-file scripts/seed-demo-data.php\n" );
	exit( 2 );
}

if ( ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "WooCommerce is not active.\n" );
	exit( 2 );
}

/**
 * Category tree. Two levels, matching the approved information architecture.
 */
$ec_categories = array(
	'אופניים'       => array( 'אופניים חשמליים', 'אופני הרים', 'אופני כביש', 'אופני עיר', 'אופני ילדים' ),
	'אביזרים'       => array( 'קסדות', 'מנעולים', 'תאורה', 'משאבות' ),
	'רכיבים וחלפים' => array( 'צמיגים ופנימיות', 'בלמים' ),
);

$ec_term_ids = array();

foreach ( $ec_categories as $ec_parent_name => $ec_children ) {
	$ec_parent = term_exists( $ec_parent_name, 'product_cat' );

	if ( ! $ec_parent ) {
		$ec_parent = wp_insert_term( $ec_parent_name, 'product_cat' );
	}

	if ( is_wp_error( $ec_parent ) ) {
		continue;
	}

	$ec_parent_id                    = (int) $ec_parent['term_id'];
	$ec_term_ids[ $ec_parent_name ] = $ec_parent_id;

	foreach ( $ec_children as $ec_child_name ) {
		$ec_child = term_exists( $ec_child_name, 'product_cat' );

		if ( ! $ec_child ) {
			$ec_child = wp_insert_term( $ec_child_name, 'product_cat', array( 'parent' => $ec_parent_id ) );
		}

		if ( ! is_wp_error( $ec_child ) ) {
			$ec_term_ids[ $ec_child_name ] = (int) $ec_child['term_id'];
		}
	}
}

echo "Categories ready.\n";

/**
 * Demo products.
 *
 * Stock levels are varied deliberately so the shop shows a realistic mix rather
 * than everything being available — which is what the availability model will
 * later turn into distinct customer-facing states.
 */
$ec_products = array(
	// Bicycles.
	array( 'EC-EB-001', 'אופניים חשמליים עירוניים 250W', 'אופניים חשמליים', 6490, 5299, 3, 'מנוע 250W, סוללה 500Wh, טווח עד 70 ק"מ. מושלמים לנסיעה יומית בעיר.' ),
	array( 'EC-EB-002', 'אופניים חשמליים מתקפלים 20"', 'אופניים חשמליים', 5290, null, 2, 'מתקפלים תוך שניות, אידיאליים לשילוב עם תחבורה ציבורית.' ),
	array( 'EC-MTB-001', 'אופני הרים 29" שיכוך קדמי', 'אופני הרים', 3890, null, 4, 'שלדת אלומיניום, 21 הילוכים, בלמי דיסק הידראוליים.' ),
	array( 'EC-MTB-002', 'אופני הרים מקצועיים פחמן', 'אופני הרים', 12900, 11500, 1, 'שלדת פחמן מלאה, מערכת הילוכים Shimano XT.' ),
	array( 'EC-RD-001', 'אופני כביש אלומיניום 700C', 'אופני כביש', 4590, null, 2, 'קלים ומהירים, 18 הילוכים, מתאימים לרכיבות ארוכות.' ),
	array( 'EC-CT-001', 'אופני עיר קלאסיים עם סלסלה', 'אופני עיר', 1890, 1590, 6, 'ישיבה זקופה ונוחה, סלסלה קדמית, מגני בוץ.' ),
	array( 'EC-KID-001', 'אופני ילדים 16" עם גלגלי עזר', 'אופני ילדים', 890, null, 8, 'מתאים לגילאי 4–6, גלגלי עזר ניתנים להסרה.' ),
	array( 'EC-KID-002', 'אופני ילדים 20" הרים', 'אופני ילדים', 1290, null, 0, 'מתאים לגילאי 7–10, 6 הילוכים, בלמי V-Brake.' ),

	// Accessories.
	array( 'EC-HLM-001', 'קסדת רכיבה מאווררת', 'קסדות', 249, 199, 15, 'קלה ומאווררת, מידה מתכווננת, תקן CE.' ),
	array( 'EC-HLM-002', 'קסדת ילדים צבעונית', 'קסדות', 179, null, 12, 'עיצוב צבעוני, רצועות מרופדות, תקן בטיחות מלא.' ),
	array( 'EC-LCK-001', 'מנעול U מוקשח', 'מנעולים', 189, null, 20, 'פלדה מוקשחת, עמיד בפני חיתוך, כולל תושבת.' ),
	array( 'EC-LCK-002', 'מנעול שרשרת עם מפתח', 'מנעולים', 129, null, 0, 'שרשרת מצופה, אורך 90 ס"מ.' ),
	array( 'EC-LGT-001', 'סט תאורה קדמית ואחורית USB', 'תאורה', 149, 119, 25, 'נטענת USB, 5 מצבי תאורה, עמידה במים.' ),
	array( 'EC-PMP-001', 'משאבת רצפה עם מד לחץ', 'משאבות', 169, null, 10, 'מד לחץ מובנה, מתאימה לשני סוגי שסתומים.' ),
	array( 'EC-PMP-002', 'משאבה ניידת קומפקטית', 'משאבות', 89, null, 18, 'קלת משקל, נתפסת לשלדה.' ),

	// Components.
	array( 'EC-TR-001', 'צמיג הרים 29x2.25', 'צמיגים ופנימיות', 179, null, 14, 'אחיזה מצוינת בשטח, עמיד בפני תקרים.' ),
	array( 'EC-TR-002', 'פנימית 700C', 'צמיגים ופנימיות', 39, null, 40, 'שסתום פרסטה, גומי איכותי.' ),
	array( 'EC-BRK-001', 'רפידות בלם דיסק', 'בלמים', 89, null, 0, 'רפידות אורגניות, שקטות ויעילות.' ),
);

$ec_created = 0;
$ec_updated = 0;

foreach ( $ec_products as $ec_row ) {
	list( $ec_sku, $ec_name, $ec_category, $ec_price, $ec_sale, $ec_stock, $ec_description ) = $ec_row;

	$ec_existing_id = wc_get_product_id_by_sku( $ec_sku );
	$ec_product     = $ec_existing_id ? wc_get_product( $ec_existing_id ) : new WC_Product_Simple();

	if ( $ec_existing_id ) {
		++$ec_updated;
	} else {
		++$ec_created;
	}

	$ec_product->set_name( $ec_name );
	$ec_product->set_sku( $ec_sku );
	$ec_product->set_regular_price( (string) $ec_price );
	$ec_product->set_price( (string) ( $ec_sale ?? $ec_price ) );

	// Clearing matters on re-run: without the else branch a product that used
	// to be on sale keeps its old sale price forever, and the seeder stops
	// being idempotent in the one way that shows on the page.
	if ( null !== $ec_sale ) {
		$ec_product->set_sale_price( (string) $ec_sale );
	} else {
		$ec_product->set_sale_price( '' );
	}

	$ec_product->set_description( $ec_description );
	$ec_product->set_short_description( $ec_description );
	$ec_product->set_manage_stock( true );
	$ec_product->set_stock_quantity( $ec_stock );
	$ec_product->set_stock_status( $ec_stock > 0 ? 'instock' : 'outofstock' );
	$ec_product->set_catalog_visibility( 'visible' );
	$ec_product->set_status( 'publish' );

	if ( isset( $ec_term_ids[ $ec_category ] ) ) {
		$ec_product->set_category_ids( array( $ec_term_ids[ $ec_category ] ) );
	}

	$ec_product->save();
}

printf( "Products: %d created, %d updated.\n", $ec_created, $ec_updated );

/*
 * The front page is deliberately NOT set here.
 *
 * scripts/build-homepage.php owns it. When setup-wordpress.sh runs with --seed,
 * this script used to point the front page at the shop archive and then the
 * homepage builder pointed it back — so the result depended on which ran last.
 * One owner, no ordering dependency.
 */

echo "Demo data seeded.\n";
