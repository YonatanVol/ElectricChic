# Local development environment

How to get a working ElectricChic development environment on macOS, and why it is
set up the way it is.

**Issue:** #07 · **Epic:** E1 Engineering Foundation

---

## Quick start

```bash
git clone https://github.com/YonatanVol/ElectricChic.git
cd ElectricChic
./scripts/bootstrap-local.sh
```

The script is idempotent — run it as often as you like. It installs only what is
missing and then verifies the result. To check without installing anything:

```bash
./scripts/bootstrap-local.sh --check
```

---

## What gets installed

| Tool | Version | Purpose |
|---|---|---|
| `php@8.3` | 8.3.x | Runs project tooling — Composer, PHPCS, PHPStan, PHPUnit |
| `composer` | 2.x | PHP dependency management |
| `wp-cli` | 2.x | WordPress administration from the command line |
| Local (by WP Engine) | 10.x | Serves the WordPress site — bundles its own PHP, MySQL and web server |

Everything comes from Homebrew, so `brew uninstall` reverses all of it.

### Why both Local and a Homebrew PHP

They do different jobs and it is worth keeping the distinction clear:

- **Local serves the site.** It bundles its own PHP, MySQL and nginx. That is
  what your browser talks to.
- **Homebrew's PHP runs the tooling.** Composer, PHPCS, PHPStan and PHPUnit run
  from your terminal against the repository, independently of whether any site
  is running.

Local was chosen over DDEV because Docker is not installed on this machine and
adding it is a heavier dependency than this project needs (decision D16).

---

## ⚠ The PHP version trap

**This project already hit this once. Read this section before debugging anything
version-related.**

Homebrew's `composer` and `wp-cli` formulae both depend on the *unversioned* `php`
formula, which tracks the newest PHP release. Installing them therefore drags in a
newer PHP than this project targets and puts it first on your `PATH`:

```
$ php -v
PHP 8.5.8          ← what Homebrew put on your PATH
$ /opt/homebrew/opt/php@8.3/bin/php -v
PHP 8.3.32         ← what this project actually targets
```

That matters because:

- **CI tests PHP 8.2 and 8.3.** Production runs 8.3. Tooling run on 8.5 gives you
  results that do not match either — green locally, red in CI.
- **PHPStan and PHPCS behave differently across PHP versions.** Analysis run on the
  wrong version misses real problems and invents fake ones.
- **It is already causing visible breakage.** Run `wp --version` bare and wp-cli
  emits deprecation warnings from its own vendored dependencies, because it is
  running on a PHP newer than it supports.

### The fix: use the wrappers

```bash
./scripts/php        # PHP 8.3
./scripts/composer   # Composer, running on PHP 8.3
./scripts/wp         # WP-CLI, running on PHP 8.3 — and no deprecation noise
```

Verify at any time:

```bash
$ ./scripts/php -r 'echo PHP_VERSION;'
8.3.32
$ ./scripts/wp --version
WP-CLI 2.12.0                      # clean, no warnings
```

### What not to do

**Do not put `php@8.3` first on your global `PATH`** to "fix" this. It would change
PHP behaviour for every other project on your machine, and it makes the setup
depend on invisible shell configuration that a new contributor will not have.

The same principle applies elsewhere in this project: the repository was created
with `git init -b main` rather than by changing your global
`init.defaultBranch`. **A project should not silently reconfigure a developer's
machine.** Project-scoped tooling belongs in `scripts/`, where it is visible,
version-controlled, and identical for everyone.

---

## Setting up a site in Local

Issue #08 covers the WordPress and WooCommerce baseline in full. In outline:

1. Open **Local.app**.
2. Create a site — suggested name `electricchic`, PHP **8.3**, nginx, MySQL 8.
3. Note the site path Local reports, typically
   `~/Local Sites/electricchic/app/public`.
4. Symlink this repository's tracked directories into that WordPress install so
   your edits are picked up live:

```bash
SITE=~/Local\ Sites/electricchic/app/public
REPO="$(pwd)"

ln -s "$REPO/wp-content/plugins/electricchic-core"  "$SITE/wp-content/plugins/electricchic-core"
ln -s "$REPO/wp-content/themes/electricchic-child"  "$SITE/wp-content/themes/electricchic-child"
```

The repository is **not** the WordPress root. It holds only the code we author —
WordPress core, uploads and third-party plugins live in the Local site and are
never version-controlled. See the root `README.md`.

### Match Local's PHP to the project

In Local, set the site's PHP version to **8.3**. If Local's available versions do
not include 8.3, pick the closest supported release and record the mismatch — a
site running a different PHP from the tooling and from production is a source of
bugs that only appear after deployment.

---

## Daily commands

```bash
./scripts/bootstrap-local.sh --check    # confirm the environment is still sane
./scripts/php -v                        # PHP 8.3
./scripts/composer install              # install dev dependencies (from Issue #02)
./scripts/wp --info                     # WP-CLI diagnostics
```

Once Issue #02 lands, the quality gates run through Composer scripts:

```bash
./scripts/composer check     # everything CI runs
./scripts/composer lint      # PHPCS — WordPress Coding Standards + HPOS sniff
./scripts/composer analyse   # PHPStan level 5
./scripts/composer test      # PHPUnit — pure business logic, no WordPress bootstrap
```

### Two layers of PHP pinning, and why both are needed

The `scripts/` wrappers pin **Composer itself** to PHP 8.3. That is not sufficient on
its own: tools Composer launches (`phpcs`, `phpstan`, `phpunit`) each carry a
`#!/usr/bin/env php` shebang, so they follow `PATH` to 8.5 regardless of how Composer
was started.

This actually happened. PHPUnit reported `Runtime: PHP 8.5.8` while every wrapper
looked like it was working.

The second layer is Composer's `@php` prefix in `composer.json`, which runs each tool
with the PHP binary Composer is already using:

```json
"test": "@php vendor/bin/phpunit"
```

`HarnessTest::test_runs_on_a_targeted_php_version()` fails loudly if this regresses.

---

## Disk space

Local plus a WordPress site needs roughly **2–3 GB**. The bootstrap script warns
below 5 GB free. If you are tight, Local's per-site binaries are the largest
consumer — deleting unused sites reclaims the most.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `php -v` shows 8.5 | Expected — Homebrew's composer/wp-cli dependency | Use `./scripts/php`. Not a fault. |
| Deprecation warnings from `wp` | wp-cli running on PHP 8.5 | Use `./scripts/wp` |
| `PHP 8.3 not found` from a wrapper | `php@8.3` not installed | `./scripts/bootstrap-local.sh` |
| Composer resolves unexpected versions | Composer running on the wrong PHP | Use `./scripts/composer`; Issue #02 also pins `config.platform.php` |
| Local site 502s | Local's PHP or database not running | Restart the site in Local.app |
| Bootstrap fails on Homebrew | Homebrew missing or broken | Install from https://brew.sh, then re-run |

---

## Uninstalling

```bash
brew uninstall php@8.3 composer wp-cli
brew uninstall --cask local
```

Delete sites from within Local.app before uninstalling it, or their files remain
in `~/Local Sites`.
