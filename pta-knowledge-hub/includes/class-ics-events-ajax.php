<?php
/**
 * Round 4 -- server side of "Add from your calendar" (step 2 of the
 * newsletter builder). Fetches the school's Google Calendar (set on
 * Newsletter settings, PTK_Share_Settings::GCAL_OPTION), caches the raw
 * feed in a transient for ~10 minutes, and hands assets/js/newsletter-builder.js
 * a small JSON list of events for the requested range chip.
 *
 * The fetch + cache are the only WordPress-coupled part; the actual ICS
 * parsing is PTK_Ics_Reader (pure, unit-tested separately).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-ics-reader.php';
require_once __DIR__ . '/class-share-settings.php';
require_once __DIR__ . '/class-newsletter-data.php';

class PTK_Ics_Events_Ajax {

    const ACTION      = 'ptk_calendar_events';
    const CACHE_TTL    = 10 * MINUTE_IN_SECONDS;
    const CACHE_PREFIX = 'ptk_gcal_feed_';

    public static function init() {
        add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    /**
     * @param string $url The calendar's .ics URL.
     * @return string Transient key for that URL's raw feed cache.
     */
    public static function cache_key( $url ) {
        return self::CACHE_PREFIX . md5( (string) $url );
    }

    /**
     * Work out the [start,end] 'Y-m-d' range for a range-chip key, given
     * the issue date. "This week" is the ISSUE's week (Mon-Sun) via the
     * same issue_week_monday() logic the rest of the builder already uses
     * -- not today's week -- so the default matches what the newsletter is
     * actually covering.
     *
     * @param string $range_key 'this_week' | 'next_week' | 'next_2_weeks' | 'this_month' | 'next_3_months'.
     * @param string $issue_date 'Y-m-d', may be ''.
     * @return array{start:string,end:string}|null
     */
    public static function range_for( $range_key, $issue_date ) {
        $monday = PTK_Newsletter_Data::issue_week_monday( '' !== trim( (string) $issue_date ) ? $issue_date : gmdate( 'Y-m-d' ) );
        $monday_dt = DateTime::createFromFormat( '!Y-m-d', $monday );
        if ( ! $monday_dt ) {
            return null;
        }

        switch ( $range_key ) {
            case 'next_week':
                $start = ( clone $monday_dt )->modify( '+7 days' );
                $end   = ( clone $start )->modify( '+6 days' );
                break;
            case 'next_2_weeks':
                $start = clone $monday_dt;
                $end   = ( clone $start )->modify( '+13 days' );
                break;
            case 'this_month':
                // From the issue week to the end of its month -- dates
                // already past are no use in a newsletter. The week's
                // Thursday decides the month, so a week that straddles
                // two months counts as the month most of it is in.
                $start = clone $monday_dt;
                $end   = ( clone $monday_dt )->modify( '+3 days' )->modify( 'last day of this month' );
                break;
            case 'next_3_months':
                // A long-range chip for schools that like to plan ahead:
                // the issue week's Monday through the day before the same
                // date 3 months later (e.g. Mon 2026-09-14 -> 2026-12-13),
                // matching the exclusive-end convention "this_month" uses.
                $start = clone $monday_dt;
                $end   = ( clone $monday_dt )->modify( '+3 months' )->modify( '-1 day' );
                break;
            case 'this_week':
            default:
                $start = clone $monday_dt;
                $end   = ( clone $start )->modify( '+6 days' );
                break;
        }

        return array( 'start' => $start->format( 'Y-m-d' ), 'end' => $end->format( 'Y-m-d' ) );
    }

    /**
     * Very small classifier, reusing the title-keyword rules from
     * docs/superpowers/specs/2026-07-21-calendar-widget-design.md §4.1
     * (word-boundary matching, parenthetical segments stripped first, an
     * explicit "school open" always wins). No feed-source or UID rules
     * here -- this round has exactly one configured calendar, so there is
     * no second feed to distinguish "School date" from "PTA event" by;
     * everything that isn't a closure or a schedule change is left
     * unlabeled rather than guessed at.
     *
     * @param string $title
     * @return string '' | 'No school' | 'Special schedule'
     */
    public static function classify_title( $title ) {
        $stripped = trim( preg_replace( '/\([^)]*\)/', '', (string) $title ) );

        if ( preg_match( '/\bschool\s*open\b|\bopen\b/i', $stripped ) ) {
            return '';
        }
        if ( preg_match( '/\b(closed|recess|no\s*school|break)\b/i', $stripped ) ) {
            return 'No school';
        }
        if ( preg_match( '/\b(abbreviated|early\s*dismissal|delayed\s*opening|conferences|p\/t\s*conf)\b/i', $stripped ) ) {
            return 'Special schedule';
        }
        return '';
    }

    public static function handle() {
        check_ajax_referer( 'ptk_calendar_events', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $url = (string) get_option( PTK_Share_Settings::GCAL_OPTION, '' );
        if ( '' === $url ) {
            wp_send_json_error( array( 'message' => 'No calendar is set up yet.', 'code' => 'not_configured' ) );
        }

        $range_key  = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'this_week';
        $issue_date = isset( $_POST['issue_date'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_date'] ) ) : '';
        $refresh    = ! empty( $_POST['refresh'] );

        $range = self::range_for( $range_key, $issue_date );
        if ( null === $range ) {
            wp_send_json_error( array( 'message' => 'Couldn’t work out that date range.' ) );
        }

        $body = self::fetch_feed( $url, $refresh );
        if ( is_wp_error( $body ) ) {
            $code    = $body->get_error_code();
            $message = ( 'not_public' === $code )
                ? 'Your calendar isn’t public yet — here’s how to fix it: open Google Calendar, go to your calendar’s Settings, then “Access permissions,” and turn on “Make available to public.”'
                : 'Couldn’t reach your calendar. Try again in a minute.';
            wp_send_json_error( array( 'message' => $message, 'code' => $code ) );
        }

        $events = PTK_Ics_Reader::events_in_range( $body, $range['start'], $range['end'], wp_timezone_string() );

        $rows = array();
        foreach ( $events as $e ) {
            $rows[] = array(
                'uid'         => $e['uid'],
                'title'       => $e['title'],
                'start'       => $e['start'],
                'end'         => $e['end'],
                'all_day'     => $e['all_day'],
                'time'        => $e['time'],
                'end_time'    => $e['end_time'],
                'location'    => $e['location'],
                'tag'         => self::classify_title( $e['title'] ),
            );
        }

        wp_send_json_success( array(
            'range'  => $range,
            'events' => $rows,
        ) );
    }

    /**
     * @param string $url
     * @param bool   $bypass_cache
     * @return string|WP_Error Raw .ics body, or a WP_Error with code 'not_public' | 'unreachable' | 'not_calendar'.
     */
    private static function fetch_feed( $url, $bypass_cache ) {
        $key = self::cache_key( $url );

        if ( ! $bypass_cache ) {
            $cached = get_transient( $key );
            if ( false !== $cached && is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        $response = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3 ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'unreachable', 'Couldn’t reach your calendar.' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( 404 === $code || 410 === $code ) {
            return new WP_Error( 'not_public', 'Calendar not public.' );
        }
        if ( $code < 200 || $code >= 300 || false === strpos( $body, 'BEGIN:VCALENDAR' ) ) {
            return new WP_Error( 'unreachable', 'Couldn’t reach your calendar.' );
        }

        set_transient( $key, $body, self::CACHE_TTL );

        return $body;
    }
}
