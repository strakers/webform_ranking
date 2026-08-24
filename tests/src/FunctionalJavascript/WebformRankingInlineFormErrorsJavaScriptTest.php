<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests GitHub issue #69: duplicated errors with 'inline_form_errors' on.
 *
 * Root cause: FormState::getError() walks an element's own '#parents' from
 * the root and returns the first prefix match — so every matrix radio
 * (whose '#parents' all start with the ranking element's own) inherits the
 * exact same '#errors' value as the composite element itself. With
 * 'inline_form_errors' enabled, its hook_preprocess_form_element() prints
 * '#errors' inline for any 'form_element'-themed element that lacks
 * '#error_no_message' — every matrix radio qualifies, since Radio's
 * default '#theme_wrappers' is ['form_element']. The result: the module's
 * own composite-level message (added for #48, via
 * preRenderWebformRanking()) plus one duplicate per radio. Fixed by
 * setting '#error_no_message' on every matrix radio, the same convention
 * Webform's own composite elements (WebformElementComposite,
 * WebformEmailConfirm, etc.) use for exactly this reason.
 *
 * Same rank-gap failure scenario as WebformRankingErrorDisplayJavaScriptTest
 * (see that class's docblock for why: a genuinely untouched or duplicate-
 * rank submission never reaches the server at all, client-side, so
 * couldn't exercise this server-side error-rendering path in the first
 * place).
 */
#[Group('webform_ranking')]
class WebformRankingInlineFormErrorsJavaScriptTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'webform_ranking', 'inline_form_errors'];

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
      'id' => 'test_ranking_inline_errors',
      'title' => 'Test ranking inline errors',
      'elements' => Yaml::encode([
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          // #required_all defaults to TRUE — left unset, same as a real
          // admin leaving the default in place.
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
   * A failed submission shows the error message exactly once.
   */
  public function testFailedSubmissionShowsErrorExactlyOnce(): void {
    $this->drupalGet('/webform/test_ranking_inline_errors');

    // A=2nd, B=3rd, C=N/A — every row filled (satisfies each row's own
    // native 'required' and #required_all), but 1st is never used, which
    // only the server's own "no gaps" rule catches.
    $this->assertSession()->waitForElement('css', 'input[name="ranking[matrix][a]"][value="2"]')->click();
    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][b]"][value="3"]')->click();
    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][c]"][value="na"]')->click();

    $this->getSession()->getPage()->pressButton('Submit');
    $found = $this->assertSession()->waitForText('starting from the top');
    $this->assertNotNull($found, 'Expected validation error text never appeared.');

    $html = $this->getSession()->getPage()->getHtml();
    $occurrences = substr_count($html, 'starting from the top');
    $this->assertSame(1, $occurrences, "Expected the error message exactly once, found $occurrences.");
  }

}
