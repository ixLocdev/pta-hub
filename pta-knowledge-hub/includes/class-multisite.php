<?php
/**
 * WordPress Multisite content sharing for PTA Knowledge Hub.
 *
 * Allows the Council site (main site) to push knowledge entries
 * to all school subsites. Individual schools can suggest content
 * back up to the Council for review.
 *
 * Only activates when is_multisite() is true. Single-site installs
 * are completely unaffected.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Multisite {

    /** @var bool Prevents infinite recursion during sync. */
    private static $syncing = false;

    /**
     * Register hooks — only when running on a multisite network.
     */
    public static function init() {
        if ( ! is_multisite() ) {
            return;
        }

        add_action( 'save_post_pta_knowledge', array( __CLASS__, 'maybe_sync_to_network' ), 20, 3 );
        add_action( 'before_delete_post', array( __CLASS__, 'maybe_delete_from_network' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_network_admin_page' ) );
        add_action( 'wp_ajax_ptk_suggest_to_council', array( __CLASS__, 'handle_suggest_to_council' ) );
    }

    /* ------------------------------------------------------------------
     * Meta Boxes
     * ----------------------------------------------------------------*/

    /**
     * Register meta boxes based on site context.
     */
    public static function register_meta_boxes() {
        if ( ! get_option( 'ptk_enable_network_sharing', false ) && self::is_main_site() ) {
            return;
        }

        if ( self::is_main_site() ) {
            add_meta_box(
                'ptk_network_share',
                'Network Sharing',
                array( __CLASS__, 'render_share_meta_box' ),
                'pta_knowledge',
                'side',
                'default'
            );
        } else {
            add_meta_box(
                'ptk_network_notice',
                'Network Status',
                array( __CLASS__, 'render_network_notice_meta_box' ),
                'pta_knowledge',
                'side',
                'high'
            );
        }
    }

    /**
     * Render the "Share to All Schools" checkbox (main site only).
     */
    public static function render_share_meta_box( $post ) {
        wp_nonce_field( 'ptk_network_share', 'ptk_network_share_nonce' );
        $shared = get_post_meta( $post->ID, 'ptk_share_network', true );
        ?>
        <label style="display:block;padding:4px 0;font-size:13px;">
            <input type="checkbox" name="ptk_share_network" value="1" <?php checked( $shared, '1' ); ?>>
            <strong>Share to All Schools</strong>
        </label>
        <p class="description" style="margin-top:8px;">
            When checked, this entry will be automatically copied to all school sites
            in the network and kept in sync when you update it.
        </p>
        <?php
    }

    /**
     * Render info banner on synced posts in subsites.
     */
    public static function render_network_notice_meta_box( $post ) {
        if ( ! self::is_network_copy( $post->ID ) ) {
            // Not a synced copy — show suggest button for local entries.
            if ( 'publish' === $post->post_status ) {
                wp_nonce_field( 'ptk_suggest_to_council', 'ptk_suggest_nonce' );
                ?>
                <p class="description">This is a local entry. You can suggest it to the PTA Council for district-wide sharing.</p>
                <button type="button" class="button" id="ptk-suggest-btn"
                        data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'ptk_suggest_nonce' ) ); ?>"
                        style="margin-top:8px;">
                    Suggest to Council
                </button>
                <div id="ptk-suggest-result" style="margin-top:8px;"></div>
                <script>
                (function(){
                    var btn = document.getElementById('ptk-suggest-btn');
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        btn.disabled = true;
                        btn.textContent = 'Sending...';
                        var body = new FormData();
                        body.append('action', 'ptk_suggest_to_council');
                        body.append('_wpnonce', btn.getAttribute('data-nonce'));
                        body.append('post_id', btn.getAttribute('data-post-id'));
                        fetch(ajaxurl, { method: 'POST', body: body })
                            .then(function(r){ return r.json(); })
                            .then(function(json){
                                var el = document.getElementById('ptk-suggest-result');
                                if (json.success) {
                                    el.textContent = 'Suggestion sent to the Council!';
                                    el.style.color = '#059669';
                                } else {
                                    el.textContent = json.data && json.data.message ? json.data.message : 'Error sending suggestion.';
                                    el.style.color = '#dc2626';
                                    btn.disabled = false;
                                    btn.textContent = 'Suggest to Council';
                                }
                            })
                            .catch(function(){
                                btn.disabled = false;
                                btn.textContent = 'Suggest to Council';
                            });
                    });
                })();
                </script>
                <?php
            }
            return;
        }

        $source_blog = get_post_meta( $post->ID, 'ptk_network_source_blog', true );
        $source_post = get_post_meta( $post->ID, 'ptk_network_source', true );

        ?>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:10px 12px;font-size:13px;color:#1e40af;">
            <strong>Managed by PTA Council</strong><br>
            This entry is synced from the Council site. Edits here will be overwritten on the next sync.
            <?php if ( $source_blog && $source_post ) :
                $edit_url = get_admin_url( $source_blog, 'post.php?post=' . intval( $source_post ) . '&action=edit' );
            ?>
                <br><a href="<?php echo esc_url( $edit_url ); ?>" style="color:#1e40af;font-weight:600;">Edit on Council site &rarr;</a>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Sync: Main Site → Subsites
     * ----------------------------------------------------------------*/

    /**
     * Hook into save_post to sync content to the network.
     */
    public static function maybe_sync_to_network( $post_id, $post, $update ) {
        // Prevent infinite recursion.
        if ( self::$syncing ) {
            return;
        }

        // Only run on main site.
        if ( ! self::is_main_site() ) {
            return;
        }

        // Skip autosaves and revisions.
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Check capability.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Check nonce (only present when saving from the editor).
        if ( isset( $_POST['ptk_network_share_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['ptk_network_share_nonce'], 'ptk_network_share' ) ) {
                return;
            }

            // Save the share checkbox.
            if ( ! empty( $_POST['ptk_share_network'] ) ) {
                update_post_meta( $post_id, 'ptk_share_network', '1' );
            } else {
                // Was previously shared, now unchecked — remove from network.
                $was_shared = get_post_meta( $post_id, 'ptk_share_network', true );
                delete_post_meta( $post_id, 'ptk_share_network' );
                if ( '1' === $was_shared ) {
                    self::unshare_from_network( $post_id );
                }
                return;
            }
        }

        // Only sync published posts that are marked for sharing.
        if ( 'publish' !== $post->post_status ) {
            return;
        }

        $shared = get_post_meta( $post_id, 'ptk_share_network', true );
        if ( '1' !== $shared ) {
            return;
        }

        // Network sharing must be enabled.
        if ( ! get_option( 'ptk_enable_network_sharing', false ) ) {
            return;
        }

        self::sync_to_network( $post_id );
    }

    /**
     * Push a post to all subsites.
     *
     * @param int $post_id Post ID on the main site.
     */
    public static function sync_to_network( $post_id ) {
        self::$syncing = true;

        $post     = get_post( $post_id );
        $main_id  = get_main_site_id();
        $subsites = self::get_subsites();

        // Get source post terms.
        $cat_terms = wp_get_post_terms( $post_id, 'knowledge_category' );
        $tag_terms = wp_get_post_terms( $post_id, 'post_tag' );

        foreach ( $subsites as $site ) {
            switch_to_blog( $site->blog_id );

            // Find existing copy.
            $existing = get_posts( array(
                'post_type'      => 'pta_knowledge',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'   => 'ptk_network_source',
                        'value' => $post_id,
                    ),
                    array(
                        'key'   => 'ptk_network_source_blog',
                        'value' => $main_id,
                    ),
                ),
            ) );

            $post_data = array(
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status'  => 'publish',
                'post_type'    => 'pta_knowledge',
            );

            if ( ! empty( $existing ) ) {
                // Update existing copy.
                $copy_id = $existing[0]->ID;
                $post_data['ID'] = $copy_id;
                wp_update_post( $post_data );
            } else {
                // Create new copy.
                $copy_id = wp_insert_post( $post_data );
                if ( $copy_id && ! is_wp_error( $copy_id ) ) {
                    update_post_meta( $copy_id, 'ptk_network_source', $post_id );
                    update_post_meta( $copy_id, 'ptk_network_source_blog', $main_id );
                }
            }

            // Sync taxonomy terms.
            if ( $copy_id && ! is_wp_error( $copy_id ) ) {
                self::sync_terms( $copy_id, $cat_terms, 'knowledge_category' );
                self::sync_terms( $copy_id, $tag_terms, 'post_tag' );
            }

            restore_current_blog();
        }

        self::$syncing = false;
    }

    /**
     * Remove network copies when unsharing a post.
     *
     * @param int $post_id Post ID on the main site.
     */
    public static function unshare_from_network( $post_id ) {
        self::$syncing = true;

        $main_id  = get_main_site_id();
        $subsites = self::get_subsites();

        foreach ( $subsites as $site ) {
            switch_to_blog( $site->blog_id );

            $copies = get_posts( array(
                'post_type'      => 'pta_knowledge',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'   => 'ptk_network_source',
                        'value' => $post_id,
                    ),
                    array(
                        'key'   => 'ptk_network_source_blog',
                        'value' => $main_id,
                    ),
                ),
            ) );

            foreach ( $copies as $copy ) {
                wp_trash_post( $copy->ID );
            }

            restore_current_blog();
        }

        self::$syncing = false;
    }

    /**
     * Delete network copies when a shared post is permanently deleted.
     *
     * @param int $post_id Post ID being deleted.
     */
    public static function maybe_delete_from_network( $post_id ) {
        if ( self::$syncing ) {
            return;
        }

        if ( 'pta_knowledge' !== get_post_type( $post_id ) ) {
            return;
        }

        if ( ! self::is_main_site() ) {
            return;
        }

        $shared = get_post_meta( $post_id, 'ptk_share_network', true );
        if ( '1' !== $shared ) {
            return;
        }

        self::unshare_from_network( $post_id );
    }

    /* ------------------------------------------------------------------
     * Suggest: Subsite → Council
     * ----------------------------------------------------------------*/

    /**
     * AJAX handler for suggesting a subsite entry to the Council.
     */
    public static function handle_suggest_to_council() {
        check_ajax_referer( 'ptk_suggest_nonce', '_wpnonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post    = get_post( $post_id );

        if ( ! $post || 'pta_knowledge' !== $post->post_type ) {
            wp_send_json_error( array( 'message' => 'Invalid entry.' ) );
        }

        $source_blog = get_current_blog_id();
        $main_id     = get_main_site_id();

        switch_to_blog( $main_id );

        // Check if already suggested.
        $existing = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'draft',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => 'ptk_suggested_from_blog',
                    'value' => $source_blog,
                ),
                array(
                    'key'   => 'ptk_suggested_from_post',
                    'value' => $post_id,
                ),
            ),
        ) );

        if ( ! empty( $existing ) ) {
            restore_current_blog();
            wp_send_json_error( array( 'message' => 'This entry has already been suggested.' ) );
        }

        $blog_details = get_blog_details( $source_blog );
        $site_name    = $blog_details ? $blog_details->blogname : 'School Site';

        $draft_id = wp_insert_post( array(
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => 'draft',
            'post_type'    => 'pta_knowledge',
        ) );

        if ( $draft_id && ! is_wp_error( $draft_id ) ) {
            update_post_meta( $draft_id, 'ptk_suggested_from_blog', $source_blog );
            update_post_meta( $draft_id, 'ptk_suggested_from_post', $post_id );

            // Copy taxonomy terms.
            restore_current_blog();
            $cat_terms = wp_get_post_terms( $post_id, 'knowledge_category' );
            $tag_terms = wp_get_post_terms( $post_id, 'post_tag' );
            switch_to_blog( $main_id );

            self::sync_terms( $draft_id, $cat_terms, 'knowledge_category' );
            self::sync_terms( $draft_id, $tag_terms, 'post_tag' );
        }

        restore_current_blog();

        wp_send_json_success( array(
            'message' => 'Suggestion sent to the PTA Council for review!',
        ) );
    }

    /* ------------------------------------------------------------------
     * Network Admin Page
     * ----------------------------------------------------------------*/

    /**
     * Add Network Sync page to the admin menu (main site only).
     */
    public static function add_network_admin_page() {
        if ( ! self::is_main_site() || ! get_option( 'ptk_enable_network_sharing', false ) ) {
            return;
        }

        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Network Sync',
            'Network Sync',
            'manage_options',
            'ptk-network-sync',
            array( __CLASS__, 'render_network_admin_page' )
        );
    }

    /**
     * Render the Network Sync admin page.
     */
    public static function render_network_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        $shared_ids = self::get_shared_post_ids();
        $subsites   = self::get_subsites();

        // Get pending suggestions.
        $suggestions = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'draft',
            'posts_per_page' => 50,
            'meta_query'     => array(
                array(
                    'key'     => 'ptk_suggested_from_blog',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Network Sync</h1>
            <p style="font-size:14px;color:#6b7280;">Manage content sharing across all school sites in your network.</p>

            <!-- Stats -->
            <div style="display:flex;gap:16px;margin:24px 0;">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;flex:1;">
                    <div style="font-size:28px;font-weight:700;color:#111827;"><?php echo esc_html( count( $shared_ids ) ); ?></div>
                    <div style="font-size:13px;color:#6b7280;">Shared Entries</div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;flex:1;">
                    <div style="font-size:28px;font-weight:700;color:#111827;"><?php echo esc_html( count( $subsites ) ); ?></div>
                    <div style="font-size:13px;color:#6b7280;">School Sites</div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;flex:1;">
                    <div style="font-size:28px;font-weight:700;color:#111827;"><?php echo esc_html( count( $suggestions ) ); ?></div>
                    <div style="font-size:13px;color:#6b7280;">Pending Suggestions</div>
                </div>
            </div>

            <!-- Pending Suggestions -->
            <?php if ( ! empty( $suggestions ) ) : ?>
            <h2 style="margin-top:32px;">Pending Suggestions from Schools</h2>
            <p style="font-size:13px;color:#6b7280;">These entries were suggested by individual schools. Review and publish them to share across the network.</p>
            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr><th>Title</th><th>From School</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $suggestions as $sug ) :
                        $from_blog = get_post_meta( $sug->ID, 'ptk_suggested_from_blog', true );
                        $blog_info = $from_blog ? get_blog_details( $from_blog ) : null;
                        $school    = $blog_info ? $blog_info->blogname : 'Unknown';
                        $edit_url  = get_edit_post_link( $sug->ID );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $sug->post_title ); ?></strong></td>
                        <td><?php echo esc_html( $school ); ?></td>
                        <td><a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Shared Entries List -->
            <?php if ( ! empty( $shared_ids ) ) : ?>
            <h2 style="margin-top:32px;">Currently Shared Entries</h2>
            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr><th>Title</th><th>Last Updated</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $shared_ids as $sid ) :
                        $spost = get_post( $sid );
                        if ( ! $spost ) continue;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $spost->post_title ); ?></strong></td>
                        <td><?php echo esc_html( get_the_modified_date( '', $spost ) ); ?></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $sid ) ); ?>" class="button button-small">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p style="color:#9ca3af;margin-top:24px;">No entries are currently shared across the network. Edit any entry and check "Share to All Schools" to get started.</p>
            <?php endif; ?>

            <!-- Subsites List -->
            <?php if ( ! empty( $subsites ) ) : ?>
            <h2 style="margin-top:32px;">School Sites in Network</h2>
            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr><th>Site</th><th>URL</th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $subsites as $site ) :
                        $details = get_blog_details( $site->blog_id );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $details ? $details->blogname : 'Site #' . $site->blog_id ); ?></strong></td>
                        <td><a href="<?php echo esc_url( $details ? $details->siteurl : '#' ); ?>" target="_blank"><?php echo esc_html( $details ? $details->siteurl : '' ); ?></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Check if the current site is the main network site.
     *
     * @return bool
     */
    public static function is_main_site() {
        if ( ! is_multisite() ) {
            return false;
        }
        return get_current_blog_id() === get_main_site_id();
    }

    /**
     * Check if a post is a synced network copy.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function is_network_copy( $post_id ) {
        if ( ! is_multisite() ) {
            return false;
        }
        $source = get_post_meta( $post_id, 'ptk_network_source', true );
        return ! empty( $source );
    }

    /**
     * Get all subsites excluding the main site.
     *
     * @return array Array of site objects.
     */
    public static function get_subsites() {
        if ( ! is_multisite() ) {
            return array();
        }

        $cached = get_transient( 'ptk_subsites' );
        if ( false !== $cached ) {
            return $cached;
        }

        $main_id = get_main_site_id();
        $sites   = get_sites( array(
            'number'     => 100,
            'public'     => 1,
            'archived'   => 0,
            'deleted'    => 0,
            'site__not_in' => array( $main_id ),
        ) );

        set_transient( 'ptk_subsites', $sites, 5 * MINUTE_IN_SECONDS );

        return $sites;
    }

    /**
     * Get IDs of all posts marked for network sharing.
     *
     * @return array Array of post IDs.
     */
    public static function get_shared_post_ids() {
        return get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'ptk_share_network',
                    'value' => '1',
                ),
            ),
        ) );
    }

    /**
     * Sync taxonomy terms from the source to a target post.
     *
     * Creates terms on the target site if they don't exist.
     *
     * @param int    $target_post_id Target post ID (on the current switched blog).
     * @param array  $source_terms   Array of WP_Term objects from the source.
     * @param string $taxonomy       Taxonomy slug.
     */
    private static function sync_terms( $target_post_id, $source_terms, $taxonomy ) {
        if ( empty( $source_terms ) || is_wp_error( $source_terms ) ) {
            wp_set_object_terms( $target_post_id, array(), $taxonomy );
            return;
        }

        $term_ids = array();
        foreach ( $source_terms as $term ) {
            $existing = get_term_by( 'slug', $term->slug, $taxonomy );
            if ( $existing ) {
                $term_ids[] = $existing->term_id;
            } else {
                $new = wp_insert_term( $term->name, $taxonomy, array(
                    'slug'        => $term->slug,
                    'description' => $term->description,
                ) );
                if ( ! is_wp_error( $new ) ) {
                    $term_ids[] = $new['term_id'];
                }
            }
        }

        wp_set_object_terms( $target_post_id, $term_ids, $taxonomy );
    }
}
