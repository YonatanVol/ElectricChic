<?php
/**
 * Tests for the Violation value object.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Configuration;

use ElectricChic\Core\Configuration\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Violation describes one setting that does not match its requirement.
 *
 * It belongs to the WordPress-free half of the plugin, which means every path
 * through it — including the ones that only run on unusual input — has to work
 * without WordPress loaded.
 */
#[CoversClass( Violation::class )]
final class ViolationTest extends TestCase {

	/**
	 * Build a violation with the given expected and actual values.
	 *
	 * @param mixed $expected Expected value.
	 * @param mixed $actual   Actual value.
	 * @return Violation
	 */
	private function violation( mixed $expected, mixed $actual ): Violation {
		return new Violation(
			key: 'some_option',
			label: 'Some option',
			expected: $expected,
			actual: $actual,
			rationale: 'Because it matters.',
			critical: true,
			is_missing: false
		);
	}

	/**
	 * Non-scalar values must render without WordPress.
	 *
	 * This is the case that caught a real bug: describe() reached for
	 * wp_json_encode() while its own docblock promised not to depend on
	 * WordPress. Every existing test passed scalars, so the path was never
	 * exercised and the leak survived. Under this suite WordPress is genuinely
	 * absent, so the old code fatals here rather than merely being impure.
	 *
	 * @param mixed  $value    A non-scalar value.
	 * @param string $expected A fragment the description must contain.
	 */
	#[DataProvider( 'non_scalar_values' )]
	public function test_describes_non_scalar_values_without_wordpress( mixed $value, string $expected ): void {
		$description = $this->violation( $value, 'actual' )->describe();

		$this->assertStringContainsString(
			$expected,
			$description,
			'Non-scalar values must be rendered using native PHP only.'
		);
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function non_scalar_values(): array {
		return array(
			'list'        => array( array( 'a', 'b' ), '["a","b"]' ),
			'map'         => array( array( 'k' => 'v' ), '{"k":"v"}' ),
			'empty array' => array( array(), '[]' ),
			'object'      => array( (object) array( 'k' => 'v' ), '{"k":"v"}' ),
		);
	}

	/**
	 * Values that cannot be encoded degrade rather than throw.
	 */
	public function test_unencodable_values_degrade_gracefully(): void {
		// A resource cannot be JSON-encoded.
		$handle = fopen( 'php://memory', 'rb' );

		$description = $this->violation( $handle, 'actual' )->describe();

		$this->assertStringContainsString( '(unencodable)', $description );

		if ( is_resource( $handle ) ) {
			fclose( $handle );
		}
	}

	/**
	 * @param mixed  $value    Scalar value.
	 * @param string $expected Expected rendering.
	 */
	#[DataProvider( 'scalar_values' )]
	public function test_renders_scalars_readably( mixed $value, string $expected ): void {
		$this->assertStringContainsString( $expected, $this->violation( $value, 'x' )->describe() );
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function scalar_values(): array {
		return array(
			'true'   => array( true, 'true' ),
			'false'  => array( false, 'false' ),
			'null'   => array( null, 'null' ),
			'string' => array( 'ILS', 'ILS' ),
			'int'    => array( 42, '42' ),
		);
	}

	/**
	 * The description has to name the setting and carry the reason, or the
	 * audit report tells someone what is wrong without telling them why.
	 */
	public function test_description_names_the_setting_and_carries_the_rationale(): void {
		$description = $this->violation( 'ILS', 'USD' )->describe();

		$this->assertStringContainsString( 'some_option', $description );
		$this->assertStringContainsString( 'Because it matters.', $description );
	}
}
