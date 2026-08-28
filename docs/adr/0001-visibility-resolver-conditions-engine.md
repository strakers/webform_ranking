# ADR-0001: Delegate item-visibility evaluation to Webform's own conditions engine

- **Status:** Accepted
- **Date:** 2026-07-09
- **Component/Subsystem:** `WebformRankingVisibilityResolver` (server-side ranking item visibility)

## Context & Problem Statement

Each ranking item can carry an optional `states` condition (the same shape as Webform's own `#states` property) controlling whether it's included at all. Client-side `#states`/`states.js` already hides/shows items in the browser, but that's never trustworthy on its own: server-side validation and processing must independently recompute which items are actually visible from the *submitted* value of whatever trigger element each condition references — never trust DOM/client visibility, since a respondent (or a replayed/edited submission) can present values the client-side UI never actually showed.

The item's own `states` array is state-keyed (e.g. `['visible' => [selector => condition]]`), matching Webform's own `#states` element property shape — not the inner conditions array `WebformSubmissionConditionsValidator::validateConditions()` expects directly on its own.

## Decision

Built on Webform's own conditions engine (`WebformSubmissionConditionsValidatorInterface`) rather than a hand-rolled `#states` evaluator, so a condition means exactly the same thing here as everywhere else in Webform — same operators, same edge cases, same bugs-if-any, with no risk of this module's own evaluator silently drifting from core's semantics over time.

`WebformRankingVisibilityResolver::isVisible()` is the piece that bridges the shape mismatch: it unwraps the state key (handling the `invisible`/`!visible` negation) and calls `validateState($state, $conditions, $submission)` — the same pattern Webform itself uses for wizard pages (see `WebformSubmissionConditionsValidator::buildPages()`, which extracts `$state`/`$conditions` from `#states` the same way before calling `validateState()`).

Three possible outcomes per state, each a deliberate choice:

- **No submission context at all** (element used standalone outside a real Webform submission form, or a bare test harness): fails **closed** — any item with a configured `states` condition is treated as not visible, since there's no submitted trigger-element data to evaluate against. Unconditional items are unaffected.
- **A resolvable state** (`validateState()` returns `TRUE`/`FALSE`): governs inclusion directly.
- **An unresolvable state** (`validateState()` returns `NULL` — the condition references a selector/element that doesn't exist, e.g. a typo in the admin's YAML): fails **open**, with a logged warning naming the item and state. A config typo is an admin-authoring error, not untrusted client data; failing closed here would silently drop a respondent's answer with no diagnostic anywhere. This matches Webform's own convention elsewhere (`$result !== NULL && !$result`).

## Alternatives Considered

- **Hand-rolled `#states` evaluator:** rejected — would need to independently track every trigger/operator Webform's own client-side `states.js` and server-side validator support, with no guarantee of staying in sync as Webform evolves. Reusing the real engine means this module inherits fixes and new trigger types for free.
- **Fail closed on an unresolvable condition (config typo):** rejected for that specific case — a typo in an admin's saved YAML isn't a security-relevant "untrusted input" scenario, and silently excluding a respondent's real answer from a ranking with no visible error was judged worse than the alternative (item included with a logged warning an admin can find and fix).

## Consequences & Trade-offs

### Positive

- Visibility semantics for a ranking item's `states` condition are guaranteed identical to every other `#states` usage in Webform, with no separate implementation to maintain or keep in sync.
- The fail-open/fail-closed split gives a clear, documented answer for both failure modes (missing submission context vs. unresolvable condition) instead of one blanket behavior for both.

### Negative / Caveats

- Any bug or quirk in Webform core's own conditions engine is inherited here too — this resolver has no independent evaluation logic to fall back on or diverge from.
- The fail-open path logs a warning rather than surfacing a hard error anywhere in the admin UI; an admin who never checks logs could have a stale/typo'd condition silently including an item indefinitely.

## Related Code & Docs

- **Files:** `src/WebformRankingVisibilityResolver.php`
- **Drupal APIs:** `\Drupal\webform\WebformSubmissionConditionsValidatorInterface::validateState()`, `\Drupal\webform\WebformSubmissionConditionsValidator::buildPages()`
