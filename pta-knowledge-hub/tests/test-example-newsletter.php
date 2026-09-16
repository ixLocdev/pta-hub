<?php
// Round 3.1 (spec item 9): the example newsletter's content and gating.
//
// PTK_Example_Newsletter's public entry points (maybe_create()/create()) are
// WordPress-coupled top to bottom (wp_insert_post(), get_option(), etc.) and
// exercised live in Playground, not here -- same split as
// PTK_Share_Color::square_background_color() and friends (see
// tests/test-share-color.php's comment). What IS covered here, with plain
// php: the class constants that gate creation and block publishing, and
// that the example's own content (example_blocks(), reached via
// Reflection since it's protected) is well-formed enough that
// PTK_Newsletter_Data::sanitize_blocks() keeps it intact rather than
// stripping it -- i.e. it would actually render as a real, finished-looking
// newsletter, not an empty shell.

require __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal stubs so example_blocks() (which calls get_bloginfo() and
// wp_specialchars_decode() for the school name) can run under plain php.
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '' ) { return 'Test Elementary PTA'; }
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
    function wp_specialchars_decode( $s, $flags = null ) { return html_entity_decode( (string) $s ); }
}

require __DIR__ . '/../includes/class-newsletter-data.php';
require __DIR__ . '/../includes/class-example-newsletter.php';

$e = 'PTK_Example_Newsletter';

// ---------------------------------------------------------------------
// Constants: the gate + the meta key handle_submission() checks to block
// publishing, and the exact title (do-not-publish is part of the point).
// ---------------------------------------------------------------------
ptk_test_ok( 'ptk_example_newsletter_created' === $e::OPTION_CREATED, 'OPTION_CREATED is ptk_example_newsletter_created' );
ptk_test_ok( 'ptk_nl_is_example' === $e::META_EXAMPLE, 'META_EXAMPLE is ptk_nl_is_example' );
ptk_test_ok( false !== strpos( $e::TITLE, 'do not publish' ), 'TITLE warns "do not publish"' );
ptk_test_ok( false !== strpos( $e::TITLE, 'EXAMPLE' ), 'TITLE is clearly marked EXAMPLE' );

// ---------------------------------------------------------------------
// example_blocks(): well-formed, finished-looking content.
// ---------------------------------------------------------------------
$method = new ReflectionMethod( $e, 'example_blocks' );
// setAccessible() is a no-op since PHP 8.5 (protected methods are already
// reachable via Reflection) and only called here on older PHP.
if ( PHP_VERSION_ID < 80500 ) {
    $method->setAccessible( true );
}
$blocks = $method->invoke( null );

$types = array_column( $blocks, 'type' );
ptk_test_ok( $types === array( 'header', 'announcement', 'events', 'featured', 'story_cards', 'quick_notes', 'footer' ), 'example_blocks() has every section, header first and footer last, in the #040 order' );

$d = 'PTK_Newsletter_Data';
$sanitized = $d::sanitize_blocks( $blocks );
ptk_test_ok( count( $sanitized ) === count( $blocks ), 'sanitize_blocks() keeps every section -- nothing is malformed enough to be dropped' );

$by_type = array();
foreach ( $sanitized as $block ) {
    $by_type[ $block['type'] ] = $block['data'];
}

ptk_test_ok( '' !== trim( $by_type['header']['headline'] ), 'header keeps its headline through sanitizing' );
ptk_test_ok( '' !== trim( $by_type['announcement']['headline'] ), 'the announcement keeps its headline (the one navy callout)' );
ptk_test_ok( count( $by_type['announcement']['timeline'] ) === 3, 'the announcement keeps its 3 timeline dates' );
ptk_test_ok( count( $by_type['events']['rows'] ) === 3, 'events keeps its 3 rows (spec item 9: "3 events")' );
ptk_test_ok( '' !== trim( $by_type['featured']['headline'] ), 'the top story keeps its headline' );
ptk_test_ok( (int) $by_type['featured']['image_id'] === 0, 'the top story has no photo (spec item 9: "no photos")' );
ptk_test_ok( count( $by_type['story_cards']['cards'] ) === 2, 'story_cards keeps its 2 cards (spec item 9: "2 stories")' );
foreach ( $by_type['story_cards']['cards'] as $card ) {
    ptk_test_ok( (int) $card['image_id'] === 0, 'a story card has no photo' );
}
ptk_test_ok( count( $by_type['quick_notes']['items'] ) === 3, 'quick notes keeps its 3 items' );
ptk_test_ok( '' !== trim( $by_type['footer']['signoff'] ), 'the footer keeps its sign-off' );

// No image anywhere -- the whole example is photo-free, so it never trips
// the photo/PII consent gate for someone just looking at it.
ptk_test_ok( false === $d::blocks_have_images( $sanitized ), 'the example has no photos at all, anywhere' );

ptk_test_done();
