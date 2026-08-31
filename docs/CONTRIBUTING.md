# Contributing

This document covers *process* — how changes get made in this repo,
regardless of whether the person (or AI agent) making them is a
first-time contributor or has been working on this module for months.
For environment setup, project structure, and coding standards, see
[DEVELOPMENT.md](DEVELOPMENT.md); for running tests, see
[TESTING.md](TESTING.md); for the *why* behind existing design
decisions, see [CONTINUATION.md](CONTINUATION.md) and `docs/adr/`.

These conventions apply the same way whether you're a human or an AI
coding agent. The discipline below — branch, issue, changelog, review —
exists specifically to keep changes reviewable and reversible, which
matters more, not less, when a large share of changes in this repo are
agent-authored.

## Branching

- Work happens on a fresh branch off `dev`, never committed directly to
  `dev` or `main`. The one established exception: finalizing
  `CHANGELOG.md`'s `[Unreleased]` section to a dated version heading
  immediately before a release — that's a direct commit on `dev`,
  matching this project's own release history, and only because it's a
  single-line, purely mechanical change with a long precedent. Ask
  before treating anything else as a similar exception.
- Branch names use a `bugfix/` or `feature/` prefix (not `fix/`) plus a
  short, kebab-case description of the change.
- `dev` is the integration branch; `main` is the stable/release branch.
  A `dev`→`main` PR is how a release ships — it bundles everything
  merged into `dev` since the last release.
- Merges into `dev` — and later `dev`→`main` — do not auto-close linked
  issues, even with `Fixes #N` in the PR, since GitHub only does that
  for merges into the repo's actual default branch (`main`), not `dev`.
  See the Issues section below for the manual step this requires.

## Issues

- File an issue before starting non-trivial work: bugs, features,
  cleanup passes. A small, fully-specified, low-risk change someone has
  already described in detail can skip straight to a branch.
- Write issue bodies in plain, non-technical language wherever a
  non-engineer (QA/BA) stakeholder might need to read them. Bullet
  points are fine; the reader shouldn't need deep codebase familiarity
  to understand the problem.
- One issue per distinct concern — don't bundle unrelated problems into
  a single issue. Split and cross-reference instead of letting an issue
  grow into a grab-bag.
- Before writing a root cause or a "this works like X" claim into an
  issue, verify it against the actual current code or a real
  reproduction — don't restate an assumption as fact. This codebase's
  own history has several examples of a plausible-sounding claim turning
  out to be wrong once actually checked (see `CONTINUATION.md`'s
  "Pattern Worth Knowing" section).
- Once a PR that fixes an issue is confirmed merged, close the issue
  manually with a comment linking back to that PR. This is a required
  step, not a nice-to-have: as noted under Branching, a merge into `dev`
  never auto-closes anything, so without this step a fixed issue just
  stays open indefinitely. Do this right after confirming the merge,
  not batched up for later.

## Changelog

- Every notable, user-facing change gets a `CHANGELOG.md` entry under
  `## [Unreleased]` at the time the change is made — not deferred until
  release.
- A purely internal change with no observable effect on a feature that
  hasn't shipped yet (e.g. a bugfix to code still under an
  `[Unreleased]` entry) usually doesn't need its own bullet — fold it
  into whatever existing entry already describes that feature, and note
  the fix in `docs/CONTINUATION.md` instead.
- Keep entries to one sentence — two only if genuinely necessary. Full
  technical detail (root cause, mechanism, alternatives considered)
  belongs in `docs/CONTINUATION.md` or a `docs/adr/` entry, not here.
- Reference the relevant issue(s) as real markdown links —
  `[#123](https://github.com/strakers/webform_ranking/issues/123)`, not
  a bare `#123`. GitHub auto-links bare references only inside its own
  UI; anywhere else (drupal.org, an editor, packagist) it's just text.
- At release time: finalize `## [Unreleased]` to
  `## [X.Y.Z] - YYYY-MM-DD`, with a fresh empty `[Unreleased]` heading
  above it, and add that version's link-reference definition at the
  bottom of the file (matching the existing `compare/vX...vY` pattern).

## Pull requests

- Every PR's description includes a final, unchecked **"Manual review
  of the diff"** checklist item. PRs are reviewed and merged by a human
  maintainer — an AI agent doesn't merge or publish a release on its
  own judgment, regardless of how confident the change is.
- Commit locally and let the maintainer review before pushing, unless
  explicitly told to push.
- Run the full test suite (see [TESTING.md](TESTING.md)) before opening
  a PR, and again after any requested changes.

## Code comments and ADRs

- Inline comments stay short: 1-3 sentences, focused on *why* something
  is the way it is, not *what* the code does (well-named code already
  says that). If the reasoning needs more than a few sentences, extract
  it into a `docs/adr/NNNN-title.md` file instead and leave a one-line
  pointer comment (`// See docs/adr/0019-....md.`) in the code.
- New ADRs get the next sequential number and follow
  `.claude/templates/adr-document-format--template.md` exactly: Context
  & Problem Statement, Decision, Alternatives Considered, Consequences &
  Trade-offs, Related Code & Docs.
- When a later change reverses part of an existing ADR's decision,
  don't rewrite history. Add a short "Superseded by ADR-NNNN" note in
  place next to the specific reversed paragraph, and only mark the
  entire ADR's own Status as superseded if *every* decision it
  documents was reversed, not just one of several.
- A deliberate design decision that departs from what might look like
  the "obvious" choice gets a high-visibility inline marker (e.g. `***
  DELIBERATE DESIGN DECISION — FLAG FOR FUTURE RE-REVIEW ***`), not just
  a commit message or an ADR — so a future reader doesn't "fix" it back
  by accident without realizing it was intentional.

## Releases

- A release is a `dev`→`main` PR, titled after the version being
  shipped, with a plain-language QA/BA-readable body describing what's
  in it (see the changelog discipline above for the underlying entries
  to summarize from).
- A draft GitHub Release is created targeting `main`, using the same
  Fixed/Added/`What's Changed`/compare-link structure as prior releases
  — created as a draft and left unpublished until the maintainer
  decides to publish it.
- SemVer bump decisions (patch vs. minor, especially pre-1.0) are the
  maintainer's own case-by-case judgment call, not a mechanical reading
  of the changelog.
