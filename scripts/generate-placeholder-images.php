<?php
/**
 * Generate tasteful placeholder images for demo products.
 *
 *   ./scripts/wp eval-file scripts/generate-placeholder-images.php
 *
 * DEMO ONLY. Real photography is the client's to supply and is explicitly out of
 * scope. These exist so the demo reads as "photography pending" rather than
 * "broken", which is the difference between a client seeing a design and a
 * client seeing a bug.
 *
 * Drawn rather than downloaded: no licensing question, no external request, and
 * the palette matches the design system exactly.
 *
 * No declare(strict_types=1) — WP-CLI's eval-file runs this through eval().
 *
 * @package ElectricChic
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 2 );
}

if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	fwrite( STDERR, "PHP GD extension is required.\n" );
	exit( 2 );
}

/**
 * Draw a bicycle-wheel motif: two rings and a suggestion of a frame.
 *
 * Abstract on purpose. A literal illustration would compete with the real
 * photography that replaces it; a motif simply holds the space.
 *
 * @param int $index Product index, used to vary the composition.
 * @return string Path to the written PNG.
 */
function ec_draw_placeholder( int $index ): string {
	// Defined locally rather than as globals: WP-CLI's eval-file runs this
	// through eval(), so top-level variables never reach true global scope and
	// `global` would bind null.
	$palette = array(
		array( 0xf3, 0xf0, 0xeb ), // ivory
		array( 0xe8, 0xe3, 0xdc ), // sand
		array( 0xef, 0xea, 0xe2 ), // between the two
	);

	$ec_stroke = array( 0xa6, 0x9f, 0x95 ); // stone
	$ec_accent = array( 0xb8, 0x97, 0x6a ); // brass

	$width  = 1200;
	$height = 900;

	$image = imagecreatetruecolor( $width, $height );
	imageantialias( $image, true );

	$bg_rgb = $palette[ $index % count( $palette ) ];
	$bg     = imagecolorallocate( $image, $bg_rgb[0], $bg_rgb[1], $bg_rgb[2] );
	imagefilledrectangle( $image, 0, 0, $width, $height, $bg );

	$stroke = imagecolorallocatealpha( $image, $ec_stroke[0], $ec_stroke[1], $ec_stroke[2], 75 );
	$accent = imagecolorallocatealpha( $image, $ec_accent[0], $ec_accent[1], $ec_accent[2], 60 );

	imagesetthickness( $image, 3 );

	$radius     = 210;
	$centre_y   = (int) ( $height * 0.56 );
	$left_x     = (int) ( $width * 0.34 );
	$right_x    = (int) ( $width * 0.66 );

	// Wheels.
	imageellipse( $image, $left_x, $centre_y, $radius * 2, $radius * 2, $stroke );
	imageellipse( $image, $right_x, $centre_y, $radius * 2, $radius * 2, $stroke );

	// Hubs.
	imagefilledellipse( $image, $left_x, $centre_y, 14, 14, $stroke );
	imagefilledellipse( $image, $right_x, $centre_y, 14, 14, $stroke );

	// Frame: a simple triangle between the hubs, in brass.
	imagesetthickness( $image, 4 );
	$apex_x = (int) ( ( $left_x + $right_x ) / 2 );
	$apex_y = $centre_y - 190;

	imageline( $image, $left_x, $centre_y, $apex_x, $apex_y, $accent );
	imageline( $image, $apex_x, $apex_y, $right_x, $centre_y, $accent );
	imageline( $image, $left_x, $centre_y, $right_x, $centre_y, $accent );

	// Handlebar and seat suggestions.
	imageline( $image, $right_x, $centre_y, $right_x + 40, $apex_y - 30, $accent );
	imageline( $image, $apex_x, $apex_y, $apex_x - 60, $apex_y - 20, $accent );

	$path = get_temp_dir() . 'ec-placeholder-' . $index . '.png';
	imagepng( $image, $path, 8 );
	imagedestroy( $image );

	return $path;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$ec_products = wc_get_products(
	array(
		'limit'  => -1,
		'status' => 'publish',
	)
);

$ec_attached = 0;
$ec_skipped  = 0;

foreach ( $ec_products as $ec_index => $ec_product ) {
	if ( $ec_product->get_image_id() ) {
		++$ec_skipped;
		continue;
	}

	$ec_file = ec_draw_placeholder( (int) $ec_index );

	$ec_attachment_id = media_handle_sideload(
		array(
			'name'     => sprintf( 'electricchic-%s.png', $ec_product->get_sku() ),
			'tmp_name' => $ec_file,
		),
		0,
		$ec_product->get_name()
	);

	if ( is_wp_error( $ec_attachment_id ) ) {
		printf( "  ! %s: %s\n", $ec_product->get_sku(), $ec_attachment_id->get_error_message() );
		continue;
	}

	// Alt text matters for accessibility and is required by the product
	// completeness gate, so it is set here rather than left for later.
	update_post_meta( $ec_attachment_id, '_wp_attachment_image_alt', $ec_product->get_name() );

	$ec_product->set_image_id( $ec_attachment_id );
	$ec_product->save();

	++$ec_attached;
}

printf( "Images: %d attached, %d already had one.\n", $ec_attached, $ec_skipped );
