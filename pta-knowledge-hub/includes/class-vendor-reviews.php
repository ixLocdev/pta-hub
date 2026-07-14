<?php
/**
 * Vendor reviews backend: shared table access, review submit endpoint,
 * aggregation queries, PTA rollup math.
 *
 * Reviews live in ONE network-wide table ({base_prefix}ptk_vendor_reviews,
 * created by PTK_Network_Provisioning) so every PTA's ratings aggregate
 * against a single vendor. One review per user per vendor (UNIQUE key);
 * re-submitting updates the row, resets it to pending, and overwrites
 * blog_id (latest submission wins attribution).
 *
 * AJAX POST fields for ptk_submit_vendor_review (Task 5's form must match):
 * vendor_id, ptk_recommend ('1'|'0'), ptk_price (1-5), ptk_quality (1-5),
 * ptk_comment, vendor_website_hp (honeypot, must stay empty), _wpnonce
 * (nonce action 'ptk_vendor_nonce').
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Vendor_Reviews {

    const RATE_LIMIT  = 5;               // submissions per user per hour
    const RATE_WINDOW = HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'wp_ajax_ptk_submit_vendor_review', array( __CLASS__, 'handle_submit' ) );
        // NO nopriv registration — members-only by construction.
    }

    /** Shared network-wide table name (base_prefix, not per-site). */
    public static function table() {
        global $wpdb;
        return $wpdb->base_prefix . 'ptk_vendor_reviews';
    }

    /* -------------------------------------------------------------- */
    /*  AJAX submit                                                   */
    /* -------------------------------------------------------------- */

    public static function handle_submit() {
        // 1. Nonce.
        check_ajax_referer( 'ptk_vendor_nonce', '_wpnonce' );

        // 2. Members only — hard requirement, independent of ptk_require_login.
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Please log in to write a review.' ), 403 );
        }

        // 3. Honeypot — pretend success so the bot moves on (suggestions pattern).
        if ( ! empty( $_POST['vendor_website_hp'] ) ) {
            wp_send_json_success( array( 'message' => 'Thanks!' ) );
        }

        $user_id = get_current_user_id();

        // 4. Inputs.
        $vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;

        $recommend_raw = isset( $_POST['ptk_recommend'] ) ? (string) wp_unslash( $_POST['ptk_recommend'] ) : '';
        if ( '1' !== $recommend_raw && '0' !== $recommend_raw ) {
            wp_send_json_error( array( 'message' => 'Please choose whether you\'d use them again.' ) );
        }
        $recommend = (int) $recommend_raw;

        $price = isset( $_POST['ptk_price'] ) ? absint( $_POST['ptk_price'] ) : 0;
        if ( $price < 1 || $price > 5 ) {
            wp_send_json_error( array( 'message' => 'Please rate Price from 1 to 5 stars.' ) );
        }

        $quality = isset( $_POST['ptk_quality'] ) ? absint( $_POST['ptk_quality'] ) : 0;
        if ( $quality < 1 || $quality > 5 ) {
            wp_send_json_error( array( 'message' => 'Please rate Quality from 1 to 5 stars.' ) );
        }

        $comment = isset( $_POST['ptk_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ptk_comment'] ) ) : '';
        if ( '' === trim( $comment ) ) {
            wp_send_json_error( array( 'message' => 'Please share a few words about your experience.' ) );
        }
        if ( mb_strlen( $comment ) > 2000 ) {
            wp_send_json_error( array( 'message' => 'Your comment is a little long — please keep it under 2,000 characters.' ) );
        }

        // 5. Object check (audit #25 lesson): standalone reviews only attach
        //    to a vendor that is live on the Council site. The suggest-a-vendor
        //    endpoint's bundled first review (Task 4) is the sole exception —
        //    it calls save_review() directly, never this handler.
        if ( ! self::vendor_exists_published( $vendor_id ) ) {
            wp_send_json_error( array( 'message' => 'That vendor no longer exists.' ) );
        }

        // 6. Per-user rate limit — checked and incremented only once the
        //    submission is otherwise valid, so typos don't burn quota
        //    (deliberate deviation from the Suggestions ordering: that
        //    pattern is per-IP/anonymous where early increment matters;
        //    here submitters are authenticated and invalid requests persist
        //    nothing). Site transients make the budget NETWORK-wide — one
        //    5/hour allowance across all ~11 sites, not 5 per site; they
        //    degrade gracefully to regular options on single-site.
        $transient_id = 'ptk_vendor_rate_' . $user_id;
        $count        = (int) get_site_transient( $transient_id );
        if ( $count >= self::RATE_LIMIT ) {
            wp_send_json_error( array( 'message' => 'You\'ve sent a few reviews recently — please try again in an hour.' ) );
        }
        set_site_transient( $transient_id, $count + 1, self::RATE_WINDOW );

        // 7. Upsert (re-submission re-pends and re-attributes to this site).
        $review_id = self::save_review( $vendor_id, $user_id, get_current_blog_id(), $recommend, $price, $quality, $comment, 'pending' );
        if ( ! $review_id ) {
            wp_send_json_error( array( 'message' => 'Sorry — your review could not be saved just now. Please try again.' ), 500 );
        }

        // 8. A re-pended review must leave public view immediately, not when
        //    the transient expires (spec: Caching).
        PTK_Network_Provisioning::bump_cache_version();

        // 9. Moderation notification email hooks this (Task 6).
        do_action( 'ptk_vendor_review_submitted', $vendor_id, $user_id );

        // 10.
        wp_send_json_success( array( 'message' => 'Thanks! Your review was sent to the PTA Council for approval.' ) );
    }

    /**
     * True when the vendor exists on the MAIN site as a published
     * ptk_vendor. Single-site-safe via the Task 1 helper.
     */
    public static function vendor_exists_published( $vendor_id ) {
        if ( $vendor_id < 1 ) {
            return false;
        }
        return (bool) PTK_Network_Provisioning::on_main_site( function() use ( $vendor_id ) {
            $post = get_post( $vendor_id );
            return $post && 'ptk_vendor' === $post->post_type && 'publish' === $post->post_status;
        } );
    }

    /* -------------------------------------------------------------- */
    /*  Upsert                                                        */
    /* -------------------------------------------------------------- */

    /**
     * Insert or update the user's single review row for a vendor.
     * Re-submission overwrites blog_id (latest wins) and resets to pending.
     *
     * Concurrency: select-then-branch means two rapid submits can both see
     * no existing row. The UNIQUE KEY (vendor_id, user_id) rejects the
     * losing insert; we detect that duplicate-entry failure, re-select, and
     * retry once through the update path so the losing submit lands as an
     * update instead of being silently dropped.
     *
     * @return int Review row id, or 0 if the write failed (callers must
     *             check — do not report success on 0).
     */
    public static function save_review( $vendor_id, $user_id, $blog_id, $recommend, $price, $quality, $comment, $status ) {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql', true ); // store GMT

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE vendor_id = %d AND user_id = %d",
            $vendor_id, $user_id
        ) );

        $data = array(
            'vendor_id'      => $vendor_id,
            'blog_id'        => $blog_id,
            'user_id'        => $user_id,
            'recommend'      => $recommend ? 1 : 0,
            'price_rating'   => $price,
            'quality_rating' => $quality,
            'comment'        => $comment,
            'status'         => $status,
            'updated_at'     => $now,
        );
        $formats = array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );

        if ( $existing_id ) {
            return self::update_review_row( (int) $existing_id, $data, $formats );
        }

        $insert_data        = $data;
        $insert_formats     = $formats;
        $insert_data['created_at'] = $now;
        $insert_formats[]          = '%s';

        if ( false !== $wpdb->insert( $table, $insert_data, $insert_formats ) ) {
            return (int) $wpdb->insert_id;
        }

        // Lost an insert race on the UNIQUE key — the other request's row
        // now exists. Re-select and retry once via the update path.
        if ( false !== strpos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE vendor_id = %d AND user_id = %d",
                $vendor_id, $user_id
            ) );
            if ( $existing_id ) {
                return self::update_review_row( (int) $existing_id, $data, $formats );
            }
        }

        return 0;
    }

    /**
     * Update one review row; 0 when the write failed. Note $wpdb->update()
     * returns 0 (not false) when the data is identical — that is a success.
     */
    private static function update_review_row( $row_id, $data, $formats ) {
        global $wpdb;
        $updated = $wpdb->update( self::table(), $data, array( 'id' => $row_id ), $formats, array( '%d' ) );
        return ( false === $updated ) ? 0 : $row_id;
    }

    /* -------------------------------------------------------------- */
    /*  Reads + aggregation                                           */
    /* -------------------------------------------------------------- */

    /**
     * All approved reviews for a vendor, newest first.
     *
     * @return array Array of row objects.
     */
    public static function get_approved_reviews( $vendor_id ) {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE vendor_id = %d AND status = 'approved' ORDER BY created_at DESC, id DESC",
            $vendor_id
        ) );
    }

    /**
     * The viewer's own review row regardless of status — a single uncached
     * lookup on the UNIQUE key, layered on at render time (spec: Caching).
     *
     * @return object|null
     */
    public static function get_user_review( $vendor_id, $user_id ) {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE vendor_id = %d AND user_id = %d",
            $vendor_id, $user_id
        ) );
    }

    /**
     * Aggregated stats for one vendor (approved reviews only).
     *
     * @return array { review_count, avg_price, avg_quality,
     *                 reviewer_recommend_pct, ptas_total, ptas_recommend }
     */
    public static function get_vendor_stats( $vendor_id ) {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT blog_id,
                    COUNT(*) AS review_count,
                    SUM(recommend) AS recommend_count,
                    SUM(price_rating) AS price_sum,
                    SUM(quality_rating) AS quality_sum
             FROM {$table}
             WHERE vendor_id = %d AND status = 'approved'
             GROUP BY blog_id",
            $vendor_id
        ) );

        return self::rollup_blog_rows( $rows ? $rows : array() );
    }

    /**
     * Same stats shape for ALL vendors in one query pass (directory cards).
     * Static query — no user input, nothing to prepare.
     *
     * @return array vendor_id => stats array (vendors with zero approved
     *               reviews are absent; callers default to zeros).
     */
    public static function get_all_stats() {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT vendor_id, blog_id,
                    COUNT(*) AS review_count,
                    SUM(recommend) AS recommend_count,
                    SUM(price_rating) AS price_sum,
                    SUM(quality_rating) AS quality_sum
             FROM {$table}
             WHERE status = 'approved'
             GROUP BY vendor_id, blog_id"
        );

        $by_vendor = array();
        foreach ( (array) $rows as $row ) {
            $by_vendor[ (int) $row->vendor_id ][] = $row;
        }

        $stats = array();
        foreach ( $by_vendor as $vendor_id => $vendor_rows ) {
            $stats[ $vendor_id ] = self::rollup_blog_rows( $vendor_rows );
        }
        return $stats;
    }

    /**
     * PTA rollup math (spec: Storage). Input: one GROUP BY blog_id row set
     * for a single vendor. A PTA counts as recommending when at least half
     * of its reviewers recommend — SUM(recommend) * 2 >= COUNT(*), so ties
     * lean yes. Card ranking uses the more granular reviewer-level
     * recommend-%; the PTA rollup is display language, not the sort key.
     */
    private static function rollup_blog_rows( $rows ) {
        $review_count    = 0;
        $recommend_count = 0;
        $price_sum       = 0;
        $quality_sum     = 0;
        $ptas_total      = 0;
        $ptas_recommend  = 0;

        foreach ( $rows as $row ) {
            $cnt = (int) $row->review_count;
            $rec = (int) $row->recommend_count;

            $review_count    += $cnt;
            $recommend_count += $rec;
            $price_sum       += (int) $row->price_sum;
            $quality_sum     += (int) $row->quality_sum;

            $ptas_total++;
            if ( $rec * 2 >= $cnt ) {
                $ptas_recommend++;
            }
        }

        return array(
            'review_count'           => $review_count,
            'avg_price'              => $review_count ? round( $price_sum / $review_count, 1 ) : 0,
            'avg_quality'            => $review_count ? round( $quality_sum / $review_count, 1 ) : 0,
            'reviewer_recommend_pct' => $review_count ? (int) round( $recommend_count * 100 / $review_count ) : 0,
            'ptas_total'             => $ptas_total,
            'ptas_recommend'         => $ptas_recommend,
        );
    }

    /* -------------------------------------------------------------- */
    /*  Attribution                                                   */
    /* -------------------------------------------------------------- */

    /**
     * Resolve a review row's attribution at render time (names are never
     * stored, so renames stay correct). Single-site-safe: get_blog_details()
     * does not exist outside multisite.
     *
     * @param object|array $row Review row with user_id and blog_id.
     * @return array { name, pta }
     */
    public static function format_attribution( $row ) {
        $row = (object) $row;

        $user = get_userdata( (int) $row->user_id );
        $name = ( $user && $user->display_name ) ? $user->display_name : 'A PTA member';

        $pta = '';
        if ( is_multisite() ) {
            $details = get_blog_details( (int) $row->blog_id );
            if ( $details && ! empty( $details->blogname ) ) {
                $pta = $details->blogname;
            }
        } else {
            $pta = get_bloginfo( 'name' );
        }
        if ( '' === $pta ) {
            $pta = 'PTA member site';
        }

        return array(
            'name' => $name,
            'pta'  => $pta,
        );
    }
}
