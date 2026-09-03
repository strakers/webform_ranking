<?php

namespace Drupal\webform_ranking\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\webform\Utility\WebformArrayHelper;
use Drupal\webform\WebformSubmissionConditionsValidator;
use Drupal\webform\WebformSubmissionForm;
use Drupal\webform_ranking\WebformRankingConverter;

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
 */
#[FormElement('webform_ranking')]
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
      '#pre_render' => [
        [$class, 'preRenderWebformRanking'],
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
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    $style = $element['#ranking_style'] ?? 'matrix';

    if ($input !== FALSE && is_array($input)) {
      if ($style === 'dragdrop') {
        return WebformRankingConverter::dragdropToCanonical($input['dragdrop'] ?? []);
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

      return WebformRankingConverter::matrixToCanonical($matrix_input);
    }

    // No submitted input: fall back to #default_value (e.g. editing an
    // existing submission). Already in canonical shape at this point —
    // WebformRanking::prepare() is responsible for ensuring
    // #default_value is canonical before the form is built.
    return $element['#default_value'] ?? [
      'values' => [],
      'na' => [],
    ];
  }

  /**
   * Process callback: builds the matrix or drag/drop sub-render array.
   */
  public static function processWebformRanking(&$element, FormStateInterface $form_state, &$complete_form) {
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

    $items = static::resolveCrossPageItemStates($items, $form_state, $complete_form);

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
   * Resolves cross-page item conditions statically, server-side.
   *
   * An item's own '#states' condition never worked when its trigger
   * lives on an earlier wizard page — Webform's own cross-page handling
   * walks the configured element tree before #items is expanded into
   * real sub-elements, so an item's condition is invisible to it. This
   * replicates that treatment narrowly: detect a cross-page trigger,
   * resolve it once via WebformRankingVisibilityResolver, and apply the
   * result statically (clearing 'states', setting '_cross_page_hidden'
   * if not visible) rather than a live binding nothing could react to
   * anyway. Same-page conditions are left completely untouched. See
   * docs/adr/0006-cross-page-item-condition-resolution.md.
   *
   * @param array $items
   *   The element's configured items (value/label/states each).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param array $complete_form
   *   The complete form structure, per this method's own '#process'
   *   caller.
   *
   * @return array
   *   $items, with each cross-page item's 'states' cleared (nothing left
   *   to attach live) and, if resolved not-visible, an internal
   *   '_cross_page_hidden' marker `buildMatrix()`/`buildDragDrop()` use
   *   to exclude it via '#access' instead.
   */
  protected static function resolveCrossPageItemStates(array $items, FormStateInterface $form_state, array $complete_form): array {
    $cross_page_deltas = [];
    foreach ($items as $delta => $item) {
      if (!empty($item['states']) && static::itemConditionIsCrossPage($item['states'], $complete_form)) {
        $cross_page_deltas[] = $delta;
      }
    }
    if (!$cross_page_deltas) {
      return $items;
    }

    // Same buildEntity()-not-getEntity() rationale as
    // validateWebformRanking(): the current request's own field changes
    // (e.g. a same-page trigger submitted moments ago on a *previous*
    // page) must be reflected, not a stale cached entity.
    $webform_submission = NULL;
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof WebformSubmissionForm) {
      $webform_submission = $form_object->buildEntity($complete_form, $form_state);
    }

    /** @var \Drupal\webform_ranking\WebformRankingVisibilityResolver $resolver */
    $resolver = \Drupal::service('webform_ranking.visibility_resolver');
    $visible_values = $resolver->resolveVisibleItemValues($items, $webform_submission);

    foreach ($cross_page_deltas as $delta) {
      $items[$delta]['states'] = [];
      if (!in_array($items[$delta]['value'], $visible_values, TRUE)) {
        $items[$delta]['_cross_page_hidden'] = TRUE;
      }
    }

    return $items;
  }

  /**
   * Whether an item's condition references an element on another page.
   *
   * Only ever returns TRUE on a *confirmed* cross-page trigger — a
   * selector this method can't resolve at all (e.g. a typo, or some
   * selector shape it doesn't recognize) falls through to FALSE,
   * preserving today's existing (live, same-page-style) '#states'
   * attachment rather than guessing. An unresolvable selector already
   * has its own, separate handling at validation time (see
   * WebformRankingVisibilityResolver's fail-open behavior); this method
   * only ever narrows behavior, never changes what already works.
   */
  protected static function itemConditionIsCrossPage(array $states, array $complete_form): bool {
    foreach (static::extractConditionSelectors($states) as $selector) {
      $input_name = WebformSubmissionConditionsValidator::getSelectorInputName($selector);
      if (!$input_name) {
        continue;
      }
      $webform_key = WebformSubmissionConditionsValidator::getInputNameAsArray($input_name, 0);
      if (static::isWebformKeyAccessible($webform_key, $complete_form['elements'] ?? []) === FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts every selector referenced by a conditions array.
   *
   * Mirrors `WebformSubmissionConditionsValidator::
   * getConditionTargetsVisibilityRecursive()` (protected, not reusable
   * directly) — same and/or/xor-aware traversal, since a condition can
   * be either a plain selector-keyed map or a sequential array mixing
   * selector conditions with 'and'/'or'/'xor' operator strings.
   */
  protected static function extractConditionSelectors(array $states): array {
    $selectors = [];
    foreach ($states as $conditions) {
      if (is_array($conditions)) {
        static::extractSelectorsRecursive($conditions, $selectors);
      }
    }
    return $selectors;
  }

  /**
   * Recursion helper for extractConditionSelectors().
   */
  protected static function extractSelectorsRecursive(array $conditions, array &$selectors): void {
    foreach ($conditions as $index => $value) {
      if (is_int($index) && is_array($value) && WebformArrayHelper::isSequential($value)) {
        static::extractSelectorsRecursive($value, $selectors);
      }
      elseif (is_string($value) && in_array($value, ['and', 'or', 'xor'], TRUE)) {
        continue;
      }
      elseif (is_int($index)) {
        $selectors[] = array_key_first($value);
      }
      else {
        $selectors[] = $index;
      }
    }
  }

  /**
   * Finds a webform element key anywhere in the tree and checks access.
   *
   * Walks the complete, all-pages form tree recursively. NULL (not
   * FALSE) if the key isn't found anywhere in the tree at all,
   * so callers can distinguish "confirmed cross-page" from "couldn't
   * determine" and default to the latter's safer, existing behavior.
   *
   * @return bool|null
   *   TRUE/FALSE if resolved, NULL if the key wasn't found anywhere.
   */
  protected static function isWebformKeyAccessible(string $webform_key, array $elements, bool $parent_accessible = TRUE): ?bool {
    foreach ($elements as $key => $element) {
      if (!is_array($element) || !isset($element['#type'])) {
        continue;
      }
      $accessible = $parent_accessible && (($element['#access'] ?? TRUE) !== FALSE);
      if ($key === $webform_key) {
        return $accessible;
      }
      $found = static::isWebformKeyAccessible($webform_key, $element, $accessible);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
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
    $defaults = WebformRankingConverter::canonicalToMatrix($element['#value'] ?? $element['#default_value'] ?? []);
    $required_all = !empty($element['#required_all']);

    // Header cells carry their own 'id'/'scope' (Table::preRenderTable()
    // and ThemePreprocess::preprocessTable() pass any array key other
    // than 'data' straight through as a <th> attribute) so each rank
    // radio below can point its aria-describedby at its own column —
    // see GitHub issue #46's matrix markup spec.
    $header_id_base = 'edit-' . implode('-', $element['#parents']);
    $rank_header_ids = [];
    $header_cells = [''];
    foreach ($rank_labels as $rank => $rank_label) {
      $rank_header_ids[$rank] = Html::getUniqueId($header_id_base . '-rank-header-' . ($rank + 1));
      $header_cells[] = [
        'data' => $rank_label,
        'id' => $rank_header_ids[$rank],
        'scope' => 'col',
      ];
    }
    $na_header_id = NULL;
    if ($element['#allow_na']) {
      $na_header_id = Html::getUniqueId($header_id_base . '-rank-header-na');
      $header_cells[] = [
        'data' => $element['#na_label'],
        'id' => $na_header_id,
        'scope' => 'col',
      ];
    }

    $element['matrix'] = [
      '#type' => 'table',
      '#header' => $header_cells,
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

    foreach ($items as $item) {
      $row_key = $item['value'];
      // '#type' => 'container', not 'html_tag': the latter's #pre_render
      // bakes #attributes before #states processing ever runs, leaving
      // a conditionally-hidden row's label with nothing to hide by.
      // See docs/adr/0007-matrix-radio-cell-rendering.md.
      $row_parents = array_merge($element['#parents'], ['matrix', $row_key]);
      $label_id = Html::getUniqueId('edit-' . implode('-', $row_parents) . '-label');
      $label_classes = ['webform-ranking-matrix__label'];

      // #required_all indication (GitHub issue #46): 'form-required'
      // matches the standard convention every core required field's
      // <label> uses (the asterisk is a CSS '::after', no glyph markup
      // needed). 'role="radiogroup"'/'aria-labelledby' substitutes for
      // the <fieldset> a <tr> can't be, per the issue's markup spec.
      if ($required_all) {
        $label_classes = array_merge($label_classes, ['form-item__label', 'js-form-required', 'form-required']);
        $element['matrix'][$row_key]['#attributes'] = [
          'role' => 'radiogroup',
          'aria-labelledby' => $label_id,
        ];
      }

      $element['matrix'][$row_key]['label'] = [
        '#type' => 'container',
        '#attributes' => ['class' => $label_classes, 'id' => $label_id],
        'text' => [
          '#markup' => $item['label'],
        ],
      ];

      // One real 'radio' input per rank column, each its own cell — not
      // a 'radios' bundle (which stacks every option in one cell instead
      // of spreading across columns). Table::preRenderTable() turns each
      // direct child into its own <td> in insertion order; mirrors core's
      // Radios::processRadios() #return_value/#parents/#id pattern for
      // the mutually-exclusive-group mechanism. See ADR-0007.
      $current_value = $defaults[$row_key] ?? NULL;
      $cell_keys = ['label'];

      // GitHub issue #102: mirroring visible/invisible into a
      // 'required'/'optional' #states key (the former GitHub #68 fix)
      // crashed WebformSubmissionConditionsValidator, which resolves a
      // Webform element plugin for any such element — none exists for a
      // bare radio/container. A conditionally-visible row's static
      // 'required' attribute is now permanently withheld instead of
      // live-toggled. See
      // docs/adr/0018-remove-required-optional-states-mirror.md.
      $suppress_static_required = $required_all && !empty($item['states']) && empty($item['_cross_page_hidden']);

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
          '#id' => Html::getUniqueId('edit-' . implode('-', array_merge($row_parents, [$return_value]))),
          // GitHub issue #69: every radio inherits this element's own
          // '#errors' (FormState::getError() matches the first #parents
          // prefix hit), which would duplicate preRenderWebformRanking()'s
          // own error message under 'inline_form_errors' — suppressed
          // the same way Webform's own composite elements suppress it
          // for their sub-elements.
          '#error_no_message' => TRUE,
          // GitHub issue #115: sighted-only, narrow-viewport aid — the
          // column header this echoes is hidden by
          // webform_ranking.matrix.css's responsive collapse, so this
          // fills in visually. aria-hidden since the radio's own #title
          // above already fully names it; unlike the Likert element's
          // similar span, this one carries no accessibility duty of its
          // own to protect.
          '#suffix' => '<span class="webform-ranking-matrix__rank-label" aria-hidden="true">' . Html::escape($rank_label) . '</span>',
        ];
        if ($required_all) {
          // Native 'required' (not Drupal's own '#required'): a plain
          // HTML attribute gets the browser's own "at least one radio
          // in this name group must be checked" constraint validation
          // for free, without engaging FormValidator's per-element
          // required check — which operates on a single radio, not the
          // whole row, and would conflict with this element's own
          // #required_all validation in validateWebformRanking().
          if (!$suppress_static_required) {
            $element['matrix'][$row_key][$cell_key]['#attributes']['required'] = 'required';
          }
          $element['matrix'][$row_key][$cell_key]['#attributes']['aria-describedby'] = $rank_header_ids[$rank];
        }
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
          '#id' => Html::getUniqueId('edit-' . implode('-', array_merge($row_parents, ['na']))),
          '#error_no_message' => TRUE,
          // See the rank radios' own '#suffix' above (GitHub issue #115).
          '#suffix' => '<span class="webform-ranking-matrix__rank-label" aria-hidden="true">' . Html::escape($element['#na_label']) . '</span>',
        ];
        if ($required_all) {
          if (!$suppress_static_required) {
            $element['matrix'][$row_key]['rank_na']['#attributes']['required'] = 'required';
          }
          $element['matrix'][$row_key]['rank_na']['#attributes']['aria-describedby'] = $na_header_id;
        }
      }

      // Applying the item's own #states to every cell is purely a
      // display convenience — WebformRankingVisibilityResolver in
      // validateWebformRanking() is the authoritative, unbypassable
      // check. '_cross_page_hidden' takes precedence (see ADR-0006):
      // already statically resolved, so '#access' excludes the cell
      // instead of a live binding that could never react to anything.
      // Rank *columns* are still always built from the full item count;
      // hiding surplus ones (GitHub issue #60) is a client-side concern
      // (updateRankColumns()), not this render pass's.
      if (!empty($item['_cross_page_hidden'])) {
        foreach ($cell_keys as $cell_key) {
          $element['matrix'][$row_key][$cell_key]['#access'] = FALSE;
        }
      }
      elseif (!empty($item['states'])) {
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
    $defaults = WebformRankingConverter::canonicalToDragdrop($element['#value'] ?? $element['#default_value'] ?? []);

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

    // Outer wrapper, no ARIA role of its own — purely structural, so
    // the hidden order/na/rank inputs and the live-region <div> below
    // can live outside the role="list" element without changing how
    // webform_ranking.dragdrop.js locates them (container.parentElement).
    // A `role="list"` element's owned children must all be
    // `role="listitem"`; those hidden inputs and the live region used
    // to be direct children of the list element itself, alongside the
    // real listitems, which made the list semantics invalid.
    $element['dragdrop'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['webform-ranking-dragdrop-wrapper'],
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

    // Per-item rank echo — a *second*, purely-derived, non-authoritative
    // data channel giving #states a real per-item selector to target
    // (dragdropToCanonical() never reads it). CRITICAL: kept in sync
    // ONLY by element.dragdrop's sync() function — any code path that
    // mutates order/na without going through sync() silently desyncs
    // #states from the real submitted state. See
    // docs/adr/0008-dragdrop-rank-echo-channel.md.
    $rank_by_value = [];
    foreach ($order_list as $position => $value) {
      $rank_by_value[$value] = (string) ($position + 1);
    }
    foreach ($na_list as $value) {
      $rank_by_value[$value] = 'na';
    }
    foreach ($items as $item) {
      $element['dragdrop']['rank'][$item['value']] = [
        '#type' => 'hidden',
        '#default_value' => $rank_by_value[$item['value']] ?? '',
        '#attributes' => [
          'class' => ['webform-ranking-dragdrop__rank'],
          // Deliberately a different attribute name than the item
          // container's own data-webform-ranking-value — a shared name
          // caused a real selector-ambiguity bug once already; see
          // docs/adr/0008-dragdrop-rank-echo-channel.md.
          'data-webform-ranking-rank-for' => $item['value'],
        ],
        '#parents' => array_merge($element['#parents'], ['dragdrop', 'rank', $item['value']]),
      ];
    }

    // #required_all indication (GitHub issue #46), drag/drop side: no
    // visual asterisk (placement in the list already denotes rank), but
    // screen readers need to know. Not aria-required — role="listitem"
    // doesn't support it per WAI-ARIA — so one shared, visually-hidden
    // description via aria-describedby on every item container instead.
    $required_all = !empty($element['#required_all']);
    $required_description_id = NULL;
    if ($required_all) {
      $required_description_id = Html::getUniqueId('edit-' . implode('-', $element['#parents']) . '-dragdrop-required-description');
      $element['dragdrop']['required_description'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $element['#allow_na']
          ? t('This item is required: it must be placed in the order or marked @na_label.', ['@na_label' => $element['#na_label']])
          : t('This item is required: it must be placed in the order.'),
        '#attributes' => [
          'id' => $required_description_id,
          'class' => ['visually-hidden'],
        ],
      ];
    }

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

    // The actual role="list" — a sibling of the hidden inputs/live
    // region above, not their parent, so its only children are
    // role="listitem" items. webform_ranking.dragdrop.js still
    // attaches its behavior to '.webform-ranking-dragdrop' (this
    // element, unchanged), so item lookups/DOM manipulation
    // (allItems(), insertBefore(), etc.) are entirely unaffected —
    // only the hidden-input/live-region lookups changed, to search
    // this element's parent instead of itself.
    $element['dragdrop']['list'] = [
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

    foreach ($items as $item) {
      $element['dragdrop']['list'][$item['value']] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['webform-ranking-dragdrop__item'],
          'role' => 'listitem',
          'tabindex' => '0',
          'data-webform-ranking-value' => $item['value'],
          'data-webform-ranking-na' => in_array($item['value'], $na_list, TRUE) ? 'true' : 'false',
        ] + ($required_all ? ['aria-describedby' => $required_description_id] : []),
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
          // The icons are wrapped in an aria-hidden span rather than
          // exposed directly: assistive tech would otherwise announce
          // redundant/confusing content alongside the aria-label. No
          // #value on the button itself — see the 'na' label below for
          // the same established pattern (a childless-#value html_tag
          // with nested html_tag children instead). Icons are built as
          // nested html_tag render arrays (svg > path), not a raw
          // markup string — Drupal\Core\Render\Element\HtmlTag's own
          // void-element list already covers 'path', and a raw string
          // would get Xss::filterAdmin()-stripped since neither 'svg'
          // nor 'path' is in its allowed-tag list. See
          // docs/adr/0022-inline-svg-via-nested-render-arrays.md.
          'move_up' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'type' => 'button',
              'class' => ['webform-ranking-dragdrop__move-up'],
              'aria-label' => (string) t('Move @item up', ['@item' => $item['label']]),
            ],
            'glyph' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#attributes' => ['aria-hidden' => 'true'],
              'icon' => static::buildMoveIcon('up'),
            ],
          ],
          'move_down' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'type' => 'button',
              'class' => ['webform-ranking-dragdrop__move-down'],
              'aria-label' => (string) t('Move @item down', ['@item' => $item['label']]),
            ],
            'glyph' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#attributes' => ['aria-hidden' => 'true'],
              'icon' => static::buildMoveIcon('down'),
            ],
          ],
        ],
      ];

      if (!empty($element['#allow_na'])) {
        $element['dragdrop']['list'][$item['value']]['controls']['na'] = [
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

      // See buildMatrix()'s equivalent block (including the
      // '_cross_page_hidden' handling, GitHub issue #61): display-layer
      // only, the resolver's server-side check is authoritative.
      if (!empty($item['_cross_page_hidden'])) {
        $element['dragdrop']['list'][$item['value']]['#access'] = FALSE;
      }
      elseif (!empty($item['states'])) {
        $element['dragdrop']['list'][$item['value']]['#states'] = $item['states'];
      }
    }

    $element['#attached']['library'][] = 'webform_ranking/element.dragdrop';

    return $element;
  }

  /**
   * Builds a move-up/move-down triangle icon as a nested render array.
   *
   * A simple filled triangle — visually equivalent to the '▲'/'▼' glyphs
   * this replaced, just vector-crisp and themable. fill="currentColor"
   * inherits the button's own text color, so it needs no color token of
   * its own. width/height: 1em ties its size to the button's font-size
   * (GitHub issue #118 scales controls via font-driven sizing), instead
   * of a hardcoded pixel size that could look wrong at other sizes.
   * focusable="false" is defensive legacy-engine hardening (some older
   * browsers made inline SVG tab-focusable by default); redundant with
   * the caller's own aria-hidden wrapper on modern browsers.
   *
   * @param string $direction
   *   Either 'up' or 'down'.
   *
   * @return array
   *   A render array for the icon's <svg>.
   */
  protected static function buildMoveIcon($direction) {
    return [
      '#type' => 'html_tag',
      '#tag' => 'svg',
      '#attributes' => [
        'class' => ['webform-ranking-dragdrop__icon'],
        'viewBox' => '0 0 16 16',
        'width' => '1em',
        'height' => '1em',
        'focusable' => 'false',
      ],
      'path' => [
        '#type' => 'html_tag',
        '#tag' => 'path',
        '#attributes' => [
          'd' => $direction === 'up' ? 'M8 4l5 8H3z' : 'M8 12L3 4h10z',
          'fill' => 'currentColor',
        ],
      ],
    ];
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
      $labels[$i] = $overrides[$i] ?? t('@position', ['@position' => static::ordinal($i + 1)]);
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
   * Pre-render callback: sets validation-failure attributes/inline text.
   *
   * Also relocates element-level '#states' the same way — see the
   * '#states' block below. FormElementBase gets neither for free
   * (GitHub issue #47), unlike native elements or Webform's own
   * WebformCompositeBase (not used here — see class docblock). NOT
   * RenderElementBase::setAttributes(): '#attributes' is never actually
   * rendered for this element's '#theme_wrappers' => ['form_element']
   * (only '#wrapper_attributes' is — confirmed directly in
   * FormPreprocess::preprocessFormElement()); Webform's own composites
   * sidestep this via '#theme_wrappers' => ['fieldset'] instead,
   * deliberately not adopted here (issue #47's own deferred decision).
   * Inline error text is injected as an ordinary descendant render item
   * (GitHub issue #48) since core's 'errors' template variable is
   * unconditionally suppressed. See
   * docs/adr/0009-prerender-attributes-states-and-error-display.md.
   */
  public static function preRenderWebformRanking(array $element) {
    // Element-level '#states' silently never worked either, for the
    // same '#attributes'-never-rendered reason above: processStates()
    // writes 'data-drupal-states' to '#attributes', so states.js had
    // nothing to bind to. Fixed the same way — call processStates() for
    // its canonical encoding, copy the result onto '#wrapper_attributes'
    // instead. See
    // docs/adr/0009-prerender-attributes-states-and-error-display.md.
    if (!empty($element['#states'])) {
      FormHelper::processStates($element);
      if (isset($element['#attributes']['data-drupal-states'])) {
        $element['#wrapper_attributes']['data-drupal-states'] = $element['#attributes']['data-drupal-states'];
      }
    }

    $class_name = str_replace('_', '-', $element['#type']);
    $element['#wrapper_attributes']['class'][] = 'js-' . $class_name;
    $element['#wrapper_attributes']['class'][] = $class_name;
    if (isset($element['#id'])) {
      // Matches WebformCompositeBase::preRenderCompositeFormElement()'s
      // own '--wrapper' id-suffix convention. Also gives the wrapper a
      // real 'data-drupal-selector' — core's FormBuilder::doBuildForm()
      // already computes one from '#id' onto '#attributes' for every
      // '#input' element, but (per this method's own docblock above)
      // that never reaches rendered markup here either.
      $wrapper_id = $element['#id'] . '--wrapper';
      $element['#wrapper_attributes']['id'] = $wrapper_id;
      $element['#wrapper_attributes']['data-drupal-selector'] = $wrapper_id;
    }

    // Mirrors RenderElementBase::setAttributes()'s own error-state
    // condition exactly (isset #parents, isset #errors, non-empty
    // #validated) so this element is flagged invalid under precisely
    // the same circumstances any native element would be.
    if (isset($element['#parents']) && isset($element['#errors']) && !empty($element['#validated'])) {
      $element['#wrapper_attributes']['class'][] = 'error';
      $element['#wrapper_attributes']['aria-invalid'] = 'true';

      $element['ranking_errors'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['webform-ranking__errors', 'form-item--error-message']],
        '#weight' => 1000,
        'message' => [
          '#markup' => $element['#errors'],
        ],
      ];

      // GitHub issue #69: 'inline_form_errors' would otherwise restore
      // its own suppressed 'errors' variable and duplicate
      // 'ranking_errors' above. '#error_no_message' opts out entirely.
      //
      // *** DELIBERATE DESIGN DECISION — FLAG FOR FUTURE RE-REVIEW ***
      // Always suppress 'inline_form_errors' unconditionally, rather
      // than detecting the module and deferring to its own rendering
      // when active — chosen because the two outputs are markup-for-
      // markup identical today except for the 'webform-ranking__errors'
      // class this module's own CSS/tests key off, and module-detection
      // would add real complexity (two paths to keep in sync) for that.
      // REVISIT if either template's error markup ever diverges further
      // (e.g. 'inline_form_errors' adds its own icon/ARIA treatment).
      // Full reasoning:
      // docs/adr/0009-prerender-attributes-states-and-error-display.md.
      $element['#error_no_message'] = TRUE;
    }

    return $element;
  }

  /**
   * Validation callback.
   *
   * Runs against the canonical #value (already normalized by
   * valueCallback() regardless of which UI produced it) — one set of
   * rules for both display styles. The visible-item set is recomputed
   * server-side via WebformRankingVisibilityResolver, never trusting
   * client-claimed DOM visibility. An unconfigured item key is a hard
   * tamper error; a configured-but-currently-invisible one (e.g. a
   * stale rank from a trigger change client JS hadn't cleared yet) is
   * silently dropped instead — expected, not tampering.
   */
  public static function validateWebformRanking(&$element, FormStateInterface $form_state, &$complete_form) {
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
    // visibility. Sanitized in place (not just errored-and-returned)
    // so every check below, and the unconditional write-back at the
    // end, keep operating on legitimate data only.
    $unknown = array_diff(array_merge($values, $na), $valid_item_values);
    if ($unknown) {
      $form_state->setError($element, $translation->translate('@title contains an invalid selection.', ['@title' => $title]));
      $values = array_values(array_intersect($values, $valid_item_values));
      $na = array_values(array_intersect($na, $valid_item_values));
    }

    // Recompute which configured items are actually visible/applicable
    // given the submitted value of any trigger element(s) — never
    // trust client-reported visibility.
    //
    // buildEntity(), not getEntity(): the cached entity isn't yet synced
    // with this request's submitted values at validation time, which
    // could evaluate a #states condition against stale data (most
    // visible during a webform_computed_twig #ajax recompute). See
    // docs/adr/0010-validation-live-entity-and-unconditional-writeback.md.
    $webform_submission = NULL;
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof WebformSubmissionForm) {
      $webform_submission = $form_object->buildEntity($complete_form, $form_state);
    }
    /** @var \Drupal\webform_ranking\WebformRankingVisibilityResolver $resolver */
    $resolver = \Drupal::service('webform_ranking.visibility_resolver');
    $visible_item_values = $resolver->resolveVisibleItemValues($items, $webform_submission);

    // Drop (not error on) entries for items that are configured but not
    // currently visible — see class-level note above.
    $values = array_values(array_intersect($values, $visible_item_values));
    $na = array_values(array_intersect($na, $visible_item_values));

    // #required: core's own required check can't see an empty ranking —
    // valueCallback() always returns a 2-key array (['values' => [],
    // 'na' => []]) even when nothing was selected, so FormValidator's
    // `count($value) == 0` test never trips. Checked here, after
    // stale/invisible entries are dropped above, so a submission
    // consisting only of hidden items still counts as empty.
    if (!empty($element['#required']) && !$values && !$na) {
      $form_state->setError($element, $element['#required_error']
        ?? $translation->translate('@title field is required.', ['@title' => $title]));
    }

    // Ranks must start from 1st with no gaps (matrix-only — dragdrop is
    // gapless by construction; see matrixRanksAreSequential()'s docblock,
    // ADR-0002). Checks the raw per-item input stashed by valueCallback()
    // (a skipped rank is invisible in canonical $values/$na), filtered to
    // currently-visible items first so a stale hidden-item rank can't
    // cause or mask a gap.
    $raw_matrix_input = array_intersect_key(
      $element['#_matrix_raw_input'] ?? [],
      array_flip($visible_item_values)
    );
    if (!WebformRankingConverter::matrixRanksAreSequential($raw_matrix_input)) {
      // '#sequential_ranks_error' uses '!empty()' not '??' — its default
      // is '' (defineDefaultProperties()), not NULL, so '??' would treat
      // an admin-unset empty string as "customized."
      $default_message = !empty($element['#allow_na'])
        ? $translation->translate('Items in @title must be ranked in order (1st, 2nd, 3rd, etc.), without skipping any positions. Where available, you may select N/A for items you do not wish to rank.', ['@title' => $title])
        : $translation->translate('Items in @title must be ranked in order (1st, 2nd, 3rd, etc.), without skipping any positions.', ['@title' => $title]);
      $form_state->setError($element, !empty($element['#sequential_ranks_error'])
        ? $element['#sequential_ranks_error']
        : $default_message);
    }

    // GitHub issue #104: two items sharing a raw matrix rank silently
    // collide in matrixToCanonical() (one is dropped, not flagged),
    // which the check below can no longer see by the time it runs — so
    // this reads the raw input directly, before that collision. Must
    // run before the #required_all check further down: FormState's
    // first-error-wins semantics mean this is what actually surfaces
    // instead of a misleading "item X was never ranked." See
    // docs/adr/0019-matrix-duplicate-rank-detection.md.
    if (!WebformRankingConverter::matrixRanksHaveNoDuplicates($raw_matrix_input)) {
      $form_state->setError($element, $translation->translate('Two items in @title share the same rank — each rank can only be assigned once.', ['@title' => $title]));
    }

    // Ranks must be a set: no item ranked more than once. Unreachable
    // via matrix (a genuine duplicate never survives to $values — see
    // above); real defense against dragdropToCanonical()'s own raw
    // shape, which has no equivalent dedup.
    if (count($values) !== count(array_unique($values))) {
      $form_state->setError($element, $translation->translate('Each item in @title can only be ranked once.', ['@title' => $title]));
    }

    // No item both ranked and marked N/A.
    if (array_intersect($values, $na)) {
      $form_state->setError($element, $translation->translate('An item in @title cannot be both ranked and marked N/A.', ['@title' => $title]));
    }

    // N/A submitted despite not being enabled for this element.
    if ($na && empty($element['#allow_na'])) {
      $form_state->setError($element, $translation->translate('@title does not allow leaving items unranked.', ['@title' => $title]));
    }

    // GitHub issue #63: #required_all alone (below) only ensures every
    // visible item is *accounted for* — ranked or marked N/A — which a
    // respondent can satisfy by marking everything N/A without ever
    // ranking anything, when #allow_na is on. This is a separate check
    // for genuine engagement: at least one item ranked 1st. The "ranks
    // must be assigned starting from 1st place with no gaps" check above
    // already guarantees $values can't be non-empty without a 1st-place
    // entry, so an empty $values here is exactly equivalent to "nothing
    // is ranked 1st" — no need to inspect rank position directly.
    if (!empty($element['#require_first_place']) && !$values) {
      $form_state->setError($element, !empty($element['#require_first_place_error'])
        ? $element['#require_first_place_error']
        : $translation->translate('@title requires at least one item to be ranked 1st.', ['@title' => $title]));
    }

    // $values is always a proper 0-indexed list here (array_values()'d
    // above when stale/invisible entries were dropped) — rank is derived
    // from iteration order, not literal keys, so a forged non-sequential
    // #value poses no risk once reindexed. (A separate explicit check for
    // this was removed as unreachable dead code — the reindex above
    // already guarantees it.)
    if (!empty($element['#required_all'])) {
      $accounted_for = WebformRankingConverter::accountedFor(['values' => $values, 'na' => $na]);
      $missing = array_diff($visible_item_values, $accounted_for);
      if ($missing) {
        $message = !empty($element['#allow_na'])
          ? $translation->translate('Every item in @title must be ranked or marked N/A.', ['@title' => $title])
          : $translation->translate('Every item in @title must be ranked.', ['@title' => $title]);
        $form_state->setError($element, $message);
      }
    }

    // Write the filtered value back in the flat item-value => rank
    // shape (storage can't persist canonical {values, na} — see
    // WebformRankingConverter's docblock). Unconditional — no check
    // above returns early — so this is always reached even on a failed
    // pass; a webform_computed_twig live recompute reads this mid-
    // validation, and needs the flat shape regardless. See
    // docs/adr/0010-validation-live-entity-and-unconditional-writeback.md.
    $form_state->setValueForElement($element, WebformRankingConverter::canonicalToMatrix([
      'values' => $values,
      'na' => $na,
    ]));
  }

}
