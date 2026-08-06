<?php
/**
 * A single required WordPress or WooCommerce setting.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Configuration;

/**
 * Declares one setting the shop must have, and why.
 *
 * The rationale is not decoration. Six months from now somebody will look at a
 * failing audit and decide whether to change the setting or change the
 * requirement, and they need to know what the value was protecting.
 */
final class SettingRequirement {

	/**
	 * Declare a required setting.
	 *
	 * @param string $key       Option name as WordPress stores it.
	 * @param string $label     Human-readable description.
	 * @param mixed  $expected  Required value.
	 * @param string $rationale Why this value, in a sentence.
	 * @param bool   $critical  Whether a mismatch blocks launch.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly mixed $expected,
		public readonly string $rationale,
		public readonly bool $critical = true
	) {}
}
