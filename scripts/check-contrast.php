<?php
/**
 * Fail if any text colour in the design system is unreadable on its ground.
 *
 *   ./scripts/composer contrast
 *
 * WHY THIS EXISTS
 *
 * The palette swap from cream to near-black remapped tokens mechanically. One
 * of them, --ec-sand, was a border colour on a light ground and became
 * --ec-line, a border colour on a dark one. Correct as a border. As footer text
 * it measured 1.74:1 — invisible to a lot of people, and it shipped.
 *
 * A manual contrast pass ran on that same change and missed it, because it
 * checked the pairs that were *designed* rather than every pair the CSS
 * actually produces. Reviewing your own intent is not the same as reviewing the
 * result, so this reads the stylesheet instead of the plan.
 *
 * WCAG 2.2 AA: 4.5:1 for normal text, 3:1 for large text and UI components.
 * Anything below 3:1 is treated as an error regardless of size, because no
 * legitimate text sits there.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

const EC_CSS      = __DIR__ . '/../wp-content/themes/electricchic-child/assets/css/design-system.css';
const EC_MIN_TEXT = 4.5;
const EC_MIN_UI   = 3.0;

/**
 * Relative luminance of a hex colour, per WCAG.
 *
 * @param string $hex Hex colour, with or without a leading hash.
 * @return float
 */
function ec_luminance( string $hex ): float {
	$hex = ltrim( $hex, '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	$channels = array();

	foreach ( array( 0, 2, 4 ) as $offset ) {
		$value      = hexdec( substr( $hex, $offset, 2 ) ) / 255;
		$channels[] = $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
	}

	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Contrast ratio between two hex colours.
 *
 * @param string $a First colour.
 * @param string $b Second colour.
 * @return float
 */
function ec_ratio( string $a, string $b ): float {
	$x = ec_luminance( $a );
	$y = ec_luminance( $b );

	return round( ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ), 2 );
}

// ---------------------------------------------------------------------------

if ( ! is_readable( EC_CSS ) ) {
	fwrite( STDERR, "Stylesheet not found: " . EC_CSS . "\n" );
	exit( 2 );
}

$css = (string) file_get_contents( EC_CSS );

// Token definitions from :root.
preg_match_all( '/--(ec-[a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8})\s*;/', $css, $matches, PREG_SET_ORDER );

$tokens = array();

foreach ( $matches as $match ) {
	$tokens[ $match[1] ] = $match[2];
}

if ( array() === $tokens ) {
	fwrite( STDERR, "No colour tokens found — has the :root block moved?\n" );
	exit( 2 );
}

/*
 * Resolve semantic aliases.
 *
 * Tokens like --ec-muted are defined as var(--ec-grey) rather than a hex value,
 * so they never matched the pattern above and were skipped silently. That is a
 * blind spot precisely where it matters: the semantic names are the ones
 * components actually use. Two passes covers the alias depth in this file.
 */
preg_match_all( '/--(ec-[a-z0-9-]+):\s*var\(\s*--(ec-[a-z0-9-]+)\s*\)\s*;/', $css, $alias_matches, PREG_SET_ORDER );

for ( $pass = 0; $pass < 2; $pass++ ) {
	foreach ( $alias_matches as $alias ) {
		if ( isset( $tokens[ $alias[2] ] ) && ! isset( $tokens[ $alias[1] ] ) ) {
			$tokens[ $alias[1] ] = $tokens[ $alias[2] ];
		}
	}
}

/**
 * Backgrounds text can land on. Availability badges carry their own ground, so
 * they are checked against it rather than against the page.
 */
$grounds = array(
	'ec-black'     => $tokens['ec-black'] ?? null,
	'ec-surface-1' => $tokens['ec-surface-1'] ?? null,
	'ec-surface-2' => $tokens['ec-surface-2'] ?? null,
	// Lime is a ground too: buttons and the sale badge are black text on lime.
	// Omitting it made the checker report a correct pairing as a failure.
	'ec-lime'      => $tokens['ec-lime'] ?? null,
);

$paired = array(
	'ec-avail-stock'    => 'ec-avail-stock-bg',
	'ec-avail-supplier' => 'ec-avail-supplier-bg',
	'ec-avail-confirm'  => 'ec-avail-confirm-bg',
	'ec-avail-out'      => 'ec-avail-out-bg',
);

// Every token actually used in a `color:` declaration.
preg_match_all( '/(?<!-)color:\s*var\(\s*--(ec-[a-z0-9-]+)\s*\)/', $css, $used, PREG_SET_ORDER );

$text_tokens = array_values( array_unique( array_map( static fn( $m ) => $m[1], $used ) ) );
sort( $text_tokens );

$failures = 0;

echo "Contrast audit — every token used as text, against every ground\n";
echo str_repeat( '-', 72 ) . "\n";

foreach ( $text_tokens as $token ) {
	if ( ! isset( $tokens[ $token ] ) ) {
		continue;
	}

	// Badge foregrounds are only ever shown on their own background.
	if ( isset( $paired[ $token ], $tokens[ $paired[ $token ] ] ) ) {
		$ratio  = ec_ratio( $tokens[ $token ], $tokens[ $paired[ $token ] ] );
		$passes = $ratio >= EC_MIN_TEXT;

		printf( "  %-22s on %-20s %6.2f:1  %s\n", $token, $paired[ $token ], $ratio, $passes ? 'pass' : 'FAIL' );

		if ( ! $passes ) {
			++$failures;
		}

		continue;
	}

	$best = 0.0;

	foreach ( $grounds as $ground_name => $ground_hex ) {
		if ( null === $ground_hex ) {
			continue;
		}

		$ratio = ec_ratio( $tokens[ $token ], $ground_hex );
		$best  = max( $best, $ratio );

		$verdict = $ratio >= EC_MIN_TEXT ? 'pass' : ( $ratio >= EC_MIN_UI ? 'large/UI only' : 'FAIL' );
		printf( "  %-22s on %-20s %6.2f:1  %s\n", $token, $ground_name, $ratio, $verdict );
	}

	// A token that fails on every ground is being used as text when it should
	// not be — the footer bug, exactly.
	if ( $best < EC_MIN_UI ) {
		++$failures;
	}
}

echo str_repeat( '-', 72 ) . "\n";

if ( $failures > 0 ) {
	printf( "FAILED — %d colour(s) unreadable on every available ground.\n", $failures );
	echo "A border token is not a text token. Use --ec-muted or lighter for text.\n";
	exit( 1 );
}

echo "PASSED — every colour used as text is readable on at least one ground,\n";
echo "and every availability badge passes 4.5:1 against its own background.\n";
exit( 0 );
