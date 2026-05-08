<?php
/**
 * Public preview links for draft pta_knowledge entries.
 *
 * Generates a 7-day token (stored in post meta) so editors can share
 * a preview URL like ?ptk_preview=<token> with someone who doesn't
 * have a wp-admin login. Tokens auto-revoke when the post is published
 * and a daily cron job cleans up expired ones.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Public_Preview {

    const META_TOKEN   = 'ptk_preview_token';
    const META_EXPIRES = 'ptk_preview_expires';
    const TTL_SECONDS  = 7 * DAY_IN_SECONDS;
    const CRON_HOOK    = 'ptk_cleanup_preview_tokens';

    private static $is_preview = false;
    private static $banner_html = '';

    public static function init() {
        // Query var registration — without this, ?ptk_preview=... gets stripped.
        add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );

        // Front-end preview rendering.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render_preview' ) );

        // Editor UI.
        add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'render_publish_box' ) );

        // Action handlers.
        add_action( 'admin_action_ptk_generate_preview', array( __CLASS__, 'handle_generate' ) );
        add_action( 'admin_action_ptk_revoke_preview', array( __CLASS__, 'handle_revoke' ) );

        // Auto-revoke when a draft transitions to publish.
        add_action( 'transition_post_status', array( __CLASS__, 'auto_revoke_on_publish' ), 10, 3 );

        // Cron cleanup.
        add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_expired' ) );
        add_action( 'init', array( __CLASS__, 'schedule_cron' ) );

        // Banner injection on the previewed entry.
        add_filter( 'the_content', array( __CLASS__, 'inject_banner' ), 30 );
    }

    public static function is_preview_mode(): bool {
        return self::$is_preview;
    }

    public static function register_query_var( $vars ) {
        $vars[] = 'ptk_preview';
        return $vars;
    }

    public static function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /* ------------------------------------------------------------- */
    /*  Front-end render                                             */
    /* ------------------------------------------------------------- */

    public static function maybe_render_preview() {
        $token = get_query_var( 'ptk_preview' );
        if ( ! $token ) {
            return;
        }

        $token = sanitize_text_field( wp_unslash( $token ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            self::deny();
        }

        $found = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => array( 'draft', 'pending', 'private', 'future' ),
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => self::META_TOKEN,
                    'value' => $token,
                ),
            ),
        ) );

        if ( empty( $found ) ) {
            self::deny();
        }

        $post = $found[0];

        $expires = (int) get_post_meta( $post->ID, self::META_EXPIRES, true );
        if ( $expires && $expires < time() ) {
            self::deny();
        }

        // Set up the global query/post so the standard single template renders.
        global $wp_query;
        $wp_query->is_single             = true;
        $wp_query->is_singular           = true;
        $wp_query->is_404                = false;
        $wp_query->is_home               = false;
        $wp_query->queried_object        = $post;
        $wp_query->queried_object_id     = $post->ID;
        $wp_query->posts                 = array( $post );
        $wp_query->post_count            = 1;
        $wp_query->found_posts           = 1;
        $wp_query->max_num_pages         = 1;
        $wp_query->current_post          = -1;
        $GLOBALS['post']                 = $post;
        setup_postdata( $post );

        self::$is_preview  = true;
        self::$banner_html = self::build_banner( $expires );

        $template = get_query_template( 'single-pta_knowledge' );
        if ( ! $template ) {
            $template = PTK_PLUGIN_DIR . 'templates/single-pta_knowledge.php';
        }
        if ( file_exists( $template ) ) {
            include $template;
        }
        exit;
    }

    private static function deny() {
        wp_die(
            'This preview link has expired or is no longer valid. Ask the PTA editor for a fresh link.',
            'Preview unavailable',
            array( 'response' => 404 )
        );
    }

    public static function inject_banner( $content ) {
        if ( ! self::$is_preview || '' === self::$banner_html ) {
            return $content;
        }
        return self::$banner_html . $content;
    }

    private static function build_banner( int $expires ): string {
        $when = $expires ? date_i18n( get_option( 'date_format' ), $expires ) : '';
        $html  = '<div class="ptk-preview-banner" style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;margin:0 0 24px;color:#78350f;font-size:14px;">';
        $html .= '<strong>Preview — not yet published.</strong> ';
        $html .= 'Anyone with this link can view this draft';
        if ( $when ) {
            $html .= ' until ' . esc_html( $when );
        }
        $html .= '.';
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------- */
    /*  Publish meta box UI                                          */
    /* ------------------------------------------------------------- */

    public static function render_publish_box( $post ) {
        if ( 'pta_knowledge' !== $post->post_type ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $token   = get_post_meta( $post->ID, self::META_TOKEN, true );
        $expires = (int) get_post_meta( $post->ID, self::META_EXPIRES, true );
        $is_active = $token && $expires && $expires > time();
        $preview_url = $is_active ? add_query_arg( 'ptk_preview', $token, home_url( '/' ) ) : '';

        ?>
        <div class="misc-pub-section ptk-preview-box" style="border-top:1px solid #ddd;padding-top:10px;">
            <strong>Share with reviewers</strong><br>
            <p class="description" style="margin:6px 0;">
                <?php if ( $is_active ) : ?>
                    Active until <?php echo esc_html( date_i18n( get_option( 'date_format' ), $expires ) ); ?>.
                <?php else : ?>
                    No active link.
                <?php endif; ?>
            </p>

            <?php if ( $is_active ) : ?>
                <input type="text" readonly value="<?php echo esc_attr( $preview_url ); ?>" id="ptk-preview-url" style="width:100%;font-size:11px;margin-bottom:6px;" onclick="this.select();" />
                <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('ptk-preview-url').value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy URL',1500);">Copy URL</button>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:inline;margin-left:6px;">
                    <?php wp_nonce_field( 'ptk_revoke_preview_' . $post->ID ); ?>
                    <input type="hidden" name="action" value="ptk_revoke_preview">
                    <input type="hidden" name="post" value="<?php echo esc_attr( $post->ID ); ?>">
                    <button type="submit" class="button button-small button-link-delete">Revoke</button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'ptk_generate_preview_' . $post->ID ); ?>
                    <input type="hidden" name="action" value="ptk_generate_preview">
                    <input type="hidden" name="post" value="<?php echo esc_attr( $post->ID ); ?>">
                    <button type="submit" class="button button-small">Generate preview link</button>
                </form>
                <p class="description" style="margin-top:6px;font-size:11px;color:#6b7280;">Anyone with the link can view this draft for 7 days.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------- */
    /*  Action handlers                                              */
    /* ------------------------------------------------------------- */

    public static function handle_generate() {
        $post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
        self::guard_request( $post_id, 'ptk_generate_preview_' . $post_id );

        $token = bin2hex( random_bytes( 16 ) );
        update_post_meta( $post_id, self::META_TOKEN, $token );
        update_post_meta( $post_id, self::META_EXPIRES, time() + self::TTL_SECONDS );

        wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
        exit;
    }

    public static function handle_revoke() {
        $post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
        self::guard_request( $post_id, 'ptk_revoke_preview_' . $post_id );

        delete_post_meta( $post_id, self::META_TOKEN );
        delete_post_meta( $post_id, self::META_EXPIRES );

        wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
        exit;
    }

    private static function guard_request( $post_id, $nonce_action ) {
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( $nonce_action );
        $post = get_post( $post_id );
        if ( ! $post || 'pta_knowledge' !== $post->post_type ) {
            wp_die( 'Entry not found.', '', array( 'response' => 404 ) );
        }
    }

    /* ------------------------------------------------------------- */
    /*  Auto revoke + cleanup                                        */
    /* ------------------------------------------------------------- */

    public static function auto_revoke_on_publish( $new_status, $old_status, $post ) {
        if ( ! $post || 'pta_knowledge' !== $post->post_type ) {
            return;
        }
        if ( 'publish' === $new_status && 'publish' !== $old_status ) {
            delete_post_meta( $post->ID, self::META_TOKEN );
            delete_post_meta( $post->ID, self::META_EXPIRES );
        }
    }

    public static function cleanup_expired() {
        global $wpdb;
        $now = time();

        // Find expired token rows; delete both meta keys for each.
        $expired_post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d",
                self::META_EXPIRES,
                $now
            )
        );

        if ( empty( $expired_post_ids ) ) {
            return;
        }

        foreach ( $expired_post_ids as $pid ) {
            delete_post_meta( (int) $pid, self::META_TOKEN );
            delete_post_meta( (int) $pid, self::META_EXPIRES );
        }
    }
}
