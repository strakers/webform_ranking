<?php

namespace Drupal\webform_ranking\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;
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
      // '#type' => 'container' (not a bare '#markup' array, and not
      // 'html_tag' either): a plain '#markup' element has no
      // #attributes-bearing wrapper, so when this row later gets
      // '#states' applied below (for a conditionally-visible item),
      // Renderer::doRender()'s 'data-drupal-states' attribute has
      // nothing to attach to and states.js has nothing to hide —
      // leaving the label visibly orphaned above an otherwise-hidden
      // row of radios. 'html_tag' doesn't fix this either: its
      // #pre_render (HtmlTag::preRenderHtmlTag()) bakes #attributes
      // into a fixed markup string, and #pre_render callbacks run
      // BEFORE Renderer::doRender() processes #states
      // (Renderer.php: #pre_render at ~line 445, #states at ~line
      // 472) — by the time 'data-drupal-states' is added, the tag
      // markup is already finalized without it. 'container' instead
      // uses '#theme_wrappers' (container.html.twig), which reads
      // #attributes at theme-render time, *after* #states processing
      // — the same pattern Container's own class docblock shows as
      // the canonical way to combine '#states' with a wrapper
      // element. The radio cells below never had this problem since
      // '#type' => 'radio' is itself a themed form input, not a
      // #pre_render-baked one.
      $row_parents = array_merge($element['#parents'], ['matrix', $row_key]);
      $label_id = Html::getUniqueId('edit-' . implode('-', $row_parents) . '-label');
      $label_classes = ['webform-ranking-matrix__label'];

      // #required_all indication (GitHub issue #46): 'form-item__label'
      // + 'form-required' (+ 'js-form-required', included purely for
      // parity — see form-element-label.html.twig, which sets all
      // three together on every core required field's own <label>) is
      // the real convention a standard required field's label already
      // uses — the asterisk itself is a CSS '::after' on
      // '.form-item__label.form-required', not literal text content,
      // so no nested marker element/glyph is needed here at all.
      // Plus 'role="radiogroup"'/'aria-labelledby' on the row so a
      // screen reader announces this row the same way a standalone
      // required radio group already is. A <fieldset> can't substitute
      // for a <tr> here (per the issue's researched markup spec), so
      // the row-level ARIA role/label is the correct substitute.
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
          '#id' => Html::getUniqueId('edit-' . implode('-', array_merge($row_parents, [$return_value]))),
        ];
        if ($required_all) {
          // Native 'required' (not Drupal's own '#required'): a plain
          // HTML attribute gets the browser's own "at least one radio
          // in this name group must be checked" constraint validation
          // for free, without engaging FormValidator's per-element
          // required check — which operates on a single radio, not the
          // whole row, and would conflict with this element's own
          // #required_all validation in validateWebformRanking().
          $element['matrix'][$row_key][$cell_key]['#attributes']['required'] = 'required';
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
        ];
        if ($required_all) {
          $element['matrix'][$row_key]['rank_na']['#attributes']['required'] = 'required';
          $element['matrix'][$row_key]['rank_na']['#attributes']['aria-describedby'] = $na_header_id;
        }
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

    // Per-item rank echo — a *second*, purely-derived data channel that
    // exists only so #states has a real per-item selector to point at
    // (mirrors buildMatrix()'s one-radio-per-item selectors; see
    // WebformRanking plugin's getElementSelectorOptions()). NOT
    // authoritative: dragdropToCanonical() only ever reads 'order'/'na'
    // above, this is never consulted for storage or validation. It
    // exists solely because 'order' bundles every item's position into
    // one CSV string, which #states's trigger vocabulary (value/
    // pattern/etc.) has no way to index into — see docs/CONTINUATION.md
    // for the full rationale.
    //
    // CRITICAL: kept in sync ONLY by element.dragdrop's sync()
    // function, in lockstep with the order/na writes above. Any new
    // code path that mutates order/na without going through sync()
    // will desync this channel — #states would then show a stale rank
    // while the actually-submitted order/na stays correct, since
    // storage never reads this. Flagged explicitly so a future edit
    // doesn't reintroduce a source of stale #states data the way the
    // matrix Array-to-string bug did for a different reason.
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
          // Deliberately a *different* attribute name than the item
          // container's own data-webform-ranking-value (not just a
          // different element) — a generic
          // `[data-webform-ranking-value="x"]` query would otherwise
          // match whichever of the two happens to come first in DOM
          // order, silently picking the wrong node. Real bug hit while
          // browser-testing this feature: a querySelector meant for
          // the item container instead matched this hidden input
          // (which renders earlier in the tree) and failed on a null
          // move-up button.
          'data-webform-ranking-rank-for' => $item['value'],
        ],
        '#parents' => array_merge($element['#parents'], ['dragdrop', 'rank', $item['value']]),
      ];
    }

    // #required_all indication (GitHub issue #46), drag/drop side: per
    // the issue's own follow-up clarification, a visual asterisk isn't
    // appropriate here (an item's placement in the ordered list already
    // denotes its rank — there's no "blank" visual state the way an
    // un-clicked matrix radio has), but screen readers still need to
    // know each item is required. Not aria-required — the item
    // container's role="listitem" (below) doesn't support it per the
    // WAI-ARIA role table, so 'aria-required="true"' there would be an
    // invalid-attribute violation. A shared, visually-hidden
    // description referenced via aria-describedby on every item
    // container is the ARIA-valid equivalent the issue's discussion
    // explicitly allowed for. One shared node (not one per item): the
    // text is generic ("this item"), not item-specific, so duplicating
    // it per item would be pure waste.
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
          // The ▲/▼ glyphs are wrapped in an aria-hidden span rather
          // than set as the button's own #value: exposed directly,
          // assistive tech announced the raw glyph character
          // alongside the aria-label (redundant/confusing symbol-name
          // readout). No #value on the button itself — see the 'na'
          // label below for the same established pattern (a
          // childless-#value html_tag with nested html_tag children
          // instead).
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
              '#value' => '▲',
              '#attributes' => ['aria-hidden' => 'true'],
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
              '#value' => '▼',
              '#attributes' => ['aria-hidden' => 'true'],
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

      // See buildMatrix()'s equivalent block: display-layer only, the
      // resolver's server-side check is authoritative.
      if (!empty($item['states'])) {
        $element['dragdrop']['list'][$item['value']]['#states'] = $item['states'];
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
    // buildEntity(), not getEntity(): getEntity() returns whatever
    // entity object is currently attached to the form state, which at
    // validation time has NOT yet been synced with this request's
    // submitted field values (that copy happens later, in submit/
    // build-entity handling) — its data can be entirely stale/empty
    // for fields the resolver needs to evaluate a #states condition
    // against (e.g. a text field this item's visibility depends on).
    // buildEntity($complete_form, $form_state) builds a fresh entity
    // from the CURRENT $form_state values instead, exactly matching
    // the pattern Webform's own generic element validator uses for
    // this same purpose (see
    // WebformSubmissionConditionsValidator::elementValidate()).
    // Without this, a conditional item's #states condition could
    // evaluate against a submission that doesn't yet reflect a
    // same-request trigger field change, incorrectly treating a truly
    // visible item as invisible and silently dropping its rank —
    // most visible during a webform_computed_twig #ajax recompute,
    // which validates the whole form on every change elsewhere.
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
    if (!WebformRankingConverter::matrixRanksAreSequential($raw_matrix_input)) {
      $form_state->setError($element, $translation->translate('@title: ranks must be assigned starting from the top, with no gaps — a lower rank cannot be used unless every rank above it is also used.', ['@title' => $title]));
    }

    // Ranks must be a set: no item ranked more than once.
    if (count($values) !== count(array_unique($values))) {
      $form_state->setError($element, $translation->translate('@title: each item can only be ranked once.', ['@title' => $title]));
    }

    // No item both ranked and marked N/A.
    if (array_intersect($values, $na)) {
      $form_state->setError($element, $translation->translate('@title: an item cannot be both ranked and marked N/A.', ['@title' => $title]));
    }

    // N/A submitted despite not being enabled for this element.
    if ($na && empty($element['#allow_na'])) {
      $form_state->setError($element, $translation->translate('@title does not allow leaving items unranked.', ['@title' => $title]));
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
      $accounted_for = WebformRankingConverter::accountedFor(['values' => $values, 'na' => $na]);
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
    //
    // Unconditional — every check above sets an error (if any) without
    // returning, specifically so this line is always reached, even on
    // a failed validation pass. This matters beyond the failing
    // submission itself: a webform_computed_twig element configured
    // for live AJAX recompute triggers a full (non-#limit_validation_errors)
    // validation pass on every keystroke/change elsewhere on the form,
    // then reads $form_state->getValues() directly via
    // WebformSubmissionForm::copyFormValuesToEntity() to build a
    // throwaway WebformSubmission for its Twig template —
    // bypassing this element's plugin entirely, with no shape
    // conversion of its own. If a `return` here had skipped this
    // write-back (the previous behaviour on most checks above), that
    // temporary submission — and therefore the Twig template — would
    // see this element still in canonical {values, na} shape instead
    // of the flat map every consumer (including a Twig token like
    // `data.ranking.pizza`) expects, for as long as the ranking
    // element was in any invalid, not-yet-fully-resolved interim
    // state (e.g. mid-click, 2nd place picked before 1st).
    $form_state->setValueForElement($element, WebformRankingConverter::canonicalToMatrix([
      'values' => $values,
      'na' => $na,
    ]));
  }

}
