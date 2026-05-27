<?php
/**
 * Stale-content review reminders.
 *
 * - Adds a "Last Reviewed" sortable column to the pta_knowledge list table
 *   with a colored dot indicator (green / amber / red).
 * - Auto-stamps `ptk_last_reviewed = today` on publish/update, EXCEPT during
 *   multisite sync (so a Council push does not reset subsite clocks).
 * - "Mark Reviewed" row action and dashboard widget for entries past the
 *   12-month threshold (or never reviewed and themselves older than that).
 *
 * Threshold defaults to 12 months. Filter `ptk_review_threshold_months` to
 * customize per site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Review_Reminders {

    const THRESHOLD_DEFAULT_MONTHS = 12;
    const META_KEY                 = 'ptk_last_reviewed';

    public static function init() {
        // List table column.
        add_filter( 'manage_pta_knowledge_posts_columns', array( __CLASS__, 'add_column' ) );
        add_action( 'manage_pta_knowledge_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( 'manage_edit-pta_knowledge_sortable_columns', array( __CLASS__, 'register_sortable' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'handle_sort' ) );

        // Row action.
        add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );

        // Auto-stamp.
        add_action( 'save_post_pta_knowledge', array( __CLASS__, 'auto_stamp' ), 30, 2 );

        // Dashboard widget.
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );

        // "Mark Reviewed" handler.
        add_action( 'admin_action_ptk_mark_reviewed', array( __CLASS__, 'handle_mark_reviewed' ) );

        // Inline CSS for dots.
        add_action( 'admin_head', array( __CLASS__, 'inline_css' ) );
    }

    public static function get_threshold(): int {
        return (int) apply_filters( 'ptk_review_threshold_months', self::THRESHOLD_DEFAULT_MONTHS );
    }

    /* ------------------------------------------------------------- */
    /*  List table                                                   */
    /* ------------------------------------------------------------- */

    public static function add_column( $columns ) {
        // Insert before the date column.
        $new = array();
        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key ) {
                $new['ptk_last_reviewed'] = 'Last Reviewed';
            }
            $new[ $key ] = $label;
        }
        if ( ! isset( $new['ptk_last_reviewed'] ) ) {
            $new['ptk_last_reviewed'] = 'Last Reviewed';
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'ptk_last_reviewed' !== $column ) {
            return;
        }

        $reviewed = get_post_meta( $post_id, self::META_KEY, true );
        $threshold_months = self::get_threshold();
        $now = current_time( 'timestamp' );

        if ( $reviewed ) {
            $rev_ts  = strtotime( $reviewed );
            $months  = ( $now - $rev_ts ) / ( 30 * DAY_IN_SECONDS );
            $color   = 'green';
            if ( $months >= $threshold_months ) {
                $color = 'red';
            } elseif ( $months >= ( $threshold_months - 2 ) ) {
                $color = 'amber';
            }
            echo '<span class="ptk-review-dot ptk-review-' . esc_attr( $color ) . '"></span> ';
            echo esc_html( $reviewed );
        } else {
            // Never reviewed — flag red if the post itself is old.
            $post_ts = get_post_time( 'U', true, $post_id );
            $months  = ( $now - $post_ts ) / ( 30 * DAY_IN_SECONDS );
            $color   = ( $months >= $threshold_months ) ? 'red' : 'amber';
            echo '<span class="ptk-review-dot ptk-review-' . esc_attr( $color ) . '"></span> ';
            echo '<em style="color:#9ca3af;">Never</em>';
        }
    }

    public static function register_sortable( $columns ) {
        $columns['ptk_last_reviewed'] = 'ptk_last_reviewed';
        return $columns;
    }

    public static function handle_sort( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( 'ptk_last_reviewed' !== $query->get( 'orderby' ) ) {
            return;
        }
        $query->set( 'meta_key', self::META_KEY );
        $query->set( 'orderby', 'meta_value' );
    }

    /* ------------------------------------------------------------- */
    /*  Row action                                                   */
    /* ------------------------------------------------------------- */

    public static function add_row_action( $actions, $post ) {
        if ( 'pta_knowledge' !== $post->post_type ) {
            return $actions;
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url( 'admin.php?action=ptk_mark_reviewed&post=' . $post->ID ),
            'ptk_mark_reviewed_' . $post->ID
        );
        $actions['ptk_mark_reviewed'] = '<a href="' . esc_url( $url ) . '">Mark Reviewed</a>';
        return $actions;
    }

    public static function handle_mark_reviewed() {
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ptk_mark_reviewed_' . $post_id );
        update_post_meta( $post_id, self::META_KEY, current_time( 'Y-m-d' ) );

        $back = wp_get_referer();
        if ( ! $back ) {
            $back = admin_url( 'edit.php?post_type=pta_knowledge' );
        }
        wp_safe_redirect( $back );
        exit;
    }

    /* ------------------------------------------------------------- */
    /*  Auto-stamp on publish/update                                 */
    /* ------------------------------------------------------------- */

    public static function auto_stamp( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( 'publish' !== $post->post_status ) {
            return;
        }
        // Don't stamp during a multisite Council->subsite push.
        if ( class_exists( 'PTK_Multisite' ) && PTK_Multisite::is_syncing() ) {
            return;
        }
        update_post_meta( $post_id, self::META_KEY, current_time( 'Y-m-d' ) );
    }

    /* ------------------------------------------------------------- */
    /*  Dashboard widget                                             */
    /* ------------------------------------------------------------- */

    public static function add_dashboard_widget() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'ptk_review_reminders',
            'PTA Hub — Needs Review',
            array( __CLASS__, 'render_dashboard_widget' )
        );
    }

    public static function render_dashboard_widget() {
        $threshold_months = self::get_threshold();
        $threshold_date   = gmdate( 'Y-m-d', strtotime( '-' . $threshold_months . ' months' ) );

        $args = array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'meta_value',
            'meta_key'       => self::META_KEY,
            'order'          => 'ASC',
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => self::META_KEY,
                    'value'   => $threshold_date,
                    'compare' => '<',
                    'type'    => 'DATE',
                ),
                array(
                    'key'     => self::META_KEY,
                    'compare' => 'NOT EXISTS',
                ),
            ),
            'date_query' => array(
                array(
                    'before'    => $threshold_date,
                    'inclusive' => true,
                ),
            ),
        );

        $posts = get_posts( $args );

        if ( empty( $posts ) ) {
            echo '<p style="color:#6b7280;">All entries are up to date. ';
            echo 'Nothing has gone past the ' . esc_html( $threshold_months ) . '-month review threshold.</p>';
            return;
        }

        echo '<p style="font-size:13px;color:#6b7280;">Entries that haven\'t been reviewed in the last ' . esc_html( $threshold_months ) . ' months. ';
        echo 'Click "Mark Reviewed" once you\'ve checked the entry is still accurate.</p>';
        echo '<table class="widefat striped" style="font-size:13px;">';
        echo '<thead><tr><th>Title</th><th>Last Reviewed</th><th style="width:160px;">Actions</th></tr></thead><tbody>';

        foreach ( $posts as $p ) {
            $last = get_post_meta( $p->ID, self::META_KEY, true );
            $last_display = $last ? $last : '<em style="color:#9ca3af;">Never</em>';
            $edit_url = get_edit_post_link( $p->ID );
            $mark_url = wp_nonce_url(
                admin_url( 'admin.php?action=ptk_mark_reviewed&post=' . $p->ID ),
                'ptk_mark_reviewed_' . $p->ID
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $p->post_title ) . '</a></strong></td>';
            echo '<td><span class="ptk-review-dot ptk-review-red"></span> ' . wp_kses_post( $last_display ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( $mark_url ) . '" class="button button-small button-primary">Mark Reviewed</a> ';
            echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">Edit</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /* ------------------------------------------------------------- */
    /*  Inline CSS                                                   */
    /* ------------------------------------------------------------- */

    public static function inline_css() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_dashboard = $screen && 'dashboard' === $screen->id;
        $is_list      = $screen && 'edit-pta_knowledge' === $screen->id;
        $is_index     = $screen && 'index.php' === $screen->parent_file;
        if ( ! $is_dashboard && ! $is_list && ! $is_index ) {
            return;
        }
        echo '<style>
        .ptk-review-dot { display:inline-block; width:8px; height:8px; border-radius:50%; vertical-align:middle; margin-right:6px; }
        .ptk-review-green { background:#10b981; }
        .ptk-review-amber { background:#f59e0b; }
        .ptk-review-red   { background:#ef4444; }
        </style>';
    }
}
