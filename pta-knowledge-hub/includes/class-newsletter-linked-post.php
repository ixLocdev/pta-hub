<?php
/**
 * Round 8, Part 2 -- "Also add a short news post that links to it"
 * (default OFF, independent of Part 1).
 *
 * A fallback for listings Part 1's query-patching can't reach (see
 * docs/superpowers/specs/2026-09-17-newsletter-round8-news-listing-design.md):
 * a REAL, separate `post` -- summary + "Inside this issue" list + a button
 * back to the newsletter -- created on publish, updated in place on every
 * later save, drafted (never deleted) when the newsletter is unpublished
 * or trashed. Never a copy of the newsletter's own rendered HTML, and
 * never saved as pta_newsletter -- the Builder's design is never
 * duplicated or fought over by the theme's normal post template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-newsletter-data.php';
require_once __DIR__ . '/class-newsletter-renderer.php';
require_once __DIR__ . '/class-newsletter-email.php';
require_once __DIR__ . '/class-newsletter-seo.php';
require_once __DIR__ . '/class-newsletter-news-listing.php';
require_once __DIR__ . '/class-share-data.php';
require_once __DIR__ . '/class-share-text.php';

class PTK_Newsletter_Linked_Post {

    const OPTION = 'ptk_newsletters_linked_post';

    /** On the newsletter: the linked post's id. */
    const META_LINKED_POST_ID = '_ptk_nl_linked_post_id';

    /** On the linked post: the newsletter it came from. */
    const META_SOURCE_NEWSLETTER_ID = '_ptk_linked_source_newsletter_id';

    /** Statuses that mean "should still be a live linked post." */
    const PUBLISHABLE_STATUSES = array( 'publish' );

    /** Statuses that should draft an existing linked post. */
    const DRAFT_ON_STATUSES = array( 'draft', 'pending', 'private', 'trash', 'auto-draft' );

    private static $syncing = array();

    public static function init() {
        add_action( 'save_post_pta_newsletter', array( __CLASS__, 'sync_on_save' ), 30, 3 );
        add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
    }

    /* ------------------------------------------------------------------
     * Pure decision helpers -- unit-tested in tests/test-newsletter-linked-post.php.
     * ------------------------------------------------------------------ */

    /**
     * @param string $status A post_status.
     * @return bool
     */
    public static function is_publishable_status( $status ) {
        return in_array( $status, self::PUBLISHABLE_STATUSES, true );
    }

    /**
     * @param string $status
     * @return bool
     */
    public static function should_draft_for_status( $status ) {
        return in_array( $status, self::DRAFT_ON_STATUSES, true );
    }

    /* ------------------------------------------------------------------
     * WordPress integration.
     * ------------------------------------------------------------------ */

    public static function on() {
        return (bool) get_option( self::OPTION, false );
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
        if ( ! self::on() ) {
            return;
        }
        if ( class_exists( 'PTK_Example_Newsletter' ) && PTK_Example_Newsletter::is_example( $post_id ) ) {
            return;
        }

        self::$syncing[ $post_id ] = true;
        try {
            $status    = get_post_status( $post_id );
            $linked_id = (int) get_post_meta( $post_id, self::META_LINKED_POST_ID, true );

            if ( self::is_publishable_status( $status ) ) {
                self::create_or_update_linked_post( $post_id );
            } elseif ( $linked_id && self::should_draft_for_status( $status ) ) {
                self::draft_linked_post( $linked_id );
            }
        } finally {
            unset( self::$syncing[ $post_id ] );
        }
    }

    /**
     * Last-resort safety net for a permanently deleted newsletter -- its
     * own meta (holding the link) is about to disappear with it.
     *
     * @param int $post_id
     */
    public static function on_delete( $post_id ) {
        if ( 'pta_newsletter' !== get_post_type( $post_id ) ) {
            return;
        }
        $linked_id = (int) get_post_meta( $post_id, self::META_LINKED_POST_ID, true );
        if ( $linked_id ) {
            self::draft_linked_post( $linked_id );
        }
    }

    /**
     * @param int $newsletter_id
     */
    private static function create_or_update_linked_post( $newsletter_id ) {
        $permalink = get_permalink( $newsletter_id );
        if ( ! $permalink ) {
            return;
        }

        $blocks = PTK_Newsletter_Data::sanitize_blocks(
            json_decode( (string) get_post_meta( $newsletter_id, 'ptk_nl_blocks', true ), true )
        );
        $issue = absint( get_post_meta( $newsletter_id, 'ptk_nl_issue', true ) );
        $date  = (string) get_post_meta( $newsletter_id, 'ptk_nl_date', true );

        $title   = PTK_Newsletter_Email::subject( $blocks, array( 'issue' => $issue, 'date' => $date ) );
        $content = self::linked_content( $blocks, $issue, $date, $permalink );

        $linked_id = (int) get_post_meta( $newsletter_id, self::META_LINKED_POST_ID, true );
        $existing  = $linked_id ? get_post( $linked_id ) : null;

        $post_data = array(
            'post_type'    => 'post',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        );

        if ( $existing && 'post' === $existing->post_type ) {
            $post_data['ID'] = $existing->ID;
            $result           = wp_update_post( $post_data, true );
        } else {
            $result = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $result ) || ! $result ) {
            return;
        }

        $linked_id = (int) $result;
        update_post_meta( $newsletter_id, self::META_LINKED_POST_ID, $linked_id );
        update_post_meta( $linked_id, self::META_SOURCE_NEWSLETTER_ID, $newsletter_id );

        $cat_id = PTK_Newsletter_News_Listing::resolve_or_create_category();
        if ( $cat_id ) {
            wp_set_post_categories( $linked_id, array( $cat_id ), false );
        }

        $square       = PTK_Share_Data::get_square( $newsletter_id );
        $square_photo = PTK_Share_Data::get_square_photo( $newsletter_id );
        $choice       = PTK_Newsletter_SEO::choose_image_source( $blocks, $square, $square_photo );
        if ( $choice['id'] && 'attachment' === get_post_type( $choice['id'] ) && wp_attachment_is_image( $choice['id'] ) ) {
            set_post_thumbnail( $linked_id, $choice['id'] );
        }
    }

    /**
     * @param array  $blocks
     * @param int    $issue
     * @param string $date
     * @param string $permalink
     * @return string
     */
    private static function linked_content( array $blocks, $issue, $date, $permalink ) {
        // Render once, purely to collect the SAME section list + anchor
        // ids the published page and the GiveBacks email already use --
        // never to reuse its HTML.
        PTK_Newsletter_Renderer::render( $blocks, array(
            'issue' => $issue,
            'date'  => $date,
            'today' => $date,
        ) );
        $sections = PTK_Newsletter_Renderer::last_sections();

        $html    = '';
        $summary = self::summary_text( $blocks );
        if ( '' !== $summary ) {
            $html .= '<p>' . esc_html( $summary ) . '</p>' . "\n";
        }

        if ( ! empty( $sections ) ) {
            $html .= "<h3>Inside this issue</h3>\n<ul>\n";
            foreach ( $sections as $section ) {
                $url   = rtrim( $permalink, '/' ) . '/#' . $section['id'];
                $html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $section['headline'] ) . '</a>';
                if ( '' !== $section['teaser'] ) {
                    $html .= ' &mdash; ' . esc_html( $section['teaser'] );
                }
                $html .= "</li>\n";
            }
            $html .= "</ul>\n";
        }

        $html .= '<p><a href="' . esc_url( $permalink ) . '">Read the full newsletter &rarr;</a></p>' . "\n";

        return $html;
    }

    /**
     * @param array $blocks
     * @return string
     */
    private static function summary_text( array $blocks ) {
        foreach ( $blocks as $block ) {
            if ( isset( $block['type'] ) && PTK_Newsletter_Data::TYPE_HEADER === $block['type'] ) {
                $summary = trim( PTK_Share_Text::html_to_text( isset( $block['data']['summary'] ) ? $block['data']['summary'] : '' ) );
                if ( '' !== $summary ) {
                    return $summary;
                }
            }
        }
        return '';
    }

    /**
     * @param int $linked_id
     */
    private static function draft_linked_post( $linked_id ) {
        $post = get_post( $linked_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return;
        }
        if ( in_array( $post->post_status, array( 'draft', 'trash' ), true ) ) {
            return;
        }
        wp_update_post( array( 'ID' => $linked_id, 'post_status' => 'draft' ) );
    }
}
