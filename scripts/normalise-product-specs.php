<?php
/**
 * Make the specification table worth reading.
 *
 *   ./scripts/wp eval-file scripts/normalise-product-specs.php dry-run
 *   ./scripts/wp eval-file scripts/normalise-product-specs.php
 *
 * WHY THIS EXISTS
 *
 * The catalogue was imported faithfully from the supplier's own copy, and the
 * supplier writes for a supplier's purposes. Three things in it read badly on
 * a shop page, and each was counted across the real catalogue before being
 * acted on rather than assumed:
 *
 * The specification is an HTML table inside the product description — class
 * `ec-spec-table` — rather than a set of WooCommerce attributes. That is how
 * the importer produced it and it is not this script's job to restructure it;
 * the rows are edited where they live.
 *
 * 1. `מהירות מירבית` is 25 קמ״ש on every one of the 22 products that declare
 *    it. A specification row that never varies distinguishes nothing; it is
 *    regulation, and it belongs in one compliance line on the page rather than
 *    in a table row per product. The single-product template already carries
 *    that line, so leaving the row in place printed the same fact twice within
 *    a few hundred pixels.
 *
 * 2. `מנוע` reads "מנוע עוצמתי" — powerful motor — on 19 products, and a
 *    variation on the same adjective on two more. To a reader who knows these
 *    machines, and the first reader here is the importer, that one cell
 *    undermines every real number in the table beside it. A missing row is
 *    honest; an adjective in a technical column is not. The two rows that name
 *    something real — a manufacturer and a motor type — are kept.
 *
 * 3. Every one of the 24 writes gershayim as two straight ASCII quotes —
 *    `ק''מ` rather than `ק״מ`. In Hebrew that is not a typographic nicety: the
 *    straight pair does not join, and it reads as a machine wrote it.
 *
 * WHAT IT DOES NOT DO
 *
 * It does not invent a value anywhere. A motor row with no wattage is deleted,
 * never filled in with a guess. When Cortez send the real figures they go back
 * in as data, and nothing here has to be undone to accept them.
 *
 * @package ElectricChic
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/** Attribute that is identical on every product that has it. */
const EC_UNIFORM_ATTRIBUTE = 'מהירות מירבית';

/** The row whose value is usually an adjective rather than a specification. */
const EC_MOTOR_ROW = 'מנוע';

/**
 * Motor values that say nothing, listed rather than detected.
 *
 * The first version of this rule was "drop the row unless it contains a
 * digit", which is the rule the plan called for. Counting the real values
 * before running it showed why that is wrong: of the 23 motor rows in the
 * catalogue not one carries a wattage, but two of them carry a manufacturer —
 * `SANDA` — and a type — `מנוע מינימוטוס`. Both are facts a buyer can use, and
 * the digit rule would have deleted them along with the adjectives.
 *
 * So the vague values are named explicitly. A list is longer to maintain than
 * a pattern and it cannot delete something nobody looked at.
 *
 * @var string[]
 */
const EC_EMPTY_MOTOR_VALUES = array(
	'מנוע עוצמתי',
	'מנוע כוח עוצמתי',
	'עוצמתי',
);

$ec_dry_run = in_array( 'dry-run', isset( $args ) && is_array( $args ) ? $args : array(), true );

/**
 * Straight ASCII quotes to Hebrew geresh and gershayim.
 *
 * Only between Hebrew letters. A straight quote inside `48V/15.6A` or an
 * English model name is doing a different job and is left alone.
 *
 * @param string $text Source text.
 * @return string
 */
function ec_hebrew_quotes( string $text ): string {
	/*
	 * The table is stored as HTML, so the straight quote arrives encoded — and
	 * a doubled apostrophe arrives as TWO consecutive entities.
	 *
	 * The first version matched a single entity with a Hebrew letter required
	 * immediately after it, which a pair can never satisfy: after the first
	 * entity comes the second, not a letter. So `ק&#039;&#039;מ` sailed
	 * through untouched and rendered as ק''מ on seven product pages while the
	 * script reported success. Found by measuring a rendered spec table, not
	 * by re-reading the regex.
	 *
	 * The pair is handled before the single, or the single would consume half
	 * of it and leave a stray apostrophe behind.
	 *
	 * The single has NO lookahead. The first attempt required a Hebrew letter
	 * after it, which is true for צ׳ק but false for the far more common case
	 * here — a word-final geresh in an abbreviation. Five products kept
	 * rendering `אינץ&#039;` for inches while the run reported success.
	 */
	$text = preg_replace( '/(?<=\p{Hebrew})(?:&#0?39;){2}(?=\p{Hebrew})/u', "\u{05F4}", $text );
	$text = preg_replace( '/(?<=\p{Hebrew})&quot;(?=\p{Hebrew})/u', "\u{05F4}", $text );
	$text = preg_replace( '/(?<=\p{Hebrew})&#0?39;/u', "\u{05F3}", $text );

	// Gershayim (U+05F4) between two Hebrew letters: ק''מ -> ק״מ
	$text = preg_replace( '/(?<=\p{Hebrew})(?:\'\'|")(?=\p{Hebrew})/u', "\u{05F4}", $text );

	// Geresh (U+05F3) after a Hebrew letter at a word end: צ' -> צ׳
	$text = preg_replace( '/(?<=\p{Hebrew})\'(?!\p{Hebrew})/u', "\u{05F3}", $text );

	return $text;
}

