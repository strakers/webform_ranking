<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WebformRanking::getItemAtRank() against a real submission.
 *
 * GitHub #137 — must resolve the ranked item off a real
 * WebformSubmission, not just the pure array logic
 * WebformRankingConverter::getItemAtRank() already covers in
 * WebformRankingConverterTest (Unit tier). Builds a real Webform +
 * WebformSubmission (via WebformRankingKernelTestBase) and calls the
 * plugin method directly, matching WebformRankingResultsFormattingTest's
 * own pattern for exercising a plugin method against a saved submission.
 */
#[Group('webform_ranking')]
class WebformRankingGetItemAtRankTest extends WebformRankingKernelTestBase {

  /**
   * Builds a saved submission and returns its ranking element/plugin.
   *
   * @return array
   *   [$element, $webform_submission, $plugin].
   */
  protected function buildRankingSubmission(array $data): array {
    $webform = $this->createWebformWithElements('test_ranking_get_item_at_rank', [
      'ranking' => [
        '#type' => 'webform_ranking',
        '#title' => 'Ranking',
        '#ranking_style' => 'matrix',
        '#items' => [
          ['value' => 'item_a', 'label' => 'Item A'],
          ['value' => 'item_b', 'label' => 'Item B'],
          ['value' => 'item_c', 'label' => 'Item C'],
        ],
      ],
    ]);
    $submission = $this->createRealSubmission($webform, $data);

    $element = $webform->getElementsInitializedAndFlattened()['ranking'];
    $plugin = \Drupal::service('plugin.manager.webform.element')->createInstance('webform_ranking');

    return [$element, $submission, $plugin];
  }

  /**
   * Tests that the 1st-place item's full data is returned by default.
   */
  public function testGetItemAtRankDefaultsToFirstPlace(): void {
    [$element, $submission, $plugin] = $this->buildRankingSubmission([
      'ranking' => ['item_a' => '2', 'item_b' => '1', 'item_c' => '3'],
    ]);

    $result = $plugin->getItemAtRank($element, $submission);

    $this->assertSame(['value' => 'item_b', 'label' => 'Item B'], $result);
  }

  /**
   * Tests that a rank beyond 1st can be looked up explicitly.
   */
  public function testGetItemAtRankFindsAnyRankPosition(): void {
    [$element, $submission, $plugin] = $this->buildRankingSubmission([
      'ranking' => ['item_a' => '2', 'item_b' => '1', 'item_c' => '3'],
    ]);

    $result = $plugin->getItemAtRank($element, $submission, 3);

    $this->assertSame(['value' => 'item_c', 'label' => 'Item C'], $result);
  }

  /**
   * Tests that a submission which never touched the element returns NULL.
   */
  public function testGetItemAtRankWithNoDataReturnsNull(): void {
    [$element, $submission, $plugin] = $this->buildRankingSubmission([]);

    $this->assertNull($plugin->getItemAtRank($element, $submission));
  }

}
