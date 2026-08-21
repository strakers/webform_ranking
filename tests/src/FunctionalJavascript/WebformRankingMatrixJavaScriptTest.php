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
        'trigger' => [
          '#type' => 'textfield',
          '#title' => 'Trigger',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            [
              'value' => 'c',
              'label' => 'Item C',
              // Conditionally-visible item, empty by default so it
              // doesn't affect the other tests in this class (all of
              // which use item 'c' freely, assuming it starts
              // visible).
              'states' => [
                'invisible' => [
                  ':input[name="trigger"]' => ['filled' => TRUE],
                ],
              ],
            ],
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
   * Reads back whether a specific item's rank radio is marked "taken".
   *
   * Purely a visual hint (see webform_ranking.matrix.js) — the input
   * itself stays enabled/clickable, since clicking a "taken" radio is
   * exactly what triggers reassignment ("stealing").
   */
  protected function isTaken(string $item, string $rank): bool {
    $selector = 'input[name="ranking[matrix][' . $item . ']"][value="' . $rank . '"]';
    return (bool) $this->getSession()->evaluateScript(
      "document.querySelector('" . addslashes($selector) . "').classList.contains('webform-ranking-matrix__radio--taken')"
    );
  }

  /**
   * Reads back whether a specific item's rank radio is genuinely disabled.
   */
  protected function isDisabled(string $item, string $rank): bool {
    $selector = 'input[name="ranking[matrix][' . $item . ']"][value="' . $rank . '"]';
    return (bool) $this->getSession()->evaluateScript(
      "document.querySelector('" . addslashes($selector) . "').disabled"
    );
  }

  /**
   * Tests that selecting a rank marks it "taken" for every other item.
   *
   * Also tests that the mark clears once freed up, and that the radio
   * itself never becomes genuinely disabled (see
   * testSelectingTakenRankStealsItFromPreviousHolder() for why: a
   * disabled radio couldn't be clicked to steal its rank back).
   */
  public function testRankExclusivity(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $this->selectRank('a', '1');

    // Rank 1 is now taken by item A: marked taken everywhere else...
    $this->assertTrue($this->isTaken('b', '1'));
    $this->assertTrue($this->isTaken('c', '1'));
    // ...but not for the item that owns it.
    $this->assertFalse($this->isTaken('a', '1'));
    // Other ranks are unaffected.
    $this->assertFalse($this->isTaken('b', '2'));
    $this->assertFalse($this->isTaken('c', '2'));
    // Never genuinely disabled, regardless of "taken" state.
    $this->assertFalse($this->isDisabled('a', '1'));
    $this->assertFalse($this->isDisabled('b', '1'));

    // Moving item A to a different rank frees rank 1 back up.
    $this->selectRank('a', '2');
    $this->assertFalse($this->isTaken('b', '1'));
    $this->assertFalse($this->isTaken('c', '1'));
    // ...and rank 2 (now owned by A) becomes exclusive instead.
    $this->assertTrue($this->isTaken('b', '2'));
    $this->assertTrue($this->isTaken('c', '2'));

    // N/A is deliberately not exclusive — multiple items can be marked
    // N/A at once.
    $this->selectRank('b', 'na');
    $this->selectRank('c', 'na');
    $this->assertTrue($this->isChecked('b', 'na'));
    $this->assertTrue($this->isChecked('c', 'na'));
  }

  /**
   * Tests that clicking an already-taken rank reassigns it.
   *
   * The real bug fixed here: with every item fully ranked, "swapping"
   * two items' positions needs two simultaneous changes, and each
   * individual target cell is always taken by exactly one other row
   * at the moment of the click. An earlier version of this behavior
   * genuinely *disabled* a taken cell's radio — meaning neither half
   * of a swap could ever be clicked at all once every item held a
   * distinct rank, permanently locking a fully-ranked matrix out of
   * ever being rearranged again (without an N/A escape hatch, and not
   * at all if #allow_na was off). Reassignment on click removes that
   * lockout: clicking a taken cell now steals the rank away from
   * whichever item currently holds it, unchecking that item's radio
   * rather than refusing the click.
   */
  public function testSelectingTakenRankStealsItFromPreviousHolder(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $this->selectRank('a', '1');
    $this->selectRank('b', '2');
    $this->selectRank('c', '3');

    // Every rank is now taken by a distinct item — the exact
    // fully-ranked state that used to be a permanent dead end.
    $this->assertTrue($this->isChecked('a', '1'));
    $this->assertTrue($this->isChecked('b', '2'));
    $this->assertTrue($this->isChecked('c', '3'));

    // Steal rank 1 from item A by selecting it for item C.
    $this->selectRank('c', '1');

    $this->assertTrue($this->isChecked('c', '1'));
    // Item A's previous selection was reassigned away, not left
    // duplicated (which the server would reject anyway).
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->isChecked('a', '1');
    }));
    // Item A holds no rank at all now — the radio group has no
    // checked member, distinct from "still checked at the old value".
    $this->assertFalse($this->isChecked('a', '2'));
    $this->assertFalse($this->isChecked('a', '3'));
    // Uninvolved item B is untouched.
    $this->assertTrue($this->isChecked('b', '2'));
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
   * Tests that the live region uses core's real '.visually-hidden'.
   *
   * Real bug: webform_ranking.matrix.css used to redefine
   * '.visually-hidden' itself, with a weaker rule (no '!important', a
   * zero-size clip rect instead of the 1px rect assistive tech
   * expects) that shadowed core's own, more complete version whenever
   * both happened to load — and this element's library never
   * guaranteed core's version was loaded at all otherwise. Fixed by
   * depending on 'system/base' instead of redefining the class. This
   * asserts the browser's *computed* clip value matches core's exact
   * rule (`rect(1px, 1px, 1px, 1px)`), proving core's CSS — not a
   * local shadow of it — is what's actually in effect.
   */
  public function testLiveRegionUsesCoreVisuallyHiddenStyle(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $clip = $this->getSession()->evaluateScript(
      "getComputedStyle(document.querySelector('.webform-ranking-matrix__live-region')).clip"
    );

    $this->assertSame('rect(1px, 1px, 1px, 1px)', $clip);
  }

  /**
   * Tests that a matrix item's rank can be used as a live #states trigger.
   *
   * No page reload required.
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

  /**
   * Tests that a conditionally-hidden item's label hides with its radios.
   *
   * Real bug: the label cell used to be a bare '#markup' array, which
   * has no #attributes-bearing wrapper for Renderer::doRender() to
   * attach 'data-drupal-states' to — so states.js had nothing to bind
   * to for the label, and it stayed visible even once its own row's
   * radios (a real '#type' => 'radio' input, unaffected by this)
   * correctly hid. A user would see an orphaned item name floating
   * above a row with no rank options. Fixed by wrapping the label in
   * '#type' => 'container', which (unlike '#type' => 'html_tag')
   * reads #attributes at theme-render time, after #states processing.
   */
  public function testConditionalItemLabelHidesTogetherWithRadios(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    // Item C is always the third (and only conditional) row.
    $labels = $this->getSession()->getPage()->findAll('css', '.webform-ranking-matrix__label');
    $this->assertCount(3, $labels);
    $label = end($labels);
    $this->assertSame('Item C', trim($label->getText()));
    $this->assertTrue($label->isVisible());

    $radio = $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="1"]');
    $this->assertTrue($radio->isVisible());

    $this->getSession()->getPage()->fillField('trigger', 'anything');

    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($label) {
      return !$label->isVisible();
    }));
    $this->assertFalse($radio->isVisible());
  }

  /**
   * Tests that hiding an item frees the rank it held for other items.
   *
   * Real bug: markTakenRanks() (then named applyExclusivity()) in
   * webform_ranking.matrix.js only recomputed on a radio 'change'
   * event. If the item currently holding a rank became conditionally
   * hidden (its own #states condition, independent of rank selection),
   * nothing ever told the JS to recompute — the rank stayed marked
   * taken for every other item indefinitely, even though
   * validateWebformRanking() server-side already drops a hidden
   * item's selection before checking rank uniqueness, so the block
   * was purely a stale client-side artifact with no server-side
   * basis. Fixed by also listening for the 'state:visible' event
   * states.js fires on each row's radios.
   */
  public function testHidingRankedItemFreesItsRankForOtherItems(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $this->selectRank('c', '1');
    $this->assertTrue($this->isTaken('a', '1'));
    $this->assertTrue($this->isTaken('b', '1'));

    // Hiding item C (which holds rank 1) must free rank 1 back up for
    // the still-visible items.
    $this->getSession()->getPage()->fillField('trigger', 'anything');

    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->isTaken('a', '1');
    }));
    $this->assertFalse($this->isTaken('b', '1'));

    // Revealing item C again re-imposes the mark, since its selection
    // was never cleared, only excluded while hidden.
    $this->getSession()->getPage()->fillField('trigger', '');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->isTaken('a', '1');
    }));
    $this->assertTrue($this->isTaken('b', '1'));
  }

}
