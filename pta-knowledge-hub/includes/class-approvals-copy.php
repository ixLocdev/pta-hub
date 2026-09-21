<?php
/**
 * The words on "Recommendations waiting for a look" -- the Council's
 * approval queue, in the new Hub look.
 *
 * Pure: no database, no capabilities, no WordPress functions, so the
 * wording is testable on its own and the screen file stays about layout.
 *
 * The voice is the rest of the Hub's: plain sentences, the member named
 * where we know them, and no word a volunteer would have to look up.
 * "Approve" and "Reject" are moderation words -- what the Council is
 * really doing is adding someone to the directory, or turning them down.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Approvals_Copy {

    /** The one sentence next to the screen's single stamp. */
    public static function waiting_sentence( $vendors, $reviews ) {
        $vendors = max( 0, (int) $vendors );
        $reviews = max( 0, (int) $reviews );

        $parts = array();
        if ( $vendors > 0 ) {
            $parts[] = ( 1 === $vendors ) ? '1 recommendation' : $vendors . ' recommendations';
        }
        if ( $reviews > 0 ) {
            $parts[] = ( 1 === $reviews ) ? '1 review' : $reviews . ' reviews';
        }

        if ( empty( $parts ) ) {
            return '';
        }

        $list = implode( ' and ', $parts );
        $verb = ( 1 === ( $vendors + $reviews ) ) ? 'is' : 'are';

        return $list . ' ' . $verb . ' waiting for a look.';
    }

    /** "Would use them again" / "Would not use them again". */
    public static function verdict_line( $recommend ) {
        return $recommend ? 'Would use them again' : 'Would not use them again';
    }

    /** "Price 3 out of 5 · Quality 5 out of 5" -- numbers, not star glyphs a screen reader spells out. */
    public static function ratings_line( $price, $quality ) {
        $price   = self::clamp_rating( $price );
        $quality = self::clamp_rating( $quality );

        $parts = array();
        if ( $price > 0 ) {
            $parts[] = 'Price ' . $price . ' out of 5';
        }
        if ( $quality > 0 ) {
            $parts[] = 'Quality ' . $quality . ' out of 5';
        }
        return implode( ' · ', $parts );
    }

    private static function clamp_rating( $value ) {
        $value = (int) $value;
        if ( $value < 0 ) {
            return 0;
        }
        return ( $value > 5 ) ? 5 : $value;
    }

    /** "suggested by Ana Ruiz, Northeast PTA" -- whichever halves we know. */
    public static function credit_line( $lead, $name, $pta ) {
        $name = trim( (string) $name );
        $pta  = trim( (string) $pta );

        if ( '' === $name && '' === $pta ) {
            return '';
        }
        $who = ( '' !== $name && '' !== $pta ) ? $name . ', ' . $pta : $name . $pta;
        return trim( (string) $lead ) . ' ' . $who;
    }

    /** The question at the top of the confirm screen. */
    public static function confirm_question( $type, $vendor_name, $who ) {
        $vendor_name = trim( (string) $vendor_name );
        $who         = trim( (string) $who );

        if ( 'review' === $type ) {
            if ( '' === $who ) {
                return 'Turn down this review of ' . $vendor_name . '?';
            }
            return 'Turn down ' . self::possessive( $who ) . ' review of ' . $vendor_name . '?';
        }

        return 'Turn down ' . $vendor_name . '?';
    }

    /**
     * "Ana Ruiz" -> "Ana Ruiz's". A name already ending in s still takes
     * 's ("Chris's", "Jones's") -- that is ordinary American usage, and
     * guessing at bare apostrophes reads like a typo to the person whose
     * name it is.
     */
    public static function possessive( $name ) {
        $name = trim( (string) $name );
        return ( '' === $name ) ? '' : $name . "'s";
    }

    /** What is really about to happen -- said before it happens, because none of it can be undone. */
    public static function confirm_warning( $type ) {
        if ( 'review' === $type ) {
            return "What they wrote will be deleted. There's no way to get it back.";
        }
        return "They won't be added to the directory, and what the member wrote about them will be deleted. There's no way to get it back.";
    }

    /** The line after something was added or turned down. */
    public static function outcome_line( $verdict ) {
        if ( 'rejected' === $verdict ) {
            return 'Turned down and deleted.';
        }
        return "Added. Families at every school can see it now (usually within the hour).";
    }

    /** Title and sentence for the all-caught-up screen. */
    public static function empty_state() {
        return array(
            'title' => 'Nothing is waiting.',
            'text'  => 'When a member recommends someone they have used, or writes about a company already in the directory, it waits here until you have a look.',
        );
    }

    /** The buttons, so the screen and the tests agree on them. */
    public static function button_label( $type, $verdict ) {
        if ( 'reject' === $verdict ) {
            return ( 'review' === $type ) ? 'Turn it down' : 'Turn this down';
        }
        return ( 'review' === $type ) ? 'Show this review' : 'Add them to the directory';
    }
}
