<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-focal-point.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$F = 'PTK_Focal_Point';
ptk_test_ok( $F::clamp_percent( 37 ) === 37, 'clamp_percent: in range passes through' );
ptk_test_ok( $F::clamp_percent( -5 ) === 0, 'clamp_percent: clamps below 0' );
ptk_test_ok( $F::clamp_percent( 140 ) === 100, 'clamp_percent: clamps above 100' );
ptk_test_ok( $F::clamp_percent( 'nope' ) === 50, 'clamp_percent: non-numeric -> center' );
ptk_test_ok( $F::clamp_percent( null ) === 50, 'clamp_percent: null -> center' );

ptk_test_ok( $F::sanitize_zoom( 100 ) === 0, 'sanitize_zoom: exactly the floor drops to 0' );
ptk_test_ok( $F::sanitize_zoom( 50 ) === 0, 'sanitize_zoom: below the floor drops to 0' );
ptk_test_ok( $F::sanitize_zoom( 175 ) === 175, 'sanitize_zoom: in range passes through' );
ptk_test_ok( $F::sanitize_zoom( 999 ) === 250, 'sanitize_zoom: clamps to the ceiling' );
ptk_test_ok( $F::sanitize_zoom( 'nope' ) === 0, 'sanitize_zoom: non-numeric drops to 0' );
ptk_test_ok( $F::sanitize_zoom( null ) === 0, 'sanitize_zoom: absent drops to 0' );

ptk_test_ok( $F::effective_zoom( 0 ) === 100, 'effective_zoom: sentinel reads as 100' );
ptk_test_ok( $F::effective_zoom( 175 ) === 175, 'effective_zoom: stored value passes through' );

ptk_test_ok( $F::object_position( 37, 62 ) === '37% 62%', 'object_position: formats both parts' );
ptk_test_ok( $F::object_position( -5, 140 ) === '0% 100%', 'object_position: clamps both parts' );

ptk_test_ok( $F::css_zoom_style( 50, 50, 0 ) === '', 'css_zoom_style: unzoomed emits nothing' );
$zs = $F::css_zoom_style( 30, 25, 175 );
ptk_test_ok( false !== strpos( $zs, 'scale(1.75)' ), 'css_zoom_style: scale from zoom/100' );
ptk_test_ok( false !== strpos( $zs, 'transform-origin:30% 25%' ), 'css_zoom_style: origin follows the focal point' );

// rect_crop() -- 16:9 window (4.5.2 photo square strip).
list( $x, $y, $w, $h ) = $F::rect_crop( 1000, 1000, 16 / 9, 50, 50, 0 );
ptk_test_ok( 1000.0 === round( $w, 2 ) && 562.5 === round( $h, 2 ) && 218.75 === round( $y, 2 ) && 0.0 === round( $x, 2 ), 'rect_crop: square source, 16:9 window centered' );
list( $x, $y, $w, $h ) = $F::rect_crop( 1000, 1000, 16 / 9, 50, 0, 0 );
ptk_test_ok( 0.0 === round( $y, 2 ), 'rect_crop: focal at top -> crop at y=0' );
list( $x, $y, $w, $h ) = $F::rect_crop( 4000, 1000, 16 / 9, 100, 50, 200 );
ptk_test_ok( 888.89 === round( $w, 2 ) && 500.0 === round( $h, 2 ) && 3111.11 === round( $x, 2 ), 'rect_crop: wide source, far right, 200% zoom' );

// square_crop_rect() -- the exact four cases verified in the spec's php -r transcript.
list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 50, 50, 0 );
ptk_test_ok( 500.0 === round( $x, 2 ) && 0.0 === round( $y, 2 ) && 1000.0 === round( $s, 2 ), 'square_crop_rect: centered, unzoomed' );
list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 0, 50, 0 );
ptk_test_ok( 0.0 === round( $x, 2 ), 'square_crop_rect: focal far left -> crop at x=0' );
list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 100, 50, 0 );
ptk_test_ok( 1000.0 === round( $x, 2 ), 'square_crop_rect: focal far right -> crop at x=avail' );
list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 50, 50, 200 );
ptk_test_ok( 750.0 === round( $x, 2 ) && 250.0 === round( $y, 2 ) && 500.0 === round( $s, 2 ), 'square_crop_rect: 200% zoom halves the window and re-centers it' );

ptk_test_done();
