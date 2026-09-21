<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-asked-for-copy.php';

$c = 'PTK_Asked_For_Copy';

// --- title and lead ---
ptk_test_ok( 'What families have asked for' === $c::title(), 'the title says what the screen is for' );
ptk_test_ok( '' !== $c::lead(), 'there is a lead sentence' );

// --- the one sentence beside the stamp: singular and plural both read correctly ---
ptk_test_ok( '1 question is waiting for you.' === $c::waiting_sentence( 1 ), 'one question' );
ptk_test_ok( '3 questions are waiting for you.' === $c::waiting_sentence( 3 ), 'several questions' );
ptk_test_ok( '' === $c::waiting_sentence( 0 ), 'nothing waiting says nothing at all' );
ptk_test_ok( '' === $c::waiting_sentence( -2 ), 'a nonsense count never prints' );

// --- who asked, including the anonymous case ---
ptk_test_ok( 'Ana Ruiz' === $c::asked_by( 'Ana Ruiz' ), 'a name is used as given' );
ptk_test_ok( 'A member' === $c::asked_by( '' ), 'nobody given a name is "A member"' );
ptk_test_ok( 'A member' === $c::asked_by( '   ' ), 'whitespace-only counts as nobody given' );

// --- the buttons ---
ptk_test_ok( 'Answer it' === $c::answer_button_label(), 'the primary button answers the question' );
ptk_test_ok( 'Remove it' === $c::remove_button_label(), 'the quiet button removes it' );

// --- the empty state, said in the positive ---
$empty = $c::empty_state();
ptk_test_ok( 'Nothing is waiting.' === $empty['title'], 'the empty title' );
ptk_test_ok( false !== strpos( $empty['text'], "asks for it" ), 'the empty text says what will land here' );

// --- the quiet link back to WordPress's own list ---
ptk_test_ok( '' !== $c::see_all_link_label(), 'there is a label for the quiet link' );

// --- the "Removed." banner text ---
ptk_test_ok( 'Removed.' === $c::removed_notice_text(), 'the removed banner says "Removed."' );

// --- no moderation or database word anywhere in the screen's words ---
$all = implode( ' ', array(
    $c::title(),
    $c::lead(),
    $c::waiting_sentence( 3 ),
    $c::asked_by( 'Ana Ruiz' ),
    $c::asked_by( '' ),
    $c::answer_button_label(),
    $c::remove_button_label(),
    $c::removed_notice_text(),
    $c::empty_state()['title'],
    $c::empty_state()['text'],
    $c::see_all_link_label(),
) );
foreach ( array( 'pending', 'queue', 'convert', 'post', 'cpt', 'trash' ) as $word ) {
    ptk_test_ok( false === stripos( $all, $word ), 'the screen never says "' . $word . '"' );
}

ptk_test_done();
