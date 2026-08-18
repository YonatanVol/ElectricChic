#!/usr/bin/env bash
#
# provision-remote.sh — turn a bare managed-WordPress account into the
# ElectricChic demo, once.
#
#   ./scripts/provision-remote.sh                 provision, keep demo mode on
#   ./scripts/provision-remote.sh --skip-content  code and settings only
#
# RUNS ON THE HOST, not on your laptop. Deploy the repository first (the GitHub
# Actions workflow does this), then run it over SSH from the WordPress root.
#
# WHY THIS EXISTS RATHER THAN A DATABASE DUMP
#
# The whole demo is reproducible from the repository: the catalogue is a JSON
# file, the homepage is a script, the availability facts are a script. So a
# fresh host is provisioned by re-running them rather than by importing a dump
# from a laptop.
#
# That matters more than convenience. A dump carries whatever happened to be in
# the local database — test orders, a developer's email address, half-finished
# drafts, a password hash. This carries only what is in version control and has
# been through code review, which is also why staging and production can never
# quietly diverge from what the repository says they are.
#
# CONTAINS NO CREDENTIALS. It uses the wp-config.php the host already wrote.
# Nothing here should ever be given a password, a key or a hostname.

set -euo pipefail

SKIP_CONTENT=0
[[ "${1:-}" == "--skip-content" ]] && SKIP_CONTENT=1

step() { printf '\n==> %s\n' "$1"; }
ok()   { printf '  ✓ %s\n' "$1"; }
warn() { printf '  ! %s\n' "$1"; }
die()  { printf '  ✗ %s\n' "$1" >&2; exit 1; }

# Managed hosts nearly always ship wp-cli; fall back to a local phar if not.
WP="$(command -v wp || true)"
[[ -n "${WP}" ]] || die "wp-cli not found on this host. Install it, or ask the host to."

# Never run as root against a WordPress install; file ownership ends up wrong
# and the next plugin update fails in a way nobody connects to this script.
if [[ "$(id -u)" == "0" ]]; then
	warn "Running as root. File ownership may end up wrong for the web user."
fi

# -----------------------------------------------------------------------------

step "Checking WordPress"

"${WP}" core is-installed >/dev/null 2>&1 || die "No WordPress here. Run this from the WordPress root."
ok "WordPress $("${WP}" core version) is installed"

php_version="$("${WP}" eval 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')"
ok "PHP ${php_version}"

case "${php_version}" in
	8.2*|8.3*|8.4*) ;;
	*) warn "PHP ${php_version} is outside the supported range (8.2+). CI does not test it." ;;
esac

# -----------------------------------------------------------------------------

step "WooCommerce"

if ! "${WP}" plugin is-installed woocommerce >/dev/null 2>&1; then
	"${WP}" plugin install woocommerce --activate
else
	"${WP}" plugin activate woocommerce >/dev/null 2>&1 || true
fi
ok "WooCommerce $("${WP}" plugin get woocommerce --field=version) active"

# -----------------------------------------------------------------------------
#
# HPOS must be enabled before a single order exists. Afterwards it is a data
# migration rather than a setting, and the whole point of doing it now is that
# there is nothing to migrate. Refusing is the correct behaviour: a script that
# silently starts migrating a live shop's orders is far worse than one that
# stops and asks.

step "High-Performance Order Storage"

#
# Fail CLOSED. The first version swallowed any error to "0", which took the
# "enable it anyway" branch — so a WooCommerce that had not loaded, a PHP
# fatal or a memory limit would silently start migrating a live shop's orders.
# The comment above says a script that does that is far worse than one that
# stops and asks; the error handling did the opposite of the comment.
#
if ! order_count="$("${WP}" eval '
	if ( ! function_exists( "wc_get_orders" ) ) { throw new RuntimeException( "WooCommerce not loaded" ); }
	echo count( wc_get_orders( array( "limit" => 1, "return" => "ids", "status" => "any" ) ) );
' 2>/dev/null)"; then
	die "Could not count existing orders. Refusing to touch HPOS while blind."
fi

if [[ ! "${order_count}" =~ ^[0-9]+$ ]]; then
	die "Order count came back as '${order_count}', which is not a number. Refusing to touch HPOS."
fi

if [[ "${order_count}" != "0" ]]; then
	warn "Orders already exist. Enabling HPOS now would be a migration, not a setting."
	warn "Refusing to change it. See docs/architecture/hpos-enforcement.md"
else
	"${WP}" option update woocommerce_custom_orders_table_enabled yes >/dev/null
	"${WP}" option update woocommerce_feature_custom_order_tables_enabled yes >/dev/null
	# Required by ShopConfigurationSpec and omitted by the first version: with
	# sync on, WooCommerce keeps writing orders to wp_posts as well, which is
	# the slow path this whole decision exists to avoid.
	"${WP}" option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
	ok "HPOS enabled with zero orders present, post-table sync off"
fi

# -----------------------------------------------------------------------------

step "ElectricChic theme and plugin"

