<?php
/**
 * "Was This Helpful?" feedback system for PTA Knowledge entries.
 *
 * Stores thumbs-up/down votes in a custom DB table, deduplicates
 * by user ID (logged in) or IP hash (anonymous), and surfaces
 * feedback data in the admin dashboard and entry list.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Feedback {

    /** @var string Table name (set in init). */
    private static $table;

    public static function init() {
        global $wpdb;
        self::$table = $wpdb->prefix . 'ptk_feedback';

        add_action( 'wp_ajax_pta_feedback_vote', array( __CLASS__, 'handle_vote' ) );
        add_action( 'wp_ajax_nopriv_pta_feedback_vote', array( __CLASS__, 'handle_vote' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
    }

    /**
     * Create the feedback table on plugin activation.
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'ptk_feedback';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            helpful TINYINT(1) NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ip_hash VARCHAR(64) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Drop the table on uninstall.
     */
    public static function drop_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptk_feedback';
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * AJAX handler: record a vote.
     */
    public static function handle_vote() {
        check_ajax_referer( 'ptk_feedback_nonce', '_wpnonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $helpful = isset( $_POST['helpful'] ) ? absint( $_POST['helpful'] ) : 1;

        if ( ! $post_id || 'pta_knowledge' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid entry.' ) );
        }

        // Clamp to 0 or 1.
        $helpful = $helpful ? 1 : 0;

        // Check for duplicate vote.
        if ( self::has_user_voted( $post_id ) ) {
            $counts = self::get_feedback_counts( $post_id );
            wp_send_json_error( array(
                'message' => 'already_voted',
                'counts'  => $counts,
            ) );
        }

        global $wpdb;

        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $ip_hash = self::get_ip_hash();

        $wpdb->insert(
            self::$table,
            array(
                'post_id'    => $post_id,
                'helpful'    => $helpful,
                'user_id'    => $user_id,
                'ip_hash'    => $ip_hash,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%s', '%s' )
        );

        $counts = self::get_feedback_counts( $post_id );

        wp_send_json_success( array(
            'message' => 'Thanks for your feedback!',
            'counts'  => $counts,
        ) );
    }

    /**
     * Get feedback counts for a post.
     *
     * @param int $post_id Post ID.
     * @return array { 'helpful' => int, 'not_helpful' => int }
     */
    public static function get_feedback_counts( $post_id ) {
        global $wpdb;
        $table = self::get_table();

        $helpful = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND helpful = 1",
            $post_id
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $not_helpful = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND helpful = 0",
            $post_id
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return array(
            'helpful'     => $helpful,
            'not_helpful' => $not_helpful,
        );
    }

    /**
     * Check if the current user/visitor has already voted on a post.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function has_user_voted( $post_id ) {
        global $wpdb;
        $table = self::get_table();

        if ( is_user_logged_in() ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND user_id = %d",
                $post_id,
                get_current_user_id()
            ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } else {
            $ip_hash = self::get_ip_hash();
            $exists  = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND ip_hash = %s AND user_id = 0",
                $post_id,
                $ip_hash
            ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        return (int) $exists > 0;
    }

    /**
     * Enqueue feedback JS on single pta_knowledge pages.
     */
    public static function enqueue_assets() {
        if ( ! is_singular( 'pta_knowledge' ) ) {
            return;
        }

        wp_enqueue_script(
            'ptk-feedback',
            PTK_PLUGIN_URL . 'assets/js/feedback.js',
            array(),
            PTK_VERSION,
            true
        );

        wp_localize_script( 'ptk-feedback', 'ptkFeedback', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ptk_feedback_nonce' ),
        ) );
    }

    /**
     * Register the dashboard widget.
     */
    public static function add_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'ptk_feedback_overview',
            'PTA Knowledge Hub — Content Feedback',
            array( __CLASS__, 'render_dashboard_widget' )
        );
    }

    /**
     * Render the feedback dashboard widget.
     */
    public static function render_dashboard_widget() {
        global $wpdb;
        $table = self::get_table();

        // Most helpful entries (last 30 days).
        $most_helpful = $wpdb->get_results(
            "SELECT post_id, SUM(helpful = 1) AS up, SUM(helpful = 0) AS down
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY post_id
             ORDER BY up DESC
             LIMIT 10"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Least helpful entries.
        $least_helpful = $wpdb->get_results(
            "SELECT post_id, SUM(helpful = 1) AS up, SUM(helpful = 0) AS down
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY post_id
             HAVING down > 0
             ORDER BY down DESC, up ASC
             LIMIT 10"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        ?>
        <p style="font-size:13px;color:#6b7280;">Last 30 days &mdash; <strong><?php echo esc_html( number_format( $total ) ); ?></strong> total votes</p>

        <?php if ( ! empty( $most_helpful ) ) : ?>
        <h4 style="margin:16px 0 8px;color:#059669;">Most Helpful</h4>
        <table class="widefat striped" style="font-size:13px;">
            <thead><tr><th>Entry</th><th style="width:50px;text-align:center;">&#128077;</th><th style="width:50px;text-align:center;">&#128078;</th></tr></thead>
            <tbody>
                <?php foreach ( $most_helpful as $row ) :
                    $title = get_the_title( $row->post_id );
                    if ( ! $title ) continue;
                ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>"><?php echo esc_html( $title ); ?></a></td>
                    <td style="text-align:center;"><?php echo esc_html( $row->up ); ?></td>
                    <td style="text-align:center;"><?php echo esc_html( $row->down ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( ! empty( $least_helpful ) ) : ?>
        <h4 style="margin:16px 0 8px;color:#dc2626;">Needs Improvement</h4>
        <p style="font-size:12px;color:#9ca3af;margin:0 0 8px;">These entries received the most negative feedback. Consider reviewing and updating them.</p>
        <table class="widefat striped" style="font-size:13px;">
            <thead><tr><th>Entry</th><th style="width:50px;text-align:center;">&#128077;</th><th style="width:50px;text-align:center;">&#128078;</th></tr></thead>
            <tbody>
                <?php foreach ( $least_helpful as $row ) :
                    $title = get_the_title( $row->post_id );
                    if ( ! $title ) continue;
                ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>"><?php echo esc_html( $title ); ?></a></td>
                    <td style="text-align:center;"><?php echo esc_html( $row->up ); ?></td>
                    <td style="text-align:center;"><?php echo esc_html( $row->down ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( empty( $most_helpful ) && empty( $least_helpful ) ) : ?>
        <p style="color:#9ca3af;">No feedback yet. Votes will appear once people start using the feedback buttons.</p>
        <?php endif; ?>
        <?php
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the table name, initializing if needed.
     */
    private static function get_table() {
        if ( ! self::$table ) {
            global $wpdb;
            self::$table = $wpdb->prefix . 'ptk_feedback';
        }
        return self::$table;
    }

    /**
     * Get hashed client IP for anonymous deduplication.
     */
    private static function get_ip_hash() {
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return hash( 'sha256', $ip . wp_salt( 'auth' ) );
            }
        }
        return hash( 'sha256', '0.0.0.0' . wp_salt( 'auth' ) );
    }
}
