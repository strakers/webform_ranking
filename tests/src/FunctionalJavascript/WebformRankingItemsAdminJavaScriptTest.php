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
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            // No 'states' key at all: the common case for a brand new
            // item row.
            ['value' => 'a', 'label' => 'Item A'],
            // Pre-existing YAML content: simulates editing an item that
            // was already configured with a condition before this page
            // loaded.
            [
              'value' => 'b',
              'label' => 'Item B',
              'states' => [
                'visible' => [
                  ':input[name="properties[allow_na]"]' => ['checked' => TRUE],
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
    $this->assertStringContainsString('allow_na', $this->getOpenDialogYamlValue());
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
