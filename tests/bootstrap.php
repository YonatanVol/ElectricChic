<?php
/**
 * PHPUnit bootstrap.
 *
 * Deliberately minimal: it loads the Composer autoloader and nothing else.
 *
 * There is no WordPress here, and that is the point. The business logic this
 * suite covers is written to be pure — inputs in, values out, no framework
 * calls inside — so it can be tested in milliseconds without a database or a
 * WordPress install. HarnessTest asserts the absence, so the suite cannot
 * quietly grow a dependency on WordPress without a test failing.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Composer autoloader missing. Run: ./scripts/composer install\n" );
	exit( 1 );
}

require_once $autoload;
