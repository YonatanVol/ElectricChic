<?php
/**
 * Replace the placeholder drawings with Cortez's real product photography.
 *
 *   ./scripts/wp eval-file scripts/import-cortez-images.php
 *   ./scripts/wp eval-file scripts/import-cortez-images.php dry-run
 *
 * WHY THIS EXISTS
 *
 * The demo drew its own product art: a line drawing of a bicycle, used on all
 * twenty-four products including the eleven scooters. It read as unfinished,
 * and on a page going to the importer it read as careless.
 *
 * The gap between this site and a good one was never the palette. Cowboy's
 * home page carries 116 images and 11 videos; ours carried a geometric sketch.
 * No amount of typography closes that.
 *
 * RIGHTS — READ THIS BEFORE RE-RUNNING
 *
 * These are Cortez's photographs. Yonatan chose to use them for the demo, and
 * the reasoning is defensible: Electric Chic is an authorised Cortez dealer,
 * and the demo's audience is Cortez themselves. That is his call to make, not
 * mine, so it is recorded in the data rather than left in a conversation:
 * every imported attachment carries `_ec_image_rights = pending` and the
 * source URL it came from.
 *
 * The correct long-term route is Cortez's official dealer asset pack — higher
 * resolution, no ambiguity, free, and already question 1 in the message
 * prepared for Eli. When it arrives, `_ec_image_source` identifies exactly
 * which images were imported here so they can be swapped without touching
 * anything a human uploaded.
 *
 * ORDER MATTERS
 *
 * The theme registers ec_hero / ec_editorial / ec_card in functions.php.
 * WordPress generates crops at upload time and never retroactively, so those
 * sizes must be registered before this runs or every file needs re-importing.
 * The script checks and refuses rather than silently producing a site with no
 * large images.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const EC_CORTEZ_API   = 'https://cortez.co.il/wp-json/wc/store/v1/products?per_page=100';
const EC_IMPORT_FLAG  = '_ec_image_source';
const EC_RIGHTS_FIELD = '_ec_image_rights';
const EC_POLITE_PAUSE = 400000; // Microseconds between downloads.

/*
 * WP-CLI parses anything dash-prefixed as its OWN flag, so `--dry-run` is
 * rejected before the script ever runs. eval-file passes bare positional
 * arguments through in $args, so the switch is spelled without dashes.
 */
$ec_dry_run = in_array( 'dry-run', isset( $args ) && is_array( $args ) ? $args : array(), true );

// ── Refuse to run against a theme that cannot store the result ──────────────
foreach ( array( 'ec_hero', 'ec_editorial', 'ec_card' ) as $ec_size ) {
	if ( ! has_image_size( $ec_size ) ) {
		WP_CLI::error(
			"Image size '{$ec_size}' is not registered. Activate the child theme and re-run — "
			. 'importing first would mean importing everything twice.'
		);
	}
}

WP_CLI::log( '' );
WP_CLI::log( 'Fetching the Cortez catalogue…' );

$ec_response = wp_remote_get(
	EC_CORTEZ_API,
	array(
		'timeout'    => 30,
		'user-agent' => 'ElectricChic dealer site (authorised Cortez reseller)',
	)
);

if ( is_wp_error( $ec_response ) ) {
	WP_CLI::error( 'Could not reach cortez.co.il: ' . $ec_response->get_error_message() );
}

$ec_remote = json_decode( (string) wp_remote_retrieve_body( $ec_response ), true );

if ( ! is_array( $ec_remote ) || array() === $ec_remote ) {
	WP_CLI::error( 'The catalogue response was empty or unreadable.' );
}

/*
 * Index the remote catalogue by permalink.
 *
 * seed-cortez-catalogue.php stored that permalink on each product as
 * _ec_source_url, so matching is exact. Matching on title would be guesswork —
 * the names were deliberately shortened when the catalogue was seeded
 * ("Cortez SPOT אופניים חשמליים מתקפלים…" became "Cortez SPOT"), so a title
 * comparison would miss almost everything.
 */
$ec_by_url = array();

foreach ( $ec_remote as $ec_item ) {
	if ( ! empty( $ec_item['permalink'] ) && ! empty( $ec_item['images'][0]['src'] ) ) {
		$ec_by_url[ $ec_item['permalink'] ] = $ec_item['images'][0]['src'];
	}
}

WP_CLI::log( sprintf( 'Remote catalogue: %d products with a photograph.', count( $ec_by_url ) ) );
WP_CLI::log( '' );

if ( $ec_dry_run ) {
	WP_CLI::warning( 'DRY RUN — nothing will be downloaded or changed.' );
	WP_CLI::log( '' );
}

$ec_products = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) );

WP_CLI::log( sprintf( '  %-26s %-9s %s', 'product', 'result', 'detail' ) );
WP_CLI::log( str_repeat( '-', 92 ) );

