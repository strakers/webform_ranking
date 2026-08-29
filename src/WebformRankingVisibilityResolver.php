<?php

namespace Drupal\webform_ranking;

use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves which configured ranking items are currently visible/valid.
 *
 * Server-side re-validation of item visibility from the submitted
 * value — never trusts client-side DOM/state visibility. Built on
 * Webform's own conditions engine, not a hand-rolled evaluator; see
 * docs/adr/0001-visibility-resolver-conditions-engine.md for why, and
 * for the fail-open/fail-closed behavior when a condition can't be
 * resolved.
 */
class WebformRankingVisibilityResolver {

  /**
   * The Webform conditions validator service.
   *
   * @var \Drupal\webform\WebformSubmissionConditionsValidatorInterface
   */
  protected $conditionsValidator;

  /**
   * The webform_ranking logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(WebformSubmissionConditionsValidatorInterface $conditions_validator, LoggerInterface $logger) {
    $this->conditionsValidator = $conditions_validator;
    $this->logger = $logger;
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
   *   a Webform submission form entirely, or from a test harness
   *   building a bare FormState directly — see class docblock for the
   *   fail-closed behaviour that applies in that case.
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
        // fail closed. See class-level note.
        continue;
      }
      if ($this->isVisible($item['value'], $item['states'], $webform_submission)) {
        $visible[] = $item['value'];
      }
    }
    return $visible;
  }

  /**
   * Evaluates one item's #states-shaped condition array against $submission.
   *
   * @param string $item_value
   *   The item's storage key, for the logged warning on an unresolvable
   *   state.
   * @param array $states
   *   The item's configured 'states' array, e.g.
   *   ['visible' => [selector => condition]].
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The submission to evaluate the condition against.
   *
   * @return bool
   *   TRUE if the item should be visible.
   */
  protected function isVisible(string $item_value, array $states, WebformSubmissionInterface $webform_submission): bool {
    foreach ($states as $state => $conditions) {
      // Only visibility states govern item inclusion — 'required',
      // 'enabled', etc. (also valid #states keys elsewhere in Webform)
      // are meaningless for an item's presence in the ranking and are
      // ignored here.
      [$base] = explode('-', ltrim((string) $state, '!'), 2);
      if (!in_array($base, ['visible', 'invisible'], TRUE)) {
        continue;
      }

      $result = $this->conditionsValidator->validateState($state, $conditions, $webform_submission);
      if ($result === NULL) {
        // Unresolvable selector/element: fail open, log it. See class
        // docblock for why this direction was chosen over fail-closed.
        $this->logger->warning('Webform Ranking item %item: could not resolve #states condition (state: %state) — treating item as visible.', [
          '%item' => $item_value,
          '%state' => $state,
        ]);
        continue;
      }
      if (!$result) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
