<?php
/**
 * Tests for the configuration auditor.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Configuration;

use ElectricChic\Core\Configuration\ConfigurationAuditor;
use ElectricChic\Core\Configuration\SettingRequirement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The auditor compares required settings against what a WordPress install
 * actually reports, and returns the differences.
 *
 * It is deliberately pure — it takes two arrays and returns a result. All
 * WordPress contact happens in a thin adapter that gathers the "actual" array.
 * That keeps the logic that decides *whether the shop is configured correctly*
 * testable in milliseconds, which is the same reasoning applied to the
 * availability resolver.
 */
#[CoversClass( ConfigurationAuditor::class )]
final class ConfigurationAuditorTest extends TestCase {

	/**
	 * Build a requirement with sensible defaults for tests.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $expected Expected value.
	 * @param bool   $critical Whether a mismatch blocks launch.
	 * @return SettingRequirement
	 */
	private function requirement( string $key, mixed $expected, bool $critical = true ): SettingRequirement {
		return new SettingRequirement(
			key: $key,
			label: 'Label for ' . $key,
			expected: $expected,
			rationale: 'Because ' . $key . ' matters.',
			critical: $critical
		);
	}

	public function test_reports_compliant_when_every_setting_matches(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_currency', 'ILS' ) ),
			array( 'woocommerce_currency' => 'ILS' )
		);

