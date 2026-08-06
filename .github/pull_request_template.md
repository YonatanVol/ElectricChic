<!--
  Fill this in properly. Passing CI is a precondition for review, not a
  substitute for it — a reviewer needs to judge architecture, behaviour, UX,
  security and maintainability, and cannot do that from a green tick.

  If this pull request is too large to review properly, split it.
-->

## Linked issue

Closes #

## What changed



## Why



## Evidence

<!--
  Screenshots or recordings for any UI change. RTL evidence is not optional:
  Hebrew layout breaks in ways that are invisible on an English screenshot.
  Write "N/A — no UI" where genuinely not applicable.
-->

| | |
|---|---|
| Desktop | |
| Mobile | |
| RTL / Hebrew | |

## Tests performed

<!-- What you ran, and what it printed. "Tests pass" is not evidence. -->

- [ ] `composer check` passes locally
- [ ] New behaviour is covered by a test that fails without the change

## Database or configuration changes

<!-- Migrations, settings applied by hand, WooCommerce configuration. "None" is a valid answer. -->

## Accessibility

<!-- WCAG 2.2 AA. Keyboard operable, focus visible and not obscured, contrast,
     labels and errors associated, target size. "N/A — no UI" if applicable. -->

## Security

<!-- Capability checks, nonces, escaping, sanitisation, data exposure.
     Anything touching orders must use WooCommerce CRUD APIs — the HPOS sniff
     enforces this, but a green sniff is not the same as a considered design. -->

## Deployment notes

<!-- Anything that must happen around the deploy, in order. "None" is valid. -->

## Rollback notes

<!-- How to undo this if it goes wrong in production. Every change needs an answer. -->

## Known limitations

<!-- What this does not do, and what you decided to defer. Be honest here —
     an undisclosed limitation becomes someone else's surprise. -->
