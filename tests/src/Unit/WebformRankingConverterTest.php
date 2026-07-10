<?php

namespace Drupal\Tests\webform_ranking\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webform_ranking\WebformRankingConverter;

/**
 * @coversDefaultClass \Drupal\webform_ranking\WebformRankingConverter
 * @group webform_ranking
 */
class WebformRankingConverterTest extends UnitTestCase {

  /**
   * @covers ::matrixToCanonical
   */
  public function testMatrixToCanonicalOrdersByRank(): void {
    $raw = [
      'item_a' => '2',
      'item_b' => '1',
      'item_c' => 'na',
    ];

    $result = WebformRankingConverter::matrixToCanonical($raw);

    $this->assertSame(['item_b', 'item_a'], $result['values']);
    $this->assertSame(['item_c'], $result['na']);
  }

  /**
   * @covers ::matrixToCanonical
   */
  public function testMatrixToCanonicalIgnoresEmptyAndInvalidEntries(): void {
    $raw = [
      'item_a' => '1',
      // Left blank (not yet interacted with, or optional and skipped).
      'item_b' => '',
      // Non-numeric garbage — normalization drops it rather than
      // erroring; validateWebformRanking() is what rejects bad data,
      // this converter's job is normalization only.
      'item_c' => 'not-a-rank',
      // Zero/negative are not valid ranks.
      'item_d' => '0',
      'item_e' => '-3',
    ];

    $result = WebformRankingConverter::matrixToCanonical($raw);

    $this->assertSame(['item_a'], $result['values']);
    $this->assertSame([], $result['na']);
  }

  /**
   * @covers ::matrixToCanonical
   *
   * A duplicate rank (two items both claiming rank '1') is exactly the
   * kind of tampered/forged input the converter deliberately does NOT
   * reject — it silently collapses to one winner here, and
   * validateWebformRanking() catches the duplication independently by
   * comparing counts against array_unique(). This test documents that
   * division of responsibility rather than asserting a specific winner
   * (which one wins is an implementation detail, not a contract).
   */
  public function testMatrixToCanonicalDuplicateRankCollapsesToOneEntry(): void {
    $raw = [
      'item_a' => '1',
      'item_b' => '1',
    ];

    $result = WebformRankingConverter::matrixToCanonical($raw);

    $this->assertCount(1, $result['values']);
  }

  /**
   * @covers ::matrixToCanonical
   */
  public function testMatrixToCanonicalWithNoInputReturnsEmptyArrays(): void {
    $result = WebformRankingConverter::matrixToCanonical([]);

    $this->assertSame([], $result['values']);
    $this->assertSame([], $result['na']);
  }

  /**
   * @covers ::canonicalToMatrix
   */
  public function testCanonicalToMatrixRoundTrip(): void {
    $canonical = [
      'values' => ['item_a', 'item_c'],
      'na' => ['item_b'],
    ];

    $result = WebformRankingConverter::canonicalToMatrix($canonical);

    $this->assertSame([
      'item_a' => '1',
      'item_c' => '2',
      'item_b' => 'na',
    ], $result);
  }

  /**
   * @covers ::canonicalToMatrix
   *
   * An item present in neither values nor na (e.g. conditionally
   * hidden, or simply not yet answered) must be absent from the
   * result entirely — not present with a NULL or empty-string rank —
   * so the matrix row correctly renders with nothing pre-selected.
   */
  public function testCanonicalToMatrixOmitsUnaccountedItems(): void {
    $result = WebformRankingConverter::canonicalToMatrix([
      'values' => ['item_a'],
      'na' => [],
    ]);

    $this->assertArrayHasKey('item_a', $result);
    $this->assertArrayNotHasKey('item_b', $result);
  }

  /**
   * @covers ::matrixToCanonical
   * @covers ::canonicalToMatrix
   */
  public function testMatrixRoundTripIsStable(): void {
    $canonical = [
      'values' => ['item_c', 'item_a', 'item_b'],
      'na' => [],
    ];

    $raw = WebformRankingConverter::canonicalToMatrix($canonical);
    $result = WebformRankingConverter::matrixToCanonical($raw);

    $this->assertSame($canonical['values'], $result['values']);
    $this->assertSame($canonical['na'], $result['na']);
  }

  /**
   * @covers ::dragdropToCanonical
   */
  public function testDragdropToCanonicalParsesCommaSeparatedValues(): void {
    $raw = [
      'order' => 'item_a,item_c',
      'na' => 'item_b',
    ];

    $result = WebformRankingConverter::dragdropToCanonical($raw);

    $this->assertSame(['item_a', 'item_c'], $result['values']);
    $this->assertSame(['item_b'], $result['na']);
  }

  /**
   * @covers ::dragdropToCanonical
   */
  public function testDragdropToCanonicalTrimsWhitespaceAroundValues(): void {
    $raw = [
      'order' => ' item_a , item_c ',
      'na' => '',
    ];

    $result = WebformRankingConverter::dragdropToCanonical($raw);

    $this->assertSame(['item_a', 'item_c'], $result['values']);
  }

  /**
   * @covers ::dragdropToCanonical
   */
  public function testDragdropToCanonicalHandlesEmptyAndMissingKeys(): void {
    $this->assertSame(
      ['values' => [], 'na' => []],
      WebformRankingConverter::dragdropToCanonical(['order' => '', 'na' => ''])
    );
    $this->assertSame(
      ['values' => [], 'na' => []],
      WebformRankingConverter::dragdropToCanonical([])
    );
  }

  /**
   * @covers ::dragdropToCanonical
   *
   * A stray trailing/leading/doubled comma (e.g. "item_a,,item_c")
   * must not produce an empty-string "item".
   */
  public function testDragdropToCanonicalFiltersOutEmptySegments(): void {
    $result = WebformRankingConverter::dragdropToCanonical([
      'order' => 'item_a,,item_c,',
      'na' => '',
    ]);

    $this->assertSame(['item_a', 'item_c'], $result['values']);
  }

  /**
   * @covers ::canonicalToDragdrop
   */
  public function testCanonicalToDragdropRoundTrip(): void {
    $canonical = [
      'values' => ['item_a', 'item_c'],
      'na' => ['item_b'],
    ];

    $result = WebformRankingConverter::canonicalToDragdrop($canonical);

    $this->assertSame('item_a,item_c', $result['order']);
    $this->assertSame('item_b', $result['na']);
  }

  /**
   * @covers ::accountedFor
   */
  public function testAccountedForMergesValuesAndNa(): void {
    $canonical = [
      'values' => ['item_a', 'item_c'],
      'na' => ['item_b'],
    ];

    $result = WebformRankingConverter::accountedFor($canonical);

    $this->assertEqualsCanonicalizing(['item_a', 'item_b', 'item_c'], $result);
  }

  /**
   * @covers ::accountedFor
   */
  public function testAccountedForWithMissingKeysReturnsEmptyArray(): void {
    $this->assertSame([], WebformRankingConverter::accountedFor([]));
  }

}
