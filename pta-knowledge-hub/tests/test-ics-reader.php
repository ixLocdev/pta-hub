<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-ics-reader.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t  = 'PTK_Ics_Reader';
$tz = 'America/New_York';

// ---------------------------------------------------------------------
// unfold() -- line folding
// ---------------------------------------------------------------------
$folded = "SUMMARY:Line one\r\n  continues here\r\nUID:abc\r\n";
$lines  = $t::unfold( $folded );
ptk_test_ok( 'SUMMARY:Line one continues here' === $lines[0], 'a folded continuation line is joined onto the previous line' );
ptk_test_ok( 'UID:abc' === $lines[1], 'the next real line stays separate' );

// ---------------------------------------------------------------------
// unescape_text()
// ---------------------------------------------------------------------
ptk_test_ok( 'a; b, c\\' === $t::unescape_text( 'a\\; b\\, c\\\\' ), 'escaped semicolon/comma/backslash unescape' );
ptk_test_ok( "line1\nline2" === $t::unescape_text( 'line1\\nline2' ), 'escaped \\n becomes a real newline' );

// ---------------------------------------------------------------------
// Real Northeast PTA fixture
// ---------------------------------------------------------------------
$ne_ics = file_get_contents( __DIR__ . '/fixtures/northeast-pta-2026-07-21.ics' );

// A known all-day multi-day event: Winter Break 20261228 -> DTEND 20270101 (exclusive) = Dec 28-31.
$events = $t::events_in_range( $ne_ics, '2026-12-01', '2026-12-31', $tz );
$winter = null;
foreach ( $events as $e ) {
    if ( false !== strpos( $e['title'], 'Winter Break' ) ) {
        $winter = $e;
    }
}
ptk_test_ok( null !== $winter, 'Winter Break - Schools Closed is found in December 2026' );
if ( $winter ) {
    ptk_test_ok( '2026-12-28' === $winter['start'], 'Winter Break starts 2026-12-28' );
    ptk_test_ok( '2026-12-31' === $winter['end'], 'exclusive all-day DTEND 2027-01-01 becomes display end 2026-12-31, not Jan 1' );
    ptk_test_ok( true === $winter['all_day'], 'Winter Break is an all-day event' );
}

// A known single-day all-day event in a known week.
$events = $t::events_in_range( $ne_ics, '2026-08-24', '2026-08-30', $tz );
$found_titles = array_map( function ( $e ) { return $e['title']; }, $events );
ptk_test_ok( in_array( 'Freshman and New Student Orientation', $found_titles, true ), 'a known event in a known week is found by title' );

// A known timed UTC event that shifts a calendar day under naive UTC
// rendering -- "PTA Meeting!" 20260227T000000Z is 8:00 PM Feb 26 Eastern.
$events = $t::events_in_range( $ne_ics, '2026-02-23', '2026-03-01', $tz );
$pta_meeting = null;
foreach ( $events as $e ) {
    if ( false !== strpos( $e['title'], 'PTA Meeting' ) ) {
        $pta_meeting = $e;
    }
}
ptk_test_ok( null !== $pta_meeting, 'PTA Meeting! is found' );
if ( $pta_meeting ) {
    ptk_test_ok( '2026-02-26' === $pta_meeting['start'], 'UTC timestamp 20260227T000000Z converts to 2026-02-26 in America/New_York, not Feb 27' );
    ptk_test_ok( '' !== $pta_meeting['time'], 'a timed event has a time string' );
}

// LOCATION with escaped commas unescapes cleanly.
$events = $t::events_in_range( $ne_ics, '2020-01-01', '2030-01-01', $tz );
$porta = null;
foreach ( $events as $e ) {
    if ( 'Porta' === trim( explode( "\n", $e['location'] )[0] ) ) {
        $porta = $e;
        break;
    }
}
ptk_test_ok( null !== $porta, 'an event with an escaped-comma LOCATION unescapes to a plain address' );

// ---------------------------------------------------------------------
// Real Montclair District fixture -- also exercises VTIMEZONE-only RRULEs
// (must be ignored: those RRULEs belong to VTIMEZONE, not any VEVENT).
// ---------------------------------------------------------------------
$mc_ics = file_get_contents( __DIR__ . '/fixtures/montclair-district-2026-07-21.ics' );
$events = $t::events_in_range( $mc_ics, '2026-04-20', '2026-04-25', $tz );
$found_titles = array_map( function ( $e ) { return $e['title']; }, $events );
ptk_test_ok( in_array( 'CHB Book Fair', $found_titles, true ), 'district feed: a known all-day event is parsed' );

// A timed UTC event from the district feed: 20260422T190000Z = 3:00 PM Eastern (EDT, UTC-4).
$events = $t::events_in_range( $mc_ics, '2026-04-22', '2026-04-22', $tz );
$timed = null;
foreach ( $events as $e ) {
    if ( ! $e['all_day'] ) { $timed = $e; break; }
}
ptk_test_ok( null !== $timed, 'district feed: a timed event is found on its converted local day' );

