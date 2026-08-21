/**
 * @file
 * Webform Ranking: matrix (radio grid) behavior.
 *
 * Enforces "each rank used at most once" client-side by disabling a
 * rank's radio in every other row once it's selected, and re-enabling
 * it when the selection changes or clears. This is a UX convenience
 * only — WebformRanking::validateWebformRanking() re-checks the same
 * rule server-side regardless, since disabled inputs are trivially
 * bypassable (devtools, JS-off, direct POST).
 */
(function (Drupal, once, $) {
  'use strict';

  Drupal.behaviors.webformRankingMatrix = {
    attach: function (context) {
      once('webform-ranking-matrix', '.webform-ranking-matrix', context).forEach(initMatrix);
    }
  };

  function initMatrix(table) {
    var groups = getRadioGroups(table);
    var groupNames = Object.keys(groups);
    // name -> currently selected value ('1', '2', ..., 'na', or absent).
    var selected = {};
    // name -> whether that row's own #states condition currently
    // shows it. Absent (never set) means "no condition, always
    // visible" — only an explicit `false` excludes a row.
    var visible = {};

    groupNames.forEach(function (name) {
      groups[name].forEach(function (input) {
        if (input.checked) {
          selected[name] = input.value;
        }
      });
    });

    applyExclusivity(groups, groupNames, selected, visible);

    groupNames.forEach(function (name) {
      groups[name].forEach(function (input) {
        input.addEventListener('change', function () {
          if (!input.checked) {
            return;
          }
          selected[name] = input.value;
          applyExclusivity(groups, groupNames, selected, visible);
          announce(table, buildAnnouncement(table, input));
        });

        // buildMatrix() applies each conditionally-visible item's own
        // #states to every radio in its row, so states.js fires this
        // event on every one of them when the condition's live value
        // changes (see web/core/misc/states.js, Dependent#reevaluate()
        // and #defaultTrigger()). Without this, a rank "used" by a
        // now-hidden item stayed disabled everywhere else even after
        // the item hiding it — a stale client-side block the server
        // itself doesn't enforce, since validateWebformRanking()
        // already drops a hidden item's selection before checking
        // rank uniqueness (see WebformRankingVisibilityResolver).
        $(input).on('state:visible', function (e) {
          visible[name] = e.value;
          applyExclusivity(groups, groupNames, selected, visible);
        });
      });
    });
  }

  /**
   * Groups every radio input in the table by its `name` attribute, i.e.
   * one group per item row.
   */
  function getRadioGroups(table) {
    var groups = {};
    Array.prototype.forEach.call(table.querySelectorAll('input[type="radio"]'), function (input) {
      var name = input.getAttribute('name');
      groups[name] = groups[name] || [];
      groups[name].push(input);
    });
    return groups;
  }

  /**
   * Recomputes disabled state for every radio from scratch based on
   * current selections. A full reset-then-reapply on every change is
   * simpler and less error-prone than incrementally diffing old vs.
   * new state, and the item counts involved here are always small.
   *
   * N/A is deliberately excluded from exclusivity — multiple items can
   * be marked N/A at once, only numeric ranks are a shared, exhaustible
   * resource.
   *
   * A currently-hidden row (visible[name] === false) is also excluded
   * from "used" ranks — its own rank is unreachable/unsubmittable
   * while hidden (see WebformRankingVisibilityResolver), so it must
   * not keep blocking that rank for every other, visible item.
   */
  function applyExclusivity(groups, groupNames, selected, visible) {
    groupNames.forEach(function (name) {
      groups[name].forEach(function (input) {
        input.disabled = false;
        input.removeAttribute('aria-disabled');
      });
    });

    var usedRanks = {};
    groupNames.forEach(function (name) {
      if (visible[name] === false) {
        return;
      }
      var value = selected[name];
      if (value && value !== 'na') {
        usedRanks[value] = name;
      }
    });

    groupNames.forEach(function (ownerName) {
      Object.keys(usedRanks).forEach(function (rank) {
        if (usedRanks[rank] === ownerName) {
          // The row that currently holds this rank keeps it enabled —
          // otherwise a checked-but-disabled radio would stop
          // submitting its own value.
          return;
        }
        groups[ownerName].forEach(function (input) {
          if (input.value === rank) {
            input.disabled = true;
            input.setAttribute('aria-disabled', 'true');
          }
        });
      });
    });
  }

  function buildAnnouncement(table, input) {
    var row = input.closest('tr');
    var rowLabelCell = row ? row.querySelector('th, td') : null;
    var itemLabel = rowLabelCell ? rowLabelCell.textContent.trim() : '';

    if (input.value === 'na') {
      return Drupal.t('@item marked as not applicable.', {'@item': itemLabel});
    }

    var radiosInRow = Array.prototype.slice.call(row.querySelectorAll('input[type="radio"]'));
    var columnIndex = radiosInRow.indexOf(input);
    // +1: header row's first column is the (blank) item-label header.
    var headerCell = table.querySelectorAll('thead th')[columnIndex + 1];
    var rankLabel = headerCell ? headerCell.textContent.trim() : input.value;

    return Drupal.t('@item ranked @rank.', {'@item': itemLabel, '@rank': rankLabel});
  }

  function announce(table, message) {
    var wrapper = table.closest('.js-form-item') || table.parentNode;
    var region = wrapper ? wrapper.querySelector('.webform-ranking-matrix__live-region') : null;
    if (!region) {
      return;
    }
    // Clear first so a repeated identical announcement (e.g. selecting
    // the same rank twice via keyboard) still triggers a fresh
    // mutation for the screen reader to pick up.
    region.textContent = '';
    window.setTimeout(function () {
      region.textContent = message;
    }, 50);
  }

})(Drupal, once, jQuery);
