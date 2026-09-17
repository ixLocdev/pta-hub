// Focal-point-and-zoom math, the JS mirror of includes/class-focal-point.php.
//
// Plain global functions (no ES module syntax) -- matches
// newsletter-relabel.js's pattern exactly: top-level `function` declarations
// so this loads as a plain enqueued script in the browser, plus a
// module.exports guard at the bottom so it also `require()`s under node for
// unit testing. Ported one-for-one from
// docs/reference/tory-focal-point/focal-point.ts and pinned to agree with
// PTK_Focal_Point number-for-number (tests/test-focal-point-js.mjs and
// tests/test-focal-point.php assert the same cases).

var PTK_FOCAL_ZOOM_MIN = 100;
var PTK_FOCAL_ZOOM_MAX = 250;

/** Whole percent 0-100. Non-numeric/NaN/absent -> 50 (center). */
function ptkFocalClampPercent( value ) {
    var n = parseFloat( value );
    if ( typeof value === 'string' && '' === value.replace( /\s/g, '' ) ) {
        return 50;
    }
    if ( isNaN( n ) || ! isFinite( n ) ) {
        return 50;
    }
    return Math.min( 100, Math.max( 0, Math.round( n ) ) );
}

/**
 * Clamp+round into [100,250]; <=100 or non-numeric drops to the sentinel 0
 * ("not zoomed" -- the stored equivalent of the reference's "absent key").
 */
function ptkFocalSanitizeZoom( value ) {
    var n = parseFloat( value );
    if ( typeof value === 'string' && '' === value.replace( /\s/g, '' ) ) {
        return 0;
    }
    if ( isNaN( n ) || ! isFinite( n ) ) {
        return 0;
    }
    var clamped = Math.min( PTK_FOCAL_ZOOM_MAX, Math.max( PTK_FOCAL_ZOOM_MIN, n ) );
    return ( clamped <= PTK_FOCAL_ZOOM_MIN ) ? 0 : Math.round( clamped );
}

/** The zoom actually used when rendering: the stored sentinel (0) reads as 100. */
function ptkFocalEffectiveZoom( storedZoom ) {
    var z = parseInt( storedZoom, 10 ) || 0;
    return ( z >= PTK_FOCAL_ZOOM_MIN ) ? z : 100;
}

/**
 * Turn a pointer position into the focal point of the rect it landed in.
 * Clamped, so dragging past an edge pins the focal there instead of running
 * off the picture; centered on a zero-size rect (not yet laid out).
 */
function ptkFocalPointerToFocal( rect, clientX, clientY ) {
    if ( ! rect || ! rect.width || ! rect.height ) {
        return { x: 50, y: 50 };
    }
    return {
        x: ptkFocalClampPercent( ( ( clientX - rect.left ) / rect.width ) * 100 ),
        y: ptkFocalClampPercent( ( ( clientY - rect.top ) / rect.height ) * 100 )
    };
}

/** object-position value, always both parts, always clamped. */
function ptkFocalObjectPosition( x, y ) {
    return ptkFocalClampPercent( x ) + '% ' + ptkFocalClampPercent( y ) + '%';
}

/**
 * The extra style fragment beyond object-fit:cover;object-position:... for
 * a zoomed CSS crop -- empty string when not zoomed.
 */
function ptkFocalCssZoomStyle( x, y, storedZoom ) {
    var effective = ptkFocalEffectiveZoom( storedZoom );
    if ( effective <= PTK_FOCAL_ZOOM_MIN ) {
        return '';
    }
    var origin = ptkFocalObjectPosition( x, y );
    var scale = Math.round( ( effective / 100 ) * 10000 ) / 10000;
    return 'transform:scale(' + scale + ');transform-origin:' + origin + ';';
}

/**
 * The crop-frame rectangle, in IMAGE pixels, for a $ratio window over an
 * $imgW x $imgH photo at focal $fx/$fy (0-100) and $storedZoom -- the JS
 * twin of PTK_Focal_Point::rect_crop(), number-for-number (see
 * tests/test-focal-point-js.mjs and tests/test-focal-point.php). Used by
 * the round-3.1 picker to draw a crop frame directly over the WHOLE photo
 * instead of relying on object-fit:cover to hide the rest.
 *
 * @return {{x:number,y:number,w:number,h:number}}
 */
function ptkFocalCropRect( imgW, imgH, ratio, fx, fy, storedZoom ) {
    var srcW = Math.max( 1, parseFloat( imgW ) || 1 );
    var srcH = Math.max( 1, parseFloat( imgH ) || 1 );
    var r = Math.max( 0.01, parseFloat( ratio ) || 1 );
    var zoom = ptkFocalEffectiveZoom( storedZoom ) / 100;
    var cropW, cropH;
    if ( srcW / srcH > r ) {
        cropH = srcH;
        cropW = srcH * r;
    } else {
        cropW = srcW;
        cropH = srcW / r;
    }
    cropW /= zoom;
    cropH /= zoom;
    var availX = srcW - cropW;
    var availY = srcH - cropH;
    var pctX = ptkFocalClampPercent( fx ) / 100;
    var pctY = ptkFocalClampPercent( fy ) / 100;
    return {
        x: Math.max( 0, Math.min( availX, availX * pctX ) ),
        y: Math.max( 0, Math.min( availY, availY * pctY ) ),
        w: cropW,
        h: cropH
    };
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = {
        ptkFocalClampPercent: ptkFocalClampPercent,
        ptkFocalSanitizeZoom: ptkFocalSanitizeZoom,
        ptkFocalEffectiveZoom: ptkFocalEffectiveZoom,
        ptkFocalPointerToFocal: ptkFocalPointerToFocal,
        ptkFocalObjectPosition: ptkFocalObjectPosition,
        ptkFocalCssZoomStyle: ptkFocalCssZoomStyle,
        ptkFocalCropRect: ptkFocalCropRect
    };
}
