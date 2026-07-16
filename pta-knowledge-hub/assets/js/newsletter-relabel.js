/**
 * Newsletter reader's-date relabel script.
 *
 * The server renders each event pill with a label computed against the
 * newsletter's issue date (see PTK_Newsletter_Renderer::render_events() in
 * includes/class-newsletter-renderer.php, driven by
 * PTK_Newsletter_Data::relabel_for_date() in includes/class-newsletter-data.php).
 * That label goes stale the moment a reader opens the newsletter on a later
 * day. This script recomputes the label client-side, against the reader's
 * own local "today", for every `[data-event-date]` element on the page.
 *
 * ptkRelabelForDate() is a byte-for-byte port of
 * PTK_Newsletter_Data::relabel_for_date() -- keep the two in sync. In
 * particular:
 *   - The calendar week is Monday-Sunday.
 *   - The PAST check runs BEFORE the week-window check: an event earlier
 *     than "today" but in the same Monday-Sunday week is 'past', not
 *     'this-week'. (See the PHP method's docblock for the same note.)
 *
 * The DOM-scanning approach (querySelectorAll('[data-event-date]'), set
 * textContent) mirrors the inline helper at the bottom of the source
 * design, /Users/lucas/apps/NEPTANewsletter/newsletter-038-week-of-6-22-26.html
 * -- but the label logic itself is NOT reused from that file; it's replaced
 * with the Monday-based PHP port described above so client and server
 * always agree.
 */

/**
 * Parse a 'YYYY-MM-DD' string into a UTC epoch-ms timestamp (midnight UTC),
 * or null if it isn't a real calendar date. Using Date.UTC (rather than the
 * local-time Date constructor) keeps day-math free of DST edge cases.
 *
 * @param {*} value
 * @return {number|null}
 */
function ptkParseISODateUTC( value ) {
    if ( typeof value !== 'string' ) {
        return null;
    }

    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value.trim() );
    if ( ! m ) {
        return null;
    }

    var year  = parseInt( m[ 1 ], 10 );
    var month = parseInt( m[ 2 ], 10 ); // 1-12
    var day   = parseInt( m[ 3 ], 10 );

    var ms = Date.UTC( year, month - 1, day );
    var dt = new Date( ms );

    // Reject overflowed dates (e.g. 2026-02-30 rolling into March).
    if ( dt.getUTCFullYear() !== year || dt.getUTCMonth() !== month - 1 || dt.getUTCDate() !== day ) {
        return null;
    }

    return ms;
}

var PTK_DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Classify an event date relative to today into a display bucket, with the
 * calendar week starting on Monday. Pure function -- no DOM access.
 *
 * Mirrors PTK_Newsletter_Data::relabel_for_date() in
 * includes/class-newsletter-data.php exactly; see that method's docblock
 * for the past-before-week-window ordering rationale.
 *
 * @param {string} eventISO 'YYYY-MM-DD'
 * @param {string} todayISO 'YYYY-MM-DD'
 * @return {'past'|'this-week'|'next-week'|'upcoming'}
 */
function ptkRelabelForDate( eventISO, todayISO ) {
    var eventMs = ptkParseISODateUTC( eventISO );
    var todayMs = ptkParseISODateUTC( todayISO );

    if ( eventMs === null || todayMs === null ) {
        return 'upcoming';
    }

    // The "past" check runs BEFORE the week-window check on purpose: an
    // event earlier than today but in the same Monday-Sunday week is
    // labeled 'past', not 'this-week'. Keep this ordering in sync with the
    // PHP implementation.
    if ( eventMs < todayMs ) {
        return 'past';
    }

    // ISO-8601 day-of-week: Monday = 1 ... Sunday = 7.
    // Date#getUTCDay() returns 0=Sunday..6=Saturday; remap Sunday to 7.
    var jsDow  = new Date( todayMs ).getUTCDay();
    var isoDow = ( 0 === jsDow ) ? 7 : jsDow;

    var thisMondayMs = todayMs - ( isoDow - 1 ) * PTK_DAY_MS;
    var thisSundayMs = thisMondayMs + 6 * PTK_DAY_MS;

    if ( eventMs >= thisMondayMs && eventMs <= thisSundayMs ) {
        return 'this-week';
    }

    var nextMondayMs = thisMondayMs + 7 * PTK_DAY_MS;
    var nextSundayMs = thisSundayMs + 7 * PTK_DAY_MS;

    if ( eventMs >= nextMondayMs && eventMs <= nextSundayMs ) {
        return 'next-week';
    }

    return 'upcoming';
}

/**
 * Display label for each relabel bucket. Mirrors
 * PTK_Newsletter_Renderer::EVENT_LABELS.
 */
var PTK_EVENT_LABELS = {
    'past': 'Past',
    'this-week': 'This week',
    'next-week': 'Next week',
    'upcoming': 'Upcoming'
};

/**
 * Today's date as a local 'YYYY-MM-DD' string (the reader's own clock/
 * timezone, not UTC -- a reader relabeling "today" should use their own
 * calendar day).
 *
 * @return {string}
 */
function ptkTodayISOLocal() {
    var now = new Date();
    var y = now.getFullYear();
    var m = String( now.getMonth() + 1 ).padStart( 2, '0' );
    var d = String( now.getDate() ).padStart( 2, '0' );
    return y + '-' + m + '-' + d;
}

/**
 * Find every [data-event-date] element on the page and set its text to the
 * label for that date relative to the reader's local "today".
 */
function ptkInitRelabel() {
    var today = ptkTodayISOLocal();
    var pills = document.querySelectorAll( '[data-event-date]' );

    pills.forEach( function( el ) {
        var bucket = ptkRelabelForDate( el.getAttribute( 'data-event-date' ), today );
        var label  = PTK_EVENT_LABELS[ bucket ] || PTK_EVENT_LABELS.upcoming;
        el.textContent = label;
    } );
}

// Guarded so this file can be required under node (for unit testing the
// pure function) without throwing on a missing `document`.
if ( typeof document !== 'undefined' ) {
    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', ptkInitRelabel );
    } else {
        ptkInitRelabel();
    }
}

// Export the pure function for node-based unit testing.
if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = { ptkRelabelForDate: ptkRelabelForDate };
}
