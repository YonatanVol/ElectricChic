<?php
/**
 * Plugin Name:       Electric Chic Core
 * Description:       Business logic for the Electric Chic shop: the availability model, supplier records and configuration auditing. Presentation lives in the child theme.
 * Version:           0.3.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Electric Chic
 * Text Domain:       electricchic
 * Domain Path:       /languages
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.3.0';
const PLUGIN_FILE = __FILE__;

/**
 * Map the ElectricChic\Core namespace onto includes/.
 *
 * Deliberately not Composer's autoloader: vendor/ is a development dependency
 * here and is not deployed with the plugin. A plugin that fatals because
 * vendor/ was not uploaded is a bad afternoon, so the runtime path stays
 * dependency-free and Composer's autoloader is used only by the test suite.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Wire the plugin up once WooCommerce is known to be present.
 *
 * Every integration below assumes WooCommerce exists. Hooking on plugins_loaded
 * and checking rather than assuming means a deactivated WooCommerce degrades to
 * "no availability badges" instead of a white screen — the shop owner is not
 * technical, and a fatal error is not a message they can act on.
 *
 * @return void
 */
function bootstrap(): void {
	// Registered before the WooCommerce check: a demo site must announce itself
	// even if WooCommerce is broken or deactivated. A half-working shop is
	// exactly when someone most needs telling it is not real.
	( new Integration\DemoMode() )->register();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_woocommerce_notice' );

		return;
	}

	( new Integration\AvailabilityDisplay() )->register();
	( new Integration\PurchasabilityGuard() )->register();
	( new Suppliers\ProductSupplierFields() )->register();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Tell an administrator why nothing is working.
 *
 * @return void
 */
function render_missing_woocommerce_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Electric Chic Core requires WooCommerce to be installed and active. Availability information is not being displayed.', 'electricchic' )
	);
}

/**
 * Declare compatibility with High-Performance Order Storage.
 *
 * Without this WooCommerce treats the plugin as HPOS-incompatible and will
 * refuse to enable HPOS at all. The codebase already enforces the underlying
 * rule with a custom PHPCS sniff, so the declaration is accurate rather than
 * aspirational.
 *
 * @return void
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
		}
	}
);
