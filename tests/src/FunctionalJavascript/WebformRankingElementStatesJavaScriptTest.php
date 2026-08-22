<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests element-level '#states' (show/hide the whole ranking field).
 *
 * Regression coverage for a real production bug: unlike per-item
 * conditional visibility (buildMatrix()/buildDragDrop() applying an
 * item's own '#states' to its row — see WebformRankingMatrixJavaScriptTest/
 * WebformRankingDragdropJavaScriptTest), hiding or showing the *entire*
 * ranking element via its own admin "Conditional logic" tab silently never
 * worked at all client-side.
 *
 * Root cause: Renderer::doRender() calls
 * \Drupal\Core\Form\FormHelper::processStates() for any element with
 * '#states' set, which writes the 'data-drupal-states' attribute
 * states.js actually reads to '#attributes' — but this element's
 * '#theme_wrappers' => ['form_element'] template never renders
 * '#attributes' anywhere (see WebformRanking::preRenderWebformRanking()'s
 * docblock, the same underlying gap #47/#48 hit). states.js had no
 * 'data-drupal-states' attribute anywhere in the DOM to bind to, so the
 * field was frozen at whatever Webform's own no-JS-fallback
 * 'js-webform-states-hidden' class computed server-side on page load,
 * never reacting to the trigger changing.
 */
#[Group('webform_ranking')]
class WebformRankingElementStatesJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_element_states',
      'title' => 'Test ranking element states',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'checkbox',
          '#title' => 'Show ranking?',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
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
   * The whole element hides/shows in a real browser as the trigger changes.
   */
  public function testElementLevelStatesToggleVisibility(): void {
    $this->drupalGet('/webform/test_ranking_element_states');

    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking--wrapper"]');
    $this->assertStringContainsString('data-drupal-states', $wrapper->getOuterHtml());
    $this->assertFalse($wrapper->isVisible(), 'Ranking element should start hidden (trigger unchecked).');

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return $wrapper->isVisible();
    }), 'Ranking element should become visible once the trigger is checked.');

    $this->getSession()->getPage()->uncheckField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return !$wrapper->isVisible();
    }), 'Ranking element should hide again once the trigger is unchecked.');
  }

}
