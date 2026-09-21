<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-post-parts.php';
require __DIR__ . '/../includes/class-post-renderer.php';

$p = 'PTK_Post_Parts';
$r = 'PTK_Post_Renderer';

// An empty parts array renders ''.
ptk_test_ok( '' === $r::render( $p::defaults() ), 'nothing written, nothing rendered' );

// A headline renders inside an <h1>.
$headline_only = $p::sanitize( array( 'headline' => 'We still need class parents.' ) );
$html = $r::render( $headline_only );
ptk_test_ok( false !== strpos( $html, '<h1' ) && false !== strpos( $html, 'We still need class parents.' ) && false !== strpos( $html, '</h1>' ), 'the headline renders inside an h1' );

// The kicker renders above the headline, and is omitted entirely when blank.
$with_kicker = $p::sanitize( array( 'kicker' => 'Class Parents 2026-2027', 'headline' => 'We still need class parents.' ) );
$html = $r::render( $with_kicker );
$kicker_pos   = strpos( $html, 'Class Parents 2026-2027' );
$headline_pos = strpos( $html, '<h1' );
ptk_test_ok( false !== $kicker_pos && false !== $headline_pos && $kicker_pos < $headline_pos, 'the kicker renders above the headline' );

$no_kicker = $p::sanitize( array( 'headline' => 'We still need class parents.' ) );
$html = $r::render( $no_kicker );
ptk_test_ok( false === strpos( $html, 'dateline' ), 'a blank kicker renders nothing at all' );

// Two paragraphs render as two <p>s.
$two_paras = $p::sanitize( array( 'words' => "First paragraph.\n\nSecond paragraph." ) );
$html = $r::render( $two_paras );
ptk_test_ok( 2 === substr_count( $html, '<p' ), 'two paragraphs render as two <p>s' );

// A step list renders numbered.
$steps = $p::sanitize( array( 'steps' => array(
    array( 'heading' => 'Sign up', 'body' => 'Fill the form.' ),
    array( 'heading' => 'The draw', 'body' => 'Two parents are chosen at random.' ),
) ) );
$html = $r::render( $steps );
ptk_test_ok( false !== strpos( $html, '>1<' ) && false !== strpos( $html, '>2<' ) && false !== strpos( $html, 'Sign up' ) && false !== strpos( $html, 'The draw' ), 'a step list renders numbered' );

// A date renders one callout.
$date = $p::sanitize( array( 'date_label' => 'The draw', 'date_note' => 'Thursday, September 10' ) );
$html = $r::render( $date );
ptk_test_ok( 1 === substr_count( $html, 'The draw' ) && 1 === substr_count( $html, 'background:#1a2f5c' ), 'a date renders one navy callout' );

// A button renders once with its text.
$button = $p::sanitize( array( 'link_url' => '/sign-up', 'link_text' => 'Sign up to be a class parent' ) );
$html = $r::render( $button );
ptk_test_ok( 1 === substr_count( $html, 'Sign up to be a class parent' ) && 1 === substr_count( $html, '<a ' ), 'a button renders once with its text' );

// javascript: never appears in output, even when it sneaks past sanitize().
$sneaky = $p::defaults();
$sneaky['headline']  = 'Click here';
$sneaky['link_url']  = 'javascript:alert(1)';
$sneaky['link_text'] = 'Go';
$html = $r::render( $sneaky );
ptk_test_ok( false === strpos( $html, 'javascript:' ), 'javascript: never appears in output' );

// render() is deterministic: the same parts twice produce identical HTML.
$full = $p::sanitize( array(
    'kicker'     => 'Class Parents 2026-2027',
    'headline'   => 'We still need class parents.',
    'words'      => "Thank you to every family who signed up.\n\nIf you've been meaning to, now is the moment.",
    'date_label' => 'The draw',
    'date_note'  => 'Thursday, September 10',
    'steps'      => array(
        array( 'heading' => 'Sign up', 'body' => 'Fill the form.' ),
    ),
    'link_url'   => '/sign-up',
    'link_text'  => 'Sign up',
) );
ptk_test_ok( $r::render( $full, "Thank you,\nYour Northeast PTA" ) === $r::render( $full, "Thank you,\nYour Northeast PTA" ), 'render() is deterministic' );

