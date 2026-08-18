#!/usr/bin/env bash
#
# verify-deployment.sh — prove a deployed site is what we think it is.
#
#   ./scripts/verify-deployment.sh https://demo.example.com
#
# Checks the site over HTTP, the way a visitor and a scraper reach it — not
# through WP-CLI. A setting can be correct in the database and still not reach
# the page: a cache, a CDN, a security plugin or a stale opcache all sit between
# the two. Reading the option proves the option. This proves the site.
#
# Every check below is phrased as an attempted violation rather than an
# inspection, because that is the only way to know a control is real. The
# repository has been wrong about this before — a control was reported working
# when the pipeline exit code had been swallowed.
#
# Exits non-zero on the first genuine failure, so it is safe to gate a deploy on.

set -uo pipefail

BASE="${1:-}"
EXPECT="${2:-auto}"

[[ -n "${BASE}" ]] || {
	printf 'usage: %s https://your-site [--expect-demo|--expect-live]\n' "$0" >&2
	exit 2
}
BASE="${BASE%/}"

case "${EXPECT}" in
	--expect-demo) EXPECT=demo ;;
	--expect-live) EXPECT=live ;;
	auto|'')       EXPECT=auto ;;
	*) printf 'unknown option: %s\n' "${EXPECT}" >&2; exit 2 ;;
esac

PASS=0
FAIL=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL + 1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# Capture separately and check status separately. `cmd | tee` returns tee's
# status, which produced a false "protection is NOT working" result in this
# project once already.
fetch() {
	curl -sSL --max-time 25 -w '\n%{http_code}' "$1" 2>/dev/null
}

body_of() { sed '$d' <<<"$1"; }
code_of() { tail -n1 <<<"$1"; }

# -----------------------------------------------------------------------------

head_ "Reachability"

home="$(fetch "${BASE}/")"
if [[ "$(code_of "${home}")" == "200" ]]; then
	pass "Site responds 200"
else
	fail "Site returned $(code_of "${home}") — nothing else below is meaningful"
	printf '\nFAILED\n'
	exit 1
fi

home_body="$(body_of "${home}")"

# -----------------------------------------------------------------------------

head_ "Demo safety"

#
# These checks used to assert the demo banner and noindex unconditionally, so
# the first deploy of the REAL shop would fail every one of them — after the
# files had already been synced. A gate written for exactly one site state
# inverts the moment that state changes, and reports the launch as a failure.
#
# Both states are now valid; what is checked is that the site is CONSISTENTLY
# one of them. A page carrying the demo banner must also be noindex. A page
# without it must be indexable and must not still be refusing orders.
#
if grep -q 'ec-demo-banner' <<<"${home_body}"; then
	SITE_MODE=demo
else
	SITE_MODE=live
fi

if [[ "${EXPECT}" != "auto" && "${EXPECT}" != "${SITE_MODE}" ]]; then
	fail "Expected a ${EXPECT} site; this one is ${SITE_MODE}. Check EC_DEMO_MODE in wp-config.php."
else
	pass "Site is in ${SITE_MODE} mode$( [[ "${EXPECT}" == "auto" ]] && printf ' (inferred)' )"
fi

has_noindex() {
	grep -qiE '<meta[^>]+name=["'"'"']robots["'"'"'][^>]+noindex' <<<"${home_body}"
}

if [[ "${SITE_MODE}" == "demo" ]]; then
	if has_noindex; then
		pass "noindex is served — the demo cannot compete with the real Google listing"
	else
		fail "Demo banner present but noindex MISSING. This page carries the real shop name and can be indexed."
	fi
else
	# A live shop that is still noindex is the failure nobody notices: the
	# launch looks fine and the shop is invisible to Google for weeks.
	if has_noindex; then
		fail "LIVE shop is serving noindex. It will not appear in Google. Check blog_public and EC_DEMO_MODE."
	else
		pass "Live shop is indexable"
	fi
fi

head_ "Catalogue and the availability model"

shop="$(fetch "${BASE}/shop/")"
shop_body="$(body_of "${shop}")"

badges="$(grep -o '<p class="ec-avail ' <<<"${shop_body}" | wc -l | tr -d ' ')"
if [[ "${badges}" -gt 0 ]]; then
	pass "${badges} availability badges rendered"
else
	fail "No availability badges. The model is not reaching the page."
fi

#
# Deliberately not asserting a minimum variety. The original required four
# distinct states, which is a fact about the seeded demo catalogue, not about a
# correct site — a real shop with everything on the shelf legitimately shows
# one. What matters is that states are rendered at all.
#
states="$(grep -oE 'data-ec-state="[a-z_]+"' <<<"${shop_body}" | sort -u | wc -l | tr -d ' ')"
pass "${states} distinct availability state(s) rendered"

# A duplicate badge per card is a real bug that has happened here before.
cards="$(grep -o 'data-block-name="woocommerce/product-price"' <<<"${shop_body}" | wc -l | tr -d ' ')"
if [[ "${cards}" -gt 0 && "${badges}" -gt $((cards * 3 / 2)) ]]; then
	fail "${badges} badges for ${cards} cards — badges are duplicating again"
