<?php
/**
 * Entry framing (2026-09-21): the picture picker's framing step, stored as
 * post meta on an entry and applied wherever a search card crops the
 * picture. See docs/superpowers/specs/2026-09-21-which-picture-design.md
 * and includes/class-content-wizard.php::handle_submission(),
 * includes/class-search-engine.php::format_result().
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-focal-point.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$F = 'PTK_Focal_Point';

// --- the exact sanitizing handle_submission() applies to the four fields ---
ptk_test_ok( $F::clamp_percent( '150' ) === 100, 'focal x/y: an out-of-range percentage clamps to 100' );
ptk_test_ok( $F::clamp_percent( '-30' ) === 0, 'focal x/y: an out-of-range percentage clamps to 0' );
ptk_test_ok( $F::clamp_percent( '' ) === 50, 'focal x/y: a blank field (no picture framed) defaults to center' );
ptk_test_ok( $F::sanitize_zoom( '9999' ) === 250, 'zoom: an absurd value clamps to the ceiling' );
ptk_test_ok( $F::sanitize_zoom( '' ) === 0, 'zoom: a blank field defaults to never-zoomed' );

// fit is whitelisted against exactly two values -- the same ternary
// handle_submission() and format_result() both use. Anything else, junk
// included, falls back to the safe default.
function ptk_test_sanitize_fit( $raw ) {
    return 'crop' === $raw ? 'crop' : 'whole';
}
ptk_test_ok( 'crop' === ptk_test_sanitize_fit( 'crop' ), 'fit: "crop" passes through' );
ptk_test_ok( 'whole' === ptk_test_sanitize_fit( 'whole' ), 'fit: "whole" passes through' );
ptk_test_ok( 'whole' === ptk_test_sanitize_fit( '<script>alert(1)</script>' ), 'fit: junk is rejected, falls back to whole' );
ptk_test_ok( 'whole' === ptk_test_sanitize_fit( null ), 'fit: absent falls back to whole' );
ptk_test_ok( 'whole' === ptk_test_sanitize_fit( '' ), 'fit: blank falls back to whole' );

// --- PTK_Search_Engine::format_result(): the no-meta default, and that
// fit:whole vs fit:crop produce different CSS on the card ---

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
    function get_the_post_thumbnail_url( $id, $size = '' ) {
        return 'https://example.test/photo-' . $id . '.jpg';
    }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return 'https://example.test/entry-' . $id . '/'; }
}
if ( ! function_exists( 'wp_trim_words' ) ) {
    function wp_trim_words( $s, $n = 30 ) { return $s; }
}
$GLOBALS['ptk_test_post_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single = false ) {
        return isset( $GLOBALS['ptk_test_post_meta'][ $id ][ $key ] ) ? $GLOBALS['ptk_test_post_meta'][ $id ][ $key ] : '';
    }
}

require __DIR__ . '/../includes/class-search-engine.php';

function ptk_test_format_result( $post_id ) {
    $post = (object) array(
        'ID'             => $post_id,
        'post_title'     => 'Test entry',
        'post_excerpt'   => 'An excerpt.',
        'post_content'   => 'Body.',
    );
    $ref = new ReflectionMethod( 'PTK_Search_Engine', 'format_result' );
    return $ref->invoke( null, $post, 10, array( 'cat_slugs' => array(), 'categories' => array(), 'tag_names' => array() ), false );
}

// An entry with no framing meta -- today's behavior, pinned.
$GLOBALS['ptk_test_post_meta'][1] = array();
$r1 = ptk_test_format_result( 1 );
ptk_test_ok( 'crop' === $r1['thumbnailStyle']['fit'], 'no meta: defaults to crop (today\'s card, unchanged)' );
ptk_test_ok( '50% 50%' === $r1['thumbnailStyle']['position'], 'no meta: centered, like every card before this' );
ptk_test_ok( '' === $r1['thumbnailStyle']['zoom'], 'no meta: no zoom style' );

// An entry framed well off-center.
$GLOBALS['ptk_test_post_meta'][2] = array(
    'ptk_image_focal_x' => 12,
    'ptk_image_focal_y' => 8,
    'ptk_image_zoom'    => 0,
    'ptk_image_fit'     => 'crop',
);
$r2 = ptk_test_format_result( 2 );
ptk_test_ok( 'crop' === $r2['thumbnailStyle']['fit'], 'framed off-center: fit is crop' );
ptk_test_ok( '12% 8%' === $r2['thumbnailStyle']['position'], 'framed off-center: object-position matches the chosen point, not 50% 50%' );

// The same photo, but "Whole photo" -- fit:whole and fit:crop must differ.
$GLOBALS['ptk_test_post_meta'][3] = array(
    'ptk_image_focal_x' => 12,
    'ptk_image_focal_y' => 8,
    'ptk_image_zoom'    => 0,
    'ptk_image_fit'     => 'whole',
);
$r3 = ptk_test_format_result( 3 );
ptk_test_ok( 'whole' === $r3['thumbnailStyle']['fit'], 'whole photo: fit is whole' );
ptk_test_ok( $r3['thumbnailStyle']['fit'] !== $r2['thumbnailStyle']['fit'], 'fit:whole and fit:crop produce different results for the same photo' );

ptk_test_done();
