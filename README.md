# Webform Ranking

A [Webform](https://www.drupal.org/project/webform) element that lets
respondents rank a set of admin-defined items (1st, 2nd, 3rd...), with an
optional N/A opt-out, two display styles, per-item conditional visibility,
and full server- and client-side validation.

## Features

- **Two display styles**
  - **Matrix** — one row per item, one radio button per rank column.
  - **Drag and drop** — reorder a list with pointer-based drag, move
    up/down buttons, and arrow-key shortcuts (mouse, touch, and pen all
    supported).
- **N/A opt-out** — respondents can mark an item as not applicable
  instead of ranking it.
- **Per-item conditional visibility** — show or hide individual items
  based on other form values, using a `#states`-style YAML condition.
- **`#states` integration** — in both display styles, each item's rank
  can be used as a trigger for other elements' conditions (e.g. "show
  this field only if Pizza is ranked 1st").
- **Randomizable item order** — reduce position bias in survey-style
  rankings.
- **Custom rank labels** — override the default "1st, 2nd, 3rd..."
  labels.
- **Results/CSV formatting** — submission views and exports list each
  item with its resolved rank (or N/A / not ranked).

## Requirements

- Drupal ^10.1 || ^11
- [Webform](https://www.drupal.org/project/webform) ^6.2

## Installation

Install as you would normally install a contributed Drupal module:

```
composer require drupal/webform_ranking
drush en webform_ranking
```

## Usage

1. Edit a webform and add a **Ranking** element.
2. Under **Ranking settings**, configure:
   - **Items to rank** — one row per item (a storage `Value` and a
     display `Label`). Optionally check **Use conditional visibility
     for this item** to enter a `#states` condition (YAML) that
     controls whether the item appears at all.
   - **Display style** — Matrix or Drag and drop.
   - **Allow abstaining (N/A)** and its label.
   - **Randomize item order per page load**.
   - **Rank position label overrides** — optional, one label per rank
     position.
   - **Require every visible item to be ranked or marked N/A**.
3. Save. Respondents rank items in the chosen style; validation
   enforces a gapless, non-duplicate ranking among currently-visible
   items.

### Using an item's rank as a condition trigger

In both matrix and drag-and-drop mode, each item exposes its own
selector (e.g. "Ranking: Pizza (rank)") in the `#states`
condition-builder UI on *other* elements, so you can show/hide a
field based on whether a specific item was ranked 1st, 2nd, etc., or
marked N/A. This reacts live in the browser as the respondent
re-ranks items — no page reload needed.

## Known limitations

- Per-item conditional visibility is configured as raw YAML, not
  Webform's visual conditions builder.
- The matrix style's rank columns are static and don't renumber when
  items are conditionally hidden (drag-and-drop's position indicator
  does).
- Submission views/exports support the `value` and `raw` item formats;
  a dedicated `table` format is not implemented.

## Development

Architecture notes, design-decision rationale, and known gaps are
tracked in [docs/CONTINUATION.md](docs/CONTINUATION.md).

Run the test suite with:

```
ddev phpunit --group webform_ranking
```

## Maintainers

- [Steven Straker](https://www.drupal.org/u/strakez)

## License

GPL-2.0-or-later
