// Node unit test for assets/js/newsletter-validate.js's pure validators
// (round 3.1, spec item 7 -- visible client-side validation).
//
// Run: node pta-knowledge-hub/tests/test-newsletter-validate.mjs

import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire( import.meta.url );
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const {
    ptkNlLooksLikeUrl,
    ptkNlLooksLikeEmail,
    ptkNlLooksLikeLinkOrEmail
} = require( path.join( __dirname, '..', 'assets', 'js', 'newsletter-validate.js' ) );

let failed = false;

function ok( cond, label ) {
    if ( cond ) {
        console.log( '  ok  - ' + label );
    } else {
        console.log( '  FAIL- ' + label );
        failed = true;
    }
}

// ptkNlLooksLikeUrl
ok( ptkNlLooksLikeUrl( 'https://northeastpta.org/volunteer' ) === true, 'looksLikeUrl: https accepted' );
ok( ptkNlLooksLikeUrl( 'http://example.org' ) === true, 'looksLikeUrl: http accepted' );
ok( ptkNlLooksLikeUrl( 'www.example.org' ) === false, 'looksLikeUrl: no scheme rejected' );
ok( ptkNlLooksLikeUrl( 'leslie@example.org' ) === false, 'looksLikeUrl: bare email rejected' );
ok( ptkNlLooksLikeUrl( 'https://' ) === false, 'looksLikeUrl: scheme with nothing after it rejected' );
ok( ptkNlLooksLikeUrl( '' ) === false, 'looksLikeUrl: empty rejected' );
ok( ptkNlLooksLikeUrl( '  https://example.org  ' ) === true, 'looksLikeUrl: surrounding whitespace trimmed' );

// ptkNlLooksLikeEmail
ok( ptkNlLooksLikeEmail( 'leslie@example.org' ) === true, 'looksLikeEmail: plain address accepted' );
ok( ptkNlLooksLikeEmail( 'https://example.org' ) === false, 'looksLikeEmail: a url is not an email' );
ok( ptkNlLooksLikeEmail( 'not-an-email' ) === false, 'looksLikeEmail: no @ rejected' );
ok( ptkNlLooksLikeEmail( 'a@b' ) === false, 'looksLikeEmail: no dot in the domain rejected' );

// ptkNlLooksLikeLinkOrEmail (announcement.button_url / quick_notes.link_url)
ok( ptkNlLooksLikeLinkOrEmail( 'https://example.org' ) === true, 'looksLikeLinkOrEmail: url accepted' );
ok( ptkNlLooksLikeLinkOrEmail( 'leslie@example.org' ) === true, 'looksLikeLinkOrEmail: bare email accepted' );
ok( ptkNlLooksLikeLinkOrEmail( 'mailto:leslie@example.org' ) === true, 'looksLikeLinkOrEmail: mailto: accepted' );
ok( ptkNlLooksLikeLinkOrEmail( 'just some text' ) === false, 'looksLikeLinkOrEmail: plain text rejected' );
ok( ptkNlLooksLikeLinkOrEmail( '' ) === false, 'looksLikeLinkOrEmail: empty rejected (callers skip empty themselves for optional fields)' );

if ( failed ) {
    console.log( 'FAILED' );
    process.exit( 1 );
}
console.log( 'PASSED' );
