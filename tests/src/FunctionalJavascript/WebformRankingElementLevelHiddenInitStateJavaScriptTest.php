<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests item rows/rank columns once an initially-hidden element is shown.
 *
 * Regression coverage for GitHub issue #123: a ranking element whose own
 * *element-level* '#states' condition hides it on initial page load (the
 * standard Webform "Conditional logic" tab — a cross-page wizard trigger
 * in the original report, or a same-page trigger such as another ranking
 * element's own rank value, in a later report folded into the same
 * issue) rendered with all item rows permanently missing and rank
 * columns collapsed to just "1st", even once the condition was satisfied.
 *
 * Root cause: entirely client-side, in js/webform_ranking.matrix.js and
 * js/webform_ranking.dragdrop.js — not the server-rendered markup, which
 * this investigation confirmed is always correct regardless of whether
 * the element starts hidden. `initMatrix()`'s (and dragdrop's
 * `isCurrentlyVisible()`'s) `offsetParent === null` check, intended only
 * to recover a *per-item* '#states' result states.js already applied
 * before this behavior's own listener existed (see
 * docs/adr/0012-matrix-conditional-item-visibility-sync.md and
 * docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-
 * state.md), is also `null` whenever any ancestor — including this
 * element's own top-level '#states' — is hidden. Every row/item was
 * wrongly seeded as individually hidden the moment the *whole* element
 * started hidden, permanently (this seeding runs once; nothing re-syncs
 * it once the element's own visibility later resolves true, since the
 * only re-sync path is a per-item 'state:visible' listener). See both
 * ADRs' 2026-09-03 corrections and the new docs/adr/0022-*.md for the
 * fix (only trust `offsetParent` for a row/item that actually carries
 * `data-drupal-states` of its own).
 */
#[Group('webform_ranking')]
class WebformRankingElementLevelHiddenInitStateJavaScriptTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'webform_ranking'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_el_hidden_init',
      'title' => 'Test ranking element-level hidden initial state',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'checkbox',
          '#title' => 'Show ranking?',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
          '#states' => [
            'visible' => [
              ':input[name="trigger"]' => ['checked' => TRUE],
            ],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * All rows and rank columns appear once an initially-hidden matrix
   * element is revealed via its own element-level condition.
   */
  public function testMatrixElementLevelHiddenOnLoadShowsAllRowsAndColumns(): void {
    $this->drupalGet('/webform/test_ranking_el_hidden_init');

    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking--wrapper"]');
    $this->assertFalse($wrapper->isVisible(), 'Ranking element should start hidden (trigger unchecked).');

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return $wrapper->isVisible();
    }), 'Ranking element should become visible once the trigger is checked.');

    // The exact reported symptom: rows/columns wrongly stuck `hidden`
    // even once the wrapper itself is visible again.
    foreach (['a', 'b', 'c'] as $item) {
      $row = $this->assertSession()->elementExists('css', "tr[data-drupal-selector='edit-ranking-matrix-{$item}']");
      $this->assertFalse($row->hasAttribute('hidden'), "Item $item's row should not be hidden.");
      $this->assertTrue($row->isVisible(), "Item $item's row should be visible.");
    }

    $header_cells = $this->getSession()->evaluateScript(
      "Array.from(document.querySelectorAll('table.webform-ranking-matrix thead th')).map(function (el) { return el.hasAttribute('hidden'); })"
    );
    $this->assertSame([FALSE, FALSE, FALSE, FALSE, FALSE], $header_cells, 'All 5 header cells (blank, 1st, 2nd, 3rd, N/A) should be visible, none collapsed.');
  }

  /**
   * The real-world reported trigger shape: a second ranking element's
   * visibility depends on a first ranking element's own per-item rank
   * value, both on the same page (no cross-page trigger involved at
   * all) — confirms this isn't specific to cross-page conditions.
   */
  public function testSecondRankingDependingOnFirstRankingShowsAllRows(): void {
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_depends_on_ranking',
      'title' => 'Test ranking depending on another ranking',
      'elements' => Yaml::encode([
        'ranking1' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking 1',
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'ab', 'label' => 'Academic Board'],
            ['value' => 'bb', 'label' => 'Business Board'],
            ['value' => 'uab', 'label' => 'University Affairs Board'],
          ],
        ],
        'ranking2' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking 2',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          '#required' => TRUE,
          '#items' => [
            ['value' => 'ac', 'label' => 'Agenda Committee'],
            ['value' => 'capp', 'label' => 'Committee on Academic Policy & Programs'],
            ['value' => 'pb', 'label' => 'Planning & Budget Committee'],
          ],
          '#states' => [
            'visible' => [
              ':input[name="ranking1[matrix][ab]"]' => ['value' => '1'],
            ],
          ],
        ],
      ]),
    ])->save();

    $this->drupalGet('/webform/test_ranking_depends_on_ranking');
    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking2--wrapper"]');
    $this->assertFalse($wrapper->isVisible(), 'Ranking 2 should start hidden.');

    $this->getSession()->getPage()->find('css', 'input[name="ranking1[matrix][ab]"][value="1"]')->click();
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return $wrapper->isVisible();
    }), 'Ranking 2 should become visible once Academic Board is ranked 1st.');

    foreach (['ac', 'capp', 'pb'] as $item) {
      $row = $this->assertSession()->elementExists('css', "tr[data-drupal-selector='edit-ranking2-matrix-{$item}']");
      $this->assertFalse($row->hasAttribute('hidden'), "Item $item's row should not be hidden.");
    }
    $header_cells = $this->getSession()->evaluateScript(
      "Array.from(document.querySelectorAll('[data-drupal-selector=\"edit-ranking2-matrix\"] thead th')).map(function (el) { return el.hasAttribute('hidden'); })"
    );
    $this->assertSame([FALSE, FALSE, FALSE, FALSE, FALSE], $header_cells, 'Ranking 2\'s header should show all rank columns, not collapsed to 1st only.');
  }

  /**
   * Drag/drop style: an item is silently excluded from the actual
   * submitted order/na values (not just misrendered) if it's wrongly
   * seeded as hidden while the whole element starts hidden — the
   * higher-impact dragdrop-side manifestation of the same bug (see
   * docs/adr/0020's 2026-09-03 correction).
   */
  public function testDragdropElementLevelHiddenOnLoadIncludesAllItemsOnceRevealed(): void {
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_dragdrop_el_hidden',
      'title' => 'Test ranking dragdrop element-level hidden initial state',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'checkbox',
          '#title' => 'Show ranking?',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'dragdrop',
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
          '#states' => [
            'visible' => [
              ':input[name="trigger"]' => ['checked' => TRUE],
            ],
          ],
        ],
      ]),
    ])->save();

    $this->drupalGet('/webform/test_ranking_dragdrop_el_hidden');
    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking--wrapper"]');
    $this->assertFalse($wrapper->isVisible());

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return $wrapper->isVisible();
    }));

    // The actual, authoritative submitted value — not just what's
    // visually rendered. Before the fix, every item was wrongly
    // excluded here regardless of the element becoming visible.
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      $value = $this->getSession()->evaluateScript(
        "document.querySelector('input[name=\"ranking[dragdrop][order]\"]').value"
      );
      return $value === 'a,b,c';
    }), 'Expected all 3 items in the submitted order once revealed. Got: ' . $this->getSession()->evaluateScript(
      "document.querySelector('input[name=\"ranking[dragdrop][order]\"]').value"
    ));
  }

}
