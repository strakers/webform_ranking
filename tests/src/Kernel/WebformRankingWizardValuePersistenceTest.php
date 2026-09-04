<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\webform\Entity\WebformSubmission;
use PHPUnit\Framework\Attributes\Group;

/**
 * GitHub #129 — a ranking element's selections must survive wizard
 * "Previous" navigation (and, by extension, show correctly on Preview),
 * matching every other field type's behavior. Regression coverage for
 * ADR-0024: WebformRanking::defineDefaultProperties() unsetting
 * 'default_value' also silently defeated
 * WebformSubmissionForm::populateElements()'s only gate for repopulating
 * #default_value from saved submission data on any rebuild.
 */
#[Group('webform_ranking')]
class WebformRankingWizardValuePersistenceTest extends WebformRankingKernelTestBase {

  /**
   * Builds a two-page wizard: ranking + a plain field on page one, for a
   * submission whose data already has values saved from an earlier page
   * visit (i.e. exactly what a real "Previous" click's rebuild looks
   * like server-side).
   */
  protected function buildOnPage(string $page, array $data): array {
    $webform = $this->createWebformWithElements('test_ranking_persistence_' . $page, [
      'pg_one' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Page One',
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
        ],
        'plain_field' => [
          '#type' => 'textfield',
          '#title' => 'Plain field',
        ],
      ],
      'pg_two' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Page Two',
        'confirm' => [
          '#type' => 'checkbox',
          '#title' => 'Confirm',
        ],
      ],
    ]);

    $submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
      'data' => $data,
    ]);
    $submission->setCurrentPage($page);
    $submission->save();

    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'add');
    $form_object->setEntity($submission);
    $form_state = new FormState();

    return \Drupal::formBuilder()->buildForm($form_object, $form_state);
  }

  /**
   * Saved ranking + plain-field data must survive a rebuild of the page
   * they're on (simulating "Previous" navigation after having already
   * advanced past that page once).
   */
  public function testSavedDataSurvivesPageRebuild(): void {
    // Data shape saved into a submission is the flat matrix shape
    // (item value => rank), per WebformRankingConverter's own
    // storage-boundary docs — matching what validateWebformRanking()
    // actually writes via setValueForElement().
    $data = [
      'ranking' => ['a' => '1', 'b' => '2'],
      'plain_field' => 'hello',
    ];
    $form = $this->buildOnPage('pg_one', $data);

    $this->assertSame('hello', $form['elements']['pg_one']['plain_field']['#default_value']);

    $ranking = $form['elements']['pg_one']['ranking'];
    $this->assertSame('1', $ranking['matrix']['a']['rank_1']['#default_value']);
    $this->assertSame('2', $ranking['matrix']['b']['rank_2']['#default_value']);
  }

  /**
   * Clicking "Next" past the ranking element's page must actually save
   * its data into the submission — confirms the symptom (blank rows on
   * "Previous"/Preview) is fully explained by the #default_value
   * repopulation gate above, not a separate save-path gap: data reaches
   * the entity correctly on the way forward already.
   */
  public function testNextPageClickSavesRankingData(): void {
    $webform = $this->createWebformWithElements('test_ranking_persistence', [
      'pg_one' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Page One',
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
        ],
        'plain_field' => [
          '#type' => 'textfield',
          '#title' => 'Plain field',
        ],
      ],
      'pg_two' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Page Two',
        'confirm' => [
          '#type' => 'checkbox',
          '#title' => 'Confirm',
        ],
      ],
    ]);

    $submission = WebformSubmission::create(['webform_id' => $webform->id()]);
    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'add');
    $form_object->setEntity($submission);

    $form_state = new FormState();
    // Drupal\Core\Form\FormBuilder::submitForm() overwrites user input
    // with getValues() before processing (to match a non-programmatic
    // submission's own already-validated state) — setValues(), not
    // setUserInput(), is the correct way to drive a programmatic
    // "as if already typed and clicked" submission here.
    $form_state->setValues([
      'ranking' => ['matrix' => ['a' => '1', 'b' => '2', 'c' => '3']],
      'plain_field' => 'hello',
      'op' => 'Next >',
    ]);
    $form_state->setRequestMethod('POST');
    $form_state->setProgrammed(TRUE);
    $form_state->setProgrammedBypassAccessCheck(FALSE);

    \Drupal::formBuilder()->submitForm($form_object, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(
      ['ranking' => ['a' => '1', 'b' => '2', 'c' => '3'], 'plain_field' => 'hello', 'confirm' => 0],
      $form_object->getEntity()->getData()
    );
    $this->assertSame('pg_two', $form_object->getEntity()->getCurrentPage());
  }

}
