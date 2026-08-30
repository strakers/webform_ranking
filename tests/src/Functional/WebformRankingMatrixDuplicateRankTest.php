<?php

namespace Drupal\Tests\webform_ranking\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression test for GitHub issue #104.
 *
 * A plain HTTP POST bypasses matrix.js entirely — proves the raw
 * duplicate-rank check holds server-side regardless of client
 * behavior, not just for the interactive hide/show path the
 * FunctionalJavascript coverage exercises. See
 * docs/adr/0019-matrix-duplicate-rank-detection.md.
 */
#[Group('webform_ranking')]
class WebformRankingMatrixDuplicateRankTest extends BrowserTestBase {

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
      'id' => 'test_ranking_dup_rank',
      'title' => 'Test ranking duplicate rank',
      'elements' => Yaml::encode([
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#required_all' => FALSE,
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * Two items forged to the same rank, no JS involved.
   */
  public function testForgedDuplicateRankIsRejected(): void {
    $this->drupalGet('/webform/test_ranking_dup_rank');
    $this->submitForm([
      'ranking[matrix][a]' => '1',
      'ranking[matrix][b]' => '2',
      'ranking[matrix][c]' => '2',
    ], 'Submit');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringNotContainsString('/confirmation', $this->getSession()->getCurrentUrl());
    $this->assertSession()->pageTextContains('share the same rank');
  }

}
