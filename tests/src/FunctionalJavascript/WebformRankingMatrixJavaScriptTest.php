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
   * Tests that stealing a rank live-updates #states watching the loser.
   *
   * Real bug: the "success scenario" above (moving item A itself off
   * rank 1) worked because that's a real user click on item A's own
   * radio, which fires a native 'change' event states.js observes.
   * But when item B *steals* rank 1 from item A instead (see
   * testSelectingTakenRankStealsItFromPreviousHolder() in this file),
   * the matrix.js reassignment logic only unchecked item A's radio via
   * a plain property assignment — no event, so states.js's cached
   * evaluation of "is item A ranked 1st" never re-ran, and any #states
   * condition watching specifically for that (like this test's
   * dependent message) stayed stuck visible even though item A no
   * longer holds rank 1 at all.
   */
  public function testStatesReactWhenRankIsStolenByAnotherItem(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $message = $this->assertSession()->elementExists('css', '#edit-first-choice-message');
    $this->assertFalse($message->isVisible());

    $this->selectRank('a', '1');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '#edit-first-choice-message'));

    // Item B steals rank 1 from item A — a different row's click, not
    // item A's own.
    $this->selectRank('b', '1');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($message) {
      return !$message->isVisible();
    }), 'Dependent message stayed visible after item A lost rank 1 to item B.');
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

    // GitHub #104: item C's own selection is cleared on hide (not just
    // excluded while hidden), so revealing it again does not re-impose
    // the mark — a stale selection could otherwise silently collide
    // with whatever a different item takes in the meantime. See
    // docs/adr/0019-matrix-duplicate-rank-detection.md.
    $this->getSession()->getPage()->fillField('trigger', '');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="1"]')->isVisible();
    }));
    $this->assertFalse($this->isChecked('c', '1'));
    $this->assertFalse($this->isTaken('a', '1'));
    $this->assertFalse($this->isTaken('b', '1'));
  }

  /**
   * Tests that a hidden item's rank can be reused without ever colliding.
   *
   * The exact interactive scenario from GitHub #104: item C ranked,
   * hidden, its rank taken by another item, then C revealed again — the
   * revealed item must not show the same rank as the item that took it.
   */
  public function testHiddenItemRankDoesNotCollideOnReappearance(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $this->selectRank('c', '1');
    $this->getSession()->getPage()->fillField('trigger', 'anything');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->isTaken('a', '1');
    }));

    // A different, still-visible item takes the now-free rank.
    $this->selectRank('a', '1');
    $this->assertTrue($this->isChecked('a', '1'));

    // Revealing item C again must not show it as also ranked 1st.
    $this->getSession()->getPage()->fillField('trigger', '');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="1"]')->isVisible();
    }));
    $this->assertFalse($this->isChecked('c', '1'));
    $this->assertTrue($this->isChecked('a', '1'));
  }

  /**
   * Tests that hiding a conditional item hides its whole <tr>, not just
   * its label/radio cells (GitHub issue #59).
   *
   * Real bug: buildMatrix() applies a conditional item's '#states' to
   * each cell's *content* individually — the label div, each radio —
   * never to the row itself (Table::preRenderTable()'s row-attributes-
   * to-<tr> merge happens before '#states' processing can add
   * 'data-drupal-states', the same timing constraint documented for why
   * the label needed its own 'container' wrapper). A hidden item left
   * an empty-looking <tr>/<td> shell in the table. Fixed client-side in
   * webform_ranking.matrix.js: toggleRow() sets the native `hidden`
   * attribute on the row itself, driven by the same 'state:visible'
   * event already listened to for rank-exclusivity (see
   * testHidingRankedItemFreesItsRankForOtherItems()).
   */
  public function testConditionalItemRowIsFullyHidden(): void {
    $this->drupalGet('/webform/test_ranking_matrix');

    $radio = $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="1"]');
    $row = $radio->find('xpath', './ancestor::tr[1]');
    $this->assertNotNull($row);
    $this->assertTrue($row->isVisible());

    $this->getSession()->getPage()->fillField('trigger', 'anything');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($row) {
      return !$row->isVisible();
    }));

    // Revealing it again un-hides the row, not just its cells.
    $this->getSession()->getPage()->fillField('trigger', '');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($row) {
      return $row->isVisible();
    }));
  }

  /**
   * Tests that a row already hidden on initial page load stays hidden.
   *
   * Distinct from testConditionalItemRowIsFullyHidden(): that test only
   * covers a *live* transition after a 'state:visible' event fires
   * while webform_ranking.matrix.js is already listening.
   * webform_ranking.matrix.js seeds each row's initial visibility from
   * `offsetParent === null` specifically because states.js's own
   * behavior evaluates and fires that event during page-load attach,
   * before this element's own behavior has a chance to listen for it —
   * an item hidden from the very first render would otherwise never
   * get its row hidden at all. Uses its own webform (not the shared
   * fixture) so the trigger can start pre-filled.
   */
  public function testConditionalItemRowHiddenOnInitialLoad(): void {
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_matrix_initial_hide',
      'title' => 'Test ranking matrix initial hide',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'textfield',
          '#title' => 'Trigger',
          '#default_value' => 'anything',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            [
              'value' => 'c',
              'label' => 'Item C',
              'states' => [
                'invisible' => [
                  ':input[name="trigger"]' => ['filled' => TRUE],
                ],
              ],
            ],
          ],
        ],
      ]),
    ])->save();

    $this->drupalGet('/webform/test_ranking_matrix_initial_hide');
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"]');

    $radio = $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="1"]');
    $row = $radio->find('xpath', './ancestor::tr[1]');
    $this->assertNotNull($row);
    $this->assertFalse($row->isVisible());
  }

  /**
   * Reads back whether a given rank's header cell is currently hidden.
   *
   * $rank is '1'-based numeric rank, or 'na' for the N/A column.
   */
  protected function isRankColumnHidden(string $rank): bool {
    $header = $this->getSession()->getPage()->find(
      'xpath',
      '//table[contains(@class, "webform-ranking-matrix")]/thead/tr/th[' . ($rank === 'na' ? 'last()' : ((int) $rank + 1)) . ']'
    );
    return $header && !$header->isVisible();
  }

  /**
   * Tests that surplus rank columns hide once fewer items are visible.
   *
   * GitHub issue #60: rank columns are built server-side from the full
   * *configured* item count and never recomputed — with 3 configured
   * items (one conditionally hidden), the 2 remaining visible items
   * still offered "1st/2nd/3rd" instead of just "1st/2nd". Fixed
   * client-side in webform_ranking.matrix.js: updateRankColumns(),
   * reacting to the same 'state:visible' event already used for
   * rank-exclusivity (see testHidingRankedItemFreesItsRankForOtherItems()
   * above).
   */
  public function testSurplusRankColumnHidesWhenItemIsHidden(): void {
    $this->drupalGet('/webform/test_ranking_matrix');
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"]');

    // All 3 configured items start visible: all 3 rank columns, plus
    // N/A, are offered.
    $this->assertFalse($this->isRankColumnHidden('1'));
    $this->assertFalse($this->isRankColumnHidden('2'));
    $this->assertFalse($this->isRankColumnHidden('3'));
    $this->assertFalse($this->isRankColumnHidden('na'));

    // Hiding item C leaves only 2 visible items — the 3rd rank column
    // is no longer meaningful and should hide, but N/A never depends
    // on the visible item count and must stay offered regardless.
    $this->getSession()->getPage()->fillField('trigger', 'anything');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->isRankColumnHidden('3');
    }));
    $this->assertFalse($this->isRankColumnHidden('1'));
    $this->assertFalse($this->isRankColumnHidden('2'));
    $this->assertFalse($this->isRankColumnHidden('na'));

    // Item C's own rank-3 cell (and its row) is hidden regardless (its
    // own '#states'); confirm the *other* still-visible items' rank-3
    // cells specifically are what just hid.
    $rank3ForA = $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][a]"][value="3"]');
    $this->assertFalse($rank3ForA->isVisible());

    // Revealing item C again restores the 3rd column.
    $this->getSession()->getPage()->fillField('trigger', '');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->isRankColumnHidden('3');
    }));
  }

  /**
   * Tests that a rank column already surplus on initial page load hides.
   *
   * Distinct from testSurplusRankColumnHidesWhenItemIsHidden(): that
   * test only covers a *live* transition after a 'state:visible' event
   * fires while webform_ranking.matrix.js is already listening.
   * updateRankColumns() needs each row's *initial* visibility (seeded
   * from `offsetParent === null`, since states.js's own page-load
   * evaluation — and the 'state:visible' event announcing it — already
   * happens before this behavior's listener exists to catch it) to get
   * the very first render right. Uses its own webform so the trigger
   * can start pre-filled.
   */
  public function testSurplusRankColumnHiddenOnInitialLoad(): void {
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_matrix_cols',
      'title' => 'Test ranking matrix initial columns',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'textfield',
          '#title' => 'Trigger',
          '#default_value' => 'anything',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            [
              'value' => 'c',
              'label' => 'Item C',
              'states' => [
                'invisible' => [
                  ':input[name="trigger"]' => ['filled' => TRUE],
                ],
              ],
            ],
          ],
        ],
      ]),
    ])->save();

    $this->drupalGet('/webform/test_ranking_matrix_cols');
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"]');

    $this->assertTrue($this->isRankColumnHidden('3'));
    $this->assertFalse($this->isRankColumnHidden('1'));
    $this->assertFalse($this->isRankColumnHidden('2'));
  }

  /**
   * Finds the rank-label span belonging to a specific item/rank radio.
   *
   * The span is a '#suffix', rendered outside the radio's own
   * '#theme_wrappers' => ['form_element'] wrapper div (see core's
   * Radio element) — not a direct sibling of the <input> itself, so
   * this walks up to the shared <td> first rather than relying on a
   * CSS sibling combinator.
   */
  protected function rankLabelSpanFor(string $item, string $rank) {
    $selector = 'input[name="ranking[matrix][' . $item . ']"][value="' . $rank . '"]';
    $radio = $this->assertSession()->waitForElement('css', $selector);
    return $radio->find('xpath', './ancestor::td[1]//*[contains(concat(" ", normalize-space(@class), " "), " webform-ranking-matrix__rank-label ")]');
  }

  /**
   * Tests the responsive collapse at narrow viewports (GitHub issue #115).
   *
   * Mirrors the Likert element's own technique
   * (web/modules/contrib/webform/css/webform.element.likert.css): below
   * the breakpoint, the column headers hide and each cell becomes a
   * block, stacking vertically. A sighted-only rank-label span (added in
   * WebformRanking::buildMatrix()) fills in for the now-hidden column
   * header.
   */
  public function testResponsiveCollapseAtNarrowViewport(): void {
    $this->drupalGet('/webform/test_ranking_matrix');
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"]');

    $page = $this->getSession()->getPage();

    // Desktop width: headers visible, rank-label spans hidden (the
    // header already conveys this).
    $thead = $page->find('css', '.webform-ranking-matrix thead');
    $this->assertNotNull($thead);
    $this->assertTrue($thead->isVisible());
    $rank_label = $this->rankLabelSpanFor('a', '1');
    $this->assertNotNull($rank_label);
    $this->assertFalse($rank_label->isVisible());

    // Narrow viewport: headers hide, the row label stays visible, and
    // the rank-label span takes over conveying the rank.
    $this->getSession()->resizeWindow(480, 800, 'current');
    $this->assertTrue($page->waitFor(4, function () use ($thead) {
      return !$thead->isVisible();
    }));

    $row_label = $page->find('css', '.webform-ranking-matrix__label');
    $this->assertNotNull($row_label);
    $this->assertTrue($row_label->isVisible());

    // The table itself needs its own top margin too — with the header
    // row hidden, nothing else separates the first item's group of
    // cells from the element's title/description text above it.
    $tableMarginTop = $this->getSession()->evaluateScript(
      "getComputedStyle(document.querySelector('.webform-ranking-matrix')).marginTop"
    );
    $this->assertSame('18px', $tableMarginTop);

    $rank_label = $this->rankLabelSpanFor('a', '1');
    $this->assertNotNull($rank_label);
    $this->assertTrue($rank_label->isVisible());
    $this->assertSame('1st', $rank_label->getText());

    // The rank-label span is a '#suffix', rendered as a sibling *after*
    // the radio's own theme_wrappers-rendered <div> (core's Radio
    // element always wraps in one) — that div is block-level by
    // default, which previously pushed the span onto its own line
    // below instead of next to the radio. Confirms the td's own
    // `display: flex` keeps them on the same line.
    $geometry = $this->getSession()->evaluateScript(<<<'JS'
(function () {
  var input = document.querySelector('input[name="ranking[matrix][a]"][value="1"]');
  var div = input.closest('td').querySelector('div');
  var span = input.closest('td').querySelector('.webform-ranking-matrix__rank-label');
  return Math.abs(div.getBoundingClientRect().top - span.getBoundingClientRect().top) < 5;
})()
JS);
    $this->assertTrue($geometry, 'Expected the rank-label span to sit on the same line as its preceding radio/label div.');

    // Cells within the same item's row sit flush together (no leftover
    // per-cell padding), but a visible gap separates one item's group
    // of cells from the next's — without it, review flagged that every
    // item's radios would run together with nothing marking where one
    // item ends and the next begins.
    $rowGaps = $this->getSession()->evaluateScript(<<<'JS'
(function () {
  var rowA = document.querySelector('tr[data-drupal-selector="edit-ranking-matrix-a"]');
  var rowB = document.querySelector('tr[data-drupal-selector="edit-ranking-matrix-b"]');
  var cellsA = rowA.querySelectorAll('td');
  return {
    withinRow: cellsA[1].getBoundingClientRect().top - cellsA[0].getBoundingClientRect().bottom,
    betweenRows: rowB.querySelector('td:first-child').getBoundingClientRect().top - rowA.querySelector('td:last-child').getBoundingClientRect().bottom
  };
})()
JS);
    $this->assertLessThanOrEqual(1, $rowGaps['withinRow'], 'Expected no gap between cells within the same item row.');
    $this->assertGreaterThan(10, $rowGaps['betweenRows'], 'Expected a visible gap between different items\' cell groups.');

    // Resizing back up restores the desktop layout.
    $this->getSession()->resizeWindow(1200, 800, 'current');
    $this->assertTrue($page->waitFor(4, function () use ($thead) {
      return $thead->isVisible();
    }));
  }

  /**
   * Tests that every rank-label span is excluded from the a11y tree.
   *
   * GitHub issue #115, flagged during review: unlike the Likert
   * element's own per-answer label (deliberately screen-reader-exposed,
   * since Likert's radios rely on it via aria-labelledby), this
   * element's radios already have a complete, unambiguous accessible
   * name ("Item: Rank") regardless of viewport — so the rank-label span
   * added for sighted narrow-viewport users must never be exposed to
   * assistive tech, or it would be announced a second time as
   * confusing, redundant content.
   */
  public function testRankLabelSpansAreAriaHidden(): void {
    $this->drupalGet('/webform/test_ranking_matrix');
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"]');

    $spans = $this->getSession()->getPage()->findAll('css', '.webform-ranking-matrix__rank-label');
    $this->assertNotEmpty($spans, 'Expected at least one rank-label span.');
    foreach ($spans as $span) {
      $this->assertSame('true', $span->getAttribute('aria-hidden'));
    }
  }

  /**
   * Tests that a conditionally-hidden row stays hidden at any width.
   *
   * Confirms the responsive collapse (GitHub issue #115) doesn't
   * interact with the `tr[hidden]` `!important` rule (GitHub issue #59)
   * — a hidden row's `display: none !important` must keep winning over
   * the new narrow-viewport `td { display: block; }` rule regardless.
   */
  public function testConditionalItemRowStaysHiddenAtNarrowViewport(): void {
    $this->drupalGet('/webform/test_ranking_matrix');
    $page = $this->getSession()->getPage();

    $radio = $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][c]"][value="1"]');
    $row = $radio->find('xpath', './ancestor::tr[1]');
    $this->assertNotNull($row);

    $page->fillField('trigger', 'anything');
    $this->assertTrue($page->waitFor(4, function () use ($row) {
      return !$row->isVisible();
    }));

    $this->getSession()->resizeWindow(480, 800, 'current');
    $this->assertTrue($page->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.webform-ranking-matrix thead')->isVisible();
    }));
    $this->assertFalse($row->isVisible());

    $this->getSession()->resizeWindow(1200, 800, 'current');
  }

}
