# Testing

The test suite has three tiers, following Drupal core/Webform's own
conventions. All three run through the same PHPUnit entry point — there's
no separate JavaScript toolchain (see "Why FunctionalJavascript, not
Nightwatch" below).

## Running the suite

Run everything:

```bash
ddev phpunit --group webform_ranking
```

Run a single test class or method:

```bash
ddev phpunit tests/src/FunctionalJavascript/WebformRankingDragdropJavaScriptTest.php
ddev phpunit --filter testGradualPointerDragReordersItems tests/src/FunctionalJavascript/WebformRankingDragdropJavaScriptTest.php
```

## Test tiers

### Unit (`tests/src/Unit/`)

No Drupal bootstrap — plain PHPUnit. Covers pure functions with no
framework dependency: `WebformRankingConverter`'s value-shape conversions
and `WebformRankingVisibilityResolver`'s resolution logic (including a
regression test for its fail-closed behavior when no submission context
is available).

### Kernel (`tests/src/Kernel/`)

A minimal Drupal bootstrap (database, module system) without a full
HTTP/browser stack. Covers:

- `validateWebformRanking()`'s server-side validation rules directly,
  including forged/malformed input a real form would never submit but a
  crafted request could.
- The real `valueCallback()` → `validateWebformRanking()` path (not via
  `FormBuilder::submitForm()` — see `CONTINUATION.md` Key Design
  Decision #6 for why).
- The plugin's own methods (`getItemRankValue()`, `getTestValues()`,
  `#states` selector generation/resolution for both display styles,
  `prepare()`'s config normalization).

### FunctionalJavascript (`tests/src/FunctionalJavascript/`)

Real-browser coverage via `WebDriverTestBase`/Mink, driven by a real
WebDriver Chrome session. This is the only tier that can exercise actual
pointer/keyboard interaction, live `#states` reactions, and admin-form JS
(dialogs, progressive disclosure, etc.) — anything that depends on real
DOM events and layout, not just server-side logic.

**One-time local setup**: FunctionalJavascript tests need a real
WebDriver-compatible browser, which isn't part of the base DDEV setup.
Install the official companion add-on once:

```bash
ddev add-on get ddev/ddev-selenium-standalone-chrome
ddev restart
```

**Test files**:

- `WebformRankingMatrixJavaScriptTest` — rank-exclusivity behavior,
  `aria-live` announcements, live `#states` reactions for the matrix
  display style.
- `WebformRankingDragdropJavaScriptTest` — pointer drag (both a
  single-jump and a gradual multi-step drag — see below), move-up/down
  buttons, arrow-key reordering, N/A toggling, and live `#states`
  reactions for the drag-and-drop display style.
- `WebformRankingItemsAdminJavaScriptTest` — the per-item conditional-
  visibility dialog on the element's admin config form. Requires
  `webform_ui` in `$modules` (unlike the other FunctionalJavascript
  tests here), since that submodule provides the element edit form
  route this test navigates to.

#### Why a "gradual" drag test exists alongside a single-jump one

`WebformRankingDragdropJavaScriptTest` has two conceptually different
pointer-drag tests, and both are needed:

- `testPointerDragReordersItems()` uses Mink's `NodeElement::dragTo()`,
  which issues exactly one `pointerMove` straight from source to
  destination.
- `testGradualPointerDragReordersItems()` drives the raw W3C WebDriver
  Actions API directly, with several discrete `pointerMove` steps in one
  gesture — closer to how a real mouse/trackpad drag actually behaves.

These aren't redundant: a real bug (pointer capture silently breaking
after the first mid-drag DOM reorder — see `CONTINUATION.md` Key Design
Decision #16) only reproduced under the multi-step gesture, not the
single-jump one. If you're testing drag/drop behavior and only writing a
`dragTo()`-based test, you may be missing exactly the class of bug that
matters most for real users.

#### Testing dialogs and CodeMirror-backed fields

If you're testing UI that involves a `webform_codemirror` field (as
`WebformRankingItemsAdminJavaScriptTest` does), note that Webform's own
CodeMirror JS (`webform.element.codemirror.js`) replaces the real
`<textarea>` with its own rendered editor and leaves the original
`<textarea>` permanently `display: none`. A few consequences for tests:

- Mink's `NodeElement::isVisible()`/`getValue()` on the raw textarea
  will never reflect reality — read/write through JS instead (see
  `WebformRankingItemsAdminJavaScriptTest::getOpenDialogYamlValue()`/
  `setOpenDialogYamlValue()` for a working pattern).
- CodeMirror debounces syncing its own editor content back to the
  textarea by 500ms. Setting the textarea's value directly and
  immediately submitting can race that debounce and lose the change.
  Prefer writing through CodeMirror's own API
  (`.CodeMirror.setValue()` + an immediate `.save()`) when it's
  attached.

## Troubleshooting

**FunctionalJavascript tests all fail with a 502 ("upstream sent too big
header")**: nginx's `fastcgi_buffer_size` is too small for the large
cache-tag response headers Drupal's `WebDriverTestBase` environment
produces with many core modules enabled. This project's
`.ddev/nginx_full/nginx-site.conf` (local-only, gitignored) needs
`fastcgi_buffers 16 64k; fastcgi_buffer_size 128k;` or larger. If you
customize this file, avoid using the literal string `#ddev-generated`
anywhere in it (including in your own comments) — DDEV does a substring
search for that marker on `ddev restart` and will silently regenerate
(and overwrite) any file containing it, including comments explaining
why you removed the marker.

## Why FunctionalJavascript, not Nightwatch

Drupal core uses Nightwatch (a separate Node.js/npm toolchain) for its
own JS behavior tests, but Webform — the module this codebase mirrors
conventions from throughout — uses FunctionalJavascript instead: pure
PHPUnit, runs via the same `ddev phpunit` command already used for
Unit/Kernel tests, no separate toolchain to install or maintain. This
project follows Webform's choice for the same reason.
