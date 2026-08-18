<?php
/**
 * Where the shop owner records the facts.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Suppliers;

use ElectricChic\Core\Availability\AvailabilityLabels;
use ElectricChic\Core\Integration\ProductStockFactsReader;
use WC_Product;

/**
 * The product-edit panel behind the whole model.
 *
 * Every field here is a FACT. There is no "availability" dropdown, and adding
 * one would undo the point of the project: a typed availability is a field
 * somebody has to remember to change, and the one they forget is the one that
 * promises a bike the shop cannot deliver.
 *
 * The panel shows the resolved state read-only at the top, so the owner can see
 * what the facts produce without saving and reloading. It is Hebrew and written
 * in plain language, because the person using it runs a bicycle shop.
 */
final class ProductSupplierFields {

	/**
	 * Build the admin panel over the availability model.
	 *
	 * @param ProductStockFactsReader $reader Resolves the live preview.
	 * @param AvailabilityLabels      $labels Hebrew wording.
	 */
	public function __construct(
		private readonly ProductStockFactsReader $reader = new ProductStockFactsReader(),
		private readonly AvailabilityLabels $labels = new AvailabilityLabels(),
	) {}

	/**
	 * Attach to WooCommerce.
	 *
	 * @return void
	 */
	public function register(): void {
		( new SupplierPostType() )->register();

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	/**
	 * Add the availability tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['ec_availability'] = array(
			'label'    => __( 'זמינות ואספקה', 'electricchic' ),
			'target'   => 'ec_availability_panel',
			'class'    => array(),
			'priority' => 25,
		);

		return $tabs;
	}

	/**
	 * Render the panel.
	 *
	 * @return void
	 */
	public function render_panel(): void {
		global $post;

		$product = wc_get_product( $post->ID ?? 0 );

		// `hidden` matters: WooCommerce hides panels only once its tab script runs,
		// so without it this one flashes on screen before the right tab is chosen.
		echo '<div id="ec_availability_panel" class="panel woocommerce_options_panel hidden">';

		if ( $product instanceof WC_Product ) {
			$state = $this->reader->state_for( $product );

			printf(
				'<p style="margin:12px;padding:10px 12px;background:#f0f6fc;border-inline-start:4px solid #2271b1;">
					<strong>%1$s</strong> %2$s<br>
					<span style="color:#646970;">%3$s</span>
				</p>',
				esc_html__( 'מה הלקוח רואה כעת:', 'electricchic' ),
				esc_html( $this->labels->for_state( $state, $this->reader->facts_for( $product ) ) ),
				esc_html__( 'הזמינות מחושבת אוטומטית מהנתונים בעמוד זה. אין צורך — ואין אפשרות — לבחור אותה ידנית.', 'electricchic' )
			);
		}

		woocommerce_wp_select(
			array(
				'id'          => ProductStockFactsReader::META_SUPPLIER_ID,
				'label'       => __( 'ספק', 'electricchic' ),
				'options'     => array( '' => __( '— ללא ספק —', 'electricchic' ) ) + SupplierPostType::options(),
				'description' => __( 'מי מספק את הפריט כשהוא אינו במלאי החנות.', 'electricchic' ),
				'desc_tip'    => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => ProductStockFactsReader::META_SUPPLIER_STOCK,
				'label'             => __( 'מלאי אצל הספק', 'electricchic' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'description'       => __( 'השאירו ריק אם לא ידוע. ריק אינו זהה לאפס: ריק פירושו שלא נבדק, ואפס פירושו שנבדק ואין. הערך הזה לעולם אינו מגדיל את המלאי למכירה באתר.', 'electricchic' ),
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => ProductStockFactsReader::META_SUPPLIER_UPDATED,
				'label'       => __( 'תאריך עדכון מהספק', 'electricchic' ),
				'type'        => 'date',
				'description' => __( 'מתי נבדק המלאי מול הספק. אחרי שבוע האתר מפסיק להציג "זמין בהזמנה" ועובר ל"הזמנה מיוחדת".', 'electricchic' ),
				'desc_tip'    => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => ProductStockFactsReader::META_LEAD_TIME_MIN,
				'label'             => __( 'זמן אספקה — מינימום (ימי עסקים)', 'electricchic' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => ProductStockFactsReader::META_LEAD_TIME_MAX,
				'label'             => __( 'זמן אספקה — מקסימום (ימי עסקים)', 'electricchic' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'description'       => __( 'אם שני השדות ריקים, לא יוצג ללקוח שום זמן אספקה — עדיף מאשר להבטיח תאריך שאיש לא בדק.', 'electricchic' ),
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => ProductStockFactsReader::META_REQUIRES_CONFIRM,
				'label'       => __( 'נדרש אישור זמינות', 'electricchic' ),
				'description' => __( 'הלקוח לא יוכל לשלם עד שתאשרו מול הספק. חל רק כשאין מלאי בחנות.', 'electricchic' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => ProductStockFactsReader::META_ENQUIRY_ONLY,
				'label'       => __( 'לפי פנייה בלבד', 'electricchic' ),
				'description' => __( 'הפריט מוצג אך אינו נמכר באתר. ללקוח יוצג "צרו קשר".', 'electricchic' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => ProductStockFactsReader::META_DISCONTINUED,
				'label'       => __( 'הופסק ייצור', 'electricchic' ),
				'description' => __( 'העמוד נשאר באתר לצורכי חיפוש ותמיכה, אך לא ניתן לרכוש.', 'electricchic' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Persist the fields.
	 *
	 * Written through the WooCommerce CRUD object rather than update_post_meta,
	 * which is the rule the custom PHPCS sniff enforces for orders and is the
	 * right habit everywhere.
	 *
	 * @param WC_Product $product The product being saved.
	 * @return void
	 */
	public function save( WC_Product $product ): void {
		// WooCommerce has already verified the product-save nonce before this
		// hook fires; re-reading $_POST here is the documented pattern.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		$errors = array();

		$supplier_id = isset( $_POST[ ProductStockFactsReader::META_SUPPLIER_ID ] )
			? absint( wp_unslash( $_POST[ ProductStockFactsReader::META_SUPPLIER_ID ] ) )
			: 0;

		$product->update_meta_data( ProductStockFactsReader::META_SUPPLIER_ID, $supplier_id > 0 ? (string) $supplier_id : '' );

		// Supplier stock: blank means "not checked", which is NOT zero. Only a
		// non-negative whole number is meaningful; anything else is rejected
		// rather than silently coerced, because (int) 'שבע' is 0 and 0 is a
		// claim the shop checked and found none.
		$raw_stock = isset( $_POST[ ProductStockFactsReader::META_SUPPLIER_STOCK ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ ProductStockFactsReader::META_SUPPLIER_STOCK ] ) ) )
			: '';

		if ( '' === $raw_stock ) {
			$product->update_meta_data( ProductStockFactsReader::META_SUPPLIER_STOCK, '' );
		} elseif ( ctype_digit( $raw_stock ) ) {
			$product->update_meta_data( ProductStockFactsReader::META_SUPPLIER_STOCK, $raw_stock );
		} else {
			$errors[] = __( 'מלאי אצל הספק חייב להיות מספר שלם או ריק. הערך לא נשמר.', 'electricchic' );
		}

		// A real calendar date, not merely a date-shaped string. checkdate()
		// rejects 2026-02-30, which DateTimeImmutable would happily roll forward
		// to 2 March — quietly making a stale supplier report look fresher.
		$raw_date = isset( $_POST[ ProductStockFactsReader::META_SUPPLIER_UPDATED ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ ProductStockFactsReader::META_SUPPLIER_UPDATED ] ) ) )
			: '';

		if ( '' === $raw_date ) {
			$product->update_meta_data( ProductStockFactsReader::META_SUPPLIER_UPDATED, '' );
		} elseif ( $this->is_real_date( $raw_date ) ) {
			$product->update_meta_data( ProductStockFactsReader::META_SUPPLIER_UPDATED, $raw_date );
		} else {
			$errors[] = __( 'תאריך העדכון מהספק אינו תאריך תקין. הערך לא נשמר.', 'electricchic' );
		}

		$min = $this->whole_number( ProductStockFactsReader::META_LEAD_TIME_MIN );
		$max = $this->whole_number( ProductStockFactsReader::META_LEAD_TIME_MAX );

		// StockFacts throws on an inverted range, and that exception would be
		// raised while RENDERING a product rather than while saving it — a
		// white screen on the shop, caused by a typo in the admin an hour
		// earlier. Catch it at the point of entry, where it can be explained.
		if ( null !== $min && null !== $max && $max < $min ) {
			$errors[] = __( 'זמן האספקה המקסימלי אינו יכול להיות קצר מהמינימלי. זמני האספקה לא נשמרו.', 'electricchic' );
		} else {
			$product->update_meta_data( ProductStockFactsReader::META_LEAD_TIME_MIN, null === $min ? '' : (string) $min );
			$product->update_meta_data( ProductStockFactsReader::META_LEAD_TIME_MAX, null === $max ? '' : (string) $max );
		}

		foreach ( array(
			ProductStockFactsReader::META_REQUIRES_CONFIRM,
			ProductStockFactsReader::META_ENQUIRY_ONLY,
			ProductStockFactsReader::META_DISCONTINUED,
		) as $key ) {
			$product->update_meta_data( $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( array() !== $errors ) {
			$this->remember_errors( $errors );
		}
	}

	/**
	 * A non-negative whole number from $_POST, or null when blank/invalid.
	 *
	 * @param string $key POST key.
	 * @return int|null
	 */
	private function whole_number( string $key ): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product-save nonce before this hook.
		$raw = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';

		return ( '' !== $raw && ctype_digit( $raw ) ) ? (int) $raw : null;
	}

	/**
	 * Whether a string is a real YYYY-MM-DD calendar date.
	 *
	 * @param string $value Candidate date.
	 * @return bool
	 */
	private function is_real_date( string $value ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Show rejected values back to the person who typed them.
	 *
	 * Silently dropping a bad value is worse than rejecting it: the shop owner
	 * believes the supplier date is saved, the badge keeps saying something
	 * else, and nobody connects the two.
	 *
	 * @param string[] $errors Messages.
	 * @return void
	 */
	private function remember_errors( array $errors ): void {
		set_transient( 'ec_availability_admin_errors_' . get_current_user_id(), $errors, 60 );

		add_action(
			'admin_notices',
			static function () use ( $errors ): void {
				foreach ( $errors as $message ) {
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
				}
			}
		);
	}
}
