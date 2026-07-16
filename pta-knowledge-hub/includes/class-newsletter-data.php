<?php
/**
 * Newsletter block data model: suggested default layout and input sanitizing.
 *
 * A newsletter is an ordered array of typed blocks, each shaped as
 * { type, data }. This class is intentionally WordPress-free beyond the
 * handful of sanitizing helpers shimmed in tests/bootstrap.php so it can be
 * unit-tested with plain php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Newsletter_Data {

    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    const TYPE_HEADER       = 'header';
    const TYPE_ANNOUNCEMENT = 'announcement';
    const TYPE_EVENTS       = 'events';
    const TYPE_FEATURED     = 'featured';
    const TYPE_STORY_CARDS  = 'story_cards';
    const TYPE_FOOTER       = 'footer';

    /**
     * Known block types, in no particular order.
     *
     * @return string[]
     */
    protected static function known_types() {
        return array(
            self::TYPE_HEADER,
            self::TYPE_ANNOUNCEMENT,
            self::TYPE_EVENTS,
            self::TYPE_FEATURED,
            self::TYPE_STORY_CARDS,
            self::TYPE_FOOTER,
        );
    }

    /**
     * The suggested default layout for a new newsletter: header first,
     * footer last, with empty-ish placeholder fields for every block.
     *
     * @return array[]
     */
    public static function default_blocks() {
        return array(
            array(
                'type' => self::TYPE_HEADER,
                'data' => array(
                    'school_name' => '',
                    'greeting'    => '',
                ),
            ),
            array(
                'type' => self::TYPE_ANNOUNCEMENT,
                'data' => array(
                    'pill' => '',
                    'text' => '',
                ),
            ),
            array(
                'type' => self::TYPE_EVENTS,
                'data' => array(
                    'rows' => array(),
                ),
            ),
            array(
                'type' => self::TYPE_FEATURED,
                'data' => array(
                    'eyebrow'  => '',
                    'headline' => '',
                    'body'     => '',
                    'image_id' => 0,
                ),
            ),
            array(
                'type' => self::TYPE_STORY_CARDS,
                'data' => array(
                    'cards' => array(),
                ),
            ),
            array(
                'type' => self::TYPE_FOOTER,
                'data' => array(
                    'signoff' => '',
                    'links'   => array(),
                ),
            ),
        );
    }

    /**
     * Normalize a submitted blocks array: keep only known block types,
     * sanitize every field per the data model, and guarantee a single
     * header block first and a single footer block last.
     *
     * @param mixed $raw Submitted blocks (expected to be an array of
     *                    { type, data } arrays).
     * @return array[]
     */
    public static function sanitize_blocks( $raw ) {
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $known  = self::known_types();
        $clean  = array();
        $header = null;
        $footer = null;

        foreach ( $raw as $block ) {
            if ( ! is_array( $block ) || empty( $block['type'] ) || ! in_array( $block['type'], $known, true ) ) {
                continue;
            }

            $type = $block['type'];
            $data = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : array();

            $sanitized = array(
                'type' => $type,
                'data' => self::sanitize_block_data( $type, $data ),
            );

            if ( self::TYPE_HEADER === $type ) {
                if ( null === $header ) {
                    $header = $sanitized;
                }
                continue;
            }

            if ( self::TYPE_FOOTER === $type ) {
                if ( null === $footer ) {
                    $footer = $sanitized;
                }
                continue;
            }

            $clean[] = $sanitized;
        }

        if ( null === $header ) {
            $header = array(
                'type' => self::TYPE_HEADER,
                'data' => self::sanitize_block_data( self::TYPE_HEADER, array() ),
            );
        }

        if ( null === $footer ) {
            $footer = array(
                'type' => self::TYPE_FOOTER,
                'data' => self::sanitize_block_data( self::TYPE_FOOTER, array() ),
            );
        }

        array_unshift( $clean, $header );
        $clean[] = $footer;

        return $clean;
    }

    /**
     * Sanitize the data payload for a single block, per the data model.
     *
     * @param string $type Block type.
     * @param array  $data Raw block data.
     * @return array Sanitized block data.
     */
    protected static function sanitize_block_data( $type, $data ) {
        switch ( $type ) {
            case self::TYPE_HEADER:
                return array(
                    'school_name' => sanitize_text_field( self::str_field( isset( $data['school_name'] ) ? $data['school_name'] : '' ) ),
                    'greeting'    => wp_kses_post( self::str_field( isset( $data['greeting'] ) ? $data['greeting'] : '' ) ),
                );

            case self::TYPE_ANNOUNCEMENT:
                return array(
                    'pill' => sanitize_text_field( self::str_field( isset( $data['pill'] ) ? $data['pill'] : '' ) ),
                    'text' => wp_kses_post( self::str_field( isset( $data['text'] ) ? $data['text'] : '' ) ),
                );

            case self::TYPE_EVENTS:
                $rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
                $clean_rows = array();
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    $clean_rows[] = array(
                        'date'  => self::sanitize_date( isset( $row['date'] ) ? $row['date'] : '' ),
                        'title' => sanitize_text_field( self::str_field( isset( $row['title'] ) ? $row['title'] : '' ) ),
                        'desc'  => wp_kses_post( self::str_field( isset( $row['desc'] ) ? $row['desc'] : '' ) ),
                    );
                }
                return array( 'rows' => $clean_rows );

            case self::TYPE_FEATURED:
                return array(
                    'eyebrow'  => sanitize_text_field( self::str_field( isset( $data['eyebrow'] ) ? $data['eyebrow'] : '' ) ),
                    'headline' => sanitize_text_field( self::str_field( isset( $data['headline'] ) ? $data['headline'] : '' ) ),
                    'body'     => wp_kses_post( self::str_field( isset( $data['body'] ) ? $data['body'] : '' ) ),
                    'image_id' => absint( isset( $data['image_id'] ) ? $data['image_id'] : 0 ),
                );

            case self::TYPE_STORY_CARDS:
                $cards = isset( $data['cards'] ) && is_array( $data['cards'] ) ? $data['cards'] : array();
                $clean_cards = array();
                foreach ( $cards as $card ) {
                    if ( ! is_array( $card ) ) {
                        continue;
                    }
                    $clean_cards[] = array(
                        'heading'   => sanitize_text_field( self::str_field( isset( $card['heading'] ) ? $card['heading'] : '' ) ),
                        'body'      => wp_kses_post( self::str_field( isset( $card['body'] ) ? $card['body'] : '' ) ),
                        'image_id'  => absint( isset( $card['image_id'] ) ? $card['image_id'] : 0 ),
                        'link_url'  => esc_url_raw( self::str_field( isset( $card['link_url'] ) ? $card['link_url'] : '' ) ),
                        'link_text' => sanitize_text_field( self::str_field( isset( $card['link_text'] ) ? $card['link_text'] : '' ) ),
                    );
                }
                return array( 'cards' => $clean_cards );

            case self::TYPE_FOOTER:
                $links = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array();
                $clean_links = array();
                foreach ( $links as $link ) {
                    if ( ! is_array( $link ) ) {
                        continue;
                    }
                    $clean_links[] = array(
                        'label' => sanitize_text_field( self::str_field( isset( $link['label'] ) ? $link['label'] : '' ) ),
                        'url'   => esc_url_raw( self::str_field( isset( $link['url'] ) ? $link['url'] : '' ) ),
                    );
                }
                return array(
                    'signoff' => wp_kses_post( self::str_field( isset( $data['signoff'] ) ? $data['signoff'] : '' ) ),
                    'links'   => $clean_links,
                );

            default:
                return array();
        }
    }

    /**
     * Coerce a submitted leaf value to a string, guarding against non-scalar
     * (array/object) input that would otherwise trigger an "Array to string
     * conversion" warning inside the WP sanitizers.
     *
     * @param mixed $v Raw value.
     * @return string
     */
    protected static function str_field( $v ) {
        return is_scalar( $v ) ? (string) $v : '';
    }

    /**
     * Validate a YYYY-MM-DD date string; blank it if it doesn't match.
     *
     * @param mixed $date Raw date value.
     * @return string
     */
    protected static function sanitize_date( $date ) {
        $date = sanitize_text_field( self::str_field( $date ) );
        return preg_match( self::DATE_PATTERN, $date ) ? $date : '';
    }

    /**
     * Bump a "last issue number" to the next issue, flooring at 1.
     *
     * @param mixed $last Last issue number (expected numeric).
     * @return int
     */
    public static function compute_next_issue( $last ) {
        return max( 1, intval( self::str_field( $last ) ) + 1 );
    }

    /**
     * Classify an event date relative to today into a display bucket, with
     * the calendar week starting on Monday.
     *
     * @param mixed $event Event date (expected 'YYYY-MM-DD').
     * @param mixed $today Reference "today" date (expected 'YYYY-MM-DD').
     * @return string One of 'past', 'this-week', 'next-week', 'upcoming'.
     */
    public static function relabel_for_date( $event, $today ) {
        $event_dt = self::parse_date( $event );
        $today_dt = self::parse_date( $today );

        if ( ! $event_dt || ! $today_dt ) {
            return 'upcoming';
        }

        // The "past" check runs BEFORE the week-window check on purpose: an
        // event earlier than $today but in the same Monday–Sunday week is
        // labeled 'past', not 'this-week'. Keep this ordering in the JS port.
        if ( $event_dt < $today_dt ) {
            return 'past';
        }

        // ISO-8601 day-of-week: Monday = 1 ... Sunday = 7.
        $dow           = (int) $today_dt->format( 'N' );
        $this_monday   = clone $today_dt;
        $this_monday->modify( '-' . ( $dow - 1 ) . ' days' );
        $this_sunday   = clone $this_monday;
        $this_sunday->modify( '+6 days' );

        if ( $event_dt >= $this_monday && $event_dt <= $this_sunday ) {
            return 'this-week';
        }

        $next_monday = clone $this_monday;
        $next_monday->modify( '+7 days' );
        $next_sunday = clone $this_sunday;
        $next_sunday->modify( '+7 days' );

        if ( $event_dt >= $next_monday && $event_dt <= $next_sunday ) {
            return 'next-week';
        }

        return 'upcoming';
    }

    /**
     * Parse a 'YYYY-MM-DD' string into a midnight DateTime, or null if it
     * isn't a valid date. No WordPress calls; safe against empty/garbage
     * input.
     *
     * @param mixed $value Raw date value.
     * @return DateTime|null
     */
    protected static function parse_date( $value ) {
        if ( ! is_scalar( $value ) ) {
            return null;
        }

        $value = trim( (string) $value );
        if ( '' === $value || ! preg_match( self::DATE_PATTERN, $value ) ) {
            return null;
        }

        $dt = DateTime::createFromFormat( '!Y-m-d', $value );
        if ( ! $dt ) {
            return null;
        }

        $errors = DateTime::getLastErrors();
        if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
            return null;
        }

        return $dt;
    }
}
