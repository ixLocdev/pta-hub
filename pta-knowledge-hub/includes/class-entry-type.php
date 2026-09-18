<?php
/**
 * Working out what kind of entry this is, from what the volunteer wrote --
 * spec §3 ("Choosing the type"), plan Task 1.
 *
 * Pure: no WordPress calls, no state, no side effects. guess() takes a flat
 * array of signals and returns a category slug; explain() takes a slug and
 * returns the quiet line's reason clause. Both are safe to unit-test with
 * plain php (see tests/test-entry-type.php) and safe to call from PHP on
 * every request -- the caller decides whether an explicit/locked choice
 * should be used instead of calling guess() at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Entry_Type {

    /**
     * Work out the category slug from what the volunteer has told us so
     * far. Ordered, first match wins -- spec §3's table, in the same order.
     *
     * Signals (all optional; missing keys behave like their empty value):
     *   'came_from'         string  'word' | 'question' | ''
     *   'step_count'        int     number of non-empty steps typed
     *   'has_date'          bool    a date follow-up was answered
     *   'has_file_or_link'  bool    the file/link follow-up was answered
     *   'question'          string  the question/title text
     *   'answer'            string  the answer text
     *
     * @param array $signals
     * @return string One of the eight category slugs.
     */
    public static function guess( array $signals ) {
        $came_from        = isset( $signals['came_from'] ) ? (string) $signals['came_from'] : '';
        $step_count        = isset( $signals['step_count'] ) ? (int) $signals['step_count'] : 0;
        $has_date          = ! empty( $signals['has_date'] );
        $has_file_or_link  = ! empty( $signals['has_file_or_link'] );
        $question          = isset( $signals['question'] ) ? (string) $signals['question'] : '';
        $answer            = isset( $signals['answer'] ) ? (string) $signals['answer'] : '';

        // 1. Came from "Explain a PTA word" -- beats everything else.
        if ( 'word' === $came_from ) {
            return 'glossary';
        }

        // 2. Two or more steps.
        if ( $step_count >= 2 ) {
            return 'how-to-guide';
        }

        // 3. A date, plus (some, but fewer than two) steps.
        if ( $has_date && $step_count >= 1 ) {
            return 'event-playbook';
        }

        // 4. A file or link, and little else (no steps, no date).
        if ( $has_file_or_link && 0 === $step_count && ! $has_date ) {
            return 'resource';
        }

        // 5. The question starts with an FAQ-shaped opener.
        if ( self::looks_like_faq_question( $question ) ) {
            return 'faq';
        }

        // 6. The answer reads like a list of things to tick off.
        if ( self::looks_like_checklist( $answer ) ) {
            return 'checklist';
        }

        // 7. The question mentions rules, policy, bylaws, allowed, must.
        if ( self::mentions_policy( $question ) ) {
            return 'policy';
        }

        // 8. Nothing above.
        return 'faq';
    }

    /**
     * True when $question starts with one of the FAQ-shaped openers
     * (spec §3, case-insensitive, allowing for leading whitespace).
     *
     * @param string $question
     * @return bool
     */
    private static function looks_like_faq_question( $question ) {
        $question = ltrim( (string) $question );
        if ( '' === $question ) {
            return false;
        }
        $openers = array( 'can i', 'do i', 'is', 'are', 'when', 'where', 'how much' );
        foreach ( $openers as $opener ) {
            $len = strlen( $opener );
            if ( 0 === strcasecmp( substr( $question, 0, $len ), $opener ) ) {
                // Must be a whole word, not e.g. "Isabelle" matching "is".
                $next = substr( $question, $len, 1 );
                if ( '' === $next || false !== strpos( " \t\r\n,.?!'", $next ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * True when $answer reads like a list of things to tick off: two or
     * more lines starting with a dash (spec §3: "lines starting with a
     * dash").
     *
     * @param string $answer
     * @return bool
     */
    private static function looks_like_checklist( $answer ) {
        $answer = (string) $answer;
        if ( '' === trim( $answer ) ) {
            return false;
        }
        $lines     = preg_split( '/\r\n|\r|\n/', $answer );
        $dash_count = 0;
        foreach ( $lines as $line ) {
            $line = ltrim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( 0 === strpos( $line, '-' ) ) {
                $dash_count++;
            }
        }
        return $dash_count >= 2;
    }

    /**
     * True when $question mentions rules, policy, bylaws, allowed or must
     * (spec §3, case-insensitive, whole-word-ish substring match).
     *
     * @param string $question
     * @return bool
     */
    private static function mentions_policy( $question ) {
        $question = strtolower( (string) $question );
        if ( '' === trim( $question ) ) {
            return false;
        }
        $words = array( 'rules', 'rule', 'policy', 'bylaws', 'allowed', 'must' );
        foreach ( $words as $word ) {
            if ( false !== strpos( $question, $word ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The quiet line's reason clause for a category slug -- "so families
     * see the steps as a numbered list" for how-to-guide, and so on. Every
     * one of the eight has an entry; an unknown slug returns ''.
     *
     * @param string $slug
     * @return string
     */
    public static function explain( $slug ) {
        $map = array(
            'how-to-guide'   => 'so families see the steps as a numbered list',
            'faq'            => 'so families see a short, copy-friendly answer',
            'glossary'       => 'so the word gets a plain-English definition and shows up as a tooltip',
            'checklist'      => 'so families see it as a list of things to tick off',
            'event-playbook' => "so families see the date, the timeline, and what's needed",
            'policy'         => "so families see exactly what the rule says",
            'resource'       => 'so families can find and open the file or page',
        );
        return isset( $map[ $slug ] ) ? $map[ $slug ] : '';
    }

    /** The eight category names, slug => label -- same set as the wizard's own default_categories. */
    public static function names() {
        return array(
            'how-to-guide'   => 'how-to guide',
            'faq'            => 'FAQ',
            'glossary'       => 'glossary term',
            'checklist'      => 'checklist',
            'event-playbook' => 'event playbook',
            'policy'         => 'policy',
            'resource'       => 'resource',
        );
    }
}
