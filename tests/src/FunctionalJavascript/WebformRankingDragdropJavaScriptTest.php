<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;
use WebDriver\Key;

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
   * Tests dragging item A onto item C via real WebDriver mouse input.
   *
   * Reported bug: pointer-dragging an item shows visual feedback (a
   * "dragging" class toggles, confirmed separately via classList
   * inspection during manual investigation) but the actual DOM order
   * never changes.
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
   * Tests a *gradual*, multi-step pointer drag.
   *
   * In contrast to testPointerDragReordersItems()'s single-jump
   * NodeElement::dragTo(), which issues exactly one pointerMove
   * straight from source to destination (duration: 0, see
   * Selenium2Driver::dragTo()). Real mouse/trackpad input instead fires
   * many incremental pointermove events as the cursor physically
   * travels, passing over every item in between rather than jumping
   * straight to the final one.
   *
   * docs/CONTINUATION.md (Key Design Decision #14) flagged this as the
   * next thing worth checking for GitHub issue #3, since the single-jump
   * test contradicted the original bug report and a real physical drag
   * is a meaningfully different input shape. Built directly on the W3C
   * WebDriver Actions API (postActions()) with absolute viewport
   * coordinates and >0 durations between steps, rather than
   * NodeElement::dragTo(), specifically to get that multi-step shape.
   *
   * Drags item A down through item B's midpoint and item C's midpoint
   * in 10 discrete steps.
   */
  public function testGradualPointerDragReordersItems(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $assert_session->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $this->assertOrder(['a', 'b', 'c']);

    $item_a = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');
    $this->assertNotNull($item_a);

    // Centers of A and C's bounding boxes, in viewport coordinates —
    // dragging from A's center to C's center (not top-left corners,
    // unlike dragTo()) passes squarely through B along the way and
    // lands mid-way inside C, so the production midpoint check
    // (event.clientY < rect.top + rect.height / 2) is expected to treat
    // the final position as "after" C.
    $centers = $this->getSession()->evaluateScript(<<<'JS'
(function () {
  function centerOf(selector) {
    var rect = document.querySelector(selector).getBoundingClientRect();
    return {x: Math.round(rect.left + rect.width / 2), y: Math.round(rect.top + rect.height / 2)};
  }
  return {
    a: centerOf('.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]'),
    c: centerOf('.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]')
  };
})()
JS
    );

    $steps = 10;
    $move_actions = [];
    for ($i = 1; $i <= $steps; $i++) {
      $move_actions[] = [
        'type' => 'pointerMove',
        'duration' => 50,
        'origin' => 'viewport',
        'x' => (int) round($centers['a']['x'] + ($centers['c']['x'] - $centers['a']['x']) * $i / $steps),
        'y' => (int) round($centers['a']['y'] + ($centers['c']['y'] - $centers['a']['y']) * $i / $steps),
      ];
    }

    $webdriver_session = $this->getSession()->getDriver()->getWebDriverSession();
    $webdriver_session->postActions([
      'actions' => [
        [
          'type' => 'pointer',
          'id' => 'mouse1',
          'parameters' => ['pointerType' => 'mouse'],
          'actions' => array_merge(
            [
              [
                'type' => 'pointerMove',
                'duration' => 0,
                'origin' => 'viewport',
                'x' => $centers['a']['x'],
                'y' => $centers['a']['y'],
              ],
              [
                'type' => 'pointerDown',
                'button' => 0,
              ],
            ],
            $move_actions,
            [['type' => 'pointerUp', 'button' => 0]]
          ),
        ],
      ],
    ]);
    $webdriver_session->deleteActions();

    // Landing at C's center (not its top-left corner) means "after C",
    // not "before C" — see this method's docblock.
    $this->assertOrder(['b', 'c', 'a']);
  }

  /**
   * Tests that the always-present move-up/move-down buttons reorder items.
   *
   * These are the primary, fully-equivalent interaction per this
   * element's own accessibility model (see
   * js/webform_ranking.dragdrop.js's file docblock).
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
   * Tests that ArrowUp/ArrowDown reorder the focused item.
   *
   * A keyboard shortcut layered on top of the buttons, per this
   * element's own accessibility model.
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
    $element->postValue(['text' => Key::DOWN_ARROW]);

    $this->assertOrder(['b', 'a', 'c']);
  }

  /**
   * Tests that marking an item N/A removes it from the ranked order.
   *
   * It's grouped at the end, excluded from rank numbering. Unmarking it
   * re-enters it at the end of the ranked list.
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
   * Tests that a dragdrop item's rank can be a live #states trigger.
   *
   * For another element (Key Design Decision #13) — no page reload
   * required.
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
   * Tests that the live region uses core's real '.visually-hidden'.
   *
   * This display style's own live region uses the same
   * '.visually-hidden' class as the matrix style's, but
   * element.dragdrop's library never loads webform_ranking.matrix.css
   * — so it always depended on that class being defined somewhere
   * else, which wasn't previously guaranteed by an explicit
   * dependency. Both libraries now depend on 'system/base' instead
   * (see webform_ranking.libraries.yml); this asserts the browser's
   * *computed* clip value matches core's exact rule
   * (`rect(1px, 1px, 1px, 1px)`), proving core's CSS is genuinely in
   * effect here too.
   */
  public function testLiveRegionUsesCoreVisuallyHiddenStyle(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');

    $clip = $this->getSession()->evaluateScript(
      "getComputedStyle(document.querySelector('.webform-ranking-dragdrop__live-region')).clip"
    );

    $this->assertSame('rect(1px, 1px, 1px, 1px)', $clip);
  }

  /**
   * Tests that the role="list" container has only listitem children.
   *
   * Real bug: the hidden order/na/rank inputs and the live-region
   * <div> used to be direct children of the role="list" element
   * itself, alongside the real role="listitem" items — invalid list
   * semantics, since a list role's owned children must all be
   * listitem. Fixed by moving those elements to a wrapper one level
   * up (see WebformRanking::buildDragDrop()).
   */
  public function testListRoleContainerHasOnlyListitemChildren(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    $childRoles = $this->getSession()->evaluateScript(<<<'JS'
      Array.from(document.querySelector('[role="list"].webform-ranking-dragdrop').children)
        .map(function (el) { return el.getAttribute('role'); })
JS);

    $this->assertNotEmpty($childRoles, 'Expected the list to have at least one child.');
    foreach ($childRoles as $role) {
      $this->assertSame('listitem', $role);
    }
  }

  /**
   * Tests that repeated move-up clicks keep moving the item.
   *
   * Real bug: moveItem() unconditionally called item.focus() after
   * every move, stealing focus off the button the user just
   * activated. A keyboard user pressing Enter on "Move up" repeatedly
   * got exactly one move — the second press landed on the item
   * container, which has no Enter handler. Simulated here by checking
   * that document.activeElement is still the button (not the item)
   * immediately after a click, which is what allows a second Enter
   * press to keep working.
   */
  public function testMoveButtonRetainsFocusAfterMove(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $page = $this->getSession()->getPage();

    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]');
    $item_c = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]');
    $item_c->find('css', '.webform-ranking-dragdrop__move-up')->click();

    $this->assertOrder(['a', 'c', 'b']);

    $active_element_class = $this->getSession()->evaluateScript(
      'document.activeElement.className'
    );
    $this->assertStringContainsString('webform-ranking-dragdrop__move-up', $active_element_class);

    // With focus retained, a second click (standing in for a second
    // Enter press on the still-focused button) keeps moving the item.
    $item_c = $page->find('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="c"]');
    $item_c->find('css', '.webform-ranking-dragdrop__move-up')->click();
    $this->assertOrder(['c', 'a', 'b']);
  }

  /**
   * Tests that the move-button glyphs are hidden from assistive tech.
   *
   * Real bug: the '▲'/'▼' glyphs were the button's own text content,
   * exposed to assistive tech alongside the button's aria-label —
   * redundant/confusing symbol-name readout. Fixed by wrapping each
   * glyph in an aria-hidden span nested inside the button.
   */
  public function testMoveButtonGlyphsAreAriaHidden(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    $hidden = $this->getSession()->evaluateScript(<<<'JS'
      (function () {
        var button = document.querySelector('.webform-ranking-dragdrop__move-up');
        var span = button.querySelector('span');
        return !!span && span.getAttribute('aria-hidden') === 'true' && span.textContent === '▲';
      })()
JS);

    $this->assertTrue($hidden, 'Expected the move-up glyph to be in an aria-hidden span.');
  }

  /**
   * Tests that draggable items declare touch-action: none.
   *
   * Real bug: without this, a touch press-and-move gesture is claimed
   * by the browser for scrolling instead of reaching this element's
   * pointermove handler, which fires 'pointercancel' and aborts the
   * drag before it starts. Not a behavioral test (WebDriver doesn't
   * simulate real touch gesture semantics) — just confirms the CSS
   * property that makes touch dragging possible at all is present.
   */
  public function testDraggableItemsDeclareTouchActionNone(): void {
    $this->drupalGet('/webform/test_ranking_dragdrop');
    $this->assertSession()->waitForElement('css', '.webform-ranking-dragdrop__item[data-webform-ranking-value="a"]');

    $touchAction = $this->getSession()->evaluateScript(
      "getComputedStyle(document.querySelector('.webform-ranking-dragdrop__item')).touchAction"
    );

    $this->assertSame('none', $touchAction);
  }

  /**
   * Asserts the top-to-bottom order of items by their value attribute.
   *
   * (Uses data-webform-ranking-value attribute.)
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
