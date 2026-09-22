<?php
/**
 * Point the big photo at the top of a post at the part that matters.
 *
 * Every school on this network uses the same Beaver Builder singular
 * template, and it shows a post's picture as a full-width banner -- as a
 * CSS background, not an <img>, fixed at dead centre. So the focal point a
 * volunteer drags in "Which picture?" reached the Hub's own cards and
 * nothing else: on the page families actually read, their choice did
 * nothing.
 *
 * This prints one rule, on one post, when that post carries framing of its
 * own. A theme with no Beaver Builder row matches nothing and is unaffected,
 * which is why the rule is safe to print on all eleven sites.
 *
 * Only the position, deliberately: a background is already sized to cover,
 * and scaling it by the picker's zoom would crop a banner differently from
 * every other place the picture appears.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Post_Banner {

    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'print_style' ), 20 );
    }

    /**
     * Pure: the rule for one post, or '' when there is nothing to say.
     *
     * @param int $post_id Used only to scope the rule to this one post.
     * @param mixed $x     Stored focal x, as read from meta ('' when unset).
     * @param mixed $y     Stored focal y.
     * @return string
     */
    public static function css( $post_id, $x, $y ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || '' === (string) $x ) {
            return '';
        }

        $position = PTK_Focal_Point::object_position( $x, $y );

        // Centre is what the template already does, so say nothing.
        if ( '50% 50%' === $position ) {
            return '';
        }

        return 'body.postid-' . $post_id . ' .fl-page-content .fl-row-bg-photo > .fl-row-content-wrap'
            . '{background-position:' . $position . ';}';
    }

    /** Print it, on a single post of ours that was framed. */
    public static function print_style() {
        if ( ! is_singular( 'post' ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || ! is_array( get_post_meta( $post_id, PTK_Post_Writer::PARTS_META, true ) ) ) {
            return;
        }

        $css = self::css(
            $post_id,
            get_post_meta( $post_id, 'ptk_image_focal_x', true ),
            get_post_meta( $post_id, 'ptk_image_focal_y', true )
        );
        if ( '' === $css ) {
            return;
        }

        echo '<style id="ptk-post-banner">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- css() builds this from two clamped integers.
    }
}
