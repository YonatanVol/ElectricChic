<?php
/**
 * Compares required settings against a WordPress install's actual settings.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Configuration;

/**
 * Pure comparison logic. No WordPress calls live here.
 *
 * The adapter that reads real option values is separate, so the rules that
 * decide whether the shop is correctly configured can be tested in
 * milliseconds — the same reasoning applied to the availability resolver.
 */
final class ConfigurationAuditor {

	/**
	 * Values WordPress uses to mean "on".
	 *
	 * @var array<int, mixed>
	 */
	private const TRUTHY = array( true, 'yes', '1', 1 );

	/**
	 * Values WordPress uses to mean "off".
	 *
	 * @var array<int, mixed>
	 */
	private const FALSY = array( false, 'no', '', '0', 0 );

	/**
	 * Compare requirements against actual settings.
	 *
	 * @param array<int, SettingRequirement> $requirements What the shop must have.
	 * @param array<string, mixed>           $actual       What it reports having.
	 */
	public function audit( array $requirements, array $actual ): AuditResult {
		$violations = array();

		foreach ( $requirements as $requirement ) {
			if ( ! array_key_exists( $requirement->key, $actual ) ) {
				$violations[] = new Violation(
					key: $requirement->key,
					label: $requirement->label,
					expected: $requirement->expected,
					actual: null,
					rationale: $requirement->rationale,
					critical: $requirement->critical,
					is_missing: true
				);
				continue;
			}

			$found = $actual[ $requirement->key ];

			if ( $this->matches( $requirement->expected, $found ) ) {
				continue;
			}

			$violations[] = new Violation(
				key: $requirement->key,
				label: $requirement->label,
				expected: $requirement->expected,
				actual: $found,
				rationale: $requirement->rationale,
				critical: $requirement->critical
			);
		}

		return new AuditResult( $violations );
	}

	/**
	 * Decide whether an actual value satisfies an expected one.
	 *
	 * WordPress never returns a PHP boolean from get_option(). WooCommerce
	 * booleans come back as 'yes'/'no' and core ones as '1'/''. Comparing those
	 * strictly against true would report every correctly-configured boolean as
	 * a violation, and a report that cries wolf stops being read.
	 *
	 * The leniency is therefore driven entirely by how the REQUIREMENT is
	 * declared, never by how the actual value happens to look:
	 *
	 *   expected is a real PHP bool  -> interpret the actual value as a boolean
	 *   expected is anything else    -> compare as strings
	 *
	 * That distinction matters more than it first appears.
	 * woocommerce_notify_no_stock_amount is legitimately 0. Inferring
	 * "boolean" from the actual value would let an unset option ('') satisfy a
	 * requirement of 0 — reporting an unconfigured shop as correctly
	 * configured. A false all-clear is the worst thing an audit can produce, so
	 * the rule is deliberately driven by the side we control.
	 *
	 * @param mixed $expected Required value.
	 * @param mixed $actual   Value found.
	 */
	private function matches( mixed $expected, mixed $actual ): bool {
		if ( is_bool( $expected ) ) {
			return $expected === $this->as_boolean( $actual );
		}

		// A boolean where a non-boolean is required is a type mismatch, not a
		// near miss: (string) true is '1', which would otherwise satisfy a
		// requirement of 1.
		if ( is_bool( $actual ) ) {
			return false;
		}

		if ( is_scalar( $expected ) && is_scalar( $actual ) ) {
			return (string) $expected === (string) $actual;
		}

		return $expected === $actual;
	}

	/**
	 * Interpret a value the way WordPress means it, or null if it is not
	 * boolean-shaped at all.
	 *
	 * @param mixed $value Value to interpret.
	 */
	private function as_boolean( mixed $value ): ?bool {
		if ( in_array( $value, self::TRUTHY, true ) ) {
			return true;
		}

		if ( in_array( $value, self::FALSY, true ) ) {
			return false;
		}

		return null;
	}
}
