<?php
/**
 * "What families have asked for" -- the Hub's own screen for topic
 * suggestions members send in from the front end (the `[ptk_suggest_form]`
 * shortcode), replacing WordPress's own bare list as the place a volunteer
 * actually works.
 *
 * A second door only: WordPress's own list, edit.php?post_type=ptk_suggestion,
 * is untouched and still reachable -- see the quiet link at the foot. This
 * page exists only when PTK_Hub_Look::on() -- see add_page() -- and is
 * registered as its own admin screen ('ptk-asked-for'), never a restyled
 * list table.
 *
 * "Answer it" reuses the EXISTING admin_action_ptk_convert_suggestion
 * handler and its ptk_convert_suggestion_{id} nonce (PTK_Suggestions::
 * handle_convert()) -- there is only ever one path that turns a suggestion
 * into a draft entry. Removing is this screen's own real trash/untrash
 * (admin_post.php, per-item nonces), the same shape PTK_Written_List uses,
 * because trashing a suggestion is genuinely undoable.
 *
 * Split the same way as PTK_Written_List: pure, WordPress-free helpers
 * first (safe to unit test with plain php -- see
 * tests/test-asked-for-list.php if one exists), then the WordPress-facing
 * methods that call them.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Asked_For_List {

    /** This screen's own page slug -- a real admin page, not a list-table view. */
    const PAGE_SLUG = 'ptk-asked-for';

    /** admin_post action / nonce action for removing a question (WordPress's own trash). */
    const TRASH_ACTION = 'ptk_asked_for_trash';

    /** admin_post action / nonce action for undoing that (WordPress's own untrash). */
    const UNTRASH_ACTION = 'ptk_asked_for_untrash';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_post_' . self::TRASH_ACTION, array( __CLASS__, 'handle_trash' ) );
        add_action( 'admin_post_' . self::UNTRASH_ACTION, array( __CLASS__, 'handle_untrash' ) );
    }

    /* ------------------------------------------------------------------
     * Pure helpers -- no WordPress calls, no state, no side effects.
     * ----------------------------------------------------------------*/

    /** Pure: is there anything in the "anything else they wrote" box worth showing? */
    public static function has_body( $body ) {
        return '' !== trim( (string) $body );
    }

    /** Pure: is there an email worth linking? A blank string never renders a mailto. */
    public static function has_email( $email ) {
        return '' !== trim( (string) $email );
    }

    /* ------------------------------------------------------------------
     * WordPress-facing.
     * ----------------------------------------------------------------*/

    /** Canonical admin URL of this screen. Same edit.php submenu shape as the wizard. */
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
            'What families have asked for',
            'What families have asked for',
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /** Shape one WP_Post (a ptk_suggestion) into the flat array the card renderer needs. */
    private static function shape_suggestion( $post ) {
        $id = $post->ID;
        return array(
            'id'          => $id,
            'topic'       => get_the_title( $post ),
            'body'        => trim( wp_strip_all_tags( (string) $post->post_content ) ),
            'name'        => trim( (string) get_post_meta( $id, 'ptk_suggester_name', true ) ),
            'email'       => trim( (string) get_post_meta( $id, 'ptk_suggester_email', true ) ),
            'can_remove'  => current_user_can( 'delete_post', $id ),
        );
    }

    /** Every question still waiting, oldest first -- fair, and matches the front-end submission order. */
    public static function get_suggestions() {
        if ( ! class_exists( 'PTK_Suggestions' ) ) {
            return array();
        }
        $query = new WP_Query( array(
            'post_type'      => PTK_Suggestions::POST_TYPE,
            'post_status'    => 'publish', // a private CPT -- "publish" just means visible in admin.
            'posts_per_page' => 300,
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ) );
        $out = array();
        foreach ( $query->posts as $post ) {
            $out[] = self::shape_suggestion( $post );
        }
        return $out;
    }

    /** The nonce'd link that hands this question to Create Entry -- PTK_Suggestions' own, unchanged. */
    private static function answer_url( $id ) {
        return wp_nonce_url(
            admin_url( 'admin.php?action=ptk_convert_suggestion&id=' . (int) $id ),
            'ptk_convert_suggestion_' . (int) $id
        );
    }

    /** Render the screen. */
    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( "You don't have permission to see this.", 'Not allowed', array( 'response' => 403 ) );
        }

        $items = self::get_suggestions();
        $count = count( $items );

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( PTK_Asked_For_Copy::title(), PTK_Asked_For_Copy::lead() );

        self::render_removed_notice();

        if ( 0 === $count ) {
            $empty = PTK_Asked_For_Copy::empty_state();
            echo PTK_Hub_UI::empty_state( $empty['title'], $empty['text'] );
        } else {
            // The screen's one stamp.
            echo PTK_Hub_UI::waiting_row( PTK_Asked_For_Copy::waiting_sentence( $count ) );

            echo '<div class="ptk-approvals">';
            foreach ( $items as $item ) {
                echo self::render_card( $item );
            }
            echo '</div>';
        }

        echo PTK_Hub_UI::quiet_links( array(
            array(
                'label' => PTK_Asked_For_Copy::see_all_link_label(),
                'url'   => class_exists( 'PTK_Suggestions' ) ? admin_url( 'edit.php?post_type=' . PTK_Suggestions::POST_TYPE ) : '',
            ),
        ) );

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    /** One card's markup for a shaped suggestion (see shape_suggestion()). */
    private static function render_card( array $item ) {
        $out  = '<article class="ptk-approval" data-ptk-key="' . esc_attr( $item['id'] ) . '">';
        $out .= '<h2 class="ptk-approval-name">' . PTK_Hub_UI::no_widow( $item['topic'] ) . '</h2>';

        $who = 'Asked by ' . PTK_Asked_For_Copy::asked_by( $item['name'] );
        $out .= '<p class="ptk-approval-meta">' . esc_html( $who );
        if ( self::has_email( $item['email'] ) ) {
            $out .= ' &middot; <a href="mailto:' . esc_attr( $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a>';
        }
        $out .= '</p>';

        if ( self::has_body( $item['body'] ) ) {
            $out .= '<div class="ptk-approval-said"><p class="ptk-approval-comment">' . esc_html( $item['body'] ) . '</p></div>';
        }

        $out .= '<div class="ptk-approval-actions">';
        $out .= '<a class="ptk-btn ptk-btn-primary" href="' . esc_url( self::answer_url( $item['id'] ) ) . '">' . esc_html( PTK_Asked_For_Copy::answer_button_label() ) . '</a>';
        $out .= '</div>';

        if ( $item['can_remove'] ) {
            $nonce = wp_create_nonce( self::TRASH_ACTION );
            $out  .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ptk-entry-remove-row">';
            $out  .= '<input type="hidden" name="action" value="' . esc_attr( self::TRASH_ACTION ) . '">';
            $out  .= '<input type="hidden" name="id" value="' . esc_attr( $item['id'] ) . '">';
            $out  .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
            $out  .= '<input type="hidden" name="redirect_to" value="' . esc_attr( self::url() ) . '">';
            $out  .= '<button type="submit" class="ptk-entry-remove-btn">' . esc_html( PTK_Asked_For_Copy::remove_button_label() ) . '</button>';
            $out  .= '</form>';
        }

        $out .= '</article>';
        return $out;
    }

    /** The "Removed. Undo" banner, when we just came back from a trash action. */
    private static function render_removed_notice() {
        if ( empty( $_GET['ptk_removed'] ) ) {
            return;
        }
        $id = absint( $_GET['ptk_removed'] );
        if ( ! $id || ! class_exists( 'PTK_Suggestions' ) || PTK_Suggestions::POST_TYPE !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
            return;
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return;
        }
        $undo_url = wp_nonce_url(
            add_query_arg( array(
                'action'      => self::UNTRASH_ACTION,
                'id'          => $id,
                'redirect_to' => rawurlencode( self::url() ),
            ), admin_url( 'admin-post.php' ) ),
            self::UNTRASH_ACTION
        );
        echo '<div class="ptk-written-notice"><p>' . esc_html( PTK_Asked_For_Copy::removed_notice_text() ) . ' <a href="' . esc_url( $undo_url ) . '">Undo</a></p></div>';
    }

    /** admin_post_ptk_asked_for_trash: remove one question (WordPress's own trash). */
    public static function handle_trash() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( 'You must be signed in to do that.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::TRASH_ACTION );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id || ! class_exists( 'PTK_Suggestions' ) || PTK_Suggestions::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
            wp_die( "You don't have permission to remove that.", 'Not allowed', array( 'response' => 403 ) );
        }

        wp_trash_post( $id );

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::url();
        $redirect = add_query_arg( 'ptk_removed', $id, remove_query_arg( 'ptk_removed', $redirect ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    /** admin_post_ptk_asked_for_untrash: real Undo. */
    public static function handle_untrash() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( 'You must be signed in to do that.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::UNTRASH_ACTION );

        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id || ! class_exists( 'PTK_Suggestions' ) || PTK_Suggestions::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
            wp_die( "You don't have permission to restore that.", 'Not allowed', array( 'response' => 403 ) );
        }

        wp_untrash_post( $id );

        // A suggestion has exactly one meaningful "visible" status --
        // 'publish' (see class-suggestions.php: "publish just means
        // visible in admin"). WordPress's own wp_untrash_post() is
        // deliberately conservative and can land a restored post on
        // 'draft' instead of its pre-trash status; force it back so Undo
        // genuinely restores the question to this screen.
        if ( PTK_Suggestions::POST_TYPE === get_post_type( $id ) && 'publish' !== get_post_status( $id ) ) {
            wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
        }

        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : self::url();
        wp_safe_redirect( $redirect );
        exit;
    }
}
