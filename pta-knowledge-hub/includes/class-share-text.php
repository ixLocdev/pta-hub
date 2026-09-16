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

    const LINE_MAX = 120;

    /**
     * One "also in this issue" line for a story card.
     *
     * In our own newsletters the heading is already a whole sentence carrying
     * the fact ("Film on the Field moves to Friday, October 16.") and the
     * Facebook line is that heading barely touched -- so the heading IS the
     * line. A PTA writing "Mum Sale" as a label gets the first sentence of the
     * body appended, since a label alone tells a reader nothing.
     */
    public static function story_line( $card ) {
        $heading = self::html_to_text( isset( $card['heading'] ) ? $card['heading'] : '' );
        $body    = self::html_to_text( isset( $card['body'] ) ? $card['body'] : '' );

        if ( '' === $heading ) {
            return self::truncate( self::first_sentence( $body ) );
        }

        if ( self::reads_as_a_sentence( $heading ) ) {
            return self::truncate( $heading );
        }

        $first = self::first_sentence( $body );
        $line  = ( '' === $first ) ? $heading : $heading . ' — ' . $first;

        return self::truncate( $line );
    }

    /** Long enough to be a statement, and punctuated like one. */
    private static function reads_as_a_sentence( $s ) {
        return strlen( $s ) >= 30 && preg_match( '/[.!?]$/', $s );
    }

    private static function first_sentence( $s ) {
        if ( '' === $s ) { return ''; }
        $parts = preg_split( '/(?<=[.!?])\s+/', $s, 2 );
        return trim( $parts[0] );
    }

    /**
     * Multibyte throughout. Our newsletters are full of en and em dashes, so a
     * byte-based substr() would split a character and emit invalid UTF-8 into a
     * caption, and a byte-based rtrim( $cut, "—" ) would strip the em dash's
     * three bytes individually and could eat the front of another character.
     */
    private static function truncate( $s ) {
        if ( mb_strlen( $s ) <= self::LINE_MAX ) { return $s; }
        $cut = mb_substr( $s, 0, self::LINE_MAX );
        $sp  = mb_strrpos( $cut, ' ' );
        if ( false !== $sp ) { $cut = mb_substr( $cut, 0, $sp ); }
        return preg_replace( '/[\s,;:—–-]+$/u', '', $cut ) . '…';
    }
}
