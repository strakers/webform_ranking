<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests conditional item visibility against a real conditions validator.
 *
 * WebformRankingVisibilityResolverTest (Unit) covers the resolver's
 * true/false/NULL evaluation logic in isolation with a mocked conditions
 * validator. This class covers what that can't: the live integration of
 * that logic through a real Webform + WebformSubmission and the real
 * webform_submission.conditions_validator service — the exact gap that let
 * B1 (validateConditions() called with the wrong array shape) ship without
 * a failing test, since every prior test mocked the collaborator.
 */
#[Group('webform_ranking')]
class WebformRankingConditionalItemTest extends WebformRankingKernelTestBase {

  /**
   * A standard two-item set where the second item carries $states.
   */
  protected function itemsWithCondition(array $states): array {
    return [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B', 'states' => $states],
    ];
  }

  /**
   * Builds a trigger+ranking webform and a real submission.
   *
   * The submission carries $trigger_value as the 'trigger' element's data.
   */
  protected function createTriggerWebform(string $id, string $trigger_value): array {
    $webform = $this->createWebformWithElements($id, [
      'trigger' => ['#type' => 'textfield', '#title' => 'Trigger'],
      'ranking' => ['#type' => 'webform_ranking', '#title' => 'Ranking'],
    ]);
    $submission = $this->createRealSubmission($webform, ['trigger' => $trigger_value]);

    return [$webform, $submission];
  }

  /**
   * The four-quadrant table: visible/invisible states × trigger match/no-match.
   */
  #[TestWith(['yes', 'visible', TRUE], 'visible state, trigger satisfied -> included')]
  #[TestWith(['no', 'visible', FALSE], 'visible state, trigger not satisfied -> excluded')]
  #[TestWith(['yes', 'invisible', FALSE], 'invisible state, trigger satisfied -> excluded')]
  #[TestWith(['no', 'invisible', TRUE], 'invisible state, trigger not satisfied -> included')]
  public function testConditionalItemVisibilityAgainstRealSubmission(string $trigger_value, string $state_key, bool $expect_included): void {
    [, $submission] = $this->createTriggerWebform('conditional_' . $state_key . '_' . $trigger_value, $trigger_value);

    $states = [$state_key => [':input[name="trigger"]' => ['value' => 'yes']]];

    $form_state = $this->validateAgainstRealSubmission(
      $submission,
      ['#items' => $this->itemsWithCondition($states)],
      ['values' => ['item_a', 'item_b'], 'na' => []]
    );

    $this->assertSame([], $form_state->getErrors());

    $expected = $expect_included ? ['item_a' => '1', 'item_b' => '2'] : ['item_a' => '1'];
    $this->assertSame($expected, $form_state->getValue('ranking'));
  }

  /**
   * The reported regression must submit cleanly.
   *
   * A conditional item whose condition IS satisfied, ranked 1st, with a
   * normal item ranked 2nd. Before the B1 fix, the resolver wrongly
   * excluded item_b regardless of the trigger, which dropped rank '1'
   * from the raw input the sequential-rank check reads — producing a
   * false "must be ranked in order" sequential-rank error.
   */
  public function testConditionSatisfiedConditionalItemRanked1stSubmitsCleanly(): void {
    [, $submission] = $this->createTriggerWebform('regression_gap', 'yes');

    $states = ['visible' => [':input[name="trigger"]' => ['value' => 'yes']]];

    $form_state = $this->validateMatrixInputAgainstRealSubmission(
      $submission,
      ['#items' => $this->itemsWithCondition($states)],
      ['item_b' => '1', 'item_a' => '2']
    );

    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(['item_b' => '1', 'item_a' => '2'], $form_state->getValue('ranking'));
  }

}
