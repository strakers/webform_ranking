# ADR-0025: CSS `:has()` for matrix's N/A row styling, over a JS class toggle

- **Status:** Accepted
- **Date:** 2026-09-04
- **Component/Subsystem:** `css/webform_ranking.matrix.css`

## Context & Problem Statement

GitHub issue #119 asked for a distinct visual treatment when a matrix
item is marked N/A — something matrix had no equivalent of at all,
unlike drag/drop's own opacity dimming. Styling a `<tr>`/label based on
"its N/A radio is currently checked" needs some way to react to a
descendant input's checked state, since the checked radio is nested
several levels below the row that needs to visually change.

Two viable approaches:

1. **A JS-driven class toggle**, mirroring the existing
   `markTakenRanks()` pattern in `js/webform_ranking.matrix.js` exactly
   — a new function toggles a class on the row whenever the N/A radio's
   checked state changes (on `change`, and on the existing
   `state:visible` handling).
2. **The CSS `:has()` selector** — `.webform-ranking-matrix
   tr:has(input[value="na"]:checked)` — no JS at all; the browser
   reacts to the checked state natively.

## Decision

`:has()`. No JS changes.

```css
.webform-ranking-matrix tr:has(input[value="na"]:checked) {
  opacity: var(--webform-ranking-na-opacity, var(--input--disabled-border-opacity, 0.7));
}
```

`:has()` has broad support in every evergreen browser as of this
writing (Chrome 105+/2022, Firefox 121+/2023, Safari 15.4+/2022) and is
a **pure progressive enhancement** here: on an unsupported browser, the
rule simply never matches — the row doesn't dim, but every actual
feature (selecting N/A, submitting, validation) is completely
unaffected. No functionality depends on this styling applying; it's
purely cosmetic feedback. That makes the "what happens on old
browsers" question low-stakes in a way it wouldn't be for, say, a
layout-critical rule.

## Alternatives Considered

- **JS class toggle (`markTakenRanks()`'s own pattern):** rejected —
  works in every browser this module supports, but adds real surface
  area for something with no functional consequence: a new function,
  new call sites (the same `change` and `state:visible` listeners
  `markTakenRanks()` already hooks), and a natural expectation of new
  test coverage for a change that's purely cosmetic. `:has()` gets the
  identical visual result with zero lines of JS and zero new test
  surface.
- **Both (`:has()` primary, JS toggle as a fallback for unsupported
  browsers):** rejected — doubles the maintenance burden for a purely
  cosmetic feature; the "unsupported browser sees no dimming" outcome
  is fully acceptable on its own, so there's nothing worth a fallback
  for.

## Consequences & Trade-offs

### Positive

- Zero JS surface for a purely visual feature — no new function, no
  new event wiring, no new test to keep in sync with markup changes.
- Genuinely simpler than the alternative it replaces in spirit
  (`markTakenRanks()`'s manual class bookkeeping) for a case that
  doesn't need manual bookkeeping at all.

### Negative / Caveats

- No visual feedback on browsers older than roughly 2022 — accepted,
  since nothing functional depends on it.
- Establishes `:has()` as an accepted technique in this codebase's CSS,
  alongside `:focus-visible` (already in use) — worth remembering as
  precedent if a future change needs to weigh a similar JS-vs-CSS
  trade-off.

## Related Code & Docs

- **Files:** `css/webform_ranking.matrix.css`
- **Related:** [ADR-0021](0021-css-custom-property-convention.md) (the
  `--webform-ranking-na-opacity` token this rule uses, introduced in
  #120), [ADR-0022](0022-inline-svg-via-nested-render-arrays.md) (this
  ADR's own precedent for documenting a single technique decision)
- **GitHub Issues:** #119
