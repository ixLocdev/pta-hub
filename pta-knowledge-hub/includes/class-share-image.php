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
        $dateline = self::dateline( $date );
        if ( '' !== $dateline ) {
            $date_size = self::fit_text( $dateline, $fonts['date'], 56, 28, $width );
            imagettftext( $im, $date_size, 0, $left, 736, $accent_col, $fonts['date'], $dateline );
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
     * @param array $args    issue, date, school_name, color, and optionally
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

        // Hash the colour actually DRAWN, not the one requested -- two
        // schools whose picks both get corrected to the same readable
        // colour should not each think the other's square is stale.
        $accent = self::accent_for( isset( $args['color'] ) ? $args['color'] : '' );

        $current_hash = PTK_Share_Data::square_inputs_hash( $issue, $date, $school, $accent, $version );
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
            'issue'       => $issue,
            'date'        => $date,
            'school_name' => $school,
            'color'       => $accent,
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
            // A 1080x1080 PNG of flat colour and type runs 45-60 KB; leave
            // room for the thumbnails WordPress generates alongside it.
            return get_upload_space_available() < ( 512 * 1024 );
        }
        return false;
    }
}
