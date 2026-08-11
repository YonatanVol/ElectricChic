<?php
/**
 * The Hebrew sentence a customer reads for each availability state.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Availability;

/**
 * Turns a state into words.
 *
 * Deliberately separate from AvailabilityResolver. The resolver decides what is
 * true; this decides how to say it. Keeping them apart means the wording can be
 * argued over and corrected without touching logic that has money riding on it.
 *
 * The strings are Hebrew literals rather than __() calls. This class is part of
 * the pure core — HarnessTest fails the suite if WordPress becomes reachable —
 * and the shop is Hebrew-only, so a translation layer would buy nothing and
 * cost the property that makes the model testable. If a second language is ever
 * needed, a WordPress-side decorator translates these, and the core stays clean.
 */
final class AvailabilityLabels {

	/**
	 * The badge text for a state.
	 *
	 * @param AvailabilityState $state The resolved state.
	 * @param StockFacts        $facts Needed for the lead-time window.
	 * @return string
	 */
	public function for_state( AvailabilityState $state, StockFacts $facts ): string {
		$base = match ( $state ) {
			AvailabilityState::DISCONTINUED          => 'הופסק ייצור',
			AvailabilityState::ENQUIRY_ONLY          => 'לפרטים וזמינות – צרו קשר',
			AvailabilityState::CONFIRMATION_REQUIRED => 'נדרש אישור זמינות',
			AvailabilityState::IN_STOCK_STORE        => 'במלאי בחנות · זמין לאיסוף מיידי',
			AvailabilityState::SUPPLIER_AVAILABLE    => 'זמין בהזמנה',
			AvailabilityState::SPECIAL_ORDER         => 'הזמנה מיוחדת',
			AvailabilityState::BACKORDER             => 'ניתן להזמנה מוקדמת',
			AvailabilityState::TEMP_OUT_OF_STOCK     => 'אזל זמנית מהמלאי',
			AvailabilityState::OUT_OF_STOCK          => 'אזל מהמלאי',
		};

		if ( ! $state->shows_lead_time() ) {
			return $base;
		}

		$window = $this->lead_time_phrase( $facts );

		return '' === $window ? $base : $base . ' · ' . $window;
	}

	/**
	 * An extra line explaining a state the customer may not expect.
	 *
	 * Only where the badge alone would mislead. A special order is purchasable
	 * and looks like any other purchase at checkout, so the one thing that makes
	 * it different — that nobody has confirmed a unit exists right now — has to
	 * be said out loud before payment, not discovered afterwards.
	 *
	 * @param AvailabilityState $state The resolved state.
	 * @return string|null
	 */
	public function notice( AvailabilityState $state ): ?string {
		return match ( $state ) {
			AvailabilityState::SPECIAL_ORDER         => 'הפריט אינו במלאי החנות וזמינותו אצל הספק טרם אומתה. נעדכן אתכם לאחר בדיקה מול הספק.',
			AvailabilityState::SUPPLIER_AVAILABLE    => 'הפריט אינו במלאי החנות ויוזמן עבורכם מהספק.',
			AvailabilityState::BACKORDER             => 'ניתן להזמין כעת; המשלוח יצא עם חידוש המלאי.',
			AvailabilityState::CONFIRMATION_REQUIRED => 'ניצור אתכם קשר לאישור זמינות לפני ביצוע ההזמנה.',
			default                                  => null,
		};
	}

	/**
	 * The CSS class carrying the badge colour.
	 *
	 * Derived from the enum name, so a new state cannot be added without getting
	 * a distinct class — and the class is never the thing that decides meaning.
	 *
	 * @param AvailabilityState $state The resolved state.
	 * @return string
	 */
	public function css_class( AvailabilityState $state ): string {
		return 'ec-avail--' . str_replace( '_', '-', $state->key() );
	}

	/**
	 * "3–7 ימי עסקים", or nothing at all.
	 *
	 * A zero lead time means nobody filled the field in. Rendering that as
	 * "0 ימי עסקים" would turn an unanswered field into a delivery commitment,
	 * so an unset window produces no phrase and the badge simply says less.
	 *
	 * @param StockFacts $facts Recorded facts.
	 * @return string
	 */
	private function lead_time_phrase( StockFacts $facts ): string {
		$min = $facts->lead_time_min_days;
		$max = $facts->lead_time_max_days;

		if ( $max <= 0 ) {
			return '';
		}

		// A single-value window reads as one number. "5–5 ימי עסקים" is the kind
		// of detail that makes a customer wonder what else here was generated
		// rather than written.
		$range = ( $min === $max || $min <= 0 ) ? (string) $max : $min . '–' . $max;

		return $range . ' ימי עסקים';
	}
}