// Picture support: a picture renders a <figure> below the headline.
$with_picture = $p::sanitize( array(
    'headline' => 'We still need class parents.',
    'words'    => 'Thank you to everyone.',
) );
$picture = array(
    'url'      => 'https://example.com/photo.jpg',
    'alt'      => 'A photo',
    'fit'      => 'crop',
    'position' => '50% 50%',
    'zoom'     => '',
);
$html = $r::render( $with_picture, '', $picture );
$headline_pos = strpos( $html, '<h1' );
$rule_pos     = strpos( $html, '<div style="height:3px' );
$figure_pos   = strpos( $html, '<figure' );
$body_pos     = strpos( $html, 'Thank you to everyone' );
ptk_test_ok( false !== $headline_pos && false !== $figure_pos && false !== $body_pos && $rule_pos < $figure_pos && $figure_pos < $body_pos, 'picture renders below the headline in a <figure>' );

// fit: crop renders object-fit:cover and object-position.
$html = $r::render( $with_picture, '', array(
    'url'      => 'https://example.com/photo.jpg',
    'alt'      => 'A photo',
    'fit'      => 'crop',
    'position' => '30% 70%',
    'zoom'     => '',
) );
ptk_test_ok( false !== strpos( $html, 'object-fit:cover' ) && false !== strpos( $html, 'object-position:30% 70%' ), 'fit:crop renders object-fit:cover and object-position' );

// fit: whole renders object-fit:contain without object-position.
$html = $r::render( $with_picture, '', array(
    'url'      => 'https://example.com/photo.jpg',
    'alt'      => 'A photo',
    'fit'      => 'whole',
    'position' => '50% 50%',
    'zoom'     => '',
) );
ptk_test_ok( false !== strpos( $html, 'object-fit:contain' ) && false === strpos( $html, 'object-position' ), 'fit:whole renders object-fit:contain without object-position' );

// Empty picture array renders no <figure>.
$html = $r::render( $with_picture, '', array() );
ptk_test_ok( false === strpos( $html, '<figure' ), 'empty picture array renders no figure' );

// Empty url renders no <figure>.
$html = $r::render( $with_picture, '', array( 'url' => '', 'alt' => 'A photo', 'fit' => 'crop' ) );
ptk_test_ok( false === strpos( $html, '<figure' ), 'empty url renders no figure' );

// javascript: url is refused.
$html = $r::render( $with_picture, '', array( 'url' => 'javascript:alert(1)', 'alt' => 'A photo', 'fit' => 'crop' ) );
ptk_test_ok( false === strpos( $html, '<figure' ), 'javascript: url is refused' );

// data: url is refused.
$html = $r::render( $with_picture, '', array( 'url' => 'data:image/png;base64,abc', 'alt' => 'A photo', 'fit' => 'crop' ) );
ptk_test_ok( false === strpos( $html, '<figure' ), 'data: url is refused' );

// Alt text is escaped.
$html = $r::render( $with_picture, '', array( 'url' => 'https://example.com/photo.jpg', 'alt' => '<script>alert(1)</script>', 'fit' => 'crop' ) );
ptk_test_ok( false === strpos( $html, '<script>' ) && false !== strpos( $html, 'alt=' ), 'alt text is escaped' );

// Zoom CSS is appended when fit:crop and zoom is non-empty.
$html = $r::render( $with_picture, '', array(
    'url'      => 'https://example.com/photo.jpg',
    'alt'      => 'A photo',
    'fit'      => 'crop',
    'position' => '50% 50%',
    'zoom'     => 'transform:scale(1.4);',
) );
ptk_test_ok( false !== strpos( $html, 'transform:scale(1.4)' ), 'zoom CSS is appended for crop' );

// Hash method: same HTML same hash.
$html1 = $r::render( $full, "Thank you,\nYour Northeast PTA" );
$html2 = $r::render( $full, "Thank you,\nYour Northeast PTA" );
ptk_test_ok( $r::hash( $html1 ) === $r::hash( $html2 ), 'same HTML produces same hash' );

// Hash method: different HTML different hash.
$html1 = $r::render( array( 'headline' => 'One' ), '' );
$html2 = $r::render( array( 'headline' => 'Two' ), '' );
ptk_test_ok( $r::hash( $html1 ) !== $r::hash( $html2 ), 'different HTML produces different hash' );

// Hash is a valid sha1.
$html = $r::render( $full, '' );
$hash = $r::hash( $html );
ptk_test_ok( 1 === preg_match( '/^[a-f0-9]{40}$/', $hash ), 'hash is a valid sha1' );

ptk_test_done();
