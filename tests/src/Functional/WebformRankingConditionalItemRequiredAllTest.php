<?php

namespace Drupal\Tests\webform_ranking\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression test for GitHub issue #102.
 *
 * A matrix ranking element with a conditionally-visible item, on a form
 * left at the default #required_all, threw an uncaught
 * PluginNotFoundException during submission validation. Reproduces on
 * the item's ordinary default (visible) state — no JS/trigger-toggling
 * needed, so this is a plain HTTP submission, not FunctionalJavascript.
 * See docs/adr/0018-remove-required-optional-states-mirror.md.
 */
#[Group('webform_ranking')]
class WebformRankingConditionalItemRequiredAllTest extends BrowserTestBase {

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
      'id' => 'test_ranking_required_crash',
      'title' => 'Test ranking conditional item crash',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'checkbox',
          '#title' => 'Hide item B',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          // #required_all defaults to TRUE — left unset, same as a real
          // admin leaving the default in place.
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            [
              'value' => 'b',
              'label' => 'Item B',
              'states' => [
                'invisible' => [
                  ':input[name="trigger"]' => ['checked' => TRUE],
                ],
              ],
            ],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * Submitting with the conditional item in its default visible state.
   */
  public function testSubmittingWithConditionalItemVisibleDoesNotFatal(): void {
    $this->drupalGet('/webform/test_ranking_required_crash');
    $this->submitForm([
      'ranking[matrix][a]' => '1',
      'ranking[matrix][b]' => '2',
    ], 'Submit');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString('/confirmation', $this->getSession()->getCurrentUrl());
  }

}
