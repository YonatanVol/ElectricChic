# Current state — Step 1 repository and environment audit

**Audit date:** 2026-07-30
**Auditor:** Lead developer
**Status:** Complete. Accepted as the Step 1 deliverable (DL-01).
**Authoritative source:** `MASTER_PLAN_V1.md` §4 — held outside this repository

This document records the measured state of the project at the moment work began.
Every entry below was observed directly. **Nothing here is inferred or assumed** —
assumptions live in master plan §5.2 and are labelled as such.

---

## 1. Repository state at audit time

| Check | Finding |
|---|---|
| `/Users/yonatanvolsky/ElectricChic` | **Empty — 0 entries**, including dotfiles |
| Git repository | **None.** No `.git`, no branches, no commits, no `.gitignore` |
| WordPress / WooCommerce | Not present |
| Themes, child themes, custom plugins | None |
| Elementor | Not present |
| `composer.json` / `package.json` | Not present |
| Documentation, tests, CI, deployment config | None |
| Design assets, product files, supplier files, CSVs, brand assets | **None present** |
| Committed secrets | **None** — nothing existed to leak |

## 2. Tooling on the development machine

| Tool | State |
|---|---|
| Platform | macOS, Darwin 25.5.0 |
| Node / npm | **v24.14.0 / 11.9.0 — installed** |
| git | **2.49.0 — installed** |
| `git config init.defaultBranch` | **Not set** — a new repo would have defaulted to `master` |
| `gh` CLI | **v2.87.3 — installed**, authenticated as `YonatanVol` on github.com |
| `gh` token scopes | `gist`, `read:org`, `repo`, **`workflow`** |
| Git identity | `Yonatan Volsky <yonatanes1@gmail.com>`, configured globally |
| rsync / ssh | **Installed** — deploy transport available |
| PHP | **Not installed** |
| Composer | **Not installed** |
| WP-CLI | **Not installed** |
| Docker / DDEV / Lando / Valet / Local / MAMP | **None installed** |
| phpcs / phpstan / phpunit / playwright | Not installed |

## 3. Current-state diagram

```
Local machine (macOS Darwin 25.5.0)
├── Node 24.14.0 / npm 11.9.0     ✅ JS+CSS tooling ready
├── git 2.49.0                     ⚠  init.defaultBranch UNSET
├── gh 2.87.3 (YonatanVol)         ✅ repo + workflow scopes
├── rsync + ssh                    ✅ deploy transport available
├── PHP / Composer / WP-CLI        ❌ ABSENT — blocks all PHP work
└── Docker / DDEV / Local / MAMP   ❌ ABSENT — no local WordPress

/Users/yonatanvolsky/ElectricChic   ⬜ EMPTY — greenfield
GitHub repository                   ⬜ not created
Managed host                        ⬜ not purchased (D17)
```

## 4. Technical-debt register

Nothing existed, so no debt was inherited. The register below records debt that
**would be created** if specific steps were skipped, and where each is prevented.

| # | Debt | Cost if incurred | Prevented in | Status |
|---|---|---|---|---|
| TD-1 | `init.defaultBranch` left unset | Repo on `master`, mismatching every workflow reference | Issue #01 | ✅ Resolved — repo created with `git init -b main` |
| TD-2 | `.gitignore` not in the first commit | On a public repo, a leaked `wp-config.php` is instantly world-readable and permanent | Issue #01 | ✅ Resolved — `.gitignore` is in commit 1 |
| TD-3 | HPOS not enabled before the first order | Costly live migration | Issue #08 | ⬜ Open |
| TD-4 | Hebrew attribute-term spelling not standardised on day one | Silently broken filters, expensive to repair once products exist | Step 9, slice 1 | ⬜ Open |
| TD-5 | Direct `get_post_meta()` on orders | Breaks under HPOS; requires a full audit later | Issue #02 (PHPCS sniff) | ⬜ Open |
| TD-6 | Background work outside Action Scheduler | Invisible failures with no retry or monitoring | Issue #18 | ⬜ Open |

