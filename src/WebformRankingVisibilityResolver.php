<?php

namespace Drupal\webform_ranking;

use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Resolves which configured ranking items are currently visible/valid.
 *
 * This is the piece that closes the validation gap flagged in the
 * element/plugin classes: server-side validation must recompute the
 * visible-item set from the *submitted* value of any trigger element,
 * never trust DOM/state-based visibility from the client.
 *
 * Deliberately built on Webform's own conditions engine
 * (WebformSubmissionConditionsValidatorInterface) rather than a
 * hand-rolled #states evaluator, so a condition means exactly the same
 * thing here as it does everywhere else in Webform — same operators,
 * same edge cases, same bugs-if-any. Reimplementing that logic
 * independently would be a maintenance liability every time Webform
 * itself changes #states semantics.
 *
 * Verification note: the class/method existence
 * (WebformSubmissionConditionsValidator::validateConditions()) is
 * confirmed via a real stack trace from a Drupal.org issue, but the
 * exact conditions-array shape and return type were not verified
 * against current Webform source in this pass. Confirm against your
 * installed Webform version's WebformSubmissionConditionsValidator
 * class before relying on this in production — if the call shape is
 * slightly different, this is the one place to fix it.
 */
class WebformRankingVisibilityResolver {

  /**
   * The Webform conditions validator service.
   *
   * @var \Drupal\webform\WebformSubmissionConditionsValidatorInterface
   */
  protected $conditionsValidator;

  public function __construct(WebformSubmissionConditionsValidatorInterface $conditions_validator) {
    $this->conditionsValidator = $conditions_validator;
  }

  /**
   * Returns the item values currently visible/applicable.
   *
   * @param array $items
   *   The element's configured #items array (value/label/states each).
   * @param \Drupal\webform\WebformSubmissionInterface|null $webform_submission
   *   The in-progress submission, reflecting values entered so far in
   *   this request. NULL when no submission context is available
   *   (e.g. called outside a normal Webform submission form) — in that
   *   case this resolves permissively (everything visible), since
   *   failing closed here would break the element anywhere it's used
   *   outside the exact context this was built for, and the caller's
   *   own tamper-defense (unknown-item check against the *full*
   *   configured set) is unaffected either way.
   *
   * @return string[]
   *   Item 'value' keys currently visible/applicable.
   */
  public function resolveVisibleItemValues(array $items, ?WebformSubmissionInterface $webform_submission): array {
    if (!$webform_submission) {
      return array_column($items, 'value');
    }

    $visible = [];
    foreach ($items as $item) {
      if (empty($item['states'])) {
        // No condition configured for this item: always visible.
        $visible[] = $item['value'];
        continue;
      }
      if ($this->conditionsValidator->validateConditions($item['states'], $webform_submission)) {
        $visible[] = $item['value'];
      }
    }
    return $visible;
  }

}
