// Node unit test for assets/js/newsletter-relabel.js's pure ptkRelabelForDate().
//
// Asserts the SAME cases as tests/test-newsletter-dates.php (the PHP port
// this file mirrors), including the Monday today=2026-06-22 cases and the
// Sunday today=2026-06-28 cases, so the JS relabel logic is proven to agree
// with PTK_Newsletter_Data::relabel_for_date() bucket-for-bucket.
//
// Run: node pta-knowledge-hub/tests/test-relabel-js.mjs

import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire( import.meta.url );
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const { ptkRelabelForDate } = require( path.join( __dirname, '..', 'assets', 'js', 'newsletter-relabel.js' ) );

let failed = false;

function ok( cond, label ) {
    if ( cond ) {
        console.log( '  ok  - ' + label );
    } else {
        console.log( '  FAIL- ' + label );
        failed = true;
    }
}

// today = 2026-06-22 is a Monday.
const today = '2026-06-22';
ok( ptkRelabelForDate( '2026-06-20', today ) === 'past', 'past date' );
ok( ptkRelabelForDate( '2026-06-25', today ) === 'this-week', 'this week' );
ok( ptkRelabelForDate( '2026-06-30', today ) === 'next-week', 'next week' );
ok( ptkRelabelForDate( '2026-07-20', today ) === 'upcoming', 'further out' );

// Non-Monday today exercises the Monday-offset math. 2026-06-28 is a
// Sunday, so its week is Mon 2026-06-22 .. Sun 2026-06-28.
const sunday = '2026-06-28';
ok( ptkRelabelForDate( '2026-06-27', sunday ) === 'past', 'sunday today: same-week earlier day is past' );
ok( ptkRelabelForDate( '2026-06-28', sunday ) === 'this-week', 'sunday today: itself is this-week' );
ok( ptkRelabelForDate( '2026-06-29', sunday ) === 'next-week', 'sunday today: next monday is next-week' );

// Mid-week today (2026-06-24 is a Wednesday), week Mon 06-22 .. Sun 06-28.
const wednesday = '2026-06-24';
ok( ptkRelabelForDate( '2026-06-28', wednesday ) === 'this-week', 'wednesday today: sunday end of week is this-week' );
ok( ptkRelabelForDate( '2026-06-29', wednesday ) === 'next-week', 'wednesday today: next monday is next-week' );

// Extra: malformed / non-string input degrades to 'upcoming', matching the
// PHP parse_date() -> null -> 'upcoming' fallback.
ok( ptkRelabelForDate( '', today ) === 'upcoming', 'blank event date -> upcoming' );
ok( ptkRelabelForDate( 'not-a-date', today ) === 'upcoming', 'garbage event date -> upcoming' );
ok( ptkRelabelForDate( '2026-06-25', 'garbage' ) === 'upcoming', 'garbage today -> upcoming' );

if ( failed ) {
    console.log( 'FAILED' );
    process.exit( 1 );
} else {
    console.log( 'PASSED' );
    process.exit( 0 );
}
