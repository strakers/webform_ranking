<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the WebformElementBase plugin directly.
 *
 * Covers the public/private methods added for results/CSV formatting
 * and Test-tab support: getItemRankValue(), getTestValues(), and
 * resolveRankDisplay() (private — invoked via reflection here rather
 * than made public just for testing, since it has no reason to be
 * part of the plugin's public API).
 *
 * Deliberately narrow scope: formatHtmlItem()/formatTextItem()
 * themselves (the actual rendered item list) aren't exercised here —
 * that needs a real Webform + WebformSubmission with stored data,
 * which is the same heavier Functional/Nightwatch-tier setup
 * CONTINUATION.md already flags as a known, deliberately deferred gap
 * for #process/rendering coverage. This test instead pins down the
 * logic those methods delegate to, which is what's actually at risk of
 * silently regressing.
 */
#[Group('webform_ranking')]
class WebformRankingPluginTest extends KernelTestBase {

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
    $this->plugin = \Drupal::service('plugin.manager.webform.element')->createInstance('webform_ranking');
  }

  /**
   * Standard 3-item configuration shared by most test cases.
   */
  protected function items(): array {
    return [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
      ['value' => 'item_c', 'label' => 'Item C'],
    ];
  }

  /**
   * Invokes a private method via reflection.
   *
   * Standard technique for pinning down private logic without
   * loosening its visibility just to make it testable.
   */
  protected function invokePrivate(string $method, array $args) {
    $reflection = new \ReflectionMethod($this->plugin, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($this->plugin, $args);
  }

  public function testGetItemRankValueReturnsStoredScalarRank(): void {
    $data = ['item_a' => '2', 'item_b' => 'na'];

    $this->assertSame('2', $this->plugin->getItemRankValue($data, 'item_a'));
    $this->assertSame('na', $this->plugin->getItemRankValue($data, 'item_b'));
  }

  public function testGetItemRankValueReturnsNullWhenItemAbsent(): void {
    $this->assertNull($this->plugin->getItemRankValue([], 'item_a'));
  }

  // Defensive per the method's own docblock: a submission that never
  // touched this element, or malformed stored data, shouldn't produce
  // a value a caller could mistake for a real rank.
  public function testGetItemRankValueReturnsNullForNonScalarValue(): void {
    $data = ['item_a' => ['unexpectedly' => 'an array']];

    $this->assertNull($this->plugin->getItemRankValue($data, 'item_a'));
  }

  public function testGetTestValuesReturnsNullWhenNoItemsConfigured(): void {
    $webform = $this->createMock(WebformInterface::class);

    $this->assertNull($this->plugin->getTestValues(['#items' => []], $webform));
  }

  // Without 'random' => TRUE, order is deterministic (configured
  // order), so the exact rank assignment can be asserted directly
  // rather than just checking it's *a* valid permutation.
  public function testGetTestValuesReturnsFullRankingInStorageShape(): void {
    $webform = $this->createMock(WebformInterface::class);
    $element = ['#items' => $this->items()];

    $result = $this->plugin->getTestValues($element, $webform, ['random' => FALSE]);

    // Wrapped in an outer array — see WebformLikert::getTestValues(),
    // the precedent this mirrors: WebformSubmissionGenerate::getTestValue()
    // treats the return as a list of candidate composite values.
    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertSame(
      ['item_a' => '1', 'item_b' => '2', 'item_c' => '3'],
      reset($result)
    );
  }

  // Not a statistical test of randomness itself — just confirms that
  // requesting a random order still produces a valid full ranking
  // (every item accounted for, ranks 1..3 each used exactly once),
  // since a broken shuffle could easily drop or duplicate a rank.
  public function testGetTestValuesRandomOrderIsStillAValidFullRanking(): void {
    $webform = $this->createMock(WebformInterface::class);
    $element = ['#items' => $this->items()];

    $result = $this->plugin->getTestValues($element, $webform, ['random' => TRUE]);
    $value = reset($result);

    $this->assertEqualsCanonicalizing(['item_a', 'item_b', 'item_c'], array_keys($value));
    $this->assertEqualsCanonicalizing(['1', '2', '3'], array_values($value));
  }

  public function testResolveRankDisplayForNaUsesConfiguredNaLabel(): void {
    $element = ['#na_label' => 'Not Applicable'];

    $result = $this->invokePrivate('resolveRankDisplay', [$element, [], 'na']);

    $this->assertSame('Not Applicable', (string) $result);
  }

  public function testResolveRankDisplayForNumericRankUsesRankLabel(): void {
    $rank_labels = ['1st', '2nd', '3rd'];

    // Rank '2' reads rank_labels[1] — 0-indexed per getRankLabels().
    $result = $this->invokePrivate('resolveRankDisplay', [[], $rank_labels, '2']);

    $this->assertSame('2nd', (string) $result);
  }

  public function testResolveRankDisplayForUnaccountedItemReturnsNotRanked(): void {
    $result = $this->invokePrivate('resolveRankDisplay', [[], ['1st', '2nd'], NULL]);

    $this->assertSame('Not ranked', (string) $result);
  }

  // An out-of-range rank (e.g. a rank_labels array shrunk after items
  // were removed from configuration) must degrade to "not ranked"
  // rather than an undefined-index warning or a crash.
  public function testResolveRankDisplayForOutOfRangeRankReturnsNotRanked(): void {
    $result = $this->invokePrivate('resolveRankDisplay', [[], ['1st'], '5']);

    $this->assertSame('Not ranked', (string) $result);
  }

  /**
   * Real bug this override fixes: without it, the server-side
   * conditions validator (WebformSubmissionConditionsValidator) hands
   * the whole flat storage map to checkConditionTrigger(), which then
   * does `(string) $element_value` on an array — a real "Array to
   * string conversion" PHP warning, confirmed via watchdog before this
   * fix. getElementSelectorInputValue() must instead resolve a single
   * item's rank via getItemRankValue(), the companion method this was
   * built for but never wired up until now.
   */
  public function testGetElementSelectorInputValueResolvesSingleItemRank(): void {
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);
    $webform_submission->method('getElementData')
      ->with('preference')
      ->willReturn(['pizza' => 'na', 'burgers' => '2', 'poutine' => '3']);

    $element = ['#webform_key' => 'preference', '#ranking_style' => 'matrix'];

    $this->assertSame(
      '2',
      $this->plugin->getElementSelectorInputValue(':input[name="preference[matrix][burgers]"]', 'value', $element, $webform_submission)
    );
    $this->assertSame(
      'na',
      $this->plugin->getElementSelectorInputValue(':input[name="preference[matrix][pizza]"]', 'value', $element, $webform_submission)
    );
  }

  public function testGetElementSelectorInputValueReturnsNullForItemNotYetRanked(): void {
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);
    $webform_submission->method('getElementData')->willReturn(['pizza' => '1']);

    $element = ['#webform_key' => 'preference', '#ranking_style' => 'matrix'];

    $this->assertNull(
      $this->plugin->getElementSelectorInputValue(':input[name="preference[matrix][burgers]"]', 'value', $element, $webform_submission)
    );
  }

  // A dragdrop selector that isn't the "rank" echo input (e.g. the
  // real 'order' field itself) must defer to the parent implementation
  // rather than being misinterpreted as a per-item rank selector.
  public function testGetElementSelectorInputValueDefersToParentForNonRankDragdropSelector(): void {
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);
    $webform_submission->method('getElementData')
      ->willReturn(['pizza' => '1', 'burgers' => '2', 'poutine' => 'na']);

    $element = ['#webform_key' => 'preference', '#ranking_style' => 'dragdrop'];

    // Parent's composite-key extraction looks for an 'order' key in
    // the flat storage map, which doesn't exist there (storage is
    // keyed by item value regardless of display style) — NULL is the
    // correct degrade, not a crash or the raw map.
    $this->assertNull(
      $this->plugin->getElementSelectorInputValue(':input[name="preference[dragdrop][order]"]', 'value', $element, $webform_submission)
    );
  }

  /**
   * Drag/drop's per-item rank echo input (see
   * WebformRanking::buildDragDrop() and this plugin's
   * getElementSelectorOptions()) must resolve identically to a matrix
   * per-item selector — same underlying flat storage, just a
   * differently-shaped selector pointing at it.
   */
  public function testGetElementSelectorInputValueResolvesDragdropItemRank(): void {
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);
    $webform_submission->method('getElementData')
      ->with('preference')
      ->willReturn(['pizza' => '1', 'burgers' => '2', 'poutine' => 'na']);

    $element = ['#webform_key' => 'preference', '#ranking_style' => 'dragdrop'];

    $this->assertSame(
      '1',
      $this->plugin->getElementSelectorInputValue(':input[name="preference[dragdrop][rank][pizza]"]', 'value', $element, $webform_submission)
    );
    $this->assertSame(
      'na',
      $this->plugin->getElementSelectorInputValue(':input[name="preference[dragdrop][rank][poutine]"]', 'value', $element, $webform_submission)
    );
  }

  public function testGetElementSelectorOptionsExposesPerItemSelectorsForBothStyles(): void {
    $element = [
      '#webform_key' => 'preference',
      '#title' => 'Preference',
      '#items' => $this->items(),
    ];

    $matrix_selectors = $this->plugin->getElementSelectorOptions($element + ['#ranking_style' => 'matrix']);
    $this->assertArrayHasKey(':input[name="preference[matrix][item_a]"]', $matrix_selectors);
    $this->assertArrayNotHasKey(':input[name="preference[dragdrop][rank][item_a]"]', $matrix_selectors);

    $dragdrop_selectors = $this->plugin->getElementSelectorOptions($element + ['#ranking_style' => 'dragdrop']);
    $this->assertArrayHasKey(':input[name="preference[dragdrop][rank][item_a]"]', $dragdrop_selectors);
    $this->assertArrayNotHasKey(':input[name="preference[matrix][item_a]"]', $dragdrop_selectors);
  }

}
