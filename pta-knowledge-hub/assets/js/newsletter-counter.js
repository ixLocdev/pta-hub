// Newsletter Builder — soft length counters (round 6, spec item 1).
//
// Plain global functions/values, no ES module syntax (matches
// newsletter-validate.js's pattern): loads as a plain enqueued script in the
// browser, and `module.exports`s at the bottom so it also `require()`s under
// node for unit testing (tests/test-newsletter-counter.mjs).
//
// These are NEVER hard limits — nothing here blocks typing, saving or
// publishing, and no `maxlength` attribute is ever added. The message is
// purely informational: a quiet nudge once a field is comfortably close to
// (or past) the length that reads best at the size the newsletter renders
// it, taken from real measurements against the design (see the handoff
// doc's Round 6 section).

/**
 * One soft limit per section type + field name, matching the `data-type` on
 * the section (`.ptk-nl-block`) and the `data-field` on the input/textarea.
 * Row-level fields (events/story_cards/quick_notes/footer links) use the
 * SAME section type + field name as their static markup, since a cloned
 * row's fields carry the identical `data-field` values.
 *
 * Fields with no entry here never show a counter — most fields (button
 * words, link addresses, the greeting, a story's body) have no measured
 * limit and are left alone on purpose.
 */
var PTK_NL_COUNTER_LIMITS = {
    'announcement.headline': 30,
    'announcement.when': 60,
    'announcement.text': 90,
    'events.title': 40,
    'events.desc': 60,
    'featured.headline': 30,
    'story_cards.heading': 40,
    'quick_notes.heading': 40,
    'footer.label': 25
};

/**
 * The counter message for a field's current length against its limit, or
 * null when nothing should be shown (comfortably under the limit, or no
 * limit at all).
 *
 * - Under ~80% of the limit: null (no counter).
 * - From ~80% up to the limit: "N characters left" (or "1 character left").
 * - Over the limit: "N over — it may wrap onto another line" — a plain,
 *   warm nudge, never phrased as an error.
 *
 * @param {number} length Current character count.
 * @param {number} limit  The soft limit, e.g. from PTK_NL_COUNTER_LIMITS.
 * @return {?string}
 */
function ptkNlCounterMessage( length, limit ) {
    length = parseInt( length, 10 );
    limit = parseInt( limit, 10 );

    if ( ! limit || limit <= 0 || isNaN( length ) || length < 0 ) {
        return null;
    }

    var remaining = limit - length;

    if ( remaining < 0 ) {
        var over = -remaining;
        return over + ' over — it may wrap onto another line';
    }

    // Comfortably under: say nothing. Math.ceil so a limit like 25 starts
    // the note at 20 (80% exactly), not 19.something rounding down.
    if ( length < Math.ceil( limit * 0.8 ) ) {
        return null;
    }

    return remaining + ( 1 === remaining ? ' character left' : ' characters left' );
}

/**
 * Look up the soft limit for a section type + field name, or undefined if
 * this field has none.
 *
 * @param {string} sectionType e.g. "announcement", "events".
 * @param {string} field       The field's data-field value, e.g. "headline".
 * @return {number|undefined}
 */
function ptkNlCounterLimitFor( sectionType, field ) {
    return PTK_NL_COUNTER_LIMITS[ sectionType + '.' + field ];
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = {
        PTK_NL_COUNTER_LIMITS: PTK_NL_COUNTER_LIMITS,
        ptkNlCounterMessage: ptkNlCounterMessage,
        ptkNlCounterLimitFor: ptkNlCounterLimitFor
    };
}