		$this->assertTrue( $result->is_compliant() );
		$this->assertSame( array(), $result->violations() );
	}

	public function test_reports_a_violation_when_a_value_differs(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_currency', 'ILS' ) ),
			array( 'woocommerce_currency' => 'USD' )
		);

		$this->assertFalse( $result->is_compliant() );
		$this->assertCount( 1, $result->violations() );

		$violation = $result->violations()[0];
		$this->assertSame( 'woocommerce_currency', $violation->key );
		$this->assertSame( 'ILS', $violation->expected );
		$this->assertSame( 'USD', $violation->actual );
	}

	/**
	 * A setting that has never been saved is a violation, not an absence.
	 *
	 * WordPress returns false for an unset option, which is indistinguishable
	 * from a genuine false. Treating "missing" as its own outcome makes the
	 * failure message honest about what happened.
	 */
	public function test_reports_a_violation_when_a_setting_is_missing_entirely(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_currency', 'ILS' ) ),
			array()
		);

		$this->assertFalse( $result->is_compliant() );
		$this->assertCount( 1, $result->violations() );
		$this->assertTrue( $result->violations()[0]->is_missing );
	}

	/**
	 * The rationale travels with the violation.
	 *
	 * A report that says "woocommerce_currency should be ILS" is less useful
	 * than one that says why, especially to whoever reads it six months from now
	 * wondering whether it is safe to change.
	 */
	public function test_violation_carries_the_rationale(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_currency', 'ILS' ) ),
			array( 'woocommerce_currency' => 'USD' )
		);

		$this->assertStringContainsString( 'matters', $result->violations()[0]->rationale );
	}

	/**
	 * WordPress stores options as strings.
	 *
	 * get_option() returns 'yes'/'no' for WooCommerce booleans and '1'/'' for
	 * core ones — never a PHP bool. A strict comparison against true would
	 * report every correctly-configured boolean as a violation, and a report
	 * that cries wolf gets ignored.
	 *
	 * @param mixed $expected Expected value as declared in the spec.
	 * @param mixed $actual   Value as WordPress would return it.
	 */
	#[DataProvider( 'equivalent_boolean_representations' )]
	public function test_treats_wordpress_boolean_representations_as_equivalent( mixed $expected, mixed $actual ): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'some_flag', $expected ) ),
			array( 'some_flag' => $actual )
		);

		$this->assertTrue(
			$result->is_compliant(),
			sprintf(
				'Expected %s and actual %s should be treated as equivalent.',
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}

	/**
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function equivalent_boolean_representations(): array {
		return array(
			'true vs yes'   => array( true, 'yes' ),
			'true vs 1'     => array( true, '1' ),
			'true vs int 1' => array( true, 1 ),
			'false vs no'   => array( false, 'no' ),
			'false vs empty' => array( false, '' ),
			'false vs zero' => array( false, '0' ),
		);
	}

	/**
	 * Genuine mismatches must still be caught after the leniency above.
	 *
	 * @param mixed $expected Expected value.
	 * @param mixed $actual   Actual value.
	 */
	#[DataProvider( 'genuine_boolean_mismatches' )]
	public function test_still_catches_genuine_boolean_mismatches( mixed $expected, mixed $actual ): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'some_flag', $expected ) ),
			array( 'some_flag' => $actual )
		);

		$this->assertFalse(
			$result->is_compliant(),
			sprintf(
				'Expected %s and actual %s are genuinely different and must be reported.',
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}

	/**
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function genuine_boolean_mismatches(): array {
		return array(
			'true vs no'    => array( true, 'no' ),
			'true vs empty' => array( true, '' ),
			'false vs yes'  => array( false, 'yes' ),
			'false vs 1'    => array( false, '1' ),
		);
	}

	/**
	 * Critical violations are separable, because they mean different things.
	 *
	 * A wrong currency blocks launch. A missing store phone number does not.
	 * Reporting both at the same weight makes the launch gate useless.
	 */
	public function test_separates_critical_from_advisory_violations(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array(
				$this->requirement( 'woocommerce_currency', 'ILS', true ),
				$this->requirement( 'woocommerce_store_postcode', '12345', false ),
			),
			array(
				'woocommerce_currency'       => 'USD',
				'woocommerce_store_postcode' => '',
			)
		);

		$this->assertCount( 2, $result->violations() );
		$this->assertCount( 1, $result->critical_violations() );
		$this->assertSame( 'woocommerce_currency', $result->critical_violations()[0]->key );
		$this->assertTrue( $result->has_critical_violations() );
	}

	public function test_has_no_critical_violations_when_only_advisory_settings_differ(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_store_postcode', '12345', false ) ),
			array( 'woocommerce_store_postcode' => '' )
		);

		$this->assertFalse( $result->is_compliant() );
		$this->assertFalse( $result->has_critical_violations() );
	}

	/**
	 * Numeric settings must not be caught by the boolean leniency.
	 *
	 * woocommerce_notify_no_stock_amount is legitimately 0. If the auditor
	 * treats a numeric 0 as boolean false, an unset option ('') would count as
	 * satisfying it — reporting an unconfigured shop as correctly configured.
	 * That is the worst failure mode an audit can have: a false all-clear.
	 *
	 * @param mixed $expected Expected value.
	 * @param mixed $actual   Actual value.
	 */
	#[DataProvider( 'numeric_settings_that_must_not_be_treated_as_boolean' )]
	public function test_numeric_settings_are_not_treated_as_booleans( mixed $expected, mixed $actual ): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_notify_no_stock_amount', $expected ) ),
			array( 'woocommerce_notify_no_stock_amount' => $actual )
		);

		$this->assertFalse(
			$result->is_compliant(),
			sprintf(
				'Numeric requirement %s must not be satisfied by %s.',
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}

	/**
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function numeric_settings_that_must_not_be_treated_as_boolean(): array {
		return array(
			'0 vs unset'   => array( 0, '' ),
			'0 vs no'      => array( 0, 'no' ),
			'1 vs yes'     => array( 1, 'yes' ),
			'1 vs true'    => array( 1, true ),
		);
	}

	/**
	 * Numeric settings still match when they genuinely agree.
	 */
	public function test_numeric_settings_match_across_string_and_int(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_notify_no_stock_amount', 0 ) ),
			array( 'woocommerce_notify_no_stock_amount' => '0' )
		);

		$this->assertTrue( $result->is_compliant() );
	}

	public function test_an_empty_requirement_set_is_vacuously_compliant(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit( array(), array( 'anything' => 'at all' ) );

		$this->assertTrue( $result->is_compliant() );
	}

	/**
	 * Settings present in the install but not required are none of our business.
	 *
	 * The audit asserts that what we require is true, not that nothing else exists.
	 */
	public function test_ignores_settings_that_are_not_required(): void {
		$auditor = new ConfigurationAuditor();

		$result = $auditor->audit(
			array( $this->requirement( 'woocommerce_currency', 'ILS' ) ),
			array(
				'woocommerce_currency' => 'ILS',
				'some_other_plugin'    => 'whatever',
			)
		);

		$this->assertTrue( $result->is_compliant() );
	}
}
