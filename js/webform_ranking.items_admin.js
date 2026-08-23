/**
 * @file
 * Per-item "conditional visibility" YAML field on the Ranking element's
 * config form: presented in a dialog rather than inline in the items
 * table, so the table itself stays compact regardless of how many items
 * have a condition configured.
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
 * Deliberately not using Drupal's States API for the trigger, for the
 * same reason as before: the previous conditional-UI attempt here
 * (webform_element_states nested inside a #webform_multiple row) crashed
 * in production, and this avoids leaning on another not-fully-verified
 * Drupal-internals mechanism inside the same nested-widget context.
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
 * The dialog now also contains a condition *picker* (element/trigger/value
 * dropdowns, GitHub issue #13) alongside the YAML field — this file only
 * relocates the whole group into the dialog as one unit and resets the
 * picker's fields on "Clear condition"; the picker's own field wiring
 * (value autocomplete, trigger-driven visibility) comes from Webform's own
 * 'webform/webform.element.states' library, reused via matching CSS
 * classes — see the 'condition_group' field definition in
 * WebformRanking::form().
 *
 * Uses const/let, unlike this module's other JS files (still var as of
 * writing) — see the module's tracked refactor issue to bring those in
 * line.
 */
(function ($, Drupal, once) {
  'use strict';

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
    // The quick condition picker (element/trigger/value) added alongside
    // the YAML fallback for GitHub issue #13 — cleared together with the
    // YAML field by "Clear condition" below, so an emptied item never
    // leaves stale picker selections behind (see
    // WebformRanking::validateConfigurationForm(), which treats a
    // non-empty selector as authoritative over the YAML field).
    const modeField = wrapper.querySelector('.webform-ranking-item-condition-mode select');
    const selectorField = wrapper.querySelector('.webform-states-table--selector select');
    const triggerField = wrapper.querySelector('.webform-states-table--trigger select');
    const valueField = wrapper.querySelector('.webform-states-table--value input');

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
          width: 500,
          buttons: [
            {
              text: Drupal.t('Clear condition'),
              click: function () {
                yamlField.value = '';
                // CodeMirror-backed fields often mirror their value
                // into a separate rendered editor instance, not just
                // the underlying <textarea> — dispatching input/change
                // lets Webform's own CodeMirror JS (if attached) pick
                // up the clear.
                yamlField.dispatchEvent(new Event('input', {bubbles: true}));
                yamlField.dispatchEvent(new Event('change', {bubbles: true}));

                if (modeField) {
                  modeField.value = 'visible';
                }
                if (selectorField) {
                  selectorField.value = '';
                  selectorField.dispatchEvent(new Event('change', {bubbles: true}));
                }
                if (triggerField) {
                  triggerField.value = 'value';
                }
                if (valueField) {
                  valueField.value = '';
                }
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

})(jQuery, Drupal, once);
