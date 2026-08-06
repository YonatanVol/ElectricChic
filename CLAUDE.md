# CLAUDE.md

Context for Claude Code sessions on this project. Read this before doing anything.

**ElectricChic** — Hebrew-first, RTL-native WooCommerce platform for a physical
bicycle shop in Israel. Greenfield; foundation complete, product work not started.

---

## ⚠ Read first: five things that will bite you

These were each discovered the expensive way. Do not rediscover them.

### 1. `php` on PATH is the WRONG version

```
$ php -v
PHP 8.5.8          ← Homebrew installed this as a composer/wp-cli dependency
$ ./scripts/php -v
PHP 8.3.32         ← what this project targets
```

**Always use the wrappers:** `./scripts/php`, `./scripts/composer`, `./scripts/wp`.

Two layers of pinning exist and **both are needed**. The wrappers pin Composer;
Composer's `@php` prefix in `composer.json` pins the tools Composer *spawns*
(phpcs, phpstan, phpunit each have a `#!/usr/bin/env php` shebang and will
otherwise follow PATH to 8.5). If you add a composer script, **it needs `@php`**.
`HarnessTest::test_runs_on_a_targeted_php_version()` fails if this regresses.

**Do not "fix" this by changing the global PATH.** It would alter PHP behaviour
for every other project on this machine.

### 2. `MASTER_PLAN_V1.md` is the spec — and it is NOT in this repo

It is gitignored deliberately. It contains commercial terms, margin methodology,
and a risk register with candid client-side assessments; this repository is
**public**. It sits in the working directory locally.

If it is present, read it — it is authoritative and section references
throughout the repo (`§11`, `§12`, …) point into it. If it is absent, ask the
user rather than guessing.

### 3. This repository is PUBLIC

No secrets, no customer data, no commercial terms, no production hostnames.
Secret scanning and push protection are on. A committed secret is world-readable
and permanent — **rotate the credential**, do not just delete the file.

### 4. `main` is protected — no direct pushes

```
Issue → branch → implement → test → PR → CI green → squash merge
```

Direct push is rejected (`GH013`). There are **no bypass actors, including the
owner**. Required check: `All checks passed`. Squash merge only.

### 5. Watch shell pipeline exit codes

`cmd | tee file` returns *tee's* status, not `cmd`'s. This produced a false
"protection is NOT working" result once in this project. Use `${PIPESTATUS[0]}`,
or capture to a file and check `$?` separately.

---

## The two architectural decisions everything else follows from

**Availability is derived, not typed.** The shop owner records *facts* — store
stock, supplier, lead time, whether confirmation is required — and a pure,
unit-tested resolver computes the customer-facing label, purchasability, and the
delivery promise. There is no dropdown where someone picks "available from
supplier".

**Supplier stock never increases WooCommerce's sellable stock number.** A wrong
supplier file can produce a wrong *promise*, never a wrong *stock count*. This
removes overselling as a systemic risk instead of relying on people remembering.

**Every integration is unreliable by default.** Payment, invoicing, shipping,
email and feeds all get signature verification, idempotency keys, retry with
backoff, failure logging, reconciliation, alerting and manual recovery —
designed in, not bolted on after the first silent failure. Invoice-creation
failures are silent by nature and have a dedicated alerting path.

---

## Hard rules

| Rule | Why |
|---|---|
| **Order data goes through WooCommerce CRUD APIs** — never `get_post_meta()` on an order | Under HPOS orders leave `wp_posts`. Direct access returns empty **without erroring**. Enforced by `ElectricChic.HPOS.NoDirectOrderMeta` |
| Background work runs through **Action Scheduler** | `wp_cron` failures are invisible and unretried |
| Business logic lives in **`electricchic-core`** (plugin) | So it can be tested, reviewed and version-controlled |
| Presentation lives in **`electricchic-child`** (theme) | Zero business logic |
| **No business logic in a page builder**, ever | Cannot be tested or reviewed |
| Unit tests are **pure** — no WordPress bootstrap | `HarnessTest` fails if `add_action()` becomes callable |
| Verify by **attempting the violation**, not reading a settings page | Configuration that has never been tested is a claim, not a control |

