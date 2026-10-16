<?php
/**
 * Crop the transparent margin off each cut-out so the product fills its frame.
 *
 *   ./scripts/wp eval-file scripts/trim-product-images.php
 *   ./scripts/wp eval-file scripts/trim-product-images.php dry-run
 *
 * WHY THIS EXISTS
 *
 * Removing the white backdrop left every product floating inside a box of
 * empty pixels, and the size of that emptiness is different in every file —
 * measured across the catalogue it runs from 0% to 14% per edge, with no
 * pattern.
 *
 * That makes a convincing shadow impossible. A contact shadow has to sit
 * exactly where the wheels meet the ground, and CSS can only position it
 * relative to the image box. With an unknown gap between the wheels and the
 * bottom of that box, the shadow floats in mid-air — which is precisely what
 * happened on the first attempt: the "staged" product and the flat
 * drop-shadow version were indistinguishable, because the staging was landing
 * below the product rather than under it.
 *
 * Trimming to the opaque bounding box makes the bottom edge of the file the
 * contact point. After this, `bottom: 0` means the ground.
 *
 * It also stops the layout lying about scale: a product with a 14% margin
 * rendered 14% smaller than its neighbour at the same CSS width, which is why
 * the earlier grids looked uneven for no visible reason.
 *
 * A small margin is deliberately kept so that soft shadows and hover
 * transforms have somewhere to go without being clipped.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/image.php';

/** Alpha below this counts as opaque. GD alpha runs 0 (solid) to 127 (clear). */
const EC_OPAQUE_MAX = 100;

/** Breathing room kept around the product, as a share of the trimmed size. */
const EC_MARGIN = 0.02;

const EC_TRIM_FLAG = '_ec_trimmed';

$ec_dry_run = in_array( 'dry-run', isset( $args ) && is_array( $args ) ? $args : array(), true );

/**
 * Find the opaque bounding box of a transparent PNG.
 *
 * Sampled every other pixel. At these dimensions that is four times faster and
 * cannot move an edge by more than one pixel, which no shadow will reveal.
 *
 * @param \GdImage $img Image.
 * @return array{0:int,1:int,2:int,3:int}|null minx, miny, maxx, maxy.
 */
function ec_opaque_bounds( $img ): ?array {
	$w = imagesx( $img );
	$h = imagesy( $img );

	$minx = $w;
	$miny = $h;
	$maxx = -1;
	$maxy = -1;

	for ( $y = 0; $y < $h; $y += 2 ) {
		for ( $x = 0; $x < $w; $x += 2 ) {
			if ( ( ( imagecolorat( $img, $x, $y ) >> 24 ) & 0x7F ) < EC_OPAQUE_MAX ) {
				if ( $x < $minx ) {
					$minx = $x;
				}
				if ( $x > $maxx ) {
					$maxx = $x;
				}
				if ( $y < $miny ) {
					$miny = $y;
				}
				if ( $y > $maxy ) {
					$maxy = $y;
				}
			}
		}
	}

	return $maxx < 0 ? null : array( $minx, $miny, $maxx, $maxy );
}

// ---------------------------------------------------------------------------

WP_CLI::log( '' );

if ( $ec_dry_run ) {
	WP_CLI::warning( 'DRY RUN — nothing will be written.' );
}

WP_CLI::log( sprintf( '  %-26s %-13s %-13s %s', 'product', 'before', 'after', 'trimmed' ) );
WP_CLI::log( str_repeat( '-', 74 ) );

$ec_done   = 0;
$ec_skip   = 0;
$ec_failed = 0;

foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $ec_product ) {
	$ec_id   = (int) $ec_product->get_image_id();
	$ec_name = mb_substr( $ec_product->get_name(), 0, 26 );

	if ( $ec_id <= 0 ) {
		++$ec_skip;
		continue;
	}

	if ( 'yes' === get_post_meta( $ec_id, EC_TRIM_FLAG, true ) ) {
		++$ec_skip;
		WP_CLI::log( sprintf( '  %-26s %-13s %-13s %s', $ec_name, '', '', 'already trimmed' ) );
		continue;
	}

	$ec_path = get_attached_file( $ec_id );

	if ( ! $ec_path || ! file_exists( $ec_path ) || ! str_ends_with( strtolower( $ec_path ), '.png' ) ) {
		++$ec_skip;
		continue;
	}

	$ec_img = @imagecreatefrompng( $ec_path );

	if ( ! $ec_img ) {
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %-13s %-13s %s', $ec_name, '', '', 'unreadable' ) );
		continue;
	}

	$ec_w      = imagesx( $ec_img );
	$ec_h      = imagesy( $ec_img );
	$ec_bounds = ec_opaque_bounds( $ec_img );

	if ( null === $ec_bounds ) {
		imagedestroy( $ec_img );
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %-13s %-13s %s', $ec_name, "{$ec_w}×{$ec_h}", '', 'fully transparent' ) );
		continue;
	}

	list( $ec_minx, $ec_miny, $ec_maxx, $ec_maxy ) = $ec_bounds;

	$ec_cw = $ec_maxx - $ec_minx + 1;
	$ec_ch = $ec_maxy - $ec_miny + 1;

	// Breathing room, so a blurred shadow or a hover lift is not clipped.
	$ec_pad  = (int) round( max( $ec_cw, $ec_ch ) * EC_MARGIN );
	$ec_minx = max( 0, $ec_minx - $ec_pad );
	$ec_miny = max( 0, $ec_miny - $ec_pad );
	$ec_cw   = min( $ec_w - $ec_minx, $ec_cw + $ec_pad * 2 );
	$ec_ch   = min( $ec_h - $ec_miny, $ec_ch + $ec_pad * 2 );

	$ec_saved = 100 - (int) round( 100 * ( $ec_cw * $ec_ch ) / ( $ec_w * $ec_h ) );

	if ( $ec_dry_run ) {
		imagedestroy( $ec_img );
		++$ec_done;
		WP_CLI::log( sprintf( '  %-26s %-13s %-13s %s', $ec_name, "{$ec_w}×{$ec_h}", "{$ec_cw}×{$ec_ch}", "would save {$ec_saved}%" ) );
		continue;
	}

	$ec_out = imagecreatetruecolor( $ec_cw, $ec_ch );
	imagealphablending( $ec_out, false );
	imagesavealpha( $ec_out, true );
	imagefill( $ec_out, 0, 0, 0x7F000000 );
	imagecopy( $ec_out, $ec_img, 0, 0, $ec_minx, $ec_miny, $ec_cw, $ec_ch );
	imagepng( $ec_out, $ec_path, 6 );

	imagedestroy( $ec_img );
	imagedestroy( $ec_out );

	// Every derived crop was cut from the untrimmed file and still carries the
	// empty margin, so they all have to be rebuilt.
	wp_update_attachment_metadata( $ec_id, wp_generate_attachment_metadata( $ec_id, $ec_path ) );
	update_post_meta( $ec_id, EC_TRIM_FLAG, 'yes' );

	++$ec_done;
	WP_CLI::log( sprintf( '  %-26s %-13s %-13s %s', $ec_name, "{$ec_w}×{$ec_h}", "{$ec_cw}×{$ec_ch}", "−{$ec_saved}%" ) );
}

WP_CLI::log( str_repeat( '-', 74 ) );
WP_CLI::log( sprintf( 'trimmed %d · skipped %d · failed %d', $ec_done, $ec_skip, $ec_failed ) );

if ( $ec_failed > 0 ) {
	WP_CLI::error( sprintf( '%d image(s) failed.', $ec_failed ) );
}

WP_CLI::success( 'The bottom edge of every file is now the ground.' );
