# Rulesets

Branch protection is configured through GitHub rulesets rather than the legacy
branch-protection API. The JSON here is the source of truth for what *should* be
applied, so the configuration is reviewable in a pull request instead of living
only in a settings screen nobody reads.

| File | Purpose |
|---|---|
| `main-protection.json` | **Currently applied.** Required approvals: 0 |
| `main-protection-with-review.json` | Apply when a second maintainer joins. Required approvals: 1, CODEOWNERS binding |

## Applying

```bash
RULESET_ID=$(gh api repos/YonatanVol/ElectricChic/rulesets --jq '.[] | select(.name=="main-protection") | .id')

gh api -X PUT "repos/YonatanVol/ElectricChic/rulesets/${RULESET_ID}" \
  --input docs/operations/rulesets/main-protection.json
```

## Verifying

```bash
gh api "repos/YonatanVol/ElectricChic/rulesets/${RULESET_ID}" \
  --jq '{name, enforcement, bypass: (.bypass_actors | length), rules: [.rules[].type]}'
```

Then actually try to violate it — see `../branch-protection.md`. A ruleset that
has never been tested against is a claim, not a control.