---

## Commands

```bash
./scripts/bootstrap-local.sh --check   # verify the environment
./scripts/composer check               # everything CI runs — do this before a PR
./scripts/composer lint                # PHPCS: WPCS + PHP 8.2 compat + HPOS sniff
./scripts/composer lint:fix            # PHPCBF safe auto-fixes
./scripts/composer analyse             # PHPStan level 5
./scripts/composer test                # PHPUnit
./scripts/composer sniff:selftest      # prove the HPOS sniff still works
```

CI runs the same commands on PHP **8.2** (supported floor) and **8.3** (local +
production). Analysis targets 8.2, not whatever is on your PATH.

---

## Layout

```
wp-content/plugins/electricchic-core/   business logic (empty — Issue #12)
wp-content/themes/electricchic-child/   presentation (empty — Issue #11)
tools/phpcs/ElectricChic/               the HPOS sniff
tests/{Unit,fixtures,Sniffs}/           unit tests; fixtures are wrong ON PURPOSE
scripts/                                env bootstrap + PHP-pinning wrappers
docs/                                   architecture, operations, decisions
.github/                                CI, templates, CODEOWNERS, dependabot
```

WordPress core, uploads and third-party plugins are **not** tracked. This repo is
not the WordPress root — symlink the two directories above into a Local site.

**`tests/fixtures/hpos/` is deliberately broken code.** `violations.php` must
produce exactly 8 `PostMeta` + 1 `OrderQuery` findings; `compliant.php` must
produce none. Do not "fix" them — the self-test asserts those counts.

---

## Status

**Done:** #01 repo + secret protection · #02 PHP quality gates + HPOS sniff ·
#03 PHPUnit harness *(partial)* · #04 CI · #05 branch protection · #07 local env

**Next:** #08 WordPress + WooCommerce baseline (**enable HPOS before any order
exists**), then #11 child theme, #12 core plugin skeleton.

### Blocked

| Item | Blocker |
|---|---|
| #06 deploy workflows | **Managed host not chosen.** Requirements: staging, SSH, WP-CLI, Redis, daily backups, PHP 8.2+ |
| JS/CSS tooling (rest of #03) | **`registry.npmjs.org` unreachable** — TLS reset. Packagist and GitHub are fine. `npm install` cannot complete. A mirror is a supply-chain decision for the user, not something to swap in silently |

### Known deviations from the plan, deliberate and documented

- **Required PR approvals is 0, not 1.** Single collaborator, and GitHub does not
  permit approving your own PR — requiring one would block every merge. Every
  other gate is enforced with no bypass actors.
  `docs/operations/rulesets/main-protection-with-review.json` turns it on when a
  second maintainer joins. Recorded as unmet rather than faked with a bypass.
- **Repository is owned by the developer account**, not the client, contrary to
  the client-owns-assets principle. Revisit before launch.
- Dependabot has open PRs including major action bumps — review before merging.

---

## Working style that fits this project

- **Verify, don't assert.** Every control here was proven by trying to break it:
  push protection with a planted secret, branch protection with a real push, the
  HPOS sniff with a deliberate violation in CI.
- **Say what you did not do.** Partial delivery stated plainly beats a green tick
  that hides a gap — see #03.
- **Prefer a documented gap to a faked control.** A rule that must be routinely
  bypassed teaches everyone the rules are theatre.
- **Legal, tax, accounting, privacy and accessibility content is not drafted
  here.** Mark it for the client's lawyer, accountant or accessibility
  professional.
- The shop owner is **not technical** and works in Hebrew. Operator-facing
  documentation is written in Hebrew, in plain language.
