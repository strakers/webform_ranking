/**
 * @file
 * Per-item "conditional visibility" field on the Ranking element's config
 * form: presented in a dialog rather than inline in the items table, so
 * the table itself stays compact regardless of how many items have a
 * condition configured.
 *
 * History: an earlier version used a per-row checkbox to inline
 * show/hide the YAML field (progressive disclosure), scoped to "this
 * row" via a structure-agnostic DOM walk-up (findRowContainer()). That
 * checkbox/toggle never actually worked (GitHub issue #4) — the walk-up
 * heuristic scoped checkboxes to the wrong row's wrapper against
 * webform_multiple's real markup. Rather than debug that heuristic
 * further, this was redesigned per-item to a dialog: even inline-and-
 * expanded, a raw YAML field per row was still real, permanent visual
 * clutter once more than a couple of items had a condition — see GitHub
 * issue #4's discussion. A dialog also sidesteps the row-scoping problem
 * entirely, since the trigger button is inserted immediately next to its
 * own item's wrapper, not matched up to it via a separate DOM search.
 *
 * GitHub issue #65 (redo of #13): the dialog's contents were originally
 * always a raw #states YAML textarea. This file now also builds a visual
 * condition-rows picker — a <fieldset> containing a <table> with the same
 * State/Element/Trigger-Value columns and 'webform-states-table*' classes
 * the real element-level "Conditional logic" tab's own builder uses — as
 * an ADDITIONAL client-side view layered over that SAME textarea, not a
 * replacement field. WebformRanking::form() (PHP) is unchanged for this
 * field beyond attaching drupalSettings the builder reads; the textarea
 * itself keeps its exact original #type/#wrapper_attributes/position, on
 * purpose — an earlier attempt at issue #65 (closed PR #64) added a new
 * wrapping container here and incidentally broke the trigger button's
 * own markup as a side effect. See initConditionBuilder() below for how
 * the builder avoids that: it only ever inserts *new* sibling content
 * inside the wrapper this file already finds, never restructures what
 * PHP rendered.
 *
 * Deliberately not using Drupal's States API for the *trigger button*
 * itself, for the same reason as before: the previous conditional-UI
 * attempt here (webform_element_states nested inside a #webform_multiple
 * row) crashed in production, and this avoids leaning on another
 * not-fully-verified Drupal-internals mechanism inside the same
 * nested-widget context. (The condition-rows *builder* below reuses
 * Webform's own 'webform/webform.element.states' library for value
 * autocomplete — that library is pure DOM-class-based JS with no
 * comparable nesting risk, unlike re-using the webform_element_states
 * Form API element itself.)
 *
 * Dialog + form-submission note: jQuery UI's dialog widget, by default,
 * appends its wrapper to <body>. If the wrapped element's own form isn't
 * itself a descendant of <body> in a way that keeps it inside <form>
 * (typical for an admin page's layout), moving the YAML field's wrapper
 * there would silently drop it out of the submitted form. `appendTo` is
 * explicitly set to the closest <form> below to guarantee the field
 * stays a form descendant regardless of dialog visual positioning
 * (dialogs are CSS-positioned as an overlay, so this doesn't affect
 * where it appears on screen). Verified via a real submission in
 * WebformRankingItemsAdminJavaScriptTest: reopening the saved element's
 * config form after using the dialog shows the condition persisted.
 *
 * Uses const/let, unlike this module's other JS files (still var as of
 * writing) — see the module's tracked refactor issue to bring those in
 * line.
 */
