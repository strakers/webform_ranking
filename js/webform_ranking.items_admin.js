/**
 * @file
 * Progressive disclosure for the per-item "conditional visibility"
 * YAML field on the Ranking element's config form.
 *
 * Deliberately not using Drupal's States API for this toggle, even
 * though that's the normal mechanism for show/hide-on-checkbox — see
 * the field definitions in WebformRanking::form() for why: the
 * previous attempt at conditional UI here (webform_element_states
 * nested inside a #webform_multiple row) crashed in production, and
 * this avoids leaning on another not-fully-verified Drupal-internals
 * mechanism inside the same nested-widget context. Plain DOM show/hide
 * instead.
 *
 * Verification note: the strategy for finding "this checkbox's row"
 * (findRowContainer(), below) is deliberately structure-agnostic —
 * it does NOT assume webform_multiple renders rows as <tr>, a
 * particular class, or any specific markup, because that markup was
 * not verified against the installed Webform version. If the toggle
 * doesn't correctly scope to a single row when tested, this function
 * is the first place to look.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.webformRankingItemsAdmin = {
    attach: function (context) {
      once('webform-ranking-items-admin', '.webform-ranking-item-use-states', context).forEach(function (checkbox) {
        var row = findRowContainer(checkbox);
        if (!row) {
          // Could not confidently scope this checkbox to a single
          // row's YAML field — bail out rather than risk toggling the
          // wrong row's field.
          return;
        }

        var yamlField = row.querySelector('.webform-ranking-item-states');
        var yamlWrapper = row.querySelector('.webform-ranking-item-states-wrapper');
        if (!yamlField || !yamlWrapper) {
          return;
        }

        function setVisible(visible) {
          yamlWrapper.style.display = visible ? '' : 'none';
        }

        // Initial state on page load: if this row already has YAML
        // content (editing an existing conditional item), show it and
        // check the box; otherwise hide it. This is what makes editing
        // an existing conditional item not look broken.
        var hasContent = Boolean(yamlField.value && yamlField.value.trim() !== '');
        checkbox.checked = hasContent;
        setVisible(hasContent);

        checkbox.addEventListener('change', function () {
          if (checkbox.checked) {
            setVisible(true);
            return;
          }

          // Clearing on uncheck: an unchecked box and lingering stale
          // YAML must never disagree with each other. The field's own
          // content is the real source of truth server-side (see
          // validateConfigurationForm() — this checkbox itself is
          // never read there), so leaving stale text behind while
          // hidden would silently re-activate a condition the admin
          // believes they turned off.
          yamlField.value = '';
          // CodeMirror-backed fields often mirror their value into a
          // separate rendered editor instance, not just the underlying
          // <textarea>. Dispatching input/change lets Webform's own
          // CodeMirror JS (if attached) pick up the clear; if it
          // doesn't, the plain <textarea> value is still correctly
          // cleared for submission either way.
          yamlField.dispatchEvent(new Event('input', {bubbles: true}));
          yamlField.dispatchEvent(new Event('change', {bubbles: true}));
          setVisible(false);
        });
      });
    }
  };

  /**
   * Walks up from a checkbox to find the nearest ancestor scoped to
   * exactly this row — the smallest container whose descendants
   * include exactly one '.webform-ranking-item-states-wrapper'.
   * Structure-agnostic on purpose (see file-level note above).
   *
   * @param {HTMLElement} checkbox
   * @return {HTMLElement|null}
   */
  function findRowContainer(checkbox) {
    var node = checkbox.parentElement;
    var attempts = 0;
    // Capped rather than walking indefinitely toward <body>/<html>,
    // where it would start matching every row's wrapper at once.
    while (node && attempts < 12) {
      var matches = node.querySelectorAll('.webform-ranking-item-states-wrapper');
      if (matches.length === 1) {
        return node;
      }
      node = node.parentElement;
      attempts++;
    }
    return null;
  }

})(Drupal, once);
