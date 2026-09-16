<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-color.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Color';

// ---------------------------------------------------------------------
// contrast_ratio() -- WCAG relative luminance
// ---------------------------------------------------------------------

$white_navy = $t::contrast_ratio( '#ffffff', '#1a2f5c' );
ptk_test_ok(
    abs( $white_navy - 13.08 ) < 0.1,
    'contrast_ratio(#ffffff,#1a2f5c) is 13.08 (WCAG); the plan said ~12.6, the formula says 13.08'
);

// The palette defaults that actually fail AA against white.
$known_white = array(
    '#d97706' => 3.19,
    '#16a34a' => 3.30,
    '#ea580c' => 3.56,
    '#0891b2' => 3.68,
    '#0d9488' => 3.74,
);
foreach ( $known_white as $hex => $expected ) {
    $got = $t::contrast_ratio( $hex, '#ffffff' );
    ptk_test_ok( abs( $got - $expected ) < 0.1, "contrast_ratio($hex,#ffffff) is about $expected" );
    ptk_test_ok( $got < 4.5, "$hex fails AA against white, so the guard has work to do" );
}

// The palette defaults that fail against the navy ground.
$known_navy = array(
    '#475569' => 1.73,
    '#4338ca' => 1.66,
    '#7c3aed' => 2.30,
);
foreach ( $known_navy as $hex => $expected ) {
    $got = $t::contrast_ratio( $hex, '#1a2f5c' );
    ptk_test_ok( abs( $got - $expected ) < 0.1, "contrast_ratio($hex,#1a2f5c) is about $expected" );
    ptk_test_ok( $got < 4.5, "$hex is unreadable on navy" );
}

// Order does not matter -- the ratio is symmetric.
ptk_test_ok(
    abs( $t::contrast_ratio( '#1a2f5c', '#ffffff' ) - $white_navy ) < 0.0001,
    'contrast_ratio is symmetric'
);

// Three-digit hex and a missing "#" are both understood.
ptk_test_ok( abs( $t::contrast_ratio( '#fff', '#000' ) - 21.0 ) < 0.01, 'contrast_ratio understands 3-digit hex; white on black is 21' );
ptk_test_ok( abs( $t::contrast_ratio( 'ffffff', '000000' ) - 21.0 ) < 0.01, 'contrast_ratio tolerates a missing hash' );

// Invalid input falls back to navy rather than erroring.
ptk_test_ok(
    abs( $t::contrast_ratio( '#zzz', '#ffffff' ) - $white_navy ) < 0.0001,
    'contrast_ratio: garbage hex is treated as navy, not an error'
);
ptk_test_ok(
    abs( $t::contrast_ratio( '', '#ffffff' ) - $white_navy ) < 0.0001,
    'contrast_ratio: empty string is treated as navy'
);
ptk_test_ok(
    abs( $t::contrast_ratio( null, '#ffffff' ) - $white_navy ) < 0.0001,
    'contrast_ratio: null is treated as navy'
);

// ---------------------------------------------------------------------
// normalize_hex()
// ---------------------------------------------------------------------

ptk_test_ok( $t::normalize_hex( '#AABBCC' ) === '#aabbcc', 'normalize_hex lowercases' );
ptk_test_ok( $t::normalize_hex( '#abc' ) === '#aabbcc', 'normalize_hex expands 3-digit hex' );
ptk_test_ok( $t::normalize_hex( 'abc' ) === '#aabbcc', 'normalize_hex adds the missing hash' );
ptk_test_ok( $t::normalize_hex( '#zzz' ) === '#1a2f5c', 'normalize_hex: garbage falls back to navy' );
ptk_test_ok( $t::normalize_hex( '' ) === '#1a2f5c', 'normalize_hex: empty falls back to navy' );
ptk_test_ok( $t::normalize_hex( null ) === '#1a2f5c', 'normalize_hex: null falls back to navy' );
ptk_test_ok( $t::normalize_hex( array( 'nope' ) ) === '#1a2f5c', 'normalize_hex: an array falls back to navy' );

// ---------------------------------------------------------------------
// readable_pair()
// ---------------------------------------------------------------------

// Already passing -> returned untouched.
ptk_test_ok( $t::readable_pair( '#1a2f5c', '#ffffff' ) === '#1a2f5c', 'readable_pair leaves navy-on-white alone' );
ptk_test_ok( $t::readable_pair( '#2563eb', '#ffffff' ) === '#2563eb', 'readable_pair leaves a passing blue alone (5.17:1)' );
ptk_test_ok( $t::readable_pair( '#ffffff', '#1a2f5c' ) === '#ffffff', 'readable_pair leaves white-on-navy alone' );

// Failing against white -> darkened until it passes.
$fixed = $t::readable_pair( '#d97706', '#ffffff' );
ptk_test_ok( $fixed !== '#d97706', 'readable_pair changes #d97706 on white' );
ptk_test_ok( (bool) preg_match( '/^#[0-9a-f]{6}$/', $fixed ), 'readable_pair returns a normalized 6-digit hex' );
ptk_test_ok( $t::contrast_ratio( $fixed, '#ffffff' ) >= 4.5, 'readable_pair(#d97706,#ffffff) now reaches AA' );

