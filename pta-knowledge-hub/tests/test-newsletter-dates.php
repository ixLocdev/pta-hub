<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';

ptk_test_ok( PTK_Newsletter_Data::compute_next_issue( 38 ) === 39, 'next issue after 38 is 39' );
ptk_test_ok( PTK_Newsletter_Data::compute_next_issue( 0 ) === 1, 'next issue floors at 1' );
ptk_test_ok( PTK_Newsletter_Data::compute_next_issue( 'x' ) === 1, 'non-numeric last issue -> 1' );

// relabel_for_date( eventDate, today ) — week starts Monday.
$today = '2026-06-22'; // a Monday
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-20', $today ) === 'past', 'past date' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-25', $today ) === 'this-week', 'this week' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-30', $today ) === 'next-week', 'next week' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-07-20', $today ) === 'upcoming', 'further out' );

// Non-Monday $today exercises the Monday-offset math. 2026-06-28 is a Sunday,
// so its week is Mon 2026-06-22 .. Sun 2026-06-28.
$sunday = '2026-06-28';
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-27', $sunday ) === 'past', 'sunday today: same-week earlier day is past' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-28', $sunday ) === 'this-week', 'sunday today: itself is this-week' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-29', $sunday ) === 'next-week', 'sunday today: next monday is next-week' );

// Mid-week $today (2026-06-24 is a Wednesday), week Mon 06-22 .. Sun 06-28.
$wednesday = '2026-06-24';
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-28', $wednesday ) === 'this-week', 'wednesday today: sunday end of week is this-week' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-29', $wednesday ) === 'next-week', 'wednesday today: next monday is next-week' );

// issue_week_monday(): the Monday a "Week of" headline names.
ptk_test_ok( PTK_Newsletter_Data::issue_week_monday( '2026-09-16' ) === '2026-09-14', 'Wed 2026-09-16 -> Mon 2026-09-14' );
ptk_test_ok( PTK_Newsletter_Data::issue_week_monday( '2026-09-14' ) === '2026-09-14', 'Mon 2026-09-14 -> itself' );
ptk_test_ok( PTK_Newsletter_Data::issue_week_monday( '2026-09-20' ) === '2026-09-21', 'Sun 2026-09-20 -> the following Monday, 2026-09-21' );
ptk_test_ok( PTK_Newsletter_Data::issue_week_monday( '2026-09-13' ) === '2026-09-14', 'Sun 2026-09-13 (#040) -> Mon 2026-09-14' );
ptk_test_ok( PTK_Newsletter_Data::issue_week_monday( 'nope' ) === '', 'garbage date -> empty' );
// relabel_for_date() still uses the same Monday math after the refactor.
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-09-20', '2026-09-16' ) === 'this-week', 'relabel: Sunday is still in the Wednesday\'s week' );

// school_year_label(): August-July. Spec Decision 1.
foreach ( array(
    '2026-09-13' => '2026–2027', // #040
    '2026-08-01' => '2026–2027', // August starts the new year
    '2026-07-31' => '2025–2026', // July closes the old one
    '2026-06-22' => '2025–2026', // #038
    '2027-01-05' => '2026–2027',
    '2026-12-31' => '2026–2027',
) as $date => $want ) {
    ptk_test_ok( PTK_Newsletter_Data::school_year_label( $date ) === $want, "school year for $date is $want" );
}
ptk_test_ok( PTK_Newsletter_Data::school_year_label( '' ) === '', 'blank date -> blank school year' );
ptk_test_ok( PTK_Newsletter_Data::school_year_label( 'nope' ) === '', 'garbage date -> blank school year' );

// timeline_states(): past by whole day, last row is the deadline. Spec Decisions 2 and 3.
$rows = array(
    array( 'date' => '2026-09-14', 'time' => '8:30 AM', 'what' => 'Opens' ),
    array( 'date' => '2026-09-17', 'time' => 'noon',    'what' => 'Closes' ),
);
$s = PTK_Newsletter_Data::timeline_states( $rows, '2026-09-13' );
ptk_test_ok( $s === array( array( 'past' => false, 'deadline' => false ), array( 'past' => false, 'deadline' => true ) ), 'before: nothing past, last row is the deadline' );
$s = PTK_Newsletter_Data::timeline_states( $rows, '2026-09-14' );
ptk_test_ok( $s[0]['past'] === false, 'the day itself is not past' );
$s = PTK_Newsletter_Data::timeline_states( $rows, '2026-09-18' );
ptk_test_ok( $s[0]['past'] === true && $s[1]['past'] === true && $s[1]['deadline'] === true, 'after: everything past, deadline still marked' );
$s = PTK_Newsletter_Data::timeline_states( array( array( 'date' => '', 'time' => 'TBA', 'what' => 'x' ) ), '2026-09-18' );
ptk_test_ok( $s === array( array( 'past' => false, 'deadline' => true ) ), 'a row with no date is never past' );
ptk_test_ok( PTK_Newsletter_Data::timeline_states( array(), '2026-09-18' ) === array(), 'no rows, no states' );

ptk_test_done();
