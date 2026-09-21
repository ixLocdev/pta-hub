<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-post-parts.php';

$p = 'PTK_Post_Parts';

// Defaults: every key present, nothing null.
$empty = $p::defaults();
foreach ( array( 'kicker', 'headline', 'words', 'image_id', 'date_label', 'date_note', 'steps', 'link_url', 'link_text' ) as $key ) {
    ptk_test_ok( array_key_exists( $key, $empty ), "defaults() has $key" );
}
ptk_test_ok( array() === $empty['steps'], 'steps default to none' );
ptk_test_ok( 0 === $empty['image_id'], 'no picture by default' );

// Sanitizing keeps text, drops markup, clamps the id.
$dirty = array(
    'kicker'    => '  Class Parents 2026-2027 <script>x</script> ',
    'headline'  => 'We still need class parents.',
    'words'     => "First paragraph.\n\nSecond paragraph.",
    'image_id'  => '-4',
    'link_url'  => 'javascript:alert(1)',
    'link_text' => 'Sign up',
    'steps'     => array( array( 'heading' => 'Sign up', 'body' => 'Fill the form.' ) ),
);
$clean = $p::sanitize( $dirty );
ptk_test_ok( false === strpos( $clean['kicker'], '<script' ), 'markup never survives the kicker' );
ptk_test_ok( 0 === $clean['image_id'], 'a negative picture id becomes none' );
ptk_test_ok( '' === $clean['link_url'], 'a javascript: link is refused' );
ptk_test_ok( 1 === count( $clean['steps'] ), 'a step survives' );

// The summary: the first paragraph.
ptk_test_ok( 'First paragraph.' === $p::summary( $clean ), 'the summary is the first paragraph' );
ptk_test_ok( '' === $p::summary( $p::defaults() ), 'nothing written, nothing summarized' );

// "Is there anything here at all?"
ptk_test_ok( false === $p::has_content( $p::defaults() ), 'empty parts have no content' );
ptk_test_ok( true === $p::has_content( $clean ), 'filled parts do' );

ptk_test_done();
