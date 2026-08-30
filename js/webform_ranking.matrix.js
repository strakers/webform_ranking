/**
 * @file
 * Webform Ranking: matrix (radio grid) behavior.
 *
 * Enforces "each rank used at most once" client-side (a UX convenience
 * only — WebformRanking::validateWebformRanking() re-checks server-side
 * regardless) by reassigning ("stealing") a rank from whichever row
 * currently holds it, rather than disabling already-taken radios —
 * disabling caused a permanent rearrange lockout once every item held a
 * distinct rank. See docs/adr/0011-matrix-rank-reassignment.md. Also
 * keeps conditionally-hidden items' rows/rank-columns in sync — see
 * docs/adr/0012-matrix-conditional-item-visibility-sync.md.
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
      // Seeds `visible` from each row's *already-applied* states.js
      // result (states.js attaches first, before this behavior's own
      // 'state:visible' listener exists to catch that first event) —
      // see docs/adr/0012-matrix-conditional-item-visibility-sync.md.
      var firstInput = groups[name][0];
      if (firstInput && firstInput.offsetParent === null) {
        visible[name] = false;
      }
      toggleRow(firstInput, visible[name] !== false);
    });

    markTakenRanks(groups, groupNames, selected, visible);
    updateRankColumns(table, groups, groupNames, visible);

    groupNames.forEach(function (name) {
      groups[name].forEach(function (input) {
        input.addEventListener('change', function () {
          if (!input.checked) {
            return;
          }

          // Reassign ("steal") rather than block — see
          // docs/adr/0011-matrix-rank-reassignment.md. 'na' is exempt:
          // multiple items can be marked N/A at once.
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
                // A plain property assignment fires no 'change' event,
                // but states.js only re-evaluates a 'value' trigger on
                // 'change'/'keyup' — without this, a #states condition
                // watching the bumped item's rank would stay stale. See
                // docs/adr/0011-matrix-rank-reassignment.md.
                otherInput.dispatchEvent(new Event('change', {bubbles: true}));
                bumpedLabel = rowLabel(otherInput);
              }
              delete selected[otherName];
            });
          }

          selected[name] = rank;
          markTakenRanks(groups, groupNames, selected, visible);
          announce(table, buildAnnouncement(table, input, bumpedLabel));
        });

        // Keeps 'taken' hints and row/column visibility in sync with
        // this item's own live condition — see
        // docs/adr/0012-matrix-conditional-item-visibility-sync.md.
        $(input).on('state:visible', function (e) {
          visible[name] = e.value;
          markTakenRanks(groups, groupNames, selected, visible);
          toggleRow(input, e.value);
          updateRankColumns(table, groups, groupNames, visible);
        });
      });
    });
  }

  /**
   * Groups every radio input in the table by its `name` attribute, i.e.
   * one group per item row.
   *
   * @param {HTMLTableElement} table
   *   The matrix table.
   *
   * @return {Object<string, HTMLInputElement[]>}
   *   Radio inputs keyed by their shared `name` attribute.
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
   * Recomputes the "taken" visual hint for every radio from scratch —
   * simpler than incrementally diffing, and item counts here are small.
   * Purely informational (every radio stays enabled — see ADR-0011).
   * N/A is excluded (not exhaustible); a currently-hidden row's rank is
   * also excluded, since it's unreachable/unsubmittable while hidden.
   *
   * @param {Object<string, HTMLInputElement[]>} groups
   *   Radio inputs keyed by row name, from getRadioGroups().
   * @param {string[]} groupNames
   *   `Object.keys(groups)`, passed in rather than recomputed.
   * @param {Object<string, string>} selected
   *   Row name -> currently selected rank value.
   * @param {Object<string, boolean>} visible
   *   Row name -> whether that row is currently visible (absent means
   *   visible; only an explicit `false` excludes it).
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
   * Hides/shows an item's entire <tr>, not just its label/radio cells
   * (GitHub issue #59 — buildMatrix() can't attach #states to the <tr>
   * itself). Native `hidden` attribute, matching states.js's own plain
   * visible/invisible hiding. See
   * docs/adr/0012-matrix-conditional-item-visibility-sync.md.
   *
   * @param {HTMLInputElement} input
   *   Any radio input belonging to the row.
   * @param {boolean} isVisible
   *   Whether the row should be shown.
   */
  function toggleRow(input, isVisible) {
    var row = input && input.closest('tr');
    if (row) {
      row.hidden = !isVisible;
    }
  }

  /**
   * Hides rank columns beyond what the currently-visible items need
   * (GitHub issue #60 — columns are built from the full configured
   * count and never recomputed). Purely presentational; the N/A column
   * is unaffected; at least one rank column always stays available even
   * with every item hidden. See
   * docs/adr/0012-matrix-conditional-item-visibility-sync.md.
   *
   * @param {HTMLTableElement} table
   *   The matrix table.
   * @param {Object<string, HTMLInputElement[]>} groups
   *   Radio inputs keyed by row name, from getRadioGroups().
   * @param {string[]} groupNames
   *   `Object.keys(groups)`, passed in rather than recomputed.
   * @param {Object<string, boolean>} visible
   *   Row name -> whether that row is currently visible.
   */
  function updateRankColumns(table, groups, groupNames, visible) {
    if (!groupNames.length) {
      return;
    }
    // Every row has the same rank_1..rank_N (+ optional 'na') radios in
    // the same order — buildMatrix() builds all rows from the same
    // configured rank count — so the first row's own radio list is
    // enough to determine both the total rank count and whether an N/A
    // column exists, without assuming anything about header markup.
    var sample = groups[groupNames[0]];
    var hasNa = sample.length > 0 && sample[sample.length - 1].value === 'na';
    var rankCount = sample.length - (hasNa ? 1 : 0);
    if (rankCount < 1) {
      return;
    }

    var visibleCount = groupNames.filter(function (name) {
      return visible[name] !== false;
    }).length;
    var neededRanks = Math.max(1, Math.min(rankCount, visibleCount));

    // Header cells: [blank label column, rank_1, rank_2, ..., rank_N,
    // (na)] — same indexing buildAnnouncement() below already relies
    // on (`columnIndex + 1`).
    var headers = table.querySelectorAll('thead th');

    for (var rank = 1; rank <= rankCount; rank++) {
      var show = rank <= neededRanks;
      var header = headers[rank];
      if (header) {
        header.hidden = !show;
      }
      var rankValue = String(rank);
      groupNames.forEach(function (name) {
        var radio = groups[name].filter(function (input) {
          return input.value === rankValue;
        })[0];
        var cell = radio && radio.closest('td');
        if (cell) {
          cell.hidden = !show;
        }
      });
    }
  }

  /**
   * Reads back an input's row's item label, for announcements.
   *
   * @param {HTMLInputElement} input
   *   Any radio input belonging to the row.
   *
   * @return {string}
   *   The row's item label text, or '' if it can't be found.
   */
  function rowLabel(input) {
    var row = input.closest('tr');
    var rowLabelCell = row ? row.querySelector('th, td') : null;
    return rowLabelCell ? rowLabelCell.textContent.trim() : '';
  }

  /**
   * Builds the live-region announcement text for a rank selection.
   *
   * @param {HTMLTableElement} table
   *   The matrix table.
   * @param {HTMLInputElement} input
   *   The radio input the respondent just selected.
   * @param {string|null} bumpedLabel
   *   The label of another row's item that lost its rank to this
   *   selection (a "steal"), or null if nothing was bumped.
   *
   * @return {string}
   *   The announcement text.
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
