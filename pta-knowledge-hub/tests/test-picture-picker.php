<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-picture-copy.php';

$c = 'PTK_Picture_Copy';

// --- the share-square rule: filename prefix, belt-and-braces title check ---
ptk_test_ok( false === $c::is_share_square( 'birthday-party-2026.jpg', 'birthday-party-2026', 'image/jpeg' ), 'a real photo passes' );
ptk_test_ok( true === $c::is_share_square( 'share-square-3736-041-a1b2c3d4.png', 'Share square for issue 041', 'image/png' ), 'a generated share square is caught by its filename' );
ptk_test_ok( true === $c::is_share_square( 'newsletter-issue-41-cover.png', 'Share square for issue 41', 'image/png' ), 'a PNG titled "Share square for issue 41" is caught even with an ordinary filename' );
ptk_test_ok( false === $c::is_share_square( 'share-squares-are-great.png', 'Share squares are great', 'image/png' ), 'prefix matching does not overreach -- "share-squares-" is not "share-square-"' );

// --- the filename rule alone ---
ptk_test_ok( true === $c::is_share_square_filename( 'share-square-12.png' ), 'exact prefix, dash included' );
ptk_test_ok( false === $c::is_share_square_filename( 'share-square.png' ), 'no dash after the word, no match' );
ptk_test_ok( false === $c::is_share_square_filename( 'my-share-square-12.png' ), 'the prefix must start the filename' );

// --- the title rule alone, mime-gated ---
ptk_test_ok( true === $c::is_share_square_title( 'Share square for issue 12', 'image/png' ), 'the exact title PTK_Share_Image gives every generated square' );
ptk_test_ok( false === $c::is_share_square_title( 'Share square for issue 12', 'image/jpeg' ), 'never a jpeg -- PTK_Share_Image::ensure_square() only ever writes PNGs' );
ptk_test_ok( true === $c::is_share_square_title( 'Share square for issue 12' ), 'mime is optional -- omitted, it is not checked' );
ptk_test_ok( false === $c::is_share_square_title( 'A square photo we like', 'image/png' ), 'an unrelated title never matches' );

// --- the words the picker says ---
$s = $c::strings();

ptk_test_ok( 'Which picture?' === $s['modal_title'], 'the modal asks one plain question' );
ptk_test_ok( "What's in this picture?" === $s['alt_question'], 'the only field after choosing a picture' );
ptk_test_ok( false !== stripos( $s['alt_help'], 'read aloud' ), 'the help line says why we ask, in plain words' );
ptk_test_ok( false !== stripos( $s['device_heading'], 'this device' ), 'the first way to answer is a picture from this device' );
ptk_test_ok( "Pictures you've used before" === $s['previous_heading'], 'the second way to answer' );
ptk_test_ok( 'Find a picture' === $s['search_placeholder'], 'the search box is a plain question' );

// --- no WordPress or database words anywhere in the picker's copy ---
$all = implode( ' ', array_values( $s ) );
$banned = array( 'media', 'attachment', 'library', 'alt text', 'file type' );
foreach ( $banned as $word ) {
    ptk_test_ok( false === stripos( $all, $word ), 'the picker never says "' . $word . '"' );
}
// "upload" is checked as a standalone word (not inside e.g. "uploaded a picture"
// prose we deliberately avoid) -- as a NOUN it must never appear; we simply
// never use the word at all, verb or noun, so the check is unconditional.
ptk_test_ok( false === stripos( $all, 'upload' ), 'the picker never says "upload" in any form' );

ptk_test_done();
