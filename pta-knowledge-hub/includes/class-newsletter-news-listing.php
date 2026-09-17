<?php
/**
 * Round 8, Part 1 -- "Show newsletters with your news posts" (default OFF).
 *
 * No duplicate content: a published newsletter stays exactly one page at
 * /newsletters/<slug>/. This class only teaches the SITE'S OWN post
 * listings -- the main WordPress query (blog home, date/author archives,
 * the "Newsletter" category archive, the main RSS feed) and the two
 * page-builder "Posts" modules this council's sites actually use -- to
 * include pta_newsletter alongside post wherever they would otherwise
 * list only post. See docs/superpowers/specs/2026-09-17-newsletter-round8-
 * news-listing-design.md for the full design and the page-builder
 * coverage table (which modules are covered, and why).
 *
 * The excerpt/thumbnail syncing in this file runs on every save
 * REGARDLESS of the setting -- it's the "so a listing looks decent the
 * moment the setting is turned on" half, cheap and inert until something
 * actually lists a newsletter next to posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-newsletter-data.php';
require_once __DIR__ . '/class-newsletter-seo.php';
require_once __DIR__ . '/class-share-data.php';

class PTK_Newsletter_News_Listing {

    const OPTION = 'ptk_newsletters_in_news';

    /** Records the attachment id THIS class set as the thumbnail, so a later hand-picked thumbnail is never overwritten. */
    const META_AUTO_THUMB = '_ptk_nl_auto_thumbnail_id';

    /** Guards against save_post_pta_newsletter re-entering itself via wp_update_post()/set_post_thumbnail(). */
    private static $syncing = array();

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 20 );
        add_action( 'pre_get_posts', array( __CLASS__, 'maybe_include_in_main_query' ) );
        add_filter( 'fl_builder_loop_query_args', array( __CLASS__, 'maybe_include_in_builder_query' ) );
        add_filter( 'uabb_blog_posts_query_args', array( __CLASS__, 'maybe_include_in_builder_query' ) );
        add_action( 'save_post_pta_newsletter', array( __CLASS__, 'sync_on_save' ), 20, 3 );
    }

    /* ------------------------------------------------------------------
     * Pure decision helpers -- no WordPress calls, unit-tested in
     * tests/test-newsletter-news-listing.php.
     * ------------------------------------------------------------------ */

    /**
     * @param mixed $pt A WP_Query 'post_type' query var value.
     * @return bool
     */
    public static function post_type_is_post_or_empty( $pt ) {
        if ( '' === $pt || null === $pt ) {
            return true;
        }
        if ( 'post' === $pt ) {
            return true;
        }
        return is_array( $pt ) && array( 'post' ) === array_values( $pt );
    }

    /**
     * @param mixed $slug
     * @return bool
     */
    public static function slug_is_newsletter_category( $slug ) {
        $slug = is_string( $slug ) ? strtolower( trim( $slug ) ) : '';
        return in_array( $slug, array( 'newsletter', 'newsletters' ), true );
    }

    /**
     * The pre_get_posts decision, given already-resolved booleans so this
     * stays testable without a real WP_Query. See the class docblock and
     * the spec's "Search: a deliberate non-change" for why is_search is
     * excluded on purpose.
     *
     * @param array $ctx {
     *     @type bool   $setting_on
     *     @type bool   $is_admin
     *     @type bool   $is_main_query
     *     @type mixed  $post_type       Raw query var.
     *     @type bool   $is_home
     *     @type bool   $is_date
     *     @type bool   $is_author
     *     @type bool   $is_newsletter_category
     *     @type bool   $is_feed
     *     @type bool   $is_singular
     *     @type bool   $is_search
     * }
     * @return bool
     */
    public static function should_include_main_query( array $ctx ) {
        $g = function ( $key ) use ( $ctx ) {
            return ! empty( $ctx[ $key ] );
        };

        if ( ! $g( 'setting_on' ) || $g( 'is_admin' ) || ! $g( 'is_main_query' ) ) {
            return false;
        }
        if ( ! self::post_type_is_post_or_empty( isset( $ctx['post_type'] ) ? $ctx['post_type'] : '' ) ) {
            return false;
        }
        if ( $g( 'is_search' ) ) {
            return false;
        }

        if ( $g( 'is_home' ) || $g( 'is_date' ) || $g( 'is_author' ) || $g( 'is_newsletter_category' ) ) {
            return true;
        }

        // A main feed request that isn't otherwise categorized above (and
        // isn't a singular post's own comment feed) -- catches /feed/ on a
        // site whose reading settings put is_home() somewhere unexpected.
        return $g( 'is_feed' ) && ! $g( 'is_singular' );
    }

    /**
     * @param mixed $post_type_value The module's own configured post_type arg.
     * @return bool
     */
    public static function should_include_builder_query( $post_type_value ) {
        return 'post' === $post_type_value;
    }

    /**
     * @param int $current_thumb_id
     * @param int $auto_prev_id      What this class set last time (0 = never/unknown).
     * @param int $candidate_id      The freshly-chosen candidate (0 = none available).
     * @return string 'set' | 'clear' | 'leave'
     */
    public static function should_auto_update_thumbnail( $current_thumb_id, $auto_prev_id, $candidate_id ) {
        $current   = (int) $current_thumb_id;
        $auto_prev = (int) $auto_prev_id;
        $candidate = (int) $candidate_id;

        $manual = $current > 0 && $current !== $auto_prev;
        if ( $manual ) {
            return 'leave';
        }

        if ( $candidate > 0 ) {
            return $candidate === $current ? 'leave' : 'set';
        }

        return $current > 0 ? 'clear' : 'leave';
    }

    /**
     * @param array $existing List of {term_id,name,slug} for the site's categories.
     * @return int Existing term_id, or 0 meaning "create one."
     */
    public static function resolve_category_choice( array $existing ) {
        foreach ( $existing as $term ) {
            $name = isset( $term['name'] ) ? strtolower( trim( (string) $term['name'] ) ) : '';
            $slug = isset( $term['slug'] ) ? strtolower( trim( (string) $term['slug'] ) ) : '';
            if ( in_array( $name, array( 'newsletter', 'newsletters' ), true )
                || in_array( $slug, array( 'newsletter', 'newsletters' ), true ) ) {
                return isset( $term['term_id'] ) ? (int) $term['term_id'] : 0;
            }
        }
        return 0;
    }

    /* ------------------------------------------------------------------
     * WordPress integration.
     * ------------------------------------------------------------------ */

    public static function on() {
        return (bool) get_option( self::OPTION, false );
    }

    /**
     * Registered unconditionally -- see class docblock. No public effect
     * by itself.
     */
    public static function register_taxonomy() {
        register_taxonomy_for_object_type( 'category', 'pta_newsletter' );
    }

    /**
     * @param WP_Query $query
     */
    public static function maybe_include_in_main_query( $query ) {
        if ( ! is_object( $query ) || ! method_exists( $query, 'is_main_query' ) ) {
            return;
        }

        $ctx = array(
            'setting_on'             => self::on(),
            'is_admin'               => is_admin(),
            'is_main_query'          => $query->is_main_query(),
            'post_type'              => $query->get( 'post_type' ),
            'is_home'                => $query->is_home(),
            'is_date'                => $query->is_date(),
            'is_author'              => $query->is_author(),
            'is_newsletter_category' => self::is_newsletter_category_query( $query ),
            'is_feed'                => $query->is_feed(),
            'is_singular'            => $query->is_singular(),
            'is_search'              => $query->is_search(),
        );

        if ( self::should_include_main_query( $ctx ) ) {
            $query->set( 'post_type', array( 'post', 'pta_newsletter' ) );
        }
    }

    /**
     * @param WP_Query $query
     * @return bool
     */
    private static function is_newsletter_category_query( $query ) {
        if ( ! $query->is_category() ) {
            return false;
        }

        $slug = $query->get( 'category_name' );
        if ( $slug && self::slug_is_newsletter_category( $slug ) ) {
            return true;
        }

        $cat_id = (int) $query->get( 'cat' );
        if ( $cat_id ) {
            $term = get_category( $cat_id );
            if ( $term && ! is_wp_error( $term ) && self::slug_is_newsletter_category( $term->slug ) ) {
                return true;
            }
        }

        $ids = $query->get( 'category__in' );
        foreach ( (array) $ids as $id ) {
            $term = get_category( (int) $id );
            if ( $term && ! is_wp_error( $term ) && self::slug_is_newsletter_category( $term->slug ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $args
     * @return mixed
     */
    public static function maybe_include_in_builder_query( $args ) {
        if ( ! is_array( $args ) || ! self::on() ) {
            return $args;
        }
        $pt = isset( $args['post_type'] ) ? $args['post_type'] : '';
        if ( self::should_include_builder_query( $pt ) ) {
            $args['post_type'] = array( 'post', 'pta_newsletter' );
        }
        return $args;
    }

    /**
     * Find (by slug or name) or create the "Newsletter" category. Public
     * so PTK_Newsletter_Linked_Post uses the SAME category, never a
     * second one with a slightly different name.
     *
     * @return int 0 on failure.
     */
    public static function resolve_or_create_category() {
        $terms = get_categories( array( 'hide_empty' => false, 'number' => 0 ) );
        $existing = array();
        foreach ( (array) $terms as $term ) {
            if ( is_object( $term ) ) {
                $existing[] = array( 'term_id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug );
            }
        }

        $found = self::resolve_category_choice( $existing );
        if ( $found ) {
            return $found;
        }

        $created = wp_insert_term( 'Newsletter', 'category' );
        if ( is_wp_error( $created ) ) {
            $data = $created->get_error_data( 'term_exists' );
            return $data ? (int) $data : 0;
        }
        return isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
    }

    /**
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update
     */
    public static function sync_on_save( $post_id, $post, $update ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! empty( self::$syncing[ $post_id ] ) ) {
            return;
        }
        if ( ! $post || 'pta_newsletter' !== $post->post_type ) {
            return;
        }

        self::$syncing[ $post_id ] = true;
        try {
            $blocks = PTK_Newsletter_Data::sanitize_blocks(
                json_decode( (string) get_post_meta( $post_id, 'ptk_nl_blocks', true ), true )
            );

            self::sync_excerpt_and_thumbnail( $post_id, $blocks );

            if ( self::on() && 'publish' === get_post_status( $post_id ) ) {
                self::assign_category( $post_id );
            }
        } finally {
            unset( self::$syncing[ $post_id ] );
        }
    }

    /**
     * @param int   $post_id
     * @param array $blocks
     */
    private static function sync_excerpt_and_thumbnail( $post_id, array $blocks ) {
        $issue       = absint( get_post_meta( $post_id, 'ptk_nl_issue', true ) );
        $date        = (string) get_post_meta( $post_id, 'ptk_nl_date', true );
        $issue_label = $issue ? PTK_Share_Text::issue_label( (string) $issue ) : '';
        $dateline    = PTK_Share_Image::dateline( $date );
        $excerpt     = PTK_Newsletter_SEO::build_description( $blocks, $issue_label, $dateline );

        $post = get_post( $post_id );
        if ( $post && $post->post_excerpt !== $excerpt ) {
            wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => $excerpt ) );
        }

        $square       = PTK_Share_Data::get_square( $post_id );
        $square_photo = PTK_Share_Data::get_square_photo( $post_id );
        $choice       = PTK_Newsletter_SEO::choose_image_source( $blocks, $square, $square_photo );

        $candidate = 0;
        if ( $choice['id'] && 'attachment' === get_post_type( $choice['id'] ) && wp_attachment_is_image( $choice['id'] ) ) {
            $candidate = (int) $choice['id'];
        }

        $current_thumb = (int) get_post_thumbnail_id( $post_id );
        $auto_prev     = (int) get_post_meta( $post_id, self::META_AUTO_THUMB, true );
        $action        = self::should_auto_update_thumbnail( $current_thumb, $auto_prev, $candidate );

        if ( 'set' === $action ) {
            set_post_thumbnail( $post_id, $candidate );
            update_post_meta( $post_id, self::META_AUTO_THUMB, $candidate );
        } elseif ( 'clear' === $action ) {
            delete_post_thumbnail( $post_id );
            delete_post_meta( $post_id, self::META_AUTO_THUMB );
        }
    }

    /**
     * @param int $post_id
     */
    private static function assign_category( $post_id ) {
        $term_id = self::resolve_or_create_category();
        if ( ! $term_id ) {
            return;
        }
        $current = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
        if ( ! in_array( $term_id, $current, true ) ) {
            wp_set_post_categories( $post_id, array_merge( $current, array( $term_id ) ), true );
        }
    }
}
