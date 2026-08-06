<?php
/**
 * The configuration this shop must have.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Configuration;

/**
 * The executable version of Issue #08's acceptance criteria.
 *
 * A runbook that says "set the currency to ILS" is a step somebody can skip.
 * This is the same statement in a form that can be checked, in CI, on every
 * environment, for as long as the shop exists. Configuration drift — someone
 * toggling a setting in wp-admin months later — is otherwise invisible until a
 * customer is charged in the wrong currency.
 *
 * Booleans are declared as real PHP booleans. WordPress will return 'yes',
 * 'no', '1' or '' and the auditor reconciles that; declaring them as bools is
 * what tells the auditor to be lenient, so a numeric setting of 0 is never
 * mistaken for "off". See ConfigurationAuditor::matches().
 */
final class ShopConfigurationSpec {

	/**
	 * Every setting the shop is required to have.
	 *
	 * @return array<int, SettingRequirement>
	 */
	public static function requirements(): array {
		return array(
			...self::order_storage(),
			...self::locale_and_currency(),
			...self::taxes(),
			...self::commerce_behaviour(),
			...self::content(),
		);
	}

	/**
	 * High-Performance Order Storage.
	 *
	 * @return array<int, SettingRequirement>
	 */
	private static function order_storage(): array {
		return array(
			new SettingRequirement(
				key: 'woocommerce_custom_orders_table_enabled',
				label: 'High-Performance Order Storage',
				expected: true,
				rationale: 'Decision D20. HPOS must be enabled before the first order exists — '
					. 'switching afterwards is a migration on a live shop. All order access goes '
					. 'through WooCommerce CRUD APIs, enforced by the ElectricChic.HPOS sniff.',
				critical: true
			),
			new SettingRequirement(
				key: 'woocommerce_custom_orders_table_data_sync_enabled',
				label: 'HPOS legacy synchronisation',
				expected: false,
				rationale: 'Sync mirrors orders back into wp_posts, which only matters while '
					. 'migrating an existing shop. On a shop that started on HPOS it is pure '
					. 'write overhead on every order, and it keeps alive the legacy tables that '
					. 'code should no longer be reading.',
				critical: false
			),
		);
	}

	/**
	 * Language, currency and place.
	 *
	 * @return array<int, SettingRequirement>
	 */
	private static function locale_and_currency(): array {
		return array(
			new SettingRequirement(
				key: 'WPLANG',
				label: 'Site language',
				expected: 'he_IL',
				rationale: 'The shop is Hebrew-first and RTL-native. The locale drives text '
					. 'direction, translations, and date and number formatting.',
				critical: true
			),
			new SettingRequirement(
				key: 'timezone_string',
				label: 'Timezone',
				expected: 'Asia/Jerusalem',
				rationale: 'Order timestamps, delivery promises and the pickup-ready window are '
					. 'all read by staff in local time. A named zone rather than a UTC offset, '
					. 'so daylight saving is handled.',
				critical: true
			),
			new SettingRequirement(
				key: 'woocommerce_currency',
				label: 'Currency',
				expected: 'ILS',
				rationale: 'The shop sells in shekels. A wrong currency charges the wrong amount.',
				critical: true
			),
			new SettingRequirement(
				key: 'woocommerce_default_country',
				label: 'Store base country',
				expected: 'IL',
				rationale: 'Drives tax rates, shipping zone matching and address formatting.',
				critical: true
			),
		);
	}

	/**
	 * VAT handling.
	 *
	 * ❗ The rate itself and the shop's VAT registration status are accounting
	 * decisions and must be confirmed with the client's accountant. What is
	 * asserted here is only that tax is calculated and that displayed prices
	 * include it, which is the Israeli retail convention.
	 *
	 * @return array<int, SettingRequirement>
	 */
	private static function taxes(): array {
		return array(
			new SettingRequirement(
				key: 'woocommerce_calc_taxes',
				label: 'Tax calculation',
				expected: true,
				rationale: 'VAT must be calculated and must appear correctly on the tax document '
					. 'issued by the invoicing provider.',
				critical: true
			),
			new SettingRequirement(
				key: 'woocommerce_prices_include_tax',
				label: 'Prices include tax',
				expected: true,
				rationale: 'Israeli retail prices are quoted VAT-inclusive. Showing a price that '
					. 'grows at checkout is both a conversion problem and a consumer-protection '
					. 'risk. ❗Confirm treatment with the client accountant.',
				critical: true
			),
		);
	}

	/**
	 * Checkout and stock behaviour.
	 *
	 * @return array<int, SettingRequirement>
	 */
	private static function commerce_behaviour(): array {
		return array(
			new SettingRequirement(
				key: 'woocommerce_enable_guest_checkout',
				label: 'Guest checkout',
				expected: true,
				rationale: 'Forcing account creation is a well-established source of abandonment, '
					. 'and most accessory purchases here are one-off.',
				critical: false
			),
			new SettingRequirement(
				key: 'woocommerce_manage_stock',
				label: 'Stock management',
				expected: true,
				rationale: 'Stock is the source of truth for the availability model. Without it, '
					. 'the resolver cannot distinguish "in the shop" from "orderable from a '
					. 'supplier", which is the distinction the whole design rests on.',
				critical: true
			),
			new SettingRequirement(
				key: 'woocommerce_notify_low_stock',
				label: 'Low stock notifications',
				expected: true,
				rationale: 'The owner restocks manually. Without a notification, the first signal '
					. 'that something ran out is a customer being unable to buy it.',
				critical: false
			),
		);
	}

	/**
	 * Content, reviews and URLs.
	 *
	 * @return array<int, SettingRequirement>
	 */
	private static function content(): array {
		return array(
			new SettingRequirement(
				key: 'woocommerce_enable_reviews',
				label: 'Product reviews',
				expected: true,
				rationale: 'Reviews are a trust signal for a shop competing with marketplaces, '
					. 'and they feed product structured data.',
				critical: false
			),
			new SettingRequirement(
				key: 'comment_moderation',
				label: 'Reviews held for moderation',
				expected: true,
				rationale: 'A public review form on a small shop attracts spam. Holding reviews '
					. 'costs the owner a few minutes and prevents the shop advertising something '
					. 'it did not write.',
				critical: false
			),
			new SettingRequirement(
				key: 'permalink_structure',
				label: 'Permalink structure',
				expected: '/%postname%/',
				rationale: 'Readable URLs for SEO. Changing this after launch requires redirects '
					. 'for every existing product, so it is set once at the start.',
				critical: true
			),
		);
	}
}
