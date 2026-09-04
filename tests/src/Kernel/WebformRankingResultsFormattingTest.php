<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * GitHub #132 — the ranking element's results/Preview HTML must bold each
 * row's label, matching WebformLikert::formatHtmlItem()'s own 'list'-format
 * precedent. Builds a real Webform + WebformSubmission (via
 * WebformRankingKernelTestBase) and renders WebformRanking::formatHtml()
 * directly, rather than the narrower reflection-based coverage
 * WebformRankingPluginTest.php deliberately stops short of (its own
 * docblock flags formatHtmlItem() as needing exactly this heavier setup).
 */
#[Group('webform_ranking')]
class WebformRankingResultsFormattingTest extends WebformRankingKernelTestBase {

  /**
   * Builds a saved submission and returns its ranking element's rendered
   * HTML markup, for asserting on.
   */
  protected function renderRankingHtml(array $data): string {
    $webform = $this->createWebformWithElements('test_ranking_results_format', [
      'ranking' => [
        '#type' => 'webform_ranking',
        '#title' => 'Ranking',
        '#ranking_style' => 'matrix',
        '#items' => [
          ['value' => 'item_a', 'label' => 'Item A'],
          ['value' => 'item_b', 'label' => 'Item B'],
        ],
      ],
    ]);
    $submission = $this->createRealSubmission($webform, $data);

    $element = $webform->getElementsInitializedAndFlattened()['ranking'];
    $plugin = \Drupal::service('plugin.manager.webform.element')->createInstance('webform_ranking');
    $build = $plugin->formatHtml($element, $submission);

    return (string) \Drupal::service('renderer')->renderRoot($build);
  }

  /**
   * A ranked item's label must be bolded in the default (non-raw) format.
   */
  public function testFormatHtmlBoldsLabelForRankedItem(): void {
    $markup = $this->renderRankingHtml(['ranking' => ['item_a' => '1', 'item_b' => '2']]);

    $this->assertStringContainsString('<b>Item A:</b> 1st', $markup);
    $this->assertStringContainsString('<b>Item B:</b> 2nd', $markup);
  }

  /**
   * An unranked item's label is still bolded, alongside "Not ranked".
   */
  public function testFormatHtmlBoldsLabelForUnrankedItem(): void {
    $markup = $this->renderRankingHtml(['ranking' => ['item_a' => '1']]);

    $this->assertStringContainsString('<b>Item B:</b> Not ranked', $markup);
  }

}
