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

ptk_test_done();
