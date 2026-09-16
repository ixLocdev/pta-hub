// Node unit test for assets/js/focal-point.js's pure math functions.
//
// Asserts the SAME cases as tests/test-focal-point.php (the PHP this file
// mirrors), so the JS the picker control uses live in the browser is proven
// to agree number-for-number with PTK_Focal_Point, plus the reference's own
// pinned interaction cases (pinch, pointer-to-focal) translated into this
// module's vocabulary.
//
// Run: node pta-knowledge-hub/tests/test-focal-point-js.mjs

import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire( import.meta.url );
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const {
    ptkFocalClampPercent,
    ptkFocalSanitizeZoom,
    ptkFocalEffectiveZoom,
    ptkFocalPointerToFocal,
    ptkFocalCssZoomStyle
} = require( path.join( __dirname, '..', 'assets', 'js', 'focal-point.js' ) );

let failed = false;

function ok( cond, label ) {
    if ( cond ) {
        console.log( '  ok  - ' + label );
    } else {
        console.log( '  FAIL- ' + label );
        failed = true;
    }
}

ok( ptkFocalClampPercent( 37 ) === 37, 'clamp_percent: in range passes through' );
ok( ptkFocalClampPercent( -5 ) === 0, 'clamp_percent: clamps below 0' );
ok( ptkFocalClampPercent( 140 ) === 100, 'clamp_percent: clamps above 100' );
ok( ptkFocalClampPercent( 'nope' ) === 50, 'clamp_percent: non-numeric -> center' );
ok( ptkFocalClampPercent( null ) === 50, 'clamp_percent: null -> center' );

ok( ptkFocalSanitizeZoom( 100 ) === 0, 'sanitize_zoom: exactly the floor drops to 0' );
ok( ptkFocalSanitizeZoom( 50 ) === 0, 'sanitize_zoom: below the floor drops to 0' );
ok( ptkFocalSanitizeZoom( 175 ) === 175, 'sanitize_zoom: in range passes through' );
ok( ptkFocalSanitizeZoom( 999 ) === 250, 'sanitize_zoom: clamps to the ceiling' );
ok( ptkFocalSanitizeZoom( 'nope' ) === 0, 'sanitize_zoom: non-numeric drops to 0' );

ok( ptkFocalEffectiveZoom( 0 ) === 100, 'effective_zoom: sentinel reads as 100' );
ok( ptkFocalEffectiveZoom( 175 ) === 175, 'effective_zoom: stored value passes through' );

ok( ptkFocalCssZoomStyle( 50, 50, 0 ) === '', 'css_zoom_style: unzoomed emits nothing' );
const zs = ptkFocalCssZoomStyle( 30, 25, 175 );
ok( zs.indexOf( 'scale(1.75)' ) !== -1, 'css_zoom_style: scale from zoom/100' );
ok( zs.indexOf( 'transform-origin:30% 25%' ) !== -1, 'css_zoom_style: origin follows the focal point' );

// Pinch: zoom 120, fingers 80px apart -> 160px apart (2x) -> 240.
ok( ptkFocalSanitizeZoom( 120 * ( 160 / 80 ) ) === 240, 'pinch: doubling the spread doubles the zoom, clamped/rounded the same as the picker will do it' );
// Pinch back in: zoom 200, halved spread -> 100 -> dropped to the sentinel.
ok( ptkFocalSanitizeZoom( 200 * ( 50 / 100 ) ) === 0, 'pinch: halving 200 lands exactly on the floor, which drops rather than stores' );

// pointerToFocal: 200x100 rect at the origin; one pixel across is 0.5%, one pixel down is 1%.
const rect = { left: 0, top: 0, width: 200, height: 100 };
const f = ptkFocalPointerToFocal( rect, 150, 50 );
ok( f.x === 75 && f.y === 50, 'pointerToFocal: reports the percent the pointer landed on' );
const clamped = ptkFocalPointerToFocal( rect, -40, 400 );
ok( clamped.x === 0 && clamped.y === 100, 'pointerToFocal: clamps a point that runs off the photo' );
const zeroRect = ptkFocalPointerToFocal( { left: 0, top: 0, width: 0, height: 0 }, 10, 10 );
ok( zeroRect.x === 50 && zeroRect.y === 50, 'pointerToFocal: a zero-size (unlaid-out) rect reports center' );

// Keyboard steps, per the reference: +5 / +25 (Shift) zoom; arrow nudge is
// the picker's own concern (not this module's), but the zoom step values
// agree with sanitize_zoom's clamp behavior at the edges.
ok( ptkFocalSanitizeZoom( 100 + 5 ) === 105, 'keyboard +5 from the floor is in range' );
ok( ptkFocalSanitizeZoom( 100 + 25 ) === 125, 'keyboard Shift+ +25 from the floor is in range' );

if ( failed ) {
    console.log( 'FAILED' );
    process.exit( 1 );
} else {
    console.log( 'PASSED' );
    process.exit( 0 );
}
