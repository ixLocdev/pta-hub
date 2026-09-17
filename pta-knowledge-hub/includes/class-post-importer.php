<?php
/**
 * Round 5 -- pure mapping logic for "Bring in your recent posts": turns a
 * WordPress post (title, content/excerpt, featured image, permalink) into a
 * Newsletter Builder suggestion (Story / Event / Quick note). No WordPress
 * database or REST calls happen in this file -- includes/class-post-import-ajax.php
 * gathers the raw post data and hands it to these static methods, exactly
 * the way PTK_Ics_Events_Ajax hands PTK_Ics_Reader a raw .ics body.
 *
 * Mirrors the field names the Builder already uses (see
 * includes/class-newsletter-data.php): events rows are {date,title,desc};
 * story cards are {eyebrow,heading,body,image_id,link_url,link_text}; quick
 * notes are {heading,body,link_url,link_text}. build_suggestion() returns
 * data shaped to drop straight into those fields client-side.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Post_Importer {

    /** Shorten a story body to roughly this many characters before cutting to a sentence boundary. */
    const SHORTEN_LIMIT = 420;

    /** A post's cleaned body under this many characters, with a link in it, is "just a pointer". */
    const POINTER_LIMIT = 220;

    private static $months = array(
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    );

    /**
     * Strip emoji/pictographic characters -- same approach as
     * assets/js/newsletter-builder.js's stripEmoji(), ported to PHP.
     *
     * @param string $str
     * @return string
     */
    public static function strip_emoji( $str ) {
        $str = (string) $str;
        $stripped = @preg_replace( '/\p{Extended_Pictographic}/u', ' ', $str );
        if ( null === $stripped ) {
            // PCRE built without full Unicode property support: leave as-is
            // rather than silently drop the whole string.
            return trim( $str );
        }
        return trim( preg_replace( '/\s+/', ' ', $stripped ) );
    }

    /**
     * Month name (full or abbreviated) -> 1-12, or 0 if not a month.
     *
     * @param string $name
     * @return int
     */
    private static function month_number( $name ) {
        $key = strtolower( trim( (string) $name, ". \t\n\r\0\x0B" ) );
        return isset( self::$months[ $key ] ) ? self::$months[ $key ] : 0;
    }

    /**
     * Work out the calendar year for a bare month/day: the next occurrence
     * on or after (issue date - 7 days), so a date just before the issue
     * still resolves to "this" year rather than jumping a year early.
     *
     * @param int    $month 1-12
     * @param int    $day   1-31
     * @param string $issue_date 'Y-m-d'; today is used when blank/invalid.
     * @return string 'Y-m-d', or '' if $month/$day don't form a real date.
     */
    public static function infer_year( $month, $day, $issue_date ) {
        $issue = self::parse_ymd( $issue_date );
        if ( ! $issue ) {
            $issue = new DateTime( 'today' );
        }
        $reference = clone $issue;
        $reference->modify( '-7 days' );
        $reference->setTime( 0, 0, 0 );

        $year = (int) $reference->format( 'Y' );
        if ( ! checkdate( (int) $month, (int) $day, $year ) ) {
            return '';
        }
        $candidate = DateTime::createFromFormat( '!Y-n-j', $year . '-' . (int) $month . '-' . (int) $day );
        if ( $candidate < $reference ) {
            $year++;
            if ( ! checkdate( (int) $month, (int) $day, $year ) ) {
                return '';
            }
            $candidate = DateTime::createFromFormat( '!Y-n-j', $year . '-' . (int) $month . '-' . (int) $day );
        }
        return $candidate->format( 'Y-m-d' );
    }

    /**
     * @param string $date 'Y-m-d'
     * @return DateTime|null
     */
    private static function parse_ymd( $date ) {
        if ( ! is_string( $date ) || '' === trim( $date ) ) {
            return null;
        }
        $dt = DateTime::createFromFormat( '!Y-m-d', $date );
        return $dt ?: null;
    }

    /**
     * Pull a date out of a post title and hand back the title with the date
     * part removed. Patterns handled (handoff §8, real examples from
     * Bradford/Montclair High/Glenfield/Watchung):
     *   - "11/4 | Election Day Bake Sale"   (leading M/D, | or : or - separator)
     *   - "JUNE 25: Field Day"              (leading MONTH D:)
     *   - "Bake Sale – Friday March 13th 7-9pm" (trailing separator + date)
     *   - "Week of September 14"            deliberately left alone -- it
     *     names a week, not a single date.
     *
     * @param string $title
     * @param string $issue_date 'Y-m-d'
     * @return array{date:string,title:string} date is '' when nothing was found.
     */
    public static function parse_date_from_title( $title, $issue_date ) {
        $title = trim( (string) $title );

        if ( preg_match( '/^\s*week\s+of\b/i', $title ) ) {
            return array( 'date' => '', 'title' => $title );
        }

        // 1) Leading "M/D | Rest" or "M/D: Rest" or "M/D - Rest".
        if ( preg_match( '/^\s*(\d{1,2})\/(\d{1,2})\s*[|:\-–—]\s*(.+)$/u', $title, $m ) ) {
            $date = self::infer_year( (int) $m[1], (int) $m[2], $issue_date );
            if ( '' !== $date ) {
                return array( 'date' => $date, 'title' => trim( $m[3] ) );
            }
        }

        // 2) Leading "MONTH D: Rest" (e.g. "JUNE 25: Field Day").
        $month_names = implode( '|', array_keys( self::$months ) );
        if ( preg_match( '/^\s*(' . $month_names . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\s*[:\-–—]\s*(.+)$/iu', $title, $m ) ) {
            $mon = self::month_number( $m[1] );
            if ( $mon ) {
                $date = self::infer_year( $mon, (int) $m[2], $issue_date );
                if ( '' !== $date ) {
                    return array( 'date' => $date, 'title' => trim( $m[3] ) );
                }
            }
        }

        // 3) Trailing "Rest – [Weekday,] Month D[st/nd/rd/th] ..." (e.g.
        //    "Bake Sale – Friday March 13th 7-9pm"). Keep everything before
        //    the separator as the title.
        $weekdays = 'mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday';
        if ( preg_match(
            '/^(.*?)[\-–—]\s*(?:(?:' . $weekdays . ')[a-z]*\.?,?\s+)?(' . $month_names . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b.*$/iu',
            $title,
            $m
        ) ) {
            $mon = self::month_number( $m[2] );
            if ( $mon && '' !== trim( $m[1] ) ) {
                $date = self::infer_year( $mon, (int) $m[3], $issue_date );
                if ( '' !== $date ) {
                    return array( 'date' => $date, 'title' => trim( $m[1] ) );
                }
            }
        }

        return array( 'date' => '', 'title' => $title );
    }

    /**
     * Parse "When: Saturday, Oct 3, 10am–2pm" / "Where: Northeast gym" lines
     * out of a plain-text body. Returns whatever it can find; every key is
     * '' when nothing matched.
     *
     * @param string $text Plain text (see html_to_text()).
     * @return array{date:string,time:string,where:string}
     */
    public static function parse_when_where( $text, $issue_date = '' ) {
        $result = array( 'date' => '', 'time' => '', 'where' => '' );
        $lines  = preg_split( '/\r\n|\r|\n/', (string) $text );

        $month_names = implode( '|', array_keys( self::$months ) );
        $weekdays    = 'mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( preg_match( '/^when\s*:\s*(.+)$/i', $line, $m ) ) {
                $rest = trim( $m[1] );
                if ( preg_match(
                    '/(?:(?:' . $weekdays . ')[a-z]*\.?,?\s+)?(' . $month_names . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?/i',
                    $rest,
                    $dm
                ) ) {
                    $mon = self::month_number( $dm[1] );
                    if ( $mon ) {
                        $result['date'] = self::infer_year( $mon, (int) $dm[2], $issue_date );
                    }
                }
                if ( preg_match( '/(\d{1,2}(:\d{2})?\s*(am|pm)[^,]*(?:[-–—]\s*\d{1,2}(:\d{2})?\s*(am|pm))?|noon|all\s*day)/iu', $rest, $tm ) ) {
                    $result['time'] = trim( $tm[0] );
                }
                continue;
            }
            if ( preg_match( '/^where\s*:\s*(.+)$/i', $line, $m ) ) {
                $result['where'] = trim( $m[1] );
            }
        }

        return $result;
    }

    /**
     * Convert HTML (or shortcode soup) to plain text, keeping paragraph
     * breaks (needed for shorten() and detect_sections()'s ALL-CAPS scan).
     * Shortcodes are stripped; Beaver Builder's own shortcode wrapper
     * ([fl_builder...]) is treated as "no usable content" by the caller,
     * not specially unwrapped here -- rendering BB layout needs the live
     * site's registered modules, which this plugin doesn't have.
     *
     * @param string $html
     * @return string
     */
    public static function html_to_text( $html ) {
        $html = (string) $html;
        $html = preg_replace( '/\[[^\]]*\]/', '', $html );
        $html = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', '', $html );
        $html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
        $html = preg_replace( '/<li[^>]*>/i', '• ', $html );
        $html = preg_replace( '/<\/(p|div|h[1-6]|li|blockquote)>/i', "\n\n", $html );
        $text = wp_strip_all_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/\n[ \t]+/', "\n", $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        return trim( $text );
    }

    /**
     * True when $content is essentially empty or is a Beaver Builder page
     * (post_content holding just the [fl_builder...] shortcode wrapper) --
     * the two cases where the plugin falls back to the excerpt.
     *
     * @param string $content Raw post_content.
     * @return bool
     */
    public static function is_trivial_content( $content ) {
        $content = (string) $content;
        if ( '' === trim( wp_strip_all_tags( preg_replace( '/\[[^\]]*\]/', '', $content ) ) ) ) {
            return true;
        }
        return (bool) preg_match( '/\[fl_builder/i', $content );
    }

    /**
     * The body text to work with: post_content, falling back to the
     * excerpt when the content is trivial/page-builder, per
     * is_trivial_content().
     *
     * @param string $content
     * @param string $excerpt
     * @return string Plain text.
     */
    public static function usable_text( $content, $excerpt ) {
        $source = self::is_trivial_content( $content ) ? $excerpt : $content;
        return self::html_to_text( $source );
    }

    /**
     * First link in the content. When $home_host is given, an internal
     * (same-host or relative) link is skipped in favor of an external one,
     * since an internal link is rarely "the point" of the post the way an
     * external registration/sign-up link is.
     *
     * @param string $html
     * @param string $home_host e.g. 'northeastpta.org'
     * @return string '' if none.
     */
    public static function first_link( $html, $home_host = '' ) {
        if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', (string) $html, $matches ) ) {
            return '';
        }
        $fallback = '';
        foreach ( $matches[1] as $href ) {
            $href = trim( html_entity_decode( $href, ENT_QUOTES ) );
            if ( '' === $href || 0 === strpos( $href, '#' ) ) {
                continue;
            }
            if ( '' === $fallback ) {
                $fallback = $href;
            }
            if ( '' === $home_host ) {
                return $href;
            }
            $host = parse_url( $href, PHP_URL_HOST );
            if ( $host && false === stripos( $host, $home_host ) ) {
                return $href;
            }
        }
        return $fallback;
    }

    /**
     * Is this post "just a pointer" (short body + a link) -- e.g.
     * "Register: https://…"? If so its own link should become the
     * story/quick-note link instead of the permalink.
     *
     * @param string $plain_text
     * @param string $link
     * @return bool
     */
    public static function is_pointer_post( $plain_text, $link ) {
        return '' !== $link && strlen( (string) $plain_text ) <= self::POINTER_LIMIT;
    }

    /**
     * Shorten plain text to 1-2 short paragraphs, cut at a sentence
     * boundary once past the limit.
     *
     * @param string $plain_text
     * @param int    $limit
     * @return array{text:string,shortened:bool}
     */
    public static function shorten( $plain_text, $limit = self::SHORTEN_LIMIT ) {
        $paras = preg_split( '/\n{2,}/', trim( (string) $plain_text ) );
        $paras = array_values( array_filter( $paras, function ( $p ) {
            return '' !== trim( $p );
        } ) );

        $original_count = count( $paras );
        $paras          = array_slice( $paras, 0, 2 );
        $joined         = implode( "\n\n", $paras );
        $shortened      = $original_count > 2;

        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $joined ) > $limit : strlen( $joined ) > $limit ) {
            $cut = function_exists( 'mb_substr' ) ? mb_substr( $joined, 0, $limit ) : substr( $joined, 0, $limit );
            $best = -1;
            foreach ( array( '. ', ".\n", '! ', '? ' ) as $needle ) {
                $pos = strrpos( $cut, $needle );
                if ( false !== $pos && $pos > $best ) {
                    $best = $pos;
                }
            }
            if ( $best > 60 ) {
                $joined = trim( substr( $cut, 0, $best + 1 ) );
            } else {
                $joined = rtrim( $cut ) . '…';
            }
            $shortened = true;
        }

        return array( 'text' => $joined, 'shortened' => $shortened );
    }

    /**
     * Detect a weekly-roundup post's sections: 2+ HTML headings, or (when
     * there aren't any) 2+ ALL-CAPS lines each starting a section -- the
     * Bradford / Montclair High style from handoff §8. Only offered when 2+
     * are found (single heading isn't a "roundup").
     *
     * @param string $html Raw post_content.
     * @return array[] Each: array('heading' => string, 'text' => string). Empty when no split is available.
     */
    public static function detect_sections( $html ) {
        $sections = self::find_sections( $html );
        // Page-builder layouts use headings for decoration too; only count
        // sections that actually have something under them.
        $sections = array_values( array_filter( $sections, function ( $section ) {
            return strlen( trim( $section['text'] ) ) >= 3 && '' !== trim( $section['heading'] );
        } ) );
        return count( $sections ) >= 2 ? $sections : array();
    }

    /** Raw heading-or-ALL-CAPS split, before detect_sections() filters it. */
    protected static function find_sections( $html ) {
        $html = (string) $html;

        // 1) HTML headings.
        if ( preg_match_all( '/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $html, $matches, PREG_OFFSET_CAPTURE ) && count( $matches[0] ) >= 2 ) {
            $sections = array();
            $count    = count( $matches[0] );
            for ( $i = 0; $i < $count; $i++ ) {
                $heading    = trim( wp_strip_all_tags( $matches[1][ $i ][0] ) );
                $start      = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
                $end        = ( $i + 1 < $count ) ? $matches[0][ $i + 1 ][1] : strlen( $html );
                $body_html  = substr( $html, $start, $end - $start );
                $sections[] = array(
                    'heading' => self::strip_emoji( $heading ),
                    'text'    => self::html_to_text( $body_html ),
                );
            }
            return $sections;
        }

        // 2) ALL-CAPS lines, each starting a section, from the plain text.
        $text  = self::html_to_text( $html );
        $lines = preg_split( '/\n{1,2}/', $text );
        $caps_at = array();
        foreach ( $lines as $i => $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            // Has letters, is entirely caps/punctuation/digits, isn't a
            // stray short acronym-only line, and isn't a When:/Where: line.
            if ( preg_match( '/[A-Z]/', $line )
                && ! preg_match( '/[a-z]/', $line )
                && strlen( $line ) >= 4 && strlen( $line ) <= 70
                && ! preg_match( '/^(WHEN|WHERE)\s*:/i', $line )
            ) {
                $caps_at[] = $i;
            }
        }
        if ( count( $caps_at ) < 2 ) {
            return array();
        }

        $sections   = array();
        $line_count = count( $caps_at );
        foreach ( $caps_at as $idx => $line_no ) {
            $next_line_no = ( $idx + 1 < $line_count ) ? $caps_at[ $idx + 1 ] : count( $lines );
            $body_lines   = array_slice( $lines, $line_no + 1, $next_line_no - $line_no - 1 );
            $sections[]   = array(
                'heading' => self::strip_emoji( trim( $lines[ $line_no ] ) ),
                'text'    => trim( implode( "\n\n", array_filter( $body_lines, function ( $l ) {
                    return '' !== trim( $l );
                } ) ) ),
            );
        }
        return $sections;
    }

    /**
     * Sensible preselected type for one item (a whole post, or one section
     * of a split roundup): Event when a date could be parsed (title or
     * When: line); Story when there's a featured image or 2+ paragraphs;
     * else Quick note.
     *
     * @param array $item array('has_event_date'=>bool,'has_image'=>bool,'paragraph_count'=>int)
     * @return string 'event'|'story'|'quick_note'
     */
    public static function classify( array $item ) {
        if ( ! empty( $item['has_event_date'] ) ) {
            return 'event';
        }
        if ( ! empty( $item['has_image'] ) || ( isset( $item['paragraph_count'] ) && $item['paragraph_count'] >= 2 ) ) {
            return 'story';
        }
        return 'quick_note';
    }

    /**
     * Build the full suggestion for one post -- the shape
     * assets/js/newsletter-builder.js drops straight into the Builder's
     * fields. Does NOT touch the database; $post is plain data gathered by
     * class-post-import-ajax.php.
     *
     * @param array  $post array(
     *     'id' => int, 'title' => string, 'date' => 'Y-m-d' (post's publish date),
     *     'content' => string (raw post_content), 'excerpt' => string (raw),
     *     'permalink' => string, 'image_id' => int, 'home_host' => string,
     * )
     * @param string $issue_date 'Y-m-d'
     * @return array Suggestion. See inline keys.
     */
    public static function build_suggestion( array $post, $issue_date ) {
        $home_host   = isset( $post['home_host'] ) ? $post['home_host'] : '';
        $raw_title   = self::strip_emoji( isset( $post['title'] ) ? $post['title'] : '' );
        $title_parts = self::parse_date_from_title( $raw_title, $issue_date );

        $content = isset( $post['content'] ) ? $post['content'] : '';
        $excerpt = isset( $post['excerpt'] ) ? $post['excerpt'] : '';
        $plain   = self::usable_text( $content, $excerpt );

        $when_where = self::parse_when_where( $plain, $issue_date );
        $event_date = ( '' !== $title_parts['date'] ) ? $title_parts['date'] : $when_where['date'];

        $has_image       = ! empty( $post['image_id'] );
        $paragraph_count = count( array_filter( preg_split( '/\n{2,}/', $plain ), function ( $p ) {
            return '' !== trim( $p );
        } ) );
        $is_flyer = $has_image && '' === trim( $plain );

        $type = self::classify( array(
            'has_event_date'   => '' !== $event_date,
            'has_image'        => $has_image,
            'paragraph_count'  => $paragraph_count,
        ) );

        $link      = self::first_link( $content, $home_host );
        $permalink = isset( $post['permalink'] ) ? $post['permalink'] : '';
        $use_link  = ( self::is_pointer_post( $plain, $link ) ) ? $link : $permalink;

        $shortened = self::shorten( $plain );

        $detail_parts = array();
        if ( $when_where['time'] ) {
            $detail_parts[] = $when_where['time'];
        }
        if ( $when_where['where'] ) {
            $detail_parts[] = $when_where['where'];
        }
        $detail = implode( ' · ', $detail_parts );

        $sections = self::detect_sections( $content );

        // Pre-compute each section's own suggestion server-side, so ticking
        // "Split into separate items" in the panel needs no extra round
        // trip -- the JS just swaps in this array instead of the one-item
        // suggestion above.
        $split_items = array();
        if ( count( $sections ) >= 2 ) {
            $post_context = array( 'id' => isset( $post['id'] ) ? $post['id'] : 0, 'permalink' => $permalink, 'home_host' => $home_host );
            foreach ( $sections as $section ) {
                $split_items[] = self::build_section_suggestion( $section, $post_context, $issue_date );
            }
        }

        return array(
            'source_post'   => isset( $post['id'] ) ? (int) $post['id'] : 0,
            'type'          => $type,
            'title'         => $title_parts['title'],
            'date'          => $event_date,
            'detail'        => $detail,
            'body'          => $is_flyer ? '' : $shortened['text'],
            'shortened'     => $is_flyer ? false : $shortened['shortened'],
            'flyer'         => $is_flyer,
            'link_url'      => $use_link,
            'link_text'     => 'Read more',
            'image_id'      => $has_image ? (int) $post['image_id'] : 0,
            'permalink'     => $permalink,
            'can_split'     => count( $sections ) >= 2,
            'split_items'   => $split_items,
        );
    }

    /**
     * build_suggestion() for one detected section of a split weekly
     * roundup -- same rules, but no per-post image (the roundup's photo, if
     * any, stays with the "whole post" option) and the section heading
     * stands in for the title.
     *
     * @param array  $section array('heading'=>string,'text'=>string) from detect_sections().
     * @param array  $post_context array('id'=>int,'permalink'=>string,'home_host'=>string)
     * @param string $issue_date
     * @return array
     */
    public static function build_section_suggestion( array $section, array $post_context, $issue_date ) {
        $title_parts = self::parse_date_from_title( self::strip_emoji( $section['heading'] ), $issue_date );
        $plain       = isset( $section['text'] ) ? $section['text'] : '';
        $when_where  = self::parse_when_where( $plain, $issue_date );
        $event_date  = ( '' !== $title_parts['date'] ) ? $title_parts['date'] : $when_where['date'];

        $paragraph_count = count( array_filter( preg_split( '/\n{2,}/', $plain ), function ( $p ) {
            return '' !== trim( $p );
        } ) );

        $type = self::classify( array(
            'has_event_date'  => '' !== $event_date,
            'has_image'       => false,
            'paragraph_count' => $paragraph_count,
        ) );

        $home_host = isset( $post_context['home_host'] ) ? $post_context['home_host'] : '';
        $link      = self::first_link( $plain, $home_host );
        $permalink = isset( $post_context['permalink'] ) ? $post_context['permalink'] : '';
        $use_link  = self::is_pointer_post( $plain, $link ) ? $link : $permalink;

        $shortened = self::shorten( $plain );

        $detail_parts = array();
        if ( $when_where['time'] ) {
            $detail_parts[] = $when_where['time'];
        }
        if ( $when_where['where'] ) {
            $detail_parts[] = $when_where['where'];
        }

        return array(
            'source_post' => isset( $post_context['id'] ) ? (int) $post_context['id'] : 0,
            'type'        => $type,
            'title'       => $title_parts['title'],
            'date'        => $event_date,
            'detail'      => implode( ' · ', $detail_parts ),
            'body'        => $shortened['text'],
            'shortened'   => $shortened['shortened'],
            'flyer'       => false,
            'link_url'    => $use_link,
            'link_text'   => 'Read more',
            'image_id'    => 0,
            'permalink'   => $permalink,
        );
    }
}
