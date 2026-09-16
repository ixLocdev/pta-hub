<?php
/**
 * The 1080x1080 share square Instagram wants.
 *
 * Draws a navy ground, the school's share colour (already through the
 * contrast guard in PTK_Share_Color) as the accent, and three pieces of
 * type: the issue number, the week, and the school's name. Nothing else --
 * no logo, no story text, because the caption carries the words.
 *
 * Two SEPARATE capability checks, because the two failures are different:
 *   imagecreatetruecolor()  GD          -- the QR encoder needs this too
 *   imagettftext()          FreeType    -- only the square needs this
 * A host can ship GD without FreeType, and then the square is the only
 * thing that breaks.
 *
 * render_png() takes an injectable $caps because plain PHP cannot stub
 * function_exists(); without injection the degradation path would be
 * untestable. It DRAWS ONLY -- no media library access whatsoever.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Image {

    const SIZE   = 1080;
    const GROUND = '#1a2f5c';
    const PAD    = 96;

    /**
     * Where the bundled fonts live. Falls back to a path relative to this
     * file so a plain-php test harness (which defines no PTK_PLUGIN_DIR)
     * still finds them.
     *
     * @return string Directory path, trailing slash.
     */
    public static function font_dir() {
        if ( defined( 'PTK_PLUGIN_DIR' ) ) {
            return rtrim( PTK_PLUGIN_DIR, '/\\' ) . '/assets/fonts/';
        }
        return dirname( __DIR__ ) . '/assets/fonts/';
    }

    /**
     * The three faces the square draws with, by role.
     *
     * @return array<string,string>
     */
    public static function font_files() {
        $dir = self::font_dir();
        return array(
            'eyebrow' => $dir . 'LibreFranklin-Bold.ttf',
            'issue'   => $dir . 'LibreFranklin-ExtraBold.ttf',
            'date'    => $dir . 'Newsreader-Regular.ttf',
            'school'  => $dir . 'LibreFranklin-Bold.ttf',
        );
    }

    /**
     * What this server can actually do. Two booleans, never one.
     *
     * @return array{gd:bool,freetype:bool}
     */
    public static function capabilities() {
        return array(
            'gd'       => function_exists( 'imagecreatetruecolor' ),
            'freetype' => function_exists( 'imagettftext' ),
        );
    }

    /**
     * The colour actually drawn: the school's share colour pushed until it
     * is readable on the navy ground. The guard runs here, on the RESOLVED
     * colour, because several palette defaults fail on their own.
     *
     * @return string '#rrggbb'
     */
    public static function accent_for( $color ) {
        return PTK_Share_Color::readable_pair( $color, self::GROUND );
    }

    /**
     * Render the square.
     *
     * @param array      $args  issue, date, school_name, color.
     * @param array|null $caps  null detects for real; inject to test the
     *                          degradation path.
     * @return string|false Binary PNG, or false when it cannot be drawn.
     *                      Never fatal, never a blank image.
     */
    public static function render_png( array $args, $caps = null ) {
        if ( null === $caps || ! is_array( $caps ) ) {
            $caps = self::capabilities();
        }

        // Both must be present. GD alone would give us a square with no
        // type on it, which is worse than no square at all.
        if ( empty( $caps['gd'] ) || empty( $caps['freetype'] ) ) {
            return false;
        }

        $fonts = self::font_files();
        foreach ( $fonts as $path ) {
            if ( ! is_readable( $path ) ) {
                return false;
            }
        }

        $issue  = isset( $args['issue'] ) ? trim( (string) $args['issue'] ) : '';
        $date   = isset( $args['date'] ) ? trim( (string) $args['date'] ) : '';
        $school = isset( $args['school_name'] ) ? trim( (string) $args['school_name'] ) : '';
        $accent = self::accent_for( isset( $args['color'] ) ? $args['color'] : '' );

        // Nothing to say, so nothing to draw. A square with only the rules
        // on it IS the blank image this must never return.
        if ( '' === $issue && '' === $date && '' === $school ) {
            return false;
        }

        $im = imagecreatetruecolor( self::SIZE, self::SIZE );
        if ( ! $im ) {
            return false;
        }

        $ground     = self::allocate( $im, self::GROUND );
        $accent_col = self::allocate( $im, $accent );
        $white      = self::allocate( $im, '#ffffff' );
        $hairline   = self::allocate( $im, '#3a4f7c' );

        imagefilledrectangle( $im, 0, 0, self::SIZE - 1, self::SIZE - 1, $ground );

        $left  = self::PAD;
        $right = self::SIZE - self::PAD;
        $width = $right - $left;

        // Eyebrow, letterspaced, in the accent. Full-width hairline under
        // it -- a masthead rule, never a side bar.
        self::draw_tracked( $im, 'NEWSLETTER', $fonts['eyebrow'], 30, $left, 168, $accent_col, 11 );
        imagefilledrectangle( $im, $left, 210, $right, 213, $hairline );

        // The issue number, as big as it can be without touching the
        // edges. The label sits clear ABOVE the digits -- at 300pt the
        // numerals stand about 300px tall, so a label baseline anywhere
        // near the digits' baseline lands inside them.
        if ( '' !== $issue ) {
            self::draw_tracked( $im, 'ISSUE', $fonts['eyebrow'], 34, $left, 296, $white, 12 );

            $issue_size = self::fit_text( $issue, $fonts['issue'], 300, 96, $width );
            imagettftext( $im, $issue_size, 0, $left, 640, $white, $fonts['issue'], $issue );
        }

        // The week, in the serif, in the accent.
        if ( '' !== $date ) {
            $date_size = self::fit_text( $date, $fonts['date'], 56, 28, $width );
            imagettftext( $im, $date_size, 0, $left, 736, $accent_col, $fonts['date'], $date );
        }

        // The school's name at the foot, above a second hairline, shrunk
        // to fit rather than clipped -- names run from "NE PTA" to
        // "The Parent Teacher Association of Northeast Elementary School,
        // Montclair". Both lines take ONE size (the smaller of the two)
        // so a wrapped name does not step down mid-word, and the block is
        // bottom-aligned so a one-line name sits where a two-line one ends.
        imagefilledrectangle( $im, $left, self::SIZE - 250, $right, self::SIZE - 247, $hairline );

        if ( '' !== $school ) {
            list( $lines, $size ) = self::fit_block( $school, $fonts['school'], 54, 22, $width, 2 );

            $line_height   = (int) round( $size * 1.24 );
            $last_baseline = self::SIZE - 110;
            $baseline      = $last_baseline - ( ( count( $lines ) - 1 ) * $line_height );

            foreach ( $lines as $line ) {
                imagettftext( $im, $size, 0, $left, $baseline, $white, $fonts['school'], $line );
                $baseline += $line_height;
            }
        }

        ob_start();
        imagepng( $im, null, 6 );
        $png = ob_get_clean();
        self::free( $im );

        if ( ! is_string( $png ) || '' === $png ) {
            return false;
        }

        return $png;
    }

    /**
     * Free an image handle. Only meaningful on PHP 7.x -- since PHP 8.0 the
     * handle is an object freed by refcount, and PHP 8.5 deprecates the
     * call outright. The plugin still supports 7.4, where it does matter.
     *
     * @return void
     */
    private static function free( $im ) {
        if ( PHP_VERSION_ID < 80000 && function_exists( 'imagedestroy' ) ) {
            imagedestroy( $im );
        }
    }

    /* ------------------------------------------------------------------
     * Drawing helpers -- measurement and fitting, so nothing clips.
     * ----------------------------------------------------------------*/

    /**
     * Allocate a hex colour on an image, via the same normalizer the rest
     * of the share code uses.
     *
     * @return int
     */
    private static function allocate( $im, $hex ) {
        $rgb = PTK_Share_Color::to_rgb( $hex );
        return imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );
    }

    /**
     * Width in pixels of one run of text at one size.
     *
     * @return int
     */
    public static function text_width( $text, $font, $size ) {
        if ( '' === $text || ! function_exists( 'imagettfbbox' ) ) {
            return 0;
        }
        $box = imagettfbbox( $size, 0, $font, $text );
        if ( ! is_array( $box ) ) {
            return 0;
        }
        return (int) ( max( $box[2], $box[4] ) - min( $box[0], $box[6] ) );
    }

    /**
     * The largest size between $min and $max at which $text fits $limit.
     * Always returns something in range, so a name can never overflow --
     * at worst it is drawn small.
     *
     * @return int
     */
    public static function fit_text( $text, $font, $max, $min, $limit ) {
        $max = (int) $max;
        $min = (int) $min;
        if ( '' === $text ) {
            return $max;
        }
        for ( $size = $max; $size > $min; $size-- ) {
            if ( self::text_width( $text, $font, $size ) <= $limit ) {
                return $size;
            }
        }
        return $min;
    }

    /**
     * The largest size at which $text wraps into at most $max_lines that
     * each fit $limit -- and the wrap done AT that size, so the break
     * falls where it belongs rather than where a bigger size would have
     * put it. Returns the floor size with a best-effort wrap if nothing
     * fits, so a name is never clipped.
     *
     * @return array{0:array<int,string>,1:int}
     */
    public static function fit_block( $text, $font, $max, $min, $limit, $max_lines = 2 ) {
        $max = (int) $max;
        $min = (int) $min;

        for ( $size = $max; $size >= $min; $size-- ) {
            $lines = self::wrap_text( $text, $font, $size, $limit, $max_lines );
            if ( count( $lines ) > $max_lines ) {
                continue;
            }
            $fits = true;
            foreach ( $lines as $line ) {
                if ( self::text_width( $line, $font, $size ) > $limit ) {
                    $fits = false;
                    break;
                }
            }
            if ( $fits ) {
                return array( $lines, $size );
            }
        }

        return array( self::wrap_text( $text, $font, $min, $limit, $max_lines ), $min );
    }

    /**
     * Break text onto at most $max_lines lines that each fit $limit at
     * $size. The last line keeps whatever is left -- fit_text() then
     * shrinks it rather than letting it run off the edge.
     *
     * @return array<int,string>
     */
    public static function wrap_text( $text, $font, $size, $limit, $max_lines = 2 ) {
        $words = preg_split( '/\s+/', trim( $text ) );
        if ( ! $words || array( '' ) === $words ) {
            return array( '' );
        }

        $lines   = array();
        $current = '';

        foreach ( $words as $word ) {
            $try = ( '' === $current ) ? $word : $current . ' ' . $word;
            if ( self::text_width( $try, $font, $size ) <= $limit || '' === $current ) {
                $current = $try;
                continue;
            }
            $lines[] = $current;
            $current = $word;
            if ( count( $lines ) === $max_lines - 1 ) {
                break;
            }
        }

        // Anything not yet placed joins the final line.
        $placed = count( $lines );
        if ( $placed > 0 && $placed === $max_lines - 1 ) {
            $used = array();
            foreach ( $lines as $line ) {
                foreach ( explode( ' ', $line ) as $w ) {
                    $used[] = $w;
                }
            }
            $rest    = array_slice( $words, count( $used ) );
            $current = implode( ' ', $rest );
        }

        if ( '' !== $current ) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Draw letterspaced text. GD has no tracking, so the eyebrow is drawn
     * one character at a time.
     *
     * @return void
     */
    private static function draw_tracked( $im, $text, $font, $size, $x, $y, $color, $track ) {
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $chars ) {
            return;
        }
        foreach ( $chars as $char ) {
            imagettftext( $im, $size, 0, (int) $x, (int) $y, $color, $font, $char );
            $x += self::text_width( $char, $font, $size ) + $track;
            if ( ' ' === $char ) {
                $x += $size * 0.3;
            }
        }
    }
}
