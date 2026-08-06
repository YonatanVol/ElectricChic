<?php
/**
 * A setting that does not match its requirement.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Configuration;

/**
 * One difference between what the shop requires and what it actually has.
 */
final class Violation {

	/**
	 * Record a difference.
	 *
	 * @param string $key       Option name.
	 * @param string $label     Human-readable description.
	 * @param mixed  $expected  Required value.
	 * @param mixed  $actual    Value found, or null when absent.
	 * @param string $rationale Why the requirement exists.
	 * @param bool   $critical  Whether this blocks launch.
	 * @param bool   $is_missing Whether the setting was absent rather than wrong.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly mixed $expected,
		public readonly mixed $actual,
		public readonly string $rationale,
		public readonly bool $critical,
		public readonly bool $is_missing = false
	) {}

	/**
	 * A one-line description suitable for a report or a failing check.
	 */
	public function describe(): string {
		$found = $this->is_missing
			? 'not set at all'
			: sprintf( '"%s"', $this->stringify( $this->actual ) );

		return sprintf(
			'%s (%s): expected "%s", found %s. %s',
			$this->label,
			$this->key,
			$this->stringify( $this->expected ),
			$found,
			$this->rationale
		);
	}

	/**
	 * Render a value for display without tripping over booleans or arrays.
	 *
	 * @param mixed $value Value to render.
	 */
	private function stringify( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return $this->encode( $value );
	}

	/**
	 * Encode a non-scalar value for display, without depending on WordPress.
	 *
	 * @param mixed $value Value to encode.
	 */
	private function encode( mixed $value ): string {
		$encoded = wp_json_encode( $value );

		return false === $encoded ? '(unencodable)' : $encoded;
	}
}
