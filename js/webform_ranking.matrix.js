/**
 * @file
 * Webform Ranking: matrix (radio grid) behavior.
 *
 * Enforces "each rank used at most once" client-side by reassigning a
 * rank away from whichever row currently holds it ("stealing") when a
 * different row selects it, and marking already-taken cells with a
 * visual hint. This is a UX convenience only —
 * WebformRanking::validateWebformRanking() re-checks the same rule
 * server-side regardless, since client-side state is trivially
 * bypassable (devtools, JS-off, direct POST).
 *
 * Earlier versions of this file disabled a rank's radio in every other
 * row once it was selected, rather than reassigning it. That's what a
 * native disabled <input> can never support: once every item held a
 * distinct rank, every "swap" needed two simultaneous changes that
 * permanently, mutually blocked each other via disabled inputs a user
 * could no longer click at all — a fully-ranked matrix could never be
 * rearranged again without an N/A escape hatch (and not at all if
 * #allow_na was off). Reassignment removes that lockout entirely.
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

    markTakenRanks(groups, groupNames, selected, visible);

    groupNames.forEach(function (name) {
      groups[name].forEach(function (input) {
        input.addEventListener('change', function () {
          if (!input.checked) {
            return;
          }

          // Reassign ("steal") rather than block: if another row
          // currently holds this exact rank, it loses it — 'na' is
          // exempt, since multiple items can be marked N/A at once
          // and it isn't a shared, exhaustible resource.
          var rank = input.value;
          var bumpedLabel = null;
          if (rank !== 'na') {
            groupNames.forEach(function (otherName) {
              // Skip a currently-hidden row: its stale selection
              // already doesn't count as "used" (see markTakenRanks()),
              // matching what the server drops before checking
              // uniqueness — nothing to steal from.
              if (otherName === name || visible[otherName] === false || selected[otherName] !== rank) {
                return;
              }
              var otherInput = groups[otherName].filter(function (candidate) {
                return candidate.value === rank;
              })[0];
              if (otherInput) {
                otherInput.checked = false;
                bumpedLabel = rowLabel(otherInput);
              }
              delete selected[otherName];
            });
          }

          selected[name] = rank;
          markTakenRanks(groups, groupNames, selected, visible);
          announce(table, buildAnnouncement(table, input, bumpedLabel));
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
          markTakenRanks(groups, groupNames, selected, visible);
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
   * Recomputes the "taken" visual hint for every radio from scratch
   * based on current selections. A full reset-then-reapply on every
   * change is simpler and less error-prone than incrementally diffing
   * old vs. new state, and the item counts involved here are always
   * small.
   *
   * Purely informational — every radio stays enabled and clickable
   * regardless. This is what makes reassignment ("stealing", see the
   * 'change' handler above) possible at all: a genuinely disabled
   * input can't be clicked to steal its rank back, which is exactly
   * the lockout this replaced (see file docblock).
   *
   * N/A is deliberately excluded — multiple items can be marked N/A at
   * once, only numeric ranks are a shared, exhaustible resource.
   *
   * A currently-hidden row (visible[name] === false) is also excluded
   * from "taken" ranks — its own rank is unreachable/unsubmittable
   * while hidden (see WebformRankingVisibilityResolver), so it must
   * not keep marking that rank as taken for every other, visible item.
   */
  function markTakenRanks(groups, groupNames, selected, visible) {
    groupNames.forEach(function (name) {
      groups[name].forEach(function (input) {
        input.classList.remove('webform-ranking-matrix__radio--taken');
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
          // The row that currently holds this rank isn't "taken" from
          // its own point of view.
          return;
        }
        groups[ownerName].forEach(function (input) {
          if (input.value === rank) {
            input.classList.add('webform-ranking-matrix__radio--taken');
          }
        });
      });
    });
  }

  /**
   * Reads back an input's row's item label, for announcements.
   */
  function rowLabel(input) {
    var row = input.closest('tr');
    var rowLabelCell = row ? row.querySelector('th, td') : null;
    return rowLabelCell ? rowLabelCell.textContent.trim() : '';
  }

  /**
   * @param {string|null} bumpedLabel
   *   The label of another row's item that lost its rank to this
   *   selection (a "steal"), or null if nothing was bumped.
   */
  function buildAnnouncement(table, input, bumpedLabel) {
    var itemLabel = rowLabel(input);

    if (input.value === 'na') {
      return Drupal.t('@item marked as not applicable.', {'@item': itemLabel});
    }

    var row = input.closest('tr');
    var radiosInRow = Array.prototype.slice.call(row.querySelectorAll('input[type="radio"]'));
    var columnIndex = radiosInRow.indexOf(input);
    // +1: header row's first column is the (blank) item-label header.
    var headerCell = table.querySelectorAll('thead th')[columnIndex + 1];
    var rankLabel = headerCell ? headerCell.textContent.trim() : input.value;

    if (bumpedLabel) {
      return Drupal.t('@item ranked @rank, replacing @bumped.', {'@item': itemLabel, '@rank': rankLabel, '@bumped': bumpedLabel});
    }
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
