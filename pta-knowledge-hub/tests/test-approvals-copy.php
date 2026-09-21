<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-approvals-copy.php';

$c = 'PTK_Approvals_Copy';

// --- the one sentence beside the stamp counts both kinds, and counts of one stay singular ---
ptk_test_ok( '1 recommendation is waiting for a look.' === $c::waiting_sentence( 1, 0 ), 'one recommendation' );
ptk_test_ok( '2 recommendations are waiting for a look.' === $c::waiting_sentence( 2, 0 ), 'two recommendations' );
ptk_test_ok( '1 review is waiting for a look.' === $c::waiting_sentence( 0, 1 ), 'one review' );
ptk_test_ok( '2 recommendations and 1 review are waiting for a look.' === $c::waiting_sentence( 2, 1 ), 'both kinds read as one sentence' );
ptk_test_ok( '' === $c::waiting_sentence( 0, 0 ), 'nothing waiting says nothing at all' );
ptk_test_ok( '' === $c::waiting_sentence( -3, 0 ), 'a nonsense count never prints' );

// --- what the member said ---
ptk_test_ok( 'Would use them again' === $c::verdict_line( 1 ), 'a thumbs up in words' );
ptk_test_ok( 'Would not use them again' === $c::verdict_line( 0 ), 'a thumbs down in words' );
ptk_test_ok( 'Price 3 out of 5 · Quality 5 out of 5' === $c::ratings_line( 3, 5 ), 'ratings are numbers, not stars a screen reader spells out' );
ptk_test_ok( 'Quality 4 out of 5' === $c::ratings_line( 0, 4 ), 'a rating nobody gave is left out' );
ptk_test_ok( '' === $c::ratings_line( 0, 0 ), 'no ratings at all prints nothing' );
ptk_test_ok( 'Price 5 out of 5' === $c::ratings_line( 9, 0 ), 'a rating out of range is clamped, never printed raw' );

// --- who it came from ---
ptk_test_ok( 'suggested by Ana Ruiz, Northeast PTA' === $c::credit_line( 'suggested by', 'Ana Ruiz', 'Northeast PTA' ), 'name and school' );
ptk_test_ok( 'suggested by Ana Ruiz' === $c::credit_line( 'suggested by', 'Ana Ruiz', '' ), 'name alone' );
ptk_test_ok( '' === $c::credit_line( 'suggested by', '', '' ), 'nobody known, nothing said' );

// --- the confirm step, because none of this can be undone ---
ptk_test_ok( "Turn down Ana's DJ Service?" === $c::confirm_question( 'vendor', "Ana's DJ Service", 'Ana Ruiz' ), 'turning down a recommendation names it' );
ptk_test_ok( "Turn down Ana Ruiz's review of Party Pros?" === $c::confirm_question( 'review', 'Party Pros', 'Ana Ruiz' ), 'turning down a review names both' );
ptk_test_ok( 'Turn down this review of Party Pros?' === $c::confirm_question( 'review', 'Party Pros', '' ), 'an unknown member still gets a clear question' );
ptk_test_ok( "Chris's" === $c::possessive( 'Chris' ), 'ordinary possessive' );
ptk_test_ok( "Jones's" === $c::possessive( 'Jones' ), 'a name already ending in s still takes an s' );
ptk_test_ok( false !== strpos( $c::confirm_warning( 'vendor' ), 'no way to get it back' ), 'the warning says it plainly' );
ptk_test_ok( false !== strpos( $c::confirm_warning( 'review' ), 'deleted' ), 'the review warning says what goes' );
ptk_test_ok( $c::confirm_warning( 'vendor' ) !== $c::confirm_warning( 'review' ), 'the two warnings say different things, because different things are lost' );

// --- the buttons ---
ptk_test_ok( 'Add them to the directory' === $c::button_label( 'vendor', 'approve' ), 'approving a vendor is adding them' );
ptk_test_ok( 'Turn this down' === $c::button_label( 'vendor', 'reject' ), 'rejecting is turning down' );
ptk_test_ok( 'Show this review' === $c::button_label( 'review', 'approve' ), 'approving a review is showing it' );

// --- no moderation jargon anywhere in the screen's words ---
$all = implode( ' ', array(
    $c::waiting_sentence( 2, 1 ),
    $c::confirm_question( 'vendor', 'Party Pros', 'Ana Ruiz' ),
    $c::confirm_warning( 'vendor' ),
    $c::confirm_warning( 'review' ),
    $c::outcome_line( 'approved' ),
    $c::outcome_line( 'rejected' ),
    $c::empty_state()['title'],
    $c::empty_state()['text'],
    $c::button_label( 'vendor', 'approve' ),
    $c::button_label( 'vendor', 'reject' ),
) );
foreach ( array( 'approve', 'reject', 'moderat', 'pending', 'queue', 'submission' ) as $word ) {
    ptk_test_ok( false === stripos( $all, $word ), 'the screen never says "' . $word . '"' );
}

ptk_test_done();
