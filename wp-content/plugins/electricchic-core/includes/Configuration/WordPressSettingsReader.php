<?php
/**
 * Reads actual setting values from a live WordPress install.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Configuration;

/**
 * The only part of the configuration audit that touches WordPress.
 *
 * Kept deliberately thin — it looks settings up and returns them. All judgement
 * about whether those values are correct lives in ConfigurationAuditor, which
 * is pure and unit-tested. The seam is what lets the rules be tested in
 * milliseconds without a database.
 */
final class WordPressSettingsReader {

	/**
	 * A sentinel distinguishing "not set" from a legitimately falsy value.
	 *
	 * WordPress returns false both for an absent option and for one saved as
	 * false, and the audit needs to tell those apart to report honestly.
	 */
	private const NOT_SET = '__ec_option_not_set__';

	/**
	 * Read the current value of every required setting.
	 *
	 * Keys with no stored value are omitted, which the auditor reports as
	 * missing rather than as merely wrong.
	 *
	 * @param array<int, SettingRequirement> $requirements Settings to look up.
	 * @return array<string, mixed>
	 */
	public function read( array $requirements ): array {
		$actual = array();

		foreach ( $requirements as $requirement ) {
			$value = get_option( $requirement->key, self::NOT_SET );

			if ( self::NOT_SET === $value ) {
				continue;
			}

			$actual[ $requirement->key ] = $value;
		}

		return $actual;
	}
}
