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

		echo '<div id="ec_availability_panel" class="panel woocommerce_options_panel">';

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

		foreach ( array(
			ProductStockFactsReader::META_SUPPLIER_ID,
			ProductStockFactsReader::META_SUPPLIER_STOCK,
			ProductStockFactsReader::META_SUPPLIER_UPDATED,
			ProductStockFactsReader::META_LEAD_TIME_MIN,
			ProductStockFactsReader::META_LEAD_TIME_MAX,
		) as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$product->update_meta_data( $key, $value );
		}

		foreach ( array(
			ProductStockFactsReader::META_REQUIRES_CONFIRM,
			ProductStockFactsReader::META_ENQUIRY_ONLY,
			ProductStockFactsReader::META_DISCONTINUED,
		) as $key ) {
			$product->update_meta_data( $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
