# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.2] - 2026-09-04

### Added

- Matrix display style: the table now collapses to a vertical, stacked layout on narrow screens, matching the Webform Likert element's own mobile behavior ([#115](https://github.com/strakers/webform_ranking/issues/115)).
- Both display styles now use theme-overridable CSS custom properties for spacing, borders, and focus rings — chaining through Olivero/Claro/Gin's own tokens where present, with static fallbacks otherwise — and the drag/drop item now has a visible focus ring ([#116](https://github.com/strakers/webform_ranking/issues/116)).

### Changed

- Release tags are now plain `X.Y.Z` (no `v` prefix) on both GitHub and the new drupal.org mirror, and every historical tag/release was retargeted to match — see [CONTRIBUTING.md](docs/CONTRIBUTING.md#remotes) for the dual-remote setup.
- Drag/drop display style: the move-up/move-down buttons' `▲`/`▼` text glyphs are now SVG icons ([#117](https://github.com/strakers/webform_ranking/issues/117)), and the move buttons/N/A checkbox now align to a uniform, theme-overridable row height instead of mismatched browser defaults; the move buttons also now pick up the active theme's own button background/text/focus-ring colors (Claro/Gin) instead of the browser's bare native chrome, with hover/active/disabled/focus states ([#118](https://github.com/strakers/webform_ranking/issues/118)).
- Drag/drop display style: item/button corner rounding and the N/A opacity level now derive from the active theme's own tokens where available (e.g. Olivero and Claro/Gin both now round item/button corners slightly), instead of being fixed values; themes with neither token see no visual change ([#120](https://github.com/strakers/webform_ranking/issues/120)).
- Matrix display style: items marked N/A now dim (matching drag/drop's own treatment), and taken (unavailable) rank radios have an intentional muted color instead of a flat opacity — both theme-overridable ([#119](https://github.com/strakers/webform_ranking/issues/119)).

### Fixed

- Corrected outdated Drupal/Webform version requirements and conditional-logic documentation in README.md ([#107](https://github.com/strakers/webform_ranking/issues/107)).
- Condensed CHANGELOG.md entries to be skimmable, and fixed missing version-header and issue links ([#109](https://github.com/strakers/webform_ranking/issues/109)).
- Drag/drop display style: "Require every visible item..." is now hidden in the admin form (a no-op for this style), and a conditionally-hidden item's rank no longer leaks to other elements' conditions — it's now excluded and coalesced like a fresh submission, and reappears unranked at the end of the list when revealed ([#108](https://github.com/strakers/webform_ranking/issues/108)).
- Element-level "only show this element when..." conditions hiding a ranking element on initial page load permanently corrupted its own rows/items even once revealed, and for drag/drop, silently excluded them from the actual submission ([#123](https://github.com/strakers/webform_ranking/issues/123)).
- A ranking element's selections no longer reset to unranked across wizard "Previous" navigation, or appear blank on the Preview page ([#129](https://github.com/strakers/webform_ranking/issues/129)).
- Ranking element results/Preview HTML now bolds item labels, matching the Likert element's own output ([#132](https://github.com/strakers/webform_ranking/issues/132)).

## [0.3.1] - 2026-08-30

### Fixed

- Matrix display style, with "Require every visible item..." on: fixed a fatal error submitting with a conditionally-visible item — "Required"/"Optional" are also no longer valid per-item condition states ([#102](https://github.com/strakers/webform_ranking/issues/102)).
- Matrix display style: fixed a hidden item's stale rank silently colliding with another item's rank on reappearance, which produced a misleading validation message ([#104](https://github.com/strakers/webform_ranking/issues/104)).

## [0.3.0] - 2026-08-29

### Added

- Added a visual point-and-click builder for per-item conditional visibility (matching the element-level "Conditional logic" tab's look), replacing hand-typed YAML as the primary configuration method — raw YAML remains available for anything the builder can't represent ([#13](https://github.com/strakers/webform_ranking/issues/13), [#65](https://github.com/strakers/webform_ranking/issues/65)). Includes guardrails against stale hand-typed YAML, an unclear "between" value format, and an unsavable same-field "All" combination ([#88](https://github.com/strakers/webform_ranking/issues/88), [#92](https://github.com/strakers/webform_ranking/issues/92)).

### Fixed

- Per-item condition builder: fixed an unsaved condition edit being silently discarded when a different item row was added or removed before saving ([#79](https://github.com/strakers/webform_ranking/issues/79)).

## [0.2.2] - 2026-08-24

### Added

- Added "Require at least one item to be ranked 1st," an independent toggle for genuine engagement alongside "Allow abstaining" ([#63](https://github.com/strakers/webform_ranking/issues/63)).
- Added a "Sequential ranks error message" admin field (matrix display style) ([#74](https://github.com/strakers/webform_ranking/issues/74)).

### Changed

- Rewrote every validation error message in plainer language, matching Webform core's own message convention ([#74](https://github.com/strakers/webform_ranking/issues/74)).
- Renamed "Require 1st place message" to "Require 1st place error message" ([#74](https://github.com/strakers/webform_ranking/issues/74)).

### Fixed

- Fixed element-level "Conditional logic" (hiding/showing the whole field) not working at all — per-item conditions were unaffected ([#57](https://github.com/strakers/webform_ranking/issues/57)).
- Matrix display style: fixed an item's own conditional visibility not working when its trigger element was on an earlier wizard page ([#61](https://github.com/strakers/webform_ranking/issues/61)).
- Matrix display style: fixed a conditionally-hidden item's table row staying visible instead of hiding along with its label/radios ([#59](https://github.com/strakers/webform_ranking/issues/59)).
- Matrix display style: fixed rank columns not shrinking to match the currently-visible item count ([#60](https://github.com/strakers/webform_ranking/issues/60)).
- Matrix display style, with "Require every visible item..." on: fixed a conditionally-hidden row silently blocking submission ([#68](https://github.com/strakers/webform_ranking/issues/68)).
- Fixed validation errors rendering duplicated when Drupal core's `inline_form_errors` module is enabled ([#69](https://github.com/strakers/webform_ranking/issues/69)).

## [0.2.1] - 2026-08-21

### Added

- Added visual/ARIA indication of `#required_all` (asterisk + `aria-describedby` for matrix, screen-reader-only cue for drag/drop) ([#46](https://github.com/strakers/webform_ranking/issues/46)).
- Added `aria-invalid`/inline error text on validation failure, matching every other field on the form ([#47](https://github.com/strakers/webform_ranking/issues/47), [#48](https://github.com/strakers/webform_ranking/issues/48)).
- Added automated accessibility auditing via `ddev-pa11y` (pa11y/pa11y-ci), covering both display styles ([#9](https://github.com/strakers/webform_ranking/issues/9)).

## [0.2.0] - 2026-08-21

### Added

- Added `LICENSE.txt` (GPL-2.0-or-later).

### Changed

- Reorganized documentation: `README.md` is now user-facing only; setup/testing moved to `docs/DEVELOPMENT.md`/`docs/TESTING.md`.
- Brought the codebase into full Drupal coding-standards compliance, in preparation for a drupal.org submission.
- Minor cleanup: removed a no-op form-state call ([#30](https://github.com/strakers/webform_ranking/issues/30)), an unused loop variable ([#31](https://github.com/strakers/webform_ranking/issues/31)), and a non-pluralizable `formatPlural()` wrapper ([#32](https://github.com/strakers/webform_ranking/issues/32)).
- Renamed a confusingly-similar-named kernel test file for clarity ([#33](https://github.com/strakers/webform_ranking/issues/33)).

### Fixed

- Added item-value validation at config-save time (letters, numbers, underscores, hyphens, periods only; 128 characters max). **Note:** an already-saved item value outside these rules must be corrected before the settings form can be saved again, even for an unrelated change ([#21](https://github.com/strakers/webform_ranking/issues/21)).
- Matrix display style: fixed a conditionally-hidden item's label staying on screen after its radios were hidden ([#35](https://github.com/strakers/webform_ranking/issues/35)).
- Fixed a bogus whole-element `#states` selector option; per-item rank selectors are now grouped correctly ([#22](https://github.com/strakers/webform_ranking/issues/22)).
- Matrix display style: fixed a conditionally-hidden item's rank not being freed up for other items until a rank was reselected ([#36](https://github.com/strakers/webform_ranking/issues/36)).
- Fixed the element's value not always being written back in storage shape after a failed validation ([#37](https://github.com/strakers/webform_ranking/issues/37)).
- Matrix display style: fixed a fully-ranked matrix being unable to rearrange — rank-exclusivity now reassigns instead of disabling taken cells ([#40](https://github.com/strakers/webform_ranking/issues/40)).
- Fixed a conditional item's rank being silently dropped from a `webform_computed_twig` live recompute ([#41](https://github.com/strakers/webform_ranking/issues/41)).
- Corrected module version metadata to match actual requirements: Drupal `^10.1 || ^11` → `^10.3 || ^11`, Webform `^6.2` → `^6.3` ([#27](https://github.com/strakers/webform_ranking/issues/27)).
- Fixed `element.dragdrop`'s missing `core/drupal.states` dependency ([#23](https://github.com/strakers/webform_ranking/issues/23)).
- Fixed both display styles shadowing/relying on an incomplete `.visually-hidden` definition instead of Drupal core's ([#26](https://github.com/strakers/webform_ranking/issues/26)).
- Fixed drag/drop touch dragging not working at all ([#24](https://github.com/strakers/webform_ranking/issues/24)).
- Drag/drop display style: three accessibility fixes — correct `role="list"` structure, move buttons keeping keyboard focus after a move, and the `▲`/`▼` glyphs no longer being announced redundantly ([#25](https://github.com/strakers/webform_ranking/issues/25)).
- Fixed randomized item order reshuffling on every form rebuild instead of staying stable per submission ([#28](https://github.com/strakers/webform_ranking/issues/28)).
- Matrix display style: fixed a stolen rank not updating `#states` conditions elsewhere on the form that were watching the item that lost it ([#51](https://github.com/strakers/webform_ranking/issues/51)).

## [0.1.0] - 2026-07-12

### Added

- Drag/drop items can now be used as `#states` trigger sources, matching matrix ([#1](https://github.com/strakers/webform_ranking/issues/1), [#2](https://github.com/strakers/webform_ranking/issues/2)).
- Added FunctionalJavascript browser test coverage for both display styles ([#6](https://github.com/strakers/webform_ranking/issues/6), [#8](https://github.com/strakers/webform_ranking/issues/8)).

### Changed

- Per-item conditional visibility now opens a dialog per item instead of an inline checkbox toggle ([#4](https://github.com/strakers/webform_ranking/issues/4), [#12](https://github.com/strakers/webform_ranking/issues/12)).

### Fixed

- Fixed per-item conditional visibility never actually hiding/showing items ([#5](https://github.com/strakers/webform_ranking/issues/5), [#7](https://github.com/strakers/webform_ranking/issues/7)).
- Fixed drag/drop pointer reordering stopping after the first swap ([#3](https://github.com/strakers/webform_ranking/issues/3), [#11](https://github.com/strakers/webform_ranking/issues/11)).
- Fixed the per-item conditional-visibility toggle not reliably scoping to the correct row ([#4](https://github.com/strakers/webform_ranking/issues/4), [#12](https://github.com/strakers/webform_ranking/issues/12)).

## [0.0.1] - 2026-07-10

### Added

- Initial release: matrix and drag/drop display styles, N/A abstention, sequential/no-duplicate-rank validation, per-item conditional visibility, per-item `#states` trigger sources, randomizable order, customizable rank labels, results/CSV export, and initial test coverage.

[Unreleased]: https://github.com/strakers/webform_ranking/compare/0.3.2...HEAD
[0.3.2]: https://github.com/strakers/webform_ranking/compare/0.3.1...0.3.2
[0.3.1]: https://github.com/strakers/webform_ranking/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/strakers/webform_ranking/compare/0.2.2...0.3.0
[0.2.2]: https://github.com/strakers/webform_ranking/compare/0.2.1...0.2.2
[0.2.1]: https://github.com/strakers/webform_ranking/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/strakers/webform_ranking/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/strakers/webform_ranking/compare/0.0.1...0.1.0
[0.0.1]: https://github.com/strakers/webform_ranking/releases/tag/0.0.1
