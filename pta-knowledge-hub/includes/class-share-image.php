<?php
/**
 * The 1080x1080 share square Instagram wants.
 *
 * Draws two colors -- a background and a text color (4.3.0; the text color
 * is already through the contrast guard in PTK_Share_Color, checked
 * against the chosen background) -- and three pieces of type: the issue
 * number, the week, and the school's name. Nothing else -- no logo, no
 * story text, because the caption carries the words.
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

require_once dirname( __FILE__ ) . '/class-focal-point.php';

class PTK_Share_Image {

    const SIZE   = 1080;
    const GROUND = '#1a2f5c';
    const PAD    = 96;

    /**
     * 4.5.2: a photo square is two zones, never text over the photo.
     * The top PHOTO_BAND_TOP pixels show the photo clear and undarkened, as
     * a 16:9 strip (1080 x 608) -- the same 16:9 frame as the adjuster the
     * volunteer drags the dot on, so what they frame is what they get. Below
     * it, a solid band in the chosen bar color holds all the words, so the
     * text/bar contrast check is exactly what gets drawn. (4.5.0 covered the
     * whole square in a near-opaque bar; 4.5.1 left only a thin strip.)
     */
    const PHOTO_BAND_TOP = 608;

    /**
     * Keep a name on one line when it fits at this share of the maximum size.
     *
     * Measured, not picked: names that would otherwise widow ("Watchung
     * Elementary / PTA") fit on one line at 89-93% of full size, while names
     * that wrap into two good lines ("Northeast Elementary / School PTA") only
     * fit on one at 74-78%. 85% sits in the gap between them.
     */
    const ONE_LINE_RATIO = 0.85;

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
        // imagettftext() can EXIST on a GD that cannot draw type -- WordPress
        // Playground's PHP is one, and gd_info() there still claims
        // "FreeType Support". Every call then warns and draws nothing,
        // leaving a navy square with no words on it. Measuring one letter
        // in a bundled font is the only honest answer. Cached per request.
        static $freetype = null;

        $gd = function_exists( 'imagecreatetruecolor' );

        if ( null === $freetype ) {
            $freetype = false;
            if ( function_exists( 'imagettftext' ) && function_exists( 'imagettfbbox' ) ) {
                $fonts = self::font_files();
                if ( is_readable( $fonts['issue'] ) ) {
                    $prev     = error_reporting( 0 );
                    $box      = imagettfbbox( 12, 0, $fonts['issue'], 'A' );
                    error_reporting( $prev );
                    $freetype = is_array( $box );
                }
            }
        }

        return array(
            'gd'       => $gd,
            'freetype' => $freetype,
        );
    }

    /**
     * The text color actually drawn: the school's chosen text color pushed
     * until it is readable against its CHOSEN background (4.3.0 — was
     * always the fixed navy GROUND; now the background is itself a
     * setting). The guard runs here, on the RESOLVED pair, because a
     * school may pick two colors that don't contrast on their own.
     *
     * @param mixed $text       The chosen (or default) text color.
     * @param mixed $background The chosen (or default) background color.
     * @return string '#rrggbb'
     */
    public static function text_for( $text, $background ) {
        return PTK_Share_Color::readable_pair( $text, $background );
    }

    /**
     * Render the square.
     *
     * @param array      $args  issue, date, school_name, background, text.
     * @param array|null $caps  null detects for real; inject to test the
     *                          degradation path.
     * @return string|false Binary PNG, or false when it cannot be drawn.
     *                      Never fatal, never a blank image.
     */
    /**
     * The issue date, written the way our mastheads write it.
     *
     * The stored value is an ISO date because that is what the Builder saves,
     * but "2026-09-14" on a square reads like a filename. Newsletters are
     * named for the week they cover, so that is what the square says. Anything
     * that is not an ISO date is passed through untouched -- a PTA may well
     * have typed something else.
     */
    public static function dateline( $date ) {
        $date = trim( (string) $date );
        if ( '' === $date ) {
            return '';
        }
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
            return $date;
        }
        // Same Monday as the newsletter's own "Week of" headline, so the
        // square and the masthead never name different weeks.
        if ( class_exists( 'PTK_Newsletter_Data' ) ) {
            $monday = PTK_Newsletter_Data::issue_week_monday( $date );
            if ( '' !== $monday && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $monday, $mm ) ) {
                $m = $mm;
            }
        }
        $stamp = mktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
        if ( false === $stamp ) {
            return $date;
        }
        return 'Week of ' . date( 'F j', $stamp );
    }

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

        // 4.3.0: two colors, background and text, each falling back to its
        // own plain default (navy / white) rather than the Council palette
        // -- see PTK_Share_Color::square_background_color()/square_text_color(),
        // the callers of ensure_square() below.
        $background = PTK_Share_Color::normalize_hex(
            isset( $args['background'] ) && '' !== $args['background'] ? $args['background'] : self::GROUND
        );
        $text = self::text_for(
            isset( $args['text'] ) && '' !== $args['text'] ? $args['text'] : PTK_Share_Color::TEXT_FALLBACK,
            $background
        );

        // Round 3.1 (spec item 4): the PHOTO square's own text/bar pair,
        // used ONLY once a photo is actually drawn (below). Deliberately
        // NOT run through text_for()/readable_pair() -- the spec calls for
        // a plain warning when this pair is hard to read, never a silent
        // override, unlike the flat square's $text above.
        $photo_text = PTK_Share_Color::normalize_hex(
            isset( $args['photo_text'] ) && '' !== $args['photo_text'] ? $args['photo_text'] : PTK_Share_Color::PHOTO_TEXT_FALLBACK
        );
        $photo_bar = PTK_Share_Color::normalize_hex(
            isset( $args['photo_bar'] ) && '' !== $args['photo_bar'] ? $args['photo_bar'] : PTK_Share_Color::PHOTO_BAR_FALLBACK
        );

        // Nothing to say, so nothing to draw. A square with only the rules
        // on it IS the blank image this must never return.
        if ( '' === $issue && '' === $date && '' === $school ) {
            return false;
        }

        $im = imagecreatetruecolor( self::SIZE, self::SIZE );
        if ( ! $im ) {
            return false;
        }

        $bg_col = self::allocate( $im, $background );
        imagefilledrectangle( $im, 0, 0, self::SIZE - 1, self::SIZE - 1, $bg_col );

        // "Photo behind the words": a source photo cropped/composed under
        // a near-opaque bar in the chosen bar color, drawn between the flat
        // fill and the eyebrow. Never fatal -- any failure to load/crop the
        // photo (missing file, unrecognized mime, unsupported format) leaves
        // the flat fill already drawn above as the fallback, exactly as if
        // no photo_id had been given at all.
        $photo_id  = isset( $args['photo_id'] ) ? absint( $args['photo_id'] ) : 0;
        $has_photo = false;
        if ( $photo_id > 0 && function_exists( 'get_attached_file' ) ) {
            $has_photo = self::draw_square_photo( $im, $photo_id, array_merge( $args, array( 'photo_bar' => $photo_bar ) ) );
        }

        // The text actually drawn: the photo pair over a photo (its own bar
        // sits behind every line of text, so it is well-defined to check
        // for contrast against), the flat pair otherwise.
        $text_col = self::allocate( $im, $has_photo ? $photo_text : $text );
        $hairline = self::allocate( $im, self::hairline_for( $has_photo ? $photo_bar : $background ) );

        $left  = self::PAD;
        $right = self::SIZE - self::PAD;
        $width = $right - $left;

        if ( $has_photo ) {
            self::draw_photo_words( $im, $fonts, $issue, $date, $school, $text_col, $hairline );
        } else {
            // "Smaller, over a dark scrim": the numerals still dominate the
            // frame but leave more of the photo visible. Pinned ratio, not a
            // user-facing setting -- see the class docblock's "cosmetic, not
            // configurable" precedent (hairline_for()).
            $scale = 1.0;

            // Eyebrow, letterspaced, in the accent. Full-width hairline under
            // it -- a masthead rule, never a side bar.
            self::draw_tracked( $im, 'NEWSLETTER', $fonts['eyebrow'], 30 * $scale, $left, 168, $text_col, 11 );
            imagefilledrectangle( $im, $left, 210, $right, 213, $hairline );

            // The issue number, as big as it can be without touching the
            // edges. The label sits clear ABOVE the digits -- at 300pt the
            // numerals stand about 300px tall, so a label baseline anywhere
            // near the digits' baseline lands inside them.
            if ( '' !== $issue ) {
                // "042", not "42" -- the same padding as the masthead and share
                // page. The № sign is left off here: the ISSUE label above already
                // says what the number is, and the bundled font may not carry №.
                $issue = PTK_Share_Text::issue_label( $issue );
                self::draw_tracked( $im, 'ISSUE', $fonts['eyebrow'], 34 * $scale, $left, 296, $text_col, 12 );

                $issue_size = self::fit_text( $issue, $fonts['issue'], 300 * $scale, 96 * $scale, $width );
                imagettftext( $im, $issue_size, 0, $left, 640, $text_col, $fonts['issue'], $issue );
            }

            // The week, in the serif, in the accent.
            $dateline = self::dateline( $date );
            if ( '' !== $dateline ) {
                $date_size = self::fit_text( $dateline, $fonts['date'], 56 * $scale, 28, $width );
                imagettftext( $im, $date_size, 0, $left, 736, $text_col, $fonts['date'], $dateline );
            }

            // The school's name at the foot, above a second hairline, shrunk
            // to fit rather than clipped -- names run from "NE PTA" to
            // "The Parent Teacher Association of Northeast Elementary School,
            // Montclair". Both lines take ONE size (the smaller of the two)
            // so a wrapped name does not step down mid-word, and the block is
            // bottom-aligned so a one-line name sits where a two-line one ends.
            imagefilledrectangle( $im, $left, self::SIZE - 250, $right, self::SIZE - 247, $hairline );

            if ( '' !== $school ) {
                list( $lines, $size ) = self::fit_block( $school, $fonts['school'], 54 * $scale, 22 * $scale, $width, 2 );

                $line_height   = (int) round( $size * 1.24 );
                $last_baseline = self::SIZE - 110;
                $baseline      = $last_baseline - ( ( count( $lines ) - 1 ) * $line_height );

                foreach ( $lines as $line ) {
                    imagettftext( $im, $size, 0, $left, $baseline, $text_col, $fonts['school'], $line );
                    $baseline += $line_height;
                }
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
     * 4.5.2: the words of a photo square, all inside the solid band below
     * the photo (PHOTO_BAND_TOP down). One compact block: the eyebrow, the
     * issue number with the week beside it, a hairline, the school name.
     */
    private static function draw_photo_words( $im, array $fonts, $issue, $date, $school, $text_col, $hairline ) {
        $left  = self::PAD;
        $right = self::SIZE - self::PAD;
        $width = $right - $left;
        $top   = self::PHOTO_BAND_TOP;

        self::draw_tracked( $im, 'NEWSLETTER', $fonts['eyebrow'], 22, $left, $top + 82, $text_col, 9 );

        $issue_baseline = $top + 268;
        $number_right   = $left;
        if ( '' !== $issue ) {
            $issue      = PTK_Share_Text::issue_label( $issue );
            $issue_size = self::fit_text( $issue, $fonts['issue'], 150, 70, (int) ( $width * 0.55 ) );
            imagettftext( $im, $issue_size, 0, $left, $issue_baseline, $text_col, $fonts['issue'], $issue );
            $number_right = $left + self::text_width( $issue, $fonts['issue'], $issue_size ) + 44;
        }

        // The week sits beside the number, on its baseline, in the serif.
        $dateline = self::dateline( $date );
        if ( '' !== $dateline ) {
            $room      = $right - $number_right;
            $date_size = self::fit_text( $dateline, $fonts['date'], 44, 22, $room );
            imagettftext( $im, $date_size, 0, $number_right, $issue_baseline - 4, $text_col, $fonts['date'], $dateline );
        }

        imagefilledrectangle( $im, $left, $top + 318, $right, $top + 320, $hairline );

        if ( '' !== $school ) {
            $size = self::fit_text( $school, $fonts['school'], 40, 16, $width );
            imagettftext( $im, $size, 0, $left, $top + 390, $text_col, $fonts['school'], $school );
        }
    }

    /**
     * Resolve a photo attachment to a file + mime and draw it, WordPress-
     * coupled thin wrapper around draw_square_photo_from_file() (the pure,
     * testable core). Never fatal: any failure to resolve the attachment
     * is treated exactly like "no photo," leaving the flat fill already
     * drawn on $im as the fallback.
     *
     * @param resource|GdImage $im       The 1080x1080 canvas, flat fill already drawn.
     * @param int              $photo_id Attachment ID.
     * @param array            $args     photo_focal_x, photo_focal_y, photo_zoom.
     * @return bool True when a photo was actually drawn.
     */
    private static function draw_square_photo( $im, $photo_id, array $args ) {
        $path = get_attached_file( $photo_id );
        if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
            return false;
        }

        $info = @getimagesize( $path );
        if ( ! is_array( $info ) || empty( $info['mime'] ) ) {
            return false;
        }

        return self::draw_square_photo_from_file( $im, $path, $info['mime'], $args );
    }

    /**
     * The pure drawing core: load $path (already known to be $mime),
     * crop it per PTK_Focal_Point::square_crop_rect() (Part B's already-
     * tested math), draw it over the full canvas, then a light overall
     * darkening plus a near-opaque bar BAND (from PHOTO_BAND_TOP down) so
     * the text drawn afterward stays readable while the top of the photo
     * stays clearly visible. WordPress-free -- callable
     * directly from a plain-php test with a fixture file, no attachment
     * id or WordPress runtime required.
     *
     * Never fatal: an unreadable file, an unrecognized mime, a missing GD
     * loader (webp is host-variable, fact 10 of the round-3 spec), or a
     * corrupt image that fails to decode all leave $im untouched -- the
     * flat fill already drawn stands as the fallback, exactly the
     * "never fatal, never blank" contract render_png() already keeps.
     *
     * @param resource|GdImage $im   The 1080x1080 canvas, flat fill already drawn.
     * @param string           $path Readable image file path.
     * @param string           $mime image/jpeg, image/png, or image/webp.
     * @param array            $args photo_focal_x, photo_focal_y, photo_zoom.
     * @return bool True when the photo (and scrim) were actually drawn.
     */
    public static function draw_square_photo_from_file( $im, $path, $mime, array $args ) {
        switch ( $mime ) {
            case 'image/jpeg':
                $loader = 'imagecreatefromjpeg';
                break;
            case 'image/png':
                $loader = 'imagecreatefrompng';
                break;
            case 'image/webp':
                // Host-variable: only present when GD was compiled with
                // libwebp. Absence degrades to "no photo," never a fatal.
                $loader = 'imagecreatefromwebp';
                break;
            default:
                return false;
        }

        if ( ! function_exists( $loader ) ) {
            return false;
        }

        $src = @$loader( $path );
        if ( ! $src ) {
            return false;
        }

        // Phone photos are often stored sideways with an orientation flag;
        // GD ignores the flag, so apply it here.
        if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
            $exif        = @exif_read_data( $path );
            $orientation = is_array( $exif ) && isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
            $angle       = array( 3 => 180, 6 => -90, 8 => 90 );
            if ( isset( $angle[ $orientation ] ) ) {
                $rotated = imagerotate( $src, $angle[ $orientation ], 0 );
                if ( $rotated ) {
                    self::free( $src );
                    $src = $rotated;
                }
            }
        }

        $src_w = imagesx( $src );
        $src_h = imagesy( $src );
        if ( $src_w < 1 || $src_h < 1 ) {
            self::free( $src );
            return false;
        }

        list( $cx, $cy, $cw, $ch ) = PTK_Focal_Point::rect_crop(
            $src_w,
            $src_h,
            self::SIZE / self::PHOTO_BAND_TOP,
            isset( $args['photo_focal_x'] ) ? $args['photo_focal_x'] : 50,
            isset( $args['photo_focal_y'] ) ? $args['photo_focal_y'] : 50,
            isset( $args['photo_zoom'] ) ? $args['photo_zoom'] : 0
        );

        imagecopyresampled(
            $im,
            $src,
            0,
            0,
            (int) round( $cx ),
            (int) round( $cy ),
            self::SIZE,
            self::PHOTO_BAND_TOP,
            (int) round( $cw ),
            (int) round( $ch )
        );

        self::free( $src );

        // The solid band that holds the words.
        $bar = isset( $args['photo_bar'] ) && is_string( $args['photo_bar'] ) && '' !== $args['photo_bar']
            ? $args['photo_bar']
            : PTK_Share_Color::PHOTO_BAR_FALLBACK;
        imagefilledrectangle( $im, 0, self::PHOTO_BAND_TOP, self::SIZE - 1, self::SIZE - 1, self::allocate( $im, $bar ) );

        return true;
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
     * Allocate a hex color on an image, via the same normalizer the rest
     * of the share code uses.
     *
     * @return int
     */
    private static function allocate( $im, $hex ) {
        $rgb = PTK_Share_Color::to_rgb( $hex );
        return imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );
    }

    /**
     * The two rule lines' color: a fixed step off the BACKGROUND, not a
     * third stored setting (spec Part D: "an implementation detail...
     * not a new user-facing setting"). Cosmetic, not contrast-guarded --
     * it doesn't carry text. +32 per channel toward white on a dark
     * background, -32 toward black on a light one, which is exactly what
     * the old fixed pair (#1a2f5c ground / #3a4f7c hairline) already was:
     * 0x1a+32=0x3a, 0x2f+32=0x4f, 0x5c+32=0x7c.
     *
     * @param string $background Normalized '#rrggbb'.
     * @return string
     */
    public static function hairline_for( $background ) {
        list( $r, $g, $b ) = PTK_Share_Color::to_rgb( $background );
        $delta = ( PTK_Share_Color::relative_luminance( $background ) < 0.5 ) ? 32 : -32;
        return PTK_Share_Color::from_rgb( $r + $delta, $g + $delta, $b + $delta );
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

        // A name that nearly fits on one line stays on one line. Breaking
        // "Watchung Elementary PTA" to avoid a widow would just trade one
        // awkward shape for another.
        $one_line_floor = max( $min, (int) ceil( $max * self::ONE_LINE_RATIO ) );
        for ( $size = $max; $size >= $one_line_floor; $size-- ) {
            if ( self::text_width( $text, $font, $size ) <= $limit ) {
                return array( array( trim( (string) $text ) ), $size );
            }
        }

        for ( $size = $max; $size >= $min; $size-- ) {
            $lines = self::wrap_text( $text, $font, $size, $limit, $max_lines );
            if ( count( $lines ) > $max_lines ) {
                continue;
            }
            // Never a widow. wrap_text() rebalances where it can; this
            // catches the case it cannot (a two-word name split one-and-one)
            // and lets the size shrink until the name fits on one line.
            if ( self::is_widowed( $lines ) ) {
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
        $words = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $words ) {
            return array( '' );
        }

        $lines   = array();
        $current = '';
        $total   = count( $words );

        for ( $i = 0; $i < $total; $i++ ) {
            $try = ( '' === $current ) ? $words[ $i ] : $current . ' ' . $words[ $i ];

            // A single word longer than the limit still goes on the line;
            // fit_text()/fit_block() shrink it rather than clipping it.
            if ( '' === $current || self::text_width( $try, $font, $size ) <= $limit ) {
                $current = $try;
                continue;
            }

            $lines[] = $current;
            $current = $words[ $i ];

            // On the last line everything left over joins it, whatever
            // that does to its width -- again, the size shrinks to suit.
            if ( count( $lines ) === $max_lines - 1 ) {
                $current = implode( ' ', array_slice( $words, $i ) );
                break;
            }
        }

        $lines[] = $current;

        return self::rebalance_widow( $lines );
    }

    /**
     * True when the last of two or more lines holds a single word.
     *
     * @param array<int,string> $lines
     */
    public static function is_widowed( array $lines ) {
        if ( count( $lines ) < 2 ) {
            return false;
        }
        $last = preg_split( '/\s+/', trim( (string) end( $lines ) ), -1, PREG_SPLIT_NO_EMPTY );
        return count( $last ) === 1;
    }

    /**
     * Pull one word down onto a widowed last line, when the line above can
     * spare it and still keep a word of its own. "Glenfield Middle School /
     * PTA" becomes "Glenfield Middle / School PTA". The line that grows may
     * no longer fit -- fit_block() measures again and shrinks if it must.
     *
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private static function rebalance_widow( array $lines ) {
        if ( ! self::is_widowed( $lines ) ) {
            return $lines;
        }
        $n     = count( $lines );
        $above = preg_split( '/\s+/', trim( (string) $lines[ $n - 2 ] ), -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $above ) < 2 ) {
            return $lines;
        }
        $moved           = array_pop( $above );
        $lines[ $n - 2 ] = implode( ' ', $above );
        $lines[ $n - 1 ] = $moved . ' ' . trim( (string) $lines[ $n - 1 ] );
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

    /* ------------------------------------------------------------------
     * The square's life in the media library.
     *
     * This is the plugin's FIRST writer to the media library -- everything
     * else in it only reads -- so the guards here are deliberately loud.
     * ----------------------------------------------------------------*/

    /**
     * Hook the cleanup. Called from the plugin bootstrap in a later batch;
     * nothing is registered yet.
     *
     * before_delete_post ONLY, never trashed_post: the trash is restorable,
     * and deleting the square on trash would force a fresh GD render the
     * moment somebody restored the newsletter.
     *
     * @return void
     */
    public static function init() {
        add_action( 'before_delete_post', array( __CLASS__, 'delete_square_with_newsletter' ) );
    }

    /**
     * Whether the stored square needs redrawing. Pure, so the rule can be
     * read and tested on its own.
     *
     * A school's own uploaded square always wins -- however stale our hash
     * looks, we never draw over it.
     *
     * @param string $stored_hash
     * @param string $current_hash
     * @param bool   $attachment_exists
     * @param bool   $custom
     * @return bool
     */
    public static function should_regenerate( $stored_hash, $current_hash, $attachment_exists, $custom ) {
        if ( $custom ) {
            return false;
        }
        if ( ! $attachment_exists ) {
            return true;
        }
        return PTK_Share_Data::is_stale( $stored_hash, $current_hash );
    }

    /**
     * Whether before_delete_post should take the square with it. Pure.
     *
     * The post-type check is not a nicety: before_delete_post fires for
     * EVERY post type, including the attachment we are about to delete,
     * so without it the handler would re-enter on its own deletion.
     *
     * @param string|false $post_type         get_post_type() result.
     * @param int          $attachment_id
     * @param bool         $attachment_exists A volunteer may have deleted it by hand.
     * @param bool         $custom            A square the school uploaded.
     * @return bool
     */
    public static function should_clean_up( $post_type, $attachment_id, $attachment_exists, $custom ) {
        if ( 'pta_newsletter' !== $post_type ) {
            return false;
        }
        if ( ! absint( $attachment_id ) ) {
            return false;
        }
        if ( $custom ) {
            return false;
        }
        return (bool) $attachment_exists;
    }

    /**
     * A predictable, filesystem-safe name for the generated file.
     *
     * @return string
     */
    public static function attachment_filename( $post_id, $issue ) {
        $post_id = absint( $post_id );
        $issue   = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $issue );

        if ( '' === $issue ) {
            return 'share-square-' . $post_id . '.png';
        }
        return 'share-square-' . $post_id . '-' . $issue . '.png';
    }

    /**
     * The square for one newsletter: the stored one when it is still
     * right, a freshly drawn and attached one when it is not.
     *
     * NEVER call this on a public page request. It runs GD and writes to
     * the media library, so an unauthenticated GET that could reach it
     * would be a way to burn a server's CPU and fill its uploads folder.
     * The admin panel render is the only caller.
     *
     * @param int   $post_id Newsletter post.
     * @param array $args    issue, date, school_name, background, text, and optionally
     *                       version (defaults to PTK_VERSION).
     * @return int|WP_Error Attachment ID, or a WP_Error the panel turns
     *                      into "upload a square picture".
     */
    public static function ensure_square( $post_id, array $args ) {
        $post_id = absint( $post_id );

        if ( ! $post_id ) {
            return new WP_Error( 'ptk_share_square_post', __( 'That newsletter could not be found.', 'pta-knowledge-hub' ) );
        }

        if ( ! self::may_write( $post_id ) ) {
            return new WP_Error(
                'ptk_share_square_context',
                __( 'The share square is only built from the newsletter editor.', 'pta-knowledge-hub' )
            );
        }

        $square = PTK_Share_Data::get_square( $post_id );

        // A square the school uploaded is theirs. Hand it back untouched.
        if ( $square['custom'] ) {
            if ( $square['image_id'] && self::attachment_exists( $square['image_id'] ) ) {
                return $square['image_id'];
            }
            return new WP_Error(
                'ptk_share_square_custom_missing',
                __( 'The square picture this newsletter used has been deleted. Upload a new one, or switch back to the generated square.', 'pta-knowledge-hub' )
            );
        }

        $issue   = isset( $args['issue'] ) ? (string) $args['issue'] : '';
        $date    = isset( $args['date'] ) ? (string) $args['date'] : '';
        $school  = isset( $args['school_name'] ) ? (string) $args['school_name'] : '';
        $version = isset( $args['version'] ) ? (string) $args['version'] : ( defined( 'PTK_VERSION' ) ? PTK_VERSION : '0' );

        // "Photo behind the words" -- see PTK_Share_Data::get_square_photo()
        // (round 3). photo_id 0 is the default: the flat square, exactly
        // as before.
        $photo_id       = isset( $args['photo_id'] ) ? absint( $args['photo_id'] ) : 0;
        $photo_focal_x  = isset( $args['photo_focal_x'] ) ? $args['photo_focal_x'] : 50;
        $photo_focal_y  = isset( $args['photo_focal_y'] ) ? $args['photo_focal_y'] : 50;
        $photo_zoom     = isset( $args['photo_zoom'] ) ? $args['photo_zoom'] : 0;

        // Hash the colors actually DRAWN, not the ones requested -- two
        // schools whose picks both get corrected to the same readable pair
        // should not each think the other's square is stale.
        $background = PTK_Share_Color::normalize_hex(
            isset( $args['background'] ) && '' !== $args['background'] ? $args['background'] : self::GROUND
        );
        $text = self::text_for(
            isset( $args['text'] ) && '' !== $args['text'] ? $args['text'] : PTK_Share_Color::TEXT_FALLBACK,
            $background
        );

        // Round 3.1: the photo-only pair, never auto-corrected (see
        // render_png()'s matching comment).
        $photo_text = PTK_Share_Color::normalize_hex(
            isset( $args['photo_text'] ) && '' !== $args['photo_text'] ? $args['photo_text'] : PTK_Share_Color::PHOTO_TEXT_FALLBACK
        );
        $photo_bar = PTK_Share_Color::normalize_hex(
            isset( $args['photo_bar'] ) && '' !== $args['photo_bar'] ? $args['photo_bar'] : PTK_Share_Color::PHOTO_BAR_FALLBACK
        );

        // square_inputs_hash()'s signature is unit-tested and shared with
        // callers that have no photo colors at all -- append the photo pair
        // to its result rather than widening it, so a school that changes
        // only the photo text/bar color still regenerates.
        $current_hash = PTK_Share_Data::square_inputs_hash( $issue, $date, $school, $background, $text, $photo_id, $photo_focal_x, $photo_focal_y, $photo_zoom, $version )
            . '|' . $photo_text . $photo_bar;
        $exists       = $square['image_id'] && self::attachment_exists( $square['image_id'] );

        if ( ! self::should_regenerate( $square['hash'], $current_hash, $exists, false ) ) {
            return $square['image_id'];
        }

        if ( self::is_over_quota() ) {
            return new WP_Error(
                'ptk_share_square_quota',
                __( 'This site is out of upload space, so the square could not be saved. Upload a square picture instead, or ask the Council to raise the limit.', 'pta-knowledge-hub' )
            );
        }

        $png = self::render_png( array(
            'issue'          => $issue,
            'date'           => $date,
            'school_name'    => $school,
            'background'     => $background,
            'text'           => $text,
            'photo_id'       => $photo_id,
            'photo_focal_x'  => $photo_focal_x,
            'photo_focal_y'  => $photo_focal_y,
            'photo_zoom'     => $photo_zoom,
            'photo_text'     => $photo_text,
            'photo_bar'      => $photo_bar,
        ) );

        if ( ! is_string( $png ) || '' === $png ) {
            return new WP_Error(
                'ptk_share_square_unsupported',
                __( 'This website cannot draw the square picture. Upload one instead -- everything else about sharing still works.', 'pta-knowledge-hub' )
            );
        }

        $upload = wp_upload_bits( self::attachment_filename( $post_id, $issue ), null, $png );
        if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
            $message = ( is_array( $upload ) && ! empty( $upload['error'] ) )
                ? $upload['error']
                : __( 'The square picture could not be saved.', 'pta-knowledge-hub' );
            return new WP_Error( 'ptk_share_square_upload', $message );
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => 'image/png',
                'post_title'     => sprintf(
                    /* translators: %s: newsletter issue number. */
                    __( 'Share square for issue %s', 'pta-knowledge-hub' ),
                    ( '' !== $issue ) ? $issue : (string) $post_id
                ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                // So the media library's "Uploaded to" column points back
                // at the newsletter. Note this does NOT make WordPress
                // delete the file with the post: wp_delete_post()
                // REPARENTS child attachments and wp_trash_post() ignores
                // them. delete_square_with_newsletter() is the only thing
                // that actually cleans up.
                'post_parent'    => $post_id,
            ),
            $upload['file'],
            $post_id
        );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error(
                'ptk_share_square_attach',
                __( 'The square picture could not be added to the media library.', 'pta-knowledge-hub' )
            );
        }

        // Thumbnails. image.php is not loaded on every admin request, and
        // wp_generate_attachment_metadata() is fatal without it.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
        );

        // Throw away the square this one replaces, so a newsletter edited
        // ten times does not leave ten orphans in the media library.
        if ( $square['image_id'] && (int) $square['image_id'] !== (int) $attachment_id && self::attachment_exists( $square['image_id'] ) ) {
            wp_delete_attachment( $square['image_id'], true );
        }

        PTK_Share_Data::save_square( $post_id, $attachment_id, false, $current_hash );

        return (int) $attachment_id;
    }

    /**
     * Go back to the generated square after a school uploaded its own.
     *
     * Forgets the upload rather than deleting it -- that image may well be
     * in use somewhere else on the site. Clearing the id (and the stale
     * baseline hash with it) is what makes the next render draw again.
     *
     * @return void
     */
    public static function use_generated_square( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        delete_post_meta( $post_id, PTK_Share_Data::META_SQUARE_CUSTOM );
        delete_post_meta( $post_id, PTK_Share_Data::META_SQUARE_ID );
        delete_post_meta( $post_id, PTK_Share_Data::META_SQUARE_HASH );
    }

    /**
     * before_delete_post handler. Permanent deletion only.
     *
     * @return void
     */
    public static function delete_square_with_newsletter( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        // Cheapest check first: this fires for every post type on the
        // site, the attachment included.
        if ( 'pta_newsletter' !== get_post_type( $post_id ) ) {
            return;
        }

        $square = PTK_Share_Data::get_square( $post_id );

        $should = self::should_clean_up(
            'pta_newsletter',
            $square['image_id'],
            $square['image_id'] ? self::attachment_exists( $square['image_id'] ) : false,
            $square['custom']
        );

        if ( ! $should ) {
            return;
        }

        wp_delete_attachment( $square['image_id'], true );
    }

    /**
     * Is this request allowed to draw and write? Admin screens only, by
     * somebody who may edit this newsletter, and never during cron or a
     * REST read.
     *
     * @return bool
     */
    protected static function may_write( $post_id ) {
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return false;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }
        if ( ! is_admin() ) {
            return false;
        }
        return current_user_can( 'edit_post', $post_id );
    }

    /**
     * Is the attachment still really there? A volunteer may have deleted
     * it by hand from the media library.
     *
     * @return bool
     */
    protected static function attachment_exists( $attachment_id ) {
        return 'attachment' === get_post_type( absint( $attachment_id ) );
    }

    /**
     * Per-site upload quota on multisite. Over it, the panel degrades to
     * "upload a square picture" rather than failing hard.
     *
     * @return bool
     */
    protected static function is_over_quota() {
        if ( ! is_multisite() ) {
            return false;
        }
        if ( function_exists( 'upload_is_user_over_quota' ) && upload_is_user_over_quota( false ) ) {
            return true;
        }
        if ( function_exists( 'get_upload_space_available' ) ) {
            // A 1080x1080 PNG of flat color and type runs 45-60 KB; leave
            // room for the thumbnails WordPress generates alongside it.
            return get_upload_space_available() < ( 512 * 1024 );
        }
        return false;
    }
}
