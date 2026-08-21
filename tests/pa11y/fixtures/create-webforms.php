<?php

/**
 * @file
 * Creates (or updates) the demo webforms pa11y-ci audits against.
 *
 * Run via `ddev pa11y-fixtures` before `ddev pa11y-ci local` (or
 * `ddev pa11y-ci-report local`) — pa11y-ci needs stable, real routes to
 * point at, unlike tests/src/FunctionalJavascript/'s own webform
 * fixtures, which are created fresh and torn down within each isolated
 * test run. Not shipped as module config/install: these are dev-only
 * fixtures for accessibility auditing, not something every site
 * installing this module should get for free.
 *
 * Idempotent — safe to re-run; updates the two webforms in place if
 * they already exist rather than erroring or duplicating them.
 *
 * @see GitHub issue #9
 */

use Drupal\Component\Serialization\Yaml;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;

// Item labels/values mirror tests/src/FunctionalJavascript/'s own
// matrix/dragdrop fixtures, so anyone already familiar with those
// recognizes these too. '#allow_na' and the '#required_all' default
// (TRUE) are both left on so pa11y-ci also covers the N/A control and
// the required-field markup added for #46/#47/#48, not just the bare
// minimum element structure.
$forms = [
  'pa11y_test_matrix' => [
    'title' => 'Pa11y test: matrix ranking',
    'ranking_style' => 'matrix',
  ],
  'pa11y_test_dragdrop' => [
    'title' => 'Pa11y test: drag/drop ranking',
    'ranking_style' => 'dragdrop',
  ],
];

foreach ($forms as $id => $form) {
  $elements = [
    'ranking' => [
      '#type' => 'webform_ranking',
      '#title' => 'Ranking',
      '#ranking_style' => $form['ranking_style'],
      '#allow_na' => TRUE,
      '#items' => [
        ['value' => 'a', 'label' => 'Item A'],
        ['value' => 'b', 'label' => 'Item B'],
        ['value' => 'c', 'label' => 'Item C'],
      ],
    ],
  ];

  $values = [
    'langcode' => 'en',
    'status' => WebformInterface::STATUS_OPEN,
    'id' => $id,
    'title' => $form['title'],
    'elements' => Yaml::encode($elements),
  ];

  $webform = Webform::load($id);
  if ($webform) {
    foreach ($values as $key => $value) {
      $webform->set($key, $value);
    }
    $webform->save();
    echo "Updated $id\n";
  }
  else {
    Webform::create($values)->save();
    echo "Created $id\n";
  }
}
