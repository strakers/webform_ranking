/**
 * @file
 * Webform Ranking: drag/drop reorder engine.
 *
 * Deliberately built on the Pointer Events API (pointerdown/move/up),
 * not the native HTML5 Drag and Drop API. HTML5 DnD behaves
 * inconsistently across browsers and has no touch support at all,
 * which works against the "consistent cross-device behavior"
 * requirement this element was built for. Pointer Events already unify
 * mouse, touch, and pen into one event model — one implementation,
 * no per-input-type branching.
 *
 * Accessibility model: the move-up/move-down buttons (server-rendered,
 * always present — see WebformRanking::buildDragDrop()) are the
 * primary, fully-equivalent interaction, not a fallback bolted on
 * after the fact. Pointer-dragging is a convenience layered on top for
 * mouse/touch users who want it. Arrow keys are a shortcut on top of
 * the buttons. All three paths funnel through the same sync()
 * function, so there's no risk of drag-reorder and keyboard-reorder
 * drifting out of sync with each other.
 *
 * Known gap: without JavaScript, there is no mechanism to reorder
 * items at all — there's no plain-HTML way to persist a drag reorder
 * short of a full-page round trip per move. A true no-JS fallback
 * isn't meaningfully achievable for this style; sites with a hard
 * no-JS requirement should use the matrix style instead.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.webformRankingDragdrop = {
    attach: function (context) {
      once('webform-ranking-dragdrop', '.webform-ranking-dragdrop', context).forEach(initDragdrop);
    }
  };

  function initDragdrop(container) {
    var orderInput = container.querySelector('.webform-ranking-dragdrop__order');
    var naInput = container.querySelector('.webform-ranking-dragdrop__na');
    var liveRegion = container.querySelector('.webform-ranking-dragdrop__live-region');

    // Per-item rank echo inputs (see WebformRanking::buildDragDrop()) —
    // the only reason these exist is to give #states a real per-item
    // selector, since 'order' bundles every item into one CSV that
    // #states can't index into. Looked up via data-webform-ranking-
    // rank-for, deliberately a different attribute than the item
    // container's own data-webform-ranking-value (config-time-
    // enforced unique per item, see validateConfigurationForm()'s
    // values_seen check) — a generic query for the latter would
    // otherwise match whichever of the two happens to come first in
    // DOM order. Do NOT write these inputs anywhere else — a second
    // write path is exactly how this channel would end up stale
    // relative to the actually-submitted order/na.
    var rankInputsByValue = {};
    Array.prototype.slice.call(container.querySelectorAll('.webform-ranking-dragdrop__rank')).forEach(function (input) {
      rankInputsByValue[input.getAttribute('data-webform-ranking-rank-for')] = input;
    });

    function allItems() {
      return Array.prototype.slice.call(container.children).filter(function (el) {
        return el.classList && el.classList.contains('webform-ranking-dragdrop__item');
      });
    }

    function isNa(item) {
      return item.getAttribute('data-webform-ranking-na') === 'true';
    }

    // Only counts items states.js currently shows — this is what keeps
    // rank *numbering* (the visible "N of M" position indicator)
    // correct as conditional items (#states) come and go, per the
    // "dynamic ranks" decision: the alternative (fixed ranks regardless
    // of visible count) was rejected earlier as confusing. Note this
    // affects the position indicator and button disabled-state only —
    // the actual submitted order/na values always include every
    // present item; validateWebformRanking() is what authoritatively
    // strips anything not currently visible, server-side.
    function isCurrentlyVisible(item) {
      return item.offsetParent !== null;
    }

    function rankedItems() {
      return allItems().filter(function (item) {
        return !isNa(item);
      });
    }

    function naItems() {
      return allItems().filter(isNa);
    }

    function itemLabel(item) {
      var label = item.querySelector('.webform-ranking-dragdrop__label');
      return label ? label.textContent.trim() : '';
    }

    function announce(message) {
      if (!liveRegion) {
        return;
      }
      liveRegion.textContent = '';
      window.setTimeout(function () {
        liveRegion.textContent = message;
      }, 50);
    }

    /**
     * Renumbers each ranked item's visible position indicator and
     * updates move-button disabled state. N/A'd items get no position
     * and both buttons disabled, since they're not part of the ranking
     * order at all.
     */
    function renumber() {
      var visibleRanked = rankedItems().filter(isCurrentlyVisible);

      visibleRanked.forEach(function (item, index) {
        setPosition(item, Drupal.t('@position of @total', {
          '@position': index + 1,
          '@total': visibleRanked.length
        }));
        setControlsDisabled(item, index === 0, index === visibleRanked.length - 1);
      });

      naItems().forEach(function (item) {
        setPosition(item, '');
        setControlsDisabled(item, true, true);
      });
    }

    function setPosition(item, text) {
      var el = item.querySelector('.webform-ranking-dragdrop__position');
      if (el) {
        el.textContent = text;
      }
    }

    function setControlsDisabled(item, disableUp, disableDown) {
      var up = item.querySelector('.webform-ranking-dragdrop__move-up');
      var down = item.querySelector('.webform-ranking-dragdrop__move-down');
      if (up) {
        up.disabled = disableUp;
      }
      if (down) {
        down.disabled = disableDown;
      }
    }

    /**
     * Writes current DOM order into the hidden order/na inputs and
     * fires a change event. The change event is what lets client-side
     * #states react live to a reorder elsewhere on the form — states.js
     * only watches real field values, it has no idea a plain DOM
     * mutation just happened.
     *
     * Also writes each item's own rank echo input (see
     * rankInputsByValue above) in the same pass, for the same reason —
     * this is the ONLY place any of these inputs are ever written, by
     * design, so order/na and the per-item echoes can never disagree.
     */
    function sync() {
      var order = rankedItems().map(function (item) {
        return item.getAttribute('data-webform-ranking-value');
      });
      var na = naItems().map(function (item) {
        return item.getAttribute('data-webform-ranking-value');
      });

      orderInput.value = order.join(',');
      naInput.value = na.join(',');
      orderInput.dispatchEvent(new Event('change', {bubbles: true}));
      naInput.dispatchEvent(new Event('change', {bubbles: true}));

      order.forEach(function (value, index) {
        setRankInput(value, String(index + 1));
      });
      na.forEach(function (value) {
        setRankInput(value, 'na');
      });

      renumber();
    }

    function setRankInput(value, rank) {
      var input = rankInputsByValue[value];
      if (!input) {
        return;
      }
      if (input.value === rank) {
        return;
      }
      input.value = rank;
      input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function moveItem(item, delta) {
      var ranked = rankedItems().filter(isCurrentlyVisible);
      var index = ranked.indexOf(item);
      if (index === -1) {
        return;
      }
      var newIndex = index + delta;
      if (newIndex < 0 || newIndex >= ranked.length) {
        return;
      }
      var sibling = ranked[newIndex];
      if (delta < 0) {
        container.insertBefore(item, sibling);
      }
      else {
        container.insertBefore(sibling, item);
      }
      item.focus();
      sync();
      announce(Drupal.t('@item moved to position @position of @total', {
        '@item': itemLabel(item),
        '@position': newIndex + 1,
        '@total': ranked.length
      }));
    }

    function setItemNa(item, na) {
      item.setAttribute('data-webform-ranking-na', na ? 'true' : 'false');
      item.classList.toggle('webform-ranking-dragdrop__item--na', na);

      if (na) {
        // Group N/A'd items at the end of the list.
        container.appendChild(item);
      }
      else {
        // Re-enter the ranked list at the end of the current ranking,
        // rather than at an arbitrary position — the user can move it
        // with the buttons/drag from there if they want it elsewhere.
        var ranked = rankedItems().filter(function (candidate) {
          return candidate !== item;
        });
        var lastRanked = ranked[ranked.length - 1];
        if (lastRanked) {
          lastRanked.insertAdjacentElement('afterend', item);
        }
        else {
          container.insertBefore(item, container.firstChild);
        }
      }

      sync();
      announce(na
        ? Drupal.t('@item marked as @na_label', {'@item': itemLabel(item), '@na_label': container.getAttribute('data-na-label') || Drupal.t('N/A')})
        : Drupal.t('@item returned to ranking', {'@item': itemLabel(item)}));
    }

    allItems().forEach(function (item) {
      var upBtn = item.querySelector('.webform-ranking-dragdrop__move-up');
      var downBtn = item.querySelector('.webform-ranking-dragdrop__move-down');
      var naCheckbox = item.querySelector('.webform-ranking-dragdrop__na-checkbox');

      if (upBtn) {
        upBtn.addEventListener('click', function () {
          moveItem(item, -1);
        });
      }
      if (downBtn) {
        downBtn.addEventListener('click', function () {
          moveItem(item, 1);
        });
      }
      if (naCheckbox) {
        naCheckbox.addEventListener('change', function () {
          setItemNa(item, naCheckbox.checked);
        });
      }

      // Arrow-key reordering: a shortcut on top of the buttons, not a
      // replacement for them (focus must be on the item itself, not a
      // nested control, so this never fights with normal button/
      // checkbox keyboard activation).
      item.addEventListener('keydown', function (event) {
        if (event.target !== item) {
          return;
        }
        if (event.key === 'ArrowUp') {
          event.preventDefault();
          moveItem(item, -1);
        }
        else if (event.key === 'ArrowDown') {
          event.preventDefault();
          moveItem(item, 1);
        }
      });

      // Pointer Events drag reordering. Ignores drags starting on a
      // nested control so a button/checkbox click is never hijacked
      // into a drag gesture, and ignores N/A'd items entirely (they're
      // not part of the ranked order to drag within).
      var dragging = false;
      var pointerId = null;

      item.addEventListener('pointerdown', function (event) {
        if (event.target.closest('button, input, label')) {
          return;
        }
        if (isNa(item)) {
          return;
        }
        dragging = true;
        pointerId = event.pointerId;
        item.setPointerCapture(pointerId);
        item.classList.add('webform-ranking-dragdrop__item--dragging');
      });

      item.addEventListener('pointermove', function (event) {
        if (!dragging || event.pointerId !== pointerId) {
          return;
        }
        var target = document.elementFromPoint(event.clientX, event.clientY);
        var targetItem = target ? target.closest('.webform-ranking-dragdrop__item') : null;
        if (!targetItem || targetItem === item || !container.contains(targetItem) || isNa(targetItem)) {
          return;
        }
        var rect = targetItem.getBoundingClientRect();
        var before = event.clientY < (rect.top + rect.height / 2);
        container.insertBefore(item, before ? targetItem : targetItem.nextSibling);
      });

      function endDrag(event) {
        if (!dragging || event.pointerId !== pointerId) {
          return;
        }
        dragging = false;
        item.classList.remove('webform-ranking-dragdrop__item--dragging');
        sync();
        var ranked = rankedItems().filter(isCurrentlyVisible);
        announce(Drupal.t('@item moved to position @position of @total', {
          '@item': itemLabel(item),
          '@position': ranked.indexOf(item) + 1,
          '@total': ranked.length
        }));
      }

      item.addEventListener('pointerup', endDrag);
      item.addEventListener('pointercancel', endDrag);
    });

    // Re-renumber whenever a states.js visibility change happens
    // anywhere in the document, so the dynamic-rank position indicator
    // stays correct as conditional items show/hide, without requiring
    // a reorder to trigger the recompute.
    //
    // Verification note: Drupal core's states.js triggers 'state:visible'
    // as a jQuery event, not a native DOM CustomEvent — whether it's
    // observable via plain addEventListener depends on the jQuery
    // version's event-bridging behavior and isn't confirmed here.
    // Falls back to jQuery's own event binding when jQuery is present,
    // which is the more reliable path against current Drupal core.
    if (window.jQuery) {
      window.jQuery(document).on('state:visible', function () {
        renumber();
      });
    }
    else {
      document.addEventListener('state:visible', function () {
        renumber();
      });
    }

    // Initial sync: makes hidden inputs match server-rendered default
    // order/N/A state, and populates position indicators on load.
    sync();
  }

})(Drupal, once);
