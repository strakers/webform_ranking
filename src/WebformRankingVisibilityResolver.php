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
 * never trust DOM/state-based visibility from the client. When no
 * submission context is available at all (see resolveVisibleItemValues()
 * for when that can happen), this resolver fails closed: conditional
 * items are treated as not visible rather than defaulting to visible.
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
   *   this request. In normal use — a live Webform submission form,
   *   an AJAX rebuild, a wizard step, the Test tab, a handler replaying
   *   a submission — this is essentially always present, since all of
   *   those go through WebformSubmissionForm.
   *
   *   NULL is only expected if this element is used standalone, outside
   *   a Webform submission form entirely (it's built as a decoupled
   *   Form API element specifically so that's possible), or from a
   *   test harness building a bare FormState directly. In that case
   *   this resolves FAIL-CLOSED: any item with a configured 'states'
   *   condition is treated as NOT visible, since there is no submitted
   *   trigger-element data to evaluate the condition against.
   *   Unconditional items (no 'states' key at all) are unaffected and
   *   remain visible. This means an element reused outside its
   *   intended Webform context will render/validate more strictly than
   *   its configuration implies — the safe direction for a fallback to
   *   err in — rather than silently ignoring all conditional item
   *   restrictions.
   *
   * @return string[]
   *   Item 'value' keys currently visible/applicable.
   */
  public function resolveVisibleItemValues(array $items, ?WebformSubmissionInterface $webform_submission): array {
    $visible = [];
    foreach ($items as $item) {
      if (empty($item['states'])) {
        // No condition configured for this item: always visible,
        // regardless of submission context.
        $visible[] = $item['value'];
        continue;
      }
      if (!$webform_submission) {
        // Conditional item, no submission context to evaluate against:
        // fail closed. See method-level note.
        continue;
      }
      if ($this->conditionsValidator->validateConditions($item['states'], $webform_submission)) {
        $visible[] = $item['value'];
      }
    }
    return $visible;
  }

}
