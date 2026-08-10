<?php
/**
 * Build the demo homepage.
 *
 *   ./scripts/wp eval-file scripts/build-homepage.php
 *
 * Idempotent: the page is matched by slug, so re-running replaces its content
 * rather than creating a second homepage.
 *
 * Structure follows the approved homepage plan — nine sections at most, ordered
 * for a Hebrew shopper on a phone. Category navigation sits high because it is
 * the largest mobile lever, and "in stock now" sits above featured products
 * because immediate availability is the shop's structural advantage over a pure
 * online retailer.
 *
 * Deliberately absent: a carousel (measurably underperforms and harms LCP), a
 * newsletter block (no consented email programme exists), and testimonials
 * (there are no real reviews yet, and placeholder testimonials are a lie).
 *
 * No declare(strict_types=1) — WP-CLI's eval-file runs this through eval().
 *
 * @package ElectricChic
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI.\n" );
	exit( 2 );
}

/**
 * Resolve a product-category archive URL by name.
 *
 * @param string $name Category name.
 * @return string
 */
function ec_category_link( string $name ): string {
	$term = get_term_by( 'name', $name, 'product_cat' );

	if ( ! $term instanceof WP_Term ) {
		return '#';
	}

	$link = get_term_link( $term );

	return is_wp_error( $link ) ? '#' : $link;
}

$ec_shop_url = get_permalink( (int) wc_get_page_id( 'shop' ) );

// ── Hero ─────────────────────────────────────────────────────────────────────

$ec_hero = '<!-- wp:html -->
<section class="ec-hero">
	<div class="ec-hero__inner">
		<p class="ec-hero__eyebrow">חנות אופניים · תל אביב</p>
		<h1 class="ec-hero__title">אופניים שנבחרו בקפידה,<br>ואנשים שיודעים לתקן אותם</h1>
		<p class="ec-hero__lead">מכירה, שירות ותיקונים. כל אופניים שיוצאים מכאן מורכבים ומכוונים ביד — ואפשר תמיד לחזור עם שאלה.</p>
		<div class="ec-hero__actions">
			<a class="ec-button ec-button--primary" href="' . esc_url( $ec_shop_url ) . '">לחנות</a>
			<a class="ec-button ec-button--ghost" href="#ec-service">שירות ותיקונים</a>
		</div>
	</div>
</section>
<!-- /wp:html -->';

// ── Categories ───────────────────────────────────────────────────────────────

$ec_category_cards = array(
	array( 'אופניים חשמליים', 'לנסיעה יומית בעיר' ),
	array( 'אופני הרים', 'לשטח ולסינגלים' ),
	array( 'אופני כביש', 'לרכיבות ארוכות' ),
	array( 'אופני ילדים', 'לפי גיל וגובה' ),
	array( 'קסדות', 'בטיחות קודמת לכול' ),
	array( 'רכיבים וחלפים', 'חלקי חילוף ותחזוקה' ),
);

$ec_cards_html = '';

foreach ( $ec_category_cards as $ec_card ) {
	list( $ec_title, $ec_sub ) = $ec_card;

	$ec_cards_html .= sprintf(
		'<a class="ec-cat" href="%s"><span class="ec-cat__title">%s</span><span class="ec-cat__sub">%s</span><span class="ec-cat__arrow" aria-hidden="true">←</span></a>',
		esc_url( ec_category_link( $ec_title ) ),
		esc_html( $ec_title ),
		esc_html( $ec_sub )
	);
}

$ec_categories = '<!-- wp:html -->
<section class="ec-section">
	<div class="ec-section__head">
		<h2 class="ec-section__title">קטגוריות</h2>
	</div>
	<div class="ec-cats">' . $ec_cards_html . '</div>
</section>
<!-- /wp:html -->';

// ── In stock now ─────────────────────────────────────────────────────────────
//
// The section that distinguishes a physical shop from a pure online retailer.
// Placeholder until the derived availability model lands — it currently uses
// WooCommerce's own stock status, which is the honest thing to show today.

$ec_in_stock = '<!-- wp:html -->
<section class="ec-section ec-section--tint">
	<div class="ec-section__head">
		<h2 class="ec-section__title">זמין עכשיו בחנות</h2>
		<p class="ec-section__sub">אפשר לבוא לראות, למדוד ולקחת היום</p>
	</div>
</section>
<!-- /wp:html -->

<!-- wp:shortcode -->
[products limit="8" columns="4" visibility="visible" orderby="date" class="ec-products"]
<!-- /wp:shortcode -->';

// ── Service ──────────────────────────────────────────────────────────────────