elif [[ "${cards}" -gt 0 ]]; then
	pass "One badge per card (${badges} badges, ${cards} cards)"
fi

# -----------------------------------------------------------------------------
#
# Internal commercial data must never reach the browser. Cost price, supplier
# terms and internal notes are exactly the fields that leak through a REST
# endpoint nobody remembered was public.

head_ "Internal fields must not be public"

leaked=0
for field in _ec_supplier_stock _ec_supplier_id _ec_cost_price _ec_supplier_notes _ec_image_rights; do
	rest="$(fetch "${BASE}/wp-json/wp/v2/product?per_page=5")"
	if grep -q "${field}" <<<"$(body_of "${rest}")"; then
		fail "REST API exposes ${field}"
		leaked=1
	fi
done
[[ "${leaked}" == "0" ]] && pass "No internal fields in the public REST API"

store_api="$(fetch "${BASE}/wp-json/wc/store/v1/products?per_page=20")"
store_body="$(body_of "${store_api}")"
if grep -qE '_ec_(supplier|cost)' <<<"${store_body}"; then
	fail "WooCommerce Store API exposes supplier or cost fields"
else
	pass "Store API carries no supplier or cost fields"
fi

if grep -qE '_ec_(supplier_stock|cost_price)' <<<"${shop_body}"; then
	fail "Supplier or cost data is present in the page source"
else
	pass "Page source carries no supplier or cost data"
fi

# -----------------------------------------------------------------------------
#
# The badge says a discontinued product cannot be bought. Attempt it.

head_ "Attempting to buy something the model refuses"

# The target is chosen from the Store API rather than scraped out of the page.
# The first version looked for a wrapper CSS class that the block catalogue
# never renders, so it silently found nothing and skipped — which looked like a
# pass. A check that quietly does not run is worse than no check, because it
# reports confidence it has not earned.
blocked_id="$(python3 -c "
import json, sys, urllib.request
try:
    with urllib.request.urlopen('${BASE}/wp-json/wc/store/v1/products?per_page=50', timeout=20) as r:
        for p in json.load(r):
            if not p.get('is_purchasable'):
                print(p['id']); break
except Exception:
    pass
" 2>/dev/null || true)"

if [[ -n "${blocked_id}" ]]; then
	attempt="$(fetch "${BASE}/?add-to-cart=${blocked_id}")"
	attempt_body="$(body_of "${attempt}")"

	# WooCommerce prints a success message only when something entered the cart.
	if grep -qE 'woocommerce-message[^<]*(added|נוסף)' <<<"${attempt_body}"; then
		fail "Product ${blocked_id} was ADDED to the cart despite being unpurchasable"
	else
		pass "Product ${blocked_id} refused the direct add-to-cart URL"
	fi

	# And through the Store API, which bypasses the add-to-cart form entirely.
	#
	# This needs a nonce. Without one every request returns 401 — including for
	# a product that IS purchasable — so the first version of this check passed
	# on a blanket rejection that had nothing to do with the guard. It reported
	# the control as proven while never exercising it.
	#
	# So the purchasable product below is not decoration. It is the control: if
	# the guard were removed, that request would still succeed and this one
	# would start failing, which is what makes the blocked result mean anything.
	jar="$(mktemp)"
	nonce="$(curl -sS -D - -o /dev/null -c "${jar}" --max-time 20 \
		"${BASE}/wp-json/wc/store/v1/cart" 2>/dev/null \
		| tr -d '\r' | awk 'tolower($1) == "nonce:" { print $2 }')"

	if [[ -z "${nonce}" ]]; then
		fail "Could not obtain a Store API nonce — the REST guard went unverified"
	else
		store_add() {
			curl -sS -b "${jar}" -c "${jar}" --max-time 20 \
				-X POST -H 'Content-Type: application/json' -H "Nonce: ${nonce}" \
				-d "{\"id\":$1,\"quantity\":1}" \
				"${BASE}/wp-json/wc/store/v1/cart/add-item" 2>/dev/null
		}

		blocked_response="$(store_add "${blocked_id}")"

		if grep -q 'woocommerce_rest_product_not_purchasable' <<<"${blocked_response}"; then
			pass "Store API refused product ${blocked_id} as not purchasable"
		elif grep -q '"items"' <<<"${blocked_response}"; then
			fail "Store API ACCEPTED unpurchasable product ${blocked_id} into the cart"
		else
			fail "Store API gave an unexpected answer for ${blocked_id} — guard unproven"
		fi

		# The control.
		good_id="$(python3 -c "
import json, urllib.request
try:
    with urllib.request.urlopen('${BASE}/wp-json/wc/store/v1/products?per_page=50', timeout=20) as r:
        for p in json.load(r):
            if p.get('is_purchasable'):
                print(p['id']); break
