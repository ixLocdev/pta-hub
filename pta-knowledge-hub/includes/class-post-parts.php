<?php
/**
 * The parts a volunteer writes when creating a single news post: a kicker,
 * a headline, the words, a picture, steps, a date callout, and a button.
 * No rendering, no output -- this class holds the data shape and cleans it.
 *
 * Pure: no WordPress calls, no database, no output. Plain PHP only, so the
 * shape is testable (tests/test-post-parts.php) and the post renderer
 * (a later task) never has to ask "is this data clean?"
 *
 * Plain English throughout -- "headline", "picture", "steps", not "title",
 * "attachment", "procedure", not "post", not "meta", not "CPT".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Post_Parts {

    /**
     * The default, empty post parts. Every key is present, nothing is null.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'kicker'     => '',
            'headline'   => '',
            'words'      => '',
            'image_id'   => 0,
            'date_label' => '',
            'date_note'  => '',
            'steps'      => array(),
            'link_url'   => '',
            'link_text'  => '',
        );
    }

    /**
     * Clean user input to remove markup, clamp numeric values, and refuse
     * unsafe URLs. Every key that defaults() provides is present in the result.
     * Missing keys are filled in with defaults. Extra keys are discarded.
     *
     * @param array $parts User input, typically from a form.
     * @return array
     */
    public static function sanitize( $parts ) {
        $parts = (array) $parts;
        $result = self::defaults();

        foreach ( array( 'kicker', 'headline', 'date_label', 'date_note', 'link_text' ) as $key ) {
            if ( isset( $parts[ $key ] ) ) {
                $result[ $key ] = self::clean_text( $parts[ $key ] );
            }
        }

        if ( isset( $parts['words'] ) ) {
            $result['words'] = self::clean_words( $parts['words'] );
        }

        if ( isset( $parts['image_id'] ) ) {
            $result['image_id'] = max( 0, (int) $parts['image_id'] );
        }

        if ( isset( $parts['link_url'] ) ) {
            $result['link_url'] = self::clean_url( $parts['link_url'] );
        }

        if ( isset( $parts['steps'] ) && is_array( $parts['steps'] ) ) {
            $result['steps'] = self::clean_steps( $parts['steps'] );
        }

        return $result;
    }

    /**
     * Sanitize a plain text field: strip markup, trim, collapse whitespace runs.
     *
     * @param string $text
     * @return string
     */
    private static function clean_text( $text ) {
        $text = (string) $text;
        $text = strip_tags( $text );
        $text = trim( $text );
        // Collapse runs of whitespace into a single space.
        $text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
        return $text;
    }

    /**
     * Sanitize the words field: keep paragraph breaks (blank lines), strip markup, trim.
     *
     * @param string $words
     * @return string
     */
    private static function clean_words( $words ) {
        $words = (string) $words;
        $words = strip_tags( $words );
        // Trim leading/trailing whitespace on the whole block.
        $words = trim( $words );
        // Collapse runs of whitespace within a line, but preserve paragraph breaks.
        // Split by double newline (paragraph break).
        $paragraphs = preg_split( '/\n\s*\n/', $words );
        $paragraphs = array_map(
            function( $p ) {
                // Within each paragraph, collapse whitespace runs.
                $p = trim( $p );
                $p = preg_replace( '/[\r\t ]+/', ' ', $p );
                // Preserve single newlines within a paragraph (just remove extra ones).
                $p = preg_replace( '/\n+/', ' ', $p );
                return trim( $p );
            },
            $paragraphs
        );
        // Filter out empty paragraphs.
        $paragraphs = array_filter( $paragraphs );
        // Join paragraphs back with double newline.
        return implode( "\n\n", $paragraphs );
    }

    /**
     * Sanitize a URL: allow only http://, https://, and mailto:; anything else becomes ''.
     *
     * @param string $url
     * @return string
     */
    private static function clean_url( $url ) {
        $url = (string) $url;
        $url = trim( $url );

        if ( '' === $url ) {
            return '';
        }

        // Extract the scheme.
        if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $m ) ) {
            $scheme = strtolower( $m[1] );
            // Allow only http, https, and mailto.
            $allowed = array( 'http', 'https', 'mailto' );
            if ( ! in_array( $scheme, $allowed, true ) ) {
                return '';
            }
        }

        return $url;
    }

    /**
     * Sanitize the steps array: each step is array('heading' => string, 'body' => string).
     * Empty steps are discarded.
     *
     * @param array $steps
     * @return array
     */
    private static function clean_steps( $steps ) {
        $result = array();

        foreach ( $steps as $step ) {
            $step = (array) $step;

            $heading = isset( $step['heading'] ) ? self::clean_text( $step['heading'] ) : '';
            $body = isset( $step['body'] ) ? self::clean_text( $step['body'] ) : '';

            // Skip empty steps.
            if ( '' === $heading && '' === $body ) {
                continue;
            }

            $result[] = array(
                'heading' => $heading,
                'body'    => $body,
            );
        }

        return $result;
    }

    /**
     * Extract a summary from the post parts: the first non-empty paragraph
     * of words, whitespace collapsed, cut at a sentence boundary near 180 characters.
     *
     * @param array $parts Sanitized parts (typically the result of sanitize()).
     * @return string
     */
    public static function summary( $parts ) {
        $parts = (array) $parts;
        $words = isset( $parts['words'] ) ? (string) $parts['words'] : '';

        if ( '' === $words ) {
            return '';
        }

        // Split by paragraph breaks.
        $paragraphs = preg_split( '/\n\n/', $words );

        // Find the first non-empty paragraph.
        $first = '';
        foreach ( $paragraphs as $p ) {
            $p = trim( $p );
            if ( '' !== $p ) {
                $first = $p;
                break;
            }
        }

        if ( '' === $first ) {
            return '';
        }

        // Collapse whitespace.
        $first = preg_replace( '/\s+/', ' ', $first );
        $first = trim( $first );

        // Cut at a sentence boundary near 180 characters.
        if ( strlen( $first ) <= 180 ) {
            return $first;
        }

        // Find the last sentence boundary (. ! ?) before or near 180 characters.
        $excerpt = substr( $first, 0, 180 );
        // Look for a sentence boundary (period, exclamation, or question mark).
        // Start from the end and work backwards.
        $last_sentence = -1;
        for ( $i = strlen( $excerpt ) - 1; $i >= 0; $i-- ) {
            if ( in_array( $excerpt[ $i ], array( '.', '!', '?' ), true ) ) {
                $last_sentence = $i;
                break;
            }
        }

        if ( -1 === $last_sentence ) {
            // No sentence boundary found, just cut at 180.
            return trim( substr( $first, 0, 180 ) );
        }

        // Return up to and including the sentence boundary.
        return trim( substr( $first, 0, $last_sentence + 1 ) );
    }

    /**
     * Is there anything here at all? True when any of headline, words, steps,
     * link or picture is set (non-empty).
     *
     * @param array $parts Sanitized parts (typically the result of sanitize()).
     * @return bool
     */
    public static function has_content( $parts ) {
        $parts = (array) $parts;

        $headline = isset( $parts['headline'] ) ? (string) $parts['headline'] : '';
        if ( '' !== $headline ) {
            return true;
        }

        $words = isset( $parts['words'] ) ? (string) $parts['words'] : '';
        if ( '' !== $words ) {
            return true;
        }

        $image_id = isset( $parts['image_id'] ) ? (int) $parts['image_id'] : 0;
        if ( 0 !== $image_id ) {
            return true;
        }

        $steps = isset( $parts['steps'] ) ? (array) $parts['steps'] : array();
        if ( ! empty( $steps ) ) {
            return true;
        }

        $link_url = isset( $parts['link_url'] ) ? (string) $parts['link_url'] : '';
        $link_text = isset( $parts['link_text'] ) ? (string) $parts['link_text'] : '';
        if ( '' !== $link_url || '' !== $link_text ) {
            return true;
        }

        return false;
    }
}
