<?php
/**
 * The words on "What families have asked for" -- the Hub's own screen for
 * topic suggestions members send in from the front end.
 *
 * Pure: no database, no capabilities, no WordPress functions, so the
 * wording is testable on its own (see tests/test-asked-for-copy.php) and
 * the screen file (class-asked-for-list.php) stays about layout.
 *
 * The voice is the rest of the Hub's: a member couldn't find something, so
 * they asked, and a volunteer answers it. No moderation or database word
 * ("pending", "queue", "convert", "post", "CPT", "trash") appears here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Asked_For_Copy {

    /** The page title. */
    public static function title() {
        return 'What families have asked for';
    }

    /** The one-line lead under the title. */
    public static function lead() {
        return "A member couldn't find something on the Hub, so they asked. Answer it, and it's here for the next person too.";
    }

    /**
     * The one sentence next to the screen's single stamp. Singular and
     * plural both read correctly; a nonsense count prints nothing.
     *
     * @param int $count How many are waiting.
     * @return string
     */
    public static function waiting_sentence( $count ) {
        $count = (int) $count;
        if ( $count <= 0 ) {
            return '';
        }
        $noun = ( 1 === $count ) ? 'question' : 'questions';
        $verb = ( 1 === $count ) ? 'is' : 'are';
        return $count . ' ' . $noun . ' ' . $verb . ' waiting for you.';
    }

    /**
     * Who asked -- their name, or "A member" when nobody gave one. Plain
     * text; the screen builds the mailto link itself when there's an email.
     *
     * @param string $name
     * @return string
     */
    public static function asked_by( $name ) {
        $name = trim( (string) $name );
        return ( '' === $name ) ? 'A member' : $name;
    }

    /** The primary button: answer it. */
    public static function answer_button_label() {
        return 'Answer it';
    }

    /** The quiet button: remove it. */
    public static function remove_button_label() {
        return 'Remove it';
    }

    /** The "Removed." half of the Removed. Undo banner. */
    public static function removed_notice_text() {
        return 'Removed.';
    }

    /** Title and sentence for the all-caught-up screen, said in the positive. */
    public static function empty_state() {
        return array(
            'title' => 'Nothing is waiting.',
            'text'  => "When a member can't find something on the Hub and asks for it, it lands here so you can answer it.",
        );
    }

    /** The quiet link back to every question that's ever come in, answered or not. */
    public static function see_all_link_label() {
        return "See every question that's come in";
    }
}
