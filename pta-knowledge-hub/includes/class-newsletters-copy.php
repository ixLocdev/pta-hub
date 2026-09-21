<?php
/**
 * The words on "Your newsletters" -- the Hub's own home for the newsletters
 * this school has written (pta_newsletter posts), replacing WordPress's own
 * "All Newsletters" list table (bulk actions, an SEO Details column, an
 * Author column, "Trash (3)") as the place a volunteer actually works.
 *
 * Pure: no database, no capabilities, no WordPress functions beyond the
 * handful of date helpers that are plain PHP (DateTime, gmdate -- no
 * WordPress translation wrappers), so the wording is testable on its own
 * (see tests/test-newsletters-copy.php) and the screen file
 * (class-newsletters-list.php) stays about layout.
 *
 * No database or WordPress word ("post", "draft", "trash", "publish",
 * "custom post type", "SEO", "author") appears in what a volunteer reads --
 * a test asserts it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Newsletters_Copy {

    /** The page title. */
    public static function title() {
        return 'Your newsletters';
    }

    /** The one-line lead under the title. */
    public static function lead() {
        return "Every newsletter this school has written, newest first \u{2014} the ones families have already seen, and the ones still being put together.";
    }

    /** The screen's one primary action: start a new issue. */
    public static function add_button_label() {
        return 'Start a new newsletter';
    }

    /** The quiet action on a card: open it in the Builder to change it. */
    public static function open_button_label() {
        return 'Open it';
    }

    /** The quiet action on a card: see it the way families see it. */
    public static function view_button_label() {
        return 'See what families see';
    }

    /**
     * The note a card shows in place of a link to families, when there is
     * nothing yet for families to see. Never a dead link -- say so instead.
     */
    public static function not_sent_note() {
        return "Not sent yet \u{2014} there's nothing here for families to see yet.";
    }

    /**
     * The issue number and date, in plain words -- "No. 41 -- September 21,
     * 2026". Either half may be missing (a newsletter started before an
     * issue number or date was filled in): what's known is still shown.
     *
     * @param int|string $issue     Issue number, or '' / 0 if unknown.
     * @param string     $date_ymd  'YYYY-MM-DD', or '' if unknown.
     * @return string
     */
    public static function issue_line( $issue, $date_ymd ) {
        $issue = (int) $issue;
        $parts = array();
        if ( $issue > 0 ) {
            $parts[] = 'No. ' . $issue;
        }
        $date_words = self::date_words( $date_ymd );
        if ( '' !== $date_words ) {
            $parts[] = $date_words;
        }
        return implode( " \u{2014} ", $parts );
    }

    /**
     * The one newsletter nobody should send: the worked example the plugin
     * writes for a new school. Its own headline is a real-looking "Week of
     * ..." line, so without this the card is indistinguishable from a draft
     * somebody started.
     */
    public static function example_note() {
        return "This one is here to look at \u{2014} open it to see how a finished newsletter is put together, but don't send it.";
    }

    /**
     * A 'YYYY-MM-DD' date in plain words -- "September 21, 2026". Empty or
     * malformed input comes back empty rather than guessing.
     *
     * @param string $date_ymd
     * @return string
     */
    public static function date_words( $date_ymd ) {
        $date_ymd = trim( (string) $date_ymd );
        if ( '' === $date_ymd || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date_ymd, $m ) ) {
            return '';
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return '';
        }
        $dt = DateTime::createFromFormat( '!Y-m-d', $date_ymd );
        if ( ! $dt ) {
            return '';
        }
        return $dt->format( 'F j, Y' );
    }

    /** Title and text for the empty state, said in the positive. */
    public static function empty_state() {
        return array(
            'title' => "You haven't written one yet.",
            'text'  => "When you start a newsletter, it lands here \u{2014} whether it has gone out to families yet or not.",
        );
    }

    /** The quiet link to WordPress's own list, the second door left open. */
    public static function see_all_link_label() {
        return "See WordPress's own list";
    }
}
