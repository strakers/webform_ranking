# Development

This document covers setting up a local environment to work on the
Webform Ranking module itself — installing it as a project dependency is
covered in the main [README](../README.md).

## Local environment

Development happens against a full Drupal core checkout managed by
[DDEV](https://ddev.com/), using the
[`ddev-drupal-contrib`](https://github.com/ddev/ddev-drupal-contrib)
add-on. `.ddev/` is gitignored in this repo, so a fresh clone needs its
own DDEV setup — this module doesn't assume a specific host environment.

Once DDEV is running and the site is installed, the module lives at
`web/modules/contrib/webform_ranking` (or wherever your `ddev-drupal-contrib`
setup places contrib modules) and can be enabled the same way as any
other module:

```bash
ddev drush en webform_ranking
```

## Project structure

- `src/Element/WebformRanking.php` — the Form API element
  (`#type => webform_ranking`): value handling, building the matrix/
  drag-and-drop sub-render arrays, and server-side validation.
- `src/Plugin/WebformElement/WebformRanking.php` — the Webform element
  plugin: the admin configuration form, default properties, and the
  `#states`/results-formatting integration points Webform itself calls
  into.
- `src/WebformRankingConverter.php` — pure, dependency-free conversion
  functions between the element's canonical value shape and its
  on-the-wire matrix/drag-and-drop shapes. If you're changing how ranks
  are represented, this is almost always the right place to start.
- `src/WebformRankingVisibilityResolver.php` — resolves which
  conditionally-visible items are currently shown, given a submission.
- `js/` — one file per client-side concern: `webform_ranking.matrix.js`
  (rank-exclusivity, live announcements), `webform_ranking.dragdrop.js`
  (the pointer-based reorder engine), `webform_ranking.items_admin.js`
  (the per-item conditional-visibility dialog on the admin config form).
- `css/` — structural/layout styles only; see Known Gaps in
  [CONTINUATION.md](CONTINUATION.md) regarding visual design.
- `tests/` — see [TESTING.md](TESTING.md).

## Architecture, design rationale, and known gaps

The *why* behind non-obvious decisions in this codebase — value-shape
conversions, `#states` integration quirks, things that were tried and
reverted, and gaps that are known but not yet addressed — is tracked in
[CONTINUATION.md](CONTINUATION.md). If you're about to change how the
element stores or resolves data, read that first; several of its Key
Design Decisions exist specifically because an earlier, more obvious
approach silently broke something non-obvious.

## Coding conventions

- New JavaScript should use `const`/`let`, not `var`. The existing files
  under `js/` still use `var` as of this writing — bringing them in line
  is tracked as a separate cleanup, not something to mix into unrelated
  changes.
- `#webform_multiple`'s row markup should be treated as unverified unless
  you've confirmed it directly (see `CONTINUATION.md`'s "Pattern Worth
  Knowing" section) — more than one bug in this module traced back to an
  assumption about that markup that turned out to be wrong.
- Prefer verifying behavior against a real browser/test run over
  reasoning from source alone, especially for anything touching Webform
  or Drupal core internals. This codebase's history has several
  instances of confident-but-wrong guesses about internal APIs that only
  got caught by actually running something.

## Running tests

See [TESTING.md](TESTING.md) for how to run the test suite and what each
tier covers.
