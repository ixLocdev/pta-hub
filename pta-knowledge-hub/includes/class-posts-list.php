<?php
/**
 * "Your posts" -- the Hub's own screen for the single announcements this
 * school has put on the website, in place of WordPress's own Posts list
 * (checkboxes, bulk actions, an SEO column, a trash count).
 *
 * A second door only: WordPress's own list, edit.php, is untouched and
 * still reachable -- see the quiet link at the foot. This page exists only
 * when PTK_Hub_Look::on(), the same gate PTK_Words_List::add_page() uses.
 *
 * The hard part is not the cards. `post` is WordPress's ordinary post type,
 * so a school's is already full of posts this screen did not write. Three
 * kinds, told apart by kind() below and handled differently -- see the
 * 2026-09-21 spec, "What the list actually contains":
 *
 *   ours        carries the writing screen's parts. Opens here.
 *   wordpress   no parts: every legacy post, every newsletter pasted into
 *               one, every "11/4 | BAKE SALE". Listed, and said to be
 *               written in WordPress, because a volunteer who wrote
 *               something last year should find it where their posts are.
 *               It opens in WordPress: there are no parts to load, and
 *               opening it here would offer to replace real writing with
 *               an empty form.
 *   newsletter  made by publishing a newsletter. Not listed at all --
 *               nobody wrote it, the newsletter maintains it, editing it
 *               here would be overwritten, and it is already represented
 *               on "Your newsletters".
 *
 * Split the same way as PTK_Newsletters_List: the wording lives in
 * PTK_Post_Copy and the decisions below are pure, so
 * tests/test-posts-list.php covers them without WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Posts_List {

    /** This screen's own page slug -- a real admin page, not a list-table view. */
    const PAGE_SLUG = 'ptk-posts';

    /** How many posts one screenful holds. A school writes a handful a year. */
    const PER_PAGE = 100;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
    }

    /* ------------------------------------------------------------------
     * Pure: which kind of post is this, and what may be done with it.
     * ----------------------------------------------------------------*/

    /**
     * Which of the three kinds a post is.
     *
     * The newsletter's own posts win over everything: a post the newsletter
     * maintains is not ours to offer even if it somehow carries parts,
     * because the next newsletter save rewrites it.
     *
     * @param mixed $parts        The parts meta as read (array, '' or null).
     * @param mixed $newsletter_id The source-newsletter meta as read.
     * @return string 'ours' | 'wordpress' | 'newsletter'
     */
    public static function kind( $parts, $newsletter_id ) {
        if ( ! empty( $newsletter_id ) ) {
            return 'newsletter';
        }
        return empty( $parts ) ? 'wordpress' : 'ours';
    }

    /** Pure: may this kind be opened on the writing screen? */
    public static function opens_here( $kind ) {
        return 'ours' === $kind;
    }

    /** Pure: does this kind appear on the screen at all? */
    public static function listed( $kind ) {
        return 'newsletter' !== $kind;
    }

    /* ------------------------------------------------------------------
     * WordPress-facing.
     * ----------------------------------------------------------------*/

    /** Canonical admin URL of this screen. Same edit.php submenu shape as the others. */
    public static function url() {
        return admin_url( 'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG );
    }

    /**
     * Register this screen -- only while the new look is on. With it off,
     * this never runs: no menu entry, no page, nothing registered.
     */
    public static function add_page() {
        if ( ! class_exists( 'PTK_Hub_Look' ) || ! PTK_Hub_Look::on() ) {
            return;
        }
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            PTK_Post_Copy::list_title(),
            PTK_Post_Copy::list_title(),
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /** Shape one WP_Post into the flat array the card renderer needs. */
    private static function shape_post( $post ) {
        $id   = $post->ID;
        $kind = self::kind(
            get_post_meta( $id, PTK_Post_Writer::PARTS_META, true ),
            get_post_meta( $id, PTK_Newsletter_Linked_Post::META_SOURCE_NEWSLETTER_ID, true )
        );

        $is_up = ( 'publish' === $post->post_status );

        return array(
            'id'       => $id,
            'kind'     => $kind,
            'headline' => get_the_title( $post ),
            'summary'  => (string) $post->post_excerpt,
            'is_up'    => $is_up,
            'when'     => get_the_date( 'F j, Y', $post ),
            'can_edit' => current_user_can( 'edit_post', $id ),
            'open_url' => self::opens_here( $kind )
                ? add_query_arg( 'ptk_post_edit_id', $id, PTK_Post_Writer::url() )
                : (string) get_edit_post_link( $id, '' ),
            'view_url' => $is_up ? get_permalink( $id ) : (string) get_preview_post_link( $id ),
        );
    }

    /**
     * Every post the current user may see, newest first, minus the ones a
     * newsletter made.
     *
     * @return array Shaped posts (see shape_post()).
     */
    public static function get_posts_for_screen() {
        $query = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'perm'           => 'readable',
            'posts_per_page' => self::PER_PAGE,
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $out = array();
        foreach ( $query->posts as $post ) {
            $shaped = self::shape_post( $post );
            if ( self::listed( $shaped['kind'] ) ) {
                $out[] = $shaped;
            }
        }
        return $out;
    }

    /** Render the screen. */
    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( "You don't have permission to see this.", 'Not allowed', array( 'response' => 403 ) );
        }

        $posts   = self::get_posts_for_screen();
        $add_url = PTK_Post_Writer::url();

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( PTK_Post_Copy::list_title(), PTK_Post_Copy::list_lead() );
        echo '<p>' . PTK_Hub_UI::primary_button( PTK_Post_Copy::list_add_button(), $add_url ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped by PTK_Hub_UI.

        if ( empty( $posts ) ) {
            echo self::render_empty( $add_url ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
        } else {
            echo '<div class="ptk-written-list">';
            foreach ( $posts as $post ) {
                echo self::render_card( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
            }
            echo '</div>';
        }

        echo PTK_Hub_UI::quiet_links( array(
            array(
                'label' => 'See all of them the WordPress way',
                'url'   => admin_url( 'edit.php' ),
            ),
        ) );

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    /** Nothing here yet, said in the positive, with the way to start. */
    private static function render_empty( $add_url ) {
        $empty = PTK_Post_Copy::empty_state();

        $out  = '<div class="ptk-empty">';
        $out .= '<p class="ptk-card-title">' . esc_html( $empty['title'] ) . '</p>';
        $out .= '<p class="ptk-help">' . esc_html( $empty['text'] ) . '</p>';
        $out .= '<p>' . PTK_Hub_UI::primary_button( PTK_Post_Copy::list_add_button(), $add_url ) . '</p>';
        $out .= '</div>';
        return $out;
    }

    /** One card for a shaped post (see shape_post()). */
    private static function render_card( array $post ) {
        $out  = '<article class="ptk-entry-card" data-ptk-key="' . esc_attr( $post['id'] ) . '" data-ptk-kind="' . esc_attr( $post['kind'] ) . '">';
        $out .= '<h2 class="ptk-entry-question">' . esc_html( $post['headline'] ) . '</h2>';

        if ( '' !== $post['summary'] ) {
            $out .= '<p class="ptk-entry-answer">' . esc_html( $post['summary'] ) . '</p>';
        }

        $out .= '<p class="ptk-entry-meta">' . esc_html( self::meta_line( $post ) ) . '</p>';

        $out .= '<div class="ptk-entry-actions">';
        if ( $post['can_edit'] && '' !== $post['open_url'] ) {
            $label = self::opens_here( $post['kind'] )
                ? PTK_Post_Copy::card_open()
                : PTK_Post_Copy::card_open_wordpress();
            $out .= '<a class="ptk-btn" href="' . esc_url( $post['open_url'] ) . '">' . esc_html( $label ) . '</a>';
        }
        if ( '' !== $post['view_url'] ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $post['view_url'] ) . '" target="_blank" rel="noopener">'
                . esc_html( PTK_Post_Copy::card_view() ) . '</a>';
        }
        $out .= '</div>';

        $out .= '</article>';
        return $out;
    }

    /**
     * The one quiet line under a card: when it went up, or that it has not
     * yet, and -- for a post this screen did not write -- why it opens
     * somewhere else.
     */
    private static function meta_line( array $post ) {
        $line = $post['is_up'] ? $post['when'] : PTK_Post_Copy::kept_private();

        if ( ! self::opens_here( $post['kind'] ) ) {
            // An em dash, not a bare space: two sentences run together read
            // as one muddled one. (A CSS `content: " · "` would collapse
            // its spaces, which is why the separator lives in the text.)
            $line .= ' — ' . PTK_Post_Copy::card_wordpress();
        }

        return $line;
    }
}
