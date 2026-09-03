/**
 * @file
 * Webform Ranking: drag/drop reorder engine.
 *
 * Built on the Pointer Events API, not HTML5 Drag and Drop (inconsistent
 * cross-browser, no touch support). The move-up/move-down buttons are
 * the primary, fully-equivalent interaction (not a fallback); pointer
 * drag and arrow keys are conveniences layered on the same sync() path.
 * No true no-JS fallback exists for this style — an accepted gap; the
 * matrix style is the no-JS-safe alternative. See
 * docs/adr/0013-dragdrop-pointer-events-and-accessibility-model.md.
 */

(function (Drupal, once, $) {
  'use strict';

  Drupal.behaviors.webformRankingDragdrop = {
    attach: function (context) {
      once('webform-ranking-dragdrop', '.webform-ranking-dragdrop', context).forEach(initDragdrop);
    }
  };

  function initDragdrop(container) {
    // 'container' (role="list") holds only listitem elements as direct
    // children — the hidden order/na/rank inputs and live-region live
    // in the wrapper one level up instead (see buildDragDrop()).
    var wrapper = container.parentElement;
    var orderInput = wrapper.querySelector('.webform-ranking-dragdrop__order');
    var naInput = wrapper.querySelector('.webform-ranking-dragdrop__na');
    var liveRegion = wrapper.querySelector('.webform-ranking-dragdrop__live-region');

    // Per-item rank echo inputs — non-authoritative, exist only so
    // #states has a real per-item selector. Do NOT write these inputs
    // anywhere else — see docs/adr/0008-dragdrop-rank-echo-channel.md.
    var rankInputsByValue = {};
    Array.prototype.slice.call(wrapper.querySelectorAll('.webform-ranking-dragdrop__rank')).forEach(function (input) {
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

    // Value -> known current visibility, updated live from each item's
    // 'state:visible' event rather than re-derived from offsetParent at
    // call time — that event fires before states.js's own DOM-hiding
    // effect actually completes. See GitHub issue #108 and
    // docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-state.md.
    var knownVisible = {};

    function isCurrentlyVisible(item) {
      // Only trust `offsetParent`/`knownVisible` for an item with its
      // own `#states` (data-drupal-states) — otherwise it can only mean
      // an ancestor is hidden, not this item. See ADR-0022 (GitHub #123).
      if (!item.hasAttribute('data-drupal-states')) {
        return true;
      }
      var value = item.getAttribute('data-webform-ranking-value');
      if (Object.prototype.hasOwnProperty.call(knownVisible, value)) {
        return knownVisible[value];
      }
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
     * Writes current DOM order into the hidden order/na inputs (firing
     * 'change' so #states reacts — states.js only watches real field
     * values) and each rank echo input, in the same pass. The ONLY
     * place any of these inputs are ever written — see
     * docs/adr/0008-dragdrop-rank-echo-channel.md. A hidden item is
     * excluded and its own echo blanked, not marked 'na' — see
     * docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-state.md.
     */
    function sync() {
      var visibleRanked = rankedItems().filter(isCurrentlyVisible);
      var visibleNa = naItems().filter(isCurrentlyVisible);
      var order = visibleRanked.map(function (item) {
        return item.getAttribute('data-webform-ranking-value');
      });
      var na = visibleNa.map(function (item) {
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
      allItems().filter(function (item) {
        return !isCurrentlyVisible(item);
      }).forEach(function (item) {
        setRankInput(item.getAttribute('data-webform-ranking-value'), '');
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

    /**
     * Moves an item up or down among currently-ranked, visible items.
     *
     * @param {HTMLElement} item
     *   The item to move.
     * @param {number} delta
     *   -1 to move up, 1 to move down.
     * @param {HTMLElement} [focusTarget]
     *   What to focus after the move — defaults to the item itself
     *   (correct for arrow keys). Button clicks pass the button
     *   explicitly: unconditionally refocusing the item stole focus off
     *   it, breaking repeated Enter presses after the first move.
     */
    function moveItem(item, delta, focusTarget) {
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
      (focusTarget || item).focus();
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

      // Keeps the checkbox itself in sync — needed once this function
      // has a programmatic caller (the reveal-time reset below) whose
      // checkbox.checked doesn't already match. Deferred: Webform
      // core's own webform.states.js restores a revealed input's
      // PRE-HIDE value via its own document-level 'state:visible'
      // handler, which runs synchronously right after this one and
      // would otherwise immediately re-check a box this function just
      // unchecked. See GitHub issue #108 and
      // docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-state.md.
      var naCheckbox = item.querySelector('.webform-ranking-dragdrop__na-checkbox');
      if (naCheckbox) {
        window.setTimeout(function () {
          naCheckbox.checked = na;
        }, 0);
      }

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
      // Same `data-drupal-states` guard as isCurrentlyVisible() — leave
      // a condition-less item unseeded so its early return handles it.
      if (item.hasAttribute('data-drupal-states')) {
        knownVisible[item.getAttribute('data-webform-ranking-value')] = item.offsetParent !== null;
      }
    });

    allItems().forEach(function (item) {
      var upBtn = item.querySelector('.webform-ranking-dragdrop__move-up');
      var downBtn = item.querySelector('.webform-ranking-dragdrop__move-down');
      var naCheckbox = item.querySelector('.webform-ranking-dragdrop__na-checkbox');

      if (upBtn) {
        upBtn.addEventListener('click', function () {
          moveItem(item, -1, upBtn);
        });
      }
      if (downBtn) {
        downBtn.addEventListener('click', function () {
          moveItem(item, 1, downBtn);
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

        // Reparenting mid-gesture silently breaks pointer capture in
        // Chromium (no error/event — later pointermove events simply
        // stop arriving), confirmed via a FunctionalJavascript test.
        // Re-capturing immediately keeps the rest of the drag alive.
        item.setPointerCapture(pointerId);
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

      // Reacts to this item's own conditional visibility (jQuery event
      // — plain addEventListener can't observe it). See GitHub issue
      // #108 and
      // docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-state.md.
      $(item).on('state:visible', function (e) {
        knownVisible[item.getAttribute('data-webform-ranking-value')] = e.value;
        if (e.value) {
          setItemNa(item, false);
        }
        else {
          sync();
        }
      });
    });

    // Re-syncs on *this element's own* visibility (not a per-item one)
    // — nothing else re-ran sync() when only the element's own
    // condition changed. Deferred a tick to win the same core
    // backup/restore race as setItemNa()'s checkbox sync above. See
    // ADR-0020/ADR-0022 (GitHub #123).
    var elementWrapper = container.closest('.js-webform-ranking');
    if (elementWrapper) {
      $(elementWrapper).on('state:visible', function (e) {
        if (e.value) {
          window.setTimeout(sync, 0);
        }
      });
    }

    // Initial sync: makes hidden inputs match server-rendered default
    // order/N/A state, and populates position indicators on load.
    sync();
  }

})(Drupal, once, jQuery);
