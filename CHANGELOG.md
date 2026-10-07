# Changelog

All notable changes to this project are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Release scheme for this project (master plan §16.10):

| Tag | Meaning |
|---|---|
| `v0.x.0` | Internal milestones, pre-launch |
| `v0.9.0` | Launch candidate |
| `v1.0.0` | First production release |
| `v1.0.x` | Backward-compatible fixes |
| `v1.x.0` | Backward-compatible features |
| `v2.0.0` | Breaking changes only |

---

## [Unreleased]

### Added
- The storefront, finished to the standard a customer pays for (`electricchic-child`):
  - **Header.** Search that opens from its icon, phone and WhatsApp, account and
    bag — every control a 44px target. The phone overlay carries the contact
    links and the store section; items arrive in sequence.
  - **Motion system.** Hero entrance choreography (copy rises in four beats,
    the machine rolls in forward), scroll-driven parallax where the browser
    supports it, staggered reveals computed per batch, a card lift with a
    grounded shadow, a cross-document morph of the tapped product image into
    its page, a mini-cart badge bump, a spinner on add-to-cart, smooth in-page
    anchors. Transforms and opacity only; hover never changes layout; every
    entry point honours reduced motion; nothing is load-bearing in JavaScript.
    The pointer-driven studio light now moves only the hero and the product
    stage — on `:root` it re-rasterised every catalogue cut-out per pointer move.
  - **Product page.** Sticky lit stage, the specification without the two empty
    tabs, an assurance list of facts the shop stands behind, a sticky purchase
    bar that submits the real form and copies the page's own availability badge
    (one source of truth), and a WhatsApp button whose message names the product.
  - **Catalogue.** WooCommerce product filters — department chips, availability,
    price — beside the grid on a desktop and as a drawer on a phone; a product
    search results template; 44px pagination.
  - **Cart, checkout, account** templates in the house style; the empty cart
    offers what is physically in the shop.
  - `scripts/audit-pages.mjs` — the verification method: screenshots at 1440 and
    390, horizontal overflow, touch targets under 24px, badge-per-card parity,
    console errors and frame gaps, in a real Chromium via Playwright.
- `docs/ux/motion.md` — the motion rules, where each piece lives, and how it
  was measured.

### Fixed
- The sticky header never stuck, on any page, since the block templates landed.
  Three causes, each measured: `overflow-x: clip` on `body` and on `html` made
  each a scroll container that sticky resolved against; and the template-part
  wrapper was exactly the header's height, so the header had no room to stick
  in. The wrapper is now the sticky element.
- The phone menu rendered as a strip inside the header: `backdrop-filter` makes
  its element the containing block for fixed descendants. Moved to a
  pseudo-element.
