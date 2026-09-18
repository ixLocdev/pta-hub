<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-wizard-copy.php';

$c = 'PTK_Wizard_Copy';

// Every 'on' string is a question or a plain instruction, and every 'off'
// string is today's literal label (task 1's fallback rule, plan's table).
$today_labels = array(
    'title'          => 'Title',
    'excerpt'        => 'Short Summary',
    'tags'           => 'Tags',
    'featured_image' => 'Featured Image',
    'introduction'    => 'Introduction',
    'difficulty'     => 'Difficulty',
    'time_estimate'  => 'Time Estimate',
    'materials'      => "What You'll Need",
    'steps'          => 'Steps',
    'resource_url'   => 'Resource URL',
    'resource_howto' => 'How to Use',
);

$map = $c::labels();

$seen = array();
foreach ( $map as $field => $entry ) {
    $seen[ $field ] = true;

    ptk_test_ok( isset( $entry['off'], $entry['on'] ), "$field has both an off and an on string" );

    if ( isset( $today_labels[ $field ] ) ) {
        ptk_test_ok( $today_labels[ $field ] === $entry['off'], "$field's fallback matches today's literal label exactly" );
    }

    $on = $entry['on'];
    ptk_test_ok(
        '?' === substr( $on, -1 ) || preg_match( '/^[A-Z][a-z]/', $on ),
        "$field's question ends in '?' or reads as a plain instruction: \"$on\""
    );

    foreach ( PTK_Wizard_Copy::BANNED_WORDS as $word ) {
        ptk_test_ok( false === strpos( $on, $word ), "$field's on-string has no banned word ($word)" );
    }
}

foreach ( array_keys( $today_labels ) as $field ) {
    ptk_test_ok( isset( $seen[ $field ] ), "$field is present in the map" );
}

// Every key in the new map exists in the fallback map and vice versa --
// here that's the same array (one flat table), so this just confirms
// $today_labels and labels() cover the same fields.
ptk_test_ok( count( array_diff_key( $map, $today_labels ) ) === 0, 'every labels() key has a today-string in the test table' );
ptk_test_ok( count( array_diff_key( $today_labels, $map ) ) === 0, 'every today-string in the test table has a labels() key' );

// label(): off returns the literal today string, on returns the question,
// and an unmapped field falls back to $default untouched.
ptk_test_ok( 'Title' === $c::label( false, 'title' ), 'label() off returns the current label' );
ptk_test_ok( "What's the question families ask?" === $c::label( true, 'title' ), 'label() on returns the question' );
ptk_test_ok( 'Resource URL' === $c::label( false, 'resource_url' ), 'resource_url off matches the current label' );
ptk_test_ok( 'Where is the file or page?' === $c::label( true, 'resource_url' ), 'resource_url on is the plan\'s question' );
ptk_test_ok( 'File Type' === $c::label( false, 'file_type', 'File Type' ), 'label() falls back for an unmapped field (off)' );
ptk_test_ok( 'File Type' === $c::label( true, 'file_type', 'File Type' ), 'label() falls back for an unmapped field (on) too' );

// meta(): the wizard-wide strings (intro line, category heading, save choices).
ptk_test_ok( '4 quick steps &middot; takes about 5 minutes' === $c::meta_text( false, 'intro_meta' ), 'intro_meta off matches the current literal text' );
ptk_test_ok( 'Four short questions. About five minutes.' === $c::meta_text( true, 'intro_meta' ), 'intro_meta on is the plan\'s wording' );
ptk_test_ok( 'What type of entry are you creating?' === $c::meta_text( false, 'category' ), 'category off matches the current literal heading' );
ptk_test_ok( 'What kind of thing is this?' === $c::meta_text( true, 'category' ), 'category on is the plan\'s question' );
ptk_test_ok( 'Draft (recommended &mdash; review before publishing)' === $c::meta_text( false, 'save_draft' ), 'save_draft off matches the current literal label' );
ptk_test_ok( 'Keep it to myself for now' === $c::meta_text( true, 'save_draft' ), 'save_draft on is the plan\'s wording' );
ptk_test_ok( 'Publish now (visible immediately)' === $c::meta_text( false, 'save_publish' ), 'save_publish off matches the current literal label' );
ptk_test_ok( 'Put it on the Hub' === $c::meta_text( true, 'save_publish' ), 'save_publish on is the plan\'s wording' );
ptk_test_ok( 'Fallback' === $c::meta_text( false, 'nope', 'Fallback' ), 'meta_text() falls back for an unmapped key' );

