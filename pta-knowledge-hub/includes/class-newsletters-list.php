<?php
/**
 * "Your newsletters" -- the Hub's own screen for the newsletters this
 * school has written (pta_newsletter posts), replacing WordPress's own
 * "All Newsletters" list table -- Bulk actions, a Rank Math filter, an
 * "SEO Details" column ("Keyword: Not Set", "Schema: Off"), Author
 * "Webmaster", and status links including "Trash (3)" -- as the place a
 * volunteer actually works.
 *
 * A second door only: WordPress's own list, edit.php?post_type=pta_newsletter,
 * is untouched and still reachable -- see the quiet link at the foot. This
 * page exists only when PTK_Hub_Look::on() -- see add_page() -- the same
 * gate PTK_Words_List::add_page() uses.
 *
 * A newsletter title is already opened for editing through the Newsletter
 * Builder, never the block editor (see PTK_Newsletter_Builder), so this
 * screen only ever LISTS -- it never edits, publishes, or removes a
 * newsletter itself. Removing one is not part of this screen; WordPress's
 * own list still has it.
 *
 * Split the same way as PTK_Words_List: pure copy lives in
 * PTK_Newsletters_Copy; this class is WordPress-facing layout only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Newsletters_List {

    /** This screen's own page slug -- a real admin page, not a list-table view. */
    const PAGE_SLUG = 'ptk-newsletters';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
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
            'Your newsletters',
            'Your newsletters',
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /**
     * The header block's own headline, if this newsletter has one --
     * that's the masthead line families actually read at the top of the
     * issue, not the internal "Newsletter No. 41 -- ..." title.
     *
     * @param int $post_id
     * @return string
     */
    private static function block_headline( $post_id ) {
        $raw = get_post_meta( $post_id, 'ptk_nl_blocks', true );
        if ( '' === $raw || ! is_string( $raw ) ) {
            return '';
        }
        $blocks = json_decode( $raw, true );
        if ( ! is_array( $blocks ) ) {
            return '';
        }
        foreach ( $blocks as $block ) {
            if ( isset( $block['type'], $block['data']['headline'] ) && 'header' === $block['type'] ) {
                return trim( (string) $block['data']['headline'] );
            }
        }
        return '';
    }

    /** Shape one WP_Post (a newsletter) into the flat array the card renderer needs. */
    private static function shape_newsletter( $post ) {
        $id      = $post->ID;
        $headline = self::block_headline( $id );
        if ( '' === $headline ) {
            $headline = get_the_title( $post );
        }

        $issue = get_post_meta( $id, 'ptk_nl_issue', true );
        $date  = get_post_meta( $id, 'ptk_nl_date', true );

        $is_sent = ( 'publish' === $post->post_status );

        // The example newsletter the plugin writes for a new school looks
        // exactly like a real one here, because it shows its own headline
        // rather than the title that says "do not publish". Say what it is.
        $is_example = class_exists( 'PTK_Example_Newsletter' ) && PTK_Example_Newsletter::is_example( $id );

        return array(
            'id'         => $id,
            'is_example' => $is_example,
            'headline'   => $headline,
            'issue_line' => PTK_Newsletters_Copy::issue_line( $issue, $date ),
            'is_sent'    => $is_sent,
            'can_edit'   => current_user_can( 'edit_post', $id ),
            'edit_url'   => class_exists( 'PTK_Newsletter_Builder' ) ? add_query_arg( 'ptk_nl_edit_id', $id, PTK_Newsletter_Builder::url() ) : get_edit_post_link( $id, '' ),
            'view_url'   => $is_sent ? get_permalink( $id ) : '',
        );
    }

    /**
     * Every newsletter the current user may see, newest first -- sent and
     * not-yet-sent alike.
     *
     * @return array Shaped newsletters (see shape_newsletter()).
     */
    public static function get_newsletters() {
        $query = new WP_Query( array(
            'post_type'      => 'pta_newsletter',
            'post_status'    => array( 'publish', 'draft' ),
            'perm'           => 'readable',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        $out = array();
        foreach ( $query->posts as $post ) {
            $out[] = self::shape_newsletter( $post );
        }
        return $out;
    }

    /** Render the screen. */
    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( "You don't have permission to see this.", 'Not allowed', array( 'response' => 403 ) );
        }

        $newsletters = self::get_newsletters();
        $add_url     = class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::url() : '';

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( PTK_Newsletters_Copy::title(), PTK_Newsletters_Copy::lead() );

        if ( '' !== $add_url ) {
            echo '<p>' . PTK_Hub_UI::primary_button( PTK_Newsletters_Copy::add_button_label(), $add_url ) . '</p>';
        }

        if ( empty( $newsletters ) ) {
            $empty = PTK_Newsletters_Copy::empty_state();
            echo '<div class="ptk-empty">';
            echo '<p class="ptk-card-title">' . esc_html( $empty['title'] ) . '</p>';
            echo '<p class="ptk-help">' . esc_html( $empty['text'] ) . '</p>';
            if ( '' !== $add_url ) {
                echo '<p>' . PTK_Hub_UI::primary_button( PTK_Newsletters_Copy::add_button_label(), $add_url ) . '</p>';
            }
            echo '</div>';
        } else {
            echo '<div class="ptk-written-list">';
            foreach ( $newsletters as $newsletter ) {
                echo self::render_card( $newsletter );
            }
            echo '</div>';
        }

        echo PTK_Hub_UI::quiet_links( array(
            array(
                'label' => PTK_Newsletters_Copy::see_all_link_label(),
                'url'   => admin_url( 'edit.php?post_type=pta_newsletter' ),
            ),
        ) );

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    /** One card's markup for a shaped newsletter (see shape_newsletter()). */
    private static function render_card( array $newsletter ) {
        $out  = '<article class="ptk-entry-card" data-ptk-key="' . esc_attr( $newsletter['id'] ) . '">';
        $out .= '<h2 class="ptk-entry-question">' . esc_html( $newsletter['headline'] ) . '</h2>';

        // The screen's one stamp, and only ever one: a school has a single
        // example newsletter, or none at all.
        if ( ! empty( $newsletter['is_example'] ) ) {
            $out .= '<p class="ptk-entry-stamp">' . PTK_Hub_UI::stamp( 'Example', 'dim' ) . '</p>';
        }

        if ( '' !== $newsletter['issue_line'] ) {
            $out .= '<p class="ptk-entry-answer">' . esc_html( $newsletter['issue_line'] ) . '</p>';
        }

        if ( ! $newsletter['is_sent'] ) {
            $note = ! empty( $newsletter['is_example'] )
                ? PTK_Newsletters_Copy::example_note()
                : PTK_Newsletters_Copy::not_sent_note();
            $out .= '<p class="ptk-entry-meta">' . esc_html( $note ) . '</p>';
        }

        $out .= '<div class="ptk-entry-actions">';
        if ( $newsletter['can_edit'] ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $newsletter['edit_url'] ) . '">' . esc_html( PTK_Newsletters_Copy::open_button_label() ) . '</a>';
        }
        if ( '' !== $newsletter['view_url'] ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $newsletter['view_url'] ) . '" target="_blank" rel="noopener">' . esc_html( PTK_Newsletters_Copy::view_button_label() ) . '</a>';
        }
        $out .= '</div>';

        $out .= '</article>';
        return $out;
    }
}