## 5. Security findings

| Finding | Severity | Action | Status |
|---|---|---|---|
| No secrets present anywhere | ✅ Clean | Preserve via `.gitignore` in commit 1, gitleaks in CI, push protection | ✅ `.gitignore` done; CI in #04 |
| `gh` token scoped to `repo` + `workflow` | ℹ️ Appropriate | No `admin:org`, no `delete_repo`. No change needed | ✅ Accepted |
| No local WordPress install | ✅ Neutral | No stale vulnerable install inherited | ✅ Accepted |
| **Public repository raises secret-leak impact** | ⚠️ **High** | Secret scanning **and push protection** enabled before the first push; full-history scan before launch | ⬜ Pending — see §7 |
| No hosting yet | ℹ️ Neutral | Hosting security requirements specified in master plan §11.1 before purchase | ⬜ Open (OD-01) |

## 6. Missing tooling

**To install (Issue #07):** Local by Flywheel, PHP 8.2+, Composer, WP-CLI.

**To create (Issues #02–#06):** `composer.json`, `package.json`, `phpcs.xml.dist`,
`phpstan.neon.dist`, `phpunit.xml.dist`, `.gitleaks.toml`, `.github/**`,
`scripts/**`, plugin and child-theme skeletons.

## 7. Blocking decisions carried out of Step 1

| # | Decision | Owner | Status |
|---|---|---|---|
| B-1 | Repository is **public**, but commercially sensitive planning material is **excluded from it** (OD-14) | Project owner | ✅ **Resolved during Issue #01** — see §9 |
| B-2 | Managed host selected against master plan §11.1 (OD-01) | Client | ⬜ Open — gates Issues #06 and #16 only |

Resolved during Step 1 and recorded as confirmed decisions in master plan §3:
D12 (public repo on GitHub Free), D13 (repository owned by `YonatanVol`),
D14 (Playwright in MVP scope), D15 (`electricchic-*` naming),
D16 (Local by Flywheel), D17 (no host purchased yet).

## 8. Deviation from plan — recorded

The master plan's next-actions list specified `git config --global init.defaultBranch main`.
The repository was instead created with `git init -b main`, which achieves the same
acceptance criterion — the repository is on `main` — **without modifying the developer's
global git configuration**, which affects every unrelated repository on the machine.

Setting the global default remains a reasonable personal preference; it is simply not a
project requirement, and a project should not silently reconfigure a developer's machine.

## 9. Amendment to decision D12 — publication boundary

The master plan set the repository to public (D12) so that branch protection, required
reviews, and Environment approval gates are enforceable at no cost, and paired that with
a hard no-secrets-in-Git rule enforced by `.gitignore`, gitleaks, and push protection.

That reasoning covers **credentials**. It did not cover **commercially sensitive prose**.
`MASTER_PLAN_V1.md` contains payment milestones, a cost and licence register, warranty and
maintenance boundaries, margin methodology, and a risk register carrying candid assessments
of client-side risk. None of that is a secret in the credential sense, so no scanner would
have caught it — and none of it belongs in a world-readable repository.

**Resolution, adopted during Issue #01 before the first push:**

- The repository stays **public**, so every branch protection remains enforceable.
- `MASTER_PLAN_V1.md` is **excluded via `.gitignore`** and held outside version control,
  shared with the client and the team directly.
- Technical content migrates from the plan into `docs/` as the build proceeds, so this
  repository becomes self-sufficient for an engineer. Commercial content never does.
- The plan **never entered Git history** — the exclusion was applied by amending the
  initial commit prior to any push, so no history rewrite was required.

**Residual item:** commit author email (`yonatanes1@gmail.com`) is publicly visible on a
public repository, which is normal for open development. GitHub's `noreply` address is
available if a different posture is preferred; no action taken.

