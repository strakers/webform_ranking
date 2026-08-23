<?php

namespace Drupal\webform_ranking\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Element\WebformElementStates;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\Utility\WebformYaml;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionConditionsValidator;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_ranking\Element\WebformRanking as WebformRankingElement;
use Drupal\webform_ranking\WebformRankingConverter;

/**
 * Provides a 'webform_ranking' element.
 *
 * @WebformElement(
 *   id = "webform_ranking",
 *   label = @Translation("Ranking"),
 *   description = @Translation("Provides a form element to rank a set of items, via a matrix of radios or a drag/drop list."),
 *   category = @Translation("Options elements"),
 *   multiline = TRUE,
 *   composite = TRUE,
 * )
 */
class WebformRanking extends WebformElementBase {

  /**
   * {@inheritdoc}
   *
   * Overrides defineDefaultProperties() (protected), not
   * getDefaultProperties() directly — the base class's public
   * getDefaultProperties() wraps this with caching and the
   * hook_webform_element_default_properties_alter() hook. An earlier
   * version of this class overrode getDefaultProperties() (and
   * getDefaultProperty(), and defineDefaultProperties() all at once,
   * redundantly) directly instead; that still technically works per
   * Webform's own deprecation notice, but silently bypasses that
   * caching/alter layer, and having three overlapping overrides was a
   * mess in its own right. Confirmed against Details, BooleanBase,
   * Address, and WebformAttachmentBase, which all consistently use
   * this pattern in current Webform.
   */
  protected function defineDefaultProperties() {
    $properties = [
      // Standard properties inherited from WebformElementBase are merged
      // automatically (title, description, required, states, wrapper
      // attributes, access, etc.) — only ranking-specific defaults here.
      'items' => [],
      'ranking_style' => 'matrix',
      'allow_na' => FALSE,
      'na_label' => (string) $this->t('N/A'),
      'rank_labels' => [],
      'randomize_item_order' => FALSE,
      'required_all' => TRUE,
    ] + parent::defineDefaultProperties();

    // The canonical {values, na} ranking value has no scalar
    // representation a "Default value" textfield could express — the
    // base class's generic textfield only ever produces a string, which
    // crashes WebformRankingConverter::canonicalToMatrix() (a typed
    // array parameter) the first time the element renders. Removing the
    // property entirely means Webform's element config form stops
    // offering it at all, rather than offering a control that can only
    // ever produce an invalid value.
    unset($properties['default_value']);

    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * Marks 'na_label' and 'rank_labels' as translatable/token-enabled.
   * Item values are deliberately excluded — they're storage keys and
   * must stay stable across languages and #states conditions. Same
   * defineTranslatableProperties() pattern as above — overrides the
   * protected hook method, not the public cached getter.
   */
  protected function defineTranslatableProperties() {
    return array_merge(parent::defineTranslatableProperties(), [
      'na_label',
      'rank_labels',
      // 'items' handled specially in buildConfigurationForm() /
      // config translation, since it's a nested sequence and only the
      // 'label' sub-key of each row should be exposed for translation.
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $webform = $form_state->getFormObject()->getWebform();

    $form['ranking'] = [
      '#type' => 'details',
      '#title' => $this->t('Ranking settings'),
      '#open' => TRUE,
      '#weight' => -10,
    ];

    // Decompose each already-saved item's 'states' YAML into the
    // quick-picker's flat 'condition_mode'/'condition_selector'/
    // 'condition_trigger'/'condition_value' keys (see the 'condition_group'
    // field below for why these are flat, not nested), so an admin
    // editing an existing simple condition sees it in the picker rather
    // than as raw YAML. Only single state + single condition shapes
    // decompose cleanly (see decomposeSimpleItemStates()) — anything more
    // complex (multiple states, multiple conditions, OR/XOR operators) is
    // left alone and still shows correctly in the 'states' YAML fallback
    // field further down, exactly as before this change. Mutating
    // 'element_properties' here (rather than #default_value directly on
    // the 'items' field below) is required because #webform_multiple
    // populates each row's sub-element defaults from this same array via
    // setConfigurationFormDefaultValueRecursive(), called by the base
    // class's buildConfigurationForm() *after* this method returns.
    $element_properties = $form_state->get('element_properties') ?? [];
    if (!empty($element_properties['items'])) {
      foreach ($element_properties['items'] as &$item) {
        if (empty($item['states'])) {
          continue;
        }
        $states = is_string($item['states']) ? WebformYaml::decode($item['states']) : $item['states'];
        $simple = is_array($states) ? $this->decomposeSimpleItemStates($states) : NULL;
        if ($simple) {
          $item['condition_mode'] = $simple['mode'];
          $item['condition_selector'] = $simple['selector'];
          $item['condition_trigger'] = $simple['trigger'];
          $item['condition_value'] = $simple['value'];
        }
      }
      unset($item);
      $form_state->set('element_properties', $element_properties);
    }

    // Admin-managed list of items to rank. Reuses Webform's own
    // "table of rows" widget pattern (as used for Options element sets)
    // rather than hand-rolled add/remove-row JS, so behavior matches
    // what admins already expect elsewhere in Webform.
    $form['ranking']['items'] = [
      '#type' => 'webform_multiple',
      '#title' => $this->t('Items to rank'),
      '#description' => $this->t('The order entered here is the default display/rank order. Item values are used as storage keys and cannot be changed once submissions exist.'),
      '#header' => TRUE,
      '#empty_items' => 3,
      '#add_more_items' => 1,
      '#element' => [
        'value' => [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#required' => TRUE,
        ],
        'label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Label'),
          '#required' => TRUE,
        ],
        // Per-item conditional inclusion. This was originally
        // 'webform_element_states' — Webform's own #states
        // condition-builder widget — nested directly inside this
        // #webform_multiple row, which crashed in production (TypeError
        // inside WebformCodeMirror::validateWebformCodeMirror(), an
        // array reaching a YAML validator expecting a string): see
        // GitHub issue #13's investigation. The crash traced specifically
        // to nesting that *composite, #tree-based, AJAX-driven* element
        // inside another #tree-based multiple-value widget — not to
        // anything about plain, non-composite fields being nested there
        // (which already works fine for 'value'/'label' above). So this
        // still avoids nesting webform_element_states itself, but
        // recreates its *visual* picker — element selector, trigger
        // (comparison operator), value — using plain select/textfield
        // sub-elements sharing its CSS classes
        // ('webform-states-table--selector'/'--trigger'/'--value')
        // and #options data sources ($webform->getElementsSelectorOptions(),
        // WebformElementStates::getTriggerOptions()). Because every
        // element edit form already unconditionally includes the
        // element-level "Conditions" tab (a real, top-level
        // webform_element_states element — see parent::form() above),
        // that field's own #attached library ('webform/webform.element.states')
        // and drupalSettings ('webformElementStates.selectors'/'.sources',
        // keyed by the same selector strings) are already present on the
        // page — so the matching-class fields below get real Webform
        // condition-builder JS (value autocomplete, trigger wiring) for
        // free, without this module attaching or reimplementing any of
        // it itself.
        //
        // Only a single state + single condition is offered here (no
        // multiple conditions, no AND/OR/XOR combining) — the raw YAML
        // field alongside it remains the escape hatch for anything more
        // complex, same role 'Edit source' plays in the real builder.
        // decomposeSimpleItemStates()/composeSimpleItemStates() convert
        // between this picker's fields and the same #states-shaped array
        // WebformRankingVisibilityResolver and buildMatrix()/
        // buildDragDrop() already expect — nothing downstream changed.
        //
        // Precedence when both are filled in (see
        // validateConfigurationForm()): the picker wins whenever
        // 'condition_selector' is set; the YAML field is only consulted
        // when the picker's selector is empty. Documented in the YAML
        // field's own #description below.
        //
        // All condition-related sub-fields (picker + YAML fallback) are
        // nested inside 'condition_group'/'condition_row' containers for
        // layout/CSS grouping only — confirmed live that #webform_multiple
        // flattens every leaf sub-element to the item's own top-level
        // array key regardless of container nesting depth (containers
        // aren't a #tree branch here, just a rendering wrapper), so each
        // leaf below is named to be unique at that flat level. An earlier
        // version of this nested 'selector'/'trigger'/'value' as bare
        // keys, which collided with the item's own pre-existing 'value'
        // key (its storage-key field above) under this same flattening —
        // caught by loading this exact form against a real webform with a
        // configured item condition, not guessed. Hence the
        // 'condition_'-prefixed leaf names throughout. A plain
        // '#type' => 'container' has none of webform_element_states' own
        // #tree/#process/AJAX machinery, so grouping fields inside one
        // doesn't reintroduce the nesting failure mode described above;
        // it's structurally the same kind of nesting as the
        // 'value'/'label' textfields already sitting in this same
        // '#element' template.
        'condition_group' => [
          '#type' => 'container',
          '#wrapper_attributes' => ['class' => ['webform-ranking-item-states-wrapper']],
          'condition_mode' => [
            '#type' => 'select',
            '#title' => $this->t('Condition'),
            '#options' => [
              'visible' => $this->t('Include this item when...'),
              'invisible' => $this->t('Hide this item when...'),
            ],
            '#default_value' => 'visible',
            '#wrapper_attributes' => ['class' => ['webform-ranking-item-condition-mode']],
          ],
          'condition_row' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['webform-states-table--condition']],
            'condition_selector' => [
              '#type' => 'select',
              '#title' => $this->t('Element'),
              '#title_display' => 'invisible',
              '#options' => $webform->getElementsSelectorOptions(),
              '#empty_option' => $this->t('- No condition (always included) -'),
              '#wrapper_attributes' => ['class' => ['webform-states-table--selector']],
            ],
            'condition_trigger' => [
              '#type' => 'select',
              '#title' => $this->t('Trigger'),
              '#title_display' => 'invisible',
              '#options' => WebformElementStates::getTriggerOptions(),
              '#default_value' => 'value',
              '#wrapper_attributes' => ['class' => ['webform-states-table--trigger']],
            ],
            'condition_value' => [
              '#type' => 'textfield',
              '#title' => $this->t('Value'),
              '#title_display' => 'invisible',
              '#size' => 20,
              '#placeholder' => $this->t('Enter value…'),
              '#description' => $this->t('Ignored for Empty/Filled/Checked/Unchecked. For Between/Not between, use the format 1:100.'),
              '#wrapper_attributes' => ['class' => ['webform-states-table--value']],
            ],
          ],
          // '#decode_value' => TRUE is load-bearing, not decorative: it's
          // what makes WebformCodeMirror::validateWebformCodeMirror()
          // (the element's own #element_validate, registered by
          // processWebformCodeMirror()) decode the submitted YAML string
          // into a real array via Yaml::decode() before it ever reaches
          // $form_state->getValue('items'). Without it, that method's
          // auto-decode branch only fires when '#default_value' already
          // happens to be an array — which it never is here, since
          // #webform_multiple populates each row's default straight from
          // stored config. Confirmed via a real bug: omitting this meant
          // $item['states'] stayed a raw YAML *string* all the way through
          // validateConfigurationForm() into saved config, and then into
          // buildMatrix()/buildDragDrop()'s '#states' assignment — Drupal's
          // FormHelper::processStates() JSON-encodes a string exactly as
          // happily as an array, so no error surfaced anywhere; the
          // condition just silently never matched (states.js can't parse
          // a JSON-encoded string as a conditions object). Same '#decode_value'
          // pattern already used by core Webform for the same reason — see
          // WebformTable.php's '#decode_value' => TRUE.
          //
          // NOT sufficient on its own for already-saved config from before
          // this fix (that path only runs at submit time) — see prepare()'s
          // read-side normalization below for the self-healing half of this
          // fix.
          'states' => [
            '#type' => 'webform_codemirror',
            '#mode' => 'yaml',
            '#decode_value' => TRUE,
            '#title' => $this->t('Advanced: Conditions (#states, YAML)'),
            '#description' => $this->t('Optional. Only used when "Element" above is left blank. Enter a #states conditions array in YAML — e.g. <code>visible:</code> on one line, then <code>  \':input[name="other_element"]\': {value: student}</code> indented beneath it.'),
            '#attributes' => ['class' => ['webform-ranking-item-states']],
          ],
        ],
      ],
      // Uniqueness of 'value' across rows is enforced in
      // validateConfigurationForm() below — #webform_multiple doesn't
      // do this on its own.
      '#attached' => ['library' => ['webform_ranking/element.itemsAdmin']],
    ];

