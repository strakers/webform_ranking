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
        // A real trigger element, so the condition picker's "Element"
        // dropdown (populated from $webform->getElementsSelectorOptions())
        // has a genuine, selectable option — see testPickerSetsCondition().
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
            // Pre-existing, decomposable single-condition YAML: simulates
            // editing an item already configured (via either this
            // picker or hand-written YAML) before this page loaded — the
            // picker should show it pre-filled, not just the YAML field.
            [
              'value' => 'b',
              'label' => 'Item B',
              'states' => [
                'visible' => [
                  ':input[name="trigger_field"]' => ['value' => 'go'],
                ],
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
    $this->assertCount(2, $triggers_a);

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
   * Reads a field's value from whichever dialog is currently visible.
   *
   * Plain 'css' selectors (Mink's find(), or a bare document.querySelector)
   * aren't enough here: once a second item's dialog has been opened in the
   * same test, the *closed* dialog from the first item is still in the DOM
   * (jQuery UI's close() hides it, it doesn't remove it — see this file's
   * getOpenDialogYamlValue(), which has the same latent ambiguity but never
   * hit it since every other test opens only one dialog per page load).
   * jQuery's ':visible' filter, evaluated in-browser, is what actually
   * disambiguates which dialog is the live one.
   */
  protected function getVisibleDialogFieldValue(string $selector): string {
    return (string) $this->getSession()->evaluateScript(
      sprintf("jQuery('.ui-dialog:visible %s').val()", $selector)
    );
  }

  /**
   * Sets a field's value in whichever dialog is currently visible.
   *
   * See getVisibleDialogFieldValue() for why this can't use Mink's find().
   */
  protected function setVisibleDialogFieldValue(string $selector, string $value): void {
    $this->getSession()->evaluateScript(sprintf(
      "jQuery('.ui-dialog:visible %s').val(%s).trigger('change')",
      $selector,
      json_encode($value)
    ));
  }

  /**
   * Clicks a jQuery UI dialog action button in whichever dialog is visible.
   *
   * Mink's generic pressButton('Done') has the same stale-DOM ambiguity as
   * getVisibleDialogFieldValue() — after a first item's dialog has been
   * closed, its "Done" button is still in the DOM (just hidden), and
   * pressButton() would happily click that invisible, zero-size one
   * instead of the currently open dialog's.
   */
  protected function clickVisibleDialogButton(string $label): void {
    $this->getSession()->evaluateScript(sprintf(
      <<<'JS'
      jQuery('.ui-dialog:visible .ui-dialog-buttonpane button').filter(function () {
        return jQuery(this).text().trim() === %s;
      })[0].click();
      JS,
      json_encode($label)
    ));
  }

  /**
   * Tests the condition picker (GitHub issue #13) alongside the YAML field.
   *
   * Covers both directions: an already-saved single-condition YAML
   * decomposes into the picker's dropdowns on load, and picking a new
   * condition through the picker (not typing YAML) persists correctly on
   * submit — see WebformRanking::decomposeSimpleItemStates()/
   * composeSimpleItemStates().
   */
  public function testPickerSetsCondition(): void {
    $this->drupalGet('/admin/structure/webform/manage/test_ranking_items_admin/element/ranking/edit');
    $assert_session = $this->assertSession();
    $assert_session->waitForElement('css', '.webform-ranking-item-configure-states');

    // Item 'b' was saved with a decomposable single condition — the
    // picker should show it pre-filled, not just the YAML fallback.
    $this->triggerForItemValue('b')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');
    $page = $this->getSession()->getPage();
    $this->assertSame('visible', $this->getVisibleDialogFieldValue('.webform-ranking-item-condition-mode select'));
    $this->assertSame(':input[name="trigger_field"]', $this->getVisibleDialogFieldValue('.webform-states-table--selector select'));
    $this->assertSame('value', $this->getVisibleDialogFieldValue('.webform-states-table--trigger select'));
    $this->assertSame('go', $this->getVisibleDialogFieldValue('.webform-states-table--value input'));
    $this->clickVisibleDialogButton('Done');
    $this->assertTrue($page->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.ui-dialog')?->isVisible();
    }));

    // Item 'a' has no condition — set one entirely through the picker
    // (never touching the YAML textarea) and confirm it's what actually
    // gets saved, proving the picker takes precedence over the (empty)
    // YAML field per validateConfigurationForm()'s documented precedence.
    $this->triggerForItemValue('a')->click();
    $assert_session->waitForElementVisible('css', '.ui-dialog');
    $this->setVisibleDialogFieldValue('.webform-ranking-item-condition-mode select', 'invisible');
    $this->setVisibleDialogFieldValue('.webform-states-table--selector select', ':input[name="trigger_field"]');
    $this->setVisibleDialogFieldValue('.webform-states-table--trigger select', 'filled');
    $this->clickVisibleDialogButton('Done');
    $this->assertTrue($page->waitFor(4, function () {
      return !$this->getSession()->getPage()->find('css', '.ui-dialog')?->isVisible();
    }));

    $page->pressButton('Save');
    $assert_session->statusMessageContains('has been', 'status');

    \Drupal::entityTypeManager()->getStorage('webform')->resetCache(['test_ranking_items_admin']);
    $webform = Webform::load('test_ranking_items_admin');
    $saved_items = $webform->getElementDecoded('ranking')['#items'];
    $item_a = current(array_filter($saved_items, static fn (array $item) => $item['value'] === 'a'));
    $this->assertSame(
      ['invisible' => [':input[name="trigger_field"]' => ['filled' => TRUE]]],
      $item_a['states'] ?? NULL
    );
    // Item 'b' round-tripped through the picker unchanged (just opened
    // and closed via "Done", no edits) should still hold its original
    // condition — proves decompose-then-recompose is lossless.
    $item_b = current(array_filter($saved_items, static fn (array $item) => $item['value'] === 'b'));
    $this->assertSame(
      ['visible' => [':input[name="trigger_field"]' => ['value' => 'go']]],
      $item_b['states'] ?? NULL
    );
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

}
