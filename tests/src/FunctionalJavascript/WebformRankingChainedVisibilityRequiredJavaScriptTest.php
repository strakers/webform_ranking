<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests GitHub issue #142: required-radio "not focusable" console block.
 *
 * A #required_all matrix ranking element's rank/N/A radios carry a
 * native `required` attribute regardless of the *element's own*
 * top-level conditional-visibility condition — buildMatrix() has no
 * awareness of it (unlike a *row's own* per-item condition, which is
 * already handled, see ADR-0018). Normally Drupal core's
 * webform.states.js clears this generically on 'state:visible'. But
 * when the element's visibility trigger is itself an :input inside
 * *another* ranking element's own per-item-conditionally-hidden row (a
 * "chained" trigger), 'state:visible' never fires at all for the
 * dependent element's wrapper — confirmed via live reproduction against
 * a real production webform, not merely inferred. A hidden-but-required
 * radio still fails native HTML5 constraint validation even though it
 * can't be focused to show the respondent, silently blocking a wizard
 * step from advancing (clicking "Next", not a final Submit — a plain
 * single-page submit did NOT reproduce this during investigation; only a
 * multi-page wizard's page-to-page AJAX transition did) with one console
 * warning per radio. See
 * docs/adr/0026-element-level-required-toggle-via-mutation-observer.md.
 */
#[Group('webform_ranking')]
class WebformRankingChainedVisibilityRequiredJavaScriptTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'webform_ranking'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mirrors the real reported shape: ranking2's own element-level
    // visibility depends on ranking1's item "ab" being ranked 1st, and
    // item "ab" *itself* has its own per-item condition tied to the same
    // trigger field — the "chained" shape that defeats 'state:visible'.
    // Deliberately a 3-page wizard, not a single flat page: a plain
    // single-page submit did not reproduce the bug during investigation
    // — only advancing past the ranking elements' own wizard page via
    // "Next" did. See docs/adr/0026-*.md.
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_chained_required',
      'title' => 'Test ranking chained visibility required',
      'elements' => Yaml::encode([
        'pg_details' => [
          '#type' => 'webform_wizard_page',
          '#title' => 'Details',
          'constituency' => [
            '#type' => 'select',
            '#title' => 'Constituency',
            '#options' => [
              'other' => 'Other',
              'community_member' => 'Community Member',
              'alumni' => 'Alumni',
            ],
          ],
          'appointment_opportunities' => [
            '#type' => 'select',
            '#title' => 'Appointment opportunities',
            '#options' => [
              'board' => 'Board',
              'tribunal' => 'Tribunal',
            ],
          ],
        ],
        'pg_rankings' => [
          '#type' => 'webform_wizard_page',
          '#title' => 'Governance Body Preferences',
          'ranking1' => [
            '#type' => 'webform_ranking',
            '#title' => 'Ranking 1',
            '#ranking_style' => 'matrix',
            // Off: this test isolates ranking2's own chained-hidden
            // required-radio behavior, not ranking1's (which defaults
            // on).
            '#required_all' => FALSE,
            // Matches the real reported shape exactly, down to the
            // multi-condition (AND of two fields) element-level visible
            // state: ranking1 ITSELF also has an element-level condition
            // sharing a field with item "ab"'s own per-item condition
            // below — item "ab"'s trigger radio ends up hidden by *two*
            // stacked conditions at once (element-level + per-item).
            '#states' => [
              'visible' => [
                ':input[name="appointment_opportunities"]' => ['!value' => 'tribunal'],
                ':input[name="constituency"]' => ['!value' => 'community_member'],
              ],
            ],
            '#items' => [
              [
                'value' => 'ab',
                'label' => 'Academic Board',
                'states' => [
                  'invisible' => [
                    ':input[name="constituency"]' => ['value' => 'community_member'],
                  ],
                ],
              ],
              ['value' => 'bb', 'label' => 'Business Board'],
              [
                'value' => 'uab',
                'label' => 'University Affairs Board',
                'states' => [
                  'invisible' => [
                    ':input[name="constituency"]' => ['value' => 'alumni'],
                  ],
                ],
              ],
            ],
            '#allow_na' => TRUE,
            '#require_first_place' => TRUE,
          ],
          'ranking2' => [
            '#type' => 'webform_ranking',
            '#title' => 'Ranking 2',
            '#ranking_style' => 'matrix',
            '#required_all' => TRUE,
            // Matches the real reported shape: the element's own plain
            // '#required' (distinct from '#required_all') is also set.
            // Webform core auto-mirrors this into the element's own
            // '#states' as a 'required' key alongside 'visible' (see
            // WebformSubmissionConditionsValidator::getBuildElementsRecursive()).
            '#required' => TRUE,
            '#items' => [
              ['value' => 'ac', 'label' => 'Agenda Committee'],
              ['value' => 'capp', 'label' => 'Committee on Academic Policy & Programs'],
              ['value' => 'pb', 'label' => 'Planning & Budget Committee'],
            ],
            '#allow_na' => TRUE,
            '#states' => [
              'visible' => [
                ':input[name="ranking1[matrix][ab]"]' => ['value' => '1'],
              ],
            ],
          ],
        ],
        'pg_confirm' => [
          '#type' => 'webform_wizard_page',
          '#title' => 'Confirmation',
          'confirm_markup' => [
            '#type' => 'webform_markup',
            '#markup' => '<p id="reached-confirmation-page">Reached the confirmation page.</p>',
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * Tests that `required` clears once hidden via the chained trigger.
   */
  public function testRequiredClearsWhenHiddenViaChainedTrigger(): void {
    $this->drupalGet('/webform/test_ranking_chained_required');
    $page = $this->getSession()->getPage();

    // Selecting community_member on page 1 hides BOTH ranking1's "ab"
    // row (its own per-item condition) AND, as a consequence, ranking2
    // itself (ab can no longer ever be ranked 1st) — both live on page
    // 2, reached via "Next".
    $page->selectFieldOption('constituency', 'community_member');
    $page->pressButton('Next');

    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking2--wrapper"]');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return !$wrapper->isVisible();
    }), 'Ranking 2 should be hidden once Academic Board can no longer be ranked.');

    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->getSession()->evaluateScript(
        "document.querySelector('[name=\"ranking2[matrix][ac]\"]').hasAttribute('required')"
      ) === FALSE;
    }), '`required` should be absent from ranking2\'s cells while hidden via the chained trigger.');
  }

  /**
   * Tests that the wizard step is no longer blocked from advancing.
   *
   * Without the fix, this hangs/fails: the browser's native constraint
   * validation blocks the "Next" click on the hidden-but-required
   * radios and logs a console warning per radio, never reaching the
   * confirmation page. A plain single-page submit did NOT reproduce
   * this during investigation — only a wizard's page-to-page "Next"
   * transition did.
   */
  public function testWizardAdvancesPastHiddenRequiredRadios(): void {
    $this->drupalGet('/webform/test_ranking_chained_required');
    $page = $this->getSession()->getPage();

    $page->selectFieldOption('constituency', 'community_member');
    $page->pressButton('Next');

    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking2--wrapper"]');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return !$wrapper->isVisible();
    }), 'Ranking 2 should be hidden before attempting to advance.');

    $page->pressButton('Next');

    $this->assertTrue($this->getSession()->getPage()->waitFor(8, function () {
      return $this->getSession()->evaluateScript(
        "!!document.getElementById('reached-confirmation-page')"
      ) === TRUE;
    }), 'The wizard should advance to the confirmation page, not get silently stuck on a hidden required radio.');
  }

  /**
   * Tests a full visibility round trip.
   *
   * `required` returns when visible, clears again when hidden —
   * confirms the fix live-toggles, not just a one-time initial-state
   * correction.
   */
  public function testRequiredTogglesAcrossVisibilityRoundTrip(): void {
    $this->drupalGet('/webform/test_ranking_chained_required');
    $page = $this->getSession()->getPage();

    // Constituency defaults to 'other', so "ab" starts rankable.
    $page->pressButton('Next');
    $this->assertSession()->waitForElement('css', 'table.webform-ranking-matrix');
    $this->getSession()->getPage()->find('css', 'input[name="ranking1[matrix][ab]"][value="1"]')->click();

    $wrapper = $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking2--wrapper"]');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return $wrapper->isVisible();
    }), 'Ranking 2 should become visible once Academic Board is ranked 1st.');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->getSession()->evaluateScript(
        "document.querySelector('[name=\"ranking2[matrix][ac]\"]').hasAttribute('required')"
      ) === TRUE;
    }), '`required` should return once ranking2 becomes visible.');

    // Now switch to the chained-hide trigger.
    $page->pressButton('Previous');
    $this->assertSession()->waitForElement('css', 'select[name="constituency"]');
    $page->selectFieldOption('constituency', 'community_member');
    $page->pressButton('Next');

    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($wrapper) {
      return !$wrapper->isVisible();
    }));
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () {
      return $this->getSession()->evaluateScript(
        "document.querySelector('[name=\"ranking2[matrix][ac]\"]').hasAttribute('required')"
      ) === FALSE;
    }), '`required` should clear again once hidden.');
  }

}
