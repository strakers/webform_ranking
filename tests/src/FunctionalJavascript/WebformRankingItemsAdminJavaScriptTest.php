<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Behat\Mink\Element\NodeElement;
use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per-item conditional-visibility dialog (GitHub issue #4).
 *
 * An earlier version used a per-row checkbox for inline progressive
 * disclosure; that never actually worked (its row-scoping heuristic
 * matched the wrong row) and was redesigned to a per-item dialog instead
 * — see js/webform_ranking.items_admin.js's file docblock.
 *
 * Requires webform_ui — the element edit form
 * (entity.webform_ui.element.edit_form) that items_admin.js attaches to
 * is provided by that submodule, not webform itself.
 */
#[Group('webform_ranking')]
class WebformRankingItemsAdminJavaScriptTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'webform_ranking', 'webform_ui'];

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
      'id' => 'test_ranking_items_admin',
      'title' => 'Test ranking items admin',
      'elements' => Yaml::encode([
        // A real trigger element, so the condition builder's "Element"
        // dropdown (populated from $webform->getElementsSelectorOptions())
        // has genuine, selectable options — see the GitHub issue #65
        // test methods below.
        'trigger_field' => [
          '#type' => 'textfield',
          '#title' => 'Trigger field',
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            // No 'states' key at all: the common case for a brand new
            // item row.
            ['value' => 'a', 'label' => 'Item A'],
            // A single, decomposable condition: simulates editing an
            // item already configured (via either the builder or
            // hand-written YAML) before this page loaded.
            [
              'value' => 'b',
              'label' => 'Item B',
              'states' => [
                'visible' => [
                  ':input[name="trigger_field"]' => ['checked' => TRUE],
                ],
              ],
            ],
            // A multi-condition, OR-shaped decomposable condition.
            [
              'value' => 'c',
              'label' => 'Item C',
              'states' => [
                'visible' => [
                  [':input[name="trigger_field"]' => ['value' => 'go']],
                  'or',
                  [':input[name="trigger_field"]' => ['value' => 'also-go']],
                ],
              ],
            ],
            // Multiple states: not representable by the builder (only a
            // single visible/invisible mode is offered) — the dialog
            // should default to the raw YAML view for this item.
            [
              'value' => 'd',
              'label' => 'Item D',
              'states' => [
                'visible' => [':input[name="trigger_field"]' => ['value' => 'x']],
                'required' => [':input[name="trigger_field"]' => ['value' => 'x']],
              ],
            ],
          ],
        ],
      ]),
    ])->save();

    $this->drupalLogin($this->rootUser);
  }

  /**
   * Finds the "Conditions" trigger button for a given item.
   *
   * Matched by the row's Value textfield content — #webform_multiple
   * rows are 0-indexed and deltas can shift, so matching by content
   * rather than a hardcoded index is more robust. The trigger button
   * itself never moves in the DOM (only the YAML field's wrapper does,
   * into the dialog), so this lookup stays valid before and after
   * opening it.
   */
  protected function triggerForItemValue(string $item_value): NodeElement {
    $page = $this->getSession()->getPage();
    foreach ($page->findAll('css', '.webform-ranking-item-configure-states') as $trigger) {
      $row = $trigger->find('xpath', './ancestor::tr[1]');
      if ($row && $row->find('css', 'input[type="text"]')?->getValue() === $item_value) {
        return $trigger;
      }
    }
    $this->fail("Could not find a 'configure states' trigger for item value '{$item_value}'.");
  }

  /**
   * Reads the YAML field's value from whichever dialog is open.
   *
   * (Uses '.webform-ranking-item-states' YAML field.)
   *
   * Deliberately reads the underlying <textarea>'s value via JS rather
   * than a Mink NodeElement — Webform's CodeMirror JS
   * (webform.element.codemirror.js) replaces the textarea with its own
   * rendered editor and leaves the original <textarea> permanently
   * display:none, so a Mink-level "is this visible"/"get value" check
   * on the textarea itself never succeeds regardless of whether the
   * dialog containing it is open, since CodeMirror's own hiding is
   * unrelated to our dialog's hiding.
   */
  protected function getOpenDialogYamlValue(): string {
    return (string) $this->getSession()->evaluateScript(
      "document.querySelector('.ui-dialog .webform-ranking-item-states').value"
    );
  }

  /**
   * Sets the YAML field's value in whichever dialog is open.
   *
   * Prefers writing through CodeMirror's own API (setValue() + an
   * immediate save() to flush into the linked <textarea>) when
   * CodeMirror is attached, rather than setting the <textarea>'s value
   * directly and dispatching input/change: webform.element.codemirror.js
   * debounces its own textarea-to-editor sync by 500ms
   * (setTimeout(..., 500) around editor.save()), so a direct textarea
   * write can race with — and lose to — that stale, already-scheduled
   * timer overwriting it back to CodeMirror's last known content before
   * the debounce fires. Writing through CodeMirror directly and calling
   * save() immediately sidesteps that race entirely.
   */
  protected function setOpenDialogYamlValue(string $value): void {
    $this->getSession()->evaluateScript(sprintf(
      <<<'JS'
(function () {
  var textarea = document.querySelector('.ui-dialog .webform-ranking-item-states');
  var cmWrapper = textarea.nextElementSibling;
  if (cmWrapper && cmWrapper.CodeMirror) {
    cmWrapper.CodeMirror.setValue(%s);
    cmWrapper.CodeMirror.save();
  }
  else {
    textarea.value = %s;
    textarea.dispatchEvent(new Event('input', {bubbles: true}));
    textarea.dispatchEvent(new Event('change', {bubbles: true}));
  }
})()
JS,
      json_encode($value),
      json_encode($value)
    ));
  }

  /**
   * Reads a field's value from whichever dialog is currently visible.
   *
   * Plain 'css' selectors (Mink's find(), or a bare
   * document.querySelector) aren't enough once a second item's dialog
   * has been opened in the same test: the *closed* dialog from a
   * previous item is still in the DOM (jQuery UI's close() hides it, it
   * doesn't remove it — getOpenDialogYamlValue() above has the same
   * latent ambiguity but never hits it, since every test using it opens
   * only one dialog per page load). jQuery's ':visible' filter,
   * evaluated in-browser, is what actually disambiguates which dialog
   * is the live one.
   */
  protected function getVisibleDialogFieldValue(string $selector): string {
    return (string) $this->getSession()->evaluateScript(
      sprintf("jQuery('.ui-dialog:visible %s').val()", $selector)
    );
  }

  /**
   * Sets a field's value in whichever dialog is currently visible.
   *
   * See getVisibleDialogFieldValue() for why this can't use Mink's
   * find(). Dispatches a native 'change' event (not jQuery's
   * .trigger()) — confirmed via an isolated scratch-element check that
   * jQuery 4's .trigger('change') does not reach a plain
   * addEventListener('change', ...) listener in this environment at
   * all, which is exactly how this module's own JS listens (matching
   * real browser behavior for an actual user interaction; only the
   * synthetic dispatch path differs here).
   */
  protected function setVisibleDialogFieldValue(string $selector, string $value): void {
    $this->getSession()->evaluateScript(sprintf(
      <<<'JS'
      (function () {
        var el = jQuery('.ui-dialog:visible %s')[0];
        el.value = %s;
        el.dispatchEvent(new Event('change', {bubbles: true}));
      })()
      JS,
      $selector,
      json_encode($value)
    ));
  }

  /**
   * Clicks a button (by its exact text) in whichever dialog is visible.
   *
   * Covers both jQuery UI's own '.ui-dialog-buttonpane' buttons (Done,
   * Clear condition) and the condition builder's own plain buttons (Add
   * another condition, Edit source, Back to condition
   * builder, Remove) — all live inside '.ui-dialog:visible', just in
   * different sub-areas. Mink's generic pressButton() has the same
   * stale-DOM ambiguity as getVisibleDialogFieldValue() once a second
   * dialog has been opened in the same test.
   */
  protected function clickVisibleDialogButton(string $label): void {
    $this->getSession()->evaluateScript(sprintf(
      <<<'JS'
      jQuery('.ui-dialog:visible button').filter(function () {
        return jQuery(this).text().trim() === %s;
      }).first()[0].click();
      JS,
      json_encode($label)
    ));
  }

  /**
   * Reads one condition row's field value from the visible dialog.
   *
   * @param int $row_index
   *   0-based row index, in DOM order.
   * @param string $field
   *   One of 'selector', 'trigger', or 'value'.
   */
  protected function conditionRowField(int $row_index, string $field): string {
    $sub_selector = $field === 'value' ? '.webform-states-table--value input' : ".webform-states-table--{$field} select";
    return (string) $this->getSession()->evaluateScript(sprintf(
      "jQuery('.ui-dialog:visible .webform-ranking-item-condition-row').eq(%d).find(%s).val()",
      $row_index,
      json_encode($sub_selector)
    ));
  }

  /**
   * Sets one condition row's field value in the visible dialog.
   *
   * Native 'change' dispatch — see setVisibleDialogFieldValue()'s
   * docblock for why jQuery's .trigger() doesn't work here.
   */
  protected function setConditionRowField(int $row_index, string $field, string $value): void {
    $sub_selector = $field === 'value' ? '.webform-states-table--value input' : ".webform-states-table--{$field} select";
    $this->getSession()->evaluateScript(sprintf(
      <<<'JS'
      (function () {
        var el = jQuery('.ui-dialog:visible .webform-ranking-item-condition-row').eq(%d).find(%s)[0];
        el.value = %s;
        el.dispatchEvent(new Event('change', {bubbles: true}));
      })()
      JS,
      $row_index,
      json_encode($sub_selector),
      json_encode($value)
    ));
  }

  /**
   * Counts condition rows currently rendered in the visible dialog.
   */
  protected function conditionRowCount(): int {
    return (int) $this->getSession()->evaluateScript(
      "jQuery('.ui-dialog:visible .webform-ranking-item-condition-row').length"
    );
  }

  /**
   * Tests whether an element inside the visible dialog is itself visible.
   *
   * Mink's 'css' selector engine (Symfony CssSelector) doesn't support
   * jQuery's ':visible' pseudo-class, so scoping to the live dialog (see
   * getVisibleDialogFieldValue()) and checking visibility both have to
   * go through jQuery here, not a Mink find()->isVisible() call.
   */
  protected function isVisibleInDialog(string $selector): bool {
    return (bool) $this->getSession()->evaluateScript(
      sprintf("jQuery('.ui-dialog:visible %s').is(':visible')", $selector)
    );
  }

  /**
   * Tests whether an element inside the visible dialog has a CSS class.
   */
  protected function hasClassInDialog(string $selector, string $class): bool {
    return (bool) $this->getSession()->evaluateScript(
      sprintf("jQuery('.ui-dialog:visible %s').hasClass(%s)", $selector, json_encode($class))
    );
  }

  /**
   * Tests that the trigger opens exactly one dialog, no extra steps.
   *
   * Also tests that "Clear condition" empties the field while "Done"
   * closes the dialog.
   *
   * A real duplicate-dialog bug was caught here during development:
   * #webform_multiple applies '#wrapper_attributes' to both its own
   * per-item table cell and the nested form-item div Drupal's Form API
   * generates for the same element, so the wrapper selector matched
   * twice per item — meaning each item briefly got two trigger buttons
   * and a trigger+dialog nested inside another trigger's dialog. Fixed
   * in js/webform_ranking.items_admin.js by skipping any wrapper match
   * that itself contains another match.
   */
  public function testDialogOpensEditsAndClears(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');

    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $triggers_a = $this->getSession()->getPage()->findAll('css', '.webform-ranking-item-configure-states');
    // Each item gets exactly one trigger button — see this method's
    // docblock for the duplicate-wrapper bug this guards against.
    $this->assertCount(4, $triggers_a);

    $trigger_a = $this->triggerForItemValue('a');
    $this->assertSame('Conditions', $trigger_a->getText());

    $trigger_a->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    // Exactly one dialog, and the YAML field is inside it and editable
    // immediately — no extra click/step needed to reach it.
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.ui-dialog'));
    $this->assertSame('', $this->getOpenDialogYamlValue());

    $this->setOpenDialogYamlValue('visible: {}');
    $this->getSession()->getPage()->pressButton('Done');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.ui-dialog')?->isVisible();
    }));

    // Reopen: the dialog shows the content just entered, and "Clear
    // condition" empties it.
    $trigger_a->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');
    $this->assertSame('visible: {}', trim($this->getOpenDialogYamlValue()));

    $this->getSession()->getPage()->pressButton('Clear condition');
    $this->assertSame('', $this->getOpenDialogYamlValue());
  }

  /**
   * Tests that an item with existing YAML content shows it pre-filled.
   *
   * Goal: editing an existing conditional item shouldn't look broken.
   */
  public function testExistingConditionShownInDialog(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');

    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $trigger_b = $this->triggerForItemValue('b');
    // Same static label as an unconfigured item — the dialog is what
    // reveals whether a condition already exists, not the button.
    $this->assertSame('Conditions', $trigger_b->getText());

    $trigger_b->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');
    $this->assertStringContainsString('trigger_field', $this->getOpenDialogYamlValue());
  }

  /**
   * Tests that a condition set through the dialog persists on submit.
   *
   * The key risk of moving the field into a dialog: jQuery UI's dialog
   * widget can, by default, append its wrapper outside the <form>,
   * which would silently drop the field from what's submitted;
   * js/webform_ranking.items_admin.js explicitly sets 'appendTo' to the
   * closest <form> to guard against this.
   */
  public function testConditionPersistsThroughSubmission(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $trigger_a = $this->triggerForItemValue('a');
    $trigger_a->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');
    $this->setOpenDialogYamlValue('visible: {}');
    $this->getSession()->getPage()->pressButton('Done');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.ui-dialog')?->isVisible();
    }));

    $this->getSession()->getPage()->pressButton('Save');
    $assert_session->statusMessageContains('has been', 'status');

    \Drupal::entityTypeManager()->getStorage('webform')->resetCache(['test_ranking_items_admin']);
    $webform = Webform::load('test_ranking_items_admin');
    $saved_items = $webform->getElementDecoded('ranking')['#items'];
    $item_a = current(array_filter($saved_items, static fn (array $item) => $item['value'] === 'a'));
    $this->assertSame(['visible' => []], $item_a['states'] ?? NULL);
  }

  /**
   * Tests the condition builder shows a decomposed saved condition.
   *
   * GitHub issue #65: a pre-saved single condition should decompose
   * into the picker's dropdowns, not just show as raw YAML.
   */
  public function testConditionBuilderShowsDecomposedCondition(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('b')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $this->assertSame('visible', $this->getVisibleDialogFieldValue('.webform-states-table--state select'));
    $this->assertSame(1, $this->conditionRowCount());
    $this->assertSame(':input[name="trigger_field"]', $this->conditionRowField(0, 'selector'));
    $this->assertSame('checked', $this->conditionRowField(0, 'trigger'));
  }

  /**
   * Tests the builder's fields carry Drupal's own form element classes.
   *
   * Confirmed against this same admin form's real, server-rendered
   * fields (e.g. the "Title" textfield, "Title display" select):
   * every form element gets 'form-element'; a <select> additionally
   * gets 'form-select'/'form-element--type-select'; a text <input>
   * additionally gets 'form-text'/'form-element--type-text'. Without
   * these, the builder's fields would render as bare, unstyled native
   * controls instead of matching the admin theme.
   */
  public function testConditionBuilderFieldsHaveDrupalFormElementClasses(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('b')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $select_classes = ['form-element', 'form-select', 'form-element--type-select'];
    $text_classes = ['form-element', 'form-text', 'form-element--type-text'];

    foreach ($select_classes as $class) {
      $this->assertTrue(
        $this->hasClassInDialog('.webform-states-table--state select', $class),
        "State select missing '{$class}'."
      );
      $this->assertTrue(
        $this->hasClassInDialog('.webform-states-table--operator select', $class),
        "Operator select missing '{$class}'."
      );
      $this->assertTrue(
        $this->hasClassInDialog('.webform-states-table--selector select', $class),
        "Selector select missing '{$class}'."
      );
      $this->assertTrue(
        $this->hasClassInDialog('.webform-states-table--trigger select', $class),
        "Trigger select missing '{$class}'."
      );
    }
    foreach ($text_classes as $class) {
      $this->assertTrue(
        $this->hasClassInDialog('.webform-states-table--value input', $class),
        "Value input missing '{$class}'."
      );
    }
  }

  /**
   * Tests a condition set purely through the builder persists on save.
   *
   * Never touches the YAML view. Also confirms the emitted YAML is
   * valid #states — decoded back
   * correctly by the same server-side path a hand-typed condition goes
   * through, proving the client-side YAML emitter
   * (webform_ranking.items_admin.js's emitYaml()) is actually correct,
   * not just "looks right" in the dialog.
   */
  public function testConditionBuilderSetsConditionAndPersists(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('a')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $this->setVisibleDialogFieldValue('.webform-states-table--state select', 'invisible');
    $this->setConditionRowField(0, 'selector', ':input[name="trigger_field"]');
    $this->setConditionRowField(0, 'trigger', 'value');
    $this->setConditionRowField(0, 'value', 'hide-me');

    $this->clickVisibleDialogButton('Done');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.ui-dialog')?->isVisible();
    }));

    $this->getSession()->getPage()->pressButton('Save');
    $assert_session->statusMessageContains('has been', 'status');

    \Drupal::entityTypeManager()->getStorage('webform')->resetCache(['test_ranking_items_admin']);
    $webform = Webform::load('test_ranking_items_admin');
    $saved_items = $webform->getElementDecoded('ranking')['#items'];
    $item_a = current(array_filter($saved_items, static fn (array $item) => $item['value'] === 'a'));
    $this->assertSame(
      ['invisible' => [':input[name="trigger_field"]' => ['value' => 'hide-me']]],
      $item_a['states'] ?? NULL
    );
  }

  /**
   * Tests adding a second condition row with the "Any" (or) operator.
   *
   * Persists as an OR-shaped #states array.
   */
  public function testConditionBuilderMultiConditionOrOperatorPersists(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('a')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $this->setConditionRowField(0, 'selector', ':input[name="trigger_field"]');
    $this->setConditionRowField(0, 'trigger', 'value');
    $this->setConditionRowField(0, 'value', 'one');

    $this->clickVisibleDialogButton('Add another condition');
    $this->assertSame(2, $this->conditionRowCount());
    $this->setConditionRowField(1, 'selector', ':input[name="trigger_field"]');
    $this->setConditionRowField(1, 'trigger', 'value');
    $this->setConditionRowField(1, 'value', 'two');

    $this->setVisibleDialogFieldValue('.webform-states-table--operator select', 'or');

    $this->clickVisibleDialogButton('Done');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.ui-dialog')?->isVisible();
    }));

    $this->getSession()->getPage()->pressButton('Save');
    $assert_session->statusMessageContains('has been', 'status');

    \Drupal::entityTypeManager()->getStorage('webform')->resetCache(['test_ranking_items_admin']);
    $webform = Webform::load('test_ranking_items_admin');
    $saved_items = $webform->getElementDecoded('ranking')['#items'];
    $item_a = current(array_filter($saved_items, static fn (array $item) => $item['value'] === 'a'));
    $this->assertSame(
      [
        'visible' => [
          [':input[name="trigger_field"]' => ['value' => 'one']],
          'or',
          [':input[name="trigger_field"]' => ['value' => 'two']],
        ],
      ],
      $item_a['states'] ?? NULL
    );
  }

  /**
   * Tests row add/remove chrome.
   *
   * The combining-operator select is always visible on the state row
   * (matching the real builder), but each row's own "Remove" button
   * only once 2+ condition rows exist — removing the last one isn't
   * offered.
   */
  public function testConditionBuilderAddAndRemoveRowChrome(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('a')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $this->assertSame(1, $this->conditionRowCount());
    $this->assertTrue($this->isVisibleInDialog('.webform-states-table--operator'));

    $this->clickVisibleDialogButton('Add another condition');
    $this->assertSame(2, $this->conditionRowCount());
    $this->assertTrue($this->isVisibleInDialog('.webform-states-table--operator'));

    $this->clickVisibleDialogButton('Remove');
    $this->assertSame(1, $this->conditionRowCount());
    $this->assertTrue($this->isVisibleInDialog('.webform-states-table--operator'));
  }

  /**
   * Tests a too-complex-for-the-builder condition defaults to YAML view.
   *
   * Multiple states aren't representable — the dialog should open
   * showing the raw YAML view, not an empty/wrong builder, matching the
   * real element-level widget's own fallback for a customized Form API
   * #states value it can't represent visually.
   */
  public function testNonDecomposableConditionDefaultsToYamlView(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('d')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $this->assertTrue($this->isVisibleInDialog('.webform-ranking-item-yaml-view'));
    $this->assertFalse($this->isVisibleInDialog('.webform-ranking-item-condition-builder'));
    $this->assertStringContainsString('required', $this->getOpenDialogYamlValue());
  }

  /**
   * Tests the "Edit source" / "Back to condition builder" toggle.
   *
   * Switches which view is visible, without disturbing the builder's
   * own rows.
   */
  public function testEditSourceToggleSwitchesViews(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    $this->triggerForItemValue('b')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');

    $this->assertTrue($this->isVisibleInDialog('.webform-ranking-item-condition-builder'));

    $this->clickVisibleDialogButton('Edit source');
    $this->assertTrue($this->isVisibleInDialog('.webform-ranking-item-yaml-view'));
    $this->assertFalse($this->isVisibleInDialog('.webform-ranking-item-condition-builder'));

    $this->clickVisibleDialogButton('Back to condition builder');
    $this->assertTrue($this->isVisibleInDialog('.webform-ranking-item-condition-builder'));
    // Switching back didn't lose the original row's data.
    $this->assertSame(':input[name="trigger_field"]', $this->conditionRowField(0, 'selector'));
  }

}
