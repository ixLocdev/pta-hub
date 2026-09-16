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

require_once dirname( __FILE__ ) . '/class-focal-point.php';

class PTK_Newsletter_Data {

    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    const TYPE_HEADER       = 'header';
    const TYPE_ANNOUNCEMENT = 'announcement';
    const TYPE_EVENTS       = 'events';
    const TYPE_FEATURED     = 'featured';
    const TYPE_STORY_CARDS  = 'story_cards';
    const TYPE_QUICK_NOTES  = 'quick_notes';
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
            self::TYPE_QUICK_NOTES,
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
                    'headline'    => '',
                    'summary'     => '',
                    'greeting'    => '',
                ),
            ),
            array(
                'type' => self::TYPE_ANNOUNCEMENT,
                'data' => array(
                    'when'        => '',
                    'headline'    => '',
                    'text'        => '',
                    'button_text' => '',
                    'button_url'  => '',
                    'timeline'    => array(),
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
                    'eyebrow'       => '',
                    'headline'      => '',
                    'body'          => '',
                    'image_id'      => 0,
                    'image_fit'     => 'whole',
                    'image_focal_x' => 50,
                    'image_focal_y' => 50,
                    'image_zoom'    => 0,
                    'link_url'      => '',
                    'link_text'     => '',
                ),
            ),
            array(
                'type' => self::TYPE_STORY_CARDS,
                'data' => array(
                    // Each card: eyebrow, heading, body, image_id, link_url, link_text.
                    'cards' => array(),
                ),
            ),
            array(
                'type' => self::TYPE_QUICK_NOTES,
                'data' => array(
                    'label' => '',
                    'items' => array(),
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
     * header block first and a single footer block last. Every other type
     * appears at most once too -- the first one wins, which is also the one
     * PTK_Share_Text::generate() reads -- so a newsletter can never carry
     * two announcements (two navy bands).
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
        $seen   = array();

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

            if ( isset( $seen[ $type ] ) ) {
                continue;
            }
            $seen[ $type ] = true;

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
     * The four fields every cropped photo carries, sanitized identically
     * wherever an image_id appears (featured, each story card). Safe
     * defaults ('whole', centered, unzoomed) so a newsletter saved before
     * round 3 -- with no crop keys in its stored JSON at all -- renders
     * exactly as it did before.
     *
     * @param array $data The block (or card) data the image_id lives in.
     * @return array The four sanitized crop fields.
     */
    protected static function sanitize_image_crop( array $data ) {
        return array(
            'image_fit'     => ( isset( $data['image_fit'] ) && 'crop' === $data['image_fit'] ) ? 'crop' : 'whole',
            'image_focal_x' => PTK_Focal_Point::clamp_percent( isset( $data['image_focal_x'] ) ? $data['image_focal_x'] : 50 ),
            'image_focal_y' => PTK_Focal_Point::clamp_percent( isset( $data['image_focal_y'] ) ? $data['image_focal_y'] : 50 ),
            'image_zoom'    => PTK_Focal_Point::sanitize_zoom( isset( $data['image_zoom'] ) ? $data['image_zoom'] : 0 ),
        );
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
                    'headline'    => sanitize_text_field( self::str_field( isset( $data['headline'] ) ? $data['headline'] : '' ) ),
                    'summary'     => sanitize_text_field( self::str_field( isset( $data['summary'] ) ? $data['summary'] : '' ) ),
                    'greeting'    => wp_kses_post( self::str_field( isset( $data['greeting'] ) ? $data['greeting'] : '' ) ),
                );

            case self::TYPE_ANNOUNCEMENT:
                // 4.1.x called the "when" line a pill. isset, not empty: a
                // posted-but-cleared "when" must still win over an old pill.
                $when = isset( $data['when'] ) ? $data['when'] : ( isset( $data['pill'] ) ? $data['pill'] : '' );
                $timeline = isset( $data['timeline'] ) && is_array( $data['timeline'] ) ? $data['timeline'] : array();
                $clean_timeline = array();
                foreach ( $timeline as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    $clean_timeline[] = array(
                        'date' => self::sanitize_date( isset( $row['date'] ) ? $row['date'] : '' ),
                        'time' => sanitize_text_field( self::str_field( isset( $row['time'] ) ? $row['time'] : '' ) ),
                        'what' => sanitize_text_field( self::str_field( isset( $row['what'] ) ? $row['what'] : '' ) ),
                    );
                }
                return array(
                    'when'        => sanitize_text_field( self::str_field( $when ) ),
                    'headline'    => sanitize_text_field( self::str_field( isset( $data['headline'] ) ? $data['headline'] : '' ) ),
                    'text'        => wp_kses_post( self::str_field( isset( $data['text'] ) ? $data['text'] : '' ) ),
                    'button_text' => sanitize_text_field( self::str_field( isset( $data['button_text'] ) ? $data['button_text'] : '' ) ),
                    'button_url'  => self::sanitize_link_url( isset( $data['button_url'] ) ? $data['button_url'] : '' ),
                    'timeline'    => $clean_timeline,
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
                return array_merge(
                    array(
                        'eyebrow'  => sanitize_text_field( self::str_field( isset( $data['eyebrow'] ) ? $data['eyebrow'] : '' ) ),
                        'headline' => sanitize_text_field( self::str_field( isset( $data['headline'] ) ? $data['headline'] : '' ) ),
                        'body'     => wp_kses_post( self::str_field( isset( $data['body'] ) ? $data['body'] : '' ) ),
                        'image_id'  => absint( isset( $data['image_id'] ) ? $data['image_id'] : 0 ),
                        'link_url'  => esc_url_raw( self::str_field( isset( $data['link_url'] ) ? $data['link_url'] : '' ) ),
                        'link_text' => sanitize_text_field( self::str_field( isset( $data['link_text'] ) ? $data['link_text'] : '' ) ),
                    ),
                    self::sanitize_image_crop( $data )
                );

            case self::TYPE_STORY_CARDS:
                $cards = isset( $data['cards'] ) && is_array( $data['cards'] ) ? $data['cards'] : array();
                $clean_cards = array();
                foreach ( $cards as $card ) {
                    if ( ! is_array( $card ) ) {
                        continue;
                    }
                    $clean_cards[] = array_merge(
                        array(
                            'eyebrow'   => sanitize_text_field( self::str_field( isset( $card['eyebrow'] ) ? $card['eyebrow'] : '' ) ),
                            'heading'   => sanitize_text_field( self::str_field( isset( $card['heading'] ) ? $card['heading'] : '' ) ),
                            'body'      => wp_kses_post( self::str_field( isset( $card['body'] ) ? $card['body'] : '' ) ),
                            'image_id'  => absint( isset( $card['image_id'] ) ? $card['image_id'] : 0 ),
                            'link_url'  => esc_url_raw( self::str_field( isset( $card['link_url'] ) ? $card['link_url'] : '' ) ),
                            'link_text' => sanitize_text_field( self::str_field( isset( $card['link_text'] ) ? $card['link_text'] : '' ) ),
                        ),
                        self::sanitize_image_crop( $card )
                    );
                }
                return array( 'cards' => $clean_cards );

            case self::TYPE_QUICK_NOTES:
                $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
                $clean_items = array();
                foreach ( $items as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $clean_items[] = array(
                        'heading'   => sanitize_text_field( self::str_field( isset( $item['heading'] ) ? $item['heading'] : '' ) ),
                        'body'      => wp_kses_post( self::str_field( isset( $item['body'] ) ? $item['body'] : '' ) ),
                        'link_url'  => self::sanitize_link_url( isset( $item['link_url'] ) ? $item['link_url'] : '' ),
                        'link_text' => sanitize_text_field( self::str_field( isset( $item['link_text'] ) ? $item['link_text'] : '' ) ),
                    );
                }
                return array(
                    'label' => sanitize_text_field( self::str_field( isset( $data['label'] ) ? $data['label'] : '' ) ),
                    'items' => $clean_items,
                );

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
     * A link a volunteer typed for a button or a quick note: a web address
     * (http or https) or an email link. A bare email address becomes an email
     * link, so nobody has to know the word "mailto". Anything else --
     * javascript:, data:, junk -- becomes '' rather than a link that surprises.
     *
     * @param mixed $url Raw link.
     * @return string
     */
    public static function sanitize_link_url( $url ) {
        $url = trim( self::str_field( $url ) );
        if ( preg_match( '/^[^@\s:\/]+@[^@\s\/]+\.[^@\s\/]+$/', $url ) ) {
            $url = 'mailto:' . $url;
        }
        $url = esc_url_raw( $url );
        return preg_match( '#^(https?://|mailto:)#i', $url ) ? $url : '';
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
     * Does this newsletter show any photo? True when any block -- or any row
     * inside a block (story cards) -- has an image_id above 0. The photo
     * consent check only means something when there is a photo to confirm.
     *
     * @param mixed $blocks Blocks array (sanitized or raw).
     * @return bool
     */
    public static function blocks_have_images( $blocks ) {
        if ( ! is_array( $blocks ) ) {
            return false;
        }
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || ! isset( $block['data'] ) || ! is_array( $block['data'] ) ) {
                continue;
            }
            foreach ( $block['data'] as $key => $value ) {
                if ( 'image_id' === $key && is_scalar( $value ) && intval( $value ) > 0 ) {
                    return true;
                }
                if ( is_array( $value ) ) {
                    foreach ( $value as $row ) {
                        if ( is_array( $row ) && isset( $row['image_id'] ) && is_scalar( $row['image_id'] ) && intval( $row['image_id'] ) > 0 ) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * "Start from last issue" (4.3.0): copy the footer wholesale and one
     * section label (the quick notes label) from the most recent
     * newsletter's SANITIZED blocks into a fresh set of defaults. Everything
     * else -- stories, events, the announcement, the greeting, the summary --
     * stays exactly as default_blocks() left it, because none of it is a
     * "set it once" label; it's per-issue content that would otherwise show
     * up stale.
     *
     * Round 3.1 (spec item 1): the Top story's eyebrow ("§ label") is
     * deliberately NOT copied any more. It is issue-specific text a
     * volunteer wrote for last week's story (e.g. "ASE volunteers"), not a
     * standing label like "Quick notes" -- copying it left a new,
     * completely different top story wearing last issue's headline. A new
     * newsletter always starts with the default "Top story" instead (see
     * default_blocks()).
     *
     * Pure and WordPress-free on purpose (spec Decision 7) so the copy rule
     * itself is unit-tested without a post query -- the WordPress-coupled
     * caller (PTK_Newsletter_Builder::default_blocks_for_site()) only has
     * to find the last newsletter's id and hand its sanitized blocks here.
     *
     * @param array $defaults    From default_blocks() (or already partly
     *                            filled in, e.g. with school_name).
     * @param array $last_blocks Sanitized blocks of the most recent
     *                            newsletter, or an empty array when there
     *                            is none yet.
     * @return array[]
     */
    public static function merge_start_from_last( array $defaults, array $last_blocks ) {
        if ( empty( $last_blocks ) ) {
            return $defaults;
        }

        $by_type = array();
        foreach ( $last_blocks as $block ) {
            if ( is_array( $block ) && isset( $block['type'] ) && ! isset( $by_type[ $block['type'] ] ) ) {
                $by_type[ $block['type'] ] = $block;
            }
        }

        foreach ( $defaults as $i => $block ) {
            if ( ! isset( $block['type'] ) ) {
                continue;
            }

            if ( self::TYPE_FOOTER === $block['type'] && isset( $by_type[ self::TYPE_FOOTER ]['data'] ) ) {
                $defaults[ $i ]['data'] = $by_type[ self::TYPE_FOOTER ]['data'];
            } elseif ( self::TYPE_QUICK_NOTES === $block['type'] && isset( $by_type[ self::TYPE_QUICK_NOTES ]['data']['label'] ) ) {
                $defaults[ $i ]['data']['label'] = $by_type[ self::TYPE_QUICK_NOTES ]['data']['label'];
            }
        }

        return $defaults;
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
     * The Monday starting the calendar week (Monday-Sunday) that $dt is in.
     * Shared by relabel_for_date() and issue_week_monday().
     *
     * @param DateTime $dt
     * @return DateTime A new object; $dt is not changed.
     */
    private static function monday_of( DateTime $dt ) {
        // ISO-8601 day-of-week: Monday = 1 ... Sunday = 7.
        $dow    = (int) $dt->format( 'N' );
        $monday = clone $dt;
        $monday->modify( '-' . ( $dow - 1 ) . ' days' );
        return $monday;
    }

    /**
     * The Monday a newsletter's "Week of ..." headline names, for its issue
     * date. A weekday belongs to its own week (Wed Sep 16 -> Mon Sep 14).
     * A SUNDAY belongs to the week starting the next day: school newsletters
     * go out on Sunday for the coming week (#040 was sent Sun Sep 13 as
     * "Week of September 14"). Saturday stays with its own week.
     *
     * @param mixed $date Issue date 'YYYY-MM-DD'.
     * @return string 'YYYY-MM-DD', or '' if the date is not valid.
     */
    public static function issue_week_monday( $date ) {
        $dt = self::parse_date( $date );
        if ( ! $dt ) {
            return '';
        }
        if ( 7 === (int) $dt->format( 'N' ) ) {
            $dt->modify( '+1 day' );
        }
        return self::monday_of( $dt )->format( 'Y-m-d' );
    }

    /**
     * "2026–2027" for an issue date. The school year runs August to July:
     * months 8-12 belong to Y–(Y+1), months 1-7 to (Y-1)–Y. A June issue
     * is the year that is ending; an August one is the year about to start.
     *
     * @return string '' when the date is not valid.
     */
    public static function school_year_label( $date ) {
        $dt = self::parse_date( $date );
        if ( ! $dt ) {
            return '';
        }
        $y = (int) $dt->format( 'Y' );
        $m = (int) $dt->format( 'n' );
        $start = ( $m >= 8 ) ? $y : $y - 1;
        return $start . "\xe2\x80\x93" . ( $start + 1 );
    }

    /**
     * Per-row display state for an announcement timeline: past (its day is
     * over, the same rule the event tags use) and deadline (the last row as
     * entered -- rows are never sorted; the volunteer said which is last).
     *
     * @return array[] One array( 'past' => bool, 'deadline' => bool ) per row.
     */
    public static function timeline_states( array $rows, $today ) {
        $states = array();
        $last   = count( $rows ) - 1;
        foreach ( array_values( $rows ) as $i => $row ) {
            $date = is_array( $row ) && isset( $row['date'] ) ? $row['date'] : '';
            $states[] = array(
                'past'     => 'past' === self::relabel_for_date( $date, $today ),
                'deadline' => $i === $last,
            );
        }
        return $states;
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

        $this_monday   = self::monday_of( $today_dt );
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
