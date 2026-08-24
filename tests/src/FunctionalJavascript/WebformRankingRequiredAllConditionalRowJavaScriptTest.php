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
 * Root cause: buildMatrix() baked a native 'required' HTML attribute onto
 * every #required_all row's radios unconditionally, before the same
 * method's own per-item '#states' visibility handling ran. A row hidden by
 * a same-page condition is only hidden client-side (states.js toggling
 * display) — the native attribute stayed regardless, on a now-hidden,
 * unfocusable control. Browsers refuse to submit a form with an unsatisfied
 * required control they can't even focus, and do so silently: no Drupal
 * error, no visible error, just a console warning. Found the same way as
 * #63 — manual browser testing, not the kernel/unit suite (a headless HTTP
 * client never runs the browser's own native constraint validation at
 * all, so this class of bug is invisible to anything but a real browser).
 *
 * Fix: the item's own visible/invisible condition is now mirrored onto
 * 'required'/'optional' in the same '#states' array already governing the
 * row's visibility, so states.js's own state:required handler keeps the
 * native attribute in sync with visibility instead of it being set once
 * and never revisited.
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
   * Item B's radios: 'required' present while visible, absent once hidden.
   */
  public function testNativeRequiredAttributeTracksVisibility(): void {
    $this->drupalGet('/webform/test_ranking_required_states');

    $radio = $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][b]"][value="1"]');
    $this->assertNotNull($radio);
    $this->assertTrue($radio->hasAttribute('required'), 'Item B should start required (visible by default).');

    $this->getSession()->getPage()->checkField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($radio) {
      return !$radio->hasAttribute('required');
    }), 'Item B radios should lose the native required attribute once hidden.');

    $this->getSession()->getPage()->uncheckField('trigger');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($radio) {
      return $radio->hasAttribute('required');
    }), 'Item B radios should regain the native required attribute once visible again.');
  }

  /**
   * Hiding a required row no longer silently blocks submission.
   *
   * Item B is hidden (its own native 'required' would previously have
   * stayed on its unfocusable radios forever), item A is fully ranked so
   * #required_all's own constraint is satisfied for every visible item.
   * A real submit reaching the confirmation page is proof the browser's
   * native constraint validation didn't block it client-side — the old
   * bug left the user stuck on the same form with no feedback at all.
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
