<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the matrix ranking style's client-side rank-exclusivity behavior.
 *
 * Covers a Known Gap noted in docs/CONTINUATION.md: "Matrix rank-
 * exclusivity JS, aria-live announcements... no coverage." See
 * js/webform_ranking.matrix.js for the behavior under test.
 */
#[Group('webform_ranking')]
class WebformRankingMatrixJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_matrix',
      'title' => 'Test ranking matrix',
      'elements' => Yaml::encode([
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
        ],
        // Dependent element used to confirm live #states reaction to a
        // matrix item's rank selection (a separate Known Gap: this was
        // previously only verified manually via chrome-cli, never with
        // automated coverage).
        'first_choice_message' => [
          '#type' => 'webform_markup',
          '#markup' => 'You selected Item A as your first choice.',
          '#states' => [
            'visible' => [
              ':input[name="ranking[matrix][a]"]' => ['value' => '1'],
            ],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * Selects a matrix radio for the given item and rank.
   */
  protected function selectRank(string $item, string $rank): void {
    $selector = 'input[name="ranking[matrix][' . $item . ']"][value="' . $rank . '"]';
    $this->assertSession()->waitForElement('css', $selector);
    $this->getSession()->getPage()->find('css', $selector)->click();
  }

  /**
   * Reads back whether a specific item's rank radio is checked.
   */
  protected function isChecked(string $item, string $rank): bool {
    $selector = 'input[name="ranking[matrix][' . $item . ']"][value="' . $rank . '"]';
    return (bool) $this->getSession()->getPage()->find('css', $selector)->getAttribute('checked')
      || $this->getSession()->evaluateScript(
        "document.querySelector('" . addslashes($selector) . "').checked"
      );
  }

  /**
   * Reads back whether a specific item's rank radio is disabled.
   */
  protected function isDisabled(string $item, string $rank): bool {
    $selector = 'input[name="ranking[matrix][' . $item . ']"][value="' . $rank . '"]';
    return (bool) $this->getSession()->evaluateScript(
      "document.querySelector('" . addslashes($selector) . "').disabled"
    );
  }

  /**
   * Tests that selecting a rank disables it for every other item, and
   * re-enables it once freed up.
   */
  public function testRankExclusivity(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $this->selectRank('a', '1');

    // Rank 1 is now taken by item A: disabled everywhere else...
    $this->assertTrue($this->isDisabled('b', '1'));
    $this->assertTrue($this->isDisabled('c', '1'));
    // ...but stays enabled for the item that owns it (a checked-but-
    // disabled radio would stop submitting its own value).
    $this->assertFalse($this->isDisabled('a', '1'));
    // Other ranks are unaffected.
    $this->assertFalse($this->isDisabled('b', '2'));
    $this->assertFalse($this->isDisabled('c', '2'));

    // Moving item A to a different rank frees rank 1 back up.
    $this->selectRank('a', '2');
    $this->assertFalse($this->isDisabled('b', '1'));
    $this->assertFalse($this->isDisabled('c', '1'));
    // ...and rank 2 (now owned by A) becomes exclusive instead.
    $this->assertTrue($this->isDisabled('b', '2'));
    $this->assertTrue($this->isDisabled('c', '2'));

    // N/A is deliberately not exclusive — multiple items can be marked
    // N/A at once.
    $this->selectRank('b', 'na');
    $this->selectRank('c', 'na');
    $this->assertTrue($this->isChecked('b', 'na'));
    $this->assertTrue($this->isChecked('c', 'na'));
  }

  /**
   * Tests that the aria-live region announces a selection.
   */
  public function testAriaLiveAnnouncement(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $this->selectRank('a', '1');

    $this->assertNotNull(
      $this->assertSession()->waitForElementVisible('css', '.webform-ranking-matrix__live-region')
    );
    $this->assertSession()->waitForText('Item A ranked 1st.');
  }

  /**
   * Tests that a matrix item's rank can be used as a live #states
   * trigger for another element — no page reload required.
   */
  public function testStatesReactToRankSelection(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $message = $this->assertSession()->elementExists('css', '#edit-first-choice-message');
    $this->assertFalse($message->isVisible());

    $this->selectRank('a', '1');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '#edit-first-choice-message'));

    // Switching item A off rank 1 hides it again live.
    $this->selectRank('a', '2');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($message) {
      return !$message->isVisible();
    }));
  }

}