    $form['ranking']['ranking_style'] = [
      '#type' => 'radios',
      '#title' => $this->t('Display style'),
      '#options' => [
        'matrix' => $this->t('Matrix (radio buttons, rank as column headers)'),
        'dragdrop' => $this->t('Drag and drop list'),
      ],
      '#default_value' => 'matrix',
      '#required' => TRUE,
    ];

    $form['ranking']['allow_na'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow abstaining from ranking an item (N/A)'),
    ];

    $form['ranking']['na_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('N/A option label'),
      '#default_value' => (string) $this->t('N/A'),
      '#states' => [
        'visible' => [':input[name="properties[allow_na]"]' => ['checked' => TRUE]],
      ],
    ];

    $form['ranking']['required_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require every visible item to be ranked or marked N/A'),
      '#default_value' => TRUE,
    ];

    $form['ranking']['randomize_item_order'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Randomize item order per page load'),
      '#description' => $this->t('Helps reduce position bias in survey-style rankings.'),
    ];

    $form['ranking']['rank_labels'] = [
      '#type' => 'webform_multiple',
      '#title' => $this->t('Rank position label overrides'),
      '#description' => $this->t('Optional. Leave empty to use default ordinal labels (1st, 2nd, 3rd...). If provided, supply one label per rank position, in order.'),
      '#empty_items' => 0,
      '#add_more_items' => 1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $items = $form_state->getValue('items') ?: [];
    // Resolve each item's 'condition_group' (the quick picker +
    // YAML-fallback fields added for GitHub issue #13) down to a single
    // flat 'states' key, matching the shape prepare()/buildMatrix()/
    // buildDragDrop()/WebformRankingVisibilityResolver have always
    // expected — nothing downstream of this method knows the picker
    // exists. The picker wins whenever an element selector was chosen;
    // otherwise the YAML fallback field (already decoded into an array
    // by WebformCodeMirror's own '#decode_value' handling) is used
    // as-is, exactly as before this change.
    foreach ($items as &$item) {
      $selector = trim($item['condition_selector'] ?? '');
      if ($selector !== '') {
        $item['states'] = $this->composeSimpleItemStates(
          $item['condition_mode'] ?? 'visible',
          $selector,
          $item['condition_trigger'] ?? 'value',
          $item['condition_value'] ?? ''
        );
      }
      else {
        $item['states'] = $item['states'] ?? [];
      }
      unset($item['condition_mode'], $item['condition_selector'], $item['condition_trigger'], $item['condition_value']);
    }
    unset($item);
    $form_state->setValue('items', $items);

    $values_seen = [];
    foreach ($items as $item) {
      $value = trim($item['value'] ?? '');
      if ($value === '') {
        continue;
      }
      // Item values become the webform_submission_data.property column
      // (varchar(128), part of the primary key) and are interpolated
      // directly into #states selector strings elsewhere in this class
      // (see getElementSelectorInputsOptions()); an unconstrained value
      // containing e.g. '"' or ']' can produce a broken/unparseable
      // selector. Webform's own Likert element applies the same 128
      // limit via #options_value_maxlength.
      if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $value)) {
        $form_state->setErrorByName('items', $this->t('Item values may only contain letters, numbers, underscores, hyphens and periods. "@value" is not valid.', ['@value' => $value]));
        break;
      }
      if (mb_strlen($value) > 128) {
        $form_state->setErrorByName('items', $this->t('Item values must be 128 characters or fewer. "@value" is too long.', ['@value' => $value]));
        break;
      }
      if (isset($values_seen[$value])) {
        $form_state->setErrorByName('items', $this->t('Item values must be unique. "@value" is used more than once.', ['@value' => $value]));
        break;
      }
      $values_seen[$value] = TRUE;
    }

    if (count($items) < 2) {
      $form_state->setErrorByName('items', $this->t('Provide at least two items to rank.'));
    }
  }

  /**
   * Builds a #states array from the per-item condition picker's fields.
   *
   * Mirrors the conventions \Drupal\webform\Element\WebformElementStates
   * ::getFormApiStatesCondition() uses to turn its own condition-row
   * submission into Form API #states — kept in sync deliberately, not
   * reused directly, since that method is protected and operates on a
   * full multi-state/multi-condition submission this picker deliberately
   * doesn't support (see form()'s docblock on 'condition_group').
   *
   * @param string $mode
   *   Either 'visible' or 'invisible'.
   * @param string $selector
   *   A `:input[name="..."]`-style selector, as produced by
   *   $webform->getElementsSelectorOptions().
   * @param string $trigger
   *   One of WebformElementStates::getTriggerOptions()'s keys.
   * @param string $value
   *   The comparison value; ignored for triggers that don't need one.
   *
   * @return array
   *   A #states-shaped array, e.g. ['visible' => [$selector => [...]]].
   */
  protected function composeSimpleItemStates(string $mode, string $selector, string $trigger, string $value): array {
    if (in_array($trigger, ['value', '!value'], TRUE)) {
      $condition = [$trigger => $value];
    }
    elseif (in_array($trigger, [
      'pattern', '!pattern', 'less', 'less_equal',
      'greater', 'greater_equal', 'between', '!between',
    ], TRUE)) {
      $condition = ['value' => [$trigger => $value]];
    }
    else {
      // 'empty', 'filled', 'checked', 'unchecked': no comparison value.
      $condition = [$trigger => TRUE];
    }
    return [$mode => [$selector => $condition]];
  }

  /**
   * Decomposes a #states array into the condition picker's fields, if possible.
   *
   * Only a #states array with exactly one state ('visible' or 'invisible')
   * and exactly one selector/condition decomposes cleanly — anything more
   * (multiple states, multiple selectors under one state, an explicit
   * 'and'/'or'/'xor' operator entry, or a state this module's own
   * WebformRankingVisibilityResolver doesn't act on) is left for the
   * admin to edit as YAML, same as this method's counterpart in
   * \Drupal\webform\Element\WebformElementStates
   * ::isDefaultValueCustomizedFormApiStates() falls back to its own
   * source-YAML mode for anything it can't represent visually.
   *
   * @param array $states
   *   A decoded #states array, e.g. ['visible' => [$selector => $condition]].
   *
   * @return array|null
   *   ['mode' => ..., 'selector' => ..., 'trigger' => ..., 'value' => ...],
   *   or NULL if $states isn't a single state/single condition shape.
   */
  protected function decomposeSimpleItemStates(array $states): ?array {
    if (count($states) !== 1) {
      return NULL;
    }
    $mode = key($states);
    if (!in_array($mode, ['visible', 'invisible'], TRUE)) {
      return NULL;
    }
    $conditions = reset($states);
    if (!is_array($conditions) || count($conditions) !== 1) {
      return NULL;
    }
    $selector = key($conditions);
    $condition = reset($conditions);
    if (!is_string($selector) || !is_array($condition) || count($condition) !== 1) {
      return NULL;
    }
    $trigger = key($condition);
    $value = reset($condition);

    // pattern/less/less_equal/greater/greater_equal/between/!between are
    // nested one level deeper by Form API convention: $trigger is always
    // literally 'value', with the real comparison type as the nested
    // array's own key — see composeSimpleItemStates()'s matching branch.
    // Checked before the plain 'value'/'!value' branch below, since both
    // share the literal outer key 'value' and are only distinguished by
    // whether $value is itself an array.
    if ($trigger === 'value' && is_array($value) && count($value) === 1) {
      $nested_trigger = key($value);
      $nested_value = reset($value);
      $nested_triggers = [
        'pattern', '!pattern', 'less', 'less_equal',
        'greater', 'greater_equal', 'between', '!between',
      ];
      if (in_array($nested_trigger, $nested_triggers, TRUE)
        && (is_string($nested_value) || is_numeric($nested_value))) {
        return [
          'mode' => $mode,
          'selector' => $selector,
          'trigger' => $nested_trigger,
          'value' => (string) $nested_value,
        ];
      }
      return NULL;
    }

    if (in_array($trigger, ['value', '!value'], TRUE)) {
      if (!is_string($value) && !is_numeric($value)) {
        return NULL;
      }
      return ['mode' => $mode, 'selector' => $selector, 'trigger' => $trigger, 'value' => (string) $value];
    }

    if (in_array($trigger, ['empty', 'filled', 'checked', 'unchecked'], TRUE) && $value === TRUE) {
      return ['mode' => $mode, 'selector' => $selector, 'trigger' => $trigger, 'value' => ''];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Maps stored config onto the #items / #ranking_style / etc. properties
   * the WebformRanking render element expects, and resolves conditional
   * item inclusion (see class-level note in the Element for the
   * server-side re-validation this depends on).
   */
  public function prepare(array &$element, ?WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepare($element, $webform_submission);

    $element['#items'] = $element['#items'] ?? [];
    $element['#ranking_style'] = $element['#ranking_style'] ?? 'matrix';
    $element['#allow_na'] = !empty($element['#allow_na']);
    $element['#na_label'] = $element['#na_label'] ?? $this->t('N/A');
    $element['#rank_labels'] = $element['#rank_labels'] ?? [];
    $element['#required_all'] = $element['#required_all'] ?? TRUE;

    // Seeded, not shuffle()'s own unseeded randomness: prepare() runs on
    // every build of this element, including validation-error rebuilds,
    // AJAX rebuilds, and wizard-step navigation within the *same* form
    // session — an unseeded shuffle() would reorder the rows on every
    // one of those, jumping the user's already-made selections to new
    // positions and undermining the bias-reduction rationale for this
    // feature in the first place. Seeding from the submission's own
    // UUID (stable for the lifetime of one in-progress submission,
    // Drupal assigns it at entity creation before the form is even
    // built) keeps the order stable within a session while still
    // varying between different respondents. mt_srand() with no
    // argument at the end reseeds from system entropy afterward, so
    // this doesn't leave PHP's global RNG state deterministic for any
    // unrelated code running later in the same request.
    if (!empty($element['#randomize_item_order'])) {
      mt_srand(crc32($webform_submission ? $webform_submission->uuid() : ''));
      shuffle($element['#items']);
      mt_srand();
    }

    // Self-healing normalization for config saved before 'states'
    // #decode_value => TRUE existed on the admin form (see form()'s
    // docblock for that field): older saved items can still have
    // 'states' as a raw YAML *string* rather than a decoded array.
    // Both buildMatrix()/buildDragDrop() (which assign this directly to
    // a sub-element's '#states') and WebformRankingVisibilityResolver
    // (which passes it to WebformSubmissionConditionsValidator
    // ::validateConditions(array $conditions, ...), a strictly
    // array-typed parameter) require a real array — a leftover string
    // silently produces an unparseable #states value client-side, and
    // a TypeError server-side. Normalizing here, once, covers both:
    // every consumer reads '#items' from this same prepared $element.
    foreach ($element['#items'] as &$item) {
      if (isset($item['states']) && is_string($item['states'])) {
        $item['states'] = WebformYaml::decode($item['states']);
      }
    }
    unset($item);

    // Webform's submission storage only persists composite elements as
    // a flat map of scalar-valued properties (see
    // WebformSubmissionStorage::saveData()) — it has no way to store
    // the canonical {values, na} shape (both keys are arrays)
    // without corrupting it. WebformRanking::validateWebformRanking()
    // therefore hands off the flat item-value => rank shape (same as
    // WebformRankingConverter::canonicalToMatrix()) as the element's
    // final #value, which is what ends up in a saved submission and
    // is what #default_value arrives as here when editing an existing
    // submission. Convert it back to canonical before buildMatrix(),
    // buildDragDrop() and valueCallback()'s no-input fallback see it —
    // all three expect canonical shape. Guarded with is_array() since
    // a never-submitted element's #default_value may still be the
    // base class's default empty string, and matrixToCanonical() is
    // otherwise tolerant of missing/malformed per-item entries (see
    // its own docblock) so a partially-populated stored value here —
    // e.g. an item added to configuration after this submission was
    // saved — degrades to "not yet accounted for" rather than erroring.
    //
    // Defence in depth against a non-array #default_value reaching
    // buildMatrix()/buildDragDrop()/valueCallback() (all three require
    // canonical {values, na} array shape): defineDefaultProperties()
    // above removes the admin-facing "Default value" textfield, but
    // existing config saved before that fix, or a value set
    // programmatically/by another module's alter hook, could still be a
    // scalar here. Normalizing unconditionally (not gated on !empty())
    // also fixes a bare [] default value being left un-normalized to
    // canonical shape, which is inconsistent with what every other
    // consumer expects.
    $element['#default_value'] = is_array($element['#default_value'] ?? NULL)
      ? WebformRankingConverter::matrixToCanonical($element['#default_value'])
      : ['values' => [], 'na' => []];
  }

  /**
   * {@inheritdoc}
   *
   * Exposes one selector per item ("Ranking [Ranking] > Item A (rank)")
   * instead of the whole element as a single scalar comparison target.
   * This is the actual fix for the Array-to-string crash seen on
   * Likert: an admin building a condition is only ever offered
   * sub-selectors that resolve to a real DOM input with a scalar
   * value, never the composite array as a whole.
   *
   * This overrides getElementSelectorInputsOptions() (protected).
   * That, not getElementSelectorOptions(), is the extension point
   * WebformElementBase actually builds around: its own
   * getElementSelectorOptions() calls this method, and if it returns a
   * non-empty array, wraps each entry as
   * `:input[name="{$name}[{$input_name}]"]` and nests the whole set
   * under the element's title. Left un-overridden (as this class
   * previously did, instead overriding getElementSelectorOptions()
   * itself and appending to parent::getElementSelectorOptions()'s
   * result), the base class falls into its other branch and returns a
   * single bogus selector matching no real DOM input
   * (`:input[name="{$name}"]`) — that bogus entry then sat alongside
   * this class's real per-item selectors as unrelated flat top-level
   * entries, ungrouped, unlike every other composite element in
   * Webform.
   *
   * Both display styles are covered, but via different real DOM
   * inputs:
   * - Matrix: each row's radios element is already a real,
   *   individually-named DOM input (`{key}[matrix][{item}]`), so no
   *   extra data is needed — states.js and the server-side conditions
   *   validator evaluate it exactly like any other radios field.
   * - Drag/drop: an item's rank has no equivalent real input of its
   *   own — it only exists as its position within a comma-joined
   *   hidden input (see WebformRankingConverter), and states.js has no
   *   way to express "parse this CSV value and check whether item X
   *   sits at index 1." Selectors here instead point at a second,
   *   purely-derived per-item hidden input (`{key}[dragdrop][rank][{item}]`)
   *   that buildDragDrop()/element.dragdrop's sync() keep in lockstep
   *   with the real 'order'/'na' inputs specifically so #states has
   *   something real to bind to. See buildDragDrop()'s docblock for
   *   the staleness risk this duplication carries and why it's
   *   confined to one write path.
   *
   * Selector bug fixed previously (matrix): this used to build
   * "{key}[matrix][{item}][rank]", a trailing `[rank]` suffix that
   * never matched any real DOM input — each matrix row's radios share
   * the row's own `#parents` directly (`{key}[matrix][{item}]`, no
   * `[rank]` segment; see buildMatrix()).
   */
  protected function getElementSelectorInputsOptions(array $element) {
    $style = $element['#ranking_style'] ?? 'matrix';
    if ($style !== 'matrix' && $style !== 'dragdrop') {
      return [];
    }

    $inputs = [];
    foreach ($element['#items'] ?? [] as $item) {
      $input_name = $style === 'dragdrop'
        ? "dragdrop][rank][{$item['value']}"
        : "matrix][{$item['value']}";
      $inputs[$input_name] = $this->t('@item (rank)', ['@item' => $item['label']]);
    }

    return $inputs;
  }

  /**
   * Resolves a scalar comparison value for a single item's rank.
   *
   * Companion to getElementSelectorOptions(): when the *server-side*
   * conditions validator (not states.js — this is the PHP-side
   * equivalent used during validation/access checks) needs "what is
   * item A's rank in this submission," it should call this rather than
   * reading stored data directly. Returns 'na', a 1-based rank string,
   * or NULL if the item currently has no value.
   *
   * @param array $data
   *   A submission's stored element data for this element, e.g.
   *   $webform_submission->getElementData($key) — already in the flat
   *   item-value => rank shape (see prepare()'s #default_value note
   *   and WebformRankingConverter's storage-boundary docs), NOT the
   *   {values, na} canonical shape used internally by
   *   validateWebformRanking(). Defensively tolerant of a missing or
   *   not-yet-array value (e.g. a submission that never touched this
   *   element) rather than assuming the shape is always fully formed.
   * @param string $item_value
   *   The item's storage key.
   */
  public function getItemRankValue(array $data, string $item_value): ?string {
    $value = $data[$item_value] ?? NULL;
    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Without this override, the server-side conditions validator falls
   * back to WebformElementBase's generic composite-key extraction,
   * which assumes a composite's sub-properties are known, fixed keys
   * (e.g. WebformName's 'first'/'last') and reduces stored data via
   * `$value[$composite_key]`. Our per-item selectors (see
   * getElementSelectorInputsOptions()) use the item's *value* as the
   * third selector segment (e.g. "preference[matrix][pizza]"), which isn't
   * a real key in that sense — it doesn't match anything in the flat
   * item-value => rank storage map, and the generic extraction can't
   * reduce it. Confirmed via watchdog: the whole flat map reached
   * checkConditionTrigger() as $element_value, which then hit
   * `(string) $element_value`, an array, producing PHP's "Array to
   * string conversion" warning — the trigger condition still
   * evaluated (comparing the string "Array" against the trigger's
   * rank value), just never correctly.
   *
   * Handles both matrix per-item selectors
   * ("{key}[matrix][{item}]") and drag/drop per-item rank-echo
   * selectors ("{key}[dragdrop][rank][{item}]", see
   * getElementSelectorInputsOptions()'s docblock and
   * WebformRanking::buildDragDrop() for what that echo input is and
   * why it exists). Either way the item value is the resolved via
   * getItemRankValue() against the submission's stored data — storage
   * is unconditionally the flat matrix-shaped map regardless of
   * display style (validateWebformRanking() always finishes with
   * canonicalToMatrix()), so no style branching is needed once the
   * item value is extracted from the selector. Anything else defers
   * to the parent implementation.
   */
  public function getElementSelectorInputValue($selector, $trigger, array $element, WebformSubmissionInterface $webform_submission) {
    $input_name = WebformSubmissionConditionsValidator::getSelectorInputName($selector);
    $parts = $input_name ? WebformSubmissionConditionsValidator::getInputNameAsArray($input_name) : [];

    $item_value = NULL;
    if (($parts[1] ?? NULL) === 'matrix' && isset($parts[2])) {
      $item_value = $parts[2];
    }
    elseif (($parts[1] ?? NULL) === 'dragdrop' && ($parts[2] ?? NULL) === 'rank' && isset($parts[3])) {
      $item_value = $parts[3];
    }

    if ($item_value !== NULL) {
      $data = $webform_submission->getElementData($element['#webform_key']);
      return $this->getItemRankValue(is_array($data) ? $data : [], $item_value);
    }

    return parent::getElementSelectorInputValue($selector, $trigger, $element, $webform_submission);
  }

  /**
   * {@inheritdoc}
   *
   * Without this override, WebformSubmissionGenerate's generic
   * name/type-based fallback guesses at a test value for this element
   * (there's no 'webform_ranking' entry in its lookup tables) and can
   * hand back an arbitrary scalar string — which then reaches
   * WebformRankingConverter::canonicalToMatrix() (via #default_value on
   * the Test-tab-generated form) and fails its array type hint, a real
   * error caught live via the "Test" tab after marking this element
   * composite. Generating a real full random ranking here, in the same
   * flat item-value => rank shape #default_value is expected to arrive
   * in (see prepare()'s note), avoids that entirely. Same
   * wrap-in-an-array return shape as WebformLikert::getTestValues() —
   * WebformSubmissionGenerate::getTestValue() treats the return as a
   * list of candidate composite values to pick one from.
   */
  public function getTestValues(array $element, WebformInterface $webform, array $options = []) {
    $items = $element['#items'] ?? [];
    if (empty($items)) {
      return NULL;
    }

    $values = array_column($items, 'value');
    if (!empty($options['random'])) {
      shuffle($values);
    }

    $value = [];
    foreach ($values as $delta => $item_value) {
      $value[$item_value] = (string) ($delta + 1);
    }
    return [$value];
  }

  /**
   * {@inheritdoc}
   *
   * Without this override, the base class's default formatHtmlItem()/
   * formatTextItem() treat getValue()'s return as a scalar —
   * formatTextItem() concatenates #field_prefix/#field_suffix onto it,
   * then formatHtmlItem() wraps the result in `#plain_text` — but our
   * stored value is the flat item-value => rank map (see
   * WebformRankingConverter's storage-boundary docs), an array. Handing
   * an array to `#plain_text` hit a real TypeError in
   * Html::escape()/Renderer::ensureMarkupIsSafe() on the submission
   * "View"/results page, caught live after marking this element
   * composite. Renders items in *rank* order (1st, 2nd, ... then N/A,
   * then never-accounted-for) rather than configured order — each line
   * is still self-labeled ("Pizza: 1st"), so nothing is lost by
   * reordering, and rank order is what actually answers "how was this
   * ranked" at a glance. See WebformRankingConverter::orderByRank().
   */
  protected function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    $value = is_array($value) ? $value : [];
    $items = WebformRankingConverter::orderByRank($element['#items'] ?? [], $value);

    if ($this->getItemFormat($element) === 'raw') {
      $rows = [];
      foreach ($items as $item) {
        $rows[$item['value']] = ['#markup' => $item['value'] . ': ' . ($value[$item['value']] ?? '')];
      }
      return ['#theme' => 'item_list', '#items' => $rows];
    }

    $rank_labels = WebformRankingElement::getRankLabels($element, count($items));
    $rows = [];
    foreach ($items as $item) {
      $rows[$item['value']] = [
        '#markup' => $item['label'] . ': ' . $this->resolveRankDisplay($element, $rank_labels, $value[$item['value']] ?? NULL),
      ];
    }
    return ['#theme' => 'item_list', '#items' => $rows];
  }

  /**
   * {@inheritdoc}
   *
   * Plain-text counterpart to formatHtmlItem() — see its docblock for
   * why this override exists at all, and
   * WebformRankingConverter::orderByRank() for the rank-order
   * rationale.
   */
  protected function formatTextItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);
    $value = is_array($value) ? $value : [];
    $items = WebformRankingConverter::orderByRank($element['#items'] ?? [], $value);

    if ($this->getItemFormat($element) === 'raw') {
      $lines = [];
      foreach ($items as $item) {
        $lines[] = $item['value'] . ': ' . ($value[$item['value']] ?? '');
      }
      return implode(PHP_EOL, $lines);
    }

    $rank_labels = WebformRankingElement::getRankLabels($element, count($items));
    $lines = [];
    foreach ($items as $item) {
      $lines[] = $item['label'] . ': ' . $this->resolveRankDisplay($element, $rank_labels, $value[$item['value']] ?? NULL);
    }
    return implode(PHP_EOL, $lines);
  }

  /**
   * Resolves the display string for one item's stored rank value.
   *
   * Shared by formatHtmlItem()/formatTextItem() so "na" vs. a numeric
   * rank vs. "never accounted for" (e.g. conditionally hidden when
   * submitted, or an item added to configuration after this submission
   * was saved) are described consistently in both.
   *
   * @param array $element
   *   The element, for #na_label.
   * @param array $rank_labels
   *   Rank position labels, per WebformRanking::getRankLabels() —
   *   0-indexed, so rank '1' reads index 0.
   * @param mixed $rank
   *   The item's stored rank value ('na', a numeric rank string, or
   *   NULL/absent if never accounted for).
   */
  private function resolveRankDisplay(array $element, array $rank_labels, $rank): string {
    if ($rank === 'na') {
      return (string) ($element['#na_label'] ?? $this->t('N/A'));
    }
    if (is_numeric($rank) && isset($rank_labels[((int) $rank) - 1])) {
      return (string) $rank_labels[((int) $rank) - 1];
    }
    return (string) $this->t('Not ranked');
  }

}
