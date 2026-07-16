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

ptk_test_done();
