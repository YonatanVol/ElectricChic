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

### Security
- `MASTER_PLAN_V1.md` is excluded from version control by `.gitignore`. It holds
  commercial terms, margin methodology, and a risk register containing candid
  client-side assessments — none of which belong in a public repository. It is
  shared with the client and the team directly.

### Notes
- No application code yet. No WordPress, no WooCommerce, no plugin or theme.
- Repository visibility, CI, and branch protection are configured in Issues #04–#06.
