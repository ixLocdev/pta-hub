// Newsletter Builder — pure reorder helpers for "Finish editing"'s drag-and-
// drop order list (round 6, spec item 2).
//
// Plain global functions, no ES module syntax (matches newsletter-
// validate.js/newsletter-counter.js's pattern): loads as a plain enqueued
// script in the browser, and `module.exports`s at the bottom so it also
// `require()`s under node for unit testing
// (tests/test-newsletter-reorder.mjs).
//
// Everything DOM-specific (pointer tracking, placeholder insertion,
// auto-scroll, the actual drag) lives in newsletter-builder.js, which reads
// the real row order out of the DOM itself. What's here is the part that's
// the same whether a move came from a mouse/touch drag or an Arrow Up/Down
// key press: given the CURRENT order and a move, what's the NEW order, and
// what should the aria-live region say about it.

/**
 * Move the item at `fromIndex` to `toIndex`, returning a NEW array (the
 * input is never mutated). Both indexes are clamped to the array's bounds,
 * so a caller never has to guard against an out-of-range keyboard move
 * (e.g. Arrow Up on the first row) — the item simply stays put.
 *
 * @param {Array} items Items in their current order.
 * @param {number} fromIndex Index of the item to move.
 * @param {number} toIndex Index to move it to.
 * @return {Array} A new array with the item moved.
 */
function ptkNlMoveItem( items, fromIndex, toIndex ) {
    var list = items.slice();
    var len = list.length;

    if ( ! len ) {
        return list;
    }

    var from = Math.max( 0, Math.min( fromIndex, len - 1 ) );
    var to = Math.max( 0, Math.min( toIndex, len - 1 ) );

    if ( from === to ) {
        return list;
    }

    var moved = list.splice( from, 1 )[ 0 ];
    list.splice( to, 0, moved );
    return list;
}

/**
 * The one-place-up index for a keyboard move, clamped to the top of the
 * list (Arrow Up on the first row is a no-op, not an error).
 *
 * @param {number} index Current index.
 * @return {number}
 */
function ptkNlMoveUpIndex( index ) {
    return Math.max( 0, index - 1 );
}

/**
 * The one-place-down index for a keyboard move, clamped to the bottom of
 * the list.
 *
 * @param {number} index Current index.
 * @param {number} length Number of items in the list.
 * @return {number}
 */
function ptkNlMoveDownIndex( index, length ) {
    return Math.min( Math.max( 0, length - 1 ), index + 1 );
}

/**
 * The aria-live announcement text for a section that just moved, e.g.
 * "Stories moved to position 3 of 6." 1-based, since that's what a
 * volunteer reading it out loud would say.
 *
 * @param {string} label Section label, e.g. "Stories".
 * @param {number} index 0-based new index.
 * @param {number} total Number of movable sections.
 * @return {string}
 */
function ptkNlReorderAnnouncement( label, index, total ) {
    return label + ' moved to position ' + ( index + 1 ) + ' of ' + total + '.';
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = {
        ptkNlMoveItem: ptkNlMoveItem,
        ptkNlMoveUpIndex: ptkNlMoveUpIndex,
        ptkNlMoveDownIndex: ptkNlMoveDownIndex,
        ptkNlReorderAnnouncement: ptkNlReorderAnnouncement
    };
}
