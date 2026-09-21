<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletters-copy.php';

$c = 'PTK_Newsletters_Copy';

// --- title and lead ---
ptk_test_ok( 'Your newsletters' === $c::title(), 'the title says what the screen is for' );
ptk_test_ok( '' !== $c::lead(), 'there is a lead sentence' );

// --- the buttons ---
ptk_test_ok( 'Start a new newsletter' === $c::add_button_label(), 'the primary action starts a new issue' );
ptk_test_ok( '' !== $c::open_button_label(), 'there is an open button' );
ptk_test_ok( '' !== $c::view_button_label(), 'there is a view button' );

// --- the "nothing to see yet" note, never a dead link ---
ptk_test_ok( '' !== $c::not_sent_note(), 'there is a not-sent note' );
ptk_test_ok( false !== stripos( $c::not_sent_note(), 'nothing' ), 'the not-sent note says there is nothing to see' );

// --- plain-words dates ---
ptk_test_ok( 'September 21, 2026' === $c::date_words( '2026-09-21' ), 'a date renders in plain words' );
ptk_test_ok( '' === $c::date_words( '' ), 'an empty date renders empty' );
ptk_test_ok( '' === $c::date_words( 'not-a-date' ), 'a malformed date renders empty, not a guess' );
ptk_test_ok( '' === $c::date_words( '2026-13-40' ), 'an impossible date renders empty' );

// --- the issue line combines number and date, plainly ---
ptk_test_ok( "No. 41 \u{2014} September 21, 2026" === $c::issue_line( 41, '2026-09-21' ), 'issue number and date combine, with a real em dash like the rest of the Hub' );
ptk_test_ok( false === strpos( $c::lead(), ' -- ' ), 'no typewriter double hyphens reach the screen' );
ptk_test_ok( 'No. 41' === $c::issue_line( 41, '' ), 'a missing date still shows the issue number' );
ptk_test_ok( 'September 21, 2026' === $c::issue_line( 0, '2026-09-21' ), 'a missing issue number still shows the date' );
ptk_test_ok( '' === $c::issue_line( 0, '' ), 'nothing known renders empty' );
ptk_test_ok( '' === $c::issue_line( '', '' ), 'a blank issue number renders empty' );

// --- the empty state, said in the positive ---
$empty = $c::empty_state();
ptk_test_ok( '' !== $empty['title'], 'the empty title' );
ptk_test_ok( '' !== $empty['text'], 'the empty text' );

// --- the quiet link to WordPress's own list ---
ptk_test_ok( '' !== $c::see_all_link_label(), 'there is a label for the WordPress list link' );

// --- no database or WordPress word anywhere in the screen's words ---
$all = implode( ' ', array(
    $c::title(),
    $c::lead(),
    $c::add_button_label(),
    $c::open_button_label(),
    $c::view_button_label(),
    $c::not_sent_note(),
    $c::empty_state()['title'],
    $c::empty_state()['text'],
    $c::see_all_link_label(),
) );
foreach ( array( 'post', 'taxonomy', 'category', 'trash', 'excerpt', 'cpt', 'draft', 'publish', 'author', 'seo' ) as $word ) {
    ptk_test_ok( false === stripos( $all, $word ), 'the screen never says "' . $word . '"' );
}

ptk_test_done();
