<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-page.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Page';

// ---------------------------------------------------------------------
// parse_id(): only a plain run of digits is an id
// ---------------------------------------------------------------------

ptk_test_ok( 7 === $t::parse_id( '7' ), 'parse_id("7") is 7' );
ptk_test_ok( 7 === $t::parse_id( ' 7 ' ), 'parse_id trims whitespace' );
ptk_test_ok( 0 === $t::parse_id( 'abc' ), 'parse_id("abc") is 0' );
ptk_test_ok( 0 === $t::parse_id( '7abc' ), 'parse_id("7abc") is 0, not 7' );
ptk_test_ok( 0 === $t::parse_id( '-7' ), 'parse_id("-7") is 0' );
ptk_test_ok( 0 === $t::parse_id( '' ), 'parse_id("") is 0' );
ptk_test_ok( 0 === $t::parse_id( array( '7' ) ), 'parse_id(array) is 0, no warning' );
ptk_test_ok( 0 === $t::parse_id( null ), 'parse_id(null) is 0' );
ptk_test_ok( 0 === $t::parse_id( '7.5' ), 'parse_id("7.5") is 0' );

// ---------------------------------------------------------------------
// The page is public, so it must stay read-only. Guard the source: none
// of the calls that draw or write may appear in executable code.
// ---------------------------------------------------------------------

$src = file_get_contents( __DIR__ . '/../includes/class-share-page.php' );
// Drop comments so the docblock that NAMES the forbidden calls doesn't trip this.
$code = '';
foreach ( token_get_all( $src ) as $tok ) {
    if ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
        continue;
    }
    $code .= is_array( $tok ) ? $tok[1] : $tok;
}

$forbidden = array(
    'ensure_square', 'render_png', 'use_generated_square', 'save_square', 'save_caption',
    'delete_caption', 'wp_insert_attachment', 'wp_insert_post', 'wp_update_post',
    'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_option',
    'wp_delete_attachment', 'imagecreate', 'imagepng', 'wp_generate_attachment_metadata',
);
foreach ( $forbidden as $call ) {
    ptk_test_ok( false === stripos( $code, $call ), "phone page never calls $call" );
}

ptk_test_ok( false !== strpos( $code, "'pta_newsletter' !== get_post_type(" ), 'phone page checks the post type' );
ptk_test_ok( false !== strpos( $code, "'publish' !== get_post_status(" ), 'phone page checks the post is published' );
ptk_test_ok( false === strpos( $code, 'esc_url( $wa_href' ), 'wa.me link is not run through esc_url (it strips %0A)' );

ptk_test_done();
