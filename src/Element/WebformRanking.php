<?php

namespace Drupal\webform_ranking\Element;

use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Core\Render\Element;

/**
 * Provides a form element for ranking a set of items.
 *
 * Canonical value shape (see module design notes):
 *
 * @code
 * [
 *   'values' => ['item_a', 'item_c'],  // ordered, position = rank - 1.
 *   'na'     => ['item_b'],            // unordered set of opted-out items.
 * ]
 * @endcode
 *
 * Items not present in either array are treated as not currently
 * applicable (e.g. conditionally hidden) rather than an error state.
 *
 * This canonical shape is used for all in-memory processing —
 * validation rules, the visibility resolver — but is NOT what
 * ultimately gets persisted. Webform's submission storage can only
 * store composite elements as a flat map of scalar-valued properties,
 * so validateWebformRanking() hands off the flat item-value => rank
 * shape (WebformRankingConverter::canonicalToMatrix()'s shape) as the
 * element's final #value; see that method and
 * WebformRankingConverter's class docblock for the full storage-
 * boundary rationale.
 *
 * @FormElement("webform_ranking")
 */
class WebformRanking extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#input' => TRUE,
      // Full set of configured items: ['value' => ..., 'label' => ...][].
      '#items' => [],
      '#ranking_style' => 'matrix',
      '#allow_na' => FALSE,
      '#na_label' => $this->t('N/A'),
      '#rank_labels' => [],
      '#required_all' => FALSE,
      '#process' => [
        [$class, 'processWebformRanking'],
      ],
      '#value_callback' => [$class, 'valueCallback'],
      // Populated in the next pass — kept here as the intended hook point
      // rather than left implicit, since server-side validation is
      // load-bearing (never trust the client-computed visible-item set).
      '#element_validate' => [
        [$class, 'validateWebformRanking'],
      ],
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * Value callback: normalizes submitted/default values to canonical shape.
   *
   * The matrix (radio) sub-implementation submits item => rank pairs;
   * this callback is responsible for converting that into the canonical
   * values/na arrays so every downstream consumer (validation, #states
   * selectors, results formatting) only ever deals with one shape.
   *
   * Left as a pass-through skeleton pending the shared converter service
   * covered in the next implementation pass.
   */
  public static function valueCallback(&$element, $input, \Drupal\Core\Form\FormStateInterface $form_state) {
    $style = $element['#ranking_style'] ?? 'matrix';

    if ($input !== FALSE && is_array($input)) {
      if ($style === 'dragdrop') {
        return \Drupal\webform_ranking\WebformRankingConverter::dragdropToCanonical($input['dragdrop'] ?? []);
      }

      $matrix_input = $input['matrix'] ?? [];
      // Stashed for validateWebformRanking()'s sequential-rank check —
      // matrixToCanonical()'s canonical output below only preserves
      // relative order, not the literal rank numbers submitted, so
      // that check needs this raw shape and it's only available here.
      // #_-prefixed per Drupal's own convention for internal
      // bookkeeping properties (e.g. WebformElementBase's
      // #_title_display), not a real element/HTML property.
      $element['#_matrix_raw_input'] = $matrix_input;

      return \Drupal\webform_ranking\WebformRankingConverter::matrixToCanonical($matrix_input);
    }

    // No submitted input: fall back to #default_value (e.g. editing an
    // existing submission). Already in canonical shape at this point —
    // WebformRanking::prepare() is responsible for ensuring
    // #default_value is canonical before the form is built.
    return isset($element['#default_value']) ? $element['#default_value'] : [
      'values' => [],
      'na' => [],
    ];
  }

  /**
   * Process callback: builds the matrix or drag/drop sub-render array.
   */
  public static function processWebformRanking(&$element, \Drupal\Core\Form\FormStateInterface $form_state, &$complete_form) {
    $element['#tree'] = TRUE;

    // Item visibility (for conditionally-included items) is resolved by
    // the Webform element plugin before this runs, via #states on each
    // row/card — see WebformRanking::prepare(). This process callback
    // only concerns itself with rendering the full configured item set;
    // the browser hides/shows rows, and server-side validation
    // independently recomputes which items are actually valid for the
    // submitted trigger value (never trusts DOM visibility).
    $items = $element['#items'];

    if (empty($items)) {
      return $element;
    }

    switch ($element['#ranking_style']) {
      case 'dragdrop':
        $element = static::buildDragDrop($element, $items);
        break;

      case 'matrix':
      default:
        $element = static::buildMatrix($element, $items);
        break;
    }

    return $element;
  }

  /**
   * Builds the radio-matrix sub-render array.
   *
   * One radio group per item (not per rank column) so "each item gets
   * exactly one rank" is the natural constraint, not something enforced
   * against the grain of the markup.
   */
  protected static function buildMatrix(array $element, array $items) {
    $rank_count = count($items);
    $rank_labels = static::getRankLabels($element, $rank_count);
    $defaults = \Drupal\webform_ranking\WebformRankingConverter::canonicalToMatrix($element['#value'] ?? $element['#default_value'] ?? []);

    $element['matrix'] = [
      '#type' => 'table',
      '#header' => array_merge(
        [''],
        $rank_labels,
        $element['#allow_na'] ? [$element['#na_label']] : []
      ),
      '#attributes' => ['class' => ['webform-ranking-matrix']],
    ];

    $element['matrix_live_region'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['webform-ranking-matrix__live-region', 'visually-hidden'],
        'aria-live' => 'polite',
        'aria-atomic' => 'true',
      ],
    ];

    foreach ($items as $delta => $item) {
      $row_key = $item['value'];
      $element['matrix'][$row_key]['label'] = [
        '#markup' => $item['label'],
      ];

      // One real 'radio' input per rank column, each its own cell — NOT
      // a single 'radios' bundle in one cell. #type 'radios' renders
      // all its options together inside one wrapper, which was exactly
      // the bug this replaces: every option landed stacked in the
      // row's single cell instead of spreading out under each rank's
      // own header column. Table::preRenderTable() turns each of a
      // row's *direct* child elements into its own <td>, in insertion
      // order — so building one bare 'radio' element per column here,
      // each keyed separately, is what actually lines each button up
      // under its header, matching #header's column order.
      //
      // Mirrors core's own Radios::processRadios() (same
      // #return_value/#default_value/#parents/#id pattern — every
      // option sharing the row's #parents is what makes them one
      // mutually-exclusive native radio group despite living in
      // separate cells) rather than reinventing that mechanism; the
      // only difference is *where* each resulting radio element ends
      // up in the render tree. element.matrix library (rank-exclusivity
      // JS, aria-live announcements) queries radios by `name` and by
      // DOM order, not by cell structure, so it needs no changes for
      // this.
      $row_parents = array_merge($element['#parents'], ['matrix', $row_key]);
      $current_value = $defaults[$row_key] ?? NULL;
      $cell_keys = ['label'];

      foreach ($rank_labels as $rank => $rank_label) {
        $return_value = (string) ($rank + 1);
        $cell_key = 'rank_' . $return_value;
        $cell_keys[] = $cell_key;
        $element['matrix'][$row_key][$cell_key] = [
          '#type' => 'radio',
          // Invisible but real (unlike the old bundle's blank
          // #options labels) — screen readers get a distinguishing
          // "Pizza: 1st" per button instead of only the row's name.
          '#title' => $item['label'] . ': ' . $rank_label,
          '#title_display' => 'invisible',
          '#return_value' => $return_value,
          '#default_value' => $current_value,
          '#parents' => $row_parents,
          '#id' => \Drupal\Component\Utility\Html::getUniqueId('edit-' . implode('-', array_merge($row_parents, [$return_value]))),
        ];
      }

      if ($element['#allow_na']) {
        $cell_keys[] = 'rank_na';
        $element['matrix'][$row_key]['rank_na'] = [
          '#type' => 'radio',
          '#title' => $item['label'] . ': ' . $element['#na_label'],
          '#title_display' => 'invisible',
          '#return_value' => 'na',
          '#default_value' => $current_value,
          '#parents' => $row_parents,
          '#id' => \Drupal\Component\Utility\Html::getUniqueId('edit-' . implode('-', array_merge($row_parents, ['na']))),
        ];
      }

      // Conditional item inclusion: applying the item's own #states
      // condition to every cell in the row is what makes states.js
      // hide the row client-side. This is purely a display
      // convenience — the authoritative check is
      // WebformRankingVisibilityResolver, run server-side in
      // validateWebformRanking(), which is what a user can't bypass by
      // disabling JS or editing the DOM.
      //
      // Not yet handled here: dynamic rank relabeling (recomputing
      // "1st/2nd/3rd" to match the currently-visible item count) is a
      // client-side JS concern, still open — see element.matrix
      // library.
      if (!empty($item['states'])) {
        foreach ($cell_keys as $cell_key) {
          $element['matrix'][$row_key][$cell_key]['#states'] = $item['states'];
        }
      }
    }

    $element['#attached']['library'][] = 'webform_ranking/element.matrix';

    return $element;
  }

  /**
   * Builds the drag/drop sub-render array.
   *
   * Markup is a plain ordered list plus per-item "move up"/"move down"
   * buttons — always present, not a fallback — with a Pointer Events
   * based reorder engine attached client-side. A hidden input carries
   * the serialized order for submission and is kept in sync on every
   * reorder (pointer-drag or keyboard) so it also drives client-side
   * #states without requiring a full AJAX round trip.
   */
  protected static function buildDragDrop(array $element, array $items) {
    $defaults = \Drupal\webform_ranking\WebformRankingConverter::canonicalToDragdrop($element['#value'] ?? $element['#default_value'] ?? []);

    // Reorder the rendered items to match any existing default value
    // (editing an existing submission), so the JS's initial sync() call
    // reflects genuinely-saved state rather than always resetting to
    // configured order. Ranked items first (in rank order), then N/A
    // items, then anything left over (new items added to config since
    // this submission was last saved).
    $order_list = $defaults['order'] !== '' ? explode(',', $defaults['order']) : [];
    $na_list = $defaults['na'] !== '' ? explode(',', $defaults['na']) : [];
    $items_by_value = array_column($items, NULL, 'value');
    $ordered_items = [];
    foreach (array_merge($order_list, $na_list) as $value) {
      if (isset($items_by_value[$value])) {
        $ordered_items[] = $items_by_value[$value];
        unset($items_by_value[$value]);
      }
    }
    foreach ($items as $item) {
      if (isset($items_by_value[$item['value']])) {
        $ordered_items[] = $item;
      }
    }
    $items = $ordered_items;

    $element['dragdrop'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['webform-ranking-dragdrop'],
        'role' => 'list',
        // Read by JS to decide whether to render the N/A toggle button
        // per item.
        'data-allow-na' => !empty($element['#allow_na']) ? '1' : '0',
        // Read by JS so aria-live announcements use the admin's
        // configured N/A label rather than a hardcoded fallback.
        'data-na-label' => (string) $element['#na_label'],
      ],
    ];

    // Hidden inputs are the actual submitted/authoritative values —
    // the reorder engine (pointer-drag and keyboard alike) keeps these
    // in sync on every change via one shared JS function, per the
    // consistency requirement discussed for the reorder engine. This is
    // also what allows client-side #states to react live to reordering,
    // since states.js only watches real field values.
    $element['dragdrop']['order'] = [
      '#type' => 'hidden',
      '#default_value' => $defaults['order'],
      '#attributes' => ['class' => ['webform-ranking-dragdrop__order']],
      '#parents' => array_merge($element['#parents'], ['dragdrop', 'order']),
    ];
    $element['dragdrop']['na'] = [
      '#type' => 'hidden',
      '#default_value' => $defaults['na'],
      '#attributes' => ['class' => ['webform-ranking-dragdrop__na']],
      '#parents' => array_merge($element['#parents'], ['dragdrop', 'na']),
    ];

    // aria-live region for reorder/N/A-toggle announcements, shared by
    // both interaction paths.
    $element['dragdrop']['live_region'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['webform-ranking-dragdrop__live-region', 'visually-hidden'],
        'aria-live' => 'polite',
        'aria-atomic' => 'true',
      ],
    ];

    foreach ($items as $item) {
      $element['dragdrop'][$item['value']] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['webform-ranking-dragdrop__item'],
          'role' => 'listitem',
          'tabindex' => '0',
          'data-webform-ranking-value' => $item['value'],
          'data-webform-ranking-na' => in_array($item['value'], $na_list, TRUE) ? 'true' : 'false',
        ],
        'position' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '',
          '#attributes' => ['class' => ['webform-ranking-dragdrop__position']],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $item['label'],
          '#attributes' => ['class' => ['webform-ranking-dragdrop__label']],
        ],
        'controls' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['webform-ranking-dragdrop__controls']],
          // Always-present, real controls — not a JS-only fallback.
          // type="button" (not "submit") so a click never triggers
          // form submission; the reorder logic itself is entirely
          // client-side (element.dragdrop library).
          'move_up' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '▲',
            '#attributes' => [
              'type' => 'button',
              'class' => ['webform-ranking-dragdrop__move-up'],
              'aria-label' => (string) t('Move @item up', ['@item' => $item['label']]),
            ],
          ],
          'move_down' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '▼',
            '#attributes' => [
              'type' => 'button',
              'class' => ['webform-ranking-dragdrop__move-down'],
              'aria-label' => (string) t('Move @item down', ['@item' => $item['label']]),
            ],
          ],
        ],
      ];

      if (!empty($element['#allow_na'])) {
        $element['dragdrop'][$item['value']]['controls']['na'] = [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => ['class' => ['webform-ranking-dragdrop__na-label']],
          'checkbox' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'type' => 'checkbox',
              'class' => ['webform-ranking-dragdrop__na-checkbox'],
              'checked' => in_array($item['value'], $element['#value']['na'] ?? $element['#default_value']['na'] ?? [], TRUE) ? 'checked' : NULL,
            ],
          ],
          'text' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => (string) $element['#na_label'],
          ],
        ];
      }

      // See buildMatrix()'s equivalent block: display-layer only, the
      // resolver's server-side check is authoritative.
      if (!empty($item['states'])) {
        $element['dragdrop'][$item['value']]['#states'] = $item['states'];
      }
    }

    $element['#attached']['library'][] = 'webform_ranking/element.dragdrop';

    return $element;
  }

  /**
   * Resolves rank position labels, honoring any admin override.
   *
   * Public (not just used internally by buildMatrix()) so the plugin's
   * results/CSV formatting can render the same rank labels an admin
   * configured for the live form, rather than a second hardcoded copy.
   */
  public static function getRankLabels(array $element, $rank_count) {
    $overrides = $element['#rank_labels'] ?? [];
    $labels = [];
    for ($i = 0; $i < $rank_count; $i++) {
      $labels[$i] = $overrides[$i] ?? \Drupal::translation()->formatPlural(
        1,
        '@position',
        '@position',
        ['@position' => static::ordinal($i + 1)]
      );
    }
    return $labels;
  }

  /**
   * Simple ordinal formatter (1st, 2nd, 3rd, 4th...).
   *
   * Intentionally not locale-aware beyond English ordinal suffix rules;
   * #rank_labels exists precisely so admins can override this per
   * language/context via translation rather than relying on this helper
   * to be linguistically correct everywhere.
   */
  protected static function ordinal($number) {
    $suffixes = ['th', 'st', 'nd', 'rd'];
    $mod100 = $number % 100;
    $suffix = $suffixes[$mod100 >= 11 && $mod100 <= 13 ? 0 : ($number % 10 <= 3 ? $number % 10 : 0)];
    return $number . $suffix;
  }

  /**
   * Validation callback.
   *
   * Runs entirely against the canonical #value (already normalized by
   * valueCallback() regardless of which UI produced it), so this logic
   * is identical for matrix and drag/drop — one set of rules, two
   * front-ends.
   *
   * Now closes the conditional-inclusion gap flagged in the earlier
   * pass: the visible-item set is recomputed server-side via
   * WebformRankingVisibilityResolver, from the submitted value of
   * whatever trigger element(s) each item's #states condition
   * references — not from anything the client claims about DOM
   * visibility.
   *
   * Note on ordering: the unknown-item tamper check runs against the
   * *full configured* item set, and is a hard error — a key that was
   * never configured at all indicates a forged request. Items that
   * are configured but not currently visible are handled differently:
   * silently dropped rather than errored, since a stale rank in a
   * hidden input (e.g. the user changed a trigger element and client
   * JS hadn't yet cleared the now-invalid row) is an expected,
   * harmless case — not tampering — and shouldn't block submission.
   */
  public static function validateWebformRanking(&$element, \Drupal\Core\Form\FormStateInterface $form_state, &$complete_form) {
    $value = $element['#value'] ?? ['values' => [], 'na' => []];
    $values = $value['values'] ?? [];
    $na = $value['na'] ?? [];
    $title = $element['#title'] ?? '';
    $translation = \Drupal::translation();
    $items = $element['#items'] ?? [];

    $valid_item_values = array_column($items, 'value');

    // Tamper defense: every submitted item key must be one this element
    // actually configured — catches forged POST data referencing item
    // keys that were never offered at all, regardless of conditional
    // visibility.
    $unknown = array_diff(array_merge($values, $na), $valid_item_values);
    if ($unknown) {
      $form_state->setError($element, $translation->translate('@title contains an invalid selection.', ['@title' => $title]));
      return;
    }

    // Recompute which configured items are actually visible/applicable
    // given the submitted value of any trigger element(s) — never
    // trust client-reported visibility.
    $webform_submission = NULL;
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof \Drupal\webform\WebformSubmissionForm) {
      $webform_submission = $form_object->getEntity();
    }
    /** @var \Drupal\webform_ranking\WebformRankingVisibilityResolver $resolver */
    $resolver = \Drupal::service('webform_ranking.visibility_resolver');
    $visible_item_values = $resolver->resolveVisibleItemValues($items, $webform_submission);

    // Drop (not error on) entries for items that are configured but not
    // currently visible — see class-level note above.
    $values = array_values(array_intersect($values, $visible_item_values));
    $na = array_values(array_intersect($na, $visible_item_values));

    // Ranks must be assigned starting from 1st place with no gaps —
    // e.g. 2nd/3rd can't be used unless 1st is also used. Matrix-only:
    // dragdrop's ordering is inherently gapless by construction (see
    // WebformRankingConverter::matrixRanksAreSequential()'s docblock
    // for why this matters — a skipped rank is invisible in the
    // canonical $values/$na this method otherwise works with, so this
    // checks the raw per-item input stashed by valueCallback()
    // instead). Filtered to currently-visible items first, same as
    // $values/$na above, so a stale rank on a conditionally-hidden
    // item can't cause a false-positive gap, and can't mask a real
    // one among visible items either.
    $raw_matrix_input = array_intersect_key(
      $element['#_matrix_raw_input'] ?? [],
      array_flip($visible_item_values)
    );
    if (!\Drupal\webform_ranking\WebformRankingConverter::matrixRanksAreSequential($raw_matrix_input)) {
      $form_state->setError($element, $translation->translate('@title: ranks must be assigned starting from the top, with no gaps — a lower rank cannot be used unless every rank above it is also used.', ['@title' => $title]));
      return;
    }

    // Ranks must be a set: no item ranked more than once.
    if (count($values) !== count(array_unique($values))) {
      $form_state->setError($element, $translation->translate('@title: each item can only be ranked once.', ['@title' => $title]));
      return;
    }

    // No item both ranked and marked N/A.
    if (array_intersect($values, $na)) {
      $form_state->setError($element, $translation->translate('@title: an item cannot be both ranked and marked N/A.', ['@title' => $title]));
      return;
    }

    // N/A submitted despite not being enabled for this element.
    if ($na && empty($element['#allow_na'])) {
      $form_state->setError($element, $translation->translate('@title does not allow leaving items unranked.', ['@title' => $title]));
      return;
    }

    // Note on array structure: $values was already reindexed via
    // array_values() a few lines up (dropping stale/invisible entries),
    // which means it's *always* a proper 0-indexed sequential list by
    // this point — there is no remaining code path where a gappy or
    // associative array could reach here. An earlier version of this
    // method had a separate check for exactly that, which turned out to
    // be both dead code (the reindex above already made it unreachable)
    // and solving a non-problem: even a genuinely forged non-sequential
    // #value poses no real harm once reindexed, since rank is derived
    // from iteration order, not literal array keys — array_values()
    // preserves that order regardless of the original keys. The actual
    // risk worth guarding — WebformRankingConverter::canonicalToMatrix()
    // deriving each item's rank from array *position* — is exactly what
    // this reindexing step guarantees stays correct.

    if (!empty($element['#required_all'])) {
      $accounted_for = \Drupal\webform_ranking\WebformRankingConverter::accountedFor(['values' => $values, 'na' => $na]);
      $missing = array_diff($visible_item_values, $accounted_for);
      if ($missing) {
        $message = !empty($element['#allow_na'])
          ? $translation->translate('@title: every item must be ranked or marked N/A.', ['@title' => $title])
          : $translation->translate('@title: every item must be ranked.', ['@title' => $title]);
        $form_state->setError($element, $message);
      }
    }

    // Write the filtered (stale entries dropped) value back — but in
    // the flat item-value => rank shape, not canonical {values, na}.
    // Webform's submission storage (a composite element, per the
    // plugin's annotation) only knows how to persist a flat map of
    // scalar-valued properties; handing it the canonical shape here
    // would silently corrupt to the literal string "Array" when saved
    // (both 'values' and 'na' are themselves arrays). See this class's
    // and WebformRankingConverter's docblocks for the full rationale.
    // WebformRanking::prepare() is the mirror-image conversion back to
    // canonical shape when editing an existing submission.
    $form_state->setValueForElement($element, \Drupal\webform_ranking\WebformRankingConverter::canonicalToMatrix(['values' => $values, 'na' => $na]));
  }

}
