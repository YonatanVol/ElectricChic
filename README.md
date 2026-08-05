# ElectricChic

Hebrew-first, RTL-native WooCommerce e-commerce platform for an Israeli physical bicycle shop.

The site serves two purposes at once: the digital storefront of a real shop (services, expertise, brands, repairs, location, live availability) and a fully transacting online store (browse, filter, cart, pay, deliver or collect).

---

## Start here

The authoritative specification is **`MASTER_PLAN_V1.md`**, which is **deliberately not in this repository**. It contains commercial terms and a candid risk register, so it is held outside version control and shared with the client and the development team directly. This repository is public; that document is not.

If you are working on this project and do not have it, ask the project owner. Section references throughout this repository (`§11`, `§12`, and so on) point into it.

| If you want to… | Read |
|---|---|
| Understand the architecture | Master plan §11 |
| Understand the data model | Master plan §12 |
| Know what is in and out of scope | Master plan §6, §7 |
| Know how work gets done here | Master plan §16, and "Workflow" below |
| Find the next task | Master plan §20 (first twenty Issues) |
| Understand a risk or open decision | Master plan §29, §30 |

Technical documentation that *is* public lives in [`docs/`](docs/) — architecture, ADRs, UX, operations runbooks, testing, and data governance. Over the course of the build, the technical content of the master plan migrates into those directories, so this repository becomes self-sufficient for an engineer. The commercial content never does.

---

## What this repository contains

This repository tracks **only the code we author**. WordPress core, uploads, and third-party plugins and themes are deliberately excluded — they are installed on the server, not versioned here.

```
.github/          CI, deploy workflows, issue and PR templates
docs/             Architecture, decisions (ADRs), UX, operations, testing, security
wp-content/
  plugins/electricchic-core/    ALL business logic
  themes/electricchic-child/    Presentation only
tests/            Unit, integration, e2e, fixtures
scripts/          Bootstrap, deploy, packaging, DB anonymisation
```

### The separation that matters

| Layer | Owns | Never contains |
|---|---|---|
| `electricchic-core` (plugin) | Supplier data, availability logic, pricing guards, returns/RMA, order statuses, integrations, admin workflow | Layout, styling |
| `electricchic-child` (theme) | Design tokens, layout, styling, RTL corrections, justified template overrides | Business logic |
| Page builder | Marketing page layout only, if used at all | Business logic — ever |

Business logic lives in the plugin so it can be tested, reviewed, and version-controlled. A rule that lives only in a theme or a page builder cannot be any of those things.

---

## Core design decisions

Two decisions shape everything else. Both are explained in full in the master plan.

**Availability is derived, not typed** (§11.12). The shop owner records *facts* — store stock, supplier, lead time, whether confirmation is required — and a pure, unit-tested resolver computes the customer-facing label, whether the product can be bought, and the delivery promise. There is no dropdown where someone selects "available from supplier".

Critically: **supplier stock never increases WooCommerce's sellable stock number.** A wrong supplier file can produce a wrong *promise*, but never a wrong *stock count*. This removes overselling as a systemic risk rather than as a thing people have to remember.

**Every integration is unreliable by default** (§11.5, §22.4). Payment, invoicing, shipping, email, and feeds all get signature verification, idempotency keys, retry with backoff, failure logging, reconciliation, alerting, and a manual recovery path — designed in from the start, not added after the first silent failure. Invoice-creation failures in particular fail silently by nature and have their own alerting path.

---

## Platform requirements

| | |
|---|---|
| PHP | 8.2+ |
| WordPress | Latest stable |
| WooCommerce | Latest stable, **HPOS enabled** |
| Node | 20+ (24.x in use) |
| Local environment | Local by Flywheel |
| Hosting | Managed WordPress with staging, SSH, WP-CLI, Redis (§11.1) |

**HPOS is mandatory.** All order data is accessed through WooCommerce CRUD APIs. Direct `get_post_meta()` / `update_post_meta()` on orders is prohibited and blocked by a custom PHPCS sniff in CI. All background work runs through Action Scheduler.

---

## Workflow

GitHub Flow. Short-lived branches from the latest `main`, one concern each.

```
Issue → branch → implement → tests → PR → review → staging → approve → squash merge
```

- `main` is protected and always deployable. No direct pushes.
- Branches: `feat/123-slug`, `fix/205-slug`, `chore/91-slug`, `docs/32-slug`, `test/188-slug`
- Commits: Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`, `perf:`, `security:`)
- Merge to `main` auto-deploys to **staging**. Production requires **manual approval**.
- The same tested commit is promoted from staging to production — production never builds from a different source.

**Passing CI is a precondition for review, not a substitute for it.** A pull request too large to review properly gets split, not approved.

See master plan §31 (Definition of Ready) and §32 (Definition of Done) before opening or closing an Issue.

---

## Quality gates

```bash
./scripts/composer check          # everything CI runs for PHP
./scripts/composer lint           # PHPCS — WordPress standards + PHP 8.2 compat + HPOS sniff
./scripts/composer lint:fix       # PHPCBF — safe auto-fixes
./scripts/composer analyse        # PHPStan level 5, WordPress + WooCommerce stubs
./scripts/composer test           # PHPUnit — pure logic, no WordPress bootstrap
./scripts/composer sniff:selftest # prove the HPOS sniff still catches violations
```

Run `check` before opening a pull request.

**`ElectricChic.HPOS.NoDirectOrderMeta`** is a project-specific sniff that enforces decision D20 mechanically: order data goes through WooCommerce CRUD APIs, never through `get_post_meta()`. Under HPOS, direct post-meta access on orders returns empty without erroring — it fails silently, which is what makes it dangerous. The sniff is itself tested against fixtures. See [`docs/architecture/hpos-enforcement.md`](docs/architecture/hpos-enforcement.md).

The unit suite covers **pure business logic only** — inputs in, values out, no framework calls. That constraint is deliberate: the highest-risk logic here (availability resolution, lead-time aggregation, margin calculation, the RMA state machine) is written to be pure so it can be tested in milliseconds without a database or a WordPress install. `HarnessTest` asserts WordPress is genuinely absent, so the suite cannot quietly grow a dependency on it.

Analysis runs against **PHP 8.2**, the supported floor — not against whatever PHP is on your PATH. Composer scripts use the `@php` prefix so the tools Composer spawns inherit the pinned PHP rather than following their shebang to whatever is first on PATH. See [`docs/operations/local-development.md`](docs/operations/local-development.md).

---

## Security

**This repository is public.** A committed secret is world-readable and permanent in history — deleting the file does not undo it.

- Nothing secret goes in Git, ever. Runtime secrets live in host environment variables; CI secrets in GitHub Actions secrets.
- `.gitignore` covers `wp-config.php`, `.env*`, `*.sql`, uploads, and third-party code. It landed in the first commit, before anything else was staged.
- Secret scanning and push protection are enabled on the repository.
- If a secret is ever committed: **rotate the credential immediately.** Do not rely on removing the file. See master plan §22.7.

Customer personal data never enters this repository, and never enters a local or staging database (§16.15).

---

## Status

Pre-implementation. Foundation work in progress — see master plan §20 for the current Issue sequence and §A for the two decisions that gate it.

## Licence

Proprietary. Client ownership terms are defined in master plan §8.13.
