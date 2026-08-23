<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormState;
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
    // Needed for prepare() (see WebformElementBase::prepare()), which
    // reads webform.settings:element.allowed_tags — unset, that's NULL,
    // and passing NULL to preg_split() there triggers a PHP
    // deprecation. Setting the one key directly rather than
    // installConfig(['webform']): the latter pulls in webform's full
    // default config, including config *entities* (e.g. the 'contact'
    // webform) that need entity schema this minimal test doesn't
    // install, and fails on a missing DB table.
    \Drupal::configFactory()->getEditable('webform.settings')
      ->set('element.allowed_tags', 'admin')
      ->save();
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
   * Runs validateConfigurationForm() against a hand-built 'items' value.
   *
   * 'default_properties' must be set on FormState before calling — the
   * parent implementation's getConfigurationFormProperties() calls
   * array_key_exists() against it, which fatals on a NULL second
   * argument if it's left unset.
   */
  protected function validateItemsConfiguration(array $items): FormState {
    $form_state = new FormState();
    $form_state->set('default_properties', $this->plugin->getDefaultProperties());
    $form_state->setValue('items', $items);

    $form = [];
    $this->plugin->validateConfigurationForm($form, $form_state);

    return $form_state;
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

  /**
   * Tests that getItemRankValue() returns the stored scalar rank.
   */
  public function testGetItemRankValueReturnsStoredScalarRank(): void {
    $data = ['item_a' => '2', 'item_b' => 'na'];

    $this->assertSame('2', $this->plugin->getItemRankValue($data, 'item_a'));
    $this->assertSame('na', $this->plugin->getItemRankValue($data, 'item_b'));
  }

  /**
   * Tests that getItemRankValue() returns NULL for an absent item.
   */
  public function testGetItemRankValueReturnsNullWhenItemAbsent(): void {
    $this->assertNull($this->plugin->getItemRankValue([], 'item_a'));
  }

  /**
   * Tests that a non-scalar stored value returns NULL, not itself.
   *
   * Defensive per the method's own docblock: a submission that never
   * touched this element, or malformed stored data, shouldn't produce
   * a value a caller could mistake for a real rank.
   */
  public function testGetItemRankValueReturnsNullForNonScalarValue(): void {
    $data = ['item_a' => ['unexpectedly' => 'an array']];

    $this->assertNull($this->plugin->getItemRankValue($data, 'item_a'));
  }

  /**
   * Tests that getTestValues() returns NULL when no items are configured.
   */
  public function testGetTestValuesReturnsNullWhenNoItemsConfigured(): void {
    $webform = $this->createMock(WebformInterface::class);

    $this->assertNull($this->plugin->getTestValues(['#items' => []], $webform));
  }

  /**
   * Tests that getTestValues() returns a full ranking in storage shape.
   *
   * Without 'random' => TRUE, order is deterministic (configured
   * order), so the exact rank assignment can be asserted directly,
   * rather than just checking it's *a* valid permutation.
   */
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

  /**
   * Tests that a random order still produces a valid full ranking.
   *
   * Not a statistical test of randomness itself — just confirms that
   * requesting a random order still produces a valid full ranking
   * (every item accounted for, ranks 1..3 each used exactly once),
   * since a broken shuffle could easily drop or duplicate a rank.
   */
  public function testGetTestValuesRandomOrderStillProducesValidFullRanking(): void {
    $webform = $this->createMock(WebformInterface::class);
    $element = ['#items' => $this->items()];

    $result = $this->plugin->getTestValues($element, $webform, ['random' => TRUE]);
    $value = reset($result);

    $this->assertEqualsCanonicalizing(['item_a', 'item_b', 'item_c'], array_keys($value));
    $this->assertEqualsCanonicalizing(['1', '2', '3'], array_values($value));
  }

  /**
   * Tests that N/A resolves to the configured N/A label.
   */
  public function testResolveRankDisplayForNaUsesConfiguredNaLabel(): void {
    $element = ['#na_label' => 'Not Applicable'];

    $result = $this->invokePrivate('resolveRankDisplay', [$element, [], 'na']);

    $this->assertSame('Not Applicable', (string) $result);
  }

  /**
   * Tests that a numeric rank resolves to its configured rank label.
   */
  public function testResolveRankDisplayForNumericRankUsesRankLabel(): void {
    $rank_labels = ['1st', '2nd', '3rd'];

    // Rank '2' reads rank_labels[1] — 0-indexed per getRankLabels().
    $result = $this->invokePrivate('resolveRankDisplay', [[], $rank_labels, '2']);

    $this->assertSame('2nd', (string) $result);
  }

  /**
   * Tests that an unaccounted-for item resolves to "Not ranked".
   */
  public function testResolveRankDisplayForUnaccountedItemReturnsNotRanked(): void {
    $result = $this->invokePrivate('resolveRankDisplay', [[], ['1st', '2nd'], NULL]);

    $this->assertSame('Not ranked', (string) $result);
  }

  /**
   * Tests that an out-of-range rank degrades to "not ranked".
   *
   * An out-of-range rank (e.g. a rank_labels array shrunk after items
   * were removed from configuration) must degrade to "not ranked"
   * rather than an undefined-index warning or a crash.
   */
  public function testResolveRankDisplayForOutOfRangeRankReturnsNotRanked(): void {
    $result = $this->invokePrivate('resolveRankDisplay', [[], ['1st'], '5']);

    $this->assertSame('Not ranked', (string) $result);
  }

  /**
   * Tests that a per-item selector resolves to that item's rank.
   *
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

  /**
   * Tests that a not-yet-ranked item's selector resolves to NULL.
   */
  public function testGetElementSelectorInputValueReturnsNullForItemNotYetRanked(): void {
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);
    $webform_submission->method('getElementData')->willReturn(['pizza' => '1']);

    $element = ['#webform_key' => 'preference', '#ranking_style' => 'matrix'];

    $this->assertNull(
      $this->plugin->getElementSelectorInputValue(':input[name="preference[matrix][burgers]"]', 'value', $element, $webform_submission)
    );
  }

  /**
   * Tests that a non-rank dragdrop selector defers to parent element.
   *
   * A dragdrop selector that isn't the "rank" echo input (e.g. the
   * real 'order' field itself) must defer to the parent implementation
   * rather than being misinterpreted as a per-item rank selector.
   */
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
   * Tests that a dragdrop per-item rank selector resolves correctly.
   *
   * Drag/drop's per-item rank echo input (see
   * WebformRanking::buildDragDrop() and this plugin's
   * getElementSelectorInputsOptions()) must resolve identically to a
   * matrix per-item selector — same underlying flat storage, just a
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

  /**
   * Tests that per-item selectors are exposed for both display styles.
   *
   * Selectors now nest under a grouped title (the base class's own
   * getElementSelectorOptions() does this whenever
   * getElementSelectorInputsOptions() returns a non-empty array — see
   * that method's docblock), rather than sitting as flat top-level
   * entries. The title is computed via the same plugin methods the
   * base class itself uses, rather than hardcoded, so this test
   * doesn't go brittle against label/translation wording changes.
   */
  public function testGetElementSelectorOptionsExposesPerItemSelectorsForBothStyles(): void {
    $element = [
      '#webform_key' => 'preference',
      '#title' => 'Preference',
      '#items' => $this->items(),
    ];
    $expected_title = $this->plugin->getAdminLabel($element) . ' [' . $this->plugin->getPluginLabel() . ']';

    $matrix_selectors = $this->plugin->getElementSelectorOptions($element + ['#ranking_style' => 'matrix']);
    $this->assertArrayHasKey($expected_title, $matrix_selectors);
    $this->assertArrayHasKey(':input[name="preference[matrix][item_a]"]', $matrix_selectors[$expected_title]);
    $this->assertArrayNotHasKey(':input[name="preference[dragdrop][rank][item_a]"]', $matrix_selectors[$expected_title]);

    $dragdrop_selectors = $this->plugin->getElementSelectorOptions($element + ['#ranking_style' => 'dragdrop']);
    $this->assertArrayHasKey(':input[name="preference[dragdrop][rank][item_a]"]', $dragdrop_selectors[$expected_title]);
    $this->assertArrayNotHasKey(':input[name="preference[matrix][item_a]"]', $dragdrop_selectors[$expected_title]);
  }

  /**
   * Tests that no bogus whole-element selector is offered.
   *
   * Regression guard for H2: before overriding
   * getElementSelectorInputsOptions(), this element fell into
   * WebformElementBase::getElementSelectorOptions()'s other branch and
   * returned a scalar `:input[name="preference"]` selector alongside
   * the real per-item ones — a selector matching no real DOM input,
   * since the element only ever renders as per-item radios/hidden
   * inputs, never a single scalar field. Kept as its own test (rather
   * than folded into the coverage above) so the exact bug this closes
   * stays unambiguous even if that test's assertions are later
   * reworked.
   */
  public function testGetElementSelectorOptionsDoesNotIncludeBogusWholeElementSelector(): void {
    $element = [
      '#webform_key' => 'preference',
      '#title' => 'Preference',
      '#items' => $this->items(),
      '#ranking_style' => 'matrix',
    ];

    $selectors = $this->plugin->getElementSelectorOptions($element);

    $this->assertArrayNotHasKey(':input[name="preference"]', $selectors);
    foreach ($selectors as $title => $value) {
      $this->assertIsArray($value, "Entry '$title' must be a grouped array of selectors, not a flat scalar title.");
    }
  }

  /**
   * Tests that a syntactically valid items configuration passes.
   */
  public function testValidateConfigurationFormAcceptsValidItemValues(): void {
    $form_state = $this->validateItemsConfiguration($this->items());

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests that an item value with disallowed characters is rejected.
   *
   * H1: item values are interpolated directly into #states selector
   * strings (see getElementSelectorInputsOptions()) and stored in the
   * webform_submission_data.property column. An unconstrained value
   * like 'pizza"' can produce a broken/unparseable selector.
   */
  public function testValidateConfigurationFormRejectsItemValueWithInvalidCharacters(): void {
    $form_state = $this->validateItemsConfiguration([
      ['value' => 'pizza"', 'label' => 'Pizza'],
      ['value' => 'burgers', 'label' => 'Burgers'],
    ]);

    $this->assertArrayHasKey('items', $form_state->getErrors());
  }

  /**
   * Tests that an item value over 128 characters is rejected.
   *
   * 128 is not arbitrary: item values become the
   * webform_submission_data.property column, a varchar(128) that is
   * also part of the primary key. Webform's own Likert element applies
   * the same limit via #options_value_maxlength.
   */
  public function testValidateConfigurationFormRejectsItemValueExceedingMaxLength(): void {
    $form_state = $this->validateItemsConfiguration([
      ['value' => str_repeat('a', 129), 'label' => 'Too long'],
      ['value' => 'burgers', 'label' => 'Burgers'],
    ]);

    $this->assertArrayHasKey('items', $form_state->getErrors());
  }

  /**
   * Tests that duplicate item values are rejected.
   *
   * Baseline coverage for a pre-existing check that had no test of its
   * own before this — added alongside the new H1 checks since this
   * method was already being touched.
   */
  public function testValidateConfigurationFormRejectsDuplicateItemValues(): void {
    $form_state = $this->validateItemsConfiguration([
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_a', 'label' => 'Item A again'],
    ]);

    $this->assertArrayHasKey('items', $form_state->getErrors());
  }

  /**
   * Tests that fewer than two items is rejected.
   *
   * Baseline coverage for a pre-existing check that had no test of its
   * own before this.
   */
  public function testValidateConfigurationFormRejectsFewerThanTwoItems(): void {
    $form_state = $this->validateItemsConfiguration([
      ['value' => 'item_a', 'label' => 'Item A'],
    ]);

    $this->assertArrayHasKey('items', $form_state->getErrors());
  }

  /**
   * Tests that prepare() decodes a string 'states' value into an array.
   *
   * Real bug: config saved before 'states' had '#decode_value' => TRUE
   * on the admin form (see form()'s docblock for that field) left
   * per-item 'states' as a raw YAML *string*. Left un-decoded,
   * buildMatrix()/buildDragDrop() hand that string directly to
   * '#states', and Drupal's FormHelper::processStates() JSON-encodes a
   * string exactly as readily as an array — producing a
   * data-drupal-states attribute states.js can't parse as a
   * conditions object, with no error anywhere. prepare() must decode
   * it back into a real array so already-saved config self-heals
   * without a manual data migration.
   */
  public function testPrepareDecodesStringItemStatesIntoArray(): void {
    $element = [
      '#items' => [
        [
          'value' => 'item_a',
          'label' => 'Item A',
          'states' => "invisible:\n  ':input[name=\"field\"]':\n    filled: true",
        ],
      ],
    ];

    $this->plugin->prepare($element);

    $this->assertSame(
      ['invisible' => [':input[name="field"]' => ['filled' => TRUE]]],
      $element['#items'][0]['states']
    );
  }

  /**
   * Tests that prepare() leaves an already-array 'states' untouched.
   *
   * Config saved *after* the '#decode_value' fix already has 'states'
   * as a real array — prepare() must leave it untouched rather than
   * double-processing (WebformYaml::decode() called on an already-array
   * value would be a type error).
   */
  public function testPrepareLeavesArrayItemStatesUntouched(): void {
    $states = ['visible' => [':input[name="field"]' => ['value' => 'yes']]];
    $element = [
      '#items' => [
        ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
      ],
    ];

    $this->plugin->prepare($element);

    $this->assertSame($states, $element['#items'][0]['states']);
  }

  /**
   * Tests that an empty-string 'states' decodes to an empty array.
   *
   * An item with no condition configured at all has 'states' as an
   * empty string (the admin never checked "use conditional
   * visibility") — must decode to an empty array, not an error, so
   * downstream `!empty($item['states'])` checks (buildMatrix()/
   * buildDragDrop(), WebformRankingVisibilityResolver) correctly treat
   * it as "no condition".
   */
  public function testPrepareDecodesEmptyStringItemStatesToEmptyArray(): void {
    $element = [
      '#items' => [
        ['value' => 'item_a', 'label' => 'Item A', 'states' => ''],
      ],
    ];

    $this->plugin->prepare($element);

    $this->assertSame([], $element['#items'][0]['states']);
  }

  /**
   * Tests that randomized order is stable across repeated prepare() calls.
   *
   * Real bug: shuffle() ran unseeded on every prepare() call —
   * including validation-error rebuilds and AJAX rebuilds within the
   * same form session — reordering the rows on every rebuild even
   * though the user's already-made selections stayed attached to
   * their items. Fixed by seeding PHP's RNG before shuffling (from the
   * submission's own UUID when one is available, so the order still
   * varies between different respondents; a fixed fallback seed
   * otherwise), so the order is stable across repeated calls.
   *
   * No WebformSubmissionInterface is constructed here deliberately:
   * parent::prepare() (WebformElementBase) reaches deep into Webform's
   * access-control machinery whenever a submission is present, which
   * would need extensive, version-fragile mocking to satisfy for a
   * test that isn't actually about access control. The NULL-submission
   * path exercises the exact same seed/shuffle/reseed block this fix
   * touches (see prepare()), just with the fallback seed source
   * instead of a submission UUID — sufficient to prove the fix: calls
   * are stable now, where they weren't before.
   */
  public function testPrepareRandomizedOrderIsStableAcrossRepeatedCalls(): void {
    $items = [];
    foreach (range('a', 'h') as $letter) {
      $items[] = ['value' => "item_$letter", 'label' => 'Item ' . strtoupper($letter)];
    }

    $element_first = ['#items' => $items, '#randomize_item_order' => TRUE];
    $this->plugin->prepare($element_first);
    $order_first = array_column($element_first['#items'], 'value');

    $element_second = ['#items' => $items, '#randomize_item_order' => TRUE];
    $this->plugin->prepare($element_second);
    $order_second = array_column($element_second['#items'], 'value');

    $this->assertSame($order_first, $order_second);
    // Confirms shuffling is actually happening (not a no-op that would
    // trivially "pass" the stability assertion above) — with 8 items,
    // the odds of a real shuffle coincidentally landing back on
    // configured order are 1 in 8! (40320), negligible.
    $this->assertNotSame(array_column($items, 'value'), $order_first);
  }

  /**
   * Tests decomposeItemStatesToConditions() for every trigger type.
   *
   * Covers every trigger \Drupal\webform\Element\WebformElementStates
   * ::getTriggerOptions() offers, confirming each round-trips through
   * decomposeCondition() correctly, including the nested
   * pattern/less/less_equal/greater/greater_equal/between/!between
   * shapes Form API represents one level deeper than a literal
   * value/!value comparison.
   */
  public function testDecomposeSingleConditionForEveryTriggerType(): void {
    $cases = [
      'value' => [['value' => 'x'], 'x'],
      '!value' => [['!value' => 'x'], 'x'],
      'pattern' => [['value' => ['pattern' => '^a']], '^a'],
      '!pattern' => [['value' => ['!pattern' => '^a']], '^a'],
      'less' => [['value' => ['less' => '5']], '5'],
      'less_equal' => [['value' => ['less_equal' => '5']], '5'],
      'greater' => [['value' => ['greater' => '5']], '5'],
      'greater_equal' => [['value' => ['greater_equal' => '5']], '5'],
      'between' => [['value' => ['between' => '1:5']], '1:5'],
      '!between' => [['value' => ['!between' => '1:5']], '1:5'],
      'empty' => [['empty' => TRUE], ''],
      'filled' => [['filled' => TRUE], ''],
      'checked' => [['checked' => TRUE], ''],
      'unchecked' => [['unchecked' => TRUE], ''],
    ];

    foreach ($cases as $trigger => [$condition, $expected_value]) {
      $states = ['visible' => [':input[name="a"]' => $condition]];
      $result = $this->invokePrivate('decomposeItemStatesToConditions', [$states]);
      $this->assertSame(
        [
          'mode' => 'visible',
          'operator' => 'and',
          'conditions' => [
            ['selector' => ':input[name="a"]', 'trigger' => $trigger, 'value' => $expected_value],
          ],
        ],
        $result,
        "Trigger '$trigger' did not decompose as expected."
      );
    }
  }

  /**
   * Tests decomposeItemStatesToConditions() for multi-condition shapes.
   *
   * AND (associative, no explicit operator), OR, and XOR (both
   * numerically-indexed with the literal operator string between
   * conditions) — the same shapes
   * \Drupal\webform\Element\WebformElementStates
   * ::convertElementValueToFormApiStates() itself produces.
   */
  public function testDecomposeMultiConditionShapes(): void {
    $and = [
      'visible' => [
        ':input[name="a"]' => ['value' => '1'],
        ':input[name="b"]' => ['value' => '2'],
      ],
    ];
    $this->assertSame('and', $this->invokePrivate('decomposeItemStatesToConditions', [$and])['operator']);
    $this->assertCount(2, $this->invokePrivate('decomposeItemStatesToConditions', [$and])['conditions']);

    $or = [
      'invisible' => [
      [':input[name="a"]' => ['value' => '1']],
        'or',
      [':input[name="b"]' => ['value' => '2']],
      ],
    ];
    $or_result = $this->invokePrivate('decomposeItemStatesToConditions', [$or]);
    $this->assertSame('invisible', $or_result['mode']);
    $this->assertSame('or', $or_result['operator']);
    $this->assertCount(2, $or_result['conditions']);

    $xor = [
      'visible' => [
      [':input[name="a"]' => ['value' => '1']],
        'xor',
      [':input[name="b"]' => ['value' => '2']],
        'xor',
      [':input[name="c"]' => ['checked' => TRUE]],
      ],
    ];
    $xor_result = $this->invokePrivate('decomposeItemStatesToConditions', [$xor]);
    $this->assertSame('xor', $xor_result['operator']);
    $this->assertCount(3, $xor_result['conditions']);
  }

  /**
   * Tests that unsupported shapes decompose to NULL, not corrupted data.
   *
   * A shape the condition-rows builder can't represent should fall back
   * to the raw YAML view, not silently corrupt or drop data. Multiple
   * states, mixed OR/XOR operators within one state, a trailing
   * operator, an unrecognized trigger, and a malformed (non-array)
   * condition value are all real shapes a hand-written YAML block could
   * contain.
   */
  public function testDecomposeReturnsNullForUnsupportedShapes(): void {
    $cases = [
      'multiple states' => [
        'visible' => [':input[name="a"]' => ['value' => '1']],
        'required' => [':input[name="a"]' => ['value' => '1']],
      ],
      'mixed or/xor operators' => [
        'visible' => [
        [':input[name="a"]' => ['value' => '1']],
          'or',
        [':input[name="b"]' => ['value' => '2']],
          'xor',
        [':input[name="c"]' => ['value' => '3']],
        ],
      ],
      'trailing operator' => [
        'visible' => [
        [':input[name="a"]' => ['value' => '1']],
          'or',
        ],
      ],
      'unrecognized trigger' => ['visible' => [':input[name="a"]' => ['bogus' => '1']]],
      'malformed condition value' => ['visible' => ':input[name="a"]'],
      // 'readonly' is a real Form API #states key (part of the "State"
      // optgroup WebformElementStates::getStateOptions() offers) but
      // isn't in self::PICKER_STATE_KEYS — the picker's dropdown only
      // offers a curated subset, see that constant's own docblock.
      'unrecognized state' => ['readonly' => [':input[name="a"]' => ['value' => '1']]],
    ];

    foreach ($cases as $label => $states) {
      $this->assertNull(
        $this->invokePrivate('decomposeItemStatesToConditions', [$states]),
        "Expected '$label' to be non-decomposable."
      );
    }
  }

}
