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

order_count="$("${WP}" eval '
	if ( ! function_exists( "wc_get_orders" ) ) { echo "0"; return; }
	echo count( wc_get_orders( array( "limit" => 1, "return" => "ids", "status" => "any" ) ) );
' 2>/dev/null || echo "0")"

if [[ "${order_count}" != "0" ]]; then
	warn "Orders already exist. Enabling HPOS now would be a migration, not a setting."
	warn "Refusing to change it. See docs/architecture/hpos-enforcement.md"
else
	"${WP}" option update woocommerce_custom_orders_table_enabled yes >/dev/null
	"${WP}" option update woocommerce_feature_custom_order_tables_enabled yes >/dev/null
	ok "HPOS enabled with zero orders present"
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

"${WP}" option update woocommerce_currency ILS >/dev/null
"${WP}" option update woocommerce_default_country "IL:TA" >/dev/null
"${WP}" option update woocommerce_store_country "IL:TA" >/dev/null
"${WP}" option update timezone_string "Asia/Jerusalem" >/dev/null
"${WP}" option update blog_public 0 >/dev/null
ok "Currency ILS, Israel, Asia/Jerusalem, search engines discouraged"

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

printf '\n'
ok "Provisioning complete. Run ./scripts/verify-deployment.sh to prove it."
