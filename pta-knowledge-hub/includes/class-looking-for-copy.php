<?php
/**
 * The words on "What families are looking for" -- the screen that replaces
 * the old Search Analytics dashboard.
 *
 * Pure: no database, no capabilities, no WordPress functions (not even a
 * WordPress date helper), so the wording is testable on its own (see
 * tests/test-looking-for-copy.php) and the screen file
 * (class-analytics.php) stays about layout and queries.
 *
 * The voice: a family searched and either found nothing (the gap this
 * screen leads with, because it's the thing a volunteer can act on) or
 * found something already written (quieter, underneath). No analytics or
 * database word -- "query", "queries", "log", "results_count", "unique",
 * "avg", "zero-result", or the letters "CSV" -- appears anywhere here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Looking_For_Copy {

    /** The page title. */
    public static function title() {
        return 'What families are looking for';
    }

    /** The one-line lead under the title. */
    public static function lead() {
        return 'See what people search for on the Hub, so you know what to write next.';
    }

    /**
     * The sentence next to the screen's one stamp, over the topics that
     * turned up nothing. Singular and plural both read correctly; a
     * nonsense count prints nothing.
     *
     * @param int $count How many topics are waiting for an answer.
     * @return string
     */
    public static function gaps_waiting_sentence( $count ) {
        $count = (int) $count;
        if ( $count <= 0 ) {
            return '';
        }
        $noun = ( 1 === $count ) ? 'topic' : 'topics';
        $verb = ( 1 === $count ) ? 'is' : 'are';
        return $count . ' ' . $noun . ' ' . $verb . ' waiting for an answer.';
    }

    /** The primary button on a gap: write the missing answer. */
    public static function write_answer_button_label() {
        return 'Write the answer';
    }

    /**
     * "9 people looked for this, most recently on Tuesday." No raw
     * timestamp -- $when is already plain words (see when_word()).
     *
     * @param int    $count How many times this was searched for.
     * @param string $when  Plain words for the most recent time, e.g.
     *                      "today", "yesterday", "on Tuesday", "on September 2".
     * @return string
     */
    public static function looked_sentence( $count, $when ) {
        $count = (int) $count;
        $who   = ( 1 === $count ) ? 'person' : 'people';
        $when  = trim( (string) $when );
        $out   = $count . ' ' . $who . ' looked for this';
        if ( '' !== $when ) {
            $out .= ', most recently ' . $when;
        }
        return $out . '.';
    }

    /**
     * Plain words for how long ago something happened, with no raw
     * timestamp. Deliberately coarse: today, yesterday, a weekday name for
     * the last week, then a month and day.
     *
     * @param int $now  Current unix timestamp.
     * @param int $then The timestamp to describe. Must not be after $now.
     * @return string "today" / "yesterday" / "on Tuesday" / "on September 2".
     */
    public static function when_word( $now, $then ) {
        $now  = (int) $now;
        $then = (int) $then;
        if ( $then > $now ) {
            $then = $now;
        }
        $days = intdiv( $now - $then, 86400 );
        if ( $days <= 0 ) {
            return 'today';
        }
        if ( 1 === $days ) {
            return 'yesterday';
        }
        if ( $days < 7 ) {
            return 'on ' . gmdate( 'l', $then );
        }
        return 'on ' . gmdate( 'F j', $then );
    }

    /** Title and sentence for when nothing came up empty, said warmly. */
    public static function gaps_empty() {
        return array(
            'title' => 'Every search found something.',
            'text'  => "When a family searches for something the Hub doesn't have yet, it'll land here so you can write the answer.",
        );
    }

    /** The quieter heading over what people did find. */
    public static function found_heading() {
        return 'What they found';
    }

    /** How many times a found term was searched for. */
    public static function found_meta( $count ) {
        $count = (int) $count;
        return ( 1 === $count ) ? 'Found once' : 'Found ' . $count . ' times';
    }

    /** The heading over the folded numbers. */
    public static function numbers_heading() {
        return 'The numbers';
    }

    /** The closed-fold summary: how many searches, over which range. */
    public static function numbers_summary( $total, $range_key ) {
        $total = (int) $total;
        $noun  = ( 1 === $total ) ? 'search' : 'searches';
        return $total . ' ' . $noun . ', ' . lcfirst( self::range_label( $range_key ) ) . '.';
    }

    /** A time range, in words instead of a number of days. */
    public static function range_label( $key ) {
        $labels = array(
            '7'   => 'This week',
            '30'  => 'This month',
            '90'  => 'Last 3 months',
            '365' => 'This year',
            'all' => 'All time',
        );
        return isset( $labels[ $key ] ) ? $labels[ $key ] : $labels['30'];
    }

    /** The four folded stat labels. */
    public static function stat_total_label() {
        return 'Searches';
    }

    public static function stat_different_label() {
        return 'Different things people searched for';
    }

    public static function stat_typical_label() {
        return 'How many answers, typically';
    }

    public static function stat_nothing_label() {
        return 'Found nothing';
    }

    /** The heading over the simple bar-by-day chart. */
    public static function chart_heading() {
        return 'Searches by day';
    }

    /** The spreadsheet download button -- never the letters "CSV". */
    public static function export_button_label() {
        return 'Download the spreadsheet';
    }

    /** The heading over the folded recent-searches list. */
    public static function recent_heading() {
        return 'Recent searches';
    }

    /** The closed-fold summary for recent searches. */
    public static function recent_summary( $count ) {
        $count = (int) $count;
        if ( $count <= 0 ) {
            return 'Nothing yet.';
        }
        $noun = ( 1 === $count ) ? 'search' : 'searches';
        return 'The last ' . $count . ' ' . $noun . '.';
    }

    /** What one recent search turned up. */
    public static function recent_result_meta( $count ) {
        $count = (int) $count;
        if ( $count <= 0 ) {
            return 'Found nothing';
        }
        return ( 1 === $count ) ? 'Found 1 answer' : 'Found ' . $count . ' answers';
    }
}
