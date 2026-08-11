<?php
/**
 * The facts a shop owner records about one product.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable snapshot of everything the availability decision depends on.
 *
 * This is the boundary of the pure core. A thin WordPress adapter reads product
 * meta and builds one of these; nothing downstream touches WordPress again.
 * That is what lets the resolver be tested in microseconds without a database.
 *
 * Note what is absent: there is no "availability" field. The owner records
 * FACTS — what is on the shelf, who supplies it, when the supplier last
 * reported, whether confirmation is needed — and the state is computed. A
 * stored availability field is one somebody has to remember to update, and the
 * one they forget is the one that oversells.
 */
final readonly class StockFacts {

	/**
	 * Record one product's stock facts, rejecting impossible combinations.
	 *
	 * @throws InvalidArgumentException When lead times are negative or inverted, or supplier stock is below zero.
	 *
	 * @param int                    $store_stock           Units physically in the shop.
	 * @param int|null               $supplier_stock        Units the supplier reports. NULL means never reported, which is not the same as zero.
	 * @param bool                   $has_supplier          Whether a supplier is assigned at all.
	 * @param DateTimeImmutable|null $supplier_updated_at   When the supplier figure was last refreshed.
	 * @param int                    $lead_time_min_days    Optimistic delivery estimate, business days.
	 * @param int                    $lead_time_max_days    Pessimistic delivery estimate, business days.
	 * @param bool                   $requires_confirmation Whether the shop must confirm before accepting payment.
	 * @param bool                   $enquiry_only          Whether the product is sold by conversation rather than cart.
	 * @param bool                   $discontinued          Whether manufacture has ended.
	 * @param bool                   $backorders_allowed    Whether WooCommerce permits ordering past zero.
	 */
	public function __construct(
		public int $store_stock,
		public ?int $supplier_stock,
		public bool $has_supplier,
		public ?DateTimeImmutable $supplier_updated_at,
		public int $lead_time_min_days,
		public int $lead_time_max_days,
		public bool $requires_confirmation,
		public bool $enquiry_only,
		public bool $discontinued,
		public bool $backorders_allowed,
	) {
		if ( $lead_time_min_days < 0 || $lead_time_max_days < 0 ) {
			throw new InvalidArgumentException( 'Lead times cannot be negative.' );
		}

		if ( $lead_time_max_days < $lead_time_min_days ) {
			/*
			 * ExceptionNotEscaped guards against unescaped values reaching a
			 * rendered exception message. Both values here are `int`-typed
			 * parameters, so PHP has already guaranteed they are integers —
			 * there is no string to escape.
			 *
			 * The sniff's remedy would be esc_html(), and that is the one thing
			 * this class must not do: StockFacts is deliberately
			 * WordPress-free so the model can be unit-tested without a
			 * bootstrap. Calling a WordPress function to satisfy a linter would
			 * break the property the linter exists to protect.
			 */
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException(
				sprintf( 'Maximum lead time (%d) cannot be shorter than the minimum (%d).', $lead_time_max_days, $lead_time_min_days )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( null !== $supplier_stock && $supplier_stock < 0 ) {
			throw new InvalidArgumentException( 'Supplier stock cannot be negative. Use null for "not reported".' );
		}
	}

	/**
	 * Build from a plain array, as the WordPress adapter supplies it.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$updated = $data['supplier_updated_at'] ?? null;

		if ( is_string( $updated ) && '' !== $updated ) {
			$updated = new DateTimeImmutable( $updated );
		} elseif ( ! $updated instanceof DateTimeImmutable ) {
			$updated = null;
		}

		$supplier_stock = $data['supplier_stock'] ?? null;

		return new self(
			store_stock: (int) ( $data['store_stock'] ?? 0 ),
			supplier_stock: null === $supplier_stock || '' === $supplier_stock ? null : (int) $supplier_stock,
			has_supplier: (bool) ( $data['has_supplier'] ?? false ),
			supplier_updated_at: $updated,
			lead_time_min_days: (int) ( $data['lead_time_min_days'] ?? 0 ),
			lead_time_max_days: (int) ( $data['lead_time_max_days'] ?? 0 ),
			requires_confirmation: (bool) ( $data['requires_confirmation'] ?? false ),
			enquiry_only: (bool) ( $data['enquiry_only'] ?? false ),
			discontinued: (bool) ( $data['discontinued'] ?? false ),
			backorders_allowed: (bool) ( $data['backorders_allowed'] ?? false ),
		);
	}

	/**
	 * Whether the shop physically holds any.
	 */
	public function has_store_stock(): bool {
		return $this->store_stock > 0;
	}

	/**
	 * Whether the supplier has reported a figure at all.
	 *
	 * "Not reported" and "reported zero" mean different things to a customer:
	 * one is a special order, the other is temporarily out. Collapsing them
	 * either invents availability or hides a product that could be ordered.
	 */
	public function has_supplier_report(): bool {
		return $this->has_supplier && null !== $this->supplier_stock;
	}
}
