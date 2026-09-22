<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-focal-point.php';
require __DIR__ . '/../includes/class-post-banner.php';

$b = 'PTK_Post_Banner';

$css = $b::css( 3819, 25, 32 );
ptk_test_ok( false !== strpos( $css, 'body.postid-3819' ), 'the rule is scoped to one post' );
ptk_test_ok( false !== strpos( $css, 'background-position:25% 32%;' ), 'and says where the picture should sit' );
ptk_test_ok( false === strpos( $css, '!important' ), 'without shouting' );

// Centre is what the template already does.
ptk_test_ok( '' === $b::css( 3819, 50, 50 ), 'a picture nobody moved needs no rule' );

// Nothing stored: an entry written before framing existed, or a post with no picture.
ptk_test_ok( '' === $b::css( 3819, '', '' ), 'no framing, no rule' );
ptk_test_ok( '' === $b::css( 0, 25, 32 ), 'and no post, no rule' );

// Whatever is in the database, what comes out is two clamped numbers -- this
// goes inside a <style> tag on a public page.
foreach ( array( '<script>', '9999', '-40', 'abc', '10;}body{display:none', null ) as $nasty ) {
    $out = $b::css( 12, $nasty, $nasty );
    ptk_test_ok( '' === $out || (bool) preg_match( '/^body\.postid-12 [^{]+\{background-position:\d{1,3}% \d{1,3}%;\}$/', $out ), 'nothing but numbers survives: ' . var_export( $nasty, true ) );
}

ptk_test_done();
