<?php

namespace Drupal\Tests\webform_ranking\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webform_ranking\WebformRankingConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(WebformRankingConverter::class)]
#[Group('webform_ranking')]
class WebformRankingConverterTest extends UnitTestCase {

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

  // A duplicate rank (two items both claiming rank '1') is exactly the
  // kind of tampered/forged input the converter deliberately does NOT
  // reject — it silently collapses to one winner here, and
  // validateWebformRanking() catches the duplication independently by
  // comparing counts against array_unique(). This test documents that
  // division of responsibility rather than asserting a specific winner
  // (which one wins is an implementation detail, not a contract).
  public function testMatrixToCanonicalDuplicateRankCollapsesToOneEntry(): void {
    $raw = [
      'item_a' => '1',
      'item_b' => '1',
    ];

    $result = WebformRankingConverter::matrixToCanonical($raw);

    $this->assertCount(1, $result['values']);
  }

  public function testMatrixToCanonicalWithNoInputReturnsEmptyArrays(): void {
    $result = WebformRankingConverter::matrixToCanonical([]);

    $this->assertSame([], $result['values']);
    $this->assertSame([], $result['na']);
  }

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

  // An item present in neither values nor na (e.g. conditionally
  // hidden, or simply not yet answered) must be absent from the
  // result entirely — not present with a NULL or empty-string rank —
  // so the matrix row correctly renders with nothing pre-selected.
  public function testCanonicalToMatrixOmitsUnaccountedItems(): void {
    $result = WebformRankingConverter::canonicalToMatrix([
      'values' => ['item_a'],
      'na' => [],
    ]);

    $this->assertArrayHasKey('item_a', $result);
    $this->assertArrayNotHasKey('item_b', $result);
  }

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

  public function testDragdropToCanonicalParsesCommaSeparatedValues(): void {
    $raw = [
      'order' => 'item_a,item_c',
      'na' => 'item_b',
    ];

    $result = WebformRankingConverter::dragdropToCanonical($raw);

    $this->assertSame(['item_a', 'item_c'], $result['values']);
    $this->assertSame(['item_b'], $result['na']);
  }

  public function testDragdropToCanonicalTrimsWhitespaceAroundValues(): void {
    $raw = [
      'order' => ' item_a , item_c ',
      'na' => '',
    ];

    $result = WebformRankingConverter::dragdropToCanonical($raw);

    $this->assertSame(['item_a', 'item_c'], $result['values']);
  }

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

  // A stray trailing/leading/doubled comma (e.g. "item_a,,item_c")
  // must not produce an empty-string "item".
  public function testDragdropToCanonicalFiltersOutEmptySegments(): void {
    $result = WebformRankingConverter::dragdropToCanonical([
      'order' => 'item_a,,item_c,',
      'na' => '',
    ]);

    $this->assertSame(['item_a', 'item_c'], $result['values']);
  }

  public function testCanonicalToDragdropRoundTrip(): void {
    $canonical = [
      'values' => ['item_a', 'item_c'],
      'na' => ['item_b'],
    ];

    $result = WebformRankingConverter::canonicalToDragdrop($canonical);

    $this->assertSame('item_a,item_c', $result['order']);
    $this->assertSame('item_b', $result['na']);
  }

  public function testAccountedForMergesValuesAndNa(): void {
    $canonical = [
      'values' => ['item_a', 'item_c'],
      'na' => ['item_b'],
    ];

    $result = WebformRankingConverter::accountedFor($canonical);

    $this->assertEqualsCanonicalizing(['item_a', 'item_b', 'item_c'], $result);
  }

  public function testAccountedForWithMissingKeysReturnsEmptyArray(): void {
    $this->assertSame([], WebformRankingConverter::accountedFor([]));
  }

  protected function items(): array {
    return [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
      ['value' => 'item_c', 'label' => 'Item C'],
    ];
  }

  public function testOrderByRankSortsRankedItemsByRank(): void {
    // Ranked out of configured order (item_c=1st, item_a=2nd,
    // item_b=3rd) — result must follow the ranking, not config order.
    $value = ['item_a' => '2', 'item_b' => '3', 'item_c' => '1'];

    $result = WebformRankingConverter::orderByRank($this->items(), $value);

    $this->assertSame(['item_c', 'item_a', 'item_b'], array_column($result, 'value'));
  }

