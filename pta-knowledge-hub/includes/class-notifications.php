<?php
/**
 * Tracks user visits and surfaces new/updated knowledge base entries.
 *
 * Uses user meta for logged-in users and a cookie for guests.
 * The visit timestamp is updated after the "What's New" section renders
 * so users see new content before the timestamp advances.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Notifications {

    /** @var string User meta key for last visit. */
    const META_KEY = 'ptk_last_kb_visit';

    /** @var string Cookie name for logged-out users. */
    const COOKIE_NAME = 'ptk_last_kb_visit';

    /** @var int Cookie lifespan in seconds (7 days). */
    const COOKIE_EXPIRY = 604800; // 7 * DAY_IN_SECONDS

    /**
     * Register hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_pta_update_kb_visit', array( __CLASS__, 'ajax_update_visit' ) );
        add_action( 'wp_ajax_nopriv_pta_update_kb_visit', array( __CLASS__, 'ajax_update_visit' ) );
    }

    /**
     * Get entries published or modified since the user's last visit.
     *
     * @param int $limit Maximum entries to return.
     * @return array Each element: { 'post' => WP_Post, 'type' => 'new'|'updated', 'category' => string }
     */
    public static function get_new_entries( $limit = 5 ) {
        if ( ! self::has_access() ) {
            return array();
        }

        $last_visit = self::get_last_visit();

        // First-time visitor — return the most recent entries.
        if ( ! $last_visit ) {
            return self::get_recent_entries( $limit );
        }

        $args = array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'date_query'     => array(
                array(
                    'column' => 'post_modified_gmt',
                    'after'  => $last_visit,
                ),
            ),
        );

        $posts = get_posts( $args );

        return self::format_entries( $posts, $last_visit );
    }

    /**
     * Get the user's last visit timestamp.
     *
     * @return string|null Datetime string (MySQL format, GMT) or null.
     */
    public static function get_last_visit() {
        if ( is_user_logged_in() ) {
            $value = get_user_meta( get_current_user_id(), self::META_KEY, true );
            return $value ? $value : null;
        }

        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            // Validate MySQL datetime format.
            if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw ) ) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * Update the last visit timestamp to the current time.
     *
     * Safe to call from template rendering context for logged-in users.
     * For logged-out users, only call from AJAX handler (before headers sent).
     */
    public static function update_last_visit() {
        $now = current_time( 'mysql', true ); // GMT.

        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::META_KEY, $now );
            return;
        }

        // For logged-out users, we can only set cookies before headers are sent.
        // If headers already sent (template context), silently skip — the AJAX
        // fallback will handle it.
        if ( ! headers_sent() ) {
            setcookie(
                self::COOKIE_NAME,
                $now,
                time() + self::COOKIE_EXPIRY,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                false
            );
        }
    }

    /**
     * Return the count of new/updated entries since last visit.
     *
     * @return int
     */
    public static function get_new_count() {
        if ( ! self::has_access() ) {
            return 0;
        }

        $last_visit = self::get_last_visit();

        if ( ! $last_visit ) {
            $counts = wp_count_posts( 'pta_knowledge' );
            return min( 5, isset( $counts->publish ) ? (int) $counts->publish : 0 );
        }

        $args = array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'date_query'     => array(
                array(
                    'column' => 'post_modified_gmt',
                    'after'  => $last_visit,
                ),
            ),
        );

        return count( get_posts( $args ) );
    }

    /* ------------------------------------------------------------------
     * AJAX handler
     * ----------------------------------------------------------------*/

    /**
     * AJAX callback — updates the visit timestamp.
     */
    public static function ajax_update_visit() {
        if ( is_user_logged_in() ) {
            check_ajax_referer( 'ptk_search_nonce', '_wpnonce' );
        }

        self::update_last_visit();
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Check whether the current user has access to the knowledge base.
     *
     * @return bool
     */
    private static function has_access() {
        if ( get_option( 'ptk_require_login', false ) && ! is_user_logged_in() ) {
            return false;
        }
        return true;
    }

    /**
     * Return the most recent published entries (for first-time visitors).
     *
     * @param int $limit Number of entries.
     * @return array Formatted entry array.
     */
    private static function get_recent_entries( $limit ) {
        $posts = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        return self::format_entries( $posts, null );
    }

    /**
     * Build the structured return array for a set of posts.
     *
     * @param WP_Post[]   $posts      Array of post objects.
     * @param string|null $last_visit  Last visit datetime (GMT) or null.
     * @return array
     */
    private static function format_entries( $posts, $last_visit ) {
        $entries = array();

        foreach ( $posts as $post ) {
            if ( ! $last_visit ) {
                $type = 'new';
            } elseif ( $post->post_date_gmt > $last_visit ) {
                $type = 'new';
            } else {
                $type = 'updated';
            }

            $categories = wp_get_post_terms( $post->ID, 'knowledge_category', array( 'fields' => 'names' ) );
            $cat_name   = ( ! is_wp_error( $categories ) && ! empty( $categories ) ) ? $categories[0] : '';

            $entries[] = array(
                'post'     => $post,
                'type'     => $type,
                'category' => $cat_name,
            );
        }

        return $entries;
    }
}