// Every failing palette default must come out readable, on white and on navy.
foreach ( array( '#475569', '#2563eb', '#dc2626', '#ea580c', '#d97706', '#16a34a', '#0d9488', '#0891b2', '#7c3aed', '#db2777', '#4338ca' ) as $hex ) {
    $on_white = $t::readable_pair( $hex, '#ffffff' );
    ptk_test_ok( $t::contrast_ratio( $on_white, '#ffffff' ) >= 4.5, "readable_pair($hex,#ffffff) reaches AA" );
    $on_navy = $t::readable_pair( $hex, '#1a2f5c' );
    ptk_test_ok( $t::contrast_ratio( $on_navy, '#1a2f5c' ) >= 4.5, "readable_pair($hex,#1a2f5c) reaches AA" );
}

// Invalid input falls back to navy, and the result is still readable.
ptk_test_ok( $t::readable_pair( '#zzz', '#ffffff' ) === '#1a2f5c', 'readable_pair: garbage falls back to navy (which passes on white)' );
ptk_test_ok( $t::readable_pair( '', '#ffffff' ) === '#1a2f5c', 'readable_pair: empty falls back to navy' );
ptk_test_ok( $t::readable_pair( null, '#ffffff' ) === '#1a2f5c', 'readable_pair: null falls back to navy' );
ptk_test_ok( $t::contrast_ratio( $t::readable_pair( null, '#1a2f5c' ), '#1a2f5c' ) >= 4.5, 'readable_pair: navy asked to sit on navy is moved until it is readable' );

// It always terminates, even for the pathological case of a color on itself.
$self = $t::readable_pair( '#808080', '#808080' );
ptk_test_ok( (bool) preg_match( '/^#[0-9a-f]{6}$/', $self ), 'readable_pair(#808080,#808080) terminates and returns a hex' );
ptk_test_ok( $t::contrast_ratio( $self, '#808080' ) > 3.0, 'readable_pair pushes mid-grey as far from itself as it can' );

// Determinism.
ptk_test_ok( $t::readable_pair( '#16a34a', '#ffffff' ) === $t::readable_pair( '#16a34a', '#ffffff' ), 'readable_pair is deterministic' );

// ---------------------------------------------------------------------
// is_hex() -- the gate that decides whether a site really chose a color
// ---------------------------------------------------------------------

ptk_test_ok( $t::is_hex( '#aabbcc' ) === true, 'is_hex accepts a 6-digit hex' );
ptk_test_ok( $t::is_hex( '#abc' ) === true, 'is_hex accepts a 3-digit hex' );
ptk_test_ok( $t::is_hex( 'aabbcc' ) === true, 'is_hex accepts a hash-less hex' );
ptk_test_ok( $t::is_hex( '' ) === false, 'is_hex rejects the empty option, so it falls through to the Council value' );
ptk_test_ok( $t::is_hex( '#zzz' ) === false, 'is_hex rejects garbage' );
ptk_test_ok( $t::is_hex( null ) === false, 'is_hex rejects null' );
ptk_test_ok( $t::is_hex( array() ) === false, 'is_hex rejects an array' );

// ---------------------------------------------------------------------
// 4.3.0: BG_OPTION / TEXT_FALLBACK constants exist and are the expected
// values -- square_background_color()/square_text_color() themselves
// touch get_option() and are exercised in Playground, not here (same
// split as share_color() above them).
// ---------------------------------------------------------------------
ptk_test_ok( 'ptk_share_bg_color' === $t::BG_OPTION, 'BG_OPTION is ptk_share_bg_color' );
ptk_test_ok( '#ffffff' === $t::TEXT_FALLBACK, 'TEXT_FALLBACK is white' );
ptk_test_ok( '#1a2f5c' === $t::FALLBACK, 'FALLBACK (background default) stays navy' );

// ---------------------------------------------------------------------
// Round 3.1 (spec item 4): the photo-square-only text/bar pair. Like
// square_background_color()/square_text_color() above, the WordPress-
// coupled getters themselves (square_photo_text_color()/
// square_photo_bar_color()) are exercised in Playground, not here -- this
// covers the constants and the contrast maths PTK_Share_Panel uses to
// decide whether to show the plain "hard to read together" warning.
// ---------------------------------------------------------------------
ptk_test_ok( 'ptk_share_photo_text_color' === $t::PHOTO_TEXT_OPTION, 'PHOTO_TEXT_OPTION is ptk_share_photo_text_color' );
ptk_test_ok( 'ptk_share_photo_bar_color' === $t::PHOTO_BAR_OPTION, 'PHOTO_BAR_OPTION is ptk_share_photo_bar_color' );
ptk_test_ok( '#ffffff' === $t::PHOTO_TEXT_FALLBACK, 'PHOTO_TEXT_FALLBACK is white' );
ptk_test_ok( '#1a2f5c' === $t::PHOTO_BAR_FALLBACK, 'PHOTO_BAR_FALLBACK is navy' );

ptk_test_ok(
    $t::contrast_ratio( $t::PHOTO_TEXT_FALLBACK, $t::PHOTO_BAR_FALLBACK ) >= $t::MIN_CONTRAST,
    'the default photo-square pair (white on navy) clears AA on its own, so the warning never shows out of the box'
);

// A pair a school might genuinely pick that reads poorly -- the panel's
// warning is meant for exactly this, and the render must NOT silently
// correct it (spec: "don't block").
ptk_test_ok(
    $t::contrast_ratio( '#1a2f5c', '#0f172a' ) < $t::MIN_CONTRAST,
    'navy text on near-navy bar fails AA -- this is the case the plain warning is for'
);

ptk_test_done();
