<?php
/**
 * Storage for the share panel: caption meta, the dirty rule, and the two
 * input hashes that decide when a stored caption or square has gone stale.
 *
 * The hashing and staleness logic is WordPress-free beyond plain PHP, so it
 * can be unit-tested with plain php -- same contract as PTK_Share_Text and
 * PTK_Newsletter_Data. All get_post_meta()/update_post_meta()/
 * delete_post_meta() access lives in separate thin methods below so the
 * pure functions stay testable without a WordPress runtime.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/class-focal-point.php';

class PTK_Share_Data {

    const CHANNELS = array( 'facebook', 'instagram', 'whatsapp' );

    const META_CAPTION       = '_ptk_share_caption_';
    const META_CAPTION_HASH  = '_ptk_share_caption_hash_';
    const META_SQUARE_ID     = '_ptk_share_square_id';
    const META_SQUARE_CUSTOM = '_ptk_share_square_custom';
    const META_SQUARE_HASH   = '_ptk_share_square_hash';

    const META_SQUARE_PHOTO_ID      = '_ptk_share_square_photo_id';
    const META_SQUARE_PHOTO_FOCAL_X = '_ptk_share_square_photo_focal_x';
    const META_SQUARE_PHOTO_FOCAL_Y = '_ptk_share_square_photo_focal_y';
    const META_SQUARE_PHOTO_ZOOM    = '_ptk_share_square_photo_zoom';

    /**
     * Hash of everything a caption is made from: the newsletter's own
     * words plus the bits of $opts that get folded into the text (url,
     * issue, date, school name). Changing a story card changes $blocks
     * and so changes this hash.
     */
    public static function caption_inputs_hash( array $blocks, $url, $issue, $date, $school_name ) {
        return md5( json_encode( $blocks ) . '|' . $url . '|' . $issue . '|' . $date . '|' . $school_name );
    }

    /**
     * Hash of everything the share SQUARE is made from: issue, date,
     * school name, and BOTH drawn colors (background, text; 4.3.0) --
     * never the newsletter's story content, so editing a story never
     * marks a perfectly fine square as stale. Two schools whose picks
     * both get corrected to the same readable pair should not each think
     * the other's square is stale, which is why the caller passes the
     * DRAWN colors (post contrast-guard), not the raw option values.
     *
     * Round 3 adds the four "photo behind the words" inputs (photo_id,
     * photo_focal_x, photo_focal_y, photo_zoom) so changing the
     * background photo, or reframing/rezooming it, invalidates a stale
     * PNG the same way a color change already does. $photo_id is hashed
     * even when 0 (no photo): every input is hashed as given, accepting
     * that a no-op focal/zoom edit on a photo-less square could
     * theoretically mark it stale even though nothing visible changed --
     * the simpler, correct-by-default choice, matching is_stale()'s own
     * "no baseline -> stale by definition" bias.
     *
     * $version is passed in rather than read from a PTK_VERSION constant
     * so this stays reachable from a plain-php test harness that defines
     * no such constant.
     */
    public static function square_inputs_hash( $issue, $date, $school_name, $background, $text, $photo_id, $photo_focal_x, $photo_focal_y, $photo_zoom, $version ) {
        return md5( $issue . '|' . $date . '|' . $school_name . '|' . $background . '|' . $text . '|' . $photo_id . '|' . $photo_focal_x . '|' . $photo_focal_y . '|' . $photo_zoom . '|' . $version );
    }

    /**
     * True when a stored hash no longer matches the current one -- also
     * true when nothing was stored yet, since "no baseline" is stale by
     * definition, not fresh.
     */
    public static function is_stale( $stored_hash, $current_hash ) {
        if ( empty( $stored_hash ) ) {
            return true;
        }
        return $stored_hash !== $current_hash;
    }

    /**
     * The dirty rule for one channel's caption:
     *   - stored meta present -> return it, flagged stale if its stored
     *     hash no longer matches what the newsletter would generate today
     *   - no stored meta -> generate fresh via PTK_Share_Text::generate()
     *     and write nothing (generation is stateless; only the square
     *     writes, and that's a later batch)
     *
     * @param int    $post_id
     * @param string $channel 'facebook' | 'instagram' | 'whatsapp'
     * @param array  $blocks  newsletter blocks, see PTK_Newsletter_Data
     * @param array  $opts    url, issue, date, school_name, today
     * @return array{text:string,stale:bool,stored:bool}
     */
    public static function resolve_caption( $post_id, $channel, array $blocks, array $opts ) {
        $stored = self::get_stored_caption( $post_id, $channel );

        $current_hash = self::caption_inputs_hash(
            $blocks,
            isset( $opts['url'] ) ? $opts['url'] : '',
            isset( $opts['issue'] ) ? $opts['issue'] : '',
            isset( $opts['date'] ) ? $opts['date'] : '',
            isset( $opts['school_name'] ) ? $opts['school_name'] : ''
        );

        if ( null !== $stored && '' !== $stored ) {
            $stored_hash = self::get_stored_caption_hash( $post_id, $channel );
            return array(
                'text'   => $stored,
                'stale'  => self::is_stale( $stored_hash, $current_hash ),
                'stored' => true,
            );
        }

        $generated = PTK_Share_Text::generate( $blocks, $opts );
        $text      = isset( $generated[ $channel ] ) ? $generated[ $channel ] : '';

        return array(
            'text'   => $text,
            'stale'  => false,
            'stored' => false,
        );
    }

    // -------------------------------------------------------------
    // Thin WordPress meta access. Deliberately NOT called by tests --
    // this is the only WordPress-coupled part of the class.
    // -------------------------------------------------------------

    protected static function get_stored_caption( $post_id, $channel ) {
        $value = get_post_meta( $post_id, self::META_CAPTION . $channel, true );
        return ( '' === $value ) ? null : $value;
    }

    protected static function get_stored_caption_hash( $post_id, $channel ) {
        return get_post_meta( $post_id, self::META_CAPTION_HASH . $channel, true );
    }

    public static function save_caption( $post_id, $channel, $text, $hash ) {
        update_post_meta( $post_id, self::META_CAPTION . $channel, $text );
        update_post_meta( $post_id, self::META_CAPTION_HASH . $channel, $hash );
    }

    public static function delete_caption( $post_id, $channel ) {
        delete_post_meta( $post_id, self::META_CAPTION . $channel );
        delete_post_meta( $post_id, self::META_CAPTION_HASH . $channel );
    }

    public static function get_square( $post_id ) {
        return array(
            'image_id' => absint( get_post_meta( $post_id, self::META_SQUARE_ID, true ) ),
            'custom'   => (bool) get_post_meta( $post_id, self::META_SQUARE_CUSTOM, true ),
            'hash'     => get_post_meta( $post_id, self::META_SQUARE_HASH, true ),
        );
    }

    public static function save_square( $post_id, $image_id, $custom, $hash ) {
        update_post_meta( $post_id, self::META_SQUARE_ID, absint( $image_id ) );
        update_post_meta( $post_id, self::META_SQUARE_CUSTOM, $custom ? 1 : 0 );
        update_post_meta( $post_id, self::META_SQUARE_HASH, $hash );
    }

    /**
     * The "photo behind the words" background photo -- a THIRD picture
     * concept alongside the drawn/custom square (see class docblock and
     * the round-3 spec, fact 8): a source photo render_png() crops and
     * composes UNDER the drawn text, still subject to the same staleness
     * hash as everything else. A photo_id of 0 (default) means "no photo,
     * flat square, exactly as before."
     */
    public static function get_square_photo( $post_id ) {
        return array(
            'photo_id' => absint( get_post_meta( $post_id, self::META_SQUARE_PHOTO_ID, true ) ),
            'focal_x'  => PTK_Focal_Point::clamp_percent( get_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_X, true ) ),
            'focal_y'  => PTK_Focal_Point::clamp_percent( get_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_Y, true ) ),
            'zoom'     => PTK_Focal_Point::sanitize_zoom( get_post_meta( $post_id, self::META_SQUARE_PHOTO_ZOOM, true ) ),
        );
    }

    public static function save_square_photo( $post_id, $photo_id, $focal_x, $focal_y, $zoom ) {
        update_post_meta( $post_id, self::META_SQUARE_PHOTO_ID, absint( $photo_id ) );
        update_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_X, PTK_Focal_Point::clamp_percent( $focal_x ) );
        update_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_Y, PTK_Focal_Point::clamp_percent( $focal_y ) );
        update_post_meta( $post_id, self::META_SQUARE_PHOTO_ZOOM, PTK_Focal_Point::sanitize_zoom( $zoom ) );
    }

    /** Back to the flat square -- no background photo. */
    public static function clear_square_photo( $post_id ) {
        delete_post_meta( $post_id, self::META_SQUARE_PHOTO_ID );
        delete_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_X );
        delete_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_Y );
        delete_post_meta( $post_id, self::META_SQUARE_PHOTO_ZOOM );
    }

    /**
     * True when the square carries its own background photo -- used to
     * extend the main photo-privacy gate (blocks_have_images()) to cover
     * a newsletter whose ONLY photo lives here rather than in any block.
     */
    public static function square_has_custom_photo( $post_id ) {
        return 0 !== self::get_square_photo( $post_id )['photo_id'];
    }
}
