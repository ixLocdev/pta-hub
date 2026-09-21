<?php
/**
 * Every sentence the picture picker ("Which picture?") shows, plus the
 * pure share-square filter it uses to keep newsletter share squares out
 * of "Pictures you've used before".
 *
 * Pure: no WordPress calls, no output, nothing but string logic -- so it
 * can be unit-tested with plain PHP (tests/test-picture-picker.php).
 *
 * Plain English throughout. No WordPress or database words: no "media",
 * "attachment", "library", "alt text", "upload" as a noun, "file type".
 * A test asserts the worst of them never appear.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Picture_Copy {

    /**
     * Is this file a generated newsletter share square? Filenames from
     * PTK_Share_Image::attachment_filename() always start with
     * "share-square-" -- exactly that prefix, dash included, so an
     * ordinary picture that merely starts with the same word (e.g.
     * "share-squares-are-great.png") is never caught by mistake.
     *
     * @param string $filename Basename, e.g. "share-square-12-041-a1b2c3d4.png".
     * @return bool
     */
    public static function is_share_square_filename( $filename ) {
        return 0 === strpos( (string) $filename, 'share-square-' );
    }

    /**
     * Belt-and-braces: a share square's post_title is always
     * "Share square for issue %s" (see PTK_Share_Image::ensure_square())
     * and it is always a PNG. Checked only when a mime type is given, so
     * a caller that doesn't have it yet can pass ''.
     *
     * @param string $title
     * @param string $mime  Attachment mime type, or '' when unknown.
     * @return bool
     */
    public static function is_share_square_title( $title, $mime = '' ) {
        if ( '' !== (string) $mime && 'image/png' !== $mime ) {
            return false;
        }
        return 0 === strpos( (string) $title, 'Share square for issue' );
    }

    /**
     * The rule the picker's list query applies to every image: true means
     * "leave it out of Pictures you've used before".
     *
     * @param string $filename Basename of the attached file.
     * @param string $title    The attachment's title.
     * @param string $mime     The attachment's mime type, or ''.
     * @return bool
     */
    public static function is_share_square( $filename, $title = '', $mime = '' ) {
        return self::is_share_square_filename( $filename )
            || self::is_share_square_title( $title, $mime );
    }

    /** Every user-visible string the picker shows, for enqueueing and for the "no banned words" test. */
    public static function strings() {
        return array(
            'modal_title'         => 'Which picture?',
            'close'                => 'Close',
            'device_heading'       => 'Use a picture from this device',
            'device_help'          => 'Drag a picture here, or choose one. On a phone this opens your camera roll.',
            'pick_button'          => 'Choose a picture',
            'drop_active'          => 'Let go to add it',
            'previous_heading'     => "Pictures you've used before",
            'search_label'         => 'Find a picture',
            'search_placeholder'   => 'Find a picture',
            'load_more'            => 'Load more pictures',
            'empty_none'           => 'No pictures yet.',
            'empty_search'         => 'Nothing matched your search.',
            'back_link'            => "\u{2190} Choose a different picture",
            'alt_question'         => "What's in this picture?",
            'alt_help'             => "This is read aloud to families who can't see the picture.",
            'use_button'           => 'Use this picture',
            'adding'                => "Adding your picture\u{2026}",
            'add_failed'           => "That didn't work. Try a different picture.",
            'not_a_picture'        => "That's not a picture. Try a different one.",
            'not_found'            => "That picture couldn't be found.",
            'no_access'            => "You don't have access to pictures.",
            'no_add_access'        => "You don't have access to add pictures.",
            'picture_name'         => 'Picture',
        );
    }
}
