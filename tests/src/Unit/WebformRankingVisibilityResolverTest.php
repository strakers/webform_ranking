<?php

namespace Drupal\Tests\webform_ranking\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_ranking\WebformRankingVisibilityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests WebformRankingVisibilityResolver against mocked collaborators.
 */
#[CoversClass(WebformRankingVisibilityResolver::class)]
#[Group('webform_ranking')]
class WebformRankingVisibilityResolverTest extends UnitTestCase {

  /**
   * Items with no 'states' key are always visible.
   */
  public function testUnconditionalItemsAreAlwaysVisible(): void {
    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    // Should never be consulted for an item with no 'states' key at all.
    $conditionsValidator->expects($this->never())->method('validateState');

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $items = [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['item_a', 'item_b'], $result);
  }

  /**
   * A 'visible' state that evaluates TRUE includes the item.
   */
  public function testConditionalItemVisibleWhenConditionEvaluatesTrue(): void {
    $conditions = [':input[name="trigger"]' => ['value' => 'student']];
    $states = ['visible' => $conditions];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $conditionsValidator->expects($this->once())
      ->method('validateState')
      ->with('visible', $conditions, $submission)
      ->willReturn(TRUE);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['item_a'], $result);
  }

  /**
   * A 'visible' state that evaluates FALSE excludes the item.
   */
  public function testConditionalItemHiddenWhenConditionEvaluatesFalse(): void {
    $conditions = [':input[name="trigger"]' => ['value' => 'student']];
    $states = ['visible' => $conditions];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $conditionsValidator->method('validateState')->willReturn(FALSE);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame([], $result);
  }

  /**
   * An 'invisible' state key is passed through to validateState() as-is.
   */
  public function testInvisibleStateIsPassedThroughToValidateState(): void {
    $conditions = [':input[name="trigger"]' => ['value' => 'student']];
    $states = ['invisible' => $conditions];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    // validateState() itself resolves the 'invisible' alias to negated
    // 'visible' internally (see WebformSubmissionConditionsValidator's
    // $aliases map) — the resolver just needs to pass the state key
    // through unmodified and trust the returned bool.
    $conditionsValidator->expects($this->once())
      ->method('validateState')
      ->with('invisible', $conditions, $submission)
      ->willReturn(FALSE);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame([], $result);
  }

  /**
   * A non-visibility state (e.g. 'required') doesn't govern item inclusion.
   */
  public function testNonVisibilityStatesAreIgnored(): void {
    $states = ['required' => [':input[name="trigger"]' => ['value' => 'yes']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    // 'required' doesn't govern item inclusion — must never be consulted.
    $conditionsValidator->expects($this->never())->method('validateState');

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['item_a'], $result);
  }

  /**
   * An unresolvable condition (e.g. a typo'd selector) fails open and logs.
   *
   * The item stays visible rather than silently dropping a respondent's
   * answer with no diagnostic anywhere.
   */
  public function testUnresolvableConditionFailsOpenAndLogsWarning(): void {
    $states = ['visible' => [':input[name="does_not_exist"]' => ['value' => 'x']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $conditionsValidator->method('validateState')->willReturn(NULL);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with($this->stringContains('could not resolve'), $this->callback(function (array $context) {
        return $context['%item'] === 'item_a' && $context['%state'] === 'visible';
      }));

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $logger);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $items = [
      ['value' => 'item_a', 'label' => 'Item A', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['item_a'], $result);
  }

  /**
   * Unconditional and conditional items in the same set evaluate independently.
   */
  public function testMixedConditionalAndUnconditionalItems(): void {
    $visibleConditions = [':input[name="trigger"]' => ['value' => 'a']];
    $hiddenConditions = [':input[name="trigger"]' => ['value' => 'b']];
    $visibleStates = ['visible' => $visibleConditions];
    $hiddenStates = ['visible' => $hiddenConditions];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $submission = $this->createMock(WebformSubmissionInterface::class);

    $conditionsValidator->method('validateState')
      ->willReturnMap([
        ['visible', $visibleConditions, $submission, TRUE],
        ['visible', $hiddenConditions, $submission, FALSE],
      ]);

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));

    $items = [
      ['value' => 'always', 'label' => 'Always'],
      ['value' => 'shown', 'label' => 'Shown', 'states' => $visibleStates],
      ['value' => 'hidden', 'label' => 'Hidden', 'states' => $hiddenStates],
    ];

    $result = $resolver->resolveVisibleItemValues($items, $submission);

    $this->assertSame(['always', 'shown'], $result);
  }

  /**
   * With no submission context, conditional items fail closed and are excluded.
   *
   * This is deliberately the stricter of the two possible fallbacks — see
   * the class-level docblock for the reasoning — so this test exists
   * specifically to guard against a future regression back toward the
   * permissive default.
   */
  public function testFailsClosedWhenNoSubmissionContextIsAvailable(): void {
    $states = ['visible' => [':input[name="trigger"]' => ['value' => 'student']]];

    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    // Must never be called at all — there's nothing to evaluate against.
    $conditionsValidator->expects($this->never())->method('validateState');

    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));

    $items = [
      ['value' => 'always', 'label' => 'Always'],
      ['value' => 'conditional', 'label' => 'Conditional', 'states' => $states],
    ];

    $result = $resolver->resolveVisibleItemValues($items, NULL);

    $this->assertSame(['always'], $result);
  }

  /**
   * An empty item list resolves to an empty visible set.
   */
  public function testEmptyItemsReturnsEmptyArray(): void {
    $conditionsValidator = $this->createMock(WebformSubmissionConditionsValidatorInterface::class);
    $resolver = new WebformRankingVisibilityResolver($conditionsValidator, $this->createMock(LoggerInterface::class));

    $this->assertSame([], $resolver->resolveVisibleItemValues([], NULL));
  }

}
