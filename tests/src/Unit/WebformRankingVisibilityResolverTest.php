<?php

namespace Drupal\Tests\webform_ranking\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_ranking\WebformRankingVisibilityResolver;

/**
 * @coversDefaultClass \Drupal\webform_ranking\WebformRankingVisibilityResolver
 * @group webform_ranking
 */
class WebformRankingVisibilityResolverTest extends UnitTestCase {

  /**
   * @covers ::resolveVisibleItemValues
   */
  public function testUnconditionalItemsAreAlwaysVisible(): void {
    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    // Should never be consulted for an item with no 'states' key at all.
    $conditionsValidator->expects($this->never())->method('validateConditions');

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $items = [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['item_a', 'item_b'], $result);
  }

  /**
   * @covers ::resolveVisibleItemValues
   */
  public function testConditionalItemVisibleWhenConditionEvaluatesTrue(): void {
    $states = ['visible' => [':input[name="trigger"]' => ['value' => 'student']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $conditionsValidator->expects($this->once())
      ->method('validateConditions')
      ->with($states, $submission)
      ->willReturn(TRUE);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator);

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['item_a'], $result);
  }

  /**
   * @covers ::resolveVisibleItemValues
   */
  public function testConditionalItemHiddenWhenConditionEvaluatesFalse(): void {
    $states = ['visible' => [':input[name="trigger"]' => ['value' => 'student']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $conditionsValidator->method('validateConditions')->willReturn(FALSE);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator);

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame([], $result);
  }

  /**
   * @covers ::resolveVisibleItemValues
   *
   * Mixed set: unconditional items always pass; conditional items are
   * evaluated independently and can land on either side.
   */
  public function testMixedConditionalAndUnconditionalItems(): void {
    $visibleStates = ['visible' => [':input[name="trigger"]' => ['value' => 'a']]];
    $hiddenStates = ['visible' => [':input[name="trigger"]' => ['value' => 'b']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $conditionsValidator->method('validateConditions')
      ->willReturnMap([
        [$visibleStates, $submission, TRUE],
        [$hiddenStates, $submission, FALSE],
      ]);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator);

    $items = [
      ['value' => 'always', 'label' => 'Always'],
      ['value' => 'shown', 'label' => 'Shown', 'states' => $visibleStates],
      ['value' => 'hidden', 'label' => 'Hidden', 'states' => $hiddenStates],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['always', 'shown'], $result);
  }

  /**
   * @covers ::resolveVisibleItemValues
   *
   * The fail-closed contract: with no submission context, any item
   * carrying a 'states' condition is excluded (there's no submitted
   * trigger data to evaluate it against), while unconditional items
   * are unaffected. This is deliberately the stricter of the two
   * possible fallbacks — see the class-level docblock for the
   * reasoning — so this test exists specifically to guard against a
   * future regression back toward the permissive default.
   */
  public function testFailsClosedWhenNoSubmissionContextIsAvailable(): void {
    $states = ['visible' => [':input[name="trigger"]' => ['value' => 'student']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    // Must never be called at all — there's nothing to evaluate against.
    $conditionsValidator->expects($this->never())->method('validateConditions');

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator);

    $items = [
      ['value' => 'always', 'label' => 'Always'],
      ['value' => 'conditional', 'label' => 'Conditional', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, NULL);

    $this->assertSame(['always'], $result);
  }

  /**
   * @covers ::resolveVisibleItemValues
   */
  public function testEmptyItemsReturnsEmptyArray(): void {
    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $resolver = new WebformRankingVisibilityResolver($conditionsValidator);

    $this->assertSame([], $resolver->resolveVisibleItemValues([], NULL));
  }

}
