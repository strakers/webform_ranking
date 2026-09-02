# ADR-0018: Removing the required/optional #states mirror for conditional matrix rows

- **Status:** Accepted
- **Date:** 2026-08-30
- **Component/Subsystem:** `WebformRanking::buildMatrix()`, `Plugin\WebformElement\WebformRanking`

## Context & Problem Statement

GitHub issue #68's fix (see ADR-0007) made a conditionally-visible matrix
row's native `required` attribute live-toggle in lockstep with its
visibility, by mirroring the row's own `visible`/`invisible` `#states`
condition into a `required`/`optional` key inside that same `#states`
array, attached to the row's raw `radio` (rank cells) and `container`
(label) sub-elements.

This crashed submission with an uncaught
`Drupal\Component\Plugin\Exception\PluginNotFoundException` (GitHub
#102). Drupal core's `Drupal\webform\WebformSubmissionConditionsValidator
::validateFormElement()` recurses every element on the form during
validation; for any element carrying a `required`/`optional` key in
`#states`, it resolves a Webform element plugin for it by `#type` (via
`WebformElementManager::getElementInstance()`) and unconditionally calls
`getFormElementClassDefinition()` on it. A bare `radio`/`container` has
no registered Webform element plugin, so the manager falls back to its
own generic `webform_element` placeholder plugin
(`WebformElementManager::getFallbackPluginId()`).
`getFormElementClassDefinition()` on that placeholder calls
`$this->elementInfo->getDefinition($this->getBaseId())` — and for the
placeholder, `getBaseId()` returns the literal string `'webform_element'`,
which is not a registered Drupal render element type (`#[RenderElement]`),
only a Webform *plugin* id. That throws the fatal exception.

The crash fires whenever `validateConditions()`'s result (inverted for
`optional`) is truthy — which includes the item's ordinary default/visible
state, not only mid-interaction. No JS or trigger-toggling is needed to
reproduce it; a plain page load followed by a submit is sufficient
whenever a conditionally-visible item exists on a form with
`#required_all` true (which is the default for a newly-added ranking
element).

A second, independent path produced the same crash-triggering shape: the
per-item visual condition builder (#65/0.3.0) let a site builder pick
"Required"/"Optional" directly as an item's own condition *state* via
`WebformRanking::PICKER_STATE_KEYS`, regardless of `#required_all`.

## Decision

Remove the mirror entirely rather than rework it. A conditionally-visible
row's native `required` attribute is now permanently withheld — never
applied, never live re-added — instead of being kept in sync with
visibility via `#states`. `WebformRankingVisibilityResolver`'s
`#required_all` check in `validateWebformRanking()` remains the
authoritative, unbypassable server-side enforcement regardless of this
change, so submission correctness is unaffected.

`required`/`optional` are also removed outright from
`WebformRanking::PICKER_STATE_KEYS`, so the per-item condition builder's
"State" dropdown no longer offers them — an item's required-ness is
derived entirely from `#required_all`, not an independent per-item
condition. `WebformRanking::validateConfigurationForm()` additionally
rejects `required`/`optional` if hand-typed into an item's raw YAML "Edit
source" field, so the dropdown removal can't be silently bypassed.

## Alternatives Considered

- **Keep mirroring, but guard against the fallback plugin** (e.g. check
  `$element_plugin` isn't the generic fallback before calling
  `getFormElementClassDefinition()`): rejected — patches around a gap in
  Webform core's own generic conditions validator rather than not relying
  on the mechanism that exposes it; fragile against future core changes
  to that fallback path.
- **Give the mirrored sub-elements their own dedicated Webform element
  plugin** so they resolve normally: rejected as disproportionate
  complexity for an internal rendering detail with no other reason to
  exist as a first-class Webform element.

## Consequences & Trade-offs

### Positive

- Removes this crash class entirely — no element this module builds can
  trigger `WebformSubmissionConditionsValidator`'s fallback-plugin path
  anymore.
- Simpler `buildMatrix()`: one suppression flag instead of a mirrored
  `#states` merge.

### Negative / Caveats

- A conditionally-visible required row no longer gets the browser's
  native "you must fill this in" nag before submit — only
  `validateWebformRanking()`'s server-side check catches it now. Slightly
  worse UX (a round-trip to see the error instead of an instant browser
  block), but correctness is unchanged.
- `required`/`optional` are no longer valid per-item condition states at
  all, even for a hypothetical future use unrelated to `#required_all` —
  acceptable, since they never had a coherent meaning here in the first
  place (see Context).

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`buildMatrix()`),
  `src/Plugin/WebformElement/WebformRanking.php` (`PICKER_STATE_KEYS`,
  `validateConfigurationForm()`)
- **Supersedes:** ADR-0007's "Native `required`, mirrored through
  `#states` when the row is conditional" decision (that ADR's other two
  decisions — container wrapper, per-column radio cells — are unaffected
  and remain in effect).
- **GitHub Issues:** #68 (original mirror fix, now reverted), #102 (this
  fix)
