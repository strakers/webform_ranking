# ADR-0017: Warn instead of re-parsing hand-typed YAML back into builder rows

- **Status:** Accepted
- **Date:** 2026-08-28
- **Component/Subsystem:** `js/webform_ranking.items_admin.js` (`showBuilder()`, `writeYamlField()`, the "Edit source" note)

## Context & Problem Statement

The condition-rows DOM is the builder's only state (see ADR-0014) — toggling to "Edit source" never destroys the rows, only hides them. But "Back to condition builder" never re-read the YAML textarea either: an admin who hand-typed something in "Edit source" and switched back saw the builder's own (unrelated, possibly stale) rows with no indication anything was different. The very next builder interaction — even a no-op one, like re-selecting an already-chosen dropdown option — then silently overwrote the hand-typed text via `onBuilderChange()`, discarding the admin's edit with no warning at all (GitHub issue #88).

Separately, hand-typing in "Edit source" bypasses two safety nets the visual builder itself enforces: the same-Element-under-"All" check (ADR-0016), and — since it only applies to the builder's own dedicated Value input in the first place — the `between`/`!between` `min:max` format hint. Neither can be validated for raw text, since no YAML parser is vendored client-side for this (see ADR-0005's own note on that same gap, in the PHP-side condition lookup).

## Decision

**No re-parse; warn instead.** A full re-parse of arbitrary hand-typed YAML back into builder rows was considered and deliberately not attempted — hand-rolling a YAML parser just for this warning would be a much larger, riskier change than the problem (a UX rough edge, not a data-integrity bug) warrants. Instead, `showBuilder()` compares the YAML field's current text against `lastWrittenYaml` (the value the builder itself last wrote, tracked by `writeYamlField()`) and shows a non-blocking warning when they differ — the next builder interaction still overwrites the hand-typed text exactly as before, but the admin now sees it coming instead of it happening silently. The warning clears itself once `writeYamlField()` runs again (builder output and field agree once more).

**Tell the admin up front, not after the fact.** "Edit source" also gained a plain-language note, shown immediately on switching to that view, naming both footguns (the AND-duplicate restriction and the `min:max` format) before an admin can run into either one — rather than only reacting after a mistake is already made.

## Alternatives Considered

- **Full YAML parse-back into rows on "Back to condition builder":** rejected — no client-side YAML parser exists in this codebase (a documented, pre-existing gap — see ADR-0005), and writing one specifically for this warning would be disproportionate to the problem's actual severity.
- **Block/confirm before allowing "Back to condition builder"** (e.g. a native `confirm()` dialog): considered and rejected in favor of the non-blocking warning — this codebase has no existing pattern for handling native browser dialogs in its `FunctionalJavascript` tests, and a warning banner matches the existing style already used for the duplicate-selector and non-visibility-state warnings.
- **Silently keep the hand-typed text and never let the builder overwrite it:** rejected — the condition-rows DOM is the authoritative state (ADR-0014); making the raw text field authoritative instead whenever it disagrees would be a much larger architectural change, not a targeted fix for a UX gap.

## Consequences & Trade-offs

### Positive

- Data loss from switching between "Edit source" and the builder is no longer silent — an admin sees a clear warning before it happens, in the same visual style as this dialog's other warnings.
- Both hand-typing footguns are now flagged proactively, not just discovered by hitting a confusing error at Save.

### Negative / Caveats

- The underlying behavior is unchanged: a builder interaction after a hand-typed edit still discards that edit. The fix only makes the loss visible, it doesn't prevent it — a future issue could revisit actually preserving hand-typed content if this rough edge proves to matter more in practice.
- `lastWrittenYaml` tracking adds a small piece of state that must stay correctly updated everywhere `writeYamlField()` is called (including `clear()`) — a future new write path that bypasses `writeYamlField()` would silently break the staleness detection.

## Related Code & Docs

- **Files:** `js/webform_ranking.items_admin.js` (`showBuilder()`, `writeYamlField()`, `getYamlFieldValue()`)
- **GitHub Issues:** #88
