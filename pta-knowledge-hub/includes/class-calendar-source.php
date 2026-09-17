<?php
/**
 * Round 4 -- turns whatever a volunteer pastes for "Google Calendar (for
 * adding events)" into the one canonical shape everything else needs: a
 * public .ics URL. Pure PHP, no WordPress, no network -- unit-tested in
 * tests/test-calendar-source.php.
 *
 * Accepts, per the plan:
 *   - the public iCal address itself (…/ical/<id>/public/basic.ics)
 *   - a bare calendar id (…@group.calendar.google.com or …@gmail.com)
 *   - a "share this calendar" embed link
 *     (…/calendar/embed?src=<id>&…) or a short "cid=" link
 *     (…/calendar/u/0/r?cid=<base64 id>) -- both come off Google Calendar's
 *     own "Get shareable link" button, so volunteers commonly paste these
 *     by mistake instead of the iCal address the help text asks for.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'PTK_CALENDAR_SOURCE_STANDALONE', true );
}

class PTK_Calendar_Source {

    /**
     * @param mixed $raw What the volunteer pasted.
     * @return array{url:string,error:string} url '' + error '' means "clear it" (empty input).
     */
    public static function normalize( $raw ) {
        $value = is_string( $raw ) ? trim( $raw ) : '';

        if ( '' === $value ) {
            return array( 'url' => '', 'error' => '' );
        }

        // Already a public .ics address -- normalize scheme and encoding,
        // otherwise pass through untouched.
        if ( preg_match( '#^https?://calendar\.google\.com/calendar/ical/([^/]+)/public/basic\.ics(\?.*)?$#i', $value, $m ) ) {
            return array( 'url' => self::build_ics_url( rawurldecode( $m[1] ) ), 'error' => '' );
        }

        // A bare calendar id: name@group.calendar.google.com, or a plain
        // gmail/G Suite address used as a personal calendar id.
        if ( preg_match( '/^[^\s@]+@(group\.calendar\.google\.com|gmail\.com|[a-z0-9.\-]+\.[a-z]{2,})$/i', $value ) && false === strpos( $value, '://' ) ) {
            return array( 'url' => self::build_ics_url( $value ), 'error' => '' );
        }

        // An embed / share link: .../calendar/embed?src=<id>&... or
        // .../calendar/u/0/r?cid=<base64 id>
        $query = self::query_params( $value );
        if ( null !== $query ) {
            if ( ! empty( $query['src'] ) ) {
                return array( 'url' => self::build_ics_url( rawurldecode( $query['src'] ) ), 'error' => '' );
            }
            if ( ! empty( $query['cid'] ) ) {
                $decoded = self::decode_cid( $query['cid'] );
                if ( '' !== $decoded ) {
                    return array( 'url' => self::build_ics_url( $decoded ), 'error' => '' );
                }
            }
        }

        return array(
            'url'   => '',
            'error' => 'That doesn’t look like a Google Calendar address. In Google Calendar, open the calendar’s Settings, then “Integrate calendar,” and paste the “Public address in iCal format.”',
        );
    }

    /**
     * @param string $id A calendar id, already decoded (contains '@').
     * @return string
     */
    private static function build_ics_url( $id ) {
        $id = trim( $id );
        return 'https://calendar.google.com/calendar/ical/' . rawurlencode( $id ) . '/public/basic.ics';
    }

    /**
     * @param string $url
     * @return array|null Query params (urldecoded keys, raw values), or null if not a URL at all.
     */
    private static function query_params( $url ) {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
            return is_array( $parts ) ? array() : null;
        }
        $out = array();
        parse_str( $parts['query'], $out );
        return $out;
    }

    /**
     * Google's short "cid" share links carry the calendar id base64-encoded
     * (URL-safe alphabet, no padding). Decode and sanity-check it looks
     * like a calendar id before trusting it.
     *
     * @param string $cid
     * @return string Decoded id, or '' if it doesn't decode to something id-shaped.
     */
    private static function decode_cid( $cid ) {
        $b64 = strtr( (string) $cid, '-_', '+/' );
        $pad = strlen( $b64 ) % 4;
        if ( $pad > 0 ) {
            $b64 .= str_repeat( '=', 4 - $pad );
        }
        $decoded = base64_decode( $b64, true );
        if ( false === $decoded ) {
            return '';
        }
        $decoded = trim( $decoded );
        if ( false !== strpos( $decoded, '@' ) ) {
            return $decoded;
        }
        return '';
    }
}
