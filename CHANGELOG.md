# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
