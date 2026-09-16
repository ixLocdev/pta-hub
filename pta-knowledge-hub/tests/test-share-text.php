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

// Northeast's headings are already whole sentences carrying the fact.
$card = array( 'heading' => 'Film on the Field moves to Friday, October 16.', 'body' => '<p>Bring a blanket. Rain date October 23.</p>' );
ptk_test_ok( $t::story_line( $card ) === 'Film on the Field moves to Friday, October 16.', 'a sentence heading is the whole line' );

// A label-style heading is too thin on its own, so the body completes it.
$label = array( 'heading' => 'Mum Sale', 'body' => '<p>Open through September 25. Pickup at the car wash.</p>' );
ptk_test_ok( $label_line = $t::story_line( $label ), 'label heading returns something' );
ptk_test_ok( strpos( $label_line, 'Mum Sale' ) === 0, 'label heading still leads' );
ptk_test_ok( strpos( $label_line, 'Open through September 25.' ) !== false, 'label heading gains the first sentence' );

ptk_test_ok( $t::story_line( array( 'heading' => '', 'body' => '<p>First one. Second one.</p>' ) ) === 'First one.', 'no heading falls back to first sentence' );
ptk_test_ok( $t::story_line( array( 'heading' => '', 'body' => '' ) ) === '', 'an empty card yields nothing' );

$long = array( 'heading' => '', 'body' => '<p>' . str_repeat( 'word ', 60 ) . '</p>' );
$cut  = $t::story_line( $long );
// Count CHARACTERS, not bytes: the ellipsis is three bytes in UTF-8.
ptk_test_ok( mb_strlen( $cut ) <= 121, 'long text is truncated near 120 chars' );
ptk_test_ok( mb_substr( $cut, -1 ) === '…', 'truncation is marked with an ellipsis' );
ptk_test_ok( strpos( $cut, 'wor…' ) === false, 'truncation lands on a word boundary' );

// A cut landing mid-character must not emit broken UTF-8 into a caption.
$dashes = array( 'heading' => '', 'body' => '<p>' . str_repeat( 'a—b ', 40 ) . '</p>' );
$dcut   = $t::story_line( $dashes );
ptk_test_ok( mb_check_encoding( $dcut, 'UTF-8' ), 'truncation never splits a multibyte character' );

ptk_test_done();
