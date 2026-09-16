<?php
/**
 * The colour a school's share square is drawn in, and the contrast guard
 * that keeps it readable.
 *
 * This is deliberately SEPARATE from PTK_Site_Colors::color_for(). That one
 * reads the NETWORK option `ptk_site_colors` and feeds the coloured owner
 * dots on every site's article list, so one school must look the same no
 * matter which site you view it from. Layering a per-site override into it
 * would break that. Here a school may pick its own share colour without
 * touching anybody else's dots.
 *
 * Resolution order in share_color():
 *   1. blog option `ptk_share_color`   -- this site's own choice
 *   2. the Council's network value     -- PTK_Site_Colors::color_for()
 *   3. the deterministic palette default (also via color_for())
 *
 * The contrast maths below is pure PHP with no WordPress in it, so it is
 * unit-tested with plain php. share_color() -- the one method that touches
 * get_option()/get_site_option() -- is a thin wrapper the tests never call.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Color {

    /** Fallback for anything unparseable. The house navy. */
    const FALLBACK = '#1a2f5c';

    /** WCAG 2.1 AA for normal text. */
    const MIN_CONTRAST = 4.5;

    /** Per-site override, stored as a blog option (not a network one). */
    const OPTION = 'ptk_share_color';

    /** Hard cap so readable_pair() always terminates. */
    const MAX_STEPS = 40;

    /** How far each step moves a channel toward black or white. */
    const STEP = 0.08;

    /**
     * Accept '#aabbcc', 'aabbcc', '#abc' or 'abc'; anything else becomes
     * the navy fallback. Never returns an empty string, never errors.
     *
     * @param mixed $value
     * @return string Normalized '#rrggbb', lowercase.
     */
    public static function normalize_hex( $value ) {
        if ( ! is_string( $value ) ) {
            return self::FALLBACK;
        }

        $hex = strtolower( ltrim( trim( $value ), '#' ) );

        if ( preg_match( '/^[0-9a-f]{3}$/', $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ( ! preg_match( '/^[0-9a-f]{6}$/', $hex ) ) {
            return self::FALLBACK;
        }

        return '#' . $hex;
    }

    /**
     * Split a colour into its three 0-255 channels.
     *
     * @param mixed $value
     * @return array{0:int,1:int,2:int}
     */
    public static function to_rgb( $value ) {
        $hex = ltrim( self::normalize_hex( $value ), '#' );
        return array(
            (int) hexdec( substr( $hex, 0, 2 ) ),
            (int) hexdec( substr( $hex, 2, 2 ) ),
            (int) hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    /**
     * Put three 0-255 channels back together, clamping out-of-range values.
     */
    public static function from_rgb( $r, $g, $b ) {
        $clamp = function ( $n ) {
            $n = (int) round( $n );
            return max( 0, min( 255, $n ) );
        };
        return sprintf( '#%02x%02x%02x', $clamp( $r ), $clamp( $g ), $clamp( $b ) );
    }

    /**
     * WCAG relative luminance (sRGB, D65).
     *
     * @param mixed $value
     * @return float 0.0 (black) .. 1.0 (white)
     */
    public static function relative_luminance( $value ) {
        list( $r, $g, $b ) = self::to_rgb( $value );

        $linear = function ( $channel ) {
            $c = $channel / 255;
            return ( $c <= 0.04045 ) ? ( $c / 12.92 ) : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        };

        return 0.2126 * $linear( $r ) + 0.7152 * $linear( $g ) + 0.0722 * $linear( $b );
    }

    /**
     * WCAG contrast ratio, 1.0 (identical) .. 21.0 (black on white).
     * Symmetric; unparseable input is read as the navy fallback.
     *
     * @return float
     */
    public static function contrast_ratio( $hex_a, $hex_b ) {
        $la = self::relative_luminance( $hex_a );
        $lb = self::relative_luminance( $hex_b );

        $light = max( $la, $lb );
        $dark  = min( $la, $lb );

        return ( $light + 0.05 ) / ( $dark + 0.05 );
    }

    /**
     * Return $color unchanged when it already clears AA against $against;
     * otherwise walk it away from $against until it does.
     *
     * Direction matters. The plan describes this as "darkens it in steps",
     * which is right for a colour sitting on white -- but the square's
     * ground is navy, and several palette defaults fail there (#475569 is
     * 1.73:1 on navy, #4338ca 1.66:1). Darkening those would walk them
     * toward black, which is only 1.61:1 against navy -- it can never
     * reach AA. So the step direction follows the background: darken on a
     * light background, lighten on a dark one. White on navy is 13.08:1,
     * black on white 21:1, so one of the two directions always succeeds.
     *
     * MAX_STEPS caps the loop so it always terminates; if the target is
     * somehow never reached (a colour asked to contrast with itself), the
     * best result seen is returned rather than the original.
     *
     * @param mixed $color
     * @param mixed $against
     * @return string Normalized '#rrggbb'.
     */
    public static function readable_pair( $color, $against ) {
        $color   = self::normalize_hex( $color );
        $against = self::normalize_hex( $against );

        if ( self::contrast_ratio( $color, $against ) >= self::MIN_CONTRAST ) {
            return $color;
        }

        // Walk away from the background: toward white on a dark ground,
        // toward black on a light one.
        $toward_white = ( self::relative_luminance( $against ) < 0.5 );

        list( $r, $g, $b ) = self::to_rgb( $color );

        $best       = $color;
        $best_ratio = self::contrast_ratio( $color, $against );

        for ( $i = 0; $i < self::MAX_STEPS; $i++ ) {
            if ( $toward_white ) {
                $r = $r + ( 255 - $r ) * self::STEP;
                $g = $g + ( 255 - $g ) * self::STEP;
                $b = $b + ( 255 - $b ) * self::STEP;
                // Floating steps toward 255 converge slowly; nudge so we
                // always arrive rather than stalling a hair short.
                $r = min( 255, $r + 1 );
                $g = min( 255, $g + 1 );
                $b = min( 255, $b + 1 );
            } else {
                $r = $r * ( 1 - self::STEP );
                $g = $g * ( 1 - self::STEP );
                $b = $b * ( 1 - self::STEP );
                $r = max( 0, $r - 1 );
                $g = max( 0, $g - 1 );
                $b = max( 0, $b - 1 );
            }

            $candidate = self::from_rgb( $r, $g, $b );
            $ratio     = self::contrast_ratio( $candidate, $against );

            if ( $ratio > $best_ratio ) {
                $best       = $candidate;
                $best_ratio = $ratio;
            }

            if ( $ratio >= self::MIN_CONTRAST ) {
                return $candidate;
            }
        }

        return $best;
    }

    // -------------------------------------------------------------
    // Thin WordPress access. Deliberately NOT called by the tests --
    // this is the only WordPress-coupled part of the class.
    // -------------------------------------------------------------

    /**
     * The share colour for one site: its own choice, else the Council's
     * value, else the deterministic palette default.
     *
     * NOT run through readable_pair() here -- callers decide what the
     * colour has to be readable against (navy ground, white text).
     *
     * @param int|null $blog_id Defaults to the current site.
     * @return string Normalized '#rrggbb'.
     */
    public static function share_color( $blog_id = null ) {
        $blog_id = ( null === $blog_id ) ? (int) get_current_blog_id() : (int) $blog_id;

        $own = get_option( self::OPTION, '' );
        if ( self::is_hex( $own ) ) {
            return self::normalize_hex( $own );
        }

        if ( class_exists( 'PTK_Site_Colors' ) ) {
            return self::normalize_hex( PTK_Site_Colors::color_for( $blog_id ) );
        }

        return self::FALLBACK;
    }

    /**
     * True only for a string that really is a colour -- so an empty or
     * junk option falls through to the Council's value instead of being
     * normalized into navy and mistaken for a deliberate choice.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_hex( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }
        return (bool) preg_match( '/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $value ) );
    }

    /**
     * The share colour already guaranteed readable against $against
     * (the navy ground, by default). This is what the square should draw
     * with -- the guard has to run on every RESOLVED colour, not only on
     * one somebody picked, because several palette defaults fail on their
     * own.
     *
     * @return string Normalized '#rrggbb'.
     */
    public static function readable_share_color( $blog_id = null, $against = self::FALLBACK ) {
        return self::readable_pair( self::share_color( $blog_id ), $against );
    }
}
