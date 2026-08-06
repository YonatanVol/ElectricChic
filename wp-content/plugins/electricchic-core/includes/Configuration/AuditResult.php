<?php
/**
 * The outcome of a configuration audit.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Configuration;

/**
 * Immutable result of comparing required settings against actual ones.
 */
final class AuditResult {

	/**
	 * Construct a result.
	 *
	 * @param array<int, Violation> $violations Differences found, in requirement order.
	 */
	public function __construct( private readonly array $violations ) {}

	/**
	 * Whether every requirement was satisfied.
	 */
	public function is_compliant(): bool {
		return array() === $this->violations;
	}

	/**
	 * All differences found.
	 *
	 * @return array<int, Violation>
	 */
	public function violations(): array {
		return $this->violations;
	}

	/**
	 * Only the differences that block launch.
	 *
	 * @return array<int, Violation>
	 */
	public function critical_violations(): array {
		return array_values(
			array_filter(
				$this->violations,
				static fn( Violation $violation ): bool => $violation->critical
			)
		);
	}

	/**
	 * Whether anything launch-blocking was found.
	 */
	public function has_critical_violations(): bool {
		return array() !== $this->critical_violations();
	}
}
