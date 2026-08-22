<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests cross-page per-item '#states' resolution — GitHub issue #61.
 *
 * The client-side "does the item actually get excluded/shown in a real
 * browser, across a real wizard-page navigation" behavior is out of scope
 * for a Kernel test (no real browser); this covers the server-side
 * resolution `WebformRanking::resolveCrossPageItemStates()` performs
 * during `processWebformRanking()` — that a cross-page item's condition
 * gets resolved once, statically, against the actual submission, and
 * applied as either an unconditional item (resolved visible) or an
 * inaccessible one (resolved hidden), never a live (and inert)
 * '#states' attachment pointing at a selector with nothing on the
 * current page to react to.
 *
 * Drives a real \Drupal::formBuilder()->buildForm() — not
 * WebformRankingKernelTestBase's own helpers, which call
 * validateWebformRanking() directly and sidestep FormBuilder/wizard-page
 * '#access' resolution entirely — because what's under test here only
 * happens as part of the real, full multi-page build pipeline.
 */
#[Group('webform_ranking')]
class WebformRankingCrossPageItemStatesTest extends WebformRankingKernelTestBase {

  /**
   * Builds the two-page wizard webform shared by both test cases below.
   */
  protected function createWizardWebform(): WebformInterface {
    return $this->createWebformWithElements('cross_page_items', [
      'pg_one' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Page One',
        'constituency' => [
          '#type' => 'select',
          '#title' => 'Constituency',
          '#options' => ['alumni' => 'Alumni', 'student' => 'Student'],
        ],
      ],
      'pg_two' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Page Two',
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'ab', 'label' => 'Item AB', 'states' => []],
            [
              'value' => 'uab',
              'label' => 'Item UAB',
              'states' => [
                'invisible' => [
                  ':input[name="constituency"]' => ['value' => 'alumni'],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
  }

  /**
   * Builds the wizard form, on page two, for the given prior page-one data.
   */
  protected function buildOnPageTwo(array $page_one_data): array {
    $webform = $this->createWizardWebform();
    $submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
      'data' => $page_one_data,
    ]);
    $submission->setCurrentPage('pg_two');

    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'add');
    $form_object->setEntity($submission);

    $form_state = new FormState();

    return \Drupal::formBuilder()->buildForm($form_object, $form_state);
  }

  /**
   * A cross-page condition resolving hidden excludes the item entirely.
   */
  public function testCrossPageItemResolvedHiddenIsInaccessible(): void {
    $form = $this->buildOnPageTwo(['constituency' => 'alumni']);

    $uab_label = $form['elements']['pg_two']['ranking']['matrix']['uab']['label'] ?? NULL;
    $uab_radio = $form['elements']['pg_two']['ranking']['matrix']['uab']['rank_1'] ?? NULL;

    $this->assertNotNull($uab_label);
    $this->assertFalse($uab_label['#access']);
    $this->assertNotNull($uab_radio);
    $this->assertFalse($uab_radio['#access']);
    // No live '#states' left on an item that's already been statically
    // resolved — there's nothing on this page that could ever change
    // 'constituency' again.
    $this->assertArrayNotHasKey('#states', $uab_label);
  }

  /**
   * A cross-page condition resolving visible renders the item normally.
   */
  public function testCrossPageItemResolvedVisibleRendersNormally(): void {
    $form = $this->buildOnPageTwo(['constituency' => 'student']);

    $uab_label = $form['elements']['pg_two']['ranking']['matrix']['uab']['label'] ?? NULL;
    $uab_radio = $form['elements']['pg_two']['ranking']['matrix']['uab']['rank_1'] ?? NULL;

    $this->assertNotNull($uab_label);
    $this->assertArrayNotHasKey('#access', $uab_label);
    $this->assertNotNull($uab_radio);
    $this->assertArrayNotHasKey('#access', $uab_radio);
    $this->assertArrayNotHasKey('#states', $uab_label);
  }

  /**
   * A same-page condition is completely unaffected by this fix.
   */
  public function testSamePageItemConditionUnaffected(): void {
    $webform = $this->createWebformWithElements('same_page_items', [
      'trigger' => [
        '#type' => 'select',
        '#title' => 'Trigger',
        '#options' => ['yes' => 'Yes', 'no' => 'No'],
      ],
      'ranking' => [
        '#type' => 'webform_ranking',
        '#title' => 'Ranking',
        '#ranking_style' => 'matrix',
        '#items' => [
          ['value' => 'a', 'label' => 'Item A', 'states' => []],
          [
            'value' => 'b',
            'label' => 'Item B',
            'states' => [
              'invisible' => [
                ':input[name="trigger"]' => ['value' => 'yes'],
              ],
            ],
          ],
        ],
      ],
    ]);
    $submission = WebformSubmission::create(['webform_id' => $webform->id()]);
    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'add');
    $form_object->setEntity($submission);
    $form_state = new FormState();

    $form = \Drupal::formBuilder()->buildForm($form_object, $form_state);

    $b_label = $form['elements']['ranking']['matrix']['b']['label'];
    $this->assertArrayNotHasKey('#access', $b_label);
    $this->assertSame(
      ['invisible' => [':input[name="trigger"]' => ['value' => 'yes']]],
      $b_label['#states']
    );
  }

}
