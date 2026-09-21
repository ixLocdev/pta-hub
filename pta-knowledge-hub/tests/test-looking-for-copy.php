<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-looking-for-copy.php';

$c = 'PTK_Looking_For_Copy';

// --- title and lead ---
ptk_test_ok( 'What families are looking for' === $c::title(), 'the title says what the screen is for' );
ptk_test_ok( '' !== $c::lead(), 'there is a lead sentence' );

// --- the stamp sentence: singular and plural both read correctly ---
ptk_test_ok( '1 topic is waiting for an answer.' === $c::gaps_waiting_sentence( 1 ), 'one topic' );
ptk_test_ok( '3 topics are waiting for an answer.' === $c::gaps_waiting_sentence( 3 ), 'several topics' );
ptk_test_ok( '' === $c::gaps_waiting_sentence( 0 ), 'nothing waiting says nothing at all' );
ptk_test_ok( '' === $c::gaps_waiting_sentence( -1 ), 'a nonsense count never prints' );

// --- "N people looked for this, most recently ..." pinned in singular and plural ---
ptk_test_ok( '9 people looked for this, most recently on Tuesday.' === $c::looked_sentence( 9, 'on Tuesday' ), 'plural, with a weekday' );
ptk_test_ok( '1 person looked for this, most recently today.' === $c::looked_sentence( 1, 'today' ), 'singular, today' );
ptk_test_ok( '2 people looked for this.' === $c::looked_sentence( 2, '' ), 'no "when" at all is left out cleanly' );

// --- when_word(): plain words, never a raw timestamp ---
$now = strtotime( '2026-09-21 12:00:00' );
ptk_test_ok( 'today' === $c::when_word( $now, $now ), 'the same moment is "today"' );
ptk_test_ok( 'today' === $c::when_word( $now, strtotime( '2026-09-21 01:00:00' ) ), 'earlier the same day is "today"' );
ptk_test_ok( 'yesterday' === $c::when_word( $now, strtotime( '2026-09-20 12:00:00' ) ), 'one day back is "yesterday"' );
ptk_test_ok( 'on ' . gmdate( 'l', strtotime( '2026-09-17 12:00:00' ) ) === $c::when_word( $now, strtotime( '2026-09-17 12:00:00' ) ), 'within the last week is a weekday name' );
ptk_test_ok( 'on September 2' === $c::when_word( $now, strtotime( '2026-09-02 12:00:00' ) ), 'further back is a month and day' );
ptk_test_ok( 'today' === $c::when_word( $now, $now + 999999 ), 'a future timestamp never breaks it' );
ptk_test_ok( false === strpos( $c::when_word( $now, strtotime( '2026-09-02 12:00:00' ) ), (string) strtotime( '2026-09-02 12:00:00' ) ), 'no raw timestamp leaks into the words' );

// --- the no-gaps state, said warmly ---
$empty = $c::gaps_empty();
ptk_test_ok( 'Every search found something.' === $empty['title'], 'the warm no-gaps title' );
ptk_test_ok( false !== strpos( $empty['text'], 'write the answer' ), 'the empty text says what will land here' );

// --- found (quieter) section ---
ptk_test_ok( 'What they found' === $c::found_heading(), 'the quieter heading' );
ptk_test_ok( 'Found once' === $c::found_meta( 1 ), 'found once, singular' );
ptk_test_ok( 'Found 9 times' === $c::found_meta( 9 ), 'found several times' );

// --- the folded numbers ---
ptk_test_ok( 'This week' === $c::range_label( '7' ), 'range: this week' );
ptk_test_ok( 'This month' === $c::range_label( '30' ), 'range: this month' );
ptk_test_ok( 'Last 3 months' === $c::range_label( '90' ), 'range: last 3 months' );
ptk_test_ok( 'This year' === $c::range_label( '365' ), 'range: this year' );
ptk_test_ok( 'All time' === $c::range_label( 'all' ), 'range: all time' );
ptk_test_ok( 'This month' === $c::range_label( 'nonsense' ), 'an unknown range key falls back to this month' );
ptk_test_ok( '1 search, this week.' === $c::numbers_summary( 1, '7' ), 'one search, singular' );
ptk_test_ok( '204 searches, this month.' === $c::numbers_summary( 204, '30' ), 'the folded summary reads as words, not a number of days' );

// --- recent searches ---
ptk_test_ok( 'The last 20 searches.' === $c::recent_summary( 20 ), 'recent fold summary, plural' );
ptk_test_ok( 'The last 1 search.' === $c::recent_summary( 1 ), 'recent fold summary, singular' );
ptk_test_ok( 'Nothing yet.' === $c::recent_summary( 0 ), 'no recent searches yet' );
ptk_test_ok( 'Found nothing' === $c::recent_result_meta( 0 ), 'a recent search that found nothing' );
ptk_test_ok( 'Found 1 answer' === $c::recent_result_meta( 1 ), 'a recent search that found one answer' );
ptk_test_ok( 'Found 4 answers' === $c::recent_result_meta( 4 ), 'a recent search that found several answers' );

// --- the export button never says the letters CSV ---
ptk_test_ok( false === stripos( $c::export_button_label(), 'csv' ), 'the export button says "spreadsheet", not "CSV"' );
ptk_test_ok( false !== stripos( $c::export_button_label(), 'spreadsheet' ), 'the export button says "spreadsheet"' );

// --- no analytics or database jargon anywhere on the screen ---
$all = implode( ' ', array(
    $c::title(),
    $c::lead(),
    $c::gaps_waiting_sentence( 3 ),
    $c::write_answer_button_label(),
    $c::looked_sentence( 9, 'on Tuesday' ),
    $c::when_word( $now, $now ),
    $c::gaps_empty()['title'],
    $c::gaps_empty()['text'],
    $c::found_heading(),
    $c::found_meta( 9 ),
    $c::numbers_heading(),
    $c::numbers_summary( 204, '30' ),
    $c::range_label( '7' ),
    $c::range_label( '30' ),
    $c::range_label( '90' ),
    $c::range_label( '365' ),
    $c::range_label( 'all' ),
    $c::stat_total_label(),
    $c::stat_different_label(),
    $c::stat_typical_label(),
    $c::stat_nothing_label(),
    $c::chart_heading(),
    $c::export_button_label(),
    $c::recent_heading(),
    $c::recent_summary( 20 ),
    $c::recent_result_meta( 4 ),
) );
foreach ( array( 'query', 'queries', 'log', 'results_count', 'unique', 'avg', 'zero-result', 'csv' ) as $word ) {
    ptk_test_ok( false === stripos( $all, $word ), 'the screen never says "' . $word . '"' );
}

ptk_test_done();