"${WP}" theme is-installed twentytwentyfive >/dev/null 2>&1 || "${WP}" theme install twentytwentyfive
"${WP}" theme activate electricchic-child >/dev/null 2>&1 \
	|| die "electricchic-child not found. Deploy the repository before provisioning."
ok "Child theme active"

"${WP}" plugin activate electricchic-core >/dev/null 2>&1 \
	|| die "electricchic-core not found. Deploy the repository before provisioning."
ok "Core plugin active"

# -----------------------------------------------------------------------------

step "Shop settings"

#
# Every key ShopConfigurationSpec declares mandatory, applied here.
#
# The first version set five of the fourteen, and set one of them wrong
# (IL:TA where the spec requires IL, marked critical). Missing entirely were
# woocommerce_calc_taxes and woocommerce_prices_include_tax — both critical —
# so a shop provisioned from it would have launched with VAT calculation off.
# That is an accounting and consumer-protection failure, not a config nit.
#
# permalink_structure was also absent, which matters immediately: without it
# /shop/ and /checkout/ do not resolve, so every catalogue check in
# verify-deployment.sh 404s and reports a broken site it cannot explain.
#
# Booleans go in as WooCommerce stores them ('yes'/'no'); the auditor
# reconciles that against the spec's real PHP booleans.
#
"${WP}" option update WPLANG "he_IL" >/dev/null
"${WP}" option update timezone_string "Asia/Jerusalem" >/dev/null
"${WP}" option update woocommerce_currency ILS >/dev/null
"${WP}" option update woocommerce_default_country "IL" >/dev/null
"${WP}" option update woocommerce_calc_taxes yes >/dev/null
"${WP}" option update woocommerce_prices_include_tax yes >/dev/null
"${WP}" option update woocommerce_enable_guest_checkout yes >/dev/null
"${WP}" option update woocommerce_manage_stock yes >/dev/null
"${WP}" option update woocommerce_notify_low_stock yes >/dev/null
"${WP}" option update woocommerce_enable_reviews yes >/dev/null
"${WP}" option update comment_moderation 1 >/dev/null
"${WP}" rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || \
	"${WP}" option update permalink_structure '/%postname%/' >/dev/null
"${WP}" rewrite flush --hard >/dev/null 2>&1 || true
ok "14 mandatory settings applied, including VAT and permalinks"

#
# blog_public follows demo mode rather than being pinned off forever.
#
# The first version set it to 0 unconditionally and nothing ever set it back,
# so the real shop would have launched deindexed from Google with nothing to
# detect it. DemoMode already forces a noindex meta tag while the demo is on,
# so this only needs to track the same switch.
#
if "${WP}" eval 'echo ElectricChic\Core\Integration\DemoMode::is_active() ? "1" : "0";' 2>/dev/null | grep -q 1; then
	"${WP}" option update blog_public 0 >/dev/null
	ok "Search engines discouraged (demo mode is on)"
else
	"${WP}" option update blog_public 1 >/dev/null
	ok "Search engines allowed (demo mode is off — this is a live shop)"
fi

# WooCommerce ships new stores behind a "coming soon" placeholder that covers
# the entire shop. It cost an afternoon locally; it would cost a demo here.
"${WP}" option update woocommerce_coming_soon no >/dev/null 2>&1 || true
ok "Coming-soon placeholder disabled"

# -----------------------------------------------------------------------------

if [[ "${SKIP_CONTENT}" == "0" ]]; then
	step "Content"

	"${WP}" eval-file scripts/seed-cortez-catalogue.php
	"${WP}" eval-file scripts/generate-placeholder-images.php
	"${WP}" eval-file scripts/build-homepage.php

	ok "Catalogue, images and homepage rebuilt from the repository"
else
	step "Content"
	ok "Skipped (--skip-content)"
fi

# -----------------------------------------------------------------------------

step "Demo mode"

if "${WP}" eval 'echo ElectricChic\Core\Integration\DemoMode::is_active() ? "on" : "off";' 2>/dev/null | grep -q on; then
	ok "Demo mode is ON — banner shown, orders refused, noindex forced"
else
	warn "Demo mode is OFF. EC_DEMO_MODE is defined false in wp-config.php."
	warn "This site will accept real orders at prices nobody has confirmed."
fi

# -----------------------------------------------------------------------------
#
# Run the audit rather than trusting the lines above.
#
# audit-configuration.php was written specifically to catch configuration drift
# and was never invoked by this script or by the deploy workflow, so it only
# ever passed on the developer's laptop — the one machine where it does not
# matter. Setting an option and verifying it are different claims.

step "Auditing the configuration"

if [[ -f scripts/audit-configuration.php ]]; then
	if "${WP}" eval-file scripts/audit-configuration.php; then
		ok "Configuration matches ShopConfigurationSpec"
	else
		warn "Configuration audit reported problems — see above. Fix before launch."
	fi
else
	warn "scripts/audit-configuration.php not deployed; configuration is unverified."
fi

printf '\n'
ok "Provisioning complete. Run ./scripts/verify-deployment.sh <url> to prove it."
