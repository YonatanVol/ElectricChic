<?php
/**
 * Turn the white studio backdrop on product photography transparent.
 *
 *   ./scripts/wp eval-file scripts/cutout-product-images.php
 *   ./scripts/wp eval-file scripts/cutout-product-images.php dry-run
 *
 * WHY THIS EXISTS
 *
 * Cortez's photographs are cut-outs on pure white. That is right for their own
 * site, which is white, and it quietly dictates ours: on any dark ground each
 * product arrives inside a white rectangle, and forcing a 4:3 crop on the four
 * portrait shots slices the handlebars off the top and the wheel off the
 * bottom.
 *
 * Both problems have the same root — the background is baked into the pixels.
 * Once it is transparent the product sits on any colour, at any aspect ratio,
 * with no crop damage, and can bleed off the edge of a layout. That is the
 * difference between a catalogue and an editorial page.
 *
 * HOW, AND WHY NOT THE OBVIOUS WAY
 *
 * The obvious approach — "every near-white pixel becomes transparent" — eats
 * the product. These machines have white decals, chrome forks, lit headlamp
 * housings and specular highlights that read as 255,255,255.
 *
 * So the fill runs from the frame EDGE inward, and only reaches background
 * that is actually connected to the edge. A second pass then clears white
 * regions trapped inside the product — the gap between the stem and the brake
 * cable, the space beside the headlight — but only when a region is both
 * near-pure white AND large enough to be backdrop rather than a highlight.
 *
 * REVERSIBLE
 *
 * The original file is replaced rather than duplicated, because uploads/ is
 * packaged and shipped to the server by export-site.sh and doubling it would
 * double every deploy. Reversal costs one command: every attachment still
 * carries `_ec_image_source`, so import-cortez-images.php re-downloads the
 * untouched original.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * How far from pure white still counts as backdrop.
 *
 * 22 was chosen against the real files: high enough to absorb JPEG ringing
 * around the product edge, low enough to leave a lit headlamp alone.
 */
const EC_WHITE_TOLERANCE = 22;

const EC_CUTOUT_FLAG = '_ec_cutout';

$ec_dry_run = in_array( 'dry-run', isset( $args ) && is_array( $args ) ? $args : array(), true );

/**
 * Whether a colour is close enough to white to be backdrop.
 *
 * @param int $rgb Packed colour.
 * @return bool
 */
function ec_is_backdrop( int $rgb ): bool {
	$r = ( $rgb >> 16 ) & 0xFF;
	$g = ( $rgb >> 8 ) & 0xFF;
	$b = $rgb & 0xFF;

	return $r >= 255 - EC_WHITE_TOLERANCE
		&& $g >= 255 - EC_WHITE_TOLERANCE
		&& $b >= 255 - EC_WHITE_TOLERANCE;
}

/**
 * Replace the white backdrop with transparency.
 *
 * @param string $path Image file, modified in place via $out.
 * @param string $out  Destination PNG.
 * @return array{cleared: float, w: int, h: int}|null
 */
function ec_cutout( string $path, string $out ): ?array {
	$type = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	$src  = match ( $type ) {
		'png'          => @imagecreatefrompng( $path ),
		'jpg', 'jpeg'  => @imagecreatefromjpeg( $path ),
		'webp'         => @imagecreatefromwebp( $path ),
		default        => false,
	};

	if ( ! $src ) {
		return null;
	}

	$w = imagesx( $src );
	$h = imagesy( $src );

	$img = imagecreatetruecolor( $w, $h );
	imagealphablending( $img, false );
	imagesavealpha( $img, true );
	imagecopy( $img, $src, 0, 0, 0, 0, $w, $h );
	imagedestroy( $src );

	$transparent = 0x7F000000;
	$seen        = array_fill( 0, $w * $h, false );
	$cleared     = 0;

	// Pass 1 — backdrop reachable from the frame edge.
	$stack = array();

	for ( $x = 0; $x < $w; $x++ ) {
		$stack[] = array( $x, 0 );
		$stack[] = array( $x, $h - 1 );
	}

	for ( $y = 0; $y < $h; $y++ ) {
		$stack[] = array( 0, $y );
		$stack[] = array( $w - 1, $y );
	}

	while ( $stack ) {
		list( $x, $y ) = array_pop( $stack );

		if ( $x < 0 || $y < 0 || $x >= $w || $y >= $h ) {
			continue;
		}

		$k = $y * $w + $x;

		if ( $seen[ $k ] ) {
			continue;
		}

		$seen[ $k ] = true;

		if ( ! ec_is_backdrop( imagecolorat( $img, $x, $y ) & 0xFFFFFF ) ) {
			continue;
		}

		imagesetpixel( $img, $x, $y, $transparent );
		++$cleared;

		$stack[] = array( $x + 1, $y );
		$stack[] = array( $x - 1, $y );
		$stack[] = array( $x, $y + 1 );
		$stack[] = array( $x, $y - 1 );
	}

	// Pass 2 — backdrop trapped inside the product.
	$min_region = max( 400, (int) ( $w * $h * 0.00012 ) );

	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$k = $y * $w + $x;

			if ( $seen[ $k ] ) {
				continue;
			}

			$seen[ $k ] = true;

			if ( ! ec_is_backdrop( imagecolorat( $img, $x, $y ) & 0xFFFFFF ) ) {
				continue;
			}

			$region = array( array( $x, $y ) );
			$queue  = array( array( $x, $y ) );

			while ( $queue ) {
				list( $cx, $cy ) = array_pop( $queue );

				foreach ( array( array( 1, 0 ), array( -1, 0 ), array( 0, 1 ), array( 0, -1 ) ) as $step ) {
					$nx = $cx + $step[0];
					$ny = $cy + $step[1];

					if ( $nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h ) {
						continue;
					}

					$nk = $ny * $w + $nx;

					if ( $seen[ $nk ] ) {
						continue;
					}

					$seen[ $nk ] = true;

					if ( ! ec_is_backdrop( imagecolorat( $img, $nx, $ny ) & 0xFFFFFF ) ) {
						continue;
					}

					$region[] = array( $nx, $ny );
					$queue[]  = array( $nx, $ny );
				}
			}

			if ( count( $region ) >= $min_region ) {
				foreach ( $region as $pixel ) {
					imagesetpixel( $img, $pixel[0], $pixel[1], $transparent );
				}

				$cleared += count( $region );
			}
		}
	}

	imagepng( $img, $out, 6 );
	imagedestroy( $img );

	return array(
		'cleared' => 100 * $cleared / ( $w * $h ),
		'w'       => $w,
		'h'       => $h,
	);
}