/**
 * Whether a motor value is one of the known empty ones.
 *
 * @param string $value Cell value, tags already stripped.
 * @return bool
 */
function ec_is_empty_motor( string $value ): bool {
	return in_array( trim( $value ), EC_EMPTY_MOTOR_VALUES, true );
}

// ---------------------------------------------------------------------------

WP_CLI::log( '' );

if ( $ec_dry_run ) {
	WP_CLI::warning( 'DRY RUN — nothing will be written.' );
}

WP_CLI::log( sprintf( '  %-28s %-9s %-9s %s', 'product', 'uniform', 'adjective', 'punctuation' ) );
WP_CLI::log( str_repeat( '-', 74 ) );

$ec_dropped_uniform = 0;
$ec_dropped_vague   = 0;
$ec_repunctuated    = 0;
$ec_touched         = 0;

foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $ec_product ) {
	$ec_name    = mb_substr( $ec_product->get_name(), 0, 28 );
	$ec_changed = false;
	$ec_note    = array( '', '', '' );

	// ── The specification table, which lives in the description ─────────
	$ec_long = $ec_product->get_description();

	$ec_long = preg_replace_callback(
		'#<tr>\s*<th[^>]*>(.*?)</th>\s*<td[^>]*>(.*?)</td>\s*</tr>#us',
		static function ( array $m ) use ( &$ec_dropped_uniform, &$ec_dropped_vague, &$ec_note ) {
			$label = trim( wp_strip_all_tags( $m[1] ) );
			$value = trim( wp_strip_all_tags( $m[2] ) );

			if ( EC_UNIFORM_ATTRIBUTE === $label ) {
				++$ec_dropped_uniform;
				$ec_note[0] = 'dropped';

				return '';
			}

			if ( EC_MOTOR_ROW === $label && ec_is_empty_motor( $value ) ) {
				++$ec_dropped_vague;
				$ec_note[1] = 'dropped';

				return '';
			}

			return $m[0];
		},
		$ec_long
	);

	$ec_long = ec_hebrew_quotes( (string) $ec_long );

	if ( $ec_long !== $ec_product->get_description() ) {
		$ec_changed = true;
	}

	// ── Copy ─────────────────────────────────────────────────────────────
	/*
	 * The same speed appears in the summary line as well as the table, and
	 * removing it from only one place moved the duplication rather than
	 * ending it. The single-product template states it once, with the reason
	 * — "מוגבל לפי התקן הישראלי" — which is the part the summary never had.
	 * Dropped here so the summary leads with what actually differs between
	 * machines: battery and range.
	 */
	$ec_short = preg_replace(
		'/\s*·?\s*מהירות\s+מ[יי]?רבית\s*:[^·]*/u',
		'',
		$ec_product->get_short_description()
	);

	$ec_short = ec_hebrew_quotes( trim( (string) $ec_short, " ·\t\n\r" ) );

	if ( $ec_short !== $ec_product->get_short_description() || $ec_long !== $ec_product->get_description() ) {
		++$ec_repunctuated;
		$ec_note[2] = 'fixed';
		$ec_changed = true;
	}

	if ( ! $ec_changed ) {
		continue;
	}

	++$ec_touched;

	WP_CLI::log( sprintf( '  %-28s %-9s %-9s %s', $ec_name, $ec_note[0], $ec_note[1], $ec_note[2] ) );

	if ( $ec_dry_run ) {
		continue;
	}

	$ec_product->set_short_description( $ec_short );
	$ec_product->set_description( $ec_long );
	$ec_product->save();
}

WP_CLI::log( str_repeat( '-', 74 ) );
WP_CLI::log(
	sprintf(
		'products changed %d · uniform rows dropped %d · empty motor rows dropped %d · texts repunctuated %d',
		$ec_touched,
		$ec_dropped_uniform,
		$ec_dropped_vague,
		$ec_repunctuated
	)
);

WP_CLI::success( $ec_dry_run ? 'Dry run complete.' : 'The spec table now says only things that vary, and says them in Hebrew.' );
