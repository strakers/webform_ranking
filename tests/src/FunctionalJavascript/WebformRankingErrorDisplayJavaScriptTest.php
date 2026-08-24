<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the validation-failure markup added for GitHub issues #47/#48.
 *
 * Drives a real failed submission through the actual page (not a hand-built
 * #errors/#validated array) so this exercises the real
 * FormValidator/FormErrorHandler -> WebformRanking::preRenderWebformRanking()
 * pipeline end to end.
 *
 * Matrix style only: WebformRankingErrorDisplayTest (Kernel) already covers
 * the underlying preRenderWebformRanking() logic directly, and it applies
 * identically regardless of #ranking_style (it only looks at #errors/
 * #validated on the top-level element, never at 'matrix'/'dragdrop'
 * children) — one style is enough to prove the real end-to-end wiring
 * works. Drag/drop specifically isn't a good second case here anyway: its
 * hidden 'order' input auto-fills with every item's default position via
 * element.dragdrop's sync() on page load (see WebformRanking::
 * buildDragDrop()'s docblock), so #required_all is trivially satisfied
 * without the user touching anything — there's no "leave it blank" failure
 * state to submit in the first place.
 *
 * The failure case deliberately triggers a *rank-gap* error (2nd/3rd used,
 * 1st skipped), not simply "leave everything blank" or "duplicate a rank":
 * - #46 added a native 'required' HTML attribute to every matrix radio, so
 *   a genuinely untouched submission never reaches the server at all — the
 *   browser's own constraint validation blocks it client-side first, same
 *   as any other native required field. Confirmed the hard way: an earlier
 *   version of this test pressed Submit without selecting anything and
 *   asserted on $this->assertSession()->waitForText(...)'s return without
 *   checking it — that method doesn't throw on timeout (it's a query, not
 *   an assertion), so the test kept "passing" against the untouched
 *   pre-submission page.
 * - A duplicate rank doesn't reach the server either: element.matrix's own
 *   rank-exclusivity JS ("steal") unchecks whichever row previously held a
 *   rank the instant another row selects it, so two rows can never both be
 *   checked for the same rank via ordinary clicking in the first place.
 *
 * Three items with N/A allowed sidesteps both: ranking item A 2nd, item B
 * 3rd, and marking item C N/A satisfies every row's own native 'required'
 * constraint (each row has *something* checked) and #required_all (every
 * item is ranked or N/A) while still failing this element's own
 * "no gaps" rule (1st never used) — a check only the server enforces.
 */
#[Group('webform_ranking')]
class WebformRankingErrorDisplayJavaScriptTest extends WebDriverTestBase {

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

    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_ranking_errors',
      'title' => 'Test ranking errors',
      'elements' => Yaml::encode([
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          // #required_all defaults to TRUE — left unset here on purpose,
          // same as a real admin leaving the default in place.
          '#items' => [
            ['value' => 'a', 'label' => 'Item A'],
            ['value' => 'b', 'label' => 'Item B'],
            ['value' => 'c', 'label' => 'Item C'],
          ],
        ],
      ]),
    ])->save();
  }

  /**
   * Reads back the ranking element's own wrapper element.
   */
  protected function wrapper() {
    return $this->assertSession()->elementExists('css', '[data-drupal-selector="edit-ranking--wrapper"]');
  }

  /**
   * On initial load (nothing submitted yet), no error state is present.
   */
  public function testInitialLoadHasNoErrorState(): void {
    $this->drupalGet('/webform/test_ranking_errors');

    $wrapper = $this->wrapper();
    $this->assertStringNotContainsString('error', $wrapper->getAttribute('class') ?? '');
    $this->assertNull($wrapper->getAttribute('aria-invalid'));
    $this->assertNull($wrapper->find('css', '.webform-ranking__errors'));
  }

  /**
   * A failed submission (rank gap) shows error styling + inline text.
   */
  public function testFailedSubmissionShowsErrorState(): void {
    $this->drupalGet('/webform/test_ranking_errors');

    // See class docblock: A=2nd, B=3rd, C=N/A — every row filled, but 1st
    // is never used.
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"][value="2"]')->click();
    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][b]"][value="3"]')->click();
    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="na"]')->click();

    $this->getSession()->getPage()->pressButton('Submit');
    $found = $this->assertSession()->waitForText('must be ranked in order');
    $this->assertNotNull($found, 'Expected validation error text never appeared.');

    $wrapper = $this->wrapper();
    $this->assertStringContainsString('error', $wrapper->getAttribute('class'));
    $this->assertSame('true', $wrapper->getAttribute('aria-invalid'));

    $inline_error = $wrapper->find('css', '.webform-ranking__errors');
    $this->assertNotNull($inline_error, 'Inline error text missing.');
    $this->assertTrue($inline_error->isVisible());
    $this->assertStringContainsString('must be ranked in order', $inline_error->getText());
  }

}
