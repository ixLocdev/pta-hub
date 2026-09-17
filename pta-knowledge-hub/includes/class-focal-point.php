<?php
/**
 * Focal-point-and-zoom math, shared by every photo the plugin crops.
 *
 * A focal point says which part of a photo must survive a crop, as
 * percentages of the photo's own box (0-100), straight into CSS
 * `object-position`. Zoom is a percentage where 100 is the whole photo and
 * a stored `0` means "never zoomed" (see sanitize_zoom()). WordPress-free,
 * like PTK_Newsletter_Data, so it is testable with plain `php`.
 *
 * Ported one-for-one from docs/reference/tory-focal-point/focal-point.ts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Focal_Point {

    const ZOOM_MIN = 100;
    const ZOOM_MAX = 250;

    /**
     * Whole percent 0-100. Non-numeric/NaN/absent -> 50 (center), matching
     * the reference's toPercent().
     */
    public static function clamp_percent( $value ) {
        if ( ! is_numeric( $value ) ) {
            return 50;
        }
        return (int) min( 100, max( 0, round( (float) $value ) ) );
    }

    /**
     * Clamp+round into [100,250]; <=100 or non-numeric drops to the
     * sentinel 0 -- the stored equivalent of the reference's "absent key
     * means not zoomed."
     */
    public static function sanitize_zoom( $value ) {
        if ( ! is_numeric( $value ) ) {
            return 0;
        }
        $clamped = min( self::ZOOM_MAX, max( self::ZOOM_MIN, (float) $value ) );
        return ( $clamped <= self::ZOOM_MIN ) ? 0 : (int) round( $clamped );
    }

    /** The zoom actually used when rendering: the stored sentinel (0) reads as 100. */
    public static function effective_zoom( $stored_zoom ) {
        $z = (int) $stored_zoom;
        return ( $z >= self::ZOOM_MIN ) ? $z : 100;
    }

    /** object-position value, always both parts, always clamped. */
    public static function object_position( $x, $y ) {
        return self::clamp_percent( $x ) . '% ' . self::clamp_percent( $y ) . '%';
    }

    /**
     * The extra style fragment beyond object-fit:cover;object-position:...
     * for a zoomed CSS crop -- empty string when not zoomed, matching the
     * reference's focalZoomStyle() "unzoomed returns nothing at all."
     */
    public static function css_zoom_style( $x, $y, $stored_zoom ) {
        $effective = self::effective_zoom( $stored_zoom );
        if ( $effective <= self::ZOOM_MIN ) {
            return '';
        }
        $origin = self::object_position( $x, $y );
        $scale  = round( $effective / 100, 4 );
        return 'transform:scale(' . $scale . ');transform-origin:' . $origin . ';';
    }

    /**
     * Like square_crop_rect(), but for a window of any width:height ratio
     * (4.5.2: the photo square shows the photo as a 16:9 strip above the
     * text band, matching the 16:9 adjuster the volunteer drags the dot on).
     *
     * @return array{0:float,1:float,2:float,3:float} x, y, width, height.
     */
    public static function rect_crop( $src_w, $src_h, $ratio, $focal_x, $focal_y, $stored_zoom ) {
        $src_w = max( 1.0, (float) $src_w );
        $src_h = max( 1.0, (float) $src_h );
        $ratio = max( 0.01, (float) $ratio );
        $zoom  = self::effective_zoom( $stored_zoom ) / 100;
        if ( $src_w / $src_h > $ratio ) {
            $crop_h = $src_h;
            $crop_w = $src_h * $ratio;
        } else {
            $crop_w = $src_w;
            $crop_h = $src_w / $ratio;
        }
        $crop_w /= $zoom;
        $crop_h /= $zoom;
        $avail_x = $src_w - $crop_w;
        $avail_y = $src_h - $crop_h;
        $fx      = self::clamp_percent( $focal_x ) / 100;
        $fy      = self::clamp_percent( $focal_y ) / 100;
        return array(
            max( 0.0, min( $avail_x, $avail_x * $fx ) ),
            max( 0.0, min( $avail_y, $avail_y * $fy ) ),
            $crop_w,
            $crop_h,
        );
    }

    /**
     * Square-crop rectangle in SOURCE pixels for a $dest x $dest square
     * (the Instagram square), covering $src_w x $src_h at $focal_x/$focal_y
     * (0-100) and $stored_zoom (0 or 100-250), object-fit:cover semantics.
     *
     * @return array{0:float,1:float,2:float} [crop_x, crop_y, crop_side], all in source pixels.
     */
    public static function square_crop_rect( $src_w, $src_h, $focal_x, $focal_y, $stored_zoom ) {
        $src_w      = max( 1.0, (float) $src_w );
        $src_h      = max( 1.0, (float) $src_h );
        $zoom       = self::effective_zoom( $stored_zoom ) / 100;
        $cover_side = min( $src_w, $src_h );
        $crop_side  = $cover_side / $zoom;
        $avail_x    = $src_w - $crop_side;
        $avail_y    = $src_h - $crop_side;
        $fx         = self::clamp_percent( $focal_x ) / 100;
        $fy         = self::clamp_percent( $focal_y ) / 100;
        return array(
            max( 0.0, min( $avail_x, $avail_x * $fx ) ),
            max( 0.0, min( $avail_y, $avail_y * $fy ) ),
            $crop_side,
        );
    }
}
