# Branch protection

What is enforced on `main`, why each rule is there, and the one place this
deliberately departs from the master plan.

**Issue:** #05 · **Ruleset:** `main-protection`

---

## What is enforced

| Rule | Setting | Why |
|---|---|---|
| Pull request required | Yes | No direct pushes to `main`, including by the owner |
| Required approvals | **0** — see below | Single-maintainer repository |
| Required status check | `All checks passed` | The aggregate job from CI (#04) |
| Strict status checks | Yes | A branch must be up to date with `main` before merging |
| Linear history | Yes | Squash merges only; no merge commits |
| Force push | Blocked | History on a public repository is permanent by intent |
| Branch deletion | Blocked | `main` cannot be deleted |
| Bypass actors | **None** | Including the repository owner |
| Stale review dismissal | Yes | A new push invalidates an existing approval |
| Conversation resolution | Required | Review comments must be resolved, not ignored |

---

## ⚠ Deviation from the master plan: required approvals is 0

The master plan (§16.2, §16.6) says *"review required"*. **That is not
implemented, and the reason is structural rather than a shortcut.**

This repository has exactly one collaborator: `YonatanVol`, admin.
**GitHub does not permit approving your own pull request.** Requiring one
approval would mean no pull request could ever be merged by the only person able
to merge it. The workflow would be blocked on day one.

There were three ways to handle that, and only one is honest:

| Option | Result |
|---|---|
| Require 1 approval | Nothing can ever merge. Unworkable. |
| Require 1 approval, add the owner as a bypass actor | *Looks* enforced, is not. Worse than not requiring it — it teaches everyone that the rules are theatre, and hides which merges skipped review. |
| **Require 0 approvals, enforce everything else** | Chosen. Every mechanical gate is real. The human gate is honestly absent rather than faked. |

**A rule that must be routinely bypassed is worse than no rule**, because it
trains people to bypass rules and it makes the bypass invisible in the audit
trail. So the human review requirement is recorded as *not yet met* rather than
dressed up.

### What still holds without required approvals

The pull request itself is still mandatory. Every change to `main` goes through
a pull request, gets a CI run, produces a reviewable diff, and leaves a record.
What is missing is only the *enforced second pair of eyes*.

### Turning it on when a second maintainer joins

This is a one-line change and nothing else has to move — CODEOWNERS is already
written and becomes binding the moment approvals are required.

```bash
# Set required approvals to 1 and make CODEOWNERS binding.
gh api -X PUT "repos/YonatanVol/ElectricChic/rulesets/<RULESET_ID>" \
  --input docs/operations/rulesets/main-protection-with-review.json
```

The ruleset ID is shown by:

```bash
gh api repos/YonatanVol/ElectricChic/rulesets --jq '.[] | "\(.id) \(.name)"'
```

**This should happen as soon as a second developer has commit access.** Until
then, the gap is deliberate, documented and visible — which is the point.

---

## No bypass actors, including the owner

The owner is *not* listed as a bypass actor. If `main` genuinely has to be
touched directly — a broken CI that cannot be fixed through the normal flow, for
example — the ruleset has to be edited or disabled first.

That is intentional friction. Editing a ruleset is a visible, logged
administrative action. A silent bypass is not. When something goes wrong at
02:00, the question "did anyone push straight to main?" should have an
answerable trail.

---

## Repository settings that back the ruleset up

| Setting | Value | Why |
|---|---|---|
| Squash merging | Enabled | The only permitted merge strategy |
| Merge commits | **Disabled** | Would break linear history |
| Rebase merging | **Disabled** | One strategy means one shape of history |
| Auto-delete head branches | Enabled | Branches are short-lived by design |

Disabling the other merge strategies matters: the linear-history *rule* would
reject a merge commit, but disabling the *button* means nobody is offered the
option in the first place. Prevent rather than reject.

---

## Verifying it actually works

Configuration that has never been tested is a claim, not a control. Both halves
were verified against the live repository:

```bash
# 1. Direct push to main must be rejected.
echo "test" >> README.md
git commit -am "test: direct push should be rejected"
git push origin main
# Expected: GH013 — "Cannot update this protected ref"

# 2. A pull request with a failing check must not be mergeable.
#    Verified during #04 with a deliberate HPOS violation: CI failed on both
#    PHP legs and the aggregate check failed with them.
```

Results are recorded on the pull request for Issue #05.

---

## Labels

The label set follows master plan §16.2. `type:` describes the kind of work,
`area:` the part of the system, `priority:` the urgency, `status:` a blocked
state, and `epic:` groups issues under a delivery epic.

GitHub's stock labels (`enhancement`, `question`, `wontfix`, and so on) were
removed. Two overlapping vocabularies means neither gets used consistently, and
inconsistent labels are worse than none — they look like data while being noise.

---

## Related

- `.github/workflows/ci.yml` — the checks being required
- `.github/CODEOWNERS` — written now, binding when approvals are enabled
- `.github/pull_request_template.md` — what a pull request must state
- Master plan §16.2, §16.5, §16.6
