<?php
/**
 * Audit a live WordPress install against the shop's configuration specification.
 *
 * Run through WP-CLI, which loads WordPress first:
 *
 *   ./scripts/wp eval-file scripts/audit-configuration.php
 *
 * Exit codes:
 *   0  compliant, or only advisory differences
 *   1  at least one critical difference — not fit to launch
 *   2  could not run
 *
 * Configuration drift is invisible by nature. Nobody gets an alert when someone
 * toggles a setting in wp-admin; the shop just starts behaving differently. This
 * turns the acceptance criteria of Issue #08 into something re-runnable on every
 * environment, for as long as the shop exists.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

use ElectricChic\Core\Configuration\ConfigurationAuditor;
use ElectricChic\Core\Configuration\ShopConfigurationSpec;
use ElectricChic\Core\Configuration\Violation;
use ElectricChic\Core\Configuration\WordPressSettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress:\n" );
	fwrite( STDERR, "  ./scripts/wp eval-file scripts/audit-configuration.php\n" );
	exit( 2 );
}

$ec_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_file( $ec_autoload ) ) {
	fwrite( STDERR, "Composer autoloader missing. Run: ./scripts/composer install\n" );
	exit( 2 );
}

require_once $ec_autoload;

$ec_requirements = ShopConfigurationSpec::requirements();
$ec_actual       = ( new WordPressSettingsReader() )->read( $ec_requirements );
$ec_result       = ( new ConfigurationAuditor() )->audit( $ec_requirements, $ec_actual );

echo "\nShop configuration audit\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "Checked %d requirement(s) against %s\n\n", count( $ec_requirements ), home_url() );

if ( $ec_result->is_compliant() ) {
	echo "COMPLIANT — every required setting matches.\n\n";
	exit( 0 );
}

/**
 * Print a group of violations.
 *
 * @param string              $heading    Section heading.
 * @param array<int, Violation> $violations Violations to print.
 */
$ec_print_group = static function ( string $heading, array $violations ): void {
	if ( array() === $violations ) {
		return;
	}

	echo $heading . "\n";
	echo str_repeat( '-', 72 ) . "\n";

	foreach ( $violations as $violation ) {
		echo '  * ' . $violation->describe() . "\n\n";
	}
};

$ec_critical = $ec_result->critical_violations();
$ec_advisory = array_values(
	array_filter(
		$ec_result->violations(),
		static fn( Violation $violation ): bool => ! $violation->critical
	)
);

$ec_print_group( 'CRITICAL — these block launch', $ec_critical );
$ec_print_group( 'ADVISORY — review, but not launch-blocking', $ec_advisory );

printf(
	"%d violation(s): %d critical, %d advisory.\n\n",
	count( $ec_result->violations() ),
	count( $ec_critical ),
	count( $ec_advisory )
);

exit( $ec_result->has_critical_violations() ? 1 : 0 );
