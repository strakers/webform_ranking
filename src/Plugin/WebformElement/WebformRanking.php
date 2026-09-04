<?php

namespace Drupal\webform_ranking\Plugin\WebformElement;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
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
   * State keys the per-item condition picker's dropdown offers.
   *
   * A subset of WebformElementStates::getStateOptions()'s full list —
   * excludes states aimed at form *inputs* (Read-only/Expanded/
   * Collapsed/Checked/Unchecked/Required/Optional), which don't read
   * sensibly for an item-inclusion condition; item required-ness is
   * derived from #required_all, not an independent per-item condition,
   * and Required/Optional specifically crashed submission (GitHub
   * #102). Only visible/invisible actually affect inclusion (see
   * self::VISIBILITY_STATE_KEYS); the rest are accepted like any other
   * #states value, matching the real widget's own flexibility, but are
   * a no-op here — the picker surfaces a note when one is selected
   * rather than hiding them outright. See
   * docs/adr/0018-remove-required-optional-states-mirror.md.
   */
  const PICKER_STATE_KEYS = [
    'visible', 'invisible', 'visible-slide', 'invisible-slide',
    'enabled', 'disabled',
  ];

  /**
   * The subset of self::PICKER_STATE_KEYS that affects item inclusion.
   *
   * An explicit list, not derived from PICKER_STATE_KEYS by position, so
   * it stays correct if that list's order/membership ever changes. Sent
   * to items_admin.js via drupalSettings as the single source for its
   * own visibility-state check.
   *
   * Deliberately NOT unified with WebformRankingVisibilityResolver
   * ::isVisible()'s own runtime check, which strips '!'/'-slide'
   * suffixes and compares the base instead — that generalizes to future
   * suffix variants without a list update, which this enumeration-only
   * use case (populating a dropdown) doesn't need. Two equally
   * intentional ways of expressing the same semantic set, not a gap.
   */
  const VISIBILITY_STATE_KEYS = [
    'visible', 'invisible', 'visible-slide', 'invisible-slide',
  ];

  /**
   * Trigger keys nested one level deeper by Form API convention.
   *
   * See decomposeCondition()'s own docblock. Shared with items_admin.js
   * via drupalSettings (see form()) as the single source for its own
   * NESTED_TRIGGERS classification, rather than a second hand-typed JS
   * copy that could silently drift if Webform core ever adds/renames a
   * trigger type.
   */
  const NESTED_TRIGGER_KEYS = [
    'pattern', '!pattern', 'less', 'less_equal',
    'greater', 'greater_equal', 'between', '!between',
  ];

  /**
   * Trigger keys that carry a bare boolean, no comparison value.
   *
   * Same shared-source-of-truth rationale as self::NESTED_TRIGGER_KEYS
   * above.
   */
  const NO_VALUE_TRIGGER_KEYS = ['empty', 'filled', 'checked', 'unchecked'];

  /**
   * {@inheritdoc}
   *
   * Overrides defineDefaultProperties() (protected), not
   * getDefaultProperties() directly — the public getter wraps this with
   * caching and hook_webform_element_default_properties_alter(), which
   * an earlier version's direct getDefaultProperties() override
   * silently bypassed. Confirmed as the consistent pattern against
   * Details, BooleanBase, Address, and WebformAttachmentBase.
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
      'require_first_place' => FALSE,
      'require_first_place_error' => '',
      'sequential_ranks_error' => '',
      // Kept declared (unlike an earlier revision — see GitHub #129):
      // WebformSubmissionForm::populateElements()'s only gate for
      // repopulating #default_value from a saved submission on any
      // rebuild (wizard back-navigation, draft resume, edit) is
      // hasProperty('default_value'), i.e. this key's mere presence
      // here — removing it silently broke that for every rebuild, not
      // just the admin config widget it was meant to suppress. See
      // ADR-0024. [] matches WebformLikert's own default and is safe:
      // WebformRankingConverter::matrixToCanonical([]) already resolves
      // to the correct empty canonical shape.
      'default_value' => [],
    ] + parent::defineDefaultProperties();

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
      'require_first_place_error',
      'sequential_ranks_error',
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

    // 'default_value' stays declared in defineDefaultProperties() now
    // (GitHub #129/ADR-0024) so Webform core's own submission-data
    // repopulation still works — this only removes the generic admin
    // widget parent::form() built for it, since a scalar textfield/YAML
    // value here still can't safely express this element's canonical
    // {values, na} shape. Unsetting the built field directly, rather
    // than re-removing the property, keeps that repopulation intact.
    unset($form['default']['default_value']);

    // Requires a form object with getWebform() — true for every real
    // caller (WebformUiElementFormBase and its WebformUiElementTestForm
    // subclass), but not guaranteed by the base class's own form()
    // signature. A direct call with a bare FormState (no form object
    // set) fatals here rather than failing gracefully; not defended
    // against, since no such call path currently exists — noted so a
    // future one doesn't hit this as a surprise.
    $webform = $form_state->getFormObject()->getWebform();

    $form['ranking'] = [
      '#type' => 'details',
      '#title' => $this->t('Ranking settings'),
      '#open' => TRUE,
      '#weight' => -10,
    ];

    // Precomputed, per-item decomposition of each already-saved item's
    // 'states' YAML into the picker's row/condition shape, keyed by
    // item value and attached below as drupalSettings — see
    // docs/adr/0005-condition-lookup-table-and-live-values.md for why
    // (the #webform_multiple shared-template constraint this sidesteps,
    // and why live submitted values are preferred over the saved-entity
    // snapshot, GitHub issue #79). Anything not single-state/decomposable
    // (see decomposeItemStatesToConditions()) is simply omitted; the
    // dialog then falls back to the raw YAML view for that item.
    $element_properties = $form_state->get('element_properties') ?? [];
    $conditions_by_value = [];
    $live_items = $form_state->getValue(['items', 'items']);
    $items_source = is_array($live_items) ? $live_items : ($element_properties['items'] ?? []);
    foreach ($items_source as $item) {
      $value = trim($item['value'] ?? '');
      if ($value === '' || empty($item['states'])) {
        continue;
      }
      // A live value (unlike the saved-entity snapshot) may be
      // genuinely invalid YAML mid-edit — decoding must not fatal the
      // whole AJAX rebuild over one item's temporarily-invalid text;
      // see docs/adr/0005-condition-lookup-table-and-live-values.md.
      try {
        $states = is_string($item['states']) ? WebformYaml::decode($item['states']) : $item['states'];
      }
      catch (InvalidDataTypeException) {
        continue;
      }
      $decomposed = is_array($states) ? $this->decomposeItemStatesToConditions($states) : NULL;
      if ($decomposed !== NULL) {
        $conditions_by_value[$value] = $decomposed;
      }
    }

    // The picker's "State" dropdown — see self::PICKER_STATE_KEYS.
    // getStateOptions() returns its options grouped by optgroup label
    // (Visibility/State/Validation/Value); OptGroup::flattenOptions()
    // (the same core helper Form API's own <select> processing uses to
    // resolve a submitted value against a grouped '#options' array)
    // collapses that down to a single flat key => label array first, then
    // array_intersect_key() keeps only the picker's own subset in that
    // same (already correctly ordered) flattened array.
    $state_options = array_intersect_key(
      array_map('strval', OptGroup::flattenOptions(WebformElementStates::getStateOptions())),
      array_flip(self::PICKER_STATE_KEYS)
    );

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
        // Per-item conditional inclusion, presented in a dialog rather
        // than inline (issue #4: a raw per-item YAML field was too much
        // visual clutter). Field type/position are unchanged from an
        // earlier crashed attempt using Webform's own
        // 'webform_element_states' widget nested here directly — see
        // docs/adr/0003-per-item-states-field-design.md for why, and
        // for why '#decode_value' => TRUE below is load-bearing (not
        // decorative): removing it lets a saved condition silently stop
        // matching, with no error anywhere.
        'states' => [
          '#type' => 'webform_codemirror',
          '#mode' => 'yaml',
          '#decode_value' => TRUE,
          '#title' => $this->t('Include this item when (#states, YAML)'),
          '#description' => $this->t('Optional, advanced. Enter a #states conditions array in YAML — e.g. <code>visible:</code> on one line, then <code>  \':input[name="other_element"]\': {value: student}</code> indented beneath it.'),
          '#wrapper_attributes' => ['class' => ['webform-ranking-item-states-wrapper']],
          '#attributes' => ['class' => ['webform-ranking-item-states']],
        ],
      ],
      // Uniqueness of 'value' across rows is enforced in
      // validateConfigurationForm() below — #webform_multiple doesn't
      // do this on its own.
      //
      // drupalSettings feeds items_admin.js's condition-rows builder
      // (rows aren't server-rendered per-row here). Trigger/state
      // options are cast to plain strings since TranslatableMarkup
      // doesn't survive JSON encoding. 'webformModulePath' is computed
      // server-side (not guessed client-side) since the install path
      // isn't guaranteed to match the common case. The *Keys constants
      // are sent so items_admin.js reads its trigger/state
      // classification from this one PHP source instead of a second
      // hand-typed copy (GitHub issue #83).
      '#attached' => [
        'library' => ['webform_ranking/element.itemsAdmin'],
        'drupalSettings' => [
          'webformRankingItemsAdmin' => [
            'conditionsByItemValue' => $conditions_by_value,
            'stateOptions' => $state_options,
            'selectorOptions' => $webform->getElementsSelectorOptions(),
            'triggerOptions' => array_map('strval', WebformElementStates::getTriggerOptions()),
            'webformModulePath' => \Drupal::service('extension.list.module')->getPath('webform'),
            'nestedTriggerKeys' => self::NESTED_TRIGGER_KEYS,
            'noValueTriggerKeys' => self::NO_VALUE_TRIGGER_KEYS,
            'visibilityStateKeys' => self::VISIBILITY_STATE_KEYS,
          ],
        ],
      ],
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

    // GitHub issue #108: hidden for drag/drop, where it's a structural
    // no-op — every drag/drop item always lands in the ranked order or
    // the N/A list, so "every visible item accounted for" can never
    // fail there. UI-only: the stored value is left untouched so it's
    // preserved if the site builder switches back to matrix later. See
    // docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-state.md.
    $form['ranking']['required_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require every visible item to be ranked or marked N/A'),
      '#default_value' => TRUE,
      '#states' => [
        'visible' => [':input[name="properties[ranking_style]"]' => ['value' => 'matrix']],
      ],
    ];

    // GitHub issue #63: #required_all alone lets a respondent mark
    // every item N/A and satisfy validation without ranking anything —
    // this is a separate, independent "must pick a top choice" toggle.
    // Deliberately not #states-gated on #allow_na/#required_all: it's
    // only ever a true no-op when both are off/on respectively, and
    // still meaningfully applies in every other combination.
    $form['ranking']['require_first_place'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require at least one item to be ranked 1st'),
      '#description' => $this->t('Ensures at least one item is ranked, even if not every item needs to be. Most useful alongside "Allow abstaining", which otherwise lets every item be marked N/A without ranking anything.'),
    ];

    $form['ranking']['require_first_place_error'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Require 1st place error message'),
      '#description' => $this->t('If set, this error message appears when there is an error in validation for "Require at least one item to be ranked 1st" — instead of the default "Field x requires at least one item to be ranked 1st." message.'),
      '#states' => [
        'visible' => [':input[name="properties[require_first_place]"]' => ['checked' => TRUE]],
      ],
    ];

    // GitHub issue #74: always visible, not #states-gated on
    // #ranking_style, even though it's a no-op for drag/drop (matrix-only
    // check — see matrixRanksAreSequential()'s docblock). Unlike
    // #required_all above (see GitHub issue #108), this field has no
    // sibling meaning for drag/drop to preserve visibility of, so it's
    // simply left inert rather than hidden.
    $form['ranking']['sequential_ranks_error'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sequential ranks error message'),
      '#description' => $this->t('If set, this error message appears when there is an error in validation for out-of-order or skipped rankings (matrix display style only) — instead of the default "Items in Field x must be ranked in order (1st, 2nd, 3rd, etc.), without skipping any positions." message (which adds a sentence about N/A being available when "Allow abstaining" is enabled).'),
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
   * Decomposes a #states array into the condition picker's row shape.
   *
   * GitHub issue #65: feeds the per-item condition-rows builder's
   * initial display for an already-saved condition. Only a single state
   * (one of self::PICKER_STATE_KEYS) with one or more conditions
   * decomposes; anything else (multiple states, an unrecognized
   * trigger, mixed AND/OR/XOR operators, malformed structure) returns
   * NULL, and the picker falls back to showing the raw YAML view — same
   * role \Drupal\webform\Element\WebformElementStates
   * ::isDefaultValueCustomizedFormApiStates() plays for the real
   * widget.
   *
   * Mirrors (in reverse) the shape conventions
   * \Drupal\webform\Element\WebformElementStates
   * ::convertElementValueToFormApiStates() produces:
   * - A single condition, or 2+ ANDed conditions: an associative array,
   *   `[$selector => [$trigger => $value]]` per condition.
   * - 2+ ORed/XORed conditions: a numerically-indexed array alternating
   *   a single-key `[$selector => [$trigger => $value]]` wrapper with
   *   the literal string `'or'`/`'xor'` between each pair.
   *
   * @param array $states
   *   A decoded #states array, e.g. ['visible' => [...]].
   *
   * @return array|null
   *   ['mode' => one of self::PICKER_STATE_KEYS, 'operator' =>
   *   'and'|'or'|'xor', 'conditions' => [['selector' => ..., 'trigger'
   *   => ..., 'value' => ...], ...]], or NULL if not decomposable.
   */
  protected function decomposeItemStatesToConditions(array $states): ?array {
    if (count($states) !== 1) {
      return NULL;
    }
    $mode = key($states);
    if (!in_array($mode, self::PICKER_STATE_KEYS, TRUE)) {
      return NULL;
    }
    $conditions_raw = reset($states);
    if (!is_array($conditions_raw) || empty($conditions_raw)) {
      return NULL;
    }

    $is_indexed = array_is_list($conditions_raw);

    $conditions = [];
    $operator = 'and';

    if (!$is_indexed) {
      // AND shape (or a single condition): selector => condition.
      foreach ($conditions_raw as $selector => $condition) {
        if (!is_string($selector) || !is_array($condition)) {
          return NULL;
        }
        $decoded = $this->decomposeCondition($selector, $condition);
        if ($decoded === NULL) {
          return NULL;
        }
        $conditions[] = $decoded;
      }
    }
    else {
      // OR/XOR shape: alternating [selector => condition] wrapper and
      // the literal operator string, always starting and ending on a
      // wrapper.
      $expect_condition = TRUE;
      foreach ($conditions_raw as $entry) {
        if ($expect_condition) {
          if (!is_array($entry) || count($entry) !== 1) {
            return NULL;
          }
          $selector = key($entry);
          $condition = reset($entry);
          if (!is_string($selector) || !is_array($condition)) {
            return NULL;
          }
          $decoded = $this->decomposeCondition($selector, $condition);
          if ($decoded === NULL) {
            return NULL;
          }
          $conditions[] = $decoded;
        }
        else {
          // Deliberately only 'or'/'xor' here, not 'and': an indexed
          // list with a literal 'and' token isn't valid/meaningful
          // #states syntax at all — traced Drupal core's actual
          // client-side evaluator (web/core/misc/states.js's
          // verifyConstraints()) and confirmed an indexed array only
          // ever means OR (default) or XOR (if the literal 'xor' token
          // is present); any other string entry, including a stray
          // 'and', is silently treated as an inert no-op condition, not
          // an AND operator. Accepting 'and' here would let a condition
          // decompose successfully and then silently misbehave at
          // runtime — see docs/CONTINUATION.md entry 27 ("Reclassified,
          // not fixed") for the full investigation this line encodes.
          if (!is_string($entry) || !in_array($entry, ['or', 'xor'], TRUE)) {
            return NULL;
          }
          // Mixed operators (some 'or', some 'xor') within one state
          // aren't representable by the picker's single operator
          // dropdown.
          if (count($conditions) > 1 && $entry !== $operator) {
            return NULL;
          }
          $operator = $entry;
        }
        $expect_condition = !$expect_condition;
      }
      // A valid list always ends on a condition, meaning the NEXT
      // expected entry (had the list continued) would be an operator —
      // i.e. $expect_condition is FALSE here. A trailing operator with
      // nothing after it ($expect_condition still TRUE) isn't a valid
      // OR/XOR shape, nor is fewer than 2 conditions.
      if ($expect_condition || count($conditions) < 2) {
        return NULL;
      }
    }

    return [
      'mode' => $mode,
      'operator' => $operator,
      'conditions' => $conditions,
    ];
  }

  /**
   * Decomposes one selector => condition pair for the picker's rows.
   *
   * @param string $selector
   *   A `:input[name="..."]`-style selector.
   * @param array $condition
   *   The single-key `[$trigger => $value]` condition array.
   *
   * @return array|null
   *   ['selector' => ..., 'trigger' => ..., 'value' => ...], or NULL if
   *   $condition isn't a shape the picker can represent.
   */
  protected function decomposeCondition(string $selector, array $condition): ?array {
    if (count($condition) !== 1) {
      return NULL;
    }
    $trigger = key($condition);
    $value = reset($condition);

    // pattern/less/less_equal/greater/greater_equal/between/!between are
    // nested one level deeper by Form API convention: $trigger is
    // always literally 'value', with the real comparison type as the
    // nested array's own key.
    if ($trigger === 'value' && is_array($value) && count($value) === 1) {
      $nested_trigger = key($value);
      $nested_value = reset($value);
      if (in_array($nested_trigger, self::NESTED_TRIGGER_KEYS, TRUE)
        && (is_string($nested_value) || is_numeric($nested_value))) {
        return [
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
      return ['selector' => $selector, 'trigger' => $trigger, 'value' => (string) $value];
    }

    if (in_array($trigger, self::NO_VALUE_TRIGGER_KEYS, TRUE) && $value === TRUE) {
      return ['selector' => $selector, 'trigger' => $trigger, 'value' => ''];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $items = $form_state->getValue('items') ?: [];
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

    // GitHub issue #102: 'required'/'optional' as a condition's #states
    // key crashes at submission time regardless of how it got there —
    // the picker no longer offers them (see PICKER_STATE_KEYS), so this
    // catches the raw YAML "Edit source" path. See
    // docs/adr/0018-remove-required-optional-states-mirror.md.
    foreach ($items as $item) {
      $states = $item['states'] ?? [];
      if (is_string($states)) {
        try {
          $states = WebformYaml::decode($states) ?? [];
        }
        catch (InvalidDataTypeException) {
          continue;
        }
      }
      if (is_array($states) && (array_key_exists('required', $states) || array_key_exists('optional', $states))) {
        $form_state->setErrorByName('items', $this->t('Item "@label": the "Include this item when" condition may not use the Required or Optional state. Use Visible/Hidden (or their Slide variants) instead.', ['@label' => $item['label'] ?? $item['value'] ?? '']));
        break;
      }
    }
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

    // Seeded from the submission's own UUID, not shuffle()'s unseeded
    // randomness: prepare() re-runs on every AJAX rebuild/wizard step
    // within the *same* form session, and an unseeded shuffle() would
    // reorder rows on every one of those, jumping the respondent's
    // already-made selections around. The trailing mt_srand() reseeds
    // from system entropy so this doesn't leave PHP's global RNG state
    // deterministic for unrelated code later in the same request.
    if (!empty($element['#randomize_item_order'])) {
      mt_srand(crc32($webform_submission ? $webform_submission->uuid() : ''));
      shuffle($element['#items']);
      mt_srand();
    }

    // Self-healing for config saved before 'states' #decode_value =>
    // TRUE existed (see form()'s docblock, ADR-0003): older items can
    // still have 'states' as a raw YAML string. buildMatrix()/
    // buildDragDrop() and WebformRankingVisibilityResolver both require
    // a real array; normalizing here, once, covers every consumer.
    foreach ($element['#items'] as &$item) {
      if (isset($item['states']) && is_string($item['states'])) {
        $item['states'] = WebformYaml::decode($item['states']);
      }
    }
    unset($item);

    // Submission storage only persists a flat scalar map (see
    // WebformRankingConverter's storage-boundary docs) — #default_value
    // arrives here in that same flat shape when editing an existing
    // submission, but buildMatrix()/buildDragDrop()/valueCallback() all
    // expect canonical {values, na} shape, hence the conversion back.
    // is_array() guards a never-submitted element's default (still the
    // base class's empty-string default) and any pre-defineDefaultProperties()
    // config/alter-hook-set scalar; unconditional (not !empty()-gated)
    // so a bare [] also gets normalized to canonical shape.
    $element['#default_value'] = is_array($element['#default_value'] ?? NULL)
      ? WebformRankingConverter::matrixToCanonical($element['#default_value'])
      : ['values' => [], 'na' => []];
  }

  /**
   * {@inheritdoc}
   *
   * Overrides getElementSelectorInputsOptions() (protected), not
   * getElementSelectorOptions() — the former is the real extension point
   * WebformElementBase builds around; overriding the latter falls into
   * a different base-class branch that returns one bogus selector
   * matching no real DOM input. Exposes one selector per item instead
   * of the whole composite value, resolving via each display style's
   * own real DOM input (drag/drop's rank has no input of its own, so a
   * synced per-item echo input exists just to give #states something
   * to bind to — see buildDragDrop()). See
   * docs/adr/0004-composite-element-states-selector-bridging.md for the
   * full history (the Array-to-string crash this fixes, and the
   * trailing-[rank]-suffix bug fixed previously).
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
   * which assumes fixed sub-property keys and can't resolve this
   * element's per-item-value selector scheme — the same
   * "Array to string conversion" failure this fixes, confirmed live via
   * watchdog. Parses the selector's matrix/dragdrop segment and item
   * value directly, then resolves via getItemRankValue() against the
   * flat matrix-shaped storage (the same regardless of display style).
   * See docs/adr/0004-composite-element-states-selector-bridging.md.
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
   * Without this override, WebformSubmissionGenerate's generic fallback
   * hands back an arbitrary scalar string (no 'webform_ranking' entry in
   * its lookup tables), which fails canonicalToMatrix()'s array type
   * hint on the Test tab — a real error caught live. Generates a real
   * full random ranking in the same flat shape #default_value expects
   * instead; return shape matches WebformLikert::getTestValues() (a
   * list of candidate values getTestValue() picks one from).
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
   * Without this override, the base class's default formatting treats
   * getValue()'s return as a scalar — but our stored value is the flat
   * item-value => rank map, an array, which hit a real TypeError
   * wrapping it in `#plain_text` on the submission "View" page, caught
   * live. Renders items in *rank* order rather than configured order
   * (see WebformRankingConverter::orderByRank()) — each line is still
   * self-labeled ("Pizza: 1st"), so nothing is lost by reordering.
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
