// Node unit test for assets/js/newsletter-counter.js's pure
// ptkNlCounterMessage()/ptkNlCounterLimitFor() (round 6, spec item 1).
//
// Run: node pta-knowledge-hub/tests/test-newsletter-counter.mjs

import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire( import.meta.url );
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const { PTK_NL_COUNTER_LIMITS, ptkNlCounterMessage, ptkNlCounterLimitFor } = require(
    path.join( __dirname, '..', 'assets', 'js', 'newsletter-counter.js' )
);

let failed = false;

function ok( cond, label ) {
    if ( cond ) {
        console.log( '  ok  - ' + label );
    } else {
        console.log( '  FAIL- ' + label );
        failed = true;
    }
}

// No limit at all -> always null, regardless of length.
ok( null === ptkNlCounterMessage( 0, 0 ), 'no limit: empty' );
ok( null === ptkNlCounterMessage( 500, 0 ), 'no limit: long text' );
ok( null === ptkNlCounterMessage( 5, undefined ), 'no limit: undefined' );

// Announcement headline, limit 30. 80% = 24.
ok( null === ptkNlCounterMessage( 0, 30 ), 'well under limit: empty' );
ok( null === ptkNlCounterMessage( 23, 30 ), 'just under the 80% threshold' );
ok( '6 characters left' === ptkNlCounterMessage( 24, 30 ), 'exactly at the 80% threshold' );
ok( '1 character left' === ptkNlCounterMessage( 29, 30 ), 'singular: 1 character left' );
ok( '0 characters left' === ptkNlCounterMessage( 30, 30 ), 'exactly at the limit' );
ok( '1 over — it may wrap onto another line' === ptkNlCounterMessage( 31, 30 ), 'singular over' );
ok( '8 over — it may wrap onto another line' === ptkNlCounterMessage( 38, 30 ), 'plural over (matches the spec’s own example)' );

// Footer link label, limit 25. 80% = 20.
ok( null === ptkNlCounterMessage( 19, 25 ), 'footer label: under 80%' );
ok( '4 characters left' === ptkNlCounterMessage( 21, 25 ), 'footer label: at 80%+' );

// Announcement "When" line, ~60.
ok( null === ptkNlCounterMessage( 47, 60 ), 'when line: under 80%' );
ok( '12 characters left' === ptkNlCounterMessage( 48, 60 ), 'when line: at 80%' );

// Announcement text, 90.
ok( null === ptkNlCounterMessage( 71, 90 ), 'announcement text: under 80%' );
ok( '18 characters left' === ptkNlCounterMessage( 72, 90 ), 'announcement text: at 80%' );

// Negative/garbage input never throws and never shows a message.
ok( null === ptkNlCounterMessage( -1, 30 ), 'negative length is ignored' );
ok( null === ptkNlCounterMessage( NaN, 30 ), 'NaN length is ignored' );

// The limits map matches the measured limits from the handoff doc.
ok( 30 === PTK_NL_COUNTER_LIMITS[ 'announcement.headline' ], 'map: announcement headline 30' );
ok( 60 === PTK_NL_COUNTER_LIMITS[ 'announcement.when' ], 'map: announcement when ~60' );
ok( 90 === PTK_NL_COUNTER_LIMITS[ 'announcement.text' ], 'map: announcement text 90' );
ok( 40 === PTK_NL_COUNTER_LIMITS[ 'events.title' ], 'map: event title 40' );
ok( 60 === PTK_NL_COUNTER_LIMITS[ 'events.desc' ], 'map: event detail 60' );
ok( 30 === PTK_NL_COUNTER_LIMITS[ 'featured.headline' ], 'map: top story headline 30' );
ok( 40 === PTK_NL_COUNTER_LIMITS[ 'story_cards.heading' ], 'map: story heading 40' );
ok( 40 === PTK_NL_COUNTER_LIMITS[ 'quick_notes.heading' ], 'map: quick-note headline 40' );
ok( 25 === PTK_NL_COUNTER_LIMITS[ 'footer.label' ], 'map: footer link label 25' );

// Fields with no measured limit stay uncounted.
ok( undefined === ptkNlCounterLimitFor( 'header', 'headline' ), 'header headline has no soft limit' );
ok( undefined === ptkNlCounterLimitFor( 'announcement', 'button_text' ), 'button words have no soft limit' );
ok( 30 === ptkNlCounterLimitFor( 'announcement', 'headline' ), 'lookup matches the map' );

if ( failed ) {
    console.log( 'FAILED' );
    process.exit( 1 );
}
console.log( 'All newsletter-counter tests passed.' );
