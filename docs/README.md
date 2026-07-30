# Documentation

Working documentation for the ElectricChic project. The authoritative
specification is `MASTER_PLAN_V1.md`, which is **held outside this repository**
(it contains commercial terms and a candid risk register, and this repository is
public). The directories here hold the technical documents that grow out of it,
and over the build they become the self-sufficient engineering record.

| Directory | Contents | Master plan reference |
|---|---|---|
| `architecture/` | Current state, architecture overview, diagrams, plugin responsibility map | §4, §11 |
| `decisions/` | Architecture Decision Records (ADR-0001…), decision register | §3, §30 |
| `ux/` | Personas, benchmark, information architecture, 20 customer journeys, design tokens, usability findings | §9, §10, §13 |
| `operations/` | Runbooks, restore procedure, escalation paths, Hebrew operations guide, attribute-term conventions | §25, §27 |
| `testing/` | Test plans, UAT scripts, test evidence | §21 |
| `releases/` | Release notes and launch checklists | §26 |
| `security/` | Threat model, ASVS checklist, security review findings, incident records | §22 |
| `data-governance/` | Field matrix, source-of-truth matrix, import template and rules | §12 |

## Conventions

- One ADR per decision, numbered sequentially, never edited after acceptance —
  superseded ADRs are marked as superseded and left in place.
- Runbooks are written for someone acting under pressure at 2am: plain language,
  numbered steps, no assumed context.
- Anything a non-technical shop owner must read is written in Hebrew.
