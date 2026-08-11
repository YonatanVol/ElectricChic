<?php
/**
 * Tests for the shop configuration specification.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Configuration;

use ElectricChic\Core\Configuration\SettingRequirement;
use ElectricChic\Core\Configuration\ShopConfigurationSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The specification is the executable version of Issue #08's acceptance
 * criteria. If a setting matters enough to be in the plan, it belongs here
 * where it can be checked, rather than in a runbook where it can be skipped.
 */
#[CoversClass( ShopConfigurationSpec::class )]
final class ShopConfigurationSpecTest extends TestCase {

	/**
	 * Fetch one requirement by key, failing clearly if it is absent.
	 *
	 * @param string $key Option key.
	 */
	private function requirement_for( string $key ): SettingRequirement {
		foreach ( ShopConfigurationSpec::requirements() as $requirement ) {
			if ( $requirement->key === $key ) {
				return $requirement;
			}
		}

		$this->fail( sprintf( 'The specification does not cover "%s".', $key ) );
	}

	/**
	 * The settings Issue #08 exists to establish.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $expected Required value.
	 */
	#[DataProvider( 'required_settings' )]
	public function test_specification_requires( string $key, mixed $expected ): void {
		$this->assertSame( $expected, $this->requirement_for( $key )->expected );
	}

	/**
	 * @return array<string, array{string, mixed}>
	 */
	public static function required_settings(): array {
		return array(
			'HPOS enabled'        => array( 'woocommerce_custom_orders_table_enabled', true ),
			'HPOS sync disabled'  => array( 'woocommerce_custom_orders_table_data_sync_enabled', false ),
			'currency is shekels' => array( 'woocommerce_currency', 'ILS' ),
			'Hebrew locale'       => array( 'WPLANG', 'he_IL' ),
			'Israeli timezone'    => array( 'timezone_string', 'Asia/Jerusalem' ),
			'Israeli base country' => array( 'woocommerce_default_country', 'IL' ),
			'guest checkout'      => array( 'woocommerce_enable_guest_checkout', true ),
			'stock management'    => array( 'woocommerce_manage_stock', true ),
			'taxes calculated'    => array( 'woocommerce_calc_taxes', true ),
			'prices include VAT'  => array( 'woocommerce_prices_include_tax', true ),
			'reviews enabled'     => array( 'woocommerce_enable_reviews', true ),
			'reviews moderated'   => array( 'comment_moderation', true ),
			'clean permalinks'    => array( 'permalink_structure', '/%postname%/' ),
		);
	}

	/**
	 * HPOS is the one setting that becomes expensive to change later.
	 *
	 * Once orders exist in the old storage, switching means a migration on a
	 * live shop. It must be a launch blocker, not an advisory note.
	 */
	public function test_hpos_is_critical(): void {
		$this->assertTrue(
			$this->requirement_for( 'woocommerce_custom_orders_table_enabled' )->critical,
			'HPOS must block launch. Retrofitting it once orders exist is a live migration.'
		);
	}

	/**
	 * Anything that affects money or tax is a launch blocker.
	 *
	 * @param string $key Option key.
	 */
	#[DataProvider( 'settings_that_must_block_launch' )]
	public function test_money_and_tax_settings_are_critical( string $key ): void {
		$this->assertTrue(
			$this->requirement_for( $key )->critical,
			sprintf( '%s affects money or tax and must block launch.', $key )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function settings_that_must_block_launch(): array {
		return array(
			'currency'           => array( 'woocommerce_currency' ),
			'tax calculation'    => array( 'woocommerce_calc_taxes' ),
			'prices include tax' => array( 'woocommerce_prices_include_tax' ),
			'base country'       => array( 'woocommerce_default_country' ),
		);
	}

	/**
	 * Every requirement explains itself.
	 *
	 * An audit that says "this should be 'yes'" without saying why invites
	 * someone to simply change the requirement to match reality.
	 */
	public function test_every_requirement_states_a_rationale(): void {
		foreach ( ShopConfigurationSpec::requirements() as $requirement ) {
			$this->assertNotSame(
				'',
				trim( $requirement->rationale ),
				sprintf( '"%s" has no rationale.', $requirement->key )
			);
		}
	}

	public function test_every_requirement_has_a_label_and_key(): void {
		foreach ( ShopConfigurationSpec::requirements() as $requirement ) {
			$this->assertNotSame( '', trim( $requirement->key ) );
			$this->assertNotSame( '', trim( $requirement->label ) );
		}
	}

	/**
	 * A duplicated key would mean two requirements silently competing.
	 */
	public function test_keys_are_unique(): void {
		$keys = array_map(
			static fn( SettingRequirement $requirement ): string => $requirement->key,
			ShopConfigurationSpec::requirements()
		);

		$this->assertSame(
			array_values( array_unique( $keys ) ),
			$keys,
			'Duplicate keys mean two requirements compete over the same setting.'
		);
	}

	/**
	 * The spec is not empty, and is not a stub.
	 */
	public function test_specification_is_substantive(): void {
		$this->assertGreaterThanOrEqual( 13, count( ShopConfigurationSpec::requirements() ) );
	}
}
