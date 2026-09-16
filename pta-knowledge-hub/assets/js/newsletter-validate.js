// Newsletter Builder — link/email validators (round 3.1, spec item 7).
//
// Plain global functions, no ES module syntax (matches focal-point.js's
// pattern): loads as a plain enqueued script in the browser, and
// `module.exports`s at the bottom so it also `require()`s under node for
// unit testing (tests/test-newsletter-validate.mjs).
//
// These mirror -- deliberately loosely, client-side is a first pass and the
// server (PTK_Newsletter_Data::sanitize_link_url() / esc_url_raw()) is the
// real gate -- what the save handler actually accepts, so a value the JS
// waves through is never rejected silently by the server, and a value the
// JS flags really wouldn't save the way the volunteer expects.

/** A web address starting with http:// or https://, with something after it. */
function ptkNlLooksLikeUrl( value ) {
    return /^https?:\/\/\S+\.\S/i.test( String( value || '' ).trim() );
}

/** A bare email address, e.g. "leslie@example.org" (no scheme). */
function ptkNlLooksLikeEmail( value ) {
    return /^[^@\s:/]+@[^@\s/]+\.[^@\s/]+$/.test( String( value || '' ).trim() );
}

/**
 * Fields that accept EITHER a web address or a bare email (the server
 * turns the email into a mailto: link) -- announcement.button_url and
 * quick_notes.link_url, via PTK_Newsletter_Data::sanitize_link_url().
 */
function ptkNlLooksLikeLinkOrEmail( value ) {
    var v = String( value || '' ).trim();
    return ptkNlLooksLikeUrl( v ) || ptkNlLooksLikeEmail( v ) || /^mailto:/i.test( v );
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = {
        ptkNlLooksLikeUrl: ptkNlLooksLikeUrl,
        ptkNlLooksLikeEmail: ptkNlLooksLikeEmail,
        ptkNlLooksLikeLinkOrEmail: ptkNlLooksLikeLinkOrEmail
    };
}
