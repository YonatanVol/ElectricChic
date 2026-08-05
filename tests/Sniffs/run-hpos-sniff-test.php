<?php
/**
 * Self-test for the HPOS sniff.
 *
 * Runs PHPCS with only the project's HPOS sniff against two fixtures and checks
 * the result against expectations:
 *
 *   violations.php  must produce exactly the errors listed below
 *   compliant.php   must produce none
 *
 * Both halves matter. A sniff that only proves it fires is half-tested — false
 * positives are what make people disable a rule, and a disabled rule enforces
 * nothing.
 *
 * Run:  ./scripts/composer sniff:selftest
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

$root     = dirname( __DIR__, 2 );
$phpcs    = $root . '/vendor/bin/phpcs';
$standard = $root . '/tools/phpcs/ElectricChic';
$fixtures = $root . '/tests/fixtures/hpos';

/**
 * Expected findings per fixture: error code => occurrences.
 */
const EXPECTED = array(
	'violations.php' => array(
		'ElectricChic.HPOS.NoDirectOrderMeta.PostMeta'   => 8,
		'ElectricChic.HPOS.NoDirectOrderMeta.OrderQuery' => 1,
	),
	'compliant.php'  => array(),
);

/**
 * Run PHPCS over one fixture and tally findings by error code.
 *
 * @param string $phpcs    Path to the phpcs binary.
 * @param string $standard Path to the coding standard to run.
 * @param string $file    Fixture to scan.
 * @return array<string, int>|null Tally, or null if PHPCS could not be parsed.
 */
function ec_run_sniff( string $phpcs, string $standard, string $file ): ?array {
	// Invoke through the PHP running this script. phpcs has a
	// #!/usr/bin/env php shebang, so calling it directly would use whatever
	// php is first on PATH — which is not the version this project targets.
	$command = sprintf(
		'%s %s --standard=%s --report=json --runtime-set ignore_warnings_on_exit 1 %s 2>/dev/null',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( $phpcs ),
		escapeshellarg( $standard ),
		escapeshellarg( $file )
	);

	$output = shell_exec( $command );
	if ( null === $output || '' === trim( (string) $output ) ) {
		return null;
	}

	$report = json_decode( (string) $output, true );
	if ( ! is_array( $report ) || ! isset( $report['files'] ) ) {
		return null;
	}

	$tally = array();
	foreach ( $report['files'] as $file_report ) {
		foreach ( $file_report['messages'] ?? array() as $message ) {
			if ( 'ERROR' !== $message['type'] ) {
				continue;
			}
			$source           = $message['source'];
			$tally[ $source ] = ( $tally[ $source ] ?? 0 ) + 1;
		}
	}

	return $tally;
}

// ---------------------------------------------------------------------------

if ( ! is_file( $phpcs ) ) {
	fwrite( STDERR, "phpcs not found at {$phpcs}. Run: ./scripts/composer install\n" );
	exit( 1 );
}

$failures = 0;

echo "HPOS sniff self-test\n";
echo str_repeat( '-', 62 ) . "\n";

foreach ( EXPECTED as $fixture => $expected ) {
	$path = $fixtures . '/' . $fixture;

	if ( ! is_file( $path ) ) {
		printf( "  ✗ %-18s fixture missing at %s\n", $fixture, $path );
		++$failures;
		continue;
	}

	$actual = ec_run_sniff( $phpcs, $standard, $path );

	if ( null === $actual ) {
		printf( "  ✗ %-18s could not parse PHPCS output\n", $fixture );
		++$failures;
		continue;
	}

	$codes = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
	sort( $codes );

	$fixture_ok = true;
	foreach ( $codes as $code ) {
		$want = $expected[ $code ] ?? 0;
		$got  = $actual[ $code ] ?? 0;

		if ( $want === $got ) {
			printf( "  ✓ %-18s %-48s %d\n", $fixture, $code, $got );
			continue;
		}

		printf( "  ✗ %-18s %-48s expected %d, got %d\n", $fixture, $code, $want, $got );
		$fixture_ok = false;
		++$failures;
	}

	if ( array() === $codes ) {
		printf( "  ✓ %-18s no findings, as expected\n", $fixture );
	}

	if ( ! $fixture_ok ) {
		echo "\n    Reproduce:\n";
		printf( "      vendor/bin/phpcs --standard=%s %s\n\n", $standard, $path );
	}
}

echo str_repeat( '-', 62 ) . "\n";

if ( $failures > 0 ) {
	printf( "FAILED — %d expectation(s) not met.\n", $failures );
	echo "The HPOS sniff is the mechanical enforcement of decision D20.\n";
	echo "If it has stopped working, direct order post-meta access can reach main.\n";
	exit( 1 );
}

echo "PASSED — the HPOS sniff catches every violation and flags nothing legitimate.\n";
exit( 0 );
