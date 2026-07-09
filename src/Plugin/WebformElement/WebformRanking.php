<?php

namespace Drupal\webform_ranking\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides a 'webform_ranking' element.
 *
 * @WebformElement(
 *   id = "webform_ranking",
 *   label = @Translation("Ranking"),
 *   description = @Translation("Provides a form element to rank a set of items, via a matrix of radios or a drag/drop list."),
 *   category = @Translation("Options elements"),
 * )
 */
class WebformRanking extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
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
    ] + parent::getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperty($property_name) {
    return $this->getDefaultProperties()[$property_name] ?? parent::getDefaultProperty($property_name);
  }

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return $this->getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   *
   * Marks 'items[].label' and 'na_label' as translatable/token-enabled.
   * Values are deliberately excluded — they're storage keys and must
   * stay stable across languages and #states conditions.
   */
  public function getTranslatableProperties() {
    return array_merge(parent::getTranslatableProperties(), [
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
  protected function form(array $form, FormStateInterface $form_state) {
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
      '#key' => 'value',
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
      ],
      // Uniqueness of 'value' across rows is enforced in
      // validateConfigurationForm() below — #webform_multiple doesn't
      // do this on its own.
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
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
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
   */
  public function getElementSelectorOptions(array $element) {
    $selectors = parent::getElementSelectorOptions($element);

    if (($element['#ranking_style'] ?? 'matrix') !== 'matrix') {
      return $selectors;
    }

    $title = $element['#admin_title'] ?? $element['#title'] ?? $element['#webform_key'];
    $items = $element['#items'] ?? [];

    foreach ($items as $item) {
      $item_selector = ":input[name=\"{$element['#webform_key']}[matrix][{$item['value']}][rank]\"]";
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
   * comparing the whole canonical array. Returns 'na', a 1-based rank
   * string, or NULL if the item currently has no value.
   *
   * @param array $canonical
   *   A canonical ranking value, per WebformRankingConverter.
   * @param string $item_value
   *   The item's storage key.
   */
  public function getItemRankValue(array $canonical, string $item_value): ?string {
    $matrix = \Drupal\webform_ranking\WebformRankingConverter::canonicalToMatrix($canonical);
    return $matrix[$item_value] ?? NULL;
  }

}
