# Webform Ranking

The Webform Ranking module adds a flexible ranking element to the Drupal [Webform](https://www.drupal.org/project/webform) ecosystem. It allows form creators to build fields where respondents rank a predefined set of items (e.g., 1st, 2nd, 3rd) using accessible, user-friendly interfaces.

## Features

- **Two Interactive Display Styles:** Form authors can choose between a **Drag and Drop** list—which supports pointer drag, touch gestures, arrow keys, and dedicated move buttons—or a traditional **Matrix Grid** featuring one row per item and one radio button per column.
- **Abstain / N/A Option:** Form respondents can mark specific items as "Not Applicable" instead of assigning them a numeric rank.
- **Built-in Validation:** The element automatically enforces clean submissions by ensuring user rankings contain no duplicate ranks and leave no numerical gaps.
- **Conditional Visibility:** Items within the ranking list can be hidden or revealed based on other form responses. Conversely, individual item ranks can serve as triggers to show or hide completely separate elements on the form.
- **Shuffled Item Order:** Survey creators can randomize the display order of items on every page load to minimize positioning bias.
- **Custom Clean Labels:** Default rank positions (1st, 2nd, 3rd...) can be overridden with custom text. Submission results map cleanly to webform views and CSV data exports.

## Requirements

- Drupal ^10.1 || ^11
- Webform ^6.2

## Installation

Install the module using Composer and enable it via Drush or the Drupal administrative interface:

```bash
composer require drupal/webform_ranking
drush en webform_ranking
```

## Configuration and Usage

Add a **Ranking** element to your webform to begin. Under the element settings, define your **Items to rank** by entering a unique storage value and a customer-facing display label for each choice. From there, select your preferred display style (Matrix or Drag and Drop), determine whether to allow N/A abstentions, and optionally apply randomization or label overrides.

### Setting Up Conditional Logic

**To hide or reveal specific ranking choices:** Click the **Conditions** button next to any choice in your item configuration list. This opens a dialog box where you can supply conditional logic, either blank or pre-filled if one is already set.

**To trigger other form fields based on a rank:** Use the standard Webform conditional wizard on _other_ form elements. The ranking element exposes unique, per-item selectors (such as `Ranking: Pizza`) directly in the condition-builder UI. You can easily construct rules like _Show [Field B] if [Ranking: Pizza] is [1]_. The visibility changes update live in the browser as the respondent interacts with the field.

## Key Design Considerations & Limitations

- **Conditional Logic Format:** Setting up visibility rules for individual choices within the ranking element requires entering raw YAML syntax inside the configuration dialog, rather than using Webform's visual rule builder.
- **Strict Matrix Order:** The Matrix display style requires respondents to assign ranks sequentially starting from the top slot. Skipping a leading rank (e.g., selecting 2nd and 3rd but leaving 1st unassigned) triggers a validation error on submission. The error message is customizable via the "Sequential ranks error message" setting.

## Development

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for local environment
setup and project structure, [docs/TESTING.md](docs/TESTING.md) for
running the test suite, and [docs/CONTINUATION.md](docs/CONTINUATION.md)
for architecture notes, design-decision rationale, and known gaps.

## AI Usage and Transparency

This module was developed with the assistance of an [AI coding agent](https://claude.com/product/claude-code), which was utilized for documentation generation, automated test writing, and code reviews. All AI contributions were executed in a manual, guided mode under strict developer oversight. Every line of code, test case, and architectural change suggested by the AI was carefully reviewed, verified, and manually approved to ensure the module meets security, stability, and high-quality coding standards.

## Maintainers

- [Steven Straker (strakez)](https://www.drupal.org/u/strakez)

## License

GPL-2.0-or-later