  public function testOrderByRankPlacesNaAfterRankedItems(): void {
    $value = ['item_a' => 'na', 'item_b' => '1'];

    $result = WebformRankingConverter::orderByRank($this->items(), $value);

    // item_b (ranked) first, item_a (na) second, item_c (unaccounted) last.
    $this->assertSame(['item_b', 'item_a', 'item_c'], array_column($result, 'value'));
  }

  // An item present in neither values nor na (never accounted for —
  // conditionally hidden when submitted, or added to configuration
  // since) has no rank to sort by, so it's appended last, in its
  // originally configured order relative to other unaccounted items.
  public function testOrderByRankAppendsUnaccountedItemsLastInConfiguredOrder(): void {
    $value = ['item_b' => '1'];

    $result = WebformRankingConverter::orderByRank($this->items(), $value);

    $this->assertSame(['item_b', 'item_a', 'item_c'], array_column($result, 'value'));
  }

  public function testOrderByRankWithNoValueAtAllPreservesConfiguredOrder(): void {
    $result = WebformRankingConverter::orderByRank($this->items(), []);

    $this->assertSame(['item_a', 'item_b', 'item_c'], array_column($result, 'value'));
  }

  // A value referencing an item that's no longer in the configured
  // item set (e.g. removed from configuration after this submission
  // was made) must be silently skipped, not surfaced as a phantom row
  // or a crash — the reordered list can only ever contain currently
  // configured items.
  public function testOrderByRankIgnoresValuesForItemsNoLongerConfigured(): void {
    $value = ['item_a' => '1', 'item_removed' => '2'];

    $result = WebformRankingConverter::orderByRank($this->items(), $value);

    $this->assertSame(['item_a', 'item_b', 'item_c'], array_column($result, 'value'));
  }

  public function testOrderByRankPreservesFullItemData(): void {
    $result = WebformRankingConverter::orderByRank($this->items(), ['item_a' => '1']);

    $this->assertSame(['value' => 'item_a', 'label' => 'Item A'], $result[0]);
  }

  public function testMatrixRanksAreSequentialWithNoRanksAtAllIsTrue(): void {
    $this->assertTrue(WebformRankingConverter::matrixRanksAreSequential([]));
  }

  // N/A entries alone (nothing numerically ranked) is vacuously
  // sequential — there's no numeric rank to have a gap in.
  public function testMatrixRanksAreSequentialWithOnlyNaEntriesIsTrue(): void {
    $raw = ['item_a' => 'na', 'item_b' => 'na'];

    $this->assertTrue(WebformRankingConverter::matrixRanksAreSequential($raw));
  }

  public function testMatrixRanksAreSequentialWithDenseRanksIsTrue(): void {
    $raw = ['item_a' => '1', 'item_b' => '2', 'item_c' => '3'];

    $this->assertTrue(WebformRankingConverter::matrixRanksAreSequential($raw));
  }

  // The exact reported bug: 2nd and 3rd used, but nothing ranked 1st.
  public function testMatrixRanksAreSequentialRejectsSkippedFirstRank(): void {
    $raw = ['item_a' => 'na', 'item_b' => '2', 'item_c' => '3'];

    $this->assertFalse(WebformRankingConverter::matrixRanksAreSequential($raw));
  }

  // A single item ranked '2', with nothing else ranked at all — the
  // simplest possible case of the same underlying gap.
  public function testMatrixRanksAreSequentialRejectsSingleNonFirstRank(): void {
    $this->assertFalse(WebformRankingConverter::matrixRanksAreSequential(['item_a' => '2']));
  }

  public function testMatrixRanksAreSequentialRejectsGapInMiddle(): void {
    // 1st and 3rd used, 2nd skipped.
    $raw = ['item_a' => '1', 'item_b' => '3'];

    $this->assertFalse(WebformRankingConverter::matrixRanksAreSequential($raw));
  }

  public function testMatrixRanksAreSequentialWithPartialRankingUsingRequiredAllFalseStillRequiresSequential(): void {
    // Only one item ranked (item_c left completely blank, not even
    // na) — still must start at 1, regardless of #required_all.
    $raw = ['item_a' => '2'];

    $this->assertFalse(WebformRankingConverter::matrixRanksAreSequential($raw));
  }

  // Duplicate ranks collapse to one distinct value here — that's a
  // separate rule (validateWebformRanking()'s own duplicate check),
  // not this method's concern. Two items both at rank '1' is
  // trivially sequential on its own.
  public function testMatrixRanksAreSequentialIgnoresDuplicateRanks(): void {
    $raw = ['item_a' => '1', 'item_b' => '1'];

    $this->assertTrue(WebformRankingConverter::matrixRanksAreSequential($raw));
  }

}