- Cart page copy saved in English inside the page content ("Your cart is
  currently empty!", "New in store") — replaced in the page, not the theme.
- A missing MOVIX image size (404 on the shop page) regenerated.

- Repository initialised on `main` with `.gitignore` in the first commit (Issue #01).
- `docs/` skeleton: architecture, decisions (ADRs), UX, operations, testing,
  releases, security, data governance.
- `docs/architecture/current-state.md` — Step 1 repository and environment audit.
- `README.md`, `CHANGELOG.md`, `.editorconfig`.
- Local development environment (Issue #07): `php@8.3`, Composer, WP-CLI and
  Local by WP Engine, installed via Homebrew.
- `scripts/bootstrap-local.sh` — idempotent environment setup and verification,
  with a `--check` mode that installs nothing.
- `scripts/php`, `scripts/composer`, `scripts/wp` — wrappers pinning project
  tooling to PHP 8.3.
- `docs/operations/local-development.md` — setup guide and troubleshooting.
- PHP quality gates (Issue #02): PHPCS with WordPress Coding Standards,
  PHPCompatibilityWP, and PHPStan level 5 with WordPress and WooCommerce stubs.
- `ElectricChic.HPOS.NoDirectOrderMeta` — project sniff enforcing decision D20.
  Catches post-meta access with an order-shaped argument, and queries against the
  `shop_order` post type. Both break silently once HPOS is enabled.
- Sniff self-test with paired fixtures: nine deliberate violations that must all
  be caught, and legitimate code that must produce no findings.
- `composer.json` with `check`, `lint`, `lint:fix`, `analyse` and
  `sniff:selftest` scripts; `phpcs.xml.dist`; `phpstan.neon.dist`.
- `docs/architecture/hpos-enforcement.md` — what the sniff catches, the limits of
  its heuristic, and how to suppress a verified false positive.
- PHPUnit harness (Issue #03): `phpunit.xml.dist`, `tests/bootstrap.php` and
  `HarnessTest`, which asserts that WordPress and WooCommerce are *not* loaded so
  the unit suite cannot quietly acquire a framework dependency.
- Continuous integration (Issue #04): `.github/workflows/ci.yml` runs PHPCS,
  PHPStan, the HPOS sniff self-test and PHPUnit against PHP 8.2 and 8.3, plus a
  full-history gitleaks scan. A single `verify` job aggregates the matrix so
  branch protection has one stable check to require.
- `.github/dependabot.yml` — weekly Composer and GitHub Actions updates. The npm
  ecosystem is deliberately absent until the JavaScript toolchain exists.
- Repository governance (Issue #05): branch protection on `main` via a ruleset,
  five issue-form templates, a pull request template, CODEOWNERS, and the
  project label set.
- `docs/operations/branch-protection.md` and `docs/operations/rulesets/` — the
  ruleset JSON kept in version control so protection is reviewable in a pull
  request rather than living only in a settings screen.
- `CLAUDE.md` — project context loaded automatically by Claude Code sessions.
  Captures the constraints, gotchas and decisions that are expensive to
  rediscover, so a session that loses context stays productive.

### Security
- `MASTER_PLAN_V1.md` is excluded from version control by `.gitignore`. It holds
  commercial terms, margin methodology, and a risk register containing candid
  client-side assessments — none of which belong in a public repository. It is
  shared with the client and the team directly.

### Fixed
- Tools spawned by Composer now inherit the pinned PHP. The `scripts/` wrappers
  pin Composer itself, but `phpcs`, `phpstan` and `phpunit` each carry a
  `#!/usr/bin/env php` shebang and were following `PATH` to 8.5 anyway — PHPUnit
  reported `Runtime: PHP 8.5.8` while the wrappers appeared to work. Composer
  scripts now use the `@php` prefix, and a harness test fails if this regresses.
- Test fixtures are excluded from the Composer classmap, silencing a PSR-4
  warning and keeping deliberately-wrong code out of the autoloader.
- Project tooling is pinned to PHP 8.3. Homebrew's `composer` and `wp-cli`
  formulae depend on the unversioned `php` formula, which put PHP 8.5 first on
  `PATH` — newer than the 8.2/8.3 that CI tests and production runs, and new
  enough that `wp-cli` emitted deprecation warnings from its own dependencies.
  The `scripts/` wrappers resolve this without altering the developer's global
  shell configuration.

### Deferred
- JavaScript and CSS tooling (the second half of Issue #03) is **not** included.
  `registry.npmjs.org` is unreachable from the development environment — TLS
  connections are reset, while Packagist and GitHub are fine. Rather than commit
  configuration that could not be run even once, it is deferred until the
  registry is reachable or a decision is taken on an alternative. Nothing else
  depends on it yet: there is no JavaScript or CSS in the project.

### Notes
- Composer's `config.platform.php` is pinned to 8.2.0 and PHPStan analyses at
  8.2, so dependency resolution and static analysis both target the supported
  floor rather than the local runtime.
- No application code yet. No WordPress, no WooCommerce, no plugin or theme.
- Deploy workflows are configured in Issue #06, which is blocked on the hosting
  decision.
- **Required approvals on `main` is 0, not 1.** This repository has a single
  collaborator, and GitHub does not permit approving your own pull request, so
  requiring an approval would block every merge permanently. Every other gate is
  enforced with no bypass actors. `docs/operations/rulesets/main-protection-with-review.json`
  turns the requirement on the moment a second maintainer exists. Recorded rather
  than faked with a bypass, because a rule that must be routinely bypassed trains
  people to bypass rules.
