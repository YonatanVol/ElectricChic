# HPOS enforcement

How decision **D20** — *HPOS enabled, WooCommerce CRUD APIs mandatory, no direct
order post-meta access* — is enforced mechanically rather than by hoping people
remember it.

**Issue:** #02 · **Sniff:** `ElectricChic.HPOS.NoDirectOrderMeta`

---

## The problem this prevents

With High-Performance Order Storage enabled, WooCommerce orders no longer live in
`wp_posts` and `wp_postmeta`. They move to dedicated tables.

Code that still reaches for post meta with an order ID does not throw. It does not
warn. It returns `''` or `false`, and the calling code carries on as though the
value were simply empty. A supplier reference goes missing, a cost snapshot reads
as zero, a margin report quietly understates.

**Silence is what makes this dangerous.** A loud failure gets fixed in an hour. A
silent one ships, and is discovered weeks later in a number nobody trusts any more.
It is the single most common way an HPOS store breaks.

## What the sniff catches

### 1. `PostMeta` — post-meta functions called with an order

| Flagged | Use instead |
|---|---|
| `get_post_meta( $order_id, $k, true )` | `$order->get_meta( $k )` |
| `update_post_meta( $order_id, $k, $v )` | `$order->update_meta_data( $k, $v )` then `$order->save()` |
| `add_post_meta( $order_id, $k, $v )` | `$order->add_meta_data( $k, $v )` then `$order->save()` |
| `delete_post_meta( $order_id, $k )` | `$order->delete_meta_data( $k )` then `$order->save()` |
| `get_post_custom( $order_id )` | `$order->get_meta_data()` |
| `metadata_exists( 'post', $order_id, $k )` | `$order->meta_exists( $k )` |

### 2. `OrderQuery` — querying the order post type

```php
get_posts( [ 'post_type' => 'shop_order' ] )   // finds nothing under HPOS
wc_get_orders( [ 'status' => 'processing' ] )  // correct
```

Covers `shop_order`, `shop_order_refund` and `shop_order_placehold`.

---

## Detection is a heuristic — know its limits

PHPCS has no type information. It sees tokens, not types. So the first check
matches on the *shape* of the first argument:

- variables whose name contains `order` in any casing — `$order_id`, `$orderId`,
  `$wc_order_id`, `$the_order`
- order objects asking for their own id — `$order->get_id()`
- line items asking for their parent — `$item->get_order_id()`
- refund-shaped names — `$refund_id`

**It will miss** an order id passed in a variable named `$id` or `$post_id`. No
static sniff can catch that without type inference; code review and the CRUD
convention have to cover it.

**It may occasionally over-fire** on something that is genuinely a post. That is
the deliberate trade: a false positive costs one reviewed line, a false negative
costs silent data loss.

The second check is precise rather than heuristic — it only fires when the string
is the value of a `'post_type'` key, so `$order->get_type() === 'shop_order'`
comparisons are left alone.

### Suppressing a false positive

Inline, on the specific line, **with a reason**:

```php
// phpcs:ignore ElectricChic.HPOS.NoDirectOrderMeta.PostMeta -- $order_id here is a supplier CPT id, not a WC order.
$value = get_post_meta( $order_id, '_ec_sup_code', true );
```

A blanket suppression, or one without a reason, should not survive code review.
If you find yourself suppressing repeatedly in one file, the variable is probably
misnamed — rename it and the noise goes away.

---

## Proving it still works

The sniff is itself tested. Two fixtures live in `tests/fixtures/hpos/`:

| Fixture | Contains | Must produce |
|---|---|---|
| `violations.php` | Nine deliberate violations | 8 × `PostMeta`, 1 × `OrderQuery` |
| `compliant.php` | Correct CRUD usage, legitimate post meta, type comparisons, a same-named method | **Zero findings** |

```bash
./scripts/composer sniff:selftest
```

**Both halves matter.** A sniff that only proves it fires is half-tested. False
positives are what make people disable a rule, and a disabled rule enforces
nothing — so `compliant.php` is as important as `violations.php`.

The fixtures are excluded from the main PHPCS run by `phpcs.xml.dist`. They are
wrong on purpose; do not "fix" them.

---

## Running the gates

```bash
./scripts/composer check          # everything CI runs for PHP
./scripts/composer lint           # PHPCS
./scripts/composer lint:fix       # PHPCBF, safe auto-fixes
./scripts/composer analyse        # PHPStan level 5
./scripts/composer sniff:selftest # prove the HPOS sniff still works
```

Run `check` before opening a pull request. Once Issue #04 lands, CI runs the same
commands, so a green local run should mean a green PR.

---

## Related

- `docs/operations/local-development.md` — why tooling is pinned to PHP 8.3
- `phpcs.xml.dist` — the full ruleset and why security sniffs are errors
- `phpstan.neon.dist` — why level 5 and not higher
- Master plan §11.5, §16.8