foreach ( PTK_Wizard_Copy::BANNED_WORDS as $word ) {
    foreach ( $c::meta() as $key => $entry ) {
        ptk_test_ok( false === strpos( $entry['on'], $word ), "meta $key has no banned word ($word)" );
    }
}

// all_entries() is a pure merge -- no field/key collides between the two tables.
$merged = $c::all_entries();
ptk_test_ok( count( $merged ) === count( $c::labels() ) + count( $c::meta() ), 'all_entries() is labels() + meta() with no key collisions' );

// Task 3: the confirmation screen's headline + stamp.
ptk_test_ok( 'Fallback text' === $c::notice_text( false, 'created', 'Fallback text' ), 'notice_text() off returns $default untouched' );
ptk_test_ok( "You're all set. Families can find this on the Hub." === $c::notice_text( true, 'created', 'Fallback text' ), 'notice_text() on for a new entry is the plan\'s sentence' );
ptk_test_ok( 'Updated. Families see the new version now.' === $c::notice_text( true, 'updated', 'Fallback text' ), 'notice_text() on for an update is the plan\'s sentence' );
ptk_test_ok( 'Saved. Nobody sees it yet.' === $c::notice_text( true, 'draft', 'Fallback text' ), 'notice_text() on for a draft is the plan\'s sentence' );
ptk_test_ok( 'Fallback text' === $c::notice_text( true, 'nope', 'Fallback text' ), 'notice_text() falls back for an unknown key even when on' );

ptk_test_ok( null === $c::notice_stamp( false, 'created' ), 'notice_stamp() is null when off' );
ptk_test_ok( array( 'SENT', 'success' ) === $c::notice_stamp( true, 'created' ), 'a new entry stamps SENT/success' );
ptk_test_ok( array( 'SENT', 'success' ) === $c::notice_stamp( true, 'updated' ), 'an update stamps SENT/success' );
ptk_test_ok( array( 'NOT SENT YET', 'dim' ) === $c::notice_stamp( true, 'draft' ), 'a draft stamps NOT SENT YET/dim' );
ptk_test_ok( null === $c::notice_stamp( true, 'nope' ), 'notice_stamp() is null for an unknown key' );

foreach ( PTK_Wizard_Copy::BANNED_WORDS as $word ) {
    foreach ( $c::notices() as $key => $entry ) {
        ptk_test_ok( false === strpos( $entry['on'], $word ), "notice $key has no banned word ($word)" );
    }
}

// Task 3: validation messages, keyed by today's literal $submission_error text.
ptk_test_ok( 'Please add a Title and pick a category, then save again.' === $c::validation_text( false, 'Please add a Title and pick a category, then save again.' ), 'validation_text() off returns the literal text unchanged' );
ptk_test_ok( 'This needs a title so families can find it. Pick a category too, then save again.' === $c::validation_text( true, 'Please add a Title and pick a category, then save again.' ), 'validation_text() on rewrites the missing-title error as a sentence' );
ptk_test_ok( 'Some dynamic error the map does not know about.' === $c::validation_text( true, 'Some dynamic error the map does not know about.' ), 'validation_text() on passes through an unmapped (e.g. dynamic) message untouched' );
foreach ( $c::validation() as $off => $on ) {
    ptk_test_ok( '?' === substr( $on, -1 ) || preg_match( '/^[A-Z][a-z]/', $on ), "validation message reads as a sentence: \"$on\"" );
}

ptk_test_done();
