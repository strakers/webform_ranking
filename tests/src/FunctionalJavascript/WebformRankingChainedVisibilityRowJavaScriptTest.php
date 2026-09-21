<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a hidden matrix row leaves no empty gap.
 *
 * Trigger and ranking question live on the same page (a cross-page
 * trigger excludes the item server-side instead — a different,
 * already-covered path, see WebformRankingCrossPageItemStatesJavaScriptTest).
 * Covers the one case
 * WebformRankingMatrixJavaScriptTest::testConditionalItemRowIsFullyHidden()
 * doesn't: a hidden row surviving a failed-validation round trip, not
 * just a first, clean render.
 *
 * @see https://github.com/strakers/webform_ranking/issues/152
 */
#[Group('webform_ranking')]
class WebformRankingChainedVisibilityRowJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_chained_row',
      'title' => 'Test ranking chained visibility row',
      'elements' => Yaml::encode([
        'constituency' => [
          '#type' => 'select',
          '#title' => 'Constituency',
          '#options' => [
            'other' => 'Other',
            'alumni' => 'Alumni',
          ],
        ],
        'ranking' => [
          '#type' => 'webform_ranking',
          '#title' => 'Ranking',
          '#ranking_style' => 'matrix',
          '#require_first_place' => TRUE,
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'ab', 'label' => 'Academic Board'],
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
        ],
      ]),
    ])->save();
  }

  /**
   * A hidden row leaves no empty gap after a failed-validation round trip.
   */
  public function testHiddenRowLeavesNoGapAfterValidationFailure(): void {
    $page = $this->getSession()->getPage();

    $this->drupalGet('/webform/test_ranking_chained_row');

    $uab_radio = $this->assertSession()->elementExists('css', 'input[name="ranking[matrix][uab]"]');
    $uab_row = $uab_radio->find('xpath', './ancestor::tr[1]');
    $this->assertNotNull($uab_row);
    $this->assertTrue($uab_row->isVisible(), 'University Affairs Board row should start visible (constituency defaults to "other").');

    $page->selectFieldOption('constituency', 'alumni');
    $this->assertTrue($this->getSession()->getPage()->waitFor(4, function () use ($uab_row) {
      return !$uab_row->isVisible();
    }), 'University Affairs Board row should be hidden once Alumni is selected.');

    // Violates '#require_first_place': ranked 2nd, skipping 1st.
    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][ab]"][value="2"]')->click();
    $this->getSession()->getPage()->find('css', 'input[name="ranking[matrix][bb]"][value="na"]')->click();
    $page->pressButton('Submit');

    $this->assertNotNull($this->assertSession()->waitForText('must be ranked'), 'Expected the require-first-place validation error to appear.');

    // The hidden row must still be genuinely hidden after the failed
    // submission redisplays the page, not left as an empty gap.
    $uab_radio = $this->assertSession()->elementExists('css', 'input[name="ranking[matrix][uab]"]');
    $uab_row = $uab_radio->find('xpath', './ancestor::tr[1]');
    $this->assertNotNull($uab_row);
    $this->assertFalse($uab_row->isVisible(), 'University Affairs Board row should still be hidden, not an empty gap, after the validation error is shown.');
    $this->assertTrue($uab_row->hasAttribute('hidden'), 'University Affairs Board row should carry the `hidden` attribute, not just be visually collapsed.');
  }

}
