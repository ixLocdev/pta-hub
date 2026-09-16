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

    const WHATSAPP_MAX = 400;

    /**
     * Build the three ready-to-paste captions for a newsletter.
     *
     * $opts carries: url, issue, date, school_name, today ('YYYY-MM-DD').
     * `today` drives the upcoming-events filter -- the blocks hold every
     * event row including past ones, so without a clock every post would
     * list events that already happened.
     *
     * @return array{facebook:string,instagram:string,whatsapp:string}
     */
    public static function generate( array $blocks, array $opts ) {
        $by_type = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || ! isset( $block['type'] ) ) { continue; }
            $data = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : array();
            // First block of a given type wins -- the data model doesn't repeat types.
            if ( ! isset( $by_type[ $block['type'] ] ) ) {
                $by_type[ $block['type'] ] = $data;
            }
        }

        $issue  = isset( $opts['issue'] ) ? $opts['issue'] : '';
        $url    = isset( $opts['url'] ) ? (string) $opts['url'] : '';
        $today  = isset( $opts['today'] ) ? (string) $opts['today'] : '';

        $featured   = isset( $by_type['featured'] ) ? $by_type['featured'] : array();
        $announce   = isset( $by_type['announcement'] ) ? $by_type['announcement'] : array();
        $events     = isset( $by_type['events']['rows'] ) && is_array( $by_type['events']['rows'] ) ? $by_type['events']['rows'] : array();
        $cards      = isset( $by_type['story_cards']['cards'] ) && is_array( $by_type['story_cards']['cards'] ) ? $by_type['story_cards']['cards'] : array();
        $footer     = isset( $by_type['footer'] ) ? $by_type['footer'] : array();

        $opening = '' !== (string) $issue
            ? 'PTA Newsletter #' . $issue . ' is out.'
            : 'A new PTA newsletter is out.';

        $featured_headline = isset( $featured['headline'] ) ? trim( (string) $featured['headline'] ) : '';
        $featured_body     = self::html_to_text( isset( $featured['body'] ) ? $featured['body'] : '' );
        $featured_lines    = array_filter( array( $featured_headline, $featured_body ), 'strlen' );
        $featured_para     = implode( "\n", $featured_lines );

        $announce_text = self::html_to_text( isset( $announce['text'] ) ? $announce['text'] : '' );

        $story_lines = array();
        foreach ( $cards as $card ) {
            $line = self::story_line( is_array( $card ) ? $card : array() );
            if ( '' !== $line ) { $story_lines[] = $line; }
        }
        $cards_block = '';
        if ( ! empty( $story_lines ) ) {
            $cards_block = "Also in this issue:\n" . implode( "\n", $story_lines );
        }

        $upcoming = array();
        foreach ( $events as $row ) {
            if ( ! is_array( $row ) || empty( $row['date'] ) ) { continue; }
            if ( '' !== $today && strcmp( (string) $row['date'], $today ) < 0 ) { continue; }
            $upcoming[] = $row;
        }
        $events_block = '';
        if ( ! empty( $upcoming ) ) {
            $lines = array();
            foreach ( $upcoming as $row ) {
                $lines[] = self::event_line( $row );
            }
            $events_block = "Upcoming events:\n" . implode( "\n", $lines );
        }

        $footer_links = isset( $footer['links'] ) && is_array( $footer['links'] ) ? $footer['links'] : array();
        $footer_block = '';
        if ( ! empty( $footer_links ) ) {
            $lines = array();
            foreach ( $footer_links as $link ) {
                if ( ! is_array( $link ) || empty( $link['label'] ) || empty( $link['url'] ) ) { continue; }
                $lines[] = trim( (string) $link['label'] ) . ': ' . trim( (string) $link['url'] );
            }
            if ( ! empty( $lines ) ) {
                $footer_block = implode( "\n", $lines );
            }
        }

        $fb_sections = array( $opening );
        foreach ( array( $featured_para, $announce_text, $cards_block, $events_block, $url, $footer_block ) as $section ) {
            if ( '' !== trim( (string) $section ) ) { $fb_sections[] = $section; }
        }
        $facebook = implode( "\n\n", $fb_sections );

        $facebook = self::strip_emoji( $facebook );

        return array(
            'facebook'  => $facebook,
            'instagram' => self::generate_instagram( $opening, $featured_headline, $announce_text, $story_lines ),
            'whatsapp'  => self::generate_whatsapp( $opening, $featured_headline, $announce_text, $url ),
        );
    }

    /** One "Upcoming events" line for an event row. */
    private static function event_line( $row ) {
        $title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
        $date  = isset( $row['date'] ) ? (string) $row['date'] : '';
        $pretty = self::pretty_date( $date );
        if ( '' === $title ) {
            return '- ' . $pretty;
        }
        return '' === $pretty ? ( '- ' . $title ) : ( '- ' . $pretty . ': ' . $title );
    }

    /** 'YYYY-MM-DD' -> 'September 20', or '' if unparseable. */
    private static function pretty_date( $date ) {
        $d = \DateTime::createFromFormat( 'Y-m-d', (string) $date );
        if ( ! $d ) { return ''; }
        return $d->format( 'F j' );
    }

    /**
     * Shorter than Facebook, and never a bare link -- Instagram captions
     * aren't clickable, so end with a "link in bio" pointer instead.
     */
    private static function generate_instagram( $opening, $featured_headline, $announce_text, array $story_lines ) {
        $parts = array( $opening );

        if ( '' !== $featured_headline ) {
            $parts[] = $featured_headline;
        }
        if ( '' !== $announce_text ) {
            $parts[] = $announce_text;
        }
        if ( ! empty( $story_lines ) ) {
            $parts[] = implode( "\n", array_slice( $story_lines, 0, 3 ) );
        }

        $parts[] = 'Link in bio for the full newsletter.';

        return self::strip_emoji( implode( "\n\n", $parts ) );
    }

    /** Two or three lines plus the url, kept under WHATSAPP_MAX chars. */
    private static function generate_whatsapp( $opening, $featured_headline, $announce_text, $url ) {
        $middle = '' !== $featured_headline ? $featured_headline : $announce_text;

        $lines = array( $opening );
        if ( '' !== $middle ) { $lines[] = $middle; }
        if ( '' !== $url ) { $lines[] = $url; }

        $text = self::strip_emoji( implode( "\n", $lines ) );

        if ( strlen( $text ) > self::WHATSAPP_MAX ) {
            $budget = self::WHATSAPP_MAX - strlen( $opening ) - strlen( $url ) - 6; // room for newlines + ellipsis
            if ( $budget > 0 && '' !== $middle ) {
                $middle = mb_substr( $middle, 0, max( 0, $budget ) );
                $middle = rtrim( $middle ) . '…';
            } else {
                $middle = '';
            }
            $lines = array( $opening );
            if ( '' !== $middle ) { $lines[] = $middle; }
            if ( '' !== $url ) { $lines[] = $url; }
            $text = implode( "\n", $lines );
        }

        return $text;
    }

    /** Strip emoji-range codepoints -- an explicit product decision, no emoji anywhere. */
    private static function strip_emoji( $s ) {
        return preg_replace( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', (string) $s );
    }
}
