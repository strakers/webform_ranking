<?php

namespace Drupal\webform_ranking;

/**
 * Converts between UI-specific raw input and the canonical ranking value.
 *
 * Canonical shape:
 * @code
 * [
 *   'values' => ['item_a', 'item_c'],  // ordered, position = rank - 1.
 *   'na'     => ['item_b'],            // unordered set of opted-out items.
 * ]
 * @endcode
 *
 * Every consumer of a ranking value (validation, #states selectors,
 * results/export formatting) should go through this class rather than
 * reading matrix or dragdrop markup directly. This is what keeps "how
 * the value was collected" decoupled from "what the value means" — the
 * matrix and dragdrop UIs are just two different producers/renderers of
 * the same canonical data.
 *
 * Pure static functions, no dependency injection needed: nothing here
 * touches the database, config, or services.
 */
class WebformRankingConverter {

  /**
   * Converts raw matrix (radios) input into the canonical shape.
   *
   * Raw shape: ['item_a' => '2', 'item_b' => 'na', 'item_c' => '1'].
   * An item with no selection yet (mid-edit, or optional + left blank)
   * is simply absent from $raw or has an empty string value.
   *
   * Malformed/out-of-range rank values are dropped here, not rejected —
   * this converter's job is normalization only. Rejecting bad data is
   * validateWebformRanking()'s job, which runs against this method's
   * *output*, so a forged rank of "99" ends up correctly reported as
   * "item never got a valid rank" rather than silently accepted.
   *
   * @param array $raw
   *   Raw submitted matrix input, item value => rank string ('1', '2',
   *   ..., 'na', or '').
   *
   * @return array
   *   Canonical ['values' => [...], 'na' => [...]] array.
   */
  public static function matrixToCanonical(array $raw): array {
    $ranked = [];
    $na = [];

    foreach ($raw as $item_value => $rank) {
      if ($rank === '' || $rank === NULL) {
        continue;
      }
      if ($rank === 'na') {
        $na[] = $item_value;
        continue;
      }
      if (is_numeric($rank) && (int) $rank > 0) {
        // Keyed by rank so duplicate ranks (forged/tampered input)
        // collide and the later one silently wins here — validation
        // catches the duplicate independently by comparing counts, so
        // this collision is fine to leave unresolved at this layer.
        $ranked[(int) $rank] = $item_value;
      }
    }

    ksort($ranked);
    return [
      'values' => array_values($ranked),
      'na' => $na,
    ];
  }

  /**
   * Converts a canonical value back into matrix (radios) default values.
   *
   * Used when populating the form for an existing submission (edit).
   *
   * @return array
   *   Item value => rank string, suitable for setting each row's radios
   *   #default_value. Items with no rank/na are simply absent.
   */
  public static function canonicalToMatrix(array $canonical): array {
    $raw = [];
    foreach ($canonical['values'] ?? [] as $delta => $item_value) {
      $raw[$item_value] = (string) ($delta + 1);
    }
    foreach ($canonical['na'] ?? [] as $item_value) {
      $raw[$item_value] = 'na';
    }
    return $raw;
  }

  /**
   * Converts raw drag/drop input into the canonical shape.
   *
   * Raw shape: two comma-separated hidden input strings, kept in sync
   * client-side on every reorder (see element.dragdrop library):
   * - 'order': ordered list of ranked item values.
   * - 'na': unordered list of opted-out item values.
   *
   * @param array $raw
   *   ['order' => 'item_a,item_c', 'na' => 'item_b'].
   */
  public static function dragdropToCanonical(array $raw): array {
    $parse = function ($string) {
      $string = trim((string) ($string ?? ''));
      return $string === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $string)), fn($v) => $v !== ''));
    };

    return [
      'values' => $parse($raw['order'] ?? ''),
      'na' => $parse($raw['na'] ?? ''),
    ];
  }

  /**
   * Converts a canonical value into drag/drop hidden-input defaults.
   */
  public static function canonicalToDragdrop(array $canonical): array {
    return [
      'order' => implode(',', $canonical['values'] ?? []),
      'na' => implode(',', $canonical['na'] ?? []),
    ];
  }

  /**
   * Computes the set of item values present in either values or na.
   *
   * Convenience for validation: "which items did the user account for
   * at all" regardless of ranked-vs-N/A.
   */
  public static function accountedFor(array $canonical): array {
    return array_merge($canonical['values'] ?? [], $canonical['na'] ?? []);
  }

  /**
   * Orders configured items by their stored rank, for results display.
   *
   * Ranked items first (in rank order), then N/A items, then items
   * never accounted for at all (e.g. conditionally hidden when this
   * submission was made, or added to configuration afterward) — those
   * last, in their originally configured order, since they have no
   * rank to sort by. Used by
   * WebformRanking::formatHtmlItem()/formatTextItem() (the plugin) so
   * results read "how was this ranked" rather than mirroring
   * configuration order; each item is still individually labeled in
   * that display, so reordering loses no information.
   *
   * @param array $items
   *   The element's configured items (value/label/states each).
   * @param array $value
   *   The submission's stored flat item-value => rank map (matrixToCanonical()'s
   *   input shape).
   *
   * @return array
   *   $items, reordered.
   */
  public static function orderByRank(array $items, array $value): array {
    $canonical = static::matrixToCanonical($value);
    $items_by_value = array_column($items, NULL, 'value');

    $ordered = [];
    foreach (array_merge($canonical['values'], $canonical['na']) as $item_value) {
      if (isset($items_by_value[$item_value])) {
        $ordered[] = $items_by_value[$item_value];
        unset($items_by_value[$item_value]);
      }
    }
    // Anything left is never accounted for — append in configured order.
    foreach ($items as $item) {
      if (isset($items_by_value[$item['value']])) {
        $ordered[] = $item;
      }
    }
    return $ordered;
  }

}
