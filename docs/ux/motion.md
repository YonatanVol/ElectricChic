# Motion

How the storefront moves, why each piece moves the way it does, and how it
was measured. Read this before adding an animation.

**Owner:** `wp-content/themes/electricchic-child` — `assets/css/design-system.css`
(Part II) and `assets/js/ec-motion.js`.

---

## The three rules

Each exists because breaking it has cost a real person something on this
project or its siblings.

1. **Hover never changes layout.** A fill fades, an opacity shifts, a transform
   moves a thing a few pixels. Nothing reflows under the pointer, so nothing a
   customer is about to tap moves away from their finger.
2. **Interactive responses are short and firm; ambient reveals are longer and
   softer.** Taps, hovers and the purchase bar: about 0.2s on
   `cubic-bezier(0.2, 0.8, 0.2, 1)`. Reveals as a section enters: 0.6s on
   `cubic-bezier(0.22, 1, 0.36, 1)`. A button that takes 0.6s feels broken; a
   section that snaps in feels cheap.
3. **Transforms and opacity only.** The compositor does the work and the main
   thread stays free for the page. The one exception is the search field's
   width, and it is the last element in its row so nothing beside it moves.

And two that follow from the project's own rules:

- **Nothing is load-bearing in JavaScript.** Every element the script touches
  is already in its final, readable state without it. The reveal class is added
  *by* the script, never written into the markup, so a blocked file can never
  leave a blank page.
- **Reduced motion is honoured at every entry point**, in CSS and in the script.
  A user who turns it on mid-session gets a reload: heavy-handed, but the only
  honest way to stop what is already in flight.

---

## What moves, and where it lives

| Piece | Where | Mechanism |
|---|---|---|
| Hero entrance: copy in four beats, machine rolls in forward | CSS `@keyframes ec-rise`, `ec-roll-in` | Once, on first paint. Suppressed under `html.ec-morphing` so it never plays over a page morph |
| Hero parallax | CSS `animation-timeline: scroll(root)` inside `@supports` | Browsers without it get no drift, which is the right fallback |
| Staggered reveals | JS `IntersectionObserver` sets `transition-delay` per batch | Everything entering in one callback is one group, 55ms apart, capped at 8 |
| Card lift and grounded shadow | CSS on `.ec-grid li:hover` | Image lifts 5px; the shadow stays on the ground and tightens |
| Product morph between pages | JS `pageswap` / `pagereveal` + CSS `::view-transition-group(ec-product)` | Names only the tapped card's image and the destination stage image, at the last moment |
| Studio light follows the pointer | JS writes `--ec-lx` once per frame | On the hero and the product stage only — never on `:root` |
| Sticky purchase bar | PHP renders, JS shows | A scroll listener reading one rectangle per frame. Not an observer: see below |
| Mini-cart badge bump | JS `MutationObserver` + CSS `ec-bump` | 0.45s, once per change |
| Add-to-cart progress | CSS `.is-busy` | Anything slower than half a second shows progress |
| Phone menu | CSS stagger on the navigation block's own overlay | The container's transition is WordPress's |

---

## Things that were measured wrong before they were fixed

Recorded so nobody re-learns them.

- **Sticky resolves against the nearest scroll container**, and `overflow-x:
  clip` on `body` or `html` makes one. The header and the demo banner declared
  `position: sticky` for weeks and sat at -556px at scrollY 600.
- **A sticky element cannot leave its parent's box.** The template-part wrapper
  was exactly the header's height. The wrapper is the sticky element now.
- **`backdrop-filter` makes its element the containing block for `position:
  fixed` descendants.** The navigation overlay was a 180px strip inside the
  header until the filter moved to a pseudo-element.
- **An `IntersectionObserver` reports changes, not states.** A buy button
  below the viewport before a flick and above it after was never intersecting
  at any sampled moment, so the purchase bar never appeared on a phone.
- **Driving a `filter` from `:root` touches every element that reads it.** The
  pointer light on `:root` re-rasterised two dozen catalogue cut-outs per move.

---

## How it is verified

```bash
npm install            # once; Playwright + Chromium
npm run audit:pages    # against http://localhost:8080 by default
```

`scripts/audit-pages.mjs` loads each page at 1440 and 390, scrolls it so the
reveals fire, and records: a full-page screenshot, horizontal overflow (must be
0), every tappable element under 24px, cards versus availability badges (must be
equal), console errors, and frame gaps during an idle second and during a
pointer sweep. `scripts/audit-frames.mjs` records frame gaps during the hero
entrance, a scroll through the catalogue, the purchase bar appearing, and the
pointer light, and prints where any long frame fell — the only long frames on
record are the first two after load, which is paint, not animation.

Hebrew letter order is checked by measurement, not by reading a screenshot: the
first letter of a Hebrew word must be the rightmost. The probe measures the
purchase bar's badge and button with a `Range` per glyph.