$ec_service = '<!-- wp:html -->
<section class="ec-section" id="ec-service">
	<div class="ec-split">
		<div class="ec-split__text">
			<h2 class="ec-section__title">שירות ותיקונים</h2>
			<p>מעבדה בחנות. טיפולים, כיוונים, החלפת חלקים והרכבות — לרוב תוך יום עד יומיים.</p>
			<ul class="ec-list">
				<li>טיפול תקופתי וכיוון הילוכים</li>
				<li>החלפת צמיגים, שרשרת ורפידות</li>
				<li>כיוון בלמים הידראוליים</li>
				<li>הרכבת אופניים חדשים והתאמה לרוכב</li>
			</ul>
			<a class="ec-button ec-button--primary" href="tel:+97200000000">לתיאום טלפוני</a>
		</div>
		<div class="ec-split__aside">
			<div class="ec-fact"><span class="ec-fact__num">1–2</span><span class="ec-fact__label">ימי עבודה לטיפול רגיל</span></div>
			<div class="ec-fact"><span class="ec-fact__num">כל</span><span class="ec-fact__label">המותגים, גם אם לא נקנו אצלנו</span></div>
		</div>
	</div>
</section>
<!-- /wp:html -->';

// ── Trust ────────────────────────────────────────────────────────────────────

$ec_trust = '<!-- wp:html -->
<section class="ec-section ec-section--tint">
	<div class="ec-section__head">
		<h2 class="ec-section__title">למה אצלנו</h2>
	</div>
	<div class="ec-trust">
		<div class="ec-trust__item">
			<h3>מורכבים ומכוונים בחנות</h3>
			<p>אופניים לא יוצאים מכאן בקרטון. הם מורכבים, מכוונים ונבדקים לפני שאתם לוקחים אותם.</p>
		</div>
		<div class="ec-trust__item">
			<h3>אחריות מול בן אדם</h3>
			<p>יש בעיה — חוזרים לחנות. לא טופס, לא מוקד, לא המתנה לספק.</p>
		</div>
		<div class="ec-trust__item">
			<h3>ייעוץ ממי שרוכב</h3>
			<p>נשאל על גובה, שימוש ותקציב לפני שנמליץ. לפעמים ההמלצה היא לא לקנות את היקר.</p>
		</div>
		<div class="ec-trust__item">
			<h3>איסוף מהחנות או משלוח</h3>
			<p>מה שבמלאי אפשר לאסוף היום. מה שלא — נזמין ונעדכן בזמן אמת.</p>
		</div>
	</div>
</section>
<!-- /wp:html -->';

// ── Store ────────────────────────────────────────────────────────────────────

$ec_store = '<!-- wp:html -->
<section class="ec-section ec-store">
	<div class="ec-split">
		<div class="ec-split__text">
			<h2 class="ec-section__title">החנות</h2>
			<p class="ec-store__addr">רחוב הדוגמה 12, תל אביב</p>
			<dl class="ec-hours">
				<dt>ראשון–חמישי</dt><dd>09:00 – 19:00</dd>
				<dt>שישי</dt><dd>09:00 – 14:00</dd>
				<dt>שבת</dt><dd>סגור</dd>
			</dl>
			<div class="ec-hero__actions">
				<a class="ec-button ec-button--primary" href="tel:+97200000000">התקשרו</a>
				<a class="ec-button ec-button--ghost" href="https://wa.me/97200000000">וואטסאפ</a>
			</div>
		</div>
	</div>
</section>
<!-- /wp:html -->';

// ── Assemble ─────────────────────────────────────────────────────────────────

$ec_content = implode(
	"\n\n",
	array( $ec_hero, $ec_categories, $ec_in_stock, $ec_service, $ec_trust, $ec_store )
);

$ec_existing = get_page_by_path( 'home', OBJECT, 'page' );

$ec_page_data = array(
	'post_title'   => 'דף הבית',
	'post_name'    => 'home',
	'post_content' => $ec_content,
	'post_status'  => 'publish',
	'post_type'    => 'page',
);

if ( $ec_existing instanceof WP_Post ) {
	$ec_page_data['ID'] = $ec_existing->ID;
	$ec_page_id         = wp_update_post( $ec_page_data, true );
	$ec_action          = 'updated';
} else {
	$ec_page_id = wp_insert_post( $ec_page_data, true );
	$ec_action  = 'created';
}

if ( is_wp_error( $ec_page_id ) ) {
	fwrite( STDERR, 'Failed: ' . $ec_page_id->get_error_message() . "\n" );
	exit( 1 );
}

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', (int) $ec_page_id );

printf( "Homepage %s (id %d) and set as the front page.\n", $ec_action, (int) $ec_page_id );
