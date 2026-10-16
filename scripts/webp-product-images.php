<?php
/**
 * Convert the product cut-outs from PNG to WebP.
 *
 *   ./scripts/wp eval-file scripts/webp-product-images.php dry-run
 *   ./scripts/wp eval-file scripts/webp-product-images.php
 *
 * WHY
 *
 * The cut-outs need an alpha channel, which is why they became PNGs in the
 * first place — a JPEG cannot carry transparency and the whole point of
 * removing the backdrop was that a product can sit on any ground. PNG is the
 * obvious format for that and a bad one for photographs: it is lossless, so a
 * studio shot of a black machine costs about 700KB.
 *
 * WebP carries alpha too, and lossy. Measured on the homepage hero before this
 * script existed:
 *
 *   PNG master           724KB
 *   WebP q80 (GD)         68KB      — 91% smaller
 *   WebP q78 (cwebp)      64KB
 *
 * Compared at 2x on the headlight chrome, the "cortez" decal and the brake
 * cable, the two are indistinguishable. There is no ringing at the cut-out
 * edge, which is the failure this kind of compression usually shows first.
 *
 * GD rather than cwebp: the 4KB cwebp saves is not worth a binary this script
 * would then require on every server it runs on. GD is already what
 * cutout-product-images.php and trim-product-images.php use.
 *
 * REVERSIBLE
 *
 * Every attachment still carries `_ec_image_source`, so
 * import-cortez-images.php re-downloads the untouched original. The old PNG
 * and its derived sizes are deleted rather than kept, because uploads/ is
 * packaged and shipped by export-site.sh and keeping both would double every
 * deploy for no benefit.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Quality.
 *
 * 82 rather than the 80 that was measured, because the measurement was taken
 * on one image and two points of headroom cost about 3KB. Below roughly 70 the
 * cut-out edge starts to show ringing against a light ground.
 */
const EC_WEBP_QUALITY = 82;

const EC_WEBP_FLAG = '_ec_webp';

$ec_dry_run = in_array( 'dry-run', isset( $args ) && is_array( $args ) ? $args : array(), true );

/**
 * Every file WordPress derived from an attachment, master included.
 *
 * @param int $id Attachment ID.
 * @return string[] Absolute paths.
 */
function ec_attachment_files( int $id ): array {
	$master = (string) get_attached_file( $id );

	if ( '' === $master ) {
		return array();
	}

	$files = array( $master );
	$dir   = dirname( $master );
	$meta  = wp_get_attachment_metadata( $id );

	foreach ( $meta['sizes'] ?? array() as $size ) {
		if ( isset( $size['file'] ) ) {
			$files[] = $dir . '/' . $size['file'];
		}
	}

	return $files;
}

// ---------------------------------------------------------------------------

WP_CLI::log( '' );

if ( $ec_dry_run ) {
	WP_CLI::warning( 'DRY RUN — nothing will be written.' );
}

WP_CLI::log( sprintf( '  %-26s %10s %10s %8s  %s', 'product', 'before', 'after', 'saved', 'note' ) );
WP_CLI::log( str_repeat( '-', 78 ) );

$ec_done    = 0;
$ec_skipped = 0;
$ec_failed  = 0;
$ec_before  = 0;
$ec_after   = 0;

foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $ec_product ) {
	$ec_id   = (int) $ec_product->get_image_id();
	$ec_name = mb_substr( $ec_product->get_name(), 0, 26 );

	if ( $ec_id <= 0 ) {
		++$ec_skipped;
		continue;
	}

	if ( 'yes' === get_post_meta( $ec_id, EC_WEBP_FLAG, true ) ) {
		++$ec_skipped;
		WP_CLI::log( sprintf( '  %-26s %10s %10s %8s  %s', $ec_name, '', '', '', 'already webp' ) );
		continue;
	}

	$ec_png = (string) get_attached_file( $ec_id );

	if ( '' === $ec_png || ! file_exists( $ec_png ) || ! str_ends_with( strtolower( $ec_png ), '.png' ) ) {
		++$ec_skipped;
		continue;
	}

	// Every file this attachment currently owns, captured BEFORE conversion so
	// the old derivatives can be removed afterwards rather than orphaned.
	$ec_old   = ec_attachment_files( $ec_id );
	$ec_bytes = array_sum( array_map( static fn( $f ) => file_exists( $f ) ? filesize( $f ) : 0, $ec_old ) );

	$ec_webp = preg_replace( '/\.png$/i', '.webp', $ec_png );

	if ( $ec_dry_run ) {
		++$ec_done;
		$ec_before += $ec_bytes;
		WP_CLI::log(
			sprintf(
				'  %-26s %9sK %10s %8s  %s',
				$ec_name,
				round( $ec_bytes / 1024 ),
				'',
				'',
				'would convert ' . count( $ec_old ) . ' files'
			)
		);
		continue;
	}

	$ec_img = @imagecreatefrompng( $ec_png );

	if ( ! $ec_img ) {
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %10s %10s %8s  %s', $ec_name, '', '', '', 'unreadable' ) );
		continue;
	}

	imagealphablending( $ec_img, false );
	imagesavealpha( $ec_img, true );

	if ( ! imagewebp( $ec_img, $ec_webp, EC_WEBP_QUALITY ) ) {
		imagedestroy( $ec_img );
		++$ec_failed;
		WP_CLI::log( sprintf( '  %-26s %10s %10s %8s  %s', $ec_name, '', '', '', 'encode failed' ) );
		continue;
	}

	imagedestroy( $ec_img );

	update_attached_file( $ec_id, $ec_webp );
	wp_update_post( array( 'ID' => $ec_id, 'post_mime_type' => 'image/webp' ) );

	// Every derived size is still a PNG cut from the old master, so they all
	// have to be rebuilt from the new file.
	wp_update_attachment_metadata( $ec_id, wp_generate_attachment_metadata( $ec_id, $ec_webp ) );

	// Now the old files can go: the attachment no longer refers to any of them.
	foreach ( $ec_old as $ec_stale ) {
		if ( file_exists( $ec_stale ) ) {
			wp_delete_file( $ec_stale );
		}
	}

	update_post_meta( $ec_id, EC_WEBP_FLAG, 'yes' );

	$ec_now     = array_sum( array_map( static fn( $f ) => file_exists( $f ) ? filesize( $f ) : 0, ec_attachment_files( $ec_id ) ) );
	$ec_before += $ec_bytes;
	$ec_after  += $ec_now;
	++$ec_done;

	WP_CLI::log(
		sprintf(
			'  %-26s %9sK %9sK %7s%%  %s',
			$ec_name,
			round( $ec_bytes / 1024 ),
			round( $ec_now / 1024 ),
			$ec_bytes > 0 ? round( 100 - 100 * $ec_now / $ec_bytes ) : 0,
			''
		)
	);
}

WP_CLI::log( str_repeat( '-', 78 ) );
WP_CLI::log( sprintf( 'converted %d · skipped %d · failed %d', $ec_done, $ec_skipped, $ec_failed ) );

if ( $ec_after > 0 ) {
	WP_CLI::log(
		sprintf(
			'uploads for these products: %sK -> %sK (%d%% smaller)',
			round( $ec_before / 1024 ),
			round( $ec_after / 1024 ),
			round( 100 - 100 * $ec_after / $ec_before )
		)
	);
}

if ( $ec_failed > 0 ) {
	WP_CLI::error( sprintf( '%d image(s) failed.', $ec_failed ) );
}

WP_CLI::success( $ec_dry_run ? 'Dry run complete.' : 'Transparency kept, weight gone.' );
