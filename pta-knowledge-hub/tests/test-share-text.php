<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-text.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Text';

ptk_test_ok( $t::html_to_text( '<p>One</p><p>Two</p>' ) === "One\nTwo", 'paragraphs become newlines' );
ptk_test_ok( $t::html_to_text( 'A<br>B' ) === "A\nB", 'br becomes a newline' );
ptk_test_ok( $t::html_to_text( '<strong>Bold</strong> text' ) === 'Bold text', 'inline tags are dropped' );
ptk_test_ok( $t::html_to_text( 'Join <a href="https://x.test/j">here</a>' ) === 'Join here (https://x.test/j)', 'links become text plus a bare URL' );
ptk_test_ok( $t::html_to_text( 'Mum&nbsp;Sale &amp; more' ) === 'Mum Sale & more', 'entities decoded, nbsp becomes a space' );
ptk_test_ok( $t::html_to_text( "<p>A</p>\n\n\n<p>B</p>" ) === "A\nB", 'runs of blank lines collapse' );
ptk_test_ok( $t::html_to_text( '' ) === '', 'empty input stays empty' );
ptk_test_ok( $t::html_to_text( array( 'x' ) ) === '', 'array input becomes empty, no warning' );

ptk_test_done();
