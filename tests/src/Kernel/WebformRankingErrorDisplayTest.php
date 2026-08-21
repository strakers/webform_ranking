<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\webform_ranking\Element\WebformRanking as WebformRankingElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WebformRanking::preRenderWebformRanking(), for GitHub issues #47/#48.
 *
 * Called directly with a hand-built element rather than driven through a
 * real form submission — #errors/#validated population is core's own
 * FormValidator/FormErrorHandler machinery, not something this module
 * implements or needs to re-test; what's specific to this module is
 * purely what preRenderWebformRanking() does once those two properties are
 * present. The real end-to-end wiring (an actual failed submission through
 * a real page) is covered separately by
 * WebformRankingErrorDisplayJavaScriptTest.
 *
 * Asserts against '#wrapper_attributes', not '#attributes': this element
 * uses '#theme_wrappers' => ['form_element'], whose own template only
 * ever reads '#wrapper_attributes' for the rendered wrapping <div> — see
 * preRenderWebformRanking()'s own docblock for the full explanation of
 * why RenderElementBase::setAttributes() (which targets '#attributes')
 * couldn't be reused as-is here.
 */
#[Group('webform_ranking')]
class WebformRankingErrorDisplayTest extends KernelTestBase {

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
   * A minimal, otherwise-valid processed element.
   */
  protected function element(): array {
    return [
      '#type' => 'webform_ranking',
      '#title' => 'Ranking',
      '#id' => 'edit-ranking',
    ];
  }

  /**
   * No '#errors' at all: no error attributes, no inline error child.
   */
  public function testNoErrorsAddsNoErrorState(): void {
    $element = WebformRankingElement::preRenderWebformRanking($this->element());

    $this->assertArrayNotHasKey('ranking_errors', $element);
    $this->assertArrayNotHasKey('aria-invalid', $element['#wrapper_attributes'] ?? []);
    $this->assertNotContains('error', $element['#wrapper_attributes']['class'] ?? []);
  }

  /**
   * Errors set but not yet validated: no error state either.
   *
   * Mirrors RenderElementBase::setAttributes()'s own gating exactly — an
   * element mid-build (not yet through its own #element_validate pass)
   * must not be flagged as invalid.
   */
  public function testErrorsWithoutValidatedAddsNoErrorState(): void {
    $element = $this->element();
    $element['#parents'] = ['ranking'];
    $element['#errors'] = 'Ranking field is required.';

    $element = WebformRankingElement::preRenderWebformRanking($element);

    $this->assertArrayNotHasKey('ranking_errors', $element);
    $this->assertArrayNotHasKey('aria-invalid', $element['#wrapper_attributes'] ?? []);
  }

  /**
   * Errors and validated: full error state — attributes and inline text.
   */
  public function testErrorsWithValidatedAddsErrorState(): void {
    $element = $this->element();
    // '#parents' is one of RenderElementBase::setAttributes()'s own
    // gating conditions (isset($element['#parents']) && ...), mirrored
    // here — always present on a real, built element, but not implied
    // by this test's otherwise-minimal element() fixture.
    $element['#parents'] = ['ranking'];
    $element['#errors'] = 'Ranking: every item must be ranked.';
    $element['#validated'] = TRUE;

    $element = WebformRankingElement::preRenderWebformRanking($element);

    $this->assertContains('error', $element['#wrapper_attributes']['class']);
    $this->assertSame('true', $element['#wrapper_attributes']['aria-invalid']);
    $this->assertSame('Ranking: every item must be ranked.', $element['ranking_errors']['message']['#markup']);
    $this->assertContains('webform-ranking__errors', $element['ranking_errors']['#attributes']['class']);
  }

  /**
   * Base classes and wrapper id/selector are always added, error or not.
   *
   * Matches WebformCompositeBase::preRenderCompositeFormElement()'s own
   * '--wrapper' id-suffix convention.
   */
  public function testBaseAttributesAlwaysAdded(): void {
    $element = WebformRankingElement::preRenderWebformRanking($this->element());

    $this->assertContains('webform-ranking', $element['#wrapper_attributes']['class']);
    $this->assertContains('js-webform-ranking', $element['#wrapper_attributes']['class']);
    $this->assertSame('edit-ranking--wrapper', $element['#wrapper_attributes']['id']);
    $this->assertSame('edit-ranking--wrapper', $element['#wrapper_attributes']['data-drupal-selector']);
  }

}