// ---------------------------------------------------------------------------

WP_CLI::log( '' );

if ( $ec_dry_run ) {
	WP_CLI::warning( 'DRY RUN — nothing will be written.' );
}

WP_CLI::log( sprintf( '  %-26s %-12s %-9s %s', 'product', 'size', 'cleared', 'note' ) );
WP_CLI::log( str_repeat( '-', 76 ) );

$ec_done    = 0;
$ec_skipped = 0;
$ec_failed  = 0;

foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $ec_product ) {
	$ec_id   = (int) $ec_product->get_image_id();
	$ec_name = mb_substr( $ec_product->get_name(), 0, 26 );

	if ( $ec_id <= 0 ) {
		++$ec_skipped;
		continue;
	}

	if ( 'yes' === get_post_meta( $ec_id, EC_CUTOUT_FLAG, true ) ) {
		++$ec_skipped;
		WP_CLI::log( sprintf( '  %-26s %-12s %-9s %s', $ec_name, '', '', 'already cut out' ) );
		continue;
	}

	$ec_path = get_attached_file( $ec_id );

	if ( ! $ec_path || ! file_exists( $ec_path ) ) {
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %-12s %-9s %s', $ec_name, '', '', 'file missing' ) );
		continue;
	}

	if ( $ec_dry_run ) {
		++$ec_done;
		WP_CLI::log( sprintf( '  %-26s %-12s %-9s %s', $ec_name, '', '', 'would cut out' ) );
		continue;
	}

	// Always PNG out — JPEG has no alpha channel.
	$ec_png = preg_replace( '/\.(jpe?g|webp)$/i', '.png', $ec_path );
	$ec_res = ec_cutout( $ec_path, $ec_png );

	if ( null === $ec_res ) {
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %-12s %-9s %s', $ec_name, '', '', 'unreadable format' ) );
		continue;
	}

	// Point the attachment at the PNG when the source was a JPEG, and drop the
	// original so the media library does not carry two copies of every product.
	if ( $ec_png !== $ec_path ) {
		update_attached_file( $ec_id, $ec_png );
		wp_update_post( array( 'ID' => $ec_id, 'post_mime_type' => 'image/png' ) );
		wp_delete_file( $ec_path );
	}

	// Every derived size still holds the old white background, so they all have
	// to be rebuilt from the new file.
	wp_update_attachment_metadata( $ec_id, wp_generate_attachment_metadata( $ec_id, $ec_png ) );
	update_post_meta( $ec_id, EC_CUTOUT_FLAG, 'yes' );

	++$ec_done;

	WP_CLI::log(
		sprintf(
			'  %-26s %-12s %-9s %s',
			$ec_name,
			$ec_res['w'] . '×' . $ec_res['h'],
			number_format( $ec_res['cleared'], 1 ) . '%',
			$ec_res['cleared'] < 20 ? 'LOW — check this one' : ''
		)
	);
}

WP_CLI::log( str_repeat( '-', 76 ) );
WP_CLI::log( sprintf( 'cut out %d · skipped %d · failed %d', $ec_done, $ec_skipped, $ec_failed ) );

if ( $ec_failed > 0 ) {
	WP_CLI::error( sprintf( '%d image(s) failed.', $ec_failed ) );
}

WP_CLI::success( 'Done. Products now sit on any background at any aspect ratio.' );
