#!/usr/bin/env bash
#
# setup-wordpress.sh — install and configure a local WordPress + WooCommerce
# for ElectricChic, from nothing to a browsable Hebrew shop.
#
#   ./scripts/setup-wordpress.sh          install and configure
#   ./scripts/setup-wordpress.sh --seed   also seed demo products
#
# Installs WordPress into the repository root. That is deliberate: .gitignore
# already excludes WordPress core, uploads and third-party plugins, while
# tracking wp-content/plugins/electricchic-core and
# wp-content/themes/electricchic-child. So the plugin and theme sit at exactly
# the paths WordPress expects, with no symlinks to go stale.
#
# LOCAL DEVELOPMENT ONLY. The credentials below are fixed and public; they exist
# so the environment is reproducible, not so it is secure. Never point this at
# anything reachable from outside your machine.

set -euo pipefail

readonly DB_NAME="electricchic"
readonly DB_USER="electricchic"
readonly DB_PASS="electricchic_dev"
readonly SITE_URL="http://localhost:8080"
readonly ADMIN_USER="admin"
readonly ADMIN_PASS="demo_admin_pw"

SEED=0
[[ "${1:-}" == "--seed" ]] && SEED=1

cd "$(dirname "${BASH_SOURCE[0]}")/.."
readonly WP="./scripts/wp"

step() { printf '\n==> %s\n' "$1"; }
ok()   { printf '  ✓ %s\n' "$1"; }
die()  { printf '  ✗ %s\n' "$1" >&2; exit 1; }

# -----------------------------------------------------------------------------

step "Checking prerequisites"

command -v mysql >/dev/null 2>&1 || die "MySQL not found. Run: brew install mysql && brew services start mysql"
mysql -u root -e "SELECT 1;" >/dev/null 2>&1 || die "Cannot connect to MySQL. Run: brew services start mysql"
ok "MySQL reachable"

[[ -x "${WP}" ]] || die "scripts/wp missing. Run ./scripts/bootstrap-local.sh"
ok "WP-CLI wrapper present"

# -----------------------------------------------------------------------------

step "Database"

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "Database ${DB_NAME} ready"

# -----------------------------------------------------------------------------

step "WordPress"

if [[ ! -f wp-settings.php ]]; then
	# Core is downloaded in English; Hebrew arrives as a language pack below.
	# WP-CLI's --locale check rejects he_IL at download time even though the
	# translation exists.
	"${WP}" core download --force
	ok "Core downloaded"
else
	ok "Core already present"
fi

"${WP}" config create \
	--dbname="${DB_NAME}" \
	--dbuser="${DB_USER}" \
	--dbpass="${DB_PASS}" \
	--dbhost=127.0.0.1 \
	--skip-check --force >/dev/null
ok "wp-config.php written (gitignored)"

if ! "${WP}" core is-installed 2>/dev/null; then
	"${WP}" core install \
		--url="${SITE_URL}" \
		--title="ElectricChic" \
		--admin_user="${ADMIN_USER}" \
		--admin_password="${ADMIN_PASS}" \
		--admin_email="dev@example.test" \
		--skip-email >/dev/null
	ok "WordPress installed"
else
	ok "WordPress already installed"
fi

"${WP}" language core install he_IL --activate >/dev/null 2>&1 || true
ok "Hebrew activated"

# -----------------------------------------------------------------------------

step "WooCommerce"

if ! "${WP}" plugin is-installed woocommerce 2>/dev/null; then
	"${WP}" plugin install woocommerce --activate >/dev/null
	ok "WooCommerce installed and activated"
else
	"${WP}" plugin activate woocommerce >/dev/null 2>&1 || true
	ok "WooCommerce already installed"
fi

"${WP}" language plugin install woocommerce he_IL >/dev/null 2>&1 || true
ok "WooCommerce Hebrew installed"

# -----------------------------------------------------------------------------

step "High-Performance Order Storage"

# HPOS must be enabled before any order exists. Switching afterwards is a
# migration on a live shop, which is why this runs before anything can create
# an order rather than as a later configuration step.
ORDER_COUNT="$("${WP}" post list --post_type=shop_order --format=count 2>/dev/null || echo 0)"

if [[ "${ORDER_COUNT}" -gt 0 ]]; then
	printf '  ! %s orders already exist. Enabling HPOS now means a migration.\n' "${ORDER_COUNT}"
	printf '    Refusing to continue automatically. See docs/architecture/hpos-enforcement.md\n'
	exit 1
fi

"${WP}" option update woocommerce_feature_custom_order_tables_enabled yes >/dev/null
"${WP}" option update woocommerce_custom_orders_table_enabled yes >/dev/null
"${WP}" option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
ok "HPOS enabled with zero orders present"

# -----------------------------------------------------------------------------

step "Shop configuration"

# These mirror ShopConfigurationSpec. The audit at the end verifies they took.
"${WP}" option update woocommerce_currency ILS >/dev/null
"${WP}" option update woocommerce_default_country IL >/dev/null
"${WP}" option update timezone_string "Asia/Jerusalem" >/dev/null
"${WP}" option update WPLANG he_IL >/dev/null
"${WP}" option update woocommerce_calc_taxes yes >/dev/null
"${WP}" option update woocommerce_prices_include_tax yes >/dev/null
"${WP}" option update woocommerce_enable_guest_checkout yes >/dev/null
"${WP}" option update woocommerce_manage_stock yes >/dev/null
"${WP}" option update woocommerce_notify_low_stock yes >/dev/null
"${WP}" option update woocommerce_enable_reviews yes >/dev/null
"${WP}" option update comment_moderation 1 >/dev/null
"${WP}" option update woocommerce_currency_pos left_space >/dev/null
"${WP}" option update woocommerce_price_thousand_sep ',' >/dev/null
"${WP}" option update woocommerce_price_decimal_sep '.' >/dev/null
"${WP}" option update woocommerce_price_num_decimals 2 >/dev/null
"${WP}" option update blogname "ElectricChic" >/dev/null
"${WP}" rewrite structure '/%postname%/' --hard >/dev/null 2>&1
ok "Settings applied"

# Hebrew titles for the WooCommerce system pages.
for pair in "shop:חנות" "cart:סל קניות" "checkout:קופה" "myaccount:החשבון שלי"; do
	key="${pair%%:*}"
	title="${pair##*:}"
	page_id="$("${WP}" option get "woocommerce_${key}_page_id" 2>/dev/null || echo 0)"
	[[ "${page_id}" != "0" && -n "${page_id}" ]] && "${WP}" post update "${page_id}" --post_title="${title}" >/dev/null 2>&1 || true
done
ok "System pages renamed to Hebrew"

# -----------------------------------------------------------------------------

if (( SEED )); then
	step "Demo data"
	"${WP}" eval-file scripts/seed-demo-data.php
fi

# -----------------------------------------------------------------------------

step "Verifying configuration"

"${WP}" eval-file scripts/audit-configuration.php

cat <<EOF

Start the site:

  ./scripts/wp server --host=localhost --port=8080

  Shop  : ${SITE_URL}
  Admin : ${SITE_URL}/wp-admin  (${ADMIN_USER} / ${ADMIN_PASS})

EOF
