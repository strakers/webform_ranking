/**
 * @file
 * Per-item "conditional visibility" field on the Ranking element's config
 * form: a dialog (not inline in the items table), holding both a raw
 * #states YAML textarea and a visual condition-rows picker layered over
 * the same textarea. See docs/adr/0014-items-admin-dialog-architecture.md
 * for the dialog's own design (non-destructive DOM relocation, form-safe
 * appendTo) and docs/adr/0015-condition-builder-markup-parity-and-collisions.md
 * for the picker's markup choices.
 *
 * Uses const/let, unlike this module's other JS files (still var as of
 * writing) — see the module's tracked refactor issue to bring those in
 * line.
 */

(function ($, Drupal, once, drupalSettings) {
  'use strict';

  /**
   * A condition row's blank/default field values — shared by
   * createConditionRow()'s own default and resetConditionRow(), so
   * "blank" only has one definition to keep in sync (GitHub issue #93).
   */
  const BLANK_CONDITION = {selector: '', trigger: 'value', value: ''};

  /**
   * Trigger keys whose Value input takes a `min:max` range rather than a
   * single comparison value — see updateValueFieldVisibility()'s
   * placeholder hint (GitHub issue #92).
   */
  const BETWEEN_TRIGGERS = ['between', '!between'];

  /**
   * Returns the CodeMirror instance wired onto `yamlField` by
   * webform.element.codemirror.js, if attached — see
   * CodeMirror.fromTextArea()'s own documented DOM shape (hides the
   * original <textarea>, inserts its generated wrapper as the next
   * sibling, which exposes the live instance via a '.CodeMirror'
   * property). Shared by every place that needs to read/write the editor
   * instance directly instead of the underlying <textarea> (GitHub issue
   * #93 — this lookup was previously repeated at three separate call
   * sites).
   *
   * @param {HTMLTextAreaElement} yamlField
   *   The original, possibly CodeMirror-replaced, YAML textarea.
   *
   * @return {?object}
   *   The live CodeMirror instance, or null if not attached.
   */
  function getCodeMirrorInstance(yamlField) {
    const codeMirrorWrapper = yamlField.nextElementSibling;
    return (codeMirrorWrapper && codeMirrorWrapper.CodeMirror) ? codeMirrorWrapper.CodeMirror : null;
  }

  Drupal.behaviors.webformRankingItemsAdmin = {
    attach: function (context) {
      once('webform-ranking-items-admin', '.webform-ranking-item-states-wrapper', context).forEach(function (wrapper) {
        // #webform_multiple applies '#wrapper_attributes' to both its
        // own per-item table cell AND the nested form-item div Drupal's
        // Form API generates for the same element, so this selector
        // matches two ancestor/descendant elements per item, not one —
        // confirmed via a real DOM inspection in
        // WebformRankingItemsAdminJavaScriptTest, which caught this
        // producing a trigger+dialog nested inside another trigger's
        // dialog. Only the innermost match (nothing further nested
        // inside it) is the real field wrapper; skip the outer one.
        if (wrapper.querySelector('.webform-ranking-item-states-wrapper')) {
          return;
        }
        initRow(wrapper);
      });
    }
  };

  function initRow(wrapper) {
    const yamlField = wrapper.querySelector('.webform-ranking-item-states');
    if (!yamlField) {
      return;
    }

    const form = wrapper.closest('form');
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'webform-ranking-item-configure-states button';
    // One static label regardless of whether a condition is already
    // configured — the dialog it opens shows that either way (empty or
    // pre-filled), so there's no need for a second "is this already
    // configured" state to track and keep in sync on the button itself.
    trigger.textContent = Drupal.t('Conditions');
    wrapper.parentNode.insertBefore(trigger, wrapper);

    // Hidden until opened in the dialog — the trigger button is the
    // only thing visible in the row itself.
    wrapper.style.display = 'none';

    let builder = null;
    let dialog = null;

    trigger.addEventListener('click', function () {
      if (!builder) {
        // Deferred to first open rather than built for every item at
        // attach time (page load, and again on every #webform_multiple
        // AJAX rebuild) — see GitHub issue #84. Most items' dialogs are
        // never opened in a given admin session; building the full
        // condition-rows DOM (fieldset, table, per-condition rows with
        // their own selector/trigger <select> population, plus the
        // saved-condition decomposition lookup) for every item
        // regardless was pure discarded work at scale. Reading
        // drupalSettings here rather than at page load is also strictly
        // more correct, not just lazier: Drupal's AJAX framework merges
        // fresh settings into the global drupalSettings object on every
        // response, so a click-time read always sees the current data.
        builder = initConditionBuilder(wrapper, yamlField);
      }
      if (!dialog) {
        dialog = Drupal.dialog(wrapper, {
          title: Drupal.t('Item visibility condition'),
          appendTo: form,
          width: 600,
          buttons: [
            {
              text: Drupal.t('Clear condition'),
              click: function () {
                builder.clear();
              }
            },
            {
              text: Drupal.t('Done'),
              primary: true,
              click: function () {
                dialog.close();
              }
            }
          ],
          // Overrides core's default close handler (which detaches
          // behaviors for 'unload', built for disposable AJAX content) —
          // this dialog is reused across opens, and that default tore
          // the wrapped field down entirely (a no-op keeps it alive).
          close: function () {}
        });
      }

      wrapper.style.display = '';
      dialog.showModal();

      // CodeMirror measures line/character dimensions against the
      // element's rendered size — if it initialized while the wrapper
      // was display:none (the row's default state), it needs a refresh
      // once actually visible, or it renders collapsed/blank.
      // CodeMirror.fromTextArea() hides the original <textarea> and
      // inserts its generated wrapper as the very next sibling, which
      // itself exposes the live editor instance via a '.CodeMirror'
      // property — see CodeMirror's own fromTextArea() documentation.
      const codeMirror = getCodeMirrorInstance(yamlField);
      if (codeMirror) {
        codeMirror.refresh();
      }
    });
  }

  /**
   * Builds the visual condition-rows picker for one item's dialog.
   *
   * Inserts two new sibling containers as the FIRST children of
   * `wrapper`, ahead of the server-rendered YAML field's own untouched
   * markup — one holding the visual builder, one an "Edit source"
   * toggle. The condition-rows DOM *is* the state, no separate JS data
   * object mirrors it. See docs/adr/0014-items-admin-dialog-architecture.md
   * and, for what happens when the YAML view's text diverges from it,
   * docs/adr/0017-hand-typed-yaml-safety-nets.md.
   *
   * @param {HTMLElement} wrapper
   *   The item's '.webform-ranking-item-states-wrapper' element.
   * @param {HTMLTextAreaElement} yamlField
   *   The item's raw YAML textarea, inside `wrapper`.
   *
   * @return {{clear: function()}}
   *   An object exposing clear(), for the dialog's "Clear condition"
   *   button.
   */
  function initConditionBuilder(wrapper, yamlField) {
    const settings = (drupalSettings.webformRankingItemsAdmin) || {};
    const stateOptions = settings.stateOptions || {};
    const selectorOptions = settings.selectorOptions || {};
    const triggerOptions = settings.triggerOptions || {};
    const conditionsByItemValue = settings.conditionsByItemValue || {};
    const iconBaseUrl = (drupalSettings.path.baseUrl || '/') + (settings.webformModulePath || '') + '/images/icons/';
    // Trigger/state classification read from PHP (WebformRanking::
    // NESTED_TRIGGER_KEYS/NO_VALUE_TRIGGER_KEYS/VISIBILITY_STATE_KEYS)
    // rather than a second hand-typed copy here — see GitHub issue #83.
    const nestedTriggers = settings.nestedTriggerKeys || [];
    const noValueTriggers = settings.noValueTriggerKeys || [];
    // Only these actually affect item inclusion —
    // WebformRankingVisibilityResolver::isVisible() ignores every other
    // state. The rest are still offered (matching the real element-level
    // builder's own full flexibility) but trigger a warning note — see
    // updateStateWarning().
    const visibilityStates = settings.visibilityStateKeys || [];

    const row = wrapper.closest('tr');
    const valueInput = row ? row.querySelector('input[name$="[value]"]') : null;
    // trim() to match PHP's own lookup key: WebformRanking::form() builds
    // $conditions_by_value keyed by trim($item['value'] ?? ''), so an
    // item value with incidental leading/trailing whitespace would
    // otherwise miss a real, saved, decomposable condition here and
    // silently fall back to the raw YAML view instead.
    const itemValue = valueInput ? valueInput.value.trim() : '';

    // Fieldset/table classes copied verbatim from the real "Conditional
    // logic" tab's own server-rendered markup, so this inherits the
    // admin theme's styling for free. See
    // docs/adr/0015-condition-builder-markup-parity-and-collisions.md.
    const fieldset = document.createElement('fieldset');
    fieldset.className = 'webform-ranking-item-condition-builder js-webform-type-fieldset webform-type-fieldset fieldset js-form-item form-item js-form-wrapper form-wrapper';
    const legend = document.createElement('legend');
    legend.className = 'fieldset__legend fieldset__legend--visible';
    const legendLabel = document.createElement('span');
    legendLabel.className = 'fieldset__label';
    legendLabel.textContent = Drupal.t('Conditional logic');
    legend.appendChild(legendLabel);
    fieldset.appendChild(legend);

    // Real fieldsets wrap their content (everything but the legend) in
    // this div — what the admin theme's fieldset CSS actually targets.
    const fieldsetWrapper = document.createElement('div');
    fieldsetWrapper.className = 'fieldset__wrapper';
    fieldset.appendChild(fieldsetWrapper);

    const stateWarning = document.createElement('div');
    stateWarning.className = 'webform-ranking-item-condition-state-warning messages messages--warning';
    stateWarning.style.display = 'none';
    stateWarning.textContent = Drupal.t('This state does not affect whether the item is ranked or hidden — only Visible/Hidden (and their Slide variants) do.');
    fieldsetWrapper.appendChild(stateWarning);

    // Two "All" conditions on the same Element have no lossless #states
    // representation — warns and withholds the write rather than
    // emitting YAML that would throw on next decode. See
    // docs/adr/0016-duplicate-selector-and-no-lossless-representation.md.
    const duplicateSelectorWarning = document.createElement('div');
    duplicateSelectorWarning.className = 'webform-ranking-item-condition-duplicate-warning messages messages--warning';
    duplicateSelectorWarning.style.display = 'none';
    duplicateSelectorWarning.textContent = Drupal.t('Two conditions target the same Element combined with "All" — this can\'t be saved until it\'s resolved. Use "Any"/"One" instead, or a single "between"/"not between" condition for a numeric range.');
    fieldsetWrapper.appendChild(duplicateSelectorWarning);

    // GitHub issue #88: warns when the YAML field has diverged from what
    // the builder last wrote, instead of silently overwriting hand-typed
    // text on the next builder interaction. See
    // docs/adr/0017-hand-typed-yaml-safety-nets.md.
    const staleEditWarning = document.createElement('div');
    staleEditWarning.className = 'webform-ranking-item-condition-stale-warning messages messages--warning';
    staleEditWarning.style.display = 'none';
    staleEditWarning.textContent = Drupal.t('The builder below still shows its own earlier state, not what you just typed under "Edit source" — any change you make here will overwrite that typed text.');
    fieldsetWrapper.appendChild(staleEditWarning);

    const table = document.createElement('table');
    table.className = 'webform-states-table';
    const thead = document.createElement('thead');
    thead.innerHTML =
      '<tr>' +
      '<th style="width:25%">' + Drupal.checkPlain(Drupal.t('State')) + '</th>' +
      '<th style="width:50%">' + Drupal.checkPlain(Drupal.t('Element')) + '</th>' +
      '<th style="width:25%">' + Drupal.checkPlain(Drupal.t('Trigger/Value')) + '</th>' +
      '<th class="visually-hidden">' + Drupal.checkPlain(Drupal.t('Operations')) + '</th>' +
      '</tr>';
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    fieldsetWrapper.appendChild(table);

    // State row: one per item, not repeatable (unlike the real builder's
    // "Add another state" — see visibilityStates above). Module-scoped
    // class, not the real widget's own 'webform-states-table--state' —
    // see docs/adr/0015-condition-builder-markup-parity-and-collisions.md.
    const stateRow = document.createElement('tr');
    stateRow.className = 'webform-ranking-item-condition-state-row';
    const stateCell = document.createElement('td');
    stateCell.className = 'webform-ranking-item-condition-state-cell';
    const modeSelect = createSelectElement();
    Object.keys(stateOptions).forEach(function (key) {
      addOption(modeSelect, key, stateOptions[key]);
    });
    stateCell.appendChild(modeSelect);
    stateRow.appendChild(stateCell);

    const operatorCell = document.createElement('td');
    operatorCell.className = 'webform-states-table--operator';
    operatorCell.colSpan = 2;
    const operatorPrefix = document.createElement('span');
    operatorPrefix.textContent = Drupal.t('if') + ' ';
    const operatorSelect = createSelectElement();
    addOption(operatorSelect, 'and', Drupal.t('All'));
    addOption(operatorSelect, 'or', Drupal.t('Any'));
    addOption(operatorSelect, 'xor', Drupal.t('One'));
    const operatorSuffix = document.createElement('span');
    operatorSuffix.textContent = ' ' + Drupal.t('of the following is met:');
    operatorCell.appendChild(operatorPrefix);
    operatorCell.appendChild(operatorSelect);
    operatorCell.appendChild(operatorSuffix);
    stateRow.appendChild(operatorCell);
    stateRow.appendChild(document.createElement('td'));
    tbody.appendChild(stateRow);

    // No separate "Add condition" button here — matching the real
    // builder, which has none for conditions either (only "Add another
    // state", not applicable to this single-state design). Each
    // condition row gets its own inline +/- icon buttons instead (see
    // createConditionRow()'s operations cell).
    const actions = document.createElement('div');
    actions.className = 'webform-ranking-item-condition-actions';

    const editSourceToggle = document.createElement('button');
    editSourceToggle.type = 'button';
    editSourceToggle.className = 'webform-ranking-item-edit-source button button--small';
    // Matches the real element-level builder's own button text exactly
    // (WebformElementStates::processWebformStates()'s 'source' action).
    editSourceToggle.textContent = Drupal.t('Edit source');
    actions.appendChild(editSourceToggle);
    fieldsetWrapper.appendChild(actions);

    // The YAML field's own server-rendered markup (label, textarea,
    // description — everything Drupal's form_element theme wrapper put
    // inside `wrapper`) is moved as a group into this one container, so
    // it can be shown/hidden as a single unit without touching its own
    // internal structure at all.
    const yamlViewContainer = document.createElement('div');
    yamlViewContainer.className = 'webform-ranking-item-yaml-view';

    // GitHub issue #88: neither footgun below is validated for raw text —
    // tells the admin up front rather than a confusing failure at Save.
    // See docs/adr/0017-hand-typed-yaml-safety-nets.md.
    const editSourceNote = document.createElement('div');
    editSourceNote.className = 'webform-ranking-item-edit-source-note description';
    editSourceNote.textContent = Drupal.t('Editing here directly skips a couple of checks the visual builder performs — avoid two conditions on the same Element combined with "All" (it can\'t be saved), and remember "between"/"not between" needs a min:max value (e.g. 1:5).');
    yamlViewContainer.appendChild(editSourceNote);

    while (wrapper.firstChild) {
      yamlViewContainer.appendChild(wrapper.firstChild);
    }
    const backToBuilderLink = document.createElement('button');
    backToBuilderLink.type = 'button';
    backToBuilderLink.className = 'webform-ranking-item-back-to-builder button button--small';
    backToBuilderLink.textContent = Drupal.t('Back to condition builder');
    yamlViewContainer.appendChild(backToBuilderLink);

    wrapper.appendChild(fieldset);
    wrapper.appendChild(yamlViewContainer);

    function addOption(parent, value, label) {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      parent.appendChild(option);
    }

    /**
     * A <select> carrying the same classes Drupal's own form rendering
     * puts on every server-rendered one ('form-select form-element
     * form-element--type-select' — confirmed against this admin form's
     * own rendered markup), so these hand-built fields pick up the
     * admin theme's existing select styling instead of looking like
     * bare, unstyled native controls.
     */
    function createSelectElement() {
      const select = document.createElement('select');
      select.className = 'form-select form-element form-element--type-select';
      return select;
    }

    /**
     * A text <input> carrying the same classes Drupal's own form
     * rendering puts on every server-rendered one ('form-text
     * form-element form-element--type-text') — see
     * createSelectElement()'s docblock for why.
     */
    function createTextInputElement() {
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-text form-element form-element--type-text';
      return input;
    }

    /**
     * Builds the Element (selector) <select>, including optgroups, once
     * per item and clones it per condition row — selectorOptions is
     * identical across every row, so rebuilding it per row was pure
     * repeated work. Mixes flat and optgroup-nested entries, same
     * shape the real "Conditional logic" tab's own dropdown uses.
     *
     * @return {HTMLSelectElement}
     *   A new, independent <select> — safe to mutate (e.g. the "stray
     *   option" fallback in createConditionRow()) without affecting the
     *   cached template or any other row's own clone.
     */
    let selectorSelectTemplate = null;
    function createSelectorSelect() {
      if (!selectorSelectTemplate) {
        const select = createSelectElement();
        addOption(select, '', Drupal.t('- Select -'));
        Object.keys(selectorOptions).forEach(function (key) {
          const value = selectorOptions[key];
          if (typeof value === 'object' && value !== null) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = key;
            Object.keys(value).forEach(function (selector) {
              addOption(optgroup, selector, value[selector]);
            });
            select.appendChild(optgroup);
          }
          else {
            addOption(select, key, value);
          }
        });
        selectorSelectTemplate = select;
      }
      return selectorSelectTemplate.cloneNode(true);
    }

    /**
     * Builds the Trigger <select>, once per item and cloned per row —
     * same rationale as createSelectorSelect() above.
     *
     * @return {HTMLSelectElement}
     */
    let triggerSelectTemplate = null;
    function createTriggerSelect() {
      if (!triggerSelectTemplate) {
        const select = createSelectElement();
        Object.keys(triggerOptions).forEach(function (key) {
          addOption(select, key, triggerOptions[key]);
        });
        triggerSelectTemplate = select;
      }
      return triggerSelectTemplate.cloneNode(true);
    }

    /**
     * Builds one condition <tr>: element/trigger/value + remove button.
     *
     * Classes match the real builder's own, so 'webform.element.states'
     * (already loaded page-wide) wires up value autocomplete with no JS
     * of this module's own needed. 'webform-states-table--condition'
     * lives on the <tr> ONLY, not also the inner trigger/value cell —
     * see docs/adr/0015-condition-builder-markup-parity-and-collisions.md
     * for the autocomplete-corrupting bug that deviation avoids.
     *
     * @param {{selector: string, trigger: string, value: string}} [data]
     *   The row's initial values, defaulting to BLANK_CONDITION.
     *
     * @return {HTMLTableRowElement}
     *   The new condition row.
     */
    function createConditionRow(data) {
      data = Object.assign({}, BLANK_CONDITION, data);
      const conditionRow = document.createElement('tr');
      conditionRow.className = 'webform-states-table--condition webform-ranking-item-condition-row';

      // Empty placeholder — purely for column alignment under the
      // "State" header, matching the real widget's own per-condition-row
      // markup (WebformElementStates::buildConditionRow()); the actual
      // State <select> lives only on the state row, above. Deliberately
      // NOT core's own 'webform-states-table--state' class here, even
      // though there's currently no <select> inside this cell for it to
      // collide via (see the state row's own construction comment for
      // the full collision this class name causes elsewhere) — using a
      // module-scoped name closes off that landmine for good, rather
      // than leaving a same-named cell one future edit away from
      // reintroducing it.
      const stateTd = document.createElement('td');
      stateTd.className = 'webform-ranking-item-condition-row-state-placeholder';
      conditionRow.appendChild(stateTd);

      const selectorTd = document.createElement('td');
      selectorTd.className = 'webform-states-table--selector';
      const selectorSelect = createSelectorSelect();
      if (data.selector) {
        selectorSelect.value = data.selector;
        // A selector already saved against this item but not present in
        // the current option list (e.g. its target element was since
        // removed from the webform) would otherwise silently show as
        // unselected — indistinguishable from "no condition" — and then
        // get dropped from the emitted YAML the next time *any* row in
        // this dialog changes. Same fallback the real builder itself
        // uses (see WebformElementStates::buildConditionRow()'s own
        // '#selector_options_flattened' check): add it as its own
        // option, labeled with the raw selector, so it stays selected
        // and correctly re-emitted even though it's not a recognized
        // element.
        if (selectorSelect.value !== data.selector) {
          addOption(selectorSelect, data.selector, data.selector);
          selectorSelect.value = data.selector;
        }
      }
      selectorTd.appendChild(selectorSelect);
      conditionRow.appendChild(selectorTd);

      const conditionTd = document.createElement('td');
      const triggerWrapper = document.createElement('div');
      triggerWrapper.className = 'webform-states-table--trigger';
      const triggerSelect = createTriggerSelect();
      triggerSelect.value = data.trigger;
      triggerWrapper.appendChild(triggerSelect);
      conditionTd.appendChild(triggerWrapper);

      const valueWrapper = document.createElement('div');
      valueWrapper.className = 'webform-states-table--value';
      const valueInputEl = createTextInputElement();
      valueInputEl.value = data.value;
      valueWrapper.appendChild(valueInputEl);
      conditionTd.appendChild(valueWrapper);
      conditionRow.appendChild(conditionTd);

      // +/- icon buttons, always available (no "only once 2+ rows
      // exist" floor) — matching the real builder, whose own remove
      // button is only ever omitted when '#multiple' is FALSE (never
      // the case here). "-" on the sole remaining row resets it to
      // blank instead of deleting it, so the table never ends up with
      // zero rows and no way back in without reopening the dialog —
      // functionally identical either way, since emitYaml() already
      // skips any condition with no selector chosen.
      const operationsTd = document.createElement('td');
      operationsTd.className = 'webform-states-table--operations';
      const addRowButton = createIconButton('plus', Drupal.t('Add'));
      const removeRowButton = createIconButton('minus', Drupal.t('Remove'));
      operationsTd.appendChild(addRowButton);
      operationsTd.appendChild(removeRowButton);
      conditionRow.appendChild(operationsTd);

      selectorSelect.addEventListener('change', onBuilderChange);
      triggerSelect.addEventListener('change', function () {
        updateValueFieldVisibility(conditionRow, triggerSelect.value);
        onBuilderChange();
      });
      // Debounced specifically for 'input' (real typing fires this once
      // per keystroke — see debouncedBuilderChange()'s own docblock),
      // but 'change' stays on the immediate onBuilderChange(): it covers
      // a value set programmatically (autofill, or a test driving the
      // field via .val()+trigger('change')) rather than typed
      // keystroke-by-keystroke, where there's no rapid-fire sequence to
      // coalesce and immediate feedback is actually wanted.
      // onBuilderChange() is idempotent, so hearing both for a single
      // real edit (a final 'change' arrives after typing stops too) is
      // harmless.
      valueInputEl.addEventListener('input', debouncedBuilderChange);
      valueInputEl.addEventListener('change', onBuilderChange);
      addRowButton.addEventListener('click', function () {
        const newRow = createConditionRow({});
        conditionRow.after(newRow);
        // See renderConditions()'s own comment on its batched attach:
        // attaching on the new row itself can never match once()'s own
        // descendant-only search for the class that row carries — tbody
        // (an ancestor) is required.
        Drupal.attachBehaviors(tbody);
        onBuilderChange();
      });
      removeRowButton.addEventListener('click', function () {
        if (tbody.querySelectorAll('.webform-ranking-item-condition-row').length > 1) {
          conditionRow.remove();
        }
        else {
          resetConditionRow(conditionRow);
        }
        onBuilderChange();
      });

      updateValueFieldVisibility(conditionRow, triggerSelect.value);

      return conditionRow;
    }

    /**
     * A <button> styled to match the real builder's own +/- icon
     * buttons (web/modules/contrib/webform/css/webform.element.states.css's
     * 'input[type="image"]' rules, reapplied to a <button type="button">
     * here — see this library's own CSS file docblock for why not a
     * real `<input type="image">`).
     *
     * @param {string} iconName
     *   'plus' or 'minus' — the icon's SVG filename, without extension.
     * @param {string} label
     *   Accessible label and title text for the button.
     *
     * @return {HTMLButtonElement}
     *   The new icon button.
     */
    function createIconButton(iconName, label) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'webform-ranking-item-icon-button image-button';
      button.style.backgroundImage = 'url(' + iconBaseUrl + iconName + '.svg)';
      button.setAttribute('aria-label', label);
      button.title = label;
      return button;
    }

    /**
     * Resets one condition row's fields back to blank/default.
     *
     * Used when "-" is clicked on the sole remaining row — see
     * createConditionRow()'s own note on why that resets rather than
     * removes.
     *
     * @param {HTMLTableRowElement} conditionRow
     *   The row to reset.
     */
    function resetConditionRow(conditionRow) {
      const selectorSelect = conditionRow.querySelector('.webform-states-table--selector select');
      selectorSelect.value = BLANK_CONDITION.selector;
      const triggerSelect = conditionRow.querySelector('.webform-states-table--trigger select');
      triggerSelect.value = BLANK_CONDITION.trigger;
      conditionRow.querySelector('.webform-states-table--value input').value = BLANK_CONDITION.value;
      updateValueFieldVisibility(conditionRow, triggerSelect.value);
    }

    /**
     * Hides the Value input for triggers that don't use one
     * (empty/filled/checked/unchecked) — this module's own lightweight
     * equivalent of the real builder's server-rendered per-row '#states'
     * for the same purpose, which doesn't apply here since these rows
     * are built/cloned client-side, not tied to a specific server-known
     * row index.
     *
     * Also sets the Value input's placeholder — GitHub issue #92:
     * 'between'/'!between' are the only triggers that need a specific
     * text format (a `min:max` range, per Form API's own #states
     * convention for those two triggers), but the field gave no hint of
     * that; a wrong-format value simply saved and silently never
     * matched, with nothing anywhere indicating why. Real
     * "Conditional logic" tab shows the same kind of per-trigger hint via
     * server-rendered per-row #states rather than a single shared
     * placeholder string; this is this module's lighter-weight
     * equivalent for the same trigger pair.
     *
     * @param {HTMLTableRowElement} conditionRow
     *   The condition row to update.
     * @param {string} trigger
     *   The row's currently-selected trigger key.
     */
    function updateValueFieldVisibility(conditionRow, trigger) {
      const valueWrapper = conditionRow.querySelector('.webform-states-table--value');
      valueWrapper.style.display = noValueTriggers.indexOf(trigger) === -1 ? '' : 'none';
      const valueInputEl = valueWrapper.querySelector('input');
      valueInputEl.placeholder = BETWEEN_TRIGGERS.indexOf(trigger) !== -1
        ? Drupal.t('e.g. 1:5 (min:max)')
        : Drupal.t('Enter value…');
    }

    /**
     * Shows a note when the selected State doesn't affect item
     * inclusion — see visibilityStates above.
     */
    function updateStateWarning() {
      stateWarning.style.display = visibilityStates.indexOf(modeSelect.value) === -1 ? '' : 'none';
    }

    /**
     * Shows a note when the "All" operator is selected with two or more
     * condition rows sharing the same Element — see
     * duplicateSelectorWarning's own construction comment above for why
     * that combination can't be saved at all, not just losslessly.
     *
     * @param {Array<{selector: string, trigger: string, value: string}>} conditions
     *   Row data from getConditionRowsData(), reused rather than
     *   re-queried here — see that function's own docblock.
     *
     * @return {boolean}
     *   Whether the warning is currently showing — callers use this to
     *   withhold writing emitYaml()'s output while true, rather than
     *   handing the YAML field a value that throws on decode.
     */
    function updateDuplicateSelectorWarning(conditions) {
      const selectors = conditions.map(function (condition) {
        return condition.selector;
      });
      const hasDuplicate = selectors.some(function (selector, index) {
        return selectors.indexOf(selector) !== index;
      });
      const showWarning = operatorSelect.value === 'and' && hasDuplicate;
      duplicateSelectorWarning.style.display = showWarning ? '' : 'none';
      return showWarning;
    }

    /**
     * Renders the builder from a decomposeItemStatesToConditions()-shaped
     * object (see the PHP method of the same purpose), or a single empty
     * row when there's nothing to show yet.
     *
     * @param {?{mode: string, operator: string, conditions: Array}} decomposed
     *   The item's decomposed saved condition, or null/empty for none.
     */
    function renderConditions(decomposed) {
      tbody.querySelectorAll('.webform-ranking-item-condition-row').forEach(function (conditionRow) {
        conditionRow.remove();
      });
      let conditions;
      if (decomposed && decomposed.conditions && decomposed.conditions.length) {
        modeSelect.value = decomposed.mode;
        operatorSelect.value = decomposed.operator;
        decomposed.conditions.forEach(function (condition) {
          tbody.appendChild(createConditionRow(condition));
        });
        conditions = decomposed.conditions;
      }
      else {
        modeSelect.value = 'visible';
        operatorSelect.value = 'and';
        tbody.appendChild(createConditionRow({}));
        conditions = [];
      }
      // One batched attach for however many rows were just appended,
      // rather than once per row (each call sweeps the *entire*
      // Drupal.behaviors registry, not just this module's) — see GitHub
      // issue #84. Drupal.attachBehaviors(context) finds target elements
      // via context.querySelectorAll(selector) (see @drupal/once), which
      // by DOM spec never matches context itself, only descendants —
      // tbody (an ancestor of every row) is required, not a row itself;
      // see createConditionRow()'s own docblock for the real bug that
      // caused (autocomplete never wiring up on any row, ever).
      Drupal.attachBehaviors(tbody);
      updateStateWarning();
      // `conditions` (built above from the exact same data the rows were
      // just created from) is already what getConditionRowsData() would
      // read straight back out of those same rows — re-querying the DOM
      // here for data just written from this same array a few lines
      // above was pure repeated work (GitHub issue #93).
      updateDuplicateSelectorWarning(conditions);
    }

    /**
     * Reads every condition row with a selector chosen into one array,
     * shared by updateDuplicateSelectorWarning() and emitYaml() — both
     * used to independently re-scan and re-query the same rows on every
     * call, doubling the DOM work onBuilderChange() does per keystroke.
     *
     * @return {Array<{selector: string, trigger: string, value: string}>}
     *   One entry per condition row that has a selector chosen.
     */
    function getConditionRowsData() {
      const conditions = [];
      tbody.querySelectorAll('.webform-ranking-item-condition-row').forEach(function (conditionRow) {
        const selector = conditionRow.querySelector('.webform-states-table--selector select').value;
        if (!selector) {
          return;
        }
        conditions.push({
          selector: selector,
          trigger: conditionRow.querySelector('.webform-states-table--trigger select').value,
          value: conditionRow.querySelector('.webform-states-table--value input').value
        });
      });
      return conditions;
    }

    /**
     * Single-quoted YAML scalar: the only escape rule needed is doubling
     * a literal embedded `'` — single-quoted YAML strings don't treat
     * colons, double quotes, or brackets specially, which is what makes
     * this safe for selectors like `:input[name="x"]` without a general
     * YAML-escaping implementation.
     *
     * @param {*} value
     *   The value to encode. Coerced to a string first.
     *
     * @return {string}
     *   A single-quoted YAML scalar.
     */
    function yamlString(value) {
      return '\'' + String(value).replace(/'/g, '\'\'') + '\'';
    }

    function yamlCondition(condition) {
      if (condition.trigger === 'value' || condition.trigger === '!value') {
        return '{' + yamlString(condition.trigger) + ': ' + yamlString(condition.value) + '}';
      }
      if (nestedTriggers.indexOf(condition.trigger) !== -1) {
        return '{' + yamlString('value') + ': {' + yamlString(condition.trigger) + ': ' + yamlString(condition.value) + '}}';
      }
      // empty/filled/checked/unchecked: a bare boolean, no comparison
      // value.
      return '{' + yamlString(condition.trigger) + ': true}';
    }

    /**
     * Serializes the builder's current rows into #states-shaped YAML
     * text (flow style throughout — e.g. `visible: {selector: {trigger:
     * value}}` — deliberately not block/indented style: flow style is
     * fully valid YAML with no indentation-tracking needed, which is
     * what keeps this emitter this simple and low-risk).
     *
     * @param {Array<{selector: string, trigger: string, value: string}>} conditions
     *   Row data from getConditionRowsData(), reused rather than
     *   re-queried here — see that function's own docblock.
     *
     * @return {string}
     *   YAML text, or '' if no condition row has a selector chosen yet.
     */
    function emitYaml(conditions) {
      if (!conditions.length) {
        return '';
      }

      let body;
      if (conditions.length === 1) {
        body = '{' + yamlString(conditions[0].selector) + ': ' + yamlCondition(conditions[0]) + '}';
      }
      else if (operatorSelect.value === 'and') {
        body = '{' + conditions.map(function (condition) {
          return yamlString(condition.selector) + ': ' + yamlCondition(condition);
        }).join(', ') + '}';
      }
      else {
        const parts = [];
        conditions.forEach(function (condition, index) {
          if (index > 0) {
            parts.push(yamlString(operatorSelect.value));
          }
          parts.push('{' + yamlString(condition.selector) + ': ' + yamlCondition(condition) + '}');
        });
        body = '[' + parts.join(', ') + ']';
      }

      return yamlString(modeSelect.value) + ': ' + body;
    }

    /**
     * Writes the builder's current state into the YAML textarea.
     *
     * Through CodeMirror's own API when attached, matching how "Clear
     * condition" already needed to (see this file's earlier history):
     * webform.element.codemirror.js debounces syncing textarea edits
     * into its own editor by 500ms, so a direct textarea write can lose
     * a race against that stale timer. Writing through CodeMirror
     * directly and saving immediately avoids it.
     *
     * @param {string} value
     *   The YAML text to write.
     */
    function writeYamlField(value) {
      const codeMirror = getCodeMirrorInstance(yamlField);
      if (codeMirror) {
        codeMirror.setValue(value);
        codeMirror.save();
        // No manual dispatchEvent() needed on this path: .save() already
        // writes `value` into yamlField.value directly, and
        // webform.element.codemirror.js's own '$input.on(\'change\', ...)'
        // listener (confirmed by reading its source) only re-syncs the
        // editor's content FROM the textarea — since we just set both
        // sides to the same `value` via the CodeMirror API above, firing
        // 'change' here would only trigger a no-op resync, not anything
        // load-bearing.
      }
      else {
        yamlField.value = value;
        yamlField.dispatchEvent(new Event('input', {bubbles: true}));
        yamlField.dispatchEvent(new Event('change', {bubbles: true}));
      }
      // The builder's own output is now what the field holds — see
      // showBuilder()'s own comment for what this is compared against.
      lastWrittenYaml = value;
      staleEditWarning.style.display = 'none';
    }

    /**
     * Reads the YAML field's current text, through CodeMirror's own API
     * when attached (its editor content is the actual live value —
     * webform.element.codemirror.js only flushes it back into the
     * underlying <textarea>'s own .value on its own 500ms-debounced
     * timer or an explicit .save(), so reading yamlField.value directly
     * here could see stale content for up to 500ms after a real
     * keystroke).
     *
     * @return {string}
     *   The YAML field's current text.
     */
    function getYamlFieldValue() {
      const codeMirror = getCodeMirrorInstance(yamlField);
      return codeMirror ? codeMirror.getValue() : yamlField.value;
    }

    // The YAML field's own current text, as of the last time
    // writeYamlField() wrote it (or, before any write, the item's
    // initial server-rendered value) — see showBuilder()'s own comment
    // for what comparing against this catches (GitHub issue #88).
    let lastWrittenYaml = getYamlFieldValue();

    function onBuilderChange() {
      updateStateWarning();
      const conditions = getConditionRowsData();
      // Withhold the write entirely while the duplicate-selector warning
      // is showing — emitYaml() would otherwise hand the field a YAML
      // string that throws on decode (see duplicateSelectorWarning's own
      // construction comment). The field simply keeps its last valid
      // value until the admin resolves the duplicate.
      if (updateDuplicateSelectorWarning(conditions)) {
        return;
      }
      writeYamlField(emitYaml(conditions));
    }

    /**
     * Debounced wrapper around onBuilderChange(), for the Value input's
     * 'input' listener specifically. Real typing fires 'input' once per
     * keystroke, each of which walks every condition row, rebuilds the
     * YAML string, and (when CodeMirror is attached) re-parses/re-
     * highlights the whole editor — coalescing rapid-fire keystrokes
     * into one rebuild after typing pauses avoids doing that full cycle
     * on every character. One shared timer per dialog (not per row) is
     * correct here: only one field is ever being typed into within a
     * single open dialog at a time.
     */
    let builderChangeDebounceTimer = null;
    function debouncedBuilderChange() {
      clearTimeout(builderChangeDebounceTimer);
      builderChangeDebounceTimer = setTimeout(onBuilderChange, 200);
    }

    modeSelect.addEventListener('change', onBuilderChange);
    operatorSelect.addEventListener('change', onBuilderChange);

    function showBuilder() {
      // GitHub issue #88: switching back to the builder does NOT re-parse
      // whatever's currently in the YAML field — see staleEditWarning's
      // own construction comment for why (no client-side YAML parser
      // exists to do that safely). If the field's text no longer matches
      // what the builder itself last wrote there, the rows about to be
      // shown are stale relative to it; flag that now, before the next
      // builder interaction silently overwrites the hand-typed text (see
      // writeYamlField(), which clears this once the builder's output and
      // the field agree again).
      staleEditWarning.style.display = (getYamlFieldValue() !== lastWrittenYaml) ? '' : 'none';
      fieldset.style.display = '';
      yamlViewContainer.style.display = 'none';
    }

    function showYamlView() {
      fieldset.style.display = 'none';
      yamlViewContainer.style.display = '';
      const codeMirror = getCodeMirrorInstance(yamlField);
      if (codeMirror) {
        codeMirror.refresh();
      }
    }

    editSourceToggle.addEventListener('click', showYamlView);
    backToBuilderLink.addEventListener('click', showBuilder);

    // Initial state: an already-saved, decomposable condition renders
    // into rows; a genuinely empty item starts with one blank row; a
    // saved condition too complex to decompose (present in the YAML
    // field but absent from the lookup table PHP built) defaults to the
    // YAML view directly, same as the real widget falling back to
    // "Edit source" for a customized Form API #states value it can't
    // represent visually.
    const decomposed = conditionsByItemValue[itemValue];
    if (!decomposed && yamlField.value.trim() !== '') {
      renderConditions(null);
      showYamlView();
    }
    else {
      renderConditions(decomposed || null);
      showBuilder();
    }

    return {
      clear: function () {
        // Resets the builder's own rows to a single blank one and empties
        // the YAML field, but deliberately does NOT force showBuilder():
        // a user who has "Edit source" open (e.g. editing a
        // too-complex-for-the-builder condition) and clicks "Clear
        // condition" expects just the field to empty, not to be yanked
        // to a different view they didn't ask for. The builder's rows
        // are still reset underneath so they're correct if/when the user
        // does click "Back to condition builder" themselves.
        renderConditions(null);
        writeYamlField('');
      }
    };
  }

})(jQuery, Drupal, once, drupalSettings);
