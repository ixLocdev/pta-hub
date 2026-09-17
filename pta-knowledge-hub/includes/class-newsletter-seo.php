<?php
/**
 * The link-preview image and description a published newsletter hands to
 * Facebook/Twitter/iMessage-style link previews (round 3.2, Part 2).
 *
 * Two halves, deliberately kept apart:
 *
 *   1. PURE SELECTION LOGIC (choose_image_source(), choose_description()) --
 *      no WordPress calls, unit-tested with plain php the same way
 *      PTK_Share_Text is. Decides WHICH photo and WHICH sentence, never
 *      how to render them.
 *   2. WORDPRESS INTEGRATION (everything below "Integration") -- resolves
 *      the pure choice to a real attachment URL/width/height, and hands it
 *      to whichever SEO plugin is active (Rank Math, optionally Yoast), or
 *      prints our own minimal og: tags when neither is active.
 *
 * Only ever touches a SINGLE, PUBLISHED pta_newsletter post. Every entry
 * point checks that for itself -- this must never alter a preview for any
 * other post type or a draft (a draft's preview would leak to whoever
 * guesses its unpublished URL, which nothing on the site should do).
 *
 * Never prints our own og: tags when an SEO plugin is active -- checked at
 * render time (not just when hooks are registered), because plugin
 * activation/deactivation doesn't re-run this plugin's own init().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Newsletter_SEO {

    /** Roughly what Facebook/Twitter/Google show before truncating with "...". */
    const DESC_MAX = 155;

    /* ------------------------------------------------------------------
     * Pure selection logic. WordPress-free -- see tests/test-newsletter-seo.php.
     * ------------------------------------------------------------------ */

    /**
     * Which photo to use for the link-preview image, in priority order:
     * the top story's photo, else the share picture's own background
     * photo, else the generated/uploaded share picture itself.
     *
     * Never resolves to a URL here -- just which attachment id, and what
     * kind it is (a real photo needs a bigger crop size than the already
     * exactly-sized square).
     *
     * @param array $blocks       Sanitized newsletter blocks.
     * @param array $square       PTK_Share_Data::get_square()'s shape: image_id, custom.
     * @param array $square_photo PTK_Share_Data::get_square_photo()'s shape: photo_id.
     * @return array{type:string,id:int} type is 'featured'|'square_photo'|'square'|'none'.
     */
    public static function choose_image_source( array $blocks, array $square, array $square_photo ) {
        foreach ( $blocks as $block ) {
            if ( isset( $block['type'] ) && 'featured' === $block['type'] ) {
                $id = isset( $block['data']['image_id'] ) ? (int) $block['data']['image_id'] : 0;
                if ( $id > 0 ) {
                    return array( 'type' => 'featured', 'id' => $id );
                }
                break; // Only ever one featured block; nothing more to look for there.
            }
        }

        $photo_id = isset( $square_photo['photo_id'] ) ? (int) $square_photo['photo_id'] : 0;
        if ( $photo_id > 0 ) {
            return array( 'type' => 'square_photo', 'id' => $photo_id );
        }

        $square_id = isset( $square['image_id'] ) ? (int) $square['image_id'] : 0;
        if ( $square_id > 0 ) {
            return array( 'type' => 'square', 'id' => $square_id );
        }

        return array( 'type' => 'none', 'id' => 0 );
    }

    /**
     * The one-line description, in priority order: the header's one-line
     * summary, else the announcement's headline, else "Newsletter № 041 ·
     * Week of September 14" built from the existing formatters. '' means
     * "nothing to say" -- the caller leaves the SEO plugin's own default
     * alone rather than printing an empty description.
     *
     * $issue_label and $dateline are ALREADY-FORMATTED strings (from
     * PTK_Share_Text::issue_label() and PTK_Share_Image::dateline()) so
     * this stays free of both WordPress and of duplicating their date math.
     *
     * @param array  $blocks      Sanitized newsletter blocks.
     * @param string $issue_label '' or e.g. "041".
     * @param string $dateline    '' or e.g. "Week of September 14".
     * @return string Plain text, untruncated. '' if nothing to say.
     */
    public static function choose_description( array $blocks, $issue_label, $dateline ) {
        $summary = '';
        $announce_headline = '';

        foreach ( $blocks as $block ) {
            if ( ! isset( $block['type'] ) ) {
                continue;
            }
            if ( 'header' === $block['type'] && '' === $summary ) {
                $summary = trim( PTK_Share_Text::html_to_text( isset( $block['data']['summary'] ) ? $block['data']['summary'] : '' ) );
            }
            if ( 'announcement' === $block['type'] && '' === $announce_headline ) {
                $announce_headline = trim( PTK_Share_Text::html_to_text( isset( $block['data']['headline'] ) ? $block['data']['headline'] : '' ) );
            }
        }

        if ( '' !== $summary ) {
            return $summary;
        }

        if ( '' !== $announce_headline ) {
            return $announce_headline;
        }

        $issue_label = trim( (string) $issue_label );
        $dateline    = trim( (string) $dateline );

        $parts = array();
        if ( '' !== $issue_label ) {
            $parts[] = 'Newsletter № ' . $issue_label;
        }
        if ( '' !== $dateline ) {
            $parts[] = $dateline;
        }

        return implode( ' · ', $parts );
    }

    /**
     * Strip emoji-range codepoints -- same product decision, and same
     * ranges, as PTK_Share_Text::strip_emoji() (private there).
     *
     * @param string $s
     * @return string
     */
    public static function strip_emoji( $s ) {
        return preg_replace( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', (string) $s );
    }

    /**
     * Multibyte-safe truncate to roughly $max characters at a word
     * boundary, same approach as PTK_Share_Text::truncate() (private
     * there, and tuned for a longer line) -- "…", never a mid-word cut.
     *
     * @param string $s
     * @param int    $max
     * @return string
     */
    public static function truncate( $s, $max = self::DESC_MAX ) {
        $s = trim( (string) $s );
        if ( '' === $s ) {
            return '';
        }
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) <= $max : strlen( $s ) <= $max ) {
            return $s;
        }
        $cut = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
        $sp  = strrpos( $cut, ' ' );
        if ( false !== $sp ) {
            $cut = substr( $cut, 0, $sp );
        }
        return rtrim( $cut, " \t\n\r\0\x0B,;:—–-" ) . '…';
    }

    /**
     * The full pipeline, WordPress-free: description text, trimmed,
     * stripped of emoji, ready to print. '' if there is nothing to say.
     *
     * @param array  $blocks
     * @param string $issue_label
     * @param string $dateline
     * @return string
     */
    public static function build_description( array $blocks, $issue_label, $dateline ) {
        $desc = self::choose_description( $blocks, $issue_label, $dateline );
        if ( '' === $desc ) {
            return '';
        }
        return self::truncate( self::strip_emoji( $desc ), self::DESC_MAX );
    }

    /* ------------------------------------------------------------------
     * Integration. Everything below touches WordPress/the SEO plugins.
     * ------------------------------------------------------------------ */

    public static function init() {
        // Deferred to wp_loaded: Rank Math/Yoast's own classes may not
        // exist yet on plugins_loaded (this plugin's own init hook)
        // depending on plugin load order.
        add_action( 'wp_loaded', array( __CLASS__, 'register' ) );
    }

    /**
     * Register with whichever SEO plugin (if any) is active. Safe to call
     * more than once -- add_filter/add_action de-duplicate identical
     * callbacks.
     */
    public static function register() {
        if ( class_exists( 'RankMath' ) ) {
            self::register_rank_math();
        }
        if ( class_exists( 'WPSEO_Options' ) ) {
            self::register_yoast();
        }
        // The no-plugin fallback is guarded again at render time
        // (has_seo_plugin()), not just here -- see wp_head_fallback().
        add_action( 'wp_head', array( __CLASS__, 'wp_head_fallback' ), 5 );
    }

    /**
     * Is this the one context this whole class is allowed to touch: a
     * single, PUBLISHED pta_newsletter?
     *
     * @return bool
     */
    protected static function applies() {
        return is_singular( 'pta_newsletter' ) && 'publish' === get_post_status( get_queried_object_id() );
    }

    /**
     * Is a known SEO plugin active? Checked at RENDER time -- plugin
     * activation/deactivation doesn't re-run this plugin's own init(), so
     * a hook-registration-time check alone could let both this class's
     * fallback AND a freshly-activated SEO plugin print og: tags together.
     *
     * @return bool
     */
    protected static function has_seo_plugin() {
        return class_exists( 'RankMath' ) || class_exists( 'WPSEO_Options' );
    }

    /**
     * The chosen image, resolved to a real URL/width/height, for the
     * CURRENT queried newsletter. null when there is nothing to offer --
     * callers must leave the SEO plugin's own default alone in that case.
     *
     * @return array{url:string,width:int,height:int}|null
     */
    protected static function resolve_image() {
        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return null;
        }

        $blocks = PTK_Newsletter_Data::sanitize_blocks(
            json_decode( (string) get_post_meta( $post_id, 'ptk_nl_blocks', true ), true )
        );
        $square       = PTK_Share_Data::get_square( $post_id );
        $square_photo = PTK_Share_Data::get_square_photo( $post_id );

        $choice = self::choose_image_source( $blocks, $square, $square_photo );
        if ( 'none' === $choice['type'] || ! $choice['id'] ) {
            return null;
        }

        if ( 'attachment' !== get_post_type( $choice['id'] ) || ! wp_attachment_is_image( $choice['id'] ) ) {
            return null;
        }

        // The generated/uploaded share picture is already exactly the size
        // it should be shown at; a real photo gets WordPress's own "large"
        // crop rather than a possibly enormous original.
        $size = 'square' === $choice['type'] ? 'full' : 'large';
        $src  = wp_get_attachment_image_src( $choice['id'], $size );
        if ( ! $src || empty( $src[0] ) ) {
            return null;
        }

        return array(
            'id'     => $choice['id'],
            'url'    => (string) $src[0],
            'width'  => (int) $src[1],
            'height' => (int) $src[2],
        );
    }

    /**
     * The chosen description for the CURRENT queried newsletter, resolved
     * and trimmed. '' when there is nothing to say.
     *
     * @return string
     */
    protected static function resolve_description() {
        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return '';
        }

        $blocks = PTK_Newsletter_Data::sanitize_blocks(
            json_decode( (string) get_post_meta( $post_id, 'ptk_nl_blocks', true ), true )
        );

        $issue = absint( get_post_meta( $post_id, 'ptk_nl_issue', true ) );
        $date  = (string) get_post_meta( $post_id, 'ptk_nl_date', true );

        $issue_label = $issue ? PTK_Share_Text::issue_label( (string) $issue ) : '';
        $dateline    = PTK_Share_Image::dateline( $date );

        return self::build_description( $blocks, $issue_label, $dateline );
    }

    /* ---- Rank Math ---------------------------------------------------
     * Filter names verified against Rank Math's own source
     * (includes/opengraph/class-opengraph.php's tag(), whose do_filter()
     * prepends 'rank_math/': "opengraph/{network}/{og_property}"; and
     * includes/opengraph/class-image.php's add_image(), whose
     * "opengraph/{network}/image_array" filter receives/returns the whole
     * {url,width,height,id,type,alt} array before validation). Description
     * goes through includes/frontend/paper/class-paper.php's
     * get_description(), filtered by "frontend/description" -- the SAME
     * filter Rank Math's og:description falls back to via
     * fallback_description(), so one hook covers both the meta tag and
     * the OpenGraph tag. Could not be exercised against a live Rank Math
     * install from this worktree/Playground (neither has Rank Math
     * installed) -- confirm the actual printed tags on the live site.
     * ------------------------------------------------------------------ */

    protected static function register_rank_math() {
        add_filter( 'rank_math/frontend/description', array( __CLASS__, 'rank_math_description' ) );

        foreach ( array( 'facebook', 'twitter' ) as $network ) {
            add_filter( "rank_math/opengraph/{$network}/image_array", array( __CLASS__, 'rank_math_image_array' ) );
        }
        // Force a large-image Twitter card when we are handing Rank Math a
        // real photo -- a flat 1x1 "summary" card would crop our square or
        // photo into a tiny thumbnail.
        add_filter( 'rank_math/opengraph/twitter/card_type', array( __CLASS__, 'rank_math_twitter_card_type' ) );
    }

    /**
     * @param string $description Rank Math's own generated description.
     * @return string
     */
    public static function rank_math_description( $description ) {
        if ( ! self::applies() ) {
            return $description;
        }
        $ours = self::resolve_description();
        return '' !== $ours ? $ours : $description;
    }

    /**
     * @param array $attachment Rank Math's own image array, or ''/[] with
     *                          nothing chosen yet.
     * @return array
     */
    public static function rank_math_image_array( $attachment ) {
        if ( ! self::applies() ) {
            return $attachment;
        }
        $image = self::resolve_image();
        if ( ! $image ) {
            return $attachment;
        }
        return array(
            'id'     => $image['id'],
            'url'    => $image['url'],
            'width'  => $image['width'],
            'height' => $image['height'],
        );
    }

    /**
     * @param string $type Rank Math's own chosen card type.
     * @return string
     */
    public static function rank_math_twitter_card_type( $type ) {
        if ( ! self::applies() ) {
            return $type;
        }
        return self::resolve_image() ? 'summary_large_image' : $type;
    }

    /* ---- Yoast (optional parity; not the primary target) ------------- */

    protected static function register_yoast() {
        add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'yoast_opengraph_image' ) );
        add_filter( 'wpseo_metadesc', array( __CLASS__, 'yoast_description' ) );
        add_filter( 'wpseo_opengraph_desc', array( __CLASS__, 'yoast_description' ) );
    }

    public static function yoast_opengraph_image( $image_url ) {
        if ( ! self::applies() ) {
            return $image_url;
        }
        $image = self::resolve_image();
        return $image ? $image['url'] : $image_url;
    }

    public static function yoast_description( $description ) {
        if ( ! self::applies() ) {
            return $description;
        }
        $ours = self::resolve_description();
        return '' !== $ours ? $ours : $description;
    }

    /* ---- No SEO plugin: our own minimal og: tags ---------------------- */

    /**
     * Printed ONLY for a single, published pta_newsletter, and ONLY when
     * no known SEO plugin is active (checked here, at render time, not
     * just when this callback was registered).
     */
    public static function wp_head_fallback() {
        if ( self::has_seo_plugin() ) {
            return;
        }
        if ( ! self::applies() ) {
            return;
        }

        $post_id = get_queried_object_id();
        $title   = get_the_title( $post_id );
        $desc    = self::resolve_description();
        $image   = self::resolve_image();
        $url     = get_permalink( $post_id );

        echo "\n<!-- PTA Knowledge Hub: newsletter link preview -->\n";
        if ( '' !== $title ) {
            echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        }
        if ( '' !== $desc ) {
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
            echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
        if ( $url ) {
            echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        }
        echo '<meta property="og:type" content="article" />' . "\n";
        if ( $image ) {
            echo '<meta property="og:image" content="' . esc_url( $image['url'] ) . '" />' . "\n";
            if ( $image['width'] && $image['height'] ) {
                echo '<meta property="og:image:width" content="' . esc_attr( $image['width'] ) . '" />' . "\n";
                echo '<meta property="og:image:height" content="' . esc_attr( $image['height'] ) . '" />' . "\n";
            }
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        } else {
            echo '<meta name="twitter:card" content="summary" />' . "\n";
        }
    }
}
