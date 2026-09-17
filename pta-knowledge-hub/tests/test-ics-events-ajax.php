<?php
require __DIR__ . '/bootstrap.php';
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
require __DIR__ . '/../includes/class-newsletter-data.php';
require __DIR__ . '/../includes/class-ics-reader.php';
require __DIR__ . '/../includes/class-share-color.php';
require __DIR__ . '/../includes/class-share-settings.php';
require __DIR__ . '/../includes/class-ics-events-ajax.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Ics_Events_Ajax';

// ---------------------------------------------------------------------
// range_for() -- issue date Wednesday 2026-09-16 -> issue week Monday 2026-09-14.
// ---------------------------------------------------------------------
$issue = '2026-09-16';

$r = $t::range_for( 'this_week', $issue );
ptk_test_ok( '2026-09-14' === $r['start'] && '2026-09-20' === $r['end'], '"This week" is the issue week Monday-Sunday, not today\'s week' );

$r = $t::range_for( 'next_week', $issue );
ptk_test_ok( '2026-09-21' === $r['start'] && '2026-09-27' === $r['end'], '"Next week" is the following Monday-Sunday' );

$r = $t::range_for( 'next_2_weeks', $issue );
ptk_test_ok( '2026-09-14' === $r['start'] && '2026-09-27' === $r['end'], '"Next 2 weeks" spans this issue week plus the next one' );

$r = $t::range_for( 'this_month', $issue );
ptk_test_ok( '2026-09-14' === $r['start'] && '2026-09-30' === $r['end'], '"This month" runs from the issue week to the end of its month (no past dates)' );
$r = $t::range_for( 'this_month', '2026-09-29' ); // week of Mon Sep 28, mostly in October
ptk_test_ok( '2026-09-28' === $r['start'] && '2026-10-31' === $r['end'], '"This month" for a week straddling two months uses the month most of the week is in' );

$r = $t::range_for( 'next_3_months', $issue );
ptk_test_ok( '2026-09-14' === $r['start'] && '2026-12-13' === $r['end'], '"Next 3 months" runs from the issue week Monday through the day before the same date 3 months later' );

// A Sunday issue date belongs to the NEXT week (matches issue_week_monday's own rule).
$r = $t::range_for( 'this_week', '2026-09-13' ); // a Sunday
ptk_test_ok( '2026-09-14' === $r['start'], 'a Sunday issue date rolls to the following Monday, like issue_week_monday() everywhere else' );

// Empty issue date falls back to today rather than erroring.
$r = $t::range_for( 'this_week', '' );
ptk_test_ok( is_array( $r ) && isset( $r['start'], $r['end'] ), 'an empty issue date still returns a usable range' );

// Unknown range key falls back to "this week" rather than failing.
$r = $t::range_for( 'nonsense', $issue );
ptk_test_ok( '2026-09-14' === $r['start'] && '2026-09-20' === $r['end'], 'an unrecognized range key defaults to "this week"' );

// ---------------------------------------------------------------------
// classify_title() -- the §4.1 false-positive regression cases from the
// calendar widget spec (docs/superpowers/specs/2026-07-21-...).
// ---------------------------------------------------------------------
ptk_test_ok( 'No school' === $t::classify_title( 'Labor Day - District Closed' ), 'District Closed classifies No school' );
ptk_test_ok( 'No school' === $t::classify_title( 'Yom Kippur - District Closed' ), 'another closure classifies No school' );
ptk_test_ok( 'Special schedule' === $t::classify_title( 'Abbreviated Day for Students and Staff' ), 'Abbreviated classifies Special schedule' );
ptk_test_ok( '' === $t::classify_title( 'Pancake Breakfast' ), '"Break" as a substring of "Breakfast" is NOT matched (word boundary)' );
ptk_test_ok( '' === $t::classify_title( 'School OPEN (Formally Spring Recess day 1)' ), 'a parenthetical "Recess" is stripped and "OPEN" wins -- not No school' );
ptk_test_ok( '' === $t::classify_title( "Eid al-Fitr - SCHOOL OPEN!" ), 'an explicit SCHOOL OPEN always outranks every keyword' );
ptk_test_ok( '' === $t::classify_title( 'Fall Global Festival' ), 'an ordinary PTA event title gets no tag' );

ptk_test_done();