// ---------------------------------------------------------------------
// Synthetic: RRULE WEEKLY with COUNT
// ---------------------------------------------------------------------
$weekly = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:weekly1\r\nDTSTART:20260901T190000Z\r\nDTEND:20260901T200000Z\r\nSUMMARY:PTA Meeting Weekly\r\nRRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=4\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$events = $t::events_in_range( $weekly, '2026-09-01', '2026-12-31', $tz );
ptk_test_ok( 4 === count( $events ), 'RRULE WEEKLY;COUNT=4 produces exactly 4 occurrences' );
ptk_test_ok( 4 === count( array_unique( array_column( $events, 'start' ) ) ), 'the 4 occurrences fall on 4 distinct dates' );

// Same rule but the display range only covers 2 of the 4 weeks: expansion
// is still capped to the range window.
$events = $t::events_in_range( $weekly, '2026-09-01', '2026-09-14', $tz );
ptk_test_ok( count( $events ) <= 2, 'RRULE expansion is limited to the requested display range' );

// ---------------------------------------------------------------------
// Synthetic: RRULE DAILY with UNTIL, plus EXDATE
// ---------------------------------------------------------------------
$daily = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:daily1\r\nDTSTART;VALUE=DATE:20260901\r\nDTEND;VALUE=DATE:20260902\r\nSUMMARY:Daily Thing\r\nRRULE:FREQ=DAILY;UNTIL=20260905\r\nEXDATE;VALUE=DATE:20260903\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$events = $t::events_in_range( $daily, '2026-09-01', '2026-09-10', $tz );
$dates  = array_column( $events, 'start' );
sort( $dates );
ptk_test_ok( ! in_array( '2026-09-03', $dates, true ), 'EXDATE removes that one occurrence' );
ptk_test_ok( in_array( '2026-09-01', $dates, true ) && in_array( '2026-09-05', $dates, true ), 'DAILY;UNTIL includes the first and last day of its span' );
ptk_test_ok( ! in_array( '2026-09-06', $dates, true ), 'DAILY;UNTIL excludes a day past the UNTIL date' );

// ---------------------------------------------------------------------
// Synthetic: RECURRENCE-ID override + STATUS:CANCELLED skip
// ---------------------------------------------------------------------
$override = "BEGIN:VCALENDAR\r\n"
    . "BEGIN:VEVENT\r\nUID:series1\r\nDTSTART:20260901T190000Z\r\nDTEND:20260901T200000Z\r\nSUMMARY:Weekly Thing\r\nRRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=3\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:series1\r\nRECURRENCE-ID:20260908T190000Z\r\nDTSTART:20260908T200000Z\r\nDTEND:20260908T210000Z\r\nSUMMARY:Weekly Thing (moved an hour later)\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:series1\r\nRECURRENCE-ID:20260915T190000Z\r\nDTSTART:20260915T190000Z\r\nDTEND:20260915T200000Z\r\nSUMMARY:Weekly Thing\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$events = $t::events_in_range( $override, '2026-09-01', '2026-09-30', $tz );
ptk_test_ok( 2 === count( $events ), 'a cancelled RECURRENCE-ID occurrence is dropped, leaving 2 of the 3' );
$titles = array_column( $events, 'title' );
ptk_test_ok( in_array( 'Weekly Thing (moved an hour later)', $titles, true ), 'a RECURRENCE-ID override replaces that single occurrence' );

// A fully CANCELLED master (no recurrence) is skipped entirely.
$cancelled = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:c1\r\nDTSTART;VALUE=DATE:20260910\r\nSUMMARY:Cancelled Thing\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$events = $t::events_in_range( $cancelled, '2026-09-01', '2026-09-30', $tz );
ptk_test_ok( 0 === count( $events ), 'a STATUS:CANCELLED master event is skipped entirely' );

// ---------------------------------------------------------------------
// Synthetic: TZID (not just bare Z)
// ---------------------------------------------------------------------
$tzid_ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:tzid1\r\nDTSTART;TZID=America/Chicago:20260910T140000\r\nDTEND;TZID=America/Chicago:20260910T150000\r\nSUMMARY:Central Time Thing\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$events = $t::events_in_range( $tzid_ics, '2026-09-10', '2026-09-10', $tz );
ptk_test_ok( 1 === count( $events ), 'a TZID=America/Chicago event is found' );
if ( 1 === count( $events ) ) {
    // 2pm Central = 3pm Eastern.
    ptk_test_ok( '3:00pm' === $events[0]['time'], 'TZID=America/Chicago 2pm converts to 3:00pm America/New_York' );
}

ptk_test_done();