(function ($, Drupal, once, drupalSettings) {
  'use strict';

  const NESTED_TRIGGERS = ['pattern', '!pattern', 'less', 'less_equal', 'greater', 'greater_equal', 'between', '!between'];
  const NO_VALUE_TRIGGERS = ['empty', 'filled', 'checked', 'unchecked'];
  // Only these actually affect item inclusion —
  // WebformRankingVisibilityResolver::isVisible() ignores every other
  // state. The rest are still offered (matching the real element-level
  // builder's own full flexibility) but trigger a warning note — see
  // updateStateWarning().
  const VISIBILITY_STATES = ['visible', 'invisible', 'visible-slide', 'invisible-slide'];

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

    const builder = initConditionBuilder(wrapper, yamlField);

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

    let dialog = null;

    trigger.addEventListener('click', function () {
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
          // Overrides Drupal core's default dialog close handler
          // (drupalSettings.dialog.close), which calls
          // Drupal.detachBehaviors(event.target, null, 'unload') — built
          // for disposable AJAX-loaded dialog content that's discarded
          // after closing. This dialog wraps a permanent, reusable part
          // of the same form, reopened on every click, so that default
          // handler is actively harmful here: detaching behaviors for
          // 'unload' tore down the wrapped field entirely, discovered
          // via a real form-submission test
          // (WebformRankingItemsAdminJavaScriptTest::
          // testConditionPersistsThroughSubmission) where the edited
          // item's <textarea> was simply gone from the DOM after
          // closing the dialog once. A no-op keeps the same DOM nodes
          // alive and reusable across opens.
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
      const codeMirrorWrapper = yamlField.nextElementSibling;
      if (codeMirrorWrapper && codeMirrorWrapper.CodeMirror) {
        codeMirrorWrapper.CodeMirror.refresh();
      }
    });
  }

  /**
   * Builds the visual condition-rows picker for one item's dialog.
   *
   * Inserts two new sibling containers as the FIRST children of
   * `wrapper` (ahead of the server-rendered YAML field's own markup,
   * which is left completely untouched in place) — one holding the
   * visual builder, one a plain "Edit source" toggle link that
   * shows/hides the original YAML field. Only one of the two is ever
   * visible at a time.
   *
   * The condition-rows DOM *is* the state — there's no separate JS data
   * object mirroring it. Toggling to the YAML view never destroys the
   * rows, just hides them, so toggling back shows exactly what was
   * there before (see file-level docblock's note on this being a
   * deliberate, documented one-way-per-session choice: hand-edited YAML
   * typed while in that view is not re-parsed back into rows without
   * closing and reopening the dialog, which re-reads the server-computed
   * decomposition fresh).
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

    const row = wrapper.closest('tr');
    const valueInput = row ? row.querySelector('input[name$="[value]"]') : null;
    const itemValue = valueInput ? valueInput.value : '';

    // <fieldset> + <table class="webform-states-table"> — the same
    // wrapping element and table classes the real element-level
    // "Conditional logic" tab's own builder uses, so this inherits the
    // admin theme's existing table/fieldset styling for free rather
    // than looking like a bespoke, different-looking widget.
    //
    // The fieldset/legend/label class list and the legend's own
    // <span class="fieldset__label"> wrapper are copied verbatim from
    // that same tab's real, server-rendered markup (confirmed by
    // inspecting a live admin form: #edit-conditional-logic) — Drupal's
    // admin theme styles fieldsets via these specific classes, not the
    // bare <fieldset>/<legend> tags, so matching them exactly (not just
    // approximately) is what actually gets the same visual treatment.
    const fieldset = document.createElement('fieldset');
    fieldset.className = 'webform-ranking-item-condition-builder js-webform-type-fieldset webform-type-fieldset fieldset js-form-item form-item js-form-wrapper form-wrapper';
    const legend = document.createElement('legend');
    legend.className = 'fieldset__legend fieldset__legend--visible';
    const legendLabel = document.createElement('span');
    legendLabel.className = 'fieldset__label';
    legendLabel.textContent = Drupal.t('Condition');
    legend.appendChild(legendLabel);
    fieldset.appendChild(legend);

    // Real fieldsets wrap their content (everything but the legend) in
    // this div — also confirmed against the same live markup — which
    // is what the admin theme's fieldset CSS actually targets for
    // padding/spacing, not the fieldset element directly.
    const fieldsetWrapper = document.createElement('div');
    fieldsetWrapper.className = 'fieldset__wrapper';
    fieldset.appendChild(fieldsetWrapper);

    const stateWarning = document.createElement('div');
    stateWarning.className = 'webform-ranking-item-condition-state-warning messages messages--warning';
    stateWarning.style.display = 'none';
    stateWarning.textContent = Drupal.t('This state does not affect whether the item is ranked or hidden — only Visible/Hidden (and their Slide variants) do.');
    fieldsetWrapper.appendChild(stateWarning);

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

    // State row: one per item (not repeatable — see VISIBILITY_STATES
    // above on why only a single state is offered here, unlike the real
    // builder's "Add another state").
    const stateRow = document.createElement('tr');
    stateRow.className = 'webform-states-table--state';
    const stateCell = document.createElement('td');
    stateCell.className = 'webform-states-table--state';
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

    const actions = document.createElement('div');
    actions.className = 'webform-ranking-item-condition-actions';
    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.className = 'webform-ranking-item-condition-add button';
    addButton.textContent = Drupal.t('Add another condition');
    actions.appendChild(addButton);

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
    Drupal.attachBehaviors(wrapper);

    function addOption(select, value, label) {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      select.appendChild(option);
    }

    function addOptionTo(parent, value, label) {
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
     * Populates the Element (selector) dropdown, including optgroups.
     *
     * $webform->getElementsSelectorOptions() mixes flat 'selector =>
     * label' entries (a single-value element) with nested 'group label
     * => {selector => label}' entries (a composite element's own
     * sub-selectors, e.g. this module's own per-item rank selectors) —
     * the same optgroup-shaped data the real "Conditional logic" tab's
     * Element dropdown renders.
     */
    function populateSelectorOptions(select) {
      select.innerHTML = '';
      addOption(select, '', Drupal.t('- Select -'));
      Object.keys(selectorOptions).forEach(function (key) {
        const value = selectorOptions[key];
        if (typeof value === 'object' && value !== null) {
          const optgroup = document.createElement('optgroup');
          optgroup.label = key;
          Object.keys(value).forEach(function (selector) {
            addOptionTo(optgroup, selector, value[selector]);
          });
          select.appendChild(optgroup);
        }
        else {
          addOptionTo(select, key, value);
        }
      });
    }

    function populateTriggerOptions(select) {
      Object.keys(triggerOptions).forEach(function (key) {
        addOption(select, key, triggerOptions[key]);
      });
    }

    /**
     * Builds one condition <tr>: element/trigger/value + remove button.
     *
     * Classes match the real element-level builder's own
     * ('webform-states-table--condition'/'--selector'/'--trigger'/
     * '--value'), so 'webform/webform.element.states' (already loaded
     * page-wide via the element-level "Conditional logic" tab, since
     * every element edit form has one) wires up value autocomplete on
     * these rows exactly as it does the real ones — no JS of this
     * module's own needed for that part.
     *
     * 'webform-states-table--condition' lives on the <tr> ONLY, not
     * also on the inner cell wrapping trigger+value — that library's
     * own behavior scopes itself via jQuery's find() from whichever
     * element carries this class (web/modules/contrib/webform/js/
     * webform.element.states.js), which only locates the Element
     * dropdown as a descendant from the *row*, since it's a sibling
     * <td> of the trigger/value cell, not nested inside it. A real bug
     * caught by a live browser test, not guessed: the real widget's own
     * server-rendered markup puts this same class on both the <tr> AND
     * the condition cell (see WebformElementStates::buildConditionRow()),
     * which duplicating here caused a genuine
     * `TypeError: this.source is not a function` from jQuery UI's
     * autocomplete widget — the cell-level match's empty-selector
     * `.find()` still re-ran `.autocomplete({minLength: 0})` on the
     * same value input a second time, corrupting the widget instance
     * the row-level match had already correctly initialized.
     */
    function createConditionRow(data) {
      data = data || {};
      const conditionRow = document.createElement('tr');
      conditionRow.className = 'webform-states-table--condition webform-ranking-item-condition-row';

      const stateTd = document.createElement('td');
      stateTd.className = 'webform-states-table--state';
      conditionRow.appendChild(stateTd);

      const selectorTd = document.createElement('td');
      selectorTd.className = 'webform-states-table--selector';
      const selectorSelect = createSelectElement();
      populateSelectorOptions(selectorSelect);
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
          addOptionTo(selectorSelect, data.selector, data.selector);
          selectorSelect.value = data.selector;
        }
      }
      selectorTd.appendChild(selectorSelect);
      conditionRow.appendChild(selectorTd);

      const conditionTd = document.createElement('td');
      const triggerWrapper = document.createElement('div');
      triggerWrapper.className = 'webform-states-table--trigger';
      const triggerSelect = createSelectElement();
      populateTriggerOptions(triggerSelect);
      triggerSelect.value = data.trigger || 'value';
      triggerWrapper.appendChild(triggerSelect);
      conditionTd.appendChild(triggerWrapper);

      const valueWrapper = document.createElement('div');
      valueWrapper.className = 'webform-states-table--value';
      const valueInputEl = createTextInputElement();
      valueInputEl.placeholder = Drupal.t('Enter value…');
      valueInputEl.value = data.value || '';
      valueWrapper.appendChild(valueInputEl);
      conditionTd.appendChild(valueWrapper);
      conditionRow.appendChild(conditionTd);

      const operationsTd = document.createElement('td');
      operationsTd.className = 'webform-states-table--operations';
      const removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.className = 'webform-ranking-item-condition-remove button button--small';
      removeButton.textContent = Drupal.t('Remove');
      operationsTd.appendChild(removeButton);
      conditionRow.appendChild(operationsTd);

      selectorSelect.addEventListener('change', onBuilderChange);
      triggerSelect.addEventListener('change', function () {
        updateValueFieldVisibility(conditionRow, triggerSelect.value);
        onBuilderChange();
      });
      valueInputEl.addEventListener('input', onBuilderChange);
      // 'change' too, not just 'input': covers a value set
      // programmatically (autofill, or a test driving the field via
      // .val()+trigger('change')) rather than typed keystroke-by-
      // keystroke. onBuilderChange() is idempotent, so hearing both for
      // a single real edit is harmless.
      valueInputEl.addEventListener('change', onBuilderChange);
      removeButton.addEventListener('click', function () {
        conditionRow.remove();
        updateRowChrome();
        onBuilderChange();
      });

      updateValueFieldVisibility(conditionRow, triggerSelect.value);

      return conditionRow;
    }

    /**
     * Hides the Value input for triggers that don't use one
     * (empty/filled/checked/unchecked) — this module's own lightweight
     * equivalent of the real builder's server-rendered per-row '#states'
     * for the same purpose, which doesn't apply here since these rows
     * are built/cloned client-side, not tied to a specific server-known
     * row index.
     */
    function updateValueFieldVisibility(conditionRow, trigger) {
      const valueWrapper = conditionRow.querySelector('.webform-states-table--value');
      valueWrapper.style.display = NO_VALUE_TRIGGERS.indexOf(trigger) === -1 ? '' : 'none';
    }

    /**
     * Shows a note when the selected State doesn't affect item
     * inclusion — see VISIBILITY_STATES above.
     */
    function updateStateWarning() {
      stateWarning.style.display = VISIBILITY_STATES.indexOf(modeSelect.value) === -1 ? '' : 'none';
    }

    /**
     * Updates each row's own "Remove" button, showing it only once
     * removing it would still leave at least one row — matching the
     * real builder's own "always at least one row" floor.
     *
     * The combining-operator select itself ("if [All/Any/One] of the
     * following is met:") is NOT toggled here — it's always visible on
     * the state row, same as the real builder, regardless of how many
     * condition rows currently exist.
     */
    function updateRowChrome() {
      const rows = tbody.querySelectorAll('.webform-ranking-item-condition-row');
      rows.forEach(function (conditionRow) {
        const removeButton = conditionRow.querySelector('.webform-ranking-item-condition-remove');
        removeButton.style.display = rows.length > 1 ? '' : 'none';
      });
    }

    function addConditionRow(data) {
      const conditionRow = createConditionRow(data);
      tbody.appendChild(conditionRow);
      Drupal.attachBehaviors(conditionRow);
      updateRowChrome();
      return conditionRow;
    }

    /**
     * Renders the builder from a decomposeItemStatesToConditions()-shaped
     * object (see the PHP method of the same purpose), or a single empty
     * row when there's nothing to show yet.
     */
    function renderConditions(decomposed) {
      tbody.querySelectorAll('.webform-ranking-item-condition-row').forEach(function (conditionRow) {
        conditionRow.remove();
      });
      if (decomposed && decomposed.conditions && decomposed.conditions.length) {
        modeSelect.value = decomposed.mode;
        operatorSelect.value = decomposed.operator;
        decomposed.conditions.forEach(function (condition) {
          addConditionRow(condition);
        });
      }
      else {
        modeSelect.value = 'visible';
        operatorSelect.value = 'and';
        addConditionRow({});
      }
      updateStateWarning();
      updateRowChrome();
    }

    /**
     * Single-quoted YAML scalar: the only escape rule needed is doubling
     * a literal embedded `'` — single-quoted YAML strings don't treat
     * colons, double quotes, or brackets specially, which is what makes
     * this safe for selectors like `:input[name="x"]` without a general
     * YAML-escaping implementation.
     */
    function yamlString(value) {
      return '\'' + String(value).replace(/'/g, '\'\'') + '\'';
    }

    function yamlCondition(condition) {
      if (condition.trigger === 'value' || condition.trigger === '!value') {
        return '{' + yamlString(condition.trigger) + ': ' + yamlString(condition.value) + '}';
      }
      if (NESTED_TRIGGERS.indexOf(condition.trigger) !== -1) {
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
     * @return {string}
     *   YAML text, or '' if no condition row has a selector chosen yet.
     */
    function emitYaml() {
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
     */
    function writeYamlField(value) {
      const codeMirrorWrapper = yamlField.nextElementSibling;
      if (codeMirrorWrapper && codeMirrorWrapper.CodeMirror) {
        codeMirrorWrapper.CodeMirror.setValue(value);
        codeMirrorWrapper.CodeMirror.save();
      }
      else {
        yamlField.value = value;
      }
      yamlField.dispatchEvent(new Event('input', {bubbles: true}));
      yamlField.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function onBuilderChange() {
      updateStateWarning();
      writeYamlField(emitYaml());
    }

    modeSelect.addEventListener('change', onBuilderChange);
    operatorSelect.addEventListener('change', onBuilderChange);
    addButton.addEventListener('click', function () {
      addConditionRow({});
      onBuilderChange();
    });

    function showBuilder() {
      fieldset.style.display = '';
      yamlViewContainer.style.display = 'none';
    }

    function showYamlView() {
      fieldset.style.display = 'none';
      yamlViewContainer.style.display = '';
      const codeMirrorWrapper = yamlField.nextElementSibling;
      if (codeMirrorWrapper && codeMirrorWrapper.CodeMirror) {
        codeMirrorWrapper.CodeMirror.refresh();
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
        renderConditions(null);
        writeYamlField('');
        showBuilder();
      }
    };
  }

})(jQuery, Drupal, once, drupalSettings);
