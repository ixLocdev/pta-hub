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

class PTK_Share_Data {

    const CHANNELS = array( 'facebook', 'instagram', 'whatsapp' );

    const META_CAPTION       = '_ptk_share_caption_';
    const META_CAPTION_HASH  = '_ptk_share_caption_hash_';
    const META_SQUARE_ID     = '_ptk_share_square_id';
    const META_SQUARE_CUSTOM = '_ptk_share_square_custom';
    const META_SQUARE_HASH   = '_ptk_share_square_hash';

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
     * school name and color -- never the newsletter's story content, so
     * editing a story never marks a perfectly fine square as stale.
     *
     * $version is passed in rather than read from a PTK_VERSION constant
     * so this stays reachable from a plain-php test harness that defines
     * no such constant.
     */
    public static function square_inputs_hash( $issue, $date, $school_name, $share_color, $version ) {
        return md5( $issue . '|' . $date . '|' . $school_name . '|' . $share_color . '|' . $version );
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
}
