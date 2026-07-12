<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the drag/drop ranking style's pointer-based reorder behavior.
 *
 * Real WebDriver mouse actions (via NodeElement::dragTo(), which issues
 * genuine W3C Actions pointerMove/pointerDown/pointerUp through
 * Selenium — not synthetic JS-dispatched events, and not HTML5 native
 * drag/drop, which this element's JS deliberately doesn't use) are
 * required here. An earlier attempt to reproduce the reported "drag
 * doesn't reorder" bug by dispatching synthetic PointerEvents via
 * chrome-cli's JS execution turned out to run in an isolated JS world,
 * separate from the page's real global scope (confirmed: window.Drupal,
 * jQuery, and drupalSettings were all undefined from that execution
 * context) — meaning prototype patches and dispatchEvent() calls made
 * there could observe the shared DOM, but couldn't reliably intercept
 * or reason about the actual page-world listeners registered by
 * webform_ranking.dragdrop.js. A real browser test driven by genuine
 * WebDriver-level input is the only reliable way to test this.
 */
#[Group('webform_ranking')]
class WebformRankingDragdropJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_dragdrop',
      'title' => 'Test ranking dragdrop',
      'elements' => Yaml::encode([
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'dragdrop',
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
        ],
        // Dependent element used to confirm live #states reaction to a
        // dragdrop item's rank (Key Design Decision #13's per-item
        // rank-echo channel) — previously only verified manually via
        // chrome-cli, never with automated coverage.
        'first_choice_message' => [
          '#type' => 'webform_markup',
          '#markup' => 'You selected Item A as your first choice.',
          '#states' => [
            'visible' => [
              ':input[name="ranking[dragdrop][rank][a]"]' => ['value' => '1'],
            ],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * Reported bug: pointer-dragging an item shows visual feedback (a
   * "dragging" class toggles, confirmed separately via classList
   * inspection during manual investigation) but the actual DOM order
   * never changes. Drags item A onto item C via a real WebDriver mouse
   * sequence and asserts the resulting order.
   *
   * Surprising result once actually run with a real WebDriver drag
   * (see below): the reorder DOES happen. Mink's NodeElement::dragTo()
   * moves the pointer to the destination element's top-left corner
   * (0,0 offset), which lands in the *upper* half of its bounding
   * box — and the production pointermove handler's own midpoint check
   * (`event.clientY < rect.top + rect.height / 2`) correctly treats
   * that as "drop before this item," inserting the dragged item
   * immediately ahead of the destination. That's exactly what this
   * test now asserts. This directly contradicts the original bug
   * report (dragging appeared to have no effect at all) — see
   * docs/CONTINUATION.md for the full discussion of what this means
   * for that report.
   */
  public function testPointerDragReordersItems(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $assert_session->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    // Sanity check the starting order before dragging, so a failure
    // clearly shows "nothing moved" rather than leaving it ambiguous
    // whether the items were ever in the expected order to begin with.
    $this->assertOrder(['a', 'b', 'c']);

    $item_a = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $item_c = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]');
    $this->assertNotNull($item_a);
    $this->assertNotNull($item_c);

    $item_a->dragTo($item_c);

    // 'a' lands immediately before 'c', not after it — see this
    // method's docblock for why "before" is the correct outcome here.
    $this->assertOrder(['b', 'a', 'c']);
  }

  /**
   * Tests that the always-present move-up/move-down buttons — the
   * primary, fully-equivalent interaction per this element's own
   * accessibility model (see js/webform_ranking.dragdrop.js's file
   * docblock) — correctly reorder items.
   */
  public function testMoveButtonsReorderItems(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $page = $this->getSession()->getPage();

    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $this->assertOrder(['a', 'b', 'c']);

    // Move item C up twice: C, A, B then A, C, B.
    $item_c = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]');
    $item_c->find('css', '.webform-ranking-dragdrop__move-up')->click();
    $this->assertOrder(['a', 'c', 'b']);

    // Button lookup is re-done per click since sync() only moves the
    // underlying DOM node, not the NodeElement reference, but a fresh
    // CSS lookup by value is always safe regardless of position.
    $item_c = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]');
    $item_c->find('css', '.webform-ranking-dragdrop__move-up')->click();
    $this->assertOrder(['c', 'a', 'b']);

    // The topmost item's move-up button is disabled — moving it
    // further is a no-op, not an error.
    $this->assertTrue($item_c->find('css', '.webform-ranking-dragdrop__move-up')->hasAttribute('disabled'));
  }

  /**
   * Tests that ArrowUp/ArrowDown reorder the focused item — a keyboard
   * shortcut layered on top of the buttons, per this element's own
   * accessibility model.
   */
  public function testArrowKeyReordersItems(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');

    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    // NodeElement::keyDown()/keyUp() go through Mink's bundled syn.js,
    // a JS-simulated keyboard event that only sets the legacy
    // keyCode/which properties correctly — its 'key' string ends up
    // wrong (chr(40), not 'ArrowDown'). Our listener checks the modern
    // event.key, so a real native key press is needed instead: sent
    // directly through the WebDriver session's legacy "element value"
    // endpoint, which real browsers do interpret as a genuine key
    // event (correct .key included), unlike syn.js's approximation.
    $webdriver_session = $this->getSession()->getDriver()->getWebDriverSession();
    $element = $webdriver_session->element('css selector', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $element->postValue(['text' => \WebDriver\Key::DOWN_ARROW]);

    $this->assertOrder(['b', 'a', 'c']);
  }

  /**
   * Tests that marking an item N/A removes it from the ranked order
   * (grouped at the end, excluded from rank numbering), and that
   * unmarking it re-enters it at the end of the ranked list.
   */
  public function testNaToggleRemovesFromRanking(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $page = $this->getSession()->getPage();

    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    $item_a = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $item_a->find('css', '.webform-ranking-dragdrop__na-checkbox')->click();

    $this->assertSame('true', $item_a->getAttribute('data-webform-ranking-na'));
    // N/A'd items are grouped at the end, so the ranked order becomes
    // b, c with a moved past them.
    $this->assertOrder(['b', 'c', 'a']);

    // Unmarking re-enters it at the end of the ranked list (not an
    // arbitrary position).
    $item_a->find('css', '.webform-ranking-dragdrop__na-checkbox')->click();
    $this->assertSame('false', $item_a->getAttribute('data-webform-ranking-na'));
    $this->assertOrder(['b', 'c', 'a']);
  }

  /**
   * Tests that a dragdrop item's rank can be used as a live #states
   * trigger for another element (Key Design Decision #13) — no page
   * reload required.
   */
  public function testStatesReactToRankSelection(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $page = $this->getSession()->getPage();

    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    // Item A starts ranked 1st by default (configured order), so the
    // dependent message should already be visible on load.
    $message = $this->assertSession()->elementExists('css', '#edit-first-choice-message');
    $this->assertTrue($message->isVisible());

    // Move A out of 1st place via the move-down button; the message
    // should hide live, with no reload.
    $item_a = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $item_a->find('css', '.webform-ranking-dragdrop__move-down')->click();

    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($message) {
      return !$message->isVisible();
    }));

    // Moving it back to 1st reveals it again.
    $item_a = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $item_a->find('css', '.webform-ranking-dragdrop__move-up')->click();
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '#edit-first-choice-message'));
  }

  /**
   * Asserts the current top-to-bottom order of ranking items by their
   * data-webform-ranking-value attribute.
   *
   * @param string[] $expected
   *   The expected ordered list of item values.
   */
  protected function assertOrder(array $expected): void {
    $values = $this->getSession()->evaluateScript(
      "Array.from(document.querySelectorAll('.webform-ranking-dragdrop__item')).map(function (el) { return el.getAttribute('data-webform-ranking-value'); })"
    );
    $this->assertSame($expected, $values);
  }

}
