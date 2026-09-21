<?php
/**
 * "I'm not sure where to start" -- turns a sentence a volunteer types into
 * the Hub screen they meant, and carries their words there.
 *
 * Pure and offline: no database, no capabilities, no network, no WordPress
 * functions in the routing itself, so tests run it directly and the same
 * answer comes back every time. Nothing here decides what a volunteer is
 * allowed to do -- the caller passes the intentions that are actually on
 * their home screen, and this class only ranks those.
 *
 * It never guesses silently. route() reports one of three states and the
 * screen always shows the volunteer what it thinks before anything happens:
 *
 *   confident -- one clear winner, offered as "That sounds like ..."
 *   choices   -- something matched, but not clearly: the best two or three
 *   none      -- nothing matched: every choice, written as a sentence
 *
 * Adding a word: put multi-word phrases and unmistakable single words in
 * `strong` (3 points), ordinary words that only hint in `plain` (1 point).
 * A phrase matches anywhere in the sentence; a single word matches whole
 * words only, so "old" never fires inside "household".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Hub_Router {

    /** A winner needs at least this much, and this much more than the runner-up. */
    const CONFIDENT_AT    = 3;
    const CONFIDENT_AHEAD = 2;

    /** Most guesses offered at once when nothing is clearly ahead. */
    const MAX_CHOICES = 3;

    /** What each intention sounds like. Order is the home screen's order. */
    public static function keywords() {
        return array(
            'newsletter' => array(
                'strong' => array(
                    'newsletter', 'tell families', 'tell parents', 'let families know',
                    'let parents know', 'coming up', 'spirit night', 'book fair',
                    'bake sale', 'fun run', 'movie night', 'family night', 'field day',
                    'sign up to help', 'volunteer for', 'volunteers for', 'we need volunteers',
                    'next meeting', 'pta meeting', 'send out', 'goes out', 'this week',
                    'next week', 'remind everyone', 'get the word out',
                ),
                'plain'  => array(
                    'meeting', 'event', 'happening', 'announce', 'announcement',
                    'remind', 'reminder', 'tonight', 'tomorrow', 'monday', 'tuesday',
                    'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'flyer',
                    'fundraiser', 'volunteer', 'volunteers', 'rsvp', 'tickets',
                    'dance', 'carnival', 'picnic', 'festival',
                ),
            ),
            'answer'     => array(
                'strong' => array(
                    'keep asking', 'keeps asking', 'kept asking', 'keep emailing',
                    'keeps emailing', 'keep calling', 'always ask', 'always asking',
                    'parents ask', 'people ask', 'same question', 'over and over',
                    'want to know', 'wants to know', 'asked me', 'how do i', 'how do you',
                    'where do i', 'where do we', 'what time', 'do i need to', 'am i supposed to',
                ),
                'plain'  => array(
                    'question', 'questions', 'asking', 'ask', 'asked', 'emailing',
                    'email', 'emails', 'wondering', 'faq', 'confused', 'unclear',
                    'nobody knows', 'how', 'where', 'when',
                ),
            ),
            'vendor'     => array(
                'strong' => array(
                    'we used', 'we hired', 'did a great job', 'was great', 'were great',
                    'was amazing', 'were amazing', 'use them again', 'recommend them',
                    'bounce house', 'face painter', 'food truck', 'party rental',
                    'porta potty', 'yearbook company', 'photo company',
                ),
                'plain'  => array(
                    'vendor', 'vendors', 'dj', 'caterer', 'catering', 'photographer',
                    'photography', 'company', 'recommend', 'recommendation', 'hired',
                    'printer', 'band', 'magician', 'balloon', 'rental', 'contractor',
                    'florist', 'baker', 'bakery',
                ),
            ),
            'word'       => array(
                'strong' => array(
                    'what does', 'stands for', 'short for', 'means', 'what is a',
                    'never heard of', 'no idea what', 'asked what', 'acronym',
                    'abbreviation', 'jargon', 'plain english',
                ),
                'plain'  => array(
                    'mean', 'meaning', 'term', 'initials', 'ase', 'givebacks',
                    'ptso', 'pto',
                ),
            ),
            'fix'        => array(
                'strong' => array(
                    'typo', 'misspelled', 'spelled wrong', 'out of date', 'out-of-date',
                    'wrong date', 'wrong time', 'is wrong', 'was wrong', 'not right',
                    'incorrect', 'needs updating', 'needs to be updated', 'broken link',
                    'says the wrong', 'used to be', 'no longer',
                ),
                'plain'  => array(
                    'fix', 'wrong', 'mistake', 'outdated', 'update', 'change',
                    'correct', 'edit', 'broken', 'error', 'remove', 'delete', 'old',
                ),
            ),
        );
    }

    /**
     * Lowercase, curly quotes straightened, punctuation turned into spaces,
     * so "Parents keep e-mailing -- about pickup!" matches the same as the
     * plain words. Spaces at both ends, so a whole-word test is a simple
     * " word " search.
     */
    public static function normalize( $text ) {
        $text = (string) $text;
        $text = str_replace(
            array( "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x93", "\xe2\x80\x94" ),
            array( "'", "'", '"', '"', '-', '-' ),
            $text
        );
        $text = strtolower( $text );
        // Keep letters, digits, spaces and the two marks that live inside
        // words we match on ("out-of-date", "don't").
        $text = preg_replace( "/[^a-z0-9'\\- ]+/", ' ', $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return ' ' . trim( $text ) . ' ';
    }

    /** True when $needle appears in the normalized $haystack as a whole word (or phrase). */
    private static function has( $haystack, $needle ) {
        $needle = trim( strtolower( $needle ) );
        if ( '' === $needle ) {
            return false;
        }
        return false !== strpos( $haystack, ' ' . $needle . ' ' )
            || false !== strpos( $haystack, ' ' . $needle . "'" );
    }

    /**
     * Points per intention for one sentence. Keys with no points are left out.
     *
     * @return int[] key => score, unsorted.
     */
    public static function score( $sentence ) {
        $text   = self::normalize( $sentence );
        $scores = array();

        foreach ( self::keywords() as $key => $lists ) {
            $points = 0;
            foreach ( $lists['strong'] as $needle ) {
                if ( self::has( $text, $needle ) ) {
                    $points += 3;
                }
            }
            foreach ( $lists['plain'] as $needle ) {
                if ( self::has( $text, $needle ) ) {
                    $points += 1;
                }
            }
            if ( $points > 0 ) {
                $scores[ $key ] = $points;
            }
        }

        return $scores;
    }

    /**
     * What to do with what the volunteer typed.
     *
     * @param string   $sentence  Their words, as typed.
     * @param string[] $available Intention keys really on their home screen.
     * @return array{sentence:string,state:string,matches:array<int,array{key:string,score:int}>}
     *         state is 'confident', 'choices' or 'none'.
     */
    public static function route( $sentence, array $available ) {
        $sentence = trim( preg_replace( '/\s+/', ' ', (string) $sentence ) );

        $result = array(
            'sentence' => $sentence,
            'state'    => 'none',
            'matches'  => array(),
        );

        if ( '' === $sentence ) {
            return $result;
        }

        $scores = self::score( $sentence );

        // Only rank what this volunteer can actually reach.
        foreach ( array_keys( $scores ) as $key ) {
            if ( ! in_array( $key, $available, true ) ) {
                unset( $scores[ $key ] );
            }
        }
        if ( empty( $scores ) ) {
            return $result;
        }

        // Highest first; ties keep the home screen's order, so the same
        // sentence always produces the same list.
        $order = array_keys( self::keywords() );
        uksort(
            $scores,
            function ( $a, $b ) use ( $scores, $order ) {
                if ( $scores[ $a ] !== $scores[ $b ] ) {
                    return ( $scores[ $a ] < $scores[ $b ] ) ? 1 : -1;
                }
                return array_search( $a, $order, true ) - array_search( $b, $order, true );
            }
        );

        $ranked = array();
        foreach ( $scores as $key => $points ) {
            $ranked[] = array( 'key' => $key, 'score' => $points );
        }

        $top    = $ranked[0]['score'];
        $second = isset( $ranked[1] ) ? $ranked[1]['score'] : 0;

        if ( $top >= self::CONFIDENT_AT && ( $top - $second ) >= self::CONFIDENT_AHEAD ) {
            $result['state']   = 'confident';
            $result['matches'] = array( $ranked[0] );
            return $result;
        }

        $result['state']   = 'choices';
        $result['matches'] = array_slice( $ranked, 0, self::MAX_CHOICES );
        return $result;
    }

    /** Words too common to search on. */
    const STOPWORDS = array(
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'do', 'does', 'for',
        'from', 'has', 'have', 'i', 'in', 'is', 'it', 'its', 'me', 'my', 'need',
        'needs', 'of', 'on', 'or', 'our', 'out', 'page', 'said', 'says', 'should',
        'site', 'so', 'some', 'somebody', 'someone', 'that', 'the', 'their', 'then',
        'there', 'these', 'they', 'this', 'to', 'up', 'us', 'was', 'we', 'website',
        'were', 'what', 'when', 'where', 'which', 'who', 'will', 'with', 'you', 'your',
    );

    /**
     * The words worth putting in a search box: the sentence with the common
     * words and the words that only said "this is broken" taken out. Returns
     * '' when little enough is left that a search would be noise -- better to
     * land on the whole list than on an empty result.
     */
    public static function search_terms( $sentence ) {
        $text  = trim( self::normalize( $sentence ) );
        if ( '' === $text ) {
            return '';
        }

        $fix    = self::keywords();
        $ignore = array_merge( self::STOPWORDS, $fix['fix']['plain'] );
        foreach ( $fix['fix']['strong'] as $phrase ) {
            foreach ( explode( ' ', $phrase ) as $piece ) {
                $ignore[] = $piece;
            }
        }

        $kept = array();
        foreach ( explode( ' ', $text ) as $word ) {
            $word = trim( $word, "'-" );
            if ( strlen( $word ) < 3 || in_array( $word, $ignore, true ) ) {
                continue;
            }
            if ( ! in_array( $word, $kept, true ) ) {
                $kept[] = $word;
            }
        }

        // One or two distinctive words find things; five find nothing.
        if ( empty( $kept ) || count( $kept ) > 3 ) {
            return '';
        }
        return implode( ' ', $kept );
    }

    /**
     * Where a match sends the volunteer, with their words carried along.
     *
     * The question screen already reads `ptk_prefill_title` (the search
     * analytics screen has linked to it that way since 4.0), and "What
     * you've written" already reads `s`, so nothing new has to be taught
     * to either screen.
     *
     * @param string $key      Intention key.
     * @param string $sentence What the volunteer typed.
     * @param array  $urls     key => plain destination url.
     * @return string Url, or '' when there is nowhere to send them.
     */
    public static function destination( $key, $sentence, array $urls ) {
        $url = isset( $urls[ $key ] ) ? (string) $urls[ $key ] : '';
        if ( '' === $url ) {
            return '';
        }

        $sentence = trim( (string) $sentence );
        if ( '' === $sentence ) {
            return $url;
        }

        if ( 'answer' === $key ) {
            return self::add_arg( $url, 'ptk_prefill_title', $sentence );
        }

        if ( 'word' === $key ) {
            // That screen's first field holds a word or a short phrase --
            // "ASE", "room parent". A whole sentence dropped into it looks
            // like a mistake, so only a short answer is carried over; a
            // longer one is left for the volunteer to shorten themselves.
            $words = preg_split( '/\s+/', $sentence );
            return ( count( $words ) > 4 ) ? $url : self::add_arg( $url, 'ptk_prefill_title', $sentence );
        }

        if ( 'fix' === $key ) {
            $terms = self::search_terms( $sentence );
            return ( '' === $terms ) ? $url : self::add_arg( $url, 's', $terms );
        }

        // The newsletter and the vendor directory have nowhere sensible to
        // put a loose sentence yet, so they open as they always do.
        return $url;
    }

    /** add_query_arg() without WordPress, so this class stays testable on its own. */
    private static function add_arg( $url, $key, $value ) {
        $glue = ( false === strpos( $url, '?' ) ) ? '?' : '&';
        return $url . $glue . rawurlencode( $key ) . '=' . rawurlencode( $value );
    }

    /** The line above the result, in the assistant's voice. */
    public static function heading( $state ) {
        if ( 'confident' === $state ) {
            return 'That sounds like:';
        }
        if ( 'choices' === $state ) {
            return 'Which of these sounds right?';
        }
        return "I couldn't tell from that. Here's everything you can do:";
    }
}
