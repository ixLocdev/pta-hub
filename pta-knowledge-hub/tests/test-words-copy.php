<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-words-copy.php';

$c = 'PTK_Words_Copy';

// --- title and lead ---
ptk_test_ok( "Words you've explained" === $c::title(), 'the title says what the screen is for' );
ptk_test_ok( '' !== $c::lead(), 'there is a lead sentence' );

// --- the buttons ---
ptk_test_ok( 'Explain a word or phrase' === $c::add_button_label(), 'the primary action explains another word' );
ptk_test_ok( 'Change the wording' === $c::change_button_label(), 'the quiet action changes the wording' );
ptk_test_ok( 'See it in the glossary' === $c::view_button_label(), 'the quiet action sees it where families see it' );

// --- the search box ---
ptk_test_ok( '' !== $c::search_placeholder(), 'there is a search placeholder' );

// --- the definition fallback: short version wins, then the longer writing, trimmed ---
ptk_test_ok( 'A short definition.' === $c::definition( 'A short definition.', 'The long version nobody will read here.' ), 'a short version is used as given' );
ptk_test_ok( 'Short.' === $c::definition( '  Short.  ', '' ), 'a short version is trimmed of surrounding whitespace' );
ptk_test_ok( 'Just a few words here' === $c::definition( '', 'Just a few words here' ), 'short writing is used whole, with no short version' );
$long   = implode( ' ', array_fill( 0, 40, 'word' ) );
$result = $c::definition( '', $long, 30 );
ptk_test_ok( 0 === strpos( $result, str_repeat( 'word ', 29 ) . 'word' ), 'long writing is trimmed to the word limit' );
ptk_test_ok( '...' === substr( $result, -3 ), 'a trimmed definition ends with an ellipsis' );
ptk_test_ok( '' === $c::definition( '', '' ), 'nothing written at all comes back empty, not a placeholder' );
ptk_test_ok( '' === $c::definition( '   ', '   ' ), 'whitespace-only writing counts as nothing written' );

// --- what a card says when there is truly nothing to show ---
ptk_test_ok( '' !== $c::no_definition_note(), 'there is a note for an empty definition' );

// --- the quiet note for a word nobody has put in front of families yet ---
ptk_test_ok( 'Not sent yet.' === $c::not_sent_note(), 'the not-sent note' );

// --- the empty state, said in the positive ---
$empty = $c::empty_state();
ptk_test_ok( 'Nothing explained yet.' === $empty['title'], 'the empty title' );
ptk_test_ok( false !== strpos( $empty['text'], 'glossary' ), 'the empty text says where it lands' );

// --- the quiet link to the glossary families read ---
ptk_test_ok( '' !== $c::glossary_link_label(), 'there is a label for the glossary link' );

// --- no database or WordPress word anywhere in the screen's words ---
$all = implode( ' ', array(
    $c::title(),
    $c::lead(),
    $c::add_button_label(),
    $c::change_button_label(),
    $c::view_button_label(),
    $c::search_placeholder(),
    $c::no_definition_note(),
    $c::not_sent_note(),
    $c::empty_state()['title'],
    $c::empty_state()['text'],
    $c::glossary_link_label(),
) );
foreach ( array( 'post', 'taxonomy', 'category', 'trash', 'excerpt', 'cpt' ) as $word ) {
    ptk_test_ok( false === stripos( $all, $word ), 'the screen never says "' . $word . '"' );
}

ptk_test_done();
