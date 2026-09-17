<?php
/**
 * Round 4 -- pure ICS (iCalendar) feed reader, no WordPress and no network.
 *
 * Turns raw .ics text into a flat array of concrete event occurrences
 * within a requested date range. Reuses the hard-won findings from
 * docs/superpowers/specs/2026-07-21-calendar-widget-design.md sections 6
 * and 7 (that spec's widget itself is a separate, not-yet-built feature --
 * this class only borrows its ICS-parsing rules):
 *
 *   - line unfolding (a folded line starts its continuation with a single
 *     space or tab)
 *   - VALUE=DATE (all-day) events are floating dates, never timezone
 *     converted; DTEND is exclusive so it's rendered as the day before
 *   - timed events carry either a bare UTC "Z" timestamp or a TZID --
 *     both are converted to the site timezone for display
 *   - multi-day events render as a range on their start day
 *   - RRULE expansion for DAILY/WEEKLY/MONTHLY with COUNT/UNTIL/BYDAY,
 *     EXDATE removals, RECURRENCE-ID single-occurrence overrides
 *   - STATUS:CANCELLED events are skipped entirely
 *   - TEXT value unescaping (\\ \; \, \n)
 *
 * Everything here is a pure function of its inputs (plus, for RRULE
 * expansion, an explicit "now" range) so tests/test-ics-reader.php can
 * exercise it with no WordPress and no network -- see that file and the
 * two committed real-feed fixtures.
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Allow the plain-PHP test runner (tests/bootstrap.php) to load this
    // file directly outside WordPress.
    if ( ! defined( 'PTK_ICS_READER_STANDALONE' ) ) {
        define( 'PTK_ICS_READER_STANDALONE', true );
    }
}

class PTK_Ics_Reader {

    /** Safety cap: how many occurrences a single RRULE may expand to. */
    const MAX_OCCURRENCES = 60;

    /* ------------------------------------------------------------------
     * Public entry point
     * ----------------------------------------------------------------*/

    /**
     * Parse raw ICS text into event occurrences overlapping [$range_start,
     * $range_end] (inclusive, 'Y-m-d' site-local dates).
     *
     * @param string $ics          Raw .ics body.
     * @param string $range_start  'Y-m-d'.
     * @param string $range_end    'Y-m-d'.
     * @param string $site_tz_name A PHP timezone name, e.g. from wp_timezone()->getName().
     * @return array[] Each: array(
     *   'uid', 'title', 'start' => 'Y-m-d', 'end' => 'Y-m-d' (inclusive display end,
     *   same as start for single-day), 'all_day' => bool,
     *   'time' => 'g:ia' or '' when all-day, 'end_time' => '' or 'g:ia',
     *   'location' => string, 'description' => string,
     * )
     */
    public static function events_in_range( $ics, $range_start, $range_end, $site_tz_name ) {
        $components = self::split_components( self::unfold( (string) $ics ) );
        $tz         = self::safe_timezone( $site_tz_name );

        $range_start_dt = DateTime::createFromFormat( '!Y-m-d', $range_start, $tz );
        $range_end_dt   = DateTime::createFromFormat( '!Y-m-d', $range_end, $tz );
        if ( ! $range_start_dt || ! $range_end_dt ) {
            return array();
        }
        $range_end_dt->setTime( 23, 59, 59 );

        // Pass 1: parse every VEVENT into a normalized shape, separating
        // masters (no RECURRENCE-ID) from overrides (has one), and
        // dropping STATUS:CANCELLED masters outright.
        $masters   = array();
        $overrides = array(); // uid => array( recurrence-id 'Y-m-d' => event )

        foreach ( $components as $raw ) {
            $ev = self::parse_vevent( $raw, $tz );
            if ( null === $ev ) {
                continue;
            }
            if ( '' !== $ev['recurrence_id'] ) {
                $overrides[ $ev['uid'] ][ $ev['recurrence_id'] ] = $ev;
                continue;
            }
            if ( 'CANCELLED' === $ev['status'] ) {
                continue;
            }
            $masters[] = $ev;
        }

        $out = array();

        foreach ( $masters as $ev ) {
            $uid_overrides = isset( $overrides[ $ev['uid'] ] ) ? $overrides[ $ev['uid'] ] : array();

            if ( '' === $ev['rrule'] ) {
                $occ_starts = array( $ev['start'] ); // DateTime
            } else {
                $occ_starts = self::expand_rrule( $ev, $range_start_dt, $range_end_dt );
            }

            foreach ( $occ_starts as $occ_start ) {
                $key = $occ_start->format( 'Y-m-d' );

                if ( isset( $uid_overrides[ $key ] ) ) {
                    $override = $uid_overrides[ $key ];
                    if ( 'CANCELLED' === $override['status'] ) {
                        continue;
                    }
                    $instance = $override;
                } else {
                    $instance          = $ev;
                    $duration          = $ev['end']->getTimestamp() - $ev['start']->getTimestamp();
                    $instance['start'] = clone $occ_start;
                    $instance['end']   = ( clone $occ_start )->setTimestamp( $occ_start->getTimestamp() + $duration );
                }

                $row = self::to_display_row( $instance );
                if ( null === $row ) {
                    continue;
                }

                $ev_start_dt = DateTime::createFromFormat( '!Y-m-d', $row['start'], $tz );
                $ev_end_dt   = DateTime::createFromFormat( '!Y-m-d', $row['end'], $tz );
                if ( ! $ev_start_dt || ! $ev_end_dt ) {
                    continue;
                }
                // Overlap test: event spans [start,end], range spans [range_start,range_end].
                if ( $ev_end_dt < $range_start_dt || $ev_start_dt > $range_end_dt ) {
                    continue;
                }

                $out[] = $row;
            }
        }

        usort( $out, function ( $a, $b ) {
            $c = strcmp( $a['start'], $b['start'] );
            if ( 0 !== $c ) {
                return $c;
            }
            return strcmp( $a['title'], $b['title'] );
        } );

        return $out;
    }

    /* ------------------------------------------------------------------
     * ICS text mechanics
     * ----------------------------------------------------------------*/

    /**
     * Unfold ICS line folding: a line starting with a single space or tab
     * is a continuation of the previous line (the leading whitespace is
     * removed, nothing else). CRLF, LF, and bare CR are all accepted.
     */
    public static function unfold( $ics ) {
        $ics   = str_replace( array( "\r\n", "\r" ), "\n", $ics );
        $lines = explode( "\n", $ics );
        $out   = array();
        foreach ( $lines as $line ) {
            if ( ( '' !== $line ) && ( ' ' === $line[0] || "\t" === $line[0] ) && ! empty( $out ) ) {
                $out[ count( $out ) - 1 ] .= substr( $line, 1 );
            } else {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * @param string[] $lines Unfolded lines.
     * @return array[] Each element is the array of lines between BEGIN:VEVENT / END:VEVENT.
     */
    private static function split_components( $lines ) {
        $events = array();
        $current = null;
        foreach ( $lines as $line ) {
            if ( 0 === strcasecmp( $line, 'BEGIN:VEVENT' ) ) {
                $current = array();
                continue;
            }
            if ( 0 === strcasecmp( $line, 'END:VEVENT' ) ) {
                if ( null !== $current ) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ( null !== $current && '' !== trim( $line ) ) {
                $current[] = $line;
            }
        }
        return $events;
    }

    /**
     * Split "NAME;PARAM=x;PARAM2=y:value" into array('name','params'=>[..],'value'=>'...').
     * Value may itself contain ':' (e.g. a URL), so the split happens on
     * the FIRST unquoted colon after the property name/params.
     */
    private static function parse_line( $line ) {
        $colon = strpos( $line, ':' );
        if ( false === $colon ) {
            return null;
        }
        $left  = substr( $line, 0, $colon );
        $value = substr( $line, $colon + 1 );

        $parts = explode( ';', $left );
        $name  = strtoupper( array_shift( $parts ) );
        $params = array();
        foreach ( $parts as $part ) {
            $eq = strpos( $part, '=' );
            if ( false === $eq ) {
                continue;
            }
            $pname  = strtoupper( substr( $part, 0, $eq ) );
            $pvalue = substr( $part, $eq + 1 );
            $params[ $pname ] = $pvalue;
        }

        return array( 'name' => $name, 'params' => $params, 'value' => $value );
    }

    /**
     * Unescape ICS TEXT value: \\ -> \, \; -> ;, \, -> ',', \n or \N -> newline.
     */
    public static function unescape_text( $value ) {
        $value = (string) $value;
        $out   = '';
        $len   = strlen( $value );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $value[ $i ];
            if ( '\\' === $ch && $i + 1 < $len ) {
                $next = $value[ $i + 1 ];
                if ( 'n' === $next || 'N' === $next ) {
                    $out .= "\n";
                    $i++;
                    continue;
                }
                if ( ';' === $next || ',' === $next || '\\' === $next ) {
                    $out .= $next;
                    $i++;
                    continue;
                }
            }
            $out .= $ch;
        }
        return $out;
    }

    private static function safe_timezone( $name ) {
        try {
            return new DateTimeZone( (string) $name );
        } catch ( Exception $e ) {
            return new DateTimeZone( 'America/New_York' );
        }
    }

    /* ------------------------------------------------------------------
     * VEVENT parsing
     * ----------------------------------------------------------------*/

    /**
     * @param string[]     $lines VEVENT body lines.
     * @param DateTimeZone $tz    Site timezone.
     * @return array|null Normalized event, or null if unparseable (no DTSTART).
     */
    private static function parse_vevent( $lines, DateTimeZone $tz ) {
        $uid            = '';
        $summary        = '';
        $location       = '';
        $description    = '';
        $status         = '';
        $rrule          = '';
        $exdates        = array(); // 'Y-m-d' => true
        $recurrence_id  = '';
        $start          = null;
        $end            = null;
        $all_day        = false;

        foreach ( $lines as $raw_line ) {
            $p = self::parse_line( $raw_line );
            if ( null === $p ) {
                continue;
            }
            switch ( $p['name'] ) {
                case 'UID':
                    $uid = trim( $p['value'] );
                    break;
                case 'SUMMARY':
                    $summary = self::unescape_text( $p['value'] );
                    break;
                case 'LOCATION':
                    $location = self::unescape_text( $p['value'] );
                    break;
                case 'DESCRIPTION':
                    $description = self::unescape_text( $p['value'] );
                    break;
                case 'STATUS':
                    $status = strtoupper( trim( $p['value'] ) );
                    break;
                case 'RRULE':
                    $rrule = trim( $p['value'] );
                    break;
                case 'EXDATE':
                    foreach ( explode( ',', $p['value'] ) as $one ) {
                        $dt = self::parse_ics_datetime( $one, $p['params'], $tz );
                        if ( $dt ) {
                            $exdates[ $dt->format( 'Y-m-d' ) ] = true;
                        }
                    }
                    break;
                case 'RECURRENCE-ID':
                    $dt = self::parse_ics_datetime( $p['value'], $p['params'], $tz );
                    if ( $dt ) {
                        $recurrence_id = $dt->format( 'Y-m-d' );
                    }
                    break;
                case 'DTSTART':
                    $start   = self::parse_ics_datetime( $p['value'], $p['params'], $tz );
                    $all_day = isset( $p['params']['VALUE'] ) && 'DATE' === strtoupper( $p['params']['VALUE'] );
                    break;
                case 'DTEND':
                    $end = self::parse_ics_datetime( $p['value'], $p['params'], $tz );
                    break;
            }
        }

        if ( null === $start ) {
            return null;
        }
        if ( null === $end ) {
            $end = $all_day ? ( clone $start )->modify( '+1 day' ) : clone $start;
        }

        return array(
            'uid'            => $uid,
            'title'          => $summary,
            'location'       => $location,
            'description'    => $description,
            'status'         => $status,
            'rrule'          => $rrule,
            'exdates'        => $exdates,
            'recurrence_id'  => $recurrence_id,
            'start'          => $start,
            'end'            => $end,
            'all_day'        => $all_day,
        );
    }

    /**
     * Parse a DTSTART/DTEND/EXDATE/RECURRENCE-ID value into a DateTime in
     * $tz. Handles VALUE=DATE (floating date, midnight local), bare "Z"
     * UTC, and TZID=... (converted from that named zone).
     */
    private static function parse_ics_datetime( $value, $params, DateTimeZone $tz ) {
        $value = trim( $value );
        $is_date = isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] );

        if ( $is_date || preg_match( '/^\d{8}$/', $value ) ) {
            if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})/', $value, $m ) ) {
                return null;
            }
            return DateTime::createFromFormat( '!Y-m-d', "{$m[1]}-{$m[2]}-{$m[3]}", $tz );
        }

        if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m ) ) {
            return null;
        }
        $y  = $m[1];
        $mo = $m[2];
        $d  = $m[3];
        $h  = $m[4];
        $mi = $m[5];
        $s  = $m[6];
        $z  = isset( $m[7] ) ? $m[7] : '';

        if ( 'Z' === $z ) {
            $dt = DateTime::createFromFormat( '!Y-m-d H:i:s', "$y-$mo-$d $h:$mi:$s", new DateTimeZone( 'UTC' ) );
            if ( $dt ) {
                $dt->setTimezone( $tz );
            }
            return $dt;
        }

        if ( isset( $params['TZID'] ) ) {
            $src_tz = self::safe_timezone( $params['TZID'] );
            $dt     = DateTime::createFromFormat( '!Y-m-d H:i:s', "$y-$mo-$d $h:$mi:$s", $src_tz );
            if ( $dt ) {
                $dt->setTimezone( $tz );
            }
            return $dt;
        }

        // Floating local time, no TZID, no Z: treat as already site-local.
        return DateTime::createFromFormat( '!Y-m-d H:i:s', "$y-$mo-$d $h:$mi:$s", $tz );
    }

    /* ------------------------------------------------------------------
     * RRULE expansion
     * ----------------------------------------------------------------*/

    /**
     * @param array    $ev             Parsed master event (has 'start','end','rrule','exdates').
     * @param DateTime $range_start_dt
     * @param DateTime $range_end_dt
     * @return DateTime[] Occurrence start datetimes overlapping the range (capped, always in-order).
     */
    private static function expand_rrule( $ev, DateTime $range_start_dt, DateTime $range_end_dt ) {
        $rule = self::parse_rrule( $ev['rrule'] );
        if ( null === $rule ) {
            return array( clone $ev['start'] );
        }

        $freq  = $rule['FREQ'];
        $count = isset( $rule['COUNT'] ) ? (int) $rule['COUNT'] : null;
        $until = isset( $rule['UNTIL'] ) ? self::parse_rrule_until( $rule['UNTIL'], $ev['start']->getTimezone() ) : null;
        $byday = isset( $rule['BYDAY'] ) ? explode( ',', $rule['BYDAY'] ) : array();

        $day_map = array( 'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6 );
        $target_days = array();
        foreach ( $byday as $d ) {
            $d = strtoupper( trim( $d ) );
            if ( isset( $day_map[ $d ] ) ) {
                $target_days[] = $day_map[ $d ];
            }
        }

        $occurrences = array();
        $cursor      = clone $ev['start'];
        $produced    = 0;
        $safety      = 0;
        // Extend the search a little past the explicit range end so a
        // weekly/monthly rule that starts before range_start still lands
        // occurrences inside the window.
        $hard_stop = clone $range_end_dt;

        while ( $safety < 5000 && $produced < self::MAX_OCCURRENCES ) {
            $safety++;

            if ( $until && $cursor > $until ) {
                break;
            }
            if ( $cursor > $hard_stop ) {
                break;
            }
            if ( $count && $produced >= $count ) {
                break;
            }

            $matches = true;
            if ( 'WEEKLY' === $freq && ! empty( $target_days ) ) {
                $matches = in_array( (int) $cursor->format( 'w' ), $target_days, true );
            }

            if ( $matches ) {
                $key = $cursor->format( 'Y-m-d' );
                $produced++; // counts toward COUNT even if outside display range
                if ( ! isset( $ev['exdates'][ $key ] ) && $cursor >= $range_start_dt && $cursor <= $range_end_dt ) {
                    $occurrences[] = clone $cursor;
                }
            }

            switch ( $freq ) {
                case 'DAILY':
                    $cursor->modify( '+1 day' );
                    break;
                case 'WEEKLY':
                    if ( empty( $target_days ) ) {
                        $cursor->modify( '+1 week' );
                    } else {
                        $cursor->modify( '+1 day' );
                    }
                    break;
                case 'MONTHLY':
                    $cursor->modify( '+1 month' );
                    break;
                default:
                    // Unknown FREQ: fall back to just the first occurrence.
                    return array( clone $ev['start'] );
            }
        }

        return $occurrences;
    }

    private static function parse_rrule( $rrule ) {
        if ( '' === trim( (string) $rrule ) ) {
            return null;
        }
        $rule = array();
        foreach ( explode( ';', $rrule ) as $part ) {
            $eq = strpos( $part, '=' );
            if ( false === $eq ) {
                continue;
            }
            $rule[ strtoupper( substr( $part, 0, $eq ) ) ] = substr( $part, $eq + 1 );
        }
        if ( empty( $rule['FREQ'] ) ) {
            return null;
        }
        $rule['FREQ'] = strtoupper( $rule['FREQ'] );
        return $rule;
    }

    private static function parse_rrule_until( $value, DateTimeZone $tz ) {
        $value = trim( $value );
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m ) ) {
            $z    = isset( $m[7] ) ? $m[7] : '';
            $zone = ( 'Z' === $z ) ? new DateTimeZone( 'UTC' ) : $tz;
            $dt   = DateTime::createFromFormat( '!Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", $zone );
            if ( $dt && 'Z' === $z ) {
                $dt->setTimezone( $tz );
            }
            return $dt;
        }
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
            return DateTime::createFromFormat( '!Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} 23:59:59", $tz );
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * Display row
     * ----------------------------------------------------------------*/

    /**
     * @param array $instance One concrete occurrence (start/end are DateTime).
     * @return array|null Display row, or null when unrenderable.
     */
    private static function to_display_row( $instance ) {
        $start = $instance['start'];
        $end   = $instance['end'];

        if ( $instance['all_day'] ) {
            // DTEND is exclusive for all-day events: the last real day is
            // one day before it.
            $display_end = ( clone $end )->modify( '-1 day' );
            if ( $display_end < $start ) {
                $display_end = clone $start;
            }
            return array(
                'uid'         => $instance['uid'],
                'title'       => trim( $instance['title'] ),
                'start'       => $start->format( 'Y-m-d' ),
                'end'         => $display_end->format( 'Y-m-d' ),
                'all_day'     => true,
                'time'        => '',
                'end_time'    => '',
                'location'    => trim( $instance['location'] ),
                'description' => trim( $instance['description'] ),
            );
        }

        return array(
            'uid'         => $instance['uid'],
            'title'       => trim( $instance['title'] ),
            'start'       => $start->format( 'Y-m-d' ),
            'end'         => $end->format( 'Y-m-d' ),
            'all_day'     => false,
            'time'        => ltrim( $start->format( 'g:ia' ) ),
            'end_time'    => ltrim( $end->format( 'g:ia' ) ),
            'location'    => trim( $instance['location'] ),
            'description' => trim( $instance['description'] ),
        );
    }
}
