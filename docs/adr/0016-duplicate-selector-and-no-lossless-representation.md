# ADR-0016: Two "All"-combined conditions on the same Element: warn and withhold the write, don't emit

- **Status:** Accepted
- **Date:** 2026-08-24
- **Component/Subsystem:** `js/webform_ranking.items_admin.js` (`updateDuplicateSelectorWarning()`, `onBuilderChange()`)

## Context & Problem Statement

Two "All" (AND) conditions on the same Element have no lossless `#states` representation. The real widget's own equivalent case (`WebformElementStates::convertElementValueToFormApiStates()`) hard-errors on a duplicate selector under AND rather than emitting anything, since Drupal's `#states` shape has no "AND of two triggers via two separate rows" form — only a single row's trigger/value can itself carry a `between`/`!between` range, or multiple triggers nested under one condition object, neither of which this picker's one-trigger-per-row model produces.

A naive "emit the collapsing associative map anyway" approach was tried first: the two rows serialize to a *flow-style* YAML mapping with a duplicate key, and Symfony's flow-mapping parser (`Inline.php`, unlike its block-style `Parser.php`) unconditionally throws a `ParseException` on that rather than silently keeping the last value. So "emit something anyway" was actually "emit something that crashes the next decode" — not a harmless data-loss fallback, a guaranteed failure the moment anything tried to read the field back.

## Decision

The builder detects this specific combination (`operatorSelect.value === 'and'` with a duplicate selector among the current rows) and shows a warning message, while `onBuilderChange()` withholds the write entirely — the YAML field simply keeps its last valid value until the admin resolves the duplicate. This matches what the real widget does for the same state (nothing saved), rather than inventing a shape that isn't actually valid `#states` syntax.

## Alternatives Considered

- **Emit the collapsing associative map anyway (last row wins):** tried and rejected — produces flow-style YAML with a duplicate key, which Symfony's parser throws on unconditionally rather than silently resolving; this is emitting a value guaranteed to crash on next decode, not a harmless simplification.
- **Silently drop one of the two conflicting rows:** not pursued — would discard an admin's data entry without any indication anything was lost, worse than a visible warning that keeps both rows intact for the admin to actually resolve.
- **Invent a new shape (e.g. nest both triggers under one condition object) to represent the combination losslessly:** rejected — `#states`'s actual trigger vocabulary has no such form for two independent triggers under AND; inventing one would produce YAML that means something different (or nothing) to Drupal's real `#states` evaluator.

## Consequences & Trade-offs

### Positive

- The YAML field can never end up holding a value that throws on decode from this specific cause — the failure mode Symfony's parser would otherwise produce is prevented at the source, not just handled defensively downstream.
- Behavior matches the real element-level widget's own handling of the same combination, rather than this module inventing different semantics for an equivalent case.

### Negative / Caveats

- An admin who wants "both conditions must be true" for the same field has no direct way to express that through the visual builder — they need "Any"/"One" instead, or a single `between`/`!between` condition for a numeric range, which isn't always a semantic fit for what they're trying to express.
- The withheld-write behavior means the field can silently diverge from what the builder's rows currently show while the warning is up — resolved once the admin fixes the duplicate, but a moment of "the field doesn't match the builder" exists in between (see ADR-0017, which builds on this same withheld-write mechanism for a related but distinct scenario).

## Related Code & Docs

- **Files:** `js/webform_ranking.items_admin.js` (`updateDuplicateSelectorWarning()`, `onBuilderChange()`)
- **Related:** `src/Plugin/WebformElement/WebformRanking.php`'s try/catch around live-value YAML decoding (ADR-0005) handles the server-side half of a duplicate-selector value reaching an AJAX rebuild
