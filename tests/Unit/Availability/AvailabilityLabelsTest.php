<?php
/**
 * Tests for the Hebrew availability labels.
 *
 * @package ElectricChic\Tests
 */

declare( strict_types = 1 );

namespace ElectricChic\Tests\Unit\Availability;

use ElectricChic\Core\Availability\AvailabilityLabels;
use ElectricChic\Core\Availability\AvailabilityState;
use ElectricChic\Core\Availability\StockFacts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The words a customer actually reads.
 *
 * The resolver decides the state; this decides the sentence. Keeping them apart
 * means the rules can be reasoned about without arguing over wording, and the
 * wording can be corrected without touching logic that has money riding on it.
 *
 * Labels are Hebrew because the shop is Hebrew. They are not run through
 * translation functions here: this class is part of the pure core, and calling
 * __() would drag WordPress into the layer that must stay testable without it.
 */
#[CoversClass( AvailabilityLabels::class )]
final class AvailabilityLabelsTest extends TestCase {

	private AvailabilityLabels $labels;

	protected function setUp(): void {
		$this->labels = new AvailabilityLabels();
	}

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 */
	private function facts( array $overrides = array() ): StockFacts {
		return StockFacts::from_array(
			array_merge(
				array(
					'lead_time_min_days' => 5,
					'lead_time_max_days' => 10,
				),
				$overrides
			)
		);
	}

	/**
	 * Every state must say something.
	 *
	 * A missing label shows the customer an empty badge, which is worse than a
	 * blunt one — it reads as a broken page. Driven off the enum itself so a
	 * tenth state cannot be added without this failing.
	 */
	#[DataProvider( 'every_state' )]
	public function test_every_state_has_a_non_empty_hebrew_label( AvailabilityState $state ): void {
		$label = $this->labels->for_state( $state, $this->facts() );

		$this->assertNotSame( '', trim( $label ), $state->name . ' has no label.' );
		$this->assertMatchesRegularExpression(
			'/\p{Hebrew}/u',
			$label,
			$state->name . ' has a label with no Hebrew in it.'
		);
	}

	/**
	 * @return array<string, array{AvailabilityState}>
	 */
	public static function every_state(): array {
		$cases = array();

		foreach ( AvailabilityState::cases() as $state ) {
			$cases[ $state->name ] = array( $state );
		}

		return $cases;
	}

	/**
	 * Every state must also carry a CSS class, and they must be distinct.
	 *
	 * Two states sharing a class means two different situations painted the same
	 * colour — the exact failure this whole model exists to prevent, reappearing
	 * at the presentation layer.
	 */
	public function test_css_classes_are_present_and_unique(): void {
		$classes = array_map(
			fn( AvailabilityState $state ): string => $this->labels->css_class( $state ),
			AvailabilityState::cases()
		);

		$this->assertNotContains( '', $classes );
		$this->assertSame( count( $classes ), count( array_unique( $classes ) ), 'Two states share a CSS class.' );
	}

	// ── Lead-time interpolation ──────────────────────────────────────────────

	public function test_supplier_available_states_the_delivery_window(): void {
		$label = $this->labels->for_state(
			AvailabilityState::SUPPLIER_AVAILABLE,
			$this->facts( array( 'lead_time_min_days' => 3, 'lead_time_max_days' => 7 ) )
		);

		$this->assertStringContainsString( '3', $label );
		$this->assertStringContainsString( '7', $label );
		$this->assertStringContainsString( 'ימי עסקים', $label );
	}

	/**
	 * An identical range reads as one number, not "5–5 days".
	 *
	 * Small thing, but "5–5 ימי עסקים" is the kind of detail that makes a
	 * customer wonder what else on the page was generated without being read.
	 */
	public function test_an_identical_range_is_written_as_a_single_number(): void {
		$label = $this->labels->for_state(
			AvailabilityState::SPECIAL_ORDER,
			$this->facts( array( 'lead_time_min_days' => 5, 'lead_time_max_days' => 5 ) )
		);

		$this->assertStringContainsString( '5', $label );
		$this->assertStringNotContainsString( '5–5', $label );
	}

	/**
	 * States that promise nothing must not show a delivery estimate.
	 *
	 * "5–10 ימי עסקים" next to "אזל מהמלאי" is a contradiction on the same line.
	 */
	#[DataProvider( 'states_without_a_promise' )]
	public function test_states_without_a_promise_show_no_lead_time( AvailabilityState $state ): void {
		$label = $this->labels->for_state(
			$state,
			$this->facts( array( 'lead_time_min_days' => 5, 'lead_time_max_days' => 10 ) )
		);

		$this->assertStringNotContainsString( 'ימי עסקים', $label, $state->name . ' should not promise a timescale.' );
	}

	/**
	 * @return array<string, array{AvailabilityState}>
	 */
	public static function states_without_a_promise(): array {
		$cases = array();

		foreach ( AvailabilityState::cases() as $state ) {
			if ( ! $state->shows_lead_time() ) {
				$cases[ $state->name ] = array( $state );
			}
		}

		return $cases;
	}

	/**
	 * A zero lead time is missing information, not a same-day promise.
	 *
	 * Lead times default to zero when nobody has filled them in. Rendering that
	 * as "0 ימי עסקים" turns an unanswered field into a delivery commitment.
	 */
	public function test_an_unset_lead_time_makes_no_promise(): void {
		$label = $this->labels->for_state(
			AvailabilityState::SPECIAL_ORDER,
			$this->facts( array( 'lead_time_min_days' => 0, 'lead_time_max_days' => 0 ) )
		);

		$this->assertStringNotContainsString( '0', $label );
		$this->assertNotSame( '', trim( $label ) );
	}

	// ── The notice on special orders ─────────────────────────────────────────

	/**
	 * A special order is purchasable, but the customer must be told why it is
	 * different — that nobody has confirmed the supplier holds one right now.
	 */
	public function test_special_order_carries_an_explanatory_notice(): void {
		$notice = $this->labels->notice( AvailabilityState::SPECIAL_ORDER );

		$this->assertNotNull( $notice );
		$this->assertMatchesRegularExpression( '/\p{Hebrew}/u', $notice );
	}

	public function test_in_stock_needs_no_notice(): void {
		$this->assertNull( $this->labels->notice( AvailabilityState::IN_STOCK_STORE ) );
	}
}
