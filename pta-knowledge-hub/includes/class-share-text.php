<?php
/**
 * Turns newsletter block data into plain-text social captions.
 *
 * WordPress-free beyond the sanitizing shims in tests/bootstrap.php, so it can
 * be unit-tested with plain php -- same contract as PTK_Newsletter_Data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Text {

    /**
     * Flatten a wp_kses_post() field to plain text fit for a social post.
     */
    public static function html_to_text( $html ) {
        if ( ! is_scalar( $html ) ) {
            return '';
        }
        $s = (string) $html;

        // Keep a link's destination -- captions have no markup to carry it.
        $s = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            function ( $m ) {
                $label = trim( strip_tags( $m[2] ) );
                $url   = trim( $m[1] );
                if ( '' === $label ) { return $url; }
                return $label . ' (' . $url . ')';
            },
            $s
        );

        $s = preg_replace( '#<br\s*/?>#i', "\n", $s );
        $s = preg_replace( '#</p\s*>#i', "\n", $s );
        $s = wp_strip_all_tags( $s );
        $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = str_replace( "\xc2\xa0", ' ', $s );
        $s = preg_replace( '/[ \t]+/', ' ', $s );
        $s = preg_replace( '/\n{2,}/', "\n", $s );

        return trim( $s );
    }
}