$ec_done    = 0;
$ec_skipped = 0;
$ec_failed  = 0;

foreach ( $ec_products as $ec_product ) {
	$ec_name   = $ec_product->get_name();
	$ec_source = (string) $ec_product->get_meta( '_ec_source_url', true );

	if ( '' === $ec_source || ! isset( $ec_by_url[ $ec_source ] ) ) {
		++$ec_skipped;
		WP_CLI::log( sprintf( '  %-26s %-9s %s', mb_substr( $ec_name, 0, 26 ), 'skip', 'no matching source URL' ) );
		continue;
	}

	$ec_image_url = $ec_by_url[ $ec_source ];

	// Already imported from this exact URL: leave it alone so re-runs are cheap
	// and so a photograph a human replaced by hand is never clobbered.
	$ec_existing = (int) $ec_product->get_image_id();

	if ( $ec_existing > 0 && get_post_meta( $ec_existing, EC_IMPORT_FLAG, true ) === $ec_image_url ) {
		++$ec_skipped;
		WP_CLI::log( sprintf( '  %-26s %-9s %s', mb_substr( $ec_name, 0, 26 ), 'have', 'already imported' ) );
		continue;
	}

	if ( $ec_dry_run ) {
		++$ec_done;
		WP_CLI::log( sprintf( '  %-26s %-9s %s', mb_substr( $ec_name, 0, 26 ), 'would', basename( wp_parse_url( $ec_image_url, PHP_URL_PATH ) ?? '' ) ) );
		continue;
	}

	$ec_tmp = download_url( $ec_image_url, 45 );

	if ( is_wp_error( $ec_tmp ) ) {
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %-9s %s', mb_substr( $ec_name, 0, 26 ), 'FAILED', $ec_tmp->get_error_message() ) );
		continue;
	}

	$ec_file = array(
		'name'     => sanitize_file_name( $ec_product->get_sku() . '-' . basename( wp_parse_url( $ec_image_url, PHP_URL_PATH ) ?? 'cortez.jpg' ) ),
		'tmp_name' => $ec_tmp,
	);

	$ec_attachment_id = media_handle_sideload( $ec_file, $ec_product->get_id(), $ec_name );

	if ( is_wp_error( $ec_attachment_id ) ) {
		// media_handle_sideload deletes the temp file on success only.
		if ( file_exists( $ec_tmp ) ) {
			wp_delete_file( $ec_tmp );
		}

		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %-9s %s', mb_substr( $ec_name, 0, 26 ), 'FAILED', $ec_attachment_id->get_error_message() ) );
		continue;
	}

	// Provenance lives on the ATTACHMENT, not only the product: an image can be
	// reused, and the question "where did this file come from" has to survive
	// being detached from the product that first imported it.
	update_post_meta( $ec_attachment_id, EC_IMPORT_FLAG, $ec_image_url );
	update_post_meta( $ec_attachment_id, EC_RIGHTS_FIELD, 'pending' );
	update_post_meta( $ec_attachment_id, '_ec_image_imported_at', gmdate( 'c' ) );

	// Remove the placeholder drawing so the media library does not fill with
	// art nobody will ever use again.
	if ( $ec_existing > 0 && '' !== (string) get_post_meta( $ec_existing, '_ec_placeholder', true ) ) {
		wp_delete_attachment( $ec_existing, true );
	}

	$ec_product->set_image_id( $ec_attachment_id );
	$ec_product->update_meta_data( EC_RIGHTS_FIELD, 'pending' );
	$ec_product->save();

	++$ec_done;

	$ec_meta = wp_get_attachment_metadata( $ec_attachment_id );
	$ec_dims = isset( $ec_meta['width'] ) ? $ec_meta['width'] . '×' . $ec_meta['height'] : '?';
	$ec_crops = isset( $ec_meta['sizes'] ) ? count( $ec_meta['sizes'] ) : 0;

	WP_CLI::log( sprintf( '  %-26s %-9s %s, %d crops', mb_substr( $ec_name, 0, 26 ), 'imported', $ec_dims, $ec_crops ) );

	// This is a business partner's server, not a scraping target.
	usleep( EC_POLITE_PAUSE );
}

WP_CLI::log( str_repeat( '-', 92 ) );
WP_CLI::log( sprintf( 'imported %d · already had %d · failed %d', $ec_done, $ec_skipped, $ec_failed ) );
WP_CLI::log( '' );

if ( $ec_failed > 0 ) {
	WP_CLI::error( sprintf( '%d image(s) failed. The catalogue is now inconsistent — investigate before showing anyone.', $ec_failed ) );
}

if ( ! $ec_dry_run ) {
	WP_CLI::warning( 'Every imported image is Cortez\'s, recorded as _ec_image_rights = pending.' );
	WP_CLI::warning( 'Request the official dealer asset pack; _ec_image_source identifies exactly what to replace.' );
}

WP_CLI::success( 'Done.' );
