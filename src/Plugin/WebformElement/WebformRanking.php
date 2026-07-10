<?php

namespace Drupal\webform_ranking\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;

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
    return [
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

    $form['ranking'] = [
      '#type' => 'details',
      '#title' => $this->t('Ranking settings'),
      '#open' => TRUE,
      '#weight' => -10,
    ];

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
        // condition-builder widget — which would have given admins the
        // exact same UI they already use for element-level conditions.
        // Flagged at the time as unconfirmed: "Nested one level inside
        // a #webform_multiple table row is not a configuration I've
        // confirmed works cleanly out of the box." That risk
        // materialized — adding a second item row crashed with a
        // TypeError inside WebformCodeMirror::validateWebformCodeMirror(),
        // an array reaching a YAML validator expecting a string,
        // strongly suggesting webform_element_states (which appears to
        // use a codemirror YAML view internally for advanced-mode
        // editing) doesn't handle being embedded inside another
        // #tree-based multiple-value widget correctly.
        //
        // Using the flagged fallback instead: a plain YAML-mode
        // codemirror field. Real cost to admin UX (raw YAML instead of
        // a visual conditions builder), but functional and confirmed
        // by the same production error to at least avoid that specific
        // nesting failure mode. WebformCodeMirror's YAML mode decodes
        // the submitted string into an array for the element's value,
        // which is the same #states-shaped array structure
        // WebformRankingVisibilityResolver and the client-side #states
        // attachment in buildMatrix()/buildDragDrop() already expect —
        // no changes needed on that side.
        // Progressive disclosure: most items won't need a condition at
        // all, so the YAML field stays hidden until this checkbox is
        // checked. This checkbox is purely a client-side convenience —
        // it is NEVER read server-side, never validated, and never
        // added to config schema. The 'states' field's own content
        // (empty or not) remains the single source of truth on the
        // backend, exactly as before this checkbox existed. JS (see
        // element.itemsAdmin library, attached below) handles: showing
        // the YAML field when checked; clearing it when unchecked (so
        // an unchecked box and lingering stale YAML can never
        // disagree); and, on page load, auto-checking + revealing the
        // field for any row that already has YAML content, so editing
        // an existing conditional item doesn't look broken.
        'use_states' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Use conditional visibility for this item'),
          '#default_value' => FALSE,
          '#attributes' => ['class' => ['webform-ranking-item-use-states']],
        ],
        'states' => [
          '#type' => 'webform_codemirror',
          '#mode' => 'yaml',
          '#title' => $this->t('Include this item when (#states, YAML)'),
          '#description' => $this->t('Optional, advanced. Enter a #states conditions array in YAML — e.g. <code>visible:</code> on one line, then <code>  \':input[name="other_element"]\': {value: student}</code> indented beneath it.'),
          '#wrapper_attributes' => ['class' => ['webform-ranking-item-states-wrapper']],
          '#attributes' => ['class' => ['webform-ranking-item-states']],
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
    $values_seen = [];
    foreach ($items as $delta => $item) {
      // 'use_states' is a client-side-only progressive-disclosure
      // toggle (see the field definition in form()) — never meant to
      // reach config. Stripping it here rather than in the schema
      // keeps the schema describing only what's actually persisted.
      unset($items[$delta]['use_states']);

      $value = trim($item['value'] ?? '');
      if ($value === '') {
        continue;
      }
      if (isset($values_seen[$value])) {
        $form_state->setErrorByName('items', $this->t('Item values must be unique. "@value" is used more than once.', ['@value' => $value]));
        break;
      }
      $values_seen[$value] = TRUE;
    }
    $form_state->setValue('items', $items);

    if (count($items) < 2) {
      $form_state->setErrorByName('items', $this->t('Provide at least two items to rank.'));
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

    if (!empty($element['#randomize_item_order'])) {
      shuffle($element['#items']);
    }

    // Per-item #states (conditional inclusion) applied to each row/card
    // is wired up here in the next pass, once the shared
    // visible-item-set resolver exists — needs to be identical logic to
    // what the validate callback recomputes server-side, so it's being
    // built as one shared service rather than duplicated.

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
    if (!empty($element['#default_value']) && is_array($element['#default_value'])) {
      $element['#default_value'] = \Drupal\webform_ranking\WebformRankingConverter::matrixToCanonical($element['#default_value']);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Exposes one selector per item ("Ranking > Item A: rank") instead of
   * the whole element as a single scalar comparison target. This is the
   * actual fix for the Array-to-string crash seen on Likert: an admin
   * building a condition is only ever offered sub-selectors that
   * resolve to a real DOM input with a scalar value, never the
   * composite array as a whole.
   *
   * Deliberate scope limit, stated plainly rather than silently
   * omitted: this only covers the *matrix* style. Each matrix row's
   * radios element is a real, individually-named DOM input, so
   * states.js (client-side) and the server-side conditions validator
   * both evaluate it exactly like any other radios field.
   *
   * The *drag/drop* style has no equivalent — an item's rank there
   * only exists as its position within a comma-joined hidden input
   * (see WebformRankingConverter), and states.js has no way to express
   * "parse this CSV value and check whether item X sits at index 1."
   * Making dragdrop-sourced items usable as #states triggers would mean
   * either a custom states.js condition evaluator, or maintaining a
   * second set of real per-item hidden inputs purely for #states to
   * bind to (duplicating what's already in 'order'/'na'). Not
   * attempted here — flagging it as a known gap rather than a silent
   * one. If it's needed, it's a separable follow-up rather than
   * something to bolt on quickly.
   *
   * The name of the base class method used for selector suffix
   * construction (getElementSelectorOptions() vs. an equivalent) may
   * differ slightly across Webform 6.2.x vs 6.3.x — worth confirming
   * against the exact Webform version this module targets before
   * relying on this signature.
   *
   * Selector bug fixed: this previously built
   * "{key}[matrix][{item}][rank]", a trailing `[rank]` suffix that
   * never matched any real DOM input — each matrix row's radios share
   * the row's own `#parents` directly (`{key}[matrix][{item}]`, no
   * `[rank]` segment; see buildMatrix()). A real, reported symptom of
   * this: an admin-configured #states condition on another element,
   * targeting "Ranking > Item (rank)" as its trigger, never reacted to
   * rank selection at all — states.js (client-side) queries the live
   * DOM by this exact selector string, so a selector matching nothing
   * silently never binds a listener. Confirmed live in a browser after
   * the fix: toggling a rank now correctly shows/hides a dependent
   * element with no page reload.
   */
  public function getElementSelectorOptions(array $element) {
    $selectors = parent::getElementSelectorOptions($element);

    if (($element['#ranking_style'] ?? 'matrix') !== 'matrix') {
      return $selectors;
    }

    $title = $element['#admin_title'] ?? $element['#title'] ?? $element['#webform_key'];
    $items = $element['#items'] ?? [];

    foreach ($items as $item) {
      $item_selector = ":input[name=\"{$element['#webform_key']}[matrix][{$item['value']}]\"]";
      $selectors[$item_selector] = $this->t('@title: @item (rank)', [
        '@title' => $title,
        '@item' => $item['label'],
      ]);
    }

    return $selectors;
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
    $items = \Drupal\webform_ranking\WebformRankingConverter::orderByRank($element['#items'] ?? [], $value);

    if ($this->getItemFormat($element) === 'raw') {
      $rows = [];
      foreach ($items as $item) {
        $rows[$item['value']] = ['#markup' => $item['value'] . ': ' . ($value[$item['value']] ?? '')];
      }
      return ['#theme' => 'item_list', '#items' => $rows];
    }

    $rank_labels = \Drupal\webform_ranking\Element\WebformRanking::getRankLabels($element, count($items));
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
    $items = \Drupal\webform_ranking\WebformRankingConverter::orderByRank($element['#items'] ?? [], $value);

    if ($this->getItemFormat($element) === 'raw') {
      $lines = [];
      foreach ($items as $item) {
        $lines[] = $item['value'] . ': ' . ($value[$item['value']] ?? '');
      }
      return implode(PHP_EOL, $lines);
    }

    $rank_labels = \Drupal\webform_ranking\Element\WebformRanking::getRankLabels($element, count($items));
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
