<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests GitHub issue #68: #required_all vs. a same-page conditional row.
 *
 * Item B's own live '#states' visibility is unaffected by #required_all;
 * only the native 'required' attribute's presence changes. Originally
 * fixed by live-mirroring visibility into 'required'/'optional' #states,
 * which itself crashed submission (GitHub #102) — superseded by
 * permanent suppression instead. See
 * docs/adr/0018-remove-required-optional-states-mirror.md.
 */
#[Group('webform_ranking')]
class WebformRankingRequiredAllConditionalRowJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_required_states',
      'title' => 'Test ranking required states',
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
   * Item B's radios never carry a native 'required' attribute (ADR-0018).
   */
  public function testConditionalRowNeverGetsStaticRequiredAttribute(): void {
    $this->drupalGet('/webform/test_ranking_required_states');

    $radio = $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][b]"][value="1"]');
    $this->assertNotNull($radio);
    $this->assertFalse($radio->hasAttribute('required'), 'Item B (has its own #states) should never carry a static required attribute.');

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($radio) {
      return !$radio->isVisible();
    }), 'Item B row should be hidden after checking the trigger.');
    $this->assertFalse($radio->hasAttribute('required'), 'Still absent once hidden.');

    $this->getSession()->getPage()->uncheckField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($radio) {
      return $radio->isVisible();
    }), 'Item B row should be visible again after unchecking the trigger.');
    $this->assertFalse($radio->hasAttribute('required'), 'Still absent once visible again.');
  }

  /**
   * Hiding a conditionally-required row doesn't block submission.
   */
  public function testHiddenRequiredRowDoesNotBlockSubmission(): void {
    $this->drupalGet('/webform/test_ranking_required_states');

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][b]"][value="1"]')->isVisible();
    }), 'Item B row should be hidden after checking the trigger.');

    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][a]"][value="1"]')->click();
    $this->getSession()->getPage()->pressButton('Submit');

    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return str_contains($this->getSession()->getCurrentUrl(), '/confirmation');
    }), 'Submission should reach the confirmation page instead of silently staying on the form.');
  }

}
