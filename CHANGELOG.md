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

### Security
- `MASTER_PLAN_V1.md` is excluded from version control by `.gitignore`. It holds
  commercial terms, margin methodology, and a risk register containing candid
  client-side assessments — none of which belong in a public repository. It is
  shared with the client and the team directly.

### Fixed
- Project tooling is pinned to PHP 8.3. Homebrew's `composer` and `wp-cli`
  formulae depend on the unversioned `php` formula, which put PHP 8.5 first on
  `PATH` — newer than the 8.2/8.3 that CI tests and production runs, and new
  enough that `wp-cli` emitted deprecation warnings from its own dependencies.
  The `scripts/` wrappers resolve this without altering the developer's global
  shell configuration.

### Notes
- Composer's `config.platform.php` is pinned to 8.2.0 and PHPStan analyses at
  8.2, so dependency resolution and static analysis both target the supported
  floor rather than the local runtime.
- No application code yet. No WordPress, no WooCommerce, no plugin or theme.
- Repository visibility, CI, and branch protection are configured in Issues #04–#06.
