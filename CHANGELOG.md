# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `LICENSE.txt` (GPL-2.0-or-later).

### Changed

- Reorganized documentation: `README.md` now focuses on user-facing
  usage; local development setup and testing instructions moved to
  `docs/DEVELOPMENT.md` and `docs/TESTING.md`.
- Brought the codebase into full compliance with Drupal's coding
  standards, in preparation for a drupal.org project application.

### Fixed

- Ranking item values are now validated at config-save time (letters,
  numbers, underscores, hyphens, periods only; 128 characters max) —
  previously an unconstrained value could produce a malformed `#states`
  selector string that silently never matched. **Note:** if an
  already-configured Ranking element has an item value outside these
  rules, its settings form will fail to save until the value is
  corrected, even for an unrelated change (#21).
- Matrix display style: a conditionally-visible item's name label no
  longer stays on screen after its own rank radios have been hidden by
  `#states`. The label cell was a bare, attribute-less render array
  that `states.js` had nothing to bind to; it's now a proper
  `container` element that hides in lockstep with its row (#35).
- `#states` condition-builder selector options for this element no
  longer include a bogus whole-element selector that matched no real
  DOM input; per-item rank selectors are now correctly grouped under
  the element's title, like every other composite element (#22).
- Matrix display style: hiding a conditionally-visible item that
  currently holds a rank now correctly frees that rank back up for
  every other item. Previously the client-side "each rank used at
  most once" check never recomputed on a visibility change, only on a
  rank selection — leaving a rank needlessly disabled after the item
  holding it disappeared (#36).
- The ranking element's value is now always written back in its
  storage (flat item => rank) shape after validation, even when
  validation fails — several checks previously returned early and
  skipped this, which was invisible on a real failed submission but
  could leave a `webform_computed_twig` element's live AJAX recompute
  reading the element's internal, pre-conversion shape instead (#37).
- Matrix display style: a fully-ranked matrix can now be rearranged.
  Rank-exclusivity previously *disabled* every already-taken cell,
  which meant swapping two items' positions needed two simultaneous
  changes that permanently, mutually blocked each other — a
  fully-ranked matrix without `#allow_na` could never be rearranged
  again. Selecting an already-taken rank now reassigns it away from
  whichever item currently holds it instead of refusing the click
  (#40).
- A conditional ranking item's rank could be silently dropped from a
  `webform_computed_twig` element's live `#ajax`-recomputed value,
  even while genuinely visible and correctly ranked. The item
  visibility resolver was consulted with the form's stale,
  not-yet-synced submission entity instead of one reflecting the
  current request's field values, so a `#states` condition depending
  on another field changed in the same request could incorrectly
  evaluate as unsatisfied (#41).
- `element.dragdrop` library now declares its `core/drupal.states`
  dependency (which itself provides jQuery), matching `element.matrix`.
  Its live-region-renumbering code previously guessed at jQuery's
  presence at runtime (`if (window.jQuery)`) with an unverified,
  unreachable-in-practice native-`addEventListener` fallback, since
  `states.js` only ever fires `state:visible` as a jQuery event (#23).
- Neither display style redefines `.visually-hidden` itself anymore.
  The matrix style's local copy (no `!important`, a zero-size clip
  rect instead of the 1px rect assistive tech expects) shadowed core's
  own, more complete version whenever both loaded — and the drag/drop
  style's live region, which uses the same class, never guaranteed
  *any* version was loaded at all. Both libraries now depend on
  `system/base`, which provides the correct version, instead (#26).

## [0.1.0] - 2026-07-12

### Added

- Drag-and-drop ranking items can now be used as `#states` trigger sources
  for other form elements, matching the matrix display style's existing
  capability (#1, #2).
- FunctionalJavascript browser test coverage for both display styles:
  pointer/keyboard/button reordering, N/A toggling, rank exclusivity,
  live `aria-live` announcements, and live `#states` reactions (#6, #8).

### Changed

- Per-item conditional visibility on the admin config form now opens a
  dialog per item ("Conditions" button) instead of an inline checkbox
  toggle, reducing clutter in the items table when several items have a
  condition configured (#4, #12).

### Fixed

- Per-item conditional visibility (`#states` YAML) never actually hid or
  showed ranking items — the submitted YAML was never decoded into an
  array, so the condition silently never matched (#5, #7).
- Drag-and-drop pointer-based reordering stopped responding after the
  first item swap during a real mouse/trackpad drag — reparenting the
  dragged item mid-gesture was silently breaking pointer capture in
  Chromium (#3, #11).
- Admin config form's per-item conditional-visibility toggle didn't
  reliably scope to the correct item row (#4, #12).

## [0.0.1] - 2026-07-10

### Added

- Initial release of the Ranking element for Webform.
- Two display styles: a matrix of radio buttons (one row per item, one
  column per rank) and a drag-and-drop list (pointer drag, touch, arrow
  keys, and move-up/down buttons).
- Optional "Not Applicable" abstention per item.
- Server- and client-side validation: no duplicate ranks, no gaps in
  rank sequence among currently-visible items.
- Per-item conditional visibility via a `#states`-style YAML condition,
  configurable per item on the admin form.
- Matrix items usable as `#states` trigger sources for other elements
  (e.g. "show this field only if Pizza is ranked 1st").
- Randomizable item display order, to reduce position bias in
  survey-style rankings.
- Customizable rank position labels (overriding the default "1st, 2nd,
  3rd..." labels).
- Results/CSV export formatting, ordered by resolved rank.
- Unit and Kernel test coverage.

[Unreleased]: https://github.com/strakers/webform_ranking/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/strakers/webform_ranking/compare/v0.0.1...v0.1.0
[0.0.1]: https://github.com/strakers/webform_ranking/releases/tag/v0.0.1
