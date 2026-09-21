<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the required-field asterisk toggles live in a real browser.
 *
 * '#required' combined with element-level '#states[visible]' gets
 * rewritten into '#states[required]' by Webform core, toggled live via
 * states.js — which needs WebformRanking::preRenderWebformRanking()'s
 * '#label_for' fix to find the right label to toggle.
 *
 * @see https://github.com/strakers/webform_ranking/issues/151
 * @see doc://docs/adr/0009-prerender-attributes-states-and-error-display.md
 */
#[Group('webform_ranking')]
class WebformRankingElementLevelRequiredToggleJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_required_toggle',
      'title' => 'Test ranking element-level required toggle',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'checkbox',
          '#title' => 'Show ranking?',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#required' => TRUE,
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
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
   * The label's required asterisk appears/disappears live with the trigger.
   */
  public function testRequiredAsteriskTogglesWithTrigger(): void {
    $this->drupalGet('/webform/test_ranking_required_toggle');

    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking--wrapper"]');
    $label = $this->assertSession()->elementExists('css', 'label[for="edit-ranking--wrapper"]');
    $this->assertFalse($label->hasClass('js-form-required'), 'Label should not be marked required while hidden.');

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return $wrapper->isVisible();
    }), 'Ranking element should become visible once the trigger is checked.');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($label) {
      return $label->hasClass('js-form-required');
    }), 'Label should gain the required asterisk once the element becomes required.');

    $this->getSession()->getPage()->uncheckField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($label) {
      return !$label->hasClass('js-form-required');
    }), 'Label should lose the required asterisk once the trigger is unchecked again.');
  }

}
