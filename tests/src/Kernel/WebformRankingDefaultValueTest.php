<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a non-array #default_value can no longer fatal the element.
 *
 * Regression coverage for B2: a scalar value in the "Default value"
 * textfield (Webform's generic composite-element config form offered one,
 * since hasProperty('default_value') returned TRUE) reached
 * WebformRankingConverter::canonicalToMatrix() — a typed array parameter —
 * unguarded, producing a fatal TypeError on every render.
 */
#[Group('webform_ranking')]
class WebformRankingDefaultValueTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'webform',
    'webform_ranking',
  ];

  /**
   * The plugin instance under test.
   *
   * @var \Drupal\webform_ranking\Plugin\WebformElement\WebformRanking
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // See WebformRankingPluginTest for why this is set directly rather
    // than via installConfig(['webform']).
    \Drupal::configFactory()->getEditable('webform.settings')
      ->set('element.allowed_tags', 'admin')
      ->save();
    $this->plugin = \Drupal::service('plugin.manager.webform.element')->createInstance('webform_ranking');
  }

  /**
   * A standard two-item set.
   */
  protected function items(): array {
    return [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
    ];
  }

  /**
   * The 'default_value' property must stay declared (GitHub #129,
   * ADR-0024) — WebformSubmissionForm::populateElements()'s only gate
   * for repopulating #default_value from a saved submission on any
   * wizard/draft/edit rebuild is hasProperty('default_value'). Only the
   * generic admin-form widget for it is suppressed (in form(), not
   * testable here since it requires the webform_ui module's own
   * WebformUiElementFormInterface form object, which this environment
   * doesn't have installed) — the property itself must remain.
   */
  public function testDefaultValuePropertyIsDeclared(): void {
    $this->assertTrue($this->plugin->hasProperty('default_value'));
  }

  /**
   * A scalar #default_value is normalized to an empty canonical value.
   */
  public function testScalarDefaultValueIsNormalizedToEmptyCanonical(): void {
    $element = ['#type' => 'webform_ranking', '#items' => $this->items(), '#default_value' => 'oops'];
    $this->plugin->prepare($element);

    $this->assertSame(['values' => [], 'na' => []], $element['#default_value']);
  }

  /**
   * A flat array #default_value is converted to canonical shape.
   */
  public function testArrayDefaultValueIsConvertedToCanonical(): void {
    $element = [
      '#type' => 'webform_ranking',
      '#items' => $this->items(),
      '#default_value' => ['item_a' => '2', 'item_b' => '1'],
    ];
    $this->plugin->prepare($element);

    $this->assertSame(['values' => ['item_b', 'item_a'], 'na' => []], $element['#default_value']);
  }

  /**
   * A bare empty array #default_value is normalized to canonical shape.
   */
  public function testEmptyArrayDefaultValueIsNormalizedToCanonical(): void {
    $element = ['#type' => 'webform_ranking', '#items' => $this->items(), '#default_value' => []];
    $this->plugin->prepare($element);

    $this->assertSame(['values' => [], 'na' => []], $element['#default_value']);
  }

  /**
   * A form with a scalar #default_value builds and renders without throwing.
   *
   * Before the fix this reproduced: `TypeError:
   * WebformRankingConverter::canonicalToMatrix(): Argument #1 ($canonical)
   * must be of type array, string given`.
   */
  public function testFormWithScalarDefaultValueRendersWithoutFatal(): void {
    $element = [
      '#type' => 'webform_ranking',
      '#title' => 'Ranking',
      '#items' => $this->items(),
      '#default_value' => 'oops',
    ];
    $this->plugin->prepare($element);

    $form_object = new WebformRankingDefaultValueDummyForm($element);
    $form = \Drupal::formBuilder()->getForm($form_object);
    $markup = (string) \Drupal::service('renderer')->renderRoot($form);

    // The real assertion is that the line above didn't throw. This
    // confirms it actually rendered the ranking element's markup, not
    // an empty/error form.
    $this->assertStringContainsString('webform-ranking-matrix', $markup);
  }

}

/**
 * Minimal form wrapping a single pre-prepared ranking element.
 */
class WebformRankingDefaultValueDummyForm implements FormInterface {

  public function __construct(protected array $element) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_ranking_default_value_dummy_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['ranking'] = $this->element;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
