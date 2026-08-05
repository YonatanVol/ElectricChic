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
- PHPUnit harness (Issue #03): `phpunit.xml.dist`, `tests/bootstrap.php` and
  `HarnessTest`, which asserts that WordPress and WooCommerce are *not* loaded so
  the unit suite cannot quietly acquire a framework dependency.
- Continuous integration (Issue #04): `.github/workflows/ci.yml` runs PHPCS,
  PHPStan, the HPOS sniff self-test and PHPUnit against PHP 8.2 and 8.3, plus a
  full-history gitleaks scan. A single `verify` job aggregates the matrix so
  branch protection has one stable check to require.
- `.github/dependabot.yml` — weekly Composer and GitHub Actions updates. The npm
  ecosystem is deliberately absent until the JavaScript toolchain exists.

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
- Branch protection and deploy workflows are configured in Issues #05 and #06.
