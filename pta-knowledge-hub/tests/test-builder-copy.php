<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-builder-copy.php';

$c = 'PTK_Builder_Copy';

// Every 'on' string is a question or a plain instruction, and every 'off'
// string is today's literal label (task 1's fallback rule).
$today_labels = array(
    'header.headline'         => 'Headline',
    'header.summary'          => 'One-line summary',
    'header.greeting'         => 'Greeting',
    'announcement.when'       => 'When',
    'announcement.headline'   => 'Headline',
    'announcement.text'       => 'Text',
    'announcement.button_text' => 'Button words',
    'announcement.button_url' => 'Button link',
    'events.date'             => 'Date',
    'events.title'            => 'Title',
    'events.desc'             => 'Description',
    'featured.eyebrow'        => 'Short label',
    'featured.headline'       => 'Headline',
    'featured.body'           => 'Story',
    'featured.image'          => 'Image',
    'featured.link_url'       => 'Link address',
    'featured.link_text'      => 'Link wording',
    'story_cards.eyebrow'     => 'Short label',
    'story_cards.heading'     => 'Heading',
    'story_cards.body'        => 'Story',
    'story_cards.image'       => 'Image',
    'story_cards.link_url'    => 'Link address',
    'story_cards.link_text'   => 'Link wording',
    'quick_notes.body'        => 'Text',
    'quick_notes.link_url'    => 'Link address',
    'quick_notes.link_text'   => 'Link wording',
    'footer.signoff'          => 'Sign-off',
    'footer.link_label'       => 'Link wording',
    'footer.link_url'         => 'Link address',
);

$map = $c::labels();

$seen = array();
foreach ( $map as $type => $fields ) {
    foreach ( $fields as $field => $entry ) {
        $key          = "$type.$field";
        $seen[ $key ] = true;

        ptk_test_ok( isset( $entry['off'], $entry['on'] ), "$key has both an off and an on string" );

        if ( isset( $today_labels[ $key ] ) ) {
            ptk_test_ok( $today_labels[ $key ] === $entry['off'], "$key's fallback matches today's literal label exactly" );
        }

        $on = $entry['on'];
        ptk_test_ok(
            '?' === substr( $on, -1 ) || preg_match( '/^[A-Z][a-z]/', $on ),
            "$key's question ends in '?' or reads as a plain instruction: \"$on\""
        );

        foreach ( PTK_Builder_Copy::BANNED_WORDS as $word ) {
            ptk_test_ok( false === strpos( $on, $word ), "$key's on-string has no banned word ($word)" );
        }
    }
}

foreach ( array_keys( $today_labels ) as $key ) {
    ptk_test_ok( isset( $seen[ $key ] ), "$key is present in the map" );
}

// label(): off returns the literal today string, on returns the question,
// and an unmapped field falls back to $default untouched.
ptk_test_ok( 'Headline' === $c::label( false, 'header', 'headline' ), 'label() off returns the current label' );
ptk_test_ok( "What's the big news this week?" === $c::label( true, 'header', 'headline' ), 'label() on returns the question' );
ptk_test_ok( 'School name' === $c::label( false, 'header', 'school_name', 'School name' ), 'label() falls back for an unmapped field (off)' );
ptk_test_ok( 'School name' === $c::label( true, 'header', 'school_name', 'School name' ), 'label() falls back for an unmapped field (on) too' );

// Step titles: 4 and 5 change, and the helper's off value matches the
// current literal step titles.
ptk_test_ok( 'Finish editing' === $c::step_title( false, 4 ), 'step 4 off matches the current title' );
ptk_test_ok( 'Put it in order' === $c::step_title( true, 4 ), 'step 4 on is the plainer name' );
ptk_test_ok( 'Publish & share' === $c::step_title( false, 5 ), 'step 5 off matches the current title' );
ptk_test_ok( 'Send it out' === $c::step_title( true, 5 ), 'step 5 on is the plainer name' );
ptk_test_ok( 'The basics' === $c::step_title( true, 1, 'The basics' ), 'a step with no entry (1-3) falls back unchanged' );

foreach ( PTK_Builder_Copy::BANNED_WORDS as $word ) {
    foreach ( $c::step_titles() as $entry ) {
        ptk_test_ok( false === strpos( $entry['on'], $word ), "step title has no banned word ($word)" );
    }
}

ptk_test_done();
