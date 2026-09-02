<?php

namespace Drupal\Tests\webform_ranking\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a webform_computed_twig #ajax element reflecting a live ranking.
 *
 * Originally filed as issue #38 ("computed value seems fixed"). That
 * turned out to be a misdiagnosis of a different bug: the
 * computed-Twig AJAX pipeline itself works correctly (confirmed
 * below) — what was actually "stuck" was the ranking selection
 * itself, because a fully-ranked matrix's rank-exclusivity used to
 * genuinely *disable* every already-taken cell, permanently locking
 * out any rearrangement (see webform_ranking.matrix.js and
 * WebformRankingMatrixJavaScriptTest::testSelectingTakenRankStealsItFromPreviousHolder()
 * for the actual fix). This file keeps the computed-Twig integration
 * coverage regardless, since it's a real, valid scenario worth
 * protecting against regressing again.
 */
#[Group('webform_ranking')]
class WebformRankingComputedTwigJavaScriptTest extends WebDriverTestBase {

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
      'id' => 'test_ranking_computed',
      'title' => 'Test ranking computed',
      'elements' => Yaml::encode([
        'preference' => [
          '#type' => 'webform_ranking',
          '#title' => 'Preference',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'pizza', 'label' => 'Pizza'],
            ['value' => 'burgers', 'label' => 'Burgers'],
            ['value' => 'poutine', 'label' => 'Poutine'],
          ],
        ],
        'computed' => [
          '#type' => 'webform_computed_twig',
          '#title' => 'Computed',
          '#template' => '{{ data.preference|json_encode }}',
          '#ajax' => TRUE,
        ],
      ]),
    ])->save();
  }

  /**
   * Selects a matrix radio for the given item and rank.
   */
  protected function selectRank(string $item, string $rank): void {
    $selector = 'input[name="preference[matrix][' . $item . ']"][value="' . $rank . '"]';
    $this->assertSession()->waitForElement('css', $selector);
    $this->getSession()->getPage()->find('css', $selector)->click();
  }

  /**
   * Reads back the computed element's currently displayed text.
   */
  protected function computedText(): string {
    return trim($this->assertSession()->elementExists('css', '.js-webform-computed-wrapper')->getText());
  }

  /**
   * Tests that the computed value updates after an achievable change.
   */
  public function testComputedValueUpdatesOnRankingChange(): void {
    $this->drupalGet('/webform/test_ranking_computed');

    $this->selectRank('pizza', '1');
    $this->selectRank('burgers', '2');
    $this->selectRank('poutine', '3');

    // Give the 500ms debounce (Drupal.webform.computed.delay) plus the
    // AJAX round trip time to settle.
    $this->assertTrue($this->getSession()->getPage()->waitFor(6, function () {
      return strpos($this->computedText(), 'poutine') !== FALSE;
    }), 'Computed value never reflected the first ranking. Got: ' . $this->computedText());

    $first = $this->computedText();

    // Now change the ranking via an ACHIEVABLE move: mark poutine N/A
    // first (a rank not exclusivity-blocked, always available), which
    // frees rank 3 for nobody in particular but proves the "remove a
    // ranked item" direction alone triggers a live update.
    $this->selectRank('poutine', 'na');

    $this->assertTrue($this->getSession()->getPage()->waitFor(6, function () use ($first) {
      return $this->computedText() !== $first;
    }), 'Computed value did not change after marking poutine N/A. Stuck at: ' . $first);
  }

  /**
   * Tests the exact scenario originally reported as issue #38.
   *
   * A fully-ranked matrix, rearranged by stealing one item's rank for
   * another — the move that used to be permanently blocked (both
   * target cells disabled) and made the computed value look "stuck".
   * With reassignment fixed, the underlying value genuinely changes,
   * and the computed-Twig element correctly reflects it.
   */
  public function testComputedValueUpdatesAfterRankReassignment(): void {
    $this->drupalGet('/webform/test_ranking_computed');

    $this->selectRank('pizza', '1');
    $this->selectRank('burgers', '2');
    $this->selectRank('poutine', '3');

    $this->assertTrue($this->getSession()->getPage()->waitFor(6, function () {
      return strpos($this->computedText(), 'poutine') !== FALSE;
    }));

    $first = $this->computedText();

    // Steal rank 1 from pizza by selecting it for poutine.
    $this->selectRank('poutine', '1');

    $this->assertTrue($this->getSession()->getPage()->waitFor(6, function () use ($first) {
      return $this->computedText() !== $first;
    }), 'Computed value did not change after reassigning rank 1 from pizza to poutine. Stuck at: ' . $first);

    // .js-webform-computed-wrapper's text includes the element's own
    // "Computed" label ahead of the rendered value — extract just the
    // JSON object rather than assume/strip an exact label string.
    $text = $this->computedText();
    $json = substr($text, strpos($text, '{'));
    $decoded = json_decode($json, TRUE);
    $this->assertSame('1', $decoded['poutine']);
    $this->assertArrayNotHasKey('pizza', $decoded);
  }

  /**
   * Documents current (undecided-whether-final) behavior for a gap.
   *
   * Ranking 1st + 3rd while skipping 2nd fails validation ("must be
   * ranked in order... without skipping any positions") — but per the
   * unconditional value write-back, the live computed preview still
   * shows a value during this invalid interim state, and that value
   * is SILENTLY RENUMBERED: poutine's actual "3rd" pick renders as
   * "2" here, since the canonical value shape only preserves relative
   * order, not the literal rank number picked (see
   * WebformRankingConverter's class docblock). This is pre-existing,
   * documented behavior for the *stored* shape; whether it's
   * acceptable to also surface through the *live* AJAX preview during
   * an actively-failing validation state is an open product decision,
   * not yet settled — this test pins down current behavior so a
   * future change here is deliberate, not accidental.
   */
  public function testComputedValueWithGapInRanksIsRenumberedNotOmitted(): void {
    $this->drupalGet('/webform/test_ranking_computed');

    $this->selectRank('pizza', '1');
    $this->selectRank('poutine', '3');

    // Wait for BOTH keys, not just the first — a premature read after
    // only the first selection's AJAX round-trip completed would
    // falsely look like "the second selection got omitted".
    $text = $this->getSession()->getPage()->waitFor(8, function () {
      $text = $this->computedText();
      return (strpos($text, 'pizza') !== FALSE && strpos($text, 'poutine') !== FALSE) ? $text : FALSE;
    });

    $this->assertNotFalse($text, 'Computed value never included both items. Got: ' . $this->computedText());

    $json = substr($text, strpos($text, '{'));
    $decoded = json_decode($json, TRUE);
    $this->assertSame('1', $decoded['pizza']);
    // Not "3" — the literal rank the user picked. Documents the
    // current coalescing behavior described above.
    $this->assertSame('2', $decoded['poutine']);
  }

  /**
   * Tests that a visible conditional item's rank isn't silently dropped.
   *
   * Real bug: WebformRankingVisibilityResolver was consulted with
   * $form_object->getEntity() — the entity currently attached to the
   * form state, which at validation time has NOT yet been synced with
   * this request's submitted field values (that copy happens later,
   * in submit/build-entity handling). Its data was stale/incomplete
   * for a #states condition depending on another field changed in the
   * SAME request (e.g. the 'trigger' field here) — the resolver
   * evaluated the condition against a submission that didn't yet know
   * 'trigger' was filled, incorrectly concluded the conditional item
   * was invisible, and silently dropped its rank from the computed
   * value. Most visible via webform_computed_twig's #ajax recompute,
   * which validates the whole form on every change elsewhere. Fixed
   * by using $form_object->buildEntity($complete_form, $form_state)
   * instead — builds a fresh entity from the CURRENT form state,
   * exactly the pattern Webform's own generic element validator uses
   * for this same purpose
   * (WebformSubmissionConditionsValidator::elementValidate()).
   */
  public function testComputedValueIncludesVisibleConditionalItem(): void {
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_rank_computed_cond',
      'title' => 'Test ranking computed conditional',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'textfield',
          '#title' => 'Trigger',
        ],
        'preference' => [
          '#type' => 'webform_ranking',
          '#title' => 'Preference',
          '#ranking_style' => 'matrix',
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'pizza', 'label' => 'Pizza'],
            [
              'value' => 'burgers',
              'label' => 'Burgers',
              'states' => [
                'visible' => [
                  ':input[name="trigger"]' => ['filled' => TRUE],
                ],
              ],
            ],
          ],
        ],
        'computed' => [
          '#type' => 'webform_computed_twig',
          '#title' => 'Computed',
          '#template' => '{{ data.preference|json_encode }}',
          '#ajax' => TRUE,
        ],
      ]),
    ])->save();

    $this->drupalGet('/webform/test_rank_computed_cond');

    $this->getSession()->getPage()->fillField('trigger', 'anything');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', 'input[name="preference[matrix][burgers]"][value="1"]'));

    $this->selectRank('pizza', '2');
    $this->selectRank('burgers', '1');

    $text = $this->getSession()->getPage()->waitFor(8, function () {
      $text = $this->computedText();
      return (strpos($text, 'pizza') !== FALSE && strpos($text, 'burgers') !== FALSE) ? $text : FALSE;
    });

    $this->assertNotFalse($text, 'Computed value never included both items. Got: ' . $this->computedText());

    $json = substr($text, strpos($text, '{'));
    $decoded = json_decode($json, TRUE);
    $this->assertSame('2', $decoded['pizza']);
    $this->assertSame('1', $decoded['burgers']);
  }

  /**
   * Tests that a hidden drag/drop item is excluded from the computed value.
   *
   * Mirrors testComputedValueIncludesVisibleConditionalItem() above for
   * the drag/drop display style (GitHub issue #108). Server-side, this
   * is already enforced regardless of display style by
   * validateWebformRanking()'s visibility-resolver intersect — this
   * pins that same protection down for drag/drop specifically, since
   * only the matrix side had coverage for it before.
   */
  public function testComputedValueExcludesHiddenDragdropItem(): void {
    Webform::create([
      'langcode' => 'en',
      'status' => WebformInterface::STATUS_OPEN,
      'id' => 'test_rank_computed_dragdrop_cond',
      'title' => 'Test ranking computed dragdrop conditional',
      'elements' => Yaml::encode([
        'trigger' => [
          '#type' => 'textfield',
          '#title' => 'Trigger',
        ],
        'preference' => [
          '#type' => 'webform_ranking',
          '#title' => 'Preference',
          '#ranking_style' => 'dragdrop',
          '#allow_na' => TRUE,
          '#items' => [
            ['value' => 'pizza', 'label' => 'Pizza'],
            [
              'value' => 'burgers',
              'label' => 'Burgers',
              'states' => [
                'invisible' => [
                  ':input[name="trigger"]' => ['filled' => TRUE],
                ],
              ],
            ],
          ],
        ],
        'computed' => [
          '#type' => 'webform_computed_twig',
          '#title' => 'Computed',
          '#template' => '{{ data.preference|json_encode }}',
          '#ajax' => TRUE,
        ],
      ]),
    ])->save();

    $this->drupalGet('/webform/test_rank_computed_dragdrop_cond');

    // Both items start ranked (pizza 1st, burgers 2nd, per configured
    // order), so the computed value should include both initially.
    $text = $this->getSession()->getPage()->waitFor(8, function () {
      $text = $this->computedText();
      return (strpos($text, 'pizza') !== FALSE && strpos($text, 'burgers') !== FALSE) ? $text : FALSE;
    });
    $this->assertNotFalse($text, 'Computed value never included both items. Got: ' . $this->computedText());

    // Hiding burgers must drop it from the computed value entirely, not
    // leave its stale rank behind.
    $this->getSession()->getPage()->fillField('trigger', 'anything');

    $text = $this->getSession()->getPage()->waitFor(8, function () {
      $text = $this->computedText();
      $json = substr($text, strpos($text, '{'));
      $decoded = json_decode($json, TRUE);
      return is_array($decoded) && !array_key_exists('burgers', $decoded) ? $text : FALSE;
    });
    $this->assertNotFalse($text, 'Computed value still included the hidden item. Got: ' . $this->computedText());

    $json = substr($text, strpos($text, '{'));
    $decoded = json_decode($json, TRUE);
    $this->assertSame('1', $decoded['pizza']);
  }

}
