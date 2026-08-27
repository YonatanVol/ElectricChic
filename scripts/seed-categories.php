<?php
/**
 * Put the shop's five departments into the catalogue.
 *
 *   ./scripts/wp eval-file scripts/seed-categories.php dry-run
 *   ./scripts/wp eval-file scripts/seed-categories.php
 *
 * WHY A SCRIPT
 *
 * Three `wp term create` commands would have done this locally and then been
 * lost. This has to run again on staging and on production, by someone who is
 * not the person who typed it the first time, and it has to be safe to run
 * twice. Everything below is idempotent: it creates what is missing, corrects
 * what has drifted, and leaves what is already right alone.
 *
 * THE RENAME
 *
 * `bikes` currently means ELECTRIC bikes. Once a separate category exists for
 * ordinary ones, that slug is a trap — it reads as the general case and means
 * the specific one. It becomes `e-bikes`.
 *
 * The freed `bikes` slug is deliberately NOT reused for the new category. If
 * it were, every existing link to /product-category/bikes/ would quietly land
 * on a page of ordinary bicycles instead of the electric ones it was written
 * for. A 404 tells the truth; a wrong page does not.
 *
 * EMPTY ON PURPOSE
 *
 * Three of the five have no products, because the catalogue is 24 Cortez
 * electric machines and nothing else. They are created empty rather than
 * populated with invented stock, and the homepage says so in as many words.
 * What the shop actually sells in accessories and spares is a question for the
 * owner, not something to fill in from a supplier's website.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * The five departments, in the order a customer should meet them.
 *
 * Order matters and is stored as term_order, so the homepage navigation and
 * the shop's own category lists agree without either of them hard-coding it.
 *
 * @var array<int, array{slug: string, name: string, was: string|null, description: string}>
 */
const EC_DEPARTMENTS = array(
	array(
		'slug'        => 'regular-bikes',
		'name'        => 'אופניים רגילים',
		'was'         => null,
		'description' => 'אופני עיר והרים ללא מנוע.',
	),
	array(
		'slug'        => 'e-bikes',
		'name'        => 'אופניים חשמליים',
		'was'         => 'bikes',
		'description' => 'אופניים עם מנוע עזר, מוגבלים ל־25 קמ״ש לפי התקן הישראלי.',
	),
	array(
		'slug'        => 'scooters',
		'name'        => 'קורקינטים חשמליים',
		'was'         => null,
		'description' => 'קורקינטים מתקפלים, מוגבלים ל־25 קמ״ש לפי התקן הישראלי.',
	),
	array(
		'slug'        => 'accessories',
		'name'        => 'אביזרים נלווים',
		'was'         => null,
		'description' => 'קסדות, מנעולים, תאורה וכל מה שנוסע יחד עם הכלי.',
	),
	array(
		'slug'        => 'parts',
		'name'        => 'חלפים',
		'was'         => null,
		'description' => 'צמיגים, פנימיות, בלמים, סוללות ומה שהמעבדה מחליפה.',
	),
);

$ec_dry_run = in_array( 'dry-run', isset( $args ) && is_array( $args ) ? $args : array(), true );

WP_CLI::log( '' );

if ( $ec_dry_run ) {
	WP_CLI::warning( 'DRY RUN — nothing will be written.' );
}

WP_CLI::log( sprintf( '  %-16s %-22s %-8s %s', 'slug', 'name', 'products', 'action' ) );
WP_CLI::log( str_repeat( '-', 74 ) );

$ec_created = 0;
$ec_renamed = 0;
$ec_intact  = 0;

foreach ( EC_DEPARTMENTS as $ec_order => $ec_dept ) {
	$ec_term   = get_term_by( 'slug', $ec_dept['slug'], 'product_cat' );
	$ec_action = '';

	// The category may still be under its previous slug.
	if ( ! $ec_term && null !== $ec_dept['was'] ) {
		$ec_old = get_term_by( 'slug', $ec_dept['was'], 'product_cat' );

		if ( $ec_old ) {
			$ec_action = sprintf( 'rename from %s', $ec_dept['was'] );
			++$ec_renamed;

			if ( ! $ec_dry_run ) {
				wp_update_term(
					$ec_old->term_id,
					'product_cat',
					array(
						'slug'        => $ec_dept['slug'],
						'name'        => $ec_dept['name'],
						'description' => $ec_dept['description'],
					)
				);
			}

			$ec_term = $ec_old;
		}
	}

	if ( ! $ec_term ) {
		$ec_action = 'create';
		++$ec_created;

		if ( ! $ec_dry_run ) {
			$ec_new = wp_insert_term(
				$ec_dept['name'],
				'product_cat',
				array(
					'slug'        => $ec_dept['slug'],
					'description' => $ec_dept['description'],
				)
			);

			if ( is_wp_error( $ec_new ) ) {
				WP_CLI::error( sprintf( '%s: %s', $ec_dept['slug'], $ec_new->get_error_message() ) );
			}

			$ec_term = get_term( $ec_new['term_id'], 'product_cat' );
		}
	} elseif ( '' === $ec_action ) {
		/*
		 * Already under the right slug — but that is not the same as being
		 * right. `scooters` predated this script and had no description, so
		 * the first version skipped it entirely and its card on the homepage
		 * rendered a department with no blurb beside four that had one.
		 *
		 * Idempotent has to mean "ends up correct", not "does nothing when it
		 * recognises the slug".
		 */
		$ec_stale = $ec_term->name !== $ec_dept['name']
			|| $ec_term->description !== $ec_dept['description'];

		if ( $ec_stale ) {
			$ec_action = 'sync name and description';
			++$ec_renamed;

			if ( ! $ec_dry_run ) {
				wp_update_term(
					$ec_term->term_id,
					'product_cat',
					array(
						'name'        => $ec_dept['name'],
						'description' => $ec_dept['description'],
					)
				);
			}
		} else {
			$ec_action = 'already correct';
			++$ec_intact;
		}
	}

	// Display order, so the navigation and the shop agree on the sequence.
	if ( ! $ec_dry_run && $ec_term instanceof WP_Term ) {
		update_term_meta( $ec_term->term_id, 'order', $ec_order + 1 );
	}

	$ec_count = $ec_term instanceof WP_Term ? (int) get_term( $ec_term->term_id, 'product_cat' )->count : 0;

	WP_CLI::log( sprintf( '  %-16s %-22s %-8s %s', $ec_dept['slug'], $ec_dept['name'], $ec_count, $ec_action ) );
}

WP_CLI::log( str_repeat( '-', 74 ) );
WP_CLI::log( sprintf( 'created %d · renamed %d · already correct %d', $ec_created, $ec_renamed, $ec_intact ) );

WP_CLI::success(
	$ec_dry_run
		? 'Dry run complete.'
		: 'Five departments. Three of them are empty, and the site says so rather than pretending.'
);