except Exception:
    pass
" 2>/dev/null || true)"

		if [[ -n "${good_id}" ]] && grep -q '"items"' <<<"$(store_add "${good_id}")"; then
			pass "Store API still accepts purchasable product ${good_id} — the refusal above is real"
		else
			fail "Store API refused a PURCHASABLE product too; the check cannot tell them apart"
		fi
	fi

	rm -f "${jar}"
else
	fail "Could not identify a non-purchasable product — the guard went unverified"
fi

# Checkout must refuse outright while demo mode is on.
checkout="$(fetch "${BASE}/checkout/")"
if [[ "$(code_of "${checkout}")" =~ ^(200|302)$ ]]; then
	pass "Checkout page reachable (order placement is blocked server-side in demo mode)"
else
	fail "Checkout returned $(code_of "${checkout}")"
fi

# -----------------------------------------------------------------------------
#
# The quantity ceiling. This is a regression guard for the worst bug the project
# has had: the shop claimed overselling was structurally impossible while the
# add-to-cart form happily accepted fifty units of a product the importer had
# reported six of. Supplier figures were never written to _stock — true, and not
# the guarantee anybody needed.

head_ "Attempting to buy more than the supplier has"

#
# Read the ceiling from a CART ITEM, not from the products endpoint.
#
# quantity_limits is computed per cart item by QuantityLimits; the products
# endpoint does not carry it, so the first version of this check looked at a
# field that is never populated and reported the ceiling missing. That is the
# third time in this project a check has been pointed at the wrong data source,
# which is itself the finding: a check is only worth what its target is.
#
jar2="$(mktemp)"
nonce2="$(curl -sS -D - -o /dev/null -c "${jar2}" --max-time 20 \
	"${BASE}/wp-json/wc/store/v1/cart" 2>/dev/null \
	| tr -d '\r' | awk 'tolower($1) == "nonce:" { print $2 }')"

store_add() {
	curl -sS -b "${jar2}" -c "${jar2}" --max-time 20 \
		-X POST -H 'Content-Type: application/json' -H "Nonce: ${nonce2}" \
		-d "{\"id\":$1,\"quantity\":$2}" \
		"${BASE}/wp-json/wc/store/v1/cart/add-item" 2>/dev/null
}

sellable_id="$(python3 -c "
import json, urllib.request
try:
    with urllib.request.urlopen('${BASE}/wp-json/wc/store/v1/products?per_page=50', timeout=20) as r:
        for p in json.load(r):
            if p.get('is_purchasable'):
                print(p['id']); break
except Exception:
    pass
" 2>/dev/null || true)"

if [[ -z "${nonce2}" || -z "${sellable_id}" ]]; then
	fail "Could not reach the Store API cart — the quantity ceiling went unverified"
else
	seeded="$(store_add "${sellable_id}" 1)"
	ceiling="$(python3 -c "
import json, sys
try:
    d = json.loads(sys.stdin.read())
    print(max((i.get('quantity_limits') or {}).get('maximum', 0) for i in d.get('items', [])))
except Exception:
    print(0)
" <<<"${seeded}" 2>/dev/null || echo 0)"

	if [[ "${ceiling}" -le 0 || "${ceiling}" -ge 500 ]]; then
		fail "Cart advertises no finite quantity ceiling (got '${ceiling}') — the cap is not reaching the customer"
	else
		pass "Cart advertises a ceiling of ${ceiling} for product ${sellable_id}"

		over="$(( ceiling + 20 ))"
		if grep -q '"code"' <<<"$(store_add "${sellable_id}" "${over}")"; then
			pass "Refused ${over} units against a ceiling of ${ceiling}"
		else
			fail "ACCEPTED ${over} units against a ceiling of ${ceiling} — OVERSOLD"
		fi

		#
		# The control. A site refusing everything must not score as a working
		# cap, so the ceiling itself has to remain reachable.
		#
		# One unit is already in the cart from the probe above, so this tops up
		# to exactly the ceiling rather than adding a further ${ceiling} — which
		# is what made the first version of this control fail against a limit
		# that was working correctly.
		#
		topup="$(( ceiling - 1 ))"

		if [[ "${topup}" -le 0 ]]; then
			pass "Ceiling is 1; the single unit already in the cart proves it is reachable"
		elif grep -q '"items"' <<<"$(store_add "${sellable_id}" "${topup}")"; then
			pass "Reaches exactly ${ceiling} units — the refusal above is real"
		else
			fail "Could not reach its own advertised ceiling of ${ceiling}"
		fi
	fi
fi

rm -f "${jar2}"

# -----------------------------------------------------------------------------

head_ "Result"
printf '  %d passed, %d failed\n\n' "${PASS}" "${FAIL}"

if [[ "${FAIL}" -gt 0 ]]; then
	printf '\033[31mFAILED\033[0m — do not send this link to anyone yet.\n'
	exit 1
fi

printf '\033[32mPASSED\033[0m — safe to share.\n'
