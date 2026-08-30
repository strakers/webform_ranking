<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the #required_all visual/ARIA indication added for GitHub issue #46.
 *
 * Matrix style: a '.form-required' asterisk on each item's label, plus
 * 'role="radiogroup"'/'aria-labelledby' on the row and a native 'required'
 * HTML attribute + 'aria-describedby' on each radio — see the concrete
 * markup spec in the issue's second comment.
 *
 * Drag/drop style: per the issue's own follow-up clarification, no visual
 * asterisk (an item's position in the list already denotes its rank) — only
 * an ARIA-valid screen-reader cue, since the item container's
 * role="listitem" doesn't support aria-required per the WAI-ARIA role
 * table.
 */
#[Group('webform_ranking')]
class WebformRankingRequiredIndicationTest extends KernelTestBase {

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
   * Renders the given element overrides and returns the resulting markup.
   */
  protected function renderElement(array $overrides): string {
    $element = $overrides + [
      '#type' => 'webform_ranking',
      '#title' => 'Ranking',
      '#items' => $this->items(),
    ];
    $this->plugin->prepare($element);

    $form_object = new WebformRankingRequiredIndicationDummyForm($element);
    $form = \Drupal::formBuilder()->getForm($form_object);
    return (string) \Drupal::service('renderer')->renderRoot($form);
  }

  /**
   * Matrix style, #required_all enabled: full markup spec present.
   */
  public function testMatrixRequiredAllAddsIndication(): void {
    $markup = $this->renderElement([
      '#ranking_style' => 'matrix',
      '#required_all' => TRUE,
    ]);

    $this->assertStringContainsString('form-required', $markup);
    $this->assertStringContainsString('form-item__label', $markup);
    $this->assertStringContainsString('role="radiogroup"', $markup);
    $this->assertStringContainsString('aria-labelledby', $markup);
    $this->assertStringContainsString('required="required"', $markup);
    $this->assertStringContainsString('aria-describedby', $markup);
    $this->assertStringContainsString('scope="col"', $markup);
  }

  /**
   * Matrix style, #required_all disabled: none of the markup is present.
   */
  public function testMatrixWithoutRequiredAllAddsNoIndication(): void {
    $markup = $this->renderElement([
      '#ranking_style' => 'matrix',
      '#required_all' => FALSE,
    ]);

    $this->assertStringNotContainsString('form-required', $markup);
    $this->assertStringNotContainsString('radiogroup', $markup);
    $this->assertStringNotContainsString('aria-labelledby', $markup);
    $this->assertStringNotContainsString('required="required"', $markup);
    $this->assertStringNotContainsString('aria-describedby', $markup);
  }

  /**
   * Drag/drop style, #required_all enabled: ARIA cue present, no asterisk.
   */
  public function testDragdropRequiredAllAddsAriaCueOnly(): void {
    $markup = $this->renderElement([
      '#ranking_style' => 'dragdrop',
      '#required_all' => TRUE,
    ]);

    $this->assertStringContainsString('aria-describedby', $markup);
    $this->assertStringContainsString('This item is required', $markup);
    // No visual asterisk for drag/drop — an item's placement in the
    // ordered list already denotes its rank (see class docblock).
    $this->assertStringNotContainsString('form-required', $markup);
  }

  /**
   * Drag/drop style, #required_all disabled: no ARIA cue added.
   */
  public function testDragdropWithoutRequiredAllAddsNoIndication(): void {
    $markup = $this->renderElement([
      '#ranking_style' => 'dragdrop',
      '#required_all' => FALSE,
    ]);

    $this->assertStringNotContainsString('aria-describedby', $markup);
    $this->assertStringNotContainsString('This item is required', $markup);
  }

  /**
   * A radio input matching the given rendered #name, or NULL if absent.
   */
  protected function findRadioByName(string $markup, string $name): ?\DOMElement {
    $dom = new \DOMDocument();
    // Errors suppressed: the markup fragment isn't a full HTML document,
    // which libxml otherwise warns about.
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $markup);
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query(sprintf('//input[@name="%s"]', $name));
    return $nodes->length ? $nodes->item(0) : NULL;
  }

  /**
   * A conditional row's radios never get a static native 'required'.
   *
   * Permanently suppressed, not live-mirrored into '#states' (GitHub
   * #102 superseded the #68 mirror; see
   * docs/adr/0018-remove-required-optional-states-mirror.md). An item
   * with no live condition is untouched and keeps the plain attribute.
   */
  public function testMatrixRequiredAllWithConditionalItemSuppressesStaticRequired(): void {
    $markup = $this->renderElement([
      '#ranking_style' => 'matrix',
      '#required_all' => TRUE,
      '#items' => [
        ['value' => 'item_a', 'label' => 'Item A'],
        [
          'value' => 'item_b',
          'label' => 'Item B',
          'states' => [
            'invisible' => [
              ':input[name="trigger"]' => ['checked' => TRUE],
            ],
          ],
        ],
      ],
    ]);

    $item_a_radio = $this->findRadioByName($markup, 'ranking[matrix][item_a]');
    $this->assertNotNull($item_a_radio);
    $this->assertSame('required', $item_a_radio->getAttribute('required'));

    $item_b_radio = $this->findRadioByName($markup, 'ranking[matrix][item_b]');
    $this->assertNotNull($item_b_radio);
    $this->assertSame('', $item_b_radio->getAttribute('required'));
    $states = $item_b_radio->getAttribute('data-drupal-states');
    $this->assertStringContainsString('invisible', $states);
    $this->assertStringNotContainsString('optional', $states);
    $this->assertStringNotContainsString('required', $states);
  }

}

/**
 * Minimal form wrapping a single pre-prepared ranking element.
 */
class WebformRankingRequiredIndicationDummyForm implements FormInterface {

  public function __construct(protected array $element) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_ranking_required_indication_dummy_form';
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
