<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests cross-page per-item '#states' in a real browser — GitHub issue #61.
 *
 * WebformRankingCrossPageItemStatesTest (Kernel) covers the underlying
 * server-side resolution directly; this drives a real wizard-page
 * navigation end to end to confirm it actually shows up correctly in
 * rendered markup, matching the originally-reported production scenario
 * (a matrix item's own condition referencing a trigger on an earlier
 * wizard page).
 */
#[Group('webform_ranking')]
class WebformRankingCrossPageItemStatesJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_crosspage_item',
      'title' => 'Test ranking cross-page item states',
      'elements' => Yaml::encode([
        'pg_one' => [
          '#type' => 'webform_wizard_page',
          '#title' => 'Page One',
          'constituency' => [
            '#type' => 'select',
            '#title' => 'Constituency',
            '#options' => ['alumni' => 'Alumni', 'student' => 'Student'],
          ],
        ],
        'pg_two' => [
          '#type' => 'webform_wizard_page',
          '#title' => 'Page Two',
          'ranking' => [
            '#type' => 'webform_ranking',
            '#title' => 'Ranking',
            '#ranking_style' => 'matrix',
            '#items' => [
              ['value' => 'ab', 'label' => 'Item AB', 'states' => []],
              [
                'value' => 'uab',
                'label' => 'Item UAB',
                'states' => [
                  'invisible' => [
                    ':input[name="constituency"]' => ['value' => 'alumni'],
                  ],
                ],
              ],
            ],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * A cross-page invisible-when condition excludes the item entirely.
   */
  public function testCrossPageItemExcludedWhenConditionMet(): void {
    $this->drupalGet('/webform/test_ranking_crosspage_item');
    $this->getSession()->getPage()->selectFieldOption('constituency', 'alumni');
    $this->getSession()->getPage()->pressButton('Next');
    $this->assertSession()->waitForElementVisible('css', 'table.webform-ranking-matrix');

    $this->assertSession()->elementNotExists('css', '[data-drupal-selector="edit-ranking-matrix-uab-label"]');
    $this->assertSession()->elementNotExists('css', 'input[name="ranking[matrix][uab]"]');
    // The unconditional item is unaffected.
    $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking-matrix-ab-label"]');
  }

  /**
   * A cross-page invisible-when condition that isn't met renders normally.
   */
  public function testCrossPageItemVisibleWhenConditionNotMet(): void {
    $this->drupalGet('/webform/test_ranking_crosspage_item');
    $this->getSession()->getPage()->selectFieldOption('constituency', 'student');
    $this->getSession()->getPage()->pressButton('Next');
    $this->assertSession()->waitForElementVisible('css', 'table.webform-ranking-matrix');

    $uab_label = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking-matrix-uab-label"]');
    $this->assertTrue($uab_label->isVisible());
    $this->assertSession()->elementExists('css', 'input[name="ranking[matrix][uab]"]');
  }

}
