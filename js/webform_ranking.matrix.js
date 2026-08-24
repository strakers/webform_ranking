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
      // Seeds `visible` from each row's *already-applied* states.js
      // result, not just from events caught from here on. Both
      // Drupal.behaviors.states (core/drupal.states, a declared
      // dependency of this library — see webform_ranking.libraries.yml)
      // and this behavior run during the same page-load attach pass,
      // states.js first; by the time this runs, a conditionally-hidden
      // row's cells are already hidden, but the 'state:visible' event
      // that announced it fired *before* the listener below existed to
      // catch it. Needed here so toggleRow() (GitHub issue #59) gets
      // the very first render right, not just later live changes.
      // `offsetParent === null` is a plain, well-supported way to ask
      // "is this currently hidden" after the fact — same technique
      // webform_ranking.dragdrop.js's own position-numbering already
      // relies on for the same reason.
      var firstInput = groups[name][0];
      if (firstInput && firstInput.offsetParent === null) {
        visible[name] = false;
      }
      toggleRow(firstInput, visible[name] !== false);
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
                // A plain property assignment fires no DOM event of
                // its own, but states.js only re-evaluates a 'value'
                // trigger condition on 'change'/'keyup' (see
                // web/core/misc/states.js, Trigger.states.value) — the
                // row that *wins* the rank gets this for free from the
                // user's real click, but the row that *loses* it is
                // only ever mutated programmatically. Without this,
                // any #states condition elsewhere on the form watching
                // specifically for this item's rank (e.g. "show X when
                // Pizza is ranked 1st") never re-evaluates once Pizza
                // is bumped, and stays stuck showing/hiding whatever
                // was last true. Mirrors the same dispatch
                // webform_ranking.dragdrop.js's sync() already does
                // for its own hidden inputs, for the same reason.
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
          toggleRow(input, e.value);
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
   * Hides/shows an item's entire <tr>, not just its label/radio cells.
   *
   * GitHub issue #59: buildMatrix() applies a conditionally-visible
   * item's own '#states' to each cell's *content* individually (the
   * label div, each radio) — states.js has nothing to attach to on the
   * row itself (Table::preRenderTable()'s row-attributes-to-<tr> merge
   * happens during #pre_render, before #states processing adds
   * 'data-drupal-states' — the same timing constraint buildMatrix()'s
   * own docblock documents for why the label needed a 'container'
   * wrapper). Left alone, a hidden item's <tr>/<td> stays in the DOM:
   * empty-looking, but present and taking up a table row. This is a
   * pure display fix — server-side validation already discards a
   * hidden item's stale selection regardless of what the row looks
   * like (see WebformRankingVisibilityResolver).
   *
   * The native `hidden` IDL attribute, not a CSS class or inline
   * `display`: equivalent to states.js's own plain 'visible'/'invisible'
   * hiding (not the 'slide' variants — this element's own admin UI only
   * ever offers 'visible'/'invisible', see WebformRanking::form()'s
   * condition picker), and one property rather than a stylesheet
   * dependency.
   */
  function toggleRow(input, isVisible) {
    var row = input && input.closest('tr');
    if (row) {
      row.hidden = !isVisible;
    }
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
