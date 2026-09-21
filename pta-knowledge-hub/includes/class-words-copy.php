<?php
/**
 * The words on "Words you've explained" -- the Hub's own home for glossary
 * terms (pta_knowledge entries carrying the `glossary` category).
 *
 * Pure: no database, no capabilities, no WordPress functions, so the
 * wording is testable on its own (see tests/test-words-copy.php) and the
 * screen file (class-words-list.php) stays about layout.
 *
 * No database or WordPress word ("post", "taxonomy", "category", "trash",
 * "excerpt", "CPT") appears here -- a test asserts it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Words_Copy {

    /** The page title. */
    public static function title() {
        return "Words you've explained";
    }

    /** The one-line lead under the title. */
    public static function lead() {
        return 'Every PTA word or acronym you\'ve explained, in plain English -- the same words families read in the glossary.';
    }

    /** The screen's one primary action: explain another word. */
    public static function add_button_label() {
        return 'Explain a word or phrase';
    }

    /** The quiet action on a card: change what it says. */
    public static function change_button_label() {
        return 'Change the wording';
    }

    /** The quiet action on a card: see it where families see it. */
    public static function view_button_label() {
        return 'See it in the glossary';
    }

    /** Search box placeholder. */
    public static function search_placeholder() {
        return 'Type a word, or part of what it means.';
    }

    /**
     * The definition to show for a term -- the short version if there is
     * one, otherwise the first part of the longer writing, trimmed at a
     * word boundary the same way the public glossary page trims it, so the
     * two never disagree about what a term means.
     *
     * @param string $short         The short version, if the volunteer wrote one.
     * @param string $long_plain    The longer writing, tags already stripped.
     * @param int    $word_limit    How many words to keep from the longer writing.
     * @return string
     */
    public static function definition( $short, $long_plain, $word_limit = 30 ) {
        $short = trim( (string) $short );
        if ( '' !== $short ) {
            return $short;
        }
        $long_plain = trim( preg_replace( '/\s+/', ' ', (string) $long_plain ) );
        if ( '' === $long_plain ) {
            return '';
        }
        $words = explode( ' ', $long_plain );
        if ( count( $words ) <= $word_limit ) {
            return $long_plain;
        }
        return implode( ' ', array_slice( $words, 0, $word_limit ) ) . '...';
    }

    /** What a card says when nobody has written a definition yet. */
    public static function no_definition_note() {
        return "Nothing written yet -- change the wording to add what it means.";
    }

    /** A quiet note next to a word nobody has put in front of families yet. */
    public static function not_sent_note() {
        return 'Not sent yet.';
    }

    /** Title and text for the empty state, said in the positive. */
    public static function empty_state() {
        return array(
            'title' => "Nothing explained yet.",
            'text'  => "When you explain a PTA word or acronym, it lands here -- and on the glossary families read.",
        );
    }

    /** The quiet link to the glossary page families actually read. */
    public static function glossary_link_label() {
        return 'See the glossary families read';
    }
}
