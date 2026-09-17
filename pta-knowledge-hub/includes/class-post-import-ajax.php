<?php
/**
 * Round 5 -- server side of "Bring in your recent posts" (steps 2 and 3 of
 * the newsletter builder). Gathers the site's recent published `post`s,
 * hands each one to PTK_Post_Importer for the mapping, and returns JSON --
 * mirrors includes/class-ics-events-ajax.php's split (WordPress-coupled
 * fetch here, pure parsing in the sibling class).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-post-importer.php';

class PTK_Post_Import_Ajax {

    const ACTION = 'ptk_import_posts';

    public static function init() {
        add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    public static function handle() {
        check_ajax_referer( 'ptk_import_posts', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $range_key  = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'two_weeks';
        $issue_date = isset( $_POST['issue_date'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_date'] ) ) : '';
        $search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $issue_dt = ( '' !== $issue_date ) ? DateTime::createFromFormat( '!Y-m-d', $issue_date ) : null;
        if ( ! $issue_dt ) {
            $issue_dt   = new DateTime( 'today' );
            $issue_date = $issue_dt->format( 'Y-m-d' );
        }

        $days  = ( 'month' === $range_key ) ? 30 : 14;
        $after = ( clone $issue_dt )->modify( '-' . $days . ' days' )->format( 'Y-m-d 00:00:00' );

        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 40,
            'date_query'     => array(
                array(
                    'after'     => $after,
                    'before'    => $issue_dt->format( 'Y-m-d 23:59:59' ),
                    'inclusive' => true,
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( '' !== $search ) {
            $args['s'] = $search;
        }

        $query     = new WP_Query( $args );
        $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        $rows = array();
        foreach ( $query->posts as $post ) {
            $thumb_id = get_post_thumbnail_id( $post );
            // PTK_Post_Importer::html_to_text(), not a raw wp_strip_all_tags():
            // stripping tags directly would fuse "<p>BAKE SALE</p><p>Join us…"
            // into "BAKE SALEJoin us…" with no space where the paragraph
            // break was.
            $excerpt = has_excerpt( $post )
                ? $post->post_excerpt
                : wp_trim_words( PTK_Post_Importer::html_to_text( $post->post_content ), 30, '…' );

            $post_data = array(
                'id'        => $post->ID,
                'title'     => get_the_title( $post ),
                'date'      => get_the_date( 'Y-m-d', $post ),
                'content'   => $post->post_content,
                'excerpt'   => $post->post_excerpt,
                'permalink' => get_permalink( $post ),
                'image_id'  => $thumb_id ? (int) $thumb_id : 0,
                'home_host' => $home_host,
            );

            $suggestion = PTK_Post_Importer::build_suggestion( $post_data, $issue_date );

            $rows[] = array_merge( $suggestion, array(
                'post_title' => $post_data['title'],
                'post_date'  => $post_data['date'],
                'excerpt'    => self::one_line_excerpt( $excerpt ),
                'thumb_url'  => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
            ) );
        }

        wp_send_json_success( array(
            'range' => $range_key,
            'posts' => $rows,
        ) );
    }

    /**
     * A single-line, plain-text excerpt for the panel's row -- strips tags
     * and shortcodes and collapses whitespace, same spirit as
     * wp_trim_words() but working from an already-set excerpt too.
     *
     * @param string $text
     * @return string
     */
    private static function one_line_excerpt( $text ) {
        $text = PTK_Post_Importer::html_to_text( (string) $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return wp_trim_words( $text, 24, '…' );
    }
}
