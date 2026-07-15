<?php
/**
 * Vendor moderation: "Vendor Approvals" admin queue (Council site),
 * approve/reject handlers, notification email.
 *
 * The queue lives ONLY on the main site (where vendor posts and the shared
 * reviews table's owning context are), so all rendering reads are local —
 * no switch_to_blog() needed. The notification hooks are registered on
 * EVERY site because submissions arrive via subsite AJAX requests.
 *
 * Semantics (plan Task 6 / spec "Moderation & notifications"):
 * - review approve  → status 'approved'
 * - review reject   → DELETE the row
 * - vendor approve  → wp_update_post to publish (generates the slug that
 *   pending posts lack — wp_publish_post would not) + approve ALL its
 *   pending reviews
 * - vendor reject   → wp_trash_post + DELETE all its review rows
 * Every action bumps the network cache version.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Vendor_Moderation {

    const PAGE_SLUG = 'ptk-vendor-approvals';

    public static function init() {
        // Notification email — hooked everywhere: reviews and suggestions
        // are submitted from subsite AJAX contexts.
        add_action( 'ptk_vendor_review_submitted', array( __CLASS__, 'notify_council' ), 10, 2 );
        add_action( 'ptk_vendor_suggested',        array( __CLASS__, 'notify_council' ), 10, 2 );

        // Queue + actions: MAIN SITE ONLY. Core is_main_site() — true on
        // single-site installs too, so they still get the queue (deliberately
        // NOT PTK_Multisite::is_main_site(), which is false on single-site).
        if ( ! is_main_site() ) {
            return;
        }

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_ptk_vendor_moderate', array( __CLASS__, 'handle_moderate' ) );

        // Review-row lifecycle: permanently deleting a vendor in wp-admin
        // must purge its rows from the shared reviews table, or they'd be
        // orphaned forever. Trash alone keeps the rows (the vendor is
        // restorable); only a hard delete purges.
        add_action( 'before_delete_post', array( __CLASS__, 'purge_reviews_on_vendor_delete' ) );
    }

    /* -------------------------------------------------------------- */
    /*  Menu                                                          */
    /* -------------------------------------------------------------- */

    public static function register_menu() {
        $pending = self::pending_count();

        $menu_title = 'Vendor Approvals';
        if ( $pending > 0 ) {
            $menu_title .= sprintf(
                ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
                $pending
            );
        }

        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Vendor Approvals',
            $menu_title,
            'edit_others_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_queue_page' )
        );
    }

    /** Pending vendors + pending reviews on published vendors (reviews on
     *  pending vendors are counted with their vendor, not double-counted). */
    private static function pending_count() {
        global $wpdb;

        $counts          = wp_count_posts( 'ptk_vendor' );
        $pending_vendors = $counts ? (int) $counts->pending : 0;

        $table           = PTK_Vendor_Reviews::table();
        $pending_reviews = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table} r
             INNER JOIN {$wpdb->posts} p
                     ON p.ID = r.vendor_id
                    AND p.post_type = 'ptk_vendor'
                    AND p.post_status = 'publish'
             WHERE r.status = 'pending'"
        );

        return $pending_vendors + $pending_reviews;
    }

    /* -------------------------------------------------------------- */
    /*  Queue page                                                    */
    /* -------------------------------------------------------------- */

    public static function render_queue_page() {
        if ( ! current_user_can( 'edit_others_posts' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        global $wpdb;
        $table = PTK_Vendor_Reviews::table();

        // Pending vendors (suggested ones arrive with a bundled review).
        $pending_vendors = get_posts( array(
            'post_type'   => 'ptk_vendor',
            'post_status' => 'pending',
            'numberposts' => -1,
            'orderby'     => 'date',
            'order'       => 'ASC',
        ) );

        // Pending reviews on ALREADY-PUBLISHED vendors. Reviews on pending
        // vendors are shown inline with their vendor above.
        $pending_reviews = $wpdb->get_results(
            "SELECT r.*, p.post_title AS vendor_name
             FROM {$table} r
             INNER JOIN {$wpdb->posts} p
                     ON p.ID = r.vendor_id
                    AND p.post_type = 'ptk_vendor'
                    AND p.post_status = 'publish'
             WHERE r.status = 'pending'
             ORDER BY r.updated_at ASC, r.id ASC"
        );

        $moderated = isset( $_GET['ptk_moderated'] ) ? sanitize_key( $_GET['ptk_moderated'] ) : '';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Vendor Approvals</h1>
            <p style="font-size:14px;color:#6b7280;">New vendors and reviews from PTA members across the network wait here until the Council approves them. Approvals appear on every school site within the hour (usually right away).</p>

            <?php if ( 'approved' === $moderated ) : ?>
                <div class="notice notice-success is-dismissible"><p>Approved &mdash; it&#8217;s now live on every school site.</p></div>
            <?php elseif ( 'rejected' === $moderated ) : ?>
                <div class="notice notice-success is-dismissible"><p>Rejected and removed.</p></div>
            <?php endif; ?>

            <?php if ( ! $pending_vendors && ! $pending_reviews ) : ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:32px;text-align:center;margin-top:16px;">
                    <p style="font-size:15px;color:#374151;margin:0;">Nothing waiting &mdash; all caught up! &#127881;</p>
                </div>
            <?php endif; ?>

            <?php if ( $pending_vendors ) : ?>
                <h2 style="margin-top:28px;">Pending Vendors (<?php echo esc_html( count( $pending_vendors ) ); ?>)</h2>
                <p style="font-size:13px;color:#6b7280;">Suggested by members, with their first review. Approving publishes the vendor AND its review together; rejecting removes both.</p>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Category</th>
                            <th>Contact</th>
                            <th>Suggested by</th>
                            <th>Their review</th>
                            <th style="width:170px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $pending_vendors as $vendor ) : ?>
                            <?php
                            $bundled = $wpdb->get_results( $wpdb->prepare(
                                "SELECT * FROM {$table} WHERE vendor_id = %d AND status = 'pending' ORDER BY id ASC",
                                $vendor->ID
                            ) );
                            $first   = $bundled ? $bundled[0] : null;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $vendor->post_title ); ?></strong></td>
                                <td><?php echo esc_html( self::vendor_category_name( $vendor ) ); ?></td>
                                <td><?php self::render_contact_cell( $vendor->ID ); ?></td>
                                <td>
                                    <?php
                                    if ( $first ) {
                                        $att = PTK_Vendor_Reviews::format_attribution( $first );
                                        echo esc_html( $att['name'] . ' · ' . $att['pta'] );
                                    } else {
                                        echo '<em>&mdash;</em>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ( $bundled ) {
                                        foreach ( $bundled as $row ) {
                                            self::render_review_summary( $row );
                                        }
                                    } else {
                                        echo '<em>No review attached.</em>';
                                    }
                                    ?>
                                </td>
                                <td><?php self::render_action_buttons( 'vendor', $vendor->ID ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $pending_reviews ) : ?>
                <h2 style="margin-top:28px;">Pending Reviews (<?php echo esc_html( count( $pending_reviews ) ); ?>)</h2>
                <p style="font-size:13px;color:#6b7280;">New reviews of vendors that are already in the directory.</p>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Reviewer</th>
                            <th>Review</th>
                            <th style="width:170px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $pending_reviews as $row ) : ?>
                            <?php $att = PTK_Vendor_Reviews::format_attribution( $row ); ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->vendor_name ); ?></strong></td>
                                <td><?php echo esc_html( $att['name'] . ' · ' . $att['pta'] ); ?></td>
                                <td><?php self::render_review_summary( $row ); ?></td>
                                <td><?php self::render_action_buttons( 'review', (int) $row->id ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /** First (only expected) category term name, or a dash. */
    private static function vendor_category_name( $vendor ) {
        $terms = get_the_terms( $vendor, 'vendor_category' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            return $terms[0]->name;
        }
        return '—';
    }

    private static function render_contact_cell( $vendor_id ) {
        $lines = array();

        $phone = get_post_meta( $vendor_id, 'ptk_vendor_phone', true );
        if ( $phone ) {
            $lines[] = esc_html( $phone );
        }
        $email = get_post_meta( $vendor_id, 'ptk_vendor_email', true );
        if ( $email ) {
            $lines[] = esc_html( $email );
        }
        $website = get_post_meta( $vendor_id, 'ptk_vendor_website', true );
        if ( $website ) {
            $lines[] = '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( preg_replace( '#^https?://#i', '', untrailingslashit( $website ) ) ) . '</a>';
        }

        echo $lines ? implode( '<br>', $lines ) : '<em>&mdash;</em>'; // Each line escaped above.
    }

    /** Thumbs + stars + full comment for one review row. */
    private static function render_review_summary( $row ) {
        $recommend = (int) $row->recommend;
        ?>
        <div style="margin:2px 0 8px;">
            <span style="font-weight:600;color:<?php echo $recommend ? '#15803d' : '#b91c1c'; ?>;">
                <?php echo $recommend ? '&#128077; Would use again' : '&#128078; Would not use again'; ?>
            </span>
            <br>
            <span style="color:#6b7280;font-size:12px;">
                Price <?php echo esc_html( str_repeat( '★', (int) $row->price_rating ) . str_repeat( '☆', 5 - (int) $row->price_rating ) ); ?>
                &middot;
                Quality <?php echo esc_html( str_repeat( '★', (int) $row->quality_rating ) . str_repeat( '☆', 5 - (int) $row->quality_rating ) ); ?>
            </span>
            <p style="margin:4px 0 0;white-space:pre-wrap;"><?php echo esc_html( $row->comment ); ?></p>
        </div>
        <?php
    }

    /** Approve / Reject links with a per-item nonce. */
    private static function render_action_buttons( $type, $id ) {
        foreach ( array( 'approve' => 'Approve', 'reject' => 'Reject' ) as $verdict => $label ) {
            $url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'ptk_vendor_moderate',
                        'type'    => $type,
                        'verdict' => $verdict,
                        'id'      => $id,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'ptk_vendor_moderate_' . $type . '_' . $id
            );
            printf(
                '<a href="%s" class="button %s" style="margin-right:6px;">%s</a>',
                esc_url( $url ),
                'approve' === $verdict ? 'button-primary' : '',
                esc_html( $label )
            );
        }
    }

    /* -------------------------------------------------------------- */
    /*  Approve / reject actions                                      */
    /* -------------------------------------------------------------- */

    public static function handle_moderate() {
        $type    = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
        $verdict = isset( $_GET['verdict'] ) ? sanitize_key( $_GET['verdict'] ) : '';
        $id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        // Per-item nonce (matches render_action_buttons).
        check_admin_referer( 'ptk_vendor_moderate_' . $type . '_' . $id );

        if ( ! current_user_can( 'edit_others_posts' ) ) {
            wp_die( 'You do not have permission to do this.' );
        }

        if ( ! in_array( $type, array( 'vendor', 'review' ), true )
            || ! in_array( $verdict, array( 'approve', 'reject' ), true )
            || $id < 1 ) {
            wp_die( 'Invalid moderation request.' );
        }

        if ( 'vendor' === $type ) {
            self::moderate_vendor( $id, $verdict );
        } else {
            self::moderate_review( $id, $verdict );
        }

        // Every moderation action changes what members should see (or the
        // queue count) — invalidate all sites' vendor caches.
        PTK_Network_Provisioning::bump_cache_version();

        $redirect = add_query_arg(
            array(
                'post_type'     => 'pta_knowledge',
                'page'          => self::PAGE_SLUG,
                'ptk_moderated' => 'approve' === $verdict ? 'approved' : 'rejected',
            ),
            admin_url( 'edit.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Vendor approve = publish + approve its bundled pending reviews;
     * vendor reject = trash + delete ALL its review rows.
     */
    private static function moderate_vendor( $vendor_id, $verdict ) {
        global $wpdb;

        $post = get_post( $vendor_id );
        if ( ! $post || 'ptk_vendor' !== $post->post_type ) {
            wp_die( 'That vendor no longer exists.' );
        }

        $table = PTK_Vendor_Reviews::table();

        if ( 'approve' === $verdict ) {
            // wp_update_post, NOT wp_publish_post: suggested vendors are
            // created as 'pending', and pending posts have NO slug.
            // wp_publish_post only flips the status ("does not do anything
            // except transition the post status" — core docs) and would
            // publish a vendor with an empty post_name, breaking the
            // directory's ?vendor={slug} links. wp_update_post runs the
            // full pipeline and generates the slug.
            wp_update_post( array(
                'ID'          => $vendor_id,
                'post_status' => 'publish',
            ) );
            // The bundle goes live together (spec: suggested vendor + first
            // review move through approval as one unit).
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET status = 'approved' WHERE vendor_id = %d AND status = 'pending'",
                $vendor_id
            ) );
        } else {
            wp_trash_post( $vendor_id );
            // Reject removes the pair: no review rows survive (the trashed
            // post itself stays restorable, per spec "trash vendor").
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE vendor_id = %d",
                $vendor_id
            ) );
        }
    }

    /**
     * Review approve = flip the row to approved; reject = delete the row
     * (no notification to the author in v3.0 — spec keeps it simple).
     */
    private static function moderate_review( $review_id, $verdict ) {
        global $wpdb;
        $table = PTK_Vendor_Reviews::table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d",
            $review_id
        ) );
        if ( ! $row ) {
            wp_die( 'That review no longer exists.' );
        }

        if ( 'approve' === $verdict ) {
            $wpdb->update(
                $table,
                array( 'status' => 'approved' ),
                array( 'id' => $review_id ),
                array( '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->delete( $table, array( 'id' => $review_id ), array( '%d' ) );
        }
    }

    /* -------------------------------------------------------------- */
    /*  Review-row lifecycle on vendor hard delete                    */
    /* -------------------------------------------------------------- */

    /**
     * before_delete_post: purge a vendor's rows from the shared reviews
     * table when the post is PERMANENTLY deleted (Empty Trash / "Delete
     * Permanently"), otherwise they would be orphaned forever.
     *
     * Trashing alone deliberately keeps the rows — a trashed vendor is
     * restorable and its reviews come back with it. Only hard delete purges.
     * Fires for every post type, so guard on ours first.
     */
    public static function purge_reviews_on_vendor_delete( $post_id ) {
        if ( get_post_type( $post_id ) !== 'ptk_vendor' ) {
            return;
        }

        global $wpdb;
        $wpdb->delete(
            PTK_Vendor_Reviews::table(),
            array( 'vendor_id' => $post_id ),
            array( '%d' )
        );
        // Cache bump for the delete itself is handled by
        // PTK_Vendor_Directory::bump_cache_on_trash_delete on this same hook.
    }

    /* -------------------------------------------------------------- */
    /*  Notification email                                            */
    /* -------------------------------------------------------------- */

    /**
     * One plain-text email to the Council admin per pending submission.
     * Runs in SUBSITE AJAX context, so the recipient must be resolved from
     * the MAIN site's options — a bare get_option('admin_email') here would
     * email the school, not the Council.
     *
     * @param int $vendor_id Vendor post ID (on the main site).
     * @param int $user_id   Submitting member.
     */
    public static function notify_council( $vendor_id, $user_id ) {
        $to = self::get_notification_recipients();
        if ( empty( $to ) ) {
            return;
        }

        $user   = get_userdata( (int) $user_id );
        $member = ( $user && $user->display_name ) ? $user->display_name : 'A PTA member';
        $pta    = get_bloginfo( 'name' ); // Current (submitting) site.

        $vendor_name = PTK_Network_Provisioning::on_main_site( function() use ( $vendor_id ) {
            $post = get_post( $vendor_id );
            return $post ? $post->post_title : '';
        } );
        if ( '' === $vendor_name ) {
            $vendor_name = 'a vendor';
        }

        $kind = ( 'ptk_vendor_suggested' === current_action() )
            ? 'suggested a new vendor'
            : 'wrote a review';

        $queue_url = get_admin_url(
            get_main_site_id(),
            'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG
        );

        $subject = 'PTA Hub: new vendor submission waiting for approval';

        $body  = "Hi,\n\n";
        $body .= sprintf( "%s (%s PTA) just %s: %s.\n\n", $member, $pta, $kind, $vendor_name );
        $body .= "It's waiting for Council approval and won't appear on any school site until someone approves it.\n\n";
        $body .= "Review it here:\n" . $queue_url . "\n\n";
        $body .= "— PTA Knowledge Hub";

        wp_mail( $to, $subject, $body );
    }

    /**
     * Resolve who receives approval notifications.
     *
     * Reads the "Vendor Approval Emails" setting (comma-separated list,
     * PTA Hub → Settings on the Council site); falls back to the Council
     * site's admin email when unset. Always resolved from the MAIN site's
     * options because submissions arrive in subsite AJAX context.
     *
     * @return string[] Valid recipient addresses (may be empty).
     */
    private static function get_notification_recipients() {
        $configured = is_multisite()
            ? get_blog_option( get_main_site_id(), 'ptk_vendor_notify_emails' )
            : get_option( 'ptk_vendor_notify_emails' );

        $recipients = array();
        foreach ( explode( ',', (string) $configured ) as $addr ) {
            $addr = trim( $addr );
            if ( $addr && is_email( $addr ) ) {
                $recipients[] = $addr;
            }
        }

        if ( empty( $recipients ) ) {
            $fallback = is_multisite()
                ? get_blog_option( get_main_site_id(), 'admin_email' )
                : get_option( 'admin_email' );
            if ( $fallback && is_email( $fallback ) ) {
                $recipients[] = $fallback;
            }
        }

        return $recipients;
    }
}
