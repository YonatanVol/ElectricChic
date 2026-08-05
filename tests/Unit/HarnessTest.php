<?php
/**
 * Tests that the test harness itself behaves as designed.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The suite makes an architectural claim: business logic is pure, so it can be
 * tested without loading WordPress.
 *
 * A claim nobody checks stops being true. These tests check it. If someone
 * later adds a WordPress bootstrap to speed up writing one test, this fails and
 * says why — which is cheaper than discovering months on that the "fast" unit
 * suite now needs a database.
 */
#[CoversNothing]
final class HarnessTest extends TestCase {

	/**
	 * The autoloader is present and the suite runs.
	 */
	public function test_composer_autoloader_is_available(): void {
		$this->assertTrue(
			class_exists( \Composer\Autoload\ClassLoader::class ),
			'Composer autoloader should be loaded by tests/bootstrap.php.'
		);
	}

	/**
	 * WordPress must not be loaded.
	 *
	 * @param string $function A function that only exists once WordPress is loaded.
	 */
	#[DataProvider('wordpress_functions')]
	public function test_wordpress_is_not_loaded( string $function ): void {
		$this->assertFalse(
			function_exists( $function ),
			sprintf(
				'%s() exists, so WordPress has been loaded into the unit suite. '
				. 'This suite is for pure logic only — inputs in, values out, no framework calls. '
				. 'If a test needs WordPress, the code under test is not pure and belongs in an '
				. 'integration suite instead. See phpunit.xml.dist and master plan §21.',
				$function
			)
		);
	}

	/**
	 * Functions whose presence would prove WordPress had been loaded.
	 *
	 * @return array<string, array{string}>
	 */
	public static function wordpress_functions(): array {
		return array(
			'actions'  => array( 'add_action' ),
			'filters'  => array( 'add_filter' ),
			'post meta' => array( 'get_post_meta' ),
			'options'  => array( 'get_option' ),
		);
	}

	/**
	 * WooCommerce must not be loaded either.
	 */
	public function test_woocommerce_is_not_loaded(): void {
		$this->assertFalse(
			function_exists( 'wc_get_order' ),
			'wc_get_order() exists, so WooCommerce has been loaded into the unit suite.'
		);
	}

	/**
	 * Tests run against the supported PHP floor or above.
	 */
	public function test_php_version_meets_the_supported_floor(): void {
		$this->assertTrue(
			version_compare( PHP_VERSION, '8.2.0', '>=' ),
			sprintf( 'Tests are running on PHP %s, below the 8.2 floor.', PHP_VERSION )
		);
	}

	/**
	 * The suite must run on the PHP version this project targets.
	 *
	 * This guards a bug that already happened once. Homebrew's composer and
	 * wp-cli formulae pull in the newest PHP, so `php` on PATH is 8.5 while the
	 * project targets 8.3. The scripts/ wrappers pin Composer itself, but tools
	 * Composer *spawns* have a #!/usr/bin/env php shebang and were picking up
	 * 8.5 regardless — PHPUnit reported "Runtime: PHP 8.5.8" while every wrapper
	 * appeared to be working. Composer's @php directive fixes it.
	 *
	 * If this fails, a composer script has lost its @php prefix, or CI is
	 * running an unintended PHP. Both are worth failing loudly for: analysis and
	 * tests that run on a version nobody deploys tell you nothing.
	 *
	 * CI deliberately tests the 8.2 floor as well, so both are accepted.
	 */
	public function test_runs_on_a_targeted_php_version(): void {
		$major_minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

		$this->assertContains(
			$major_minor,
			array( '8.2', '8.3' ),
			sprintf(
				'Tests are running on PHP %s. The project targets 8.3 locally and tests '
				. '8.2 (the supported floor) in CI. Check that the composer script uses '
				. 'the @php prefix — without it, the tool follows its shebang to whatever '
				. 'php is first on PATH. See docs/operations/local-development.md.',
				PHP_VERSION
			)
		);
	}
}
