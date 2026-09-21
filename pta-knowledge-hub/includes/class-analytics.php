<?php
/**
 * Lightweight search analytics for PTA Knowledge Hub.
 *
 * Logs search queries to a custom DB table, surfaces top searches
 * and zero-result queries in an admin dashboard widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Analytics {

    /** @var string Table name (set in init). */
    private static $table;

    public static function init() {
        global $wpdb;
        self::$table = $wpdb->prefix . 'ptk_search_log';

        add_action( 'wp_ajax_pta_search_log', array( __CLASS__, 'log_search' ) );
        add_action( 'wp_ajax_nopriv_pta_search_log', array( __CLASS__, 'log_search' ) );
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_analytics_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_csv_export' ) );

        add_action( 'ptk_prune_logs', array( __CLASS__, 'prune_logs' ) );
        if ( ! wp_next_scheduled( 'ptk_prune_logs' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ptk_prune_logs' );
        }
    }

    /**
     * Delete search/click log rows older than 12 months. Runs daily via cron —
     * these tables accept nopriv inserts and would otherwise grow unbounded.
     */
    public static function prune_logs() {
        global $wpdb;
        $cutoff       = gmdate( 'Y-m-d H:i:s', strtotime( '-12 months' ) );
        $search_table = $wpdb->prefix . 'ptk_search_log';
        $click_table  = $wpdb->prefix . 'ptk_click_log';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$search_table} WHERE searched_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$click_table} WHERE clicked_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
    }

    /**
     * Create the analytics table on plugin activation.
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptk_search_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            query VARCHAR(255) NOT NULL,
            results_count INT UNSIGNED NOT NULL DEFAULT 0,
            ip_hash VARCHAR(64) DEFAULT '',
            searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_query (query(100)),
            KEY idx_searched_at (searched_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Drop the table on uninstall.
     */
    public static function drop_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptk_search_log';
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * AJAX handler: log a search query.
     * Called from the front-end after search results are rendered.
     */
    public static function log_search() {
        check_ajax_referer( 'ptk_search_nonce', '_wpnonce' );

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;

        if ( empty( $query ) ) {
            wp_send_json_error();
        }

        global $wpdb;

        // Hash the IP for privacy — we only need it for deduplication.
        $ip_hash = hash( 'sha256', self::get_client_ip() . wp_salt( 'auth' ) );

        $wpdb->insert(
            self::$table,
            array(
                'query'         => mb_substr( $query, 0, 255 ),
                'results_count' => $count,
                'ip_hash'       => $ip_hash,
                'searched_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%d', '%s', '%s' )
        );

        wp_send_json_success();
    }

    /**
     * Register the dashboard widget.
     */
    public static function add_dashboard_widget() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'ptk_search_analytics',
            'PTA Hub — Search Analytics',
            array( __CLASS__, 'render_dashboard_widget' )
        );
    }

    /**
     * Render the analytics widget.
     */
    public static function render_dashboard_widget() {
        global $wpdb;
        $table = self::$table;

        // Top 10 searches (last 30 days)
        $top_searches = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count, ROUND(AVG(results_count)) AS avg_results
             FROM {$table}
             WHERE searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY query
             ORDER BY search_count DESC
             LIMIT 10"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Zero-result queries (last 30 days)
        $zero_results = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count
             FROM {$table}
             WHERE results_count = 0
               AND searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY query
             ORDER BY search_count DESC
             LIMIT 10"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Total searches last 30 days
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        ?>
        <p style="font-size:13px;color:#6b7280;">Last 30 days &mdash; <strong><?php echo esc_html( number_format( (int) $total ) ); ?></strong> total searches</p>

        <style>
        .ptk-anly-row { display: flex; align-items: center; gap: 10px; }
        .ptk-anly-bar { flex: 1; height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden; min-width: 40px; }
        .ptk-anly-bar-fill { height: 100%; background: #2563eb; border-radius: 999px; }
        .ptk-anly-bar-red .ptk-anly-bar-fill { background: #ef4444; }
        .ptk-anly-num { font-variant-numeric: tabular-nums; min-width: 28px; text-align: right; color: #1f2937; font-weight: 600; }
        </style>

        <?php
        $top_max = ! empty( $top_searches ) ? max( array_map( function( $r ){ return (int) $r->search_count; }, $top_searches ) ) : 0;
        $zero_max = ! empty( $zero_results ) ? max( array_map( function( $r ){ return (int) $r->search_count; }, $zero_results ) ) : 0;
        ?>

        <?php if ( ! empty( $top_searches ) ) : ?>
        <h4 style="margin:16px 0 8px;">Top Searches</h4>
        <table class="widefat striped" style="font-size:13px;">
            <thead>
                <tr><th>Query</th><th style="width:55%;">Count</th><th style="width:80px;text-align:center;">Avg Results</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $top_searches as $row ) :
                    $pct = $top_max > 0 ? max( 6, (int) round( ( $row->search_count / $top_max ) * 100 ) ) : 0;
                ?>
                <tr>
                    <td><?php echo esc_html( $row->query ); ?></td>
                    <td>
                        <div class="ptk-anly-row">
                            <div class="ptk-anly-bar"><div class="ptk-anly-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div></div>
                            <span class="ptk-anly-num"><?php echo esc_html( $row->search_count ); ?></span>
                        </div>
                    </td>
                    <td style="text-align:center;"><?php echo esc_html( $row->avg_results ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( ! empty( $zero_results ) ) : ?>
        <h4 style="margin:16px 0 8px;color:#dc2626;">Zero-Result Queries</h4>
        <p style="font-size:12px;color:#9ca3af;margin:0 0 8px;">People searched for these but found nothing. Consider adding content!</p>
        <table class="widefat striped" style="font-size:13px;">
            <thead>
                <tr><th>Query</th><th style="width:55%;">Count</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $zero_results as $row ) :
                    $pct = $zero_max > 0 ? max( 6, (int) round( ( $row->search_count / $zero_max ) * 100 ) ) : 0;
                ?>
                <tr>
                    <td><?php echo esc_html( $row->query ); ?></td>
                    <td>
                        <div class="ptk-anly-row ptk-anly-bar-red">
                            <div class="ptk-anly-bar"><div class="ptk-anly-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div></div>
                            <span class="ptk-anly-num"><?php echo esc_html( $row->search_count ); ?></span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( empty( $top_searches ) && empty( $zero_results ) ) : ?>
        <p style="color:#9ca3af;">No search data yet. Analytics will appear once people start searching.</p>
        <?php endif; ?>
        <?php
    }

    /* ---------------------------------------------------------------
     *  Full Analytics Admin Page
     * ------------------------------------------------------------- */

    /**
     * Register the analytics admin page under PTA Knowledge menu.
     */
    public static function add_analytics_page() {
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Search Analytics',
            'Search Analytics',
            'edit_posts',
            'ptk-search-analytics',
            array( __CLASS__, 'render_analytics_page' )
        );
    }

    /**
     * Render the full analytics admin page.
     */
    public static function render_analytics_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        if ( class_exists( 'PTK_Hub_Look' ) && PTK_Hub_Look::on() ) {
            self::render_new_page();
            return;
        }

        global $wpdb;
        $table = self::$table;

        // Date range filter.
        $range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification
        $valid_ranges = array( '7', '30', '90', '365', 'all' );
        if ( ! in_array( $range, $valid_ranges, true ) ) {
            $range = '30';
        }

        $where_date = 'all' === $range
            ? ''
            : $wpdb->prepare( 'WHERE searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range );

        $range_label = array(
            '7'   => 'Last 7 days',
            '30'  => 'Last 30 days',
            '90'  => 'Last 90 days',
            '365' => 'Last year',
            'all' => 'All time',
        );

        // --- Stat cards ---
        $total_searches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_date}" ); // phpcs:ignore
        $unique_queries = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT query) FROM {$table} {$where_date}" ); // phpcs:ignore
        $zero_count     = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} " .
            ( 'all' === $range
                ? 'WHERE results_count = 0'
                : $wpdb->prepare( 'WHERE results_count = 0 AND searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range )
            )
        ); // phpcs:ignore
        $avg_results    = $wpdb->get_var( "SELECT ROUND(AVG(results_count), 1) FROM {$table} {$where_date}" ); // phpcs:ignore
        $avg_results    = $avg_results ?? '0';

        // --- Top searches ---
        $top_searches = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count, ROUND(AVG(results_count)) AS avg_results,
                    MIN(searched_at) AS first_searched, MAX(searched_at) AS last_searched
             FROM {$table}
             {$where_date}
             GROUP BY query
             ORDER BY search_count DESC
             LIMIT 25"
        ); // phpcs:ignore

        // --- Zero-result queries ---
        $zero_results = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count, MAX(searched_at) AS last_searched
             FROM {$table} " .
            ( 'all' === $range
                ? 'WHERE results_count = 0'
                : $wpdb->prepare( 'WHERE results_count = 0 AND searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range )
            ) .
            " GROUP BY query
              ORDER BY search_count DESC
              LIMIT 25"
        ); // phpcs:ignore

        // --- Daily search volume (for chart) ---
        $chart_days  = 'all' === $range ? 90 : min( (int) $range, 90 );
        $daily_data  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(searched_at) AS search_date, COUNT(*) AS search_count
                 FROM {$table}
                 WHERE searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(searched_at)
                 ORDER BY search_date ASC",
                $chart_days
            )
        ); // phpcs:ignore

        // --- Recently searched (last 20 individual searches) ---
        $recent_searches = $wpdb->get_results(
            "SELECT query, results_count, searched_at
             FROM {$table}
             ORDER BY searched_at DESC
             LIMIT 20"
        ); // phpcs:ignore

        // Build chart data arrays.
        $chart_labels = array();
        $chart_values = array();
        if ( $daily_data ) {
            foreach ( $daily_data as $day ) {
                $chart_labels[] = gmdate( 'M j', strtotime( $day->search_date ) );
                $chart_values[] = (int) $day->search_count;
            }
        }

        // New entry URL base.
        $wizard_url = PTK_Content_Wizard::url();

        // Enqueue admin styles.
        wp_enqueue_style( 'ptk-analytics-admin', PTK_PLUGIN_URL . 'assets/css/analytics-admin.css', array(), PTK_VERSION );

        ?>
        <div class="wrap ptk-analytics-wrap">
            <h1 class="wp-heading-inline">Search Analytics</h1>
            <p class="ptk-analytics-subtitle">See what your members are searching for so you can create the content they need.</p>

            <!-- Date range filter -->
            <div class="ptk-analytics-filters">
                <?php
                $base_url = admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-search-analytics' );
                foreach ( $range_label as $val => $label ) :
                    $active = ( $val === $range ) ? ' class="active"' : '';
                    ?>
                    <a href="<?php echo esc_url( add_query_arg( 'range', $val, $base_url ) ); ?>"<?php echo $active; ?>><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>

                <a href="<?php echo esc_url( add_query_arg( array( 'range' => $range, 'ptk_export_csv' => '1', '_wpnonce' => wp_create_nonce( 'ptk_export_csv' ) ), $base_url ) ); ?>" class="ptk-export-btn" title="Download CSV">
                    <span class="dashicons dashicons-download"></span> Export CSV
                </a>
            </div>

            <!-- Stat cards -->
            <div class="ptk-stat-cards">
                <div class="ptk-stat-card">
                    <div class="ptk-stat-number"><?php echo esc_html( number_format( $total_searches ) ); ?></div>
                    <div class="ptk-stat-label">Total Searches</div>
                </div>
                <div class="ptk-stat-card">
                    <div class="ptk-stat-number"><?php echo esc_html( number_format( $unique_queries ) ); ?></div>
                    <div class="ptk-stat-label">Unique Queries</div>
                </div>
                <div class="ptk-stat-card">
                    <div class="ptk-stat-number"><?php echo esc_html( $avg_results ); ?></div>
                    <div class="ptk-stat-label">Avg. Results per Search</div>
                </div>
                <div class="ptk-stat-card ptk-stat-card-alert">
                    <div class="ptk-stat-number"><?php echo esc_html( number_format( $zero_count ) ); ?></div>
                    <div class="ptk-stat-label">Searches with No Results</div>
                </div>
            </div>

            <!-- Search volume chart -->
            <?php if ( ! empty( $chart_labels ) ) : ?>
            <div class="ptk-analytics-section">
                <h2>Search Volume</h2>
                <div class="ptk-chart-container">
                    <canvas id="ptk-search-chart" height="260"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Two-column: Top searches + Zero-result queries -->
            <div class="ptk-analytics-columns">
                <!-- Top searches -->
                <div class="ptk-analytics-section">
                    <h2>Top Searches <span class="ptk-section-badge"><?php echo esc_html( $range_label[ $range ] ); ?></span></h2>
                    <?php if ( ! empty( $top_searches ) ) : ?>
                    <table class="widefat striped ptk-analytics-table">
                        <thead>
                            <tr>
                                <th>Search Term</th>
                                <th class="ptk-col-center">Times Searched</th>
                                <th class="ptk-col-center">Avg. Results</th>
                                <th>Last Searched</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $top_searches as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->query ); ?></strong></td>
                                <td class="ptk-col-center"><?php echo esc_html( $row->search_count ); ?></td>
                                <td class="ptk-col-center">
                                    <?php if ( (int) $row->avg_results === 0 ) : ?>
                                        <span class="ptk-badge-zero">0</span>
                                    <?php else : ?>
                                        <?php echo esc_html( $row->avg_results ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( human_time_diff( strtotime( $row->last_searched ), time() ) . ' ago' ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else : ?>
                        <p class="ptk-no-data">No search data yet. Analytics will appear once people start using the search bar.</p>
                    <?php endif; ?>
                </div>

                <!-- Zero-result queries -->
                <div class="ptk-analytics-section">
                    <h2>Content Gaps <span class="ptk-section-badge ptk-badge-alert">Needs Attention</span></h2>
                    <p class="ptk-section-description">People searched for these terms but found nothing. Consider creating content to fill the gap.</p>
                    <?php if ( ! empty( $zero_results ) ) : ?>
                    <table class="widefat striped ptk-analytics-table">
                        <thead>
                            <tr>
                                <th>Search Term</th>
                                <th class="ptk-col-center">Times Searched</th>
                                <th>Last Searched</th>
                                <th class="ptk-col-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $zero_results as $row ) :
                                $create_url = add_query_arg( 'ptk_prefill_title', urlencode( $row->query ), $wizard_url );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->query ); ?></strong></td>
                                <td class="ptk-col-center"><?php echo esc_html( $row->search_count ); ?></td>
                                <td><?php echo esc_html( human_time_diff( strtotime( $row->last_searched ), time() ) . ' ago' ); ?></td>
                                <td class="ptk-col-center">
                                    <a href="<?php echo esc_url( $create_url ); ?>" class="button button-small ptk-create-entry-btn" title="Create an entry about this topic">
                                        + Create Entry
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else : ?>
                        <p class="ptk-no-data ptk-no-data-good">All searches returned results — nice work keeping your PTA Hub complete!</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent searches stream -->
            <div class="ptk-analytics-section">
                <h2>Recent Searches</h2>
                <p class="ptk-section-description">The 20 most recent individual searches, in real time.</p>
                <?php if ( ! empty( $recent_searches ) ) : ?>
                <table class="widefat striped ptk-analytics-table">
                    <thead>
                        <tr>
                            <th>Search Term</th>
                            <th class="ptk-col-center">Results Found</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent_searches as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row->query ); ?></td>
                            <td class="ptk-col-center">
                                <?php if ( (int) $row->results_count === 0 ) : ?>
                                    <span class="ptk-badge-zero">0</span>
                                <?php else : ?>
                                    <?php echo esc_html( $row->results_count ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( human_time_diff( strtotime( $row->searched_at ), time() ) . ' ago' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                    <p class="ptk-no-data">No searches recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( ! empty( $chart_labels ) ) : ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ctx = document.getElementById('ptk-search-chart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo wp_json_encode( $chart_labels ); ?>,
                    datasets: [{
                        label: 'Searches',
                        data: <?php echo wp_json_encode( $chart_values ); ?>,
                        backgroundColor: 'rgba(79, 70, 229, 0.6)',
                        borderColor: 'rgba(79, 70, 229, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    }
                }
            });
        });
        </script>
        <?php endif; ?>
        <?php
    }

    /* ---------------------------------------------------------------
     *  New look: "What families are looking for"
     * ------------------------------------------------------------- */

    /**
     * The new look. Same table, same columns, same prepared queries as the
     * dashboard above -- only the shape of the screen changes. Gaps (what
     * turned up nothing) lead, because that's the thing a volunteer can
     * act on; what people did find sits underneath, quieter; the four
     * counts, the day-by-day chart and the recent-searches stream all fold
     * away closed, reachable but not the first thing anyone sees.
     *
     * Chart.js decision: dropped. The old screen loaded a Chart.js build
     * from a CDN -- an external request from a school's own admin screen
     * for what is, underneath, a simple set of daily counts. A plain row
     * of inline bars (var(--ptk-primary) fill, no new CSS) reads just as
     * well folded away and adds no dependency.
     */
    public static function render_new_page() {
        global $wpdb;
        $table = self::$table;

        $range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification
        $valid_ranges = array( '7', '30', '90', '365', 'all' );
        if ( ! in_array( $range, $valid_ranges, true ) ) {
            $range = '30';
        }

        $where_date = 'all' === $range
            ? ''
            : $wpdb->prepare( 'WHERE searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range );
        $zero_where = 'all' === $range
            ? 'WHERE results_count = 0'
            : $wpdb->prepare( 'WHERE results_count = 0 AND searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range );
        $found_where = 'all' === $range
            ? 'WHERE results_count > 0'
            : $wpdb->prepare( 'WHERE results_count > 0 AND searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range );

        // --- the gaps: what turned up nothing ---
        $gaps = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count, MAX(searched_at) AS last_searched
             FROM {$table} {$zero_where}
             GROUP BY query
             ORDER BY search_count DESC
             LIMIT 25"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // --- what they did find ---
        $found = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count
             FROM {$table} {$found_where}
             GROUP BY query
             ORDER BY search_count DESC
             LIMIT 15"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // --- the folded stat tiles ---
        $total_searches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_date}" ); // phpcs:ignore
        $unique_queries = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT query) FROM {$table} {$where_date}" ); // phpcs:ignore
        $zero_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$zero_where}" ); // phpcs:ignore
        $avg_results    = $wpdb->get_var( "SELECT ROUND(AVG(results_count), 1) FROM {$table} {$where_date}" ); // phpcs:ignore
        $avg_results    = $avg_results ?? '0';

        // --- the folded chart: daily volume, as plain bars ---
        $chart_days = 'all' === $range ? 90 : min( (int) $range, 90 );
        $daily_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(searched_at) AS search_date, COUNT(*) AS search_count
                 FROM {$table}
                 WHERE searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(searched_at)
                 ORDER BY search_date ASC",
                $chart_days
            )
        ); // phpcs:ignore

        // --- the folded recent-searches stream ---
        $recent_searches = $wpdb->get_results(
            "SELECT query, results_count, searched_at
             FROM {$table}
             ORDER BY searched_at DESC
             LIMIT 20"
        ); // phpcs:ignore

        $wizard_url = PTK_Content_Wizard::url();
        $base_url   = admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-search-analytics' );
        $now        = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( PTK_Looking_For_Copy::title(), PTK_Looking_For_Copy::lead() );

        // 1. The gaps, first.
        if ( ! empty( $gaps ) ) {
            echo PTK_Hub_UI::waiting_row( PTK_Looking_For_Copy::gaps_waiting_sentence( count( $gaps ) ) );
            echo '<div class="ptk-approvals">';
            foreach ( $gaps as $row ) {
                echo self::render_gap_card( $row, $wizard_url, $now );
            }
            echo '</div>';
        } else {
            $empty = PTK_Looking_For_Copy::gaps_empty();
            echo PTK_Hub_UI::empty_state( $empty['title'], $empty['text'] );
        }

        // 2. What they did find, underneath, quieter.
        if ( ! empty( $found ) ) {
            echo '<h2 style="margin-top:28px;">' . esc_html( PTK_Looking_For_Copy::found_heading() ) . '</h2>';
            echo '<div class="ptk-cards">';
            foreach ( $found as $row ) {
                echo PTK_Hub_UI::card( array(
                    'title' => $row->query,
                    'meta'  => PTK_Looking_For_Copy::found_meta( (int) $row->search_count ),
                ) );
            }
            echo '</div>';
        }

        // 3. The numbers, folded away, closed by default.
        echo PTK_Hub_UI::section_fold( array(
            'title'   => PTK_Looking_For_Copy::numbers_heading(),
            'summary' => PTK_Looking_For_Copy::numbers_summary( $total_searches, $range ),
            'body'    => self::render_numbers_fold_body( $range, $base_url, $total_searches, $unique_queries, $avg_results, $zero_count, $daily_data ),
        ) );

        // 4. Recent searches, quietly, in their own closed fold.
        echo PTK_Hub_UI::section_fold( array(
            'title'   => PTK_Looking_For_Copy::recent_heading(),
            'summary' => PTK_Looking_For_Copy::recent_summary( count( $recent_searches ) ),
            'body'    => self::render_recent_fold_body( $recent_searches, $now ),
        ) );

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    /** One gap card: the term, how many looked and when, and the way to write it. */
    private static function render_gap_card( $row, $wizard_url, $now ) {
        $count      = (int) $row->search_count;
        $when       = PTK_Looking_For_Copy::when_word( $now, strtotime( $row->last_searched ) );
        $create_url = add_query_arg( 'ptk_prefill_title', urlencode( $row->query ), $wizard_url );

        $out  = '<article class="ptk-approval">';
        $out .= '<h2 class="ptk-approval-name">' . PTK_Hub_UI::no_widow( $row->query ) . '</h2>';
        $out .= '<p class="ptk-approval-meta">' . esc_html( PTK_Looking_For_Copy::looked_sentence( $count, $when ) ) . '</p>';
        $out .= '<div class="ptk-approval-actions">';
        $out .= '<a class="ptk-btn ptk-btn-primary" href="' . esc_url( $create_url ) . '">' . esc_html( PTK_Looking_For_Copy::write_answer_button_label() ) . '</a>';
        $out .= '</div>';
        $out .= '</article>';
        return $out;
    }

    /** The body of the folded "numbers" section: range words, four tiles, the plain bar chart, the export button. */
    private static function render_numbers_fold_body( $range, $base_url, $total_searches, $unique_queries, $avg_results, $zero_count, $daily_data ) {
        $out = '';

        // The range, as words -- the same pill markup "What you've written" uses for its own filters.
        $out .= '<div class="ptk-written-filters" role="group" aria-label="Choose a time range">';
        foreach ( array( '7', '30', '90', '365', 'all' ) as $key ) {
            $active = ( $key === $range );
            $url    = add_query_arg( 'range', $key, $base_url );
            $out   .= '<a href="' . esc_url( $url ) . '" class="ptk-written-filter' . ( $active ? ' ptk-written-filter--active' : '' ) . '"' . ( $active ? ' aria-current="true"' : '' ) . '>' . esc_html( PTK_Looking_For_Copy::range_label( $key ) ) . '</a>';
        }
        $out .= '</div>';

        // Four stat tiles.
        $out .= '<div class="ptk-cards" style="margin-top:16px;">';
        $out .= PTK_Hub_UI::card( array( 'title' => number_format( $total_searches ), 'meta' => PTK_Looking_For_Copy::stat_total_label() ) );
        $out .= PTK_Hub_UI::card( array( 'title' => number_format( $unique_queries ), 'meta' => PTK_Looking_For_Copy::stat_different_label() ) );
        $out .= PTK_Hub_UI::card( array( 'title' => (string) $avg_results, 'meta' => PTK_Looking_For_Copy::stat_typical_label() ) );
        $out .= PTK_Hub_UI::card( array( 'title' => number_format( $zero_count ), 'meta' => PTK_Looking_For_Copy::stat_nothing_label() ) );
        $out .= '</div>';

        // Plain inline bars -- no external chart library, no CDN request from a school's admin.
        if ( ! empty( $daily_data ) ) {
            $max = 1;
            foreach ( $daily_data as $day ) {
                $max = max( $max, (int) $day->search_count );
            }

            // Everything below is styled by .ptk-days* in hub.css -- the
            // only value that has to be inline is the bar's own width,
            // which is the datum itself.
            $out .= '<h3 class="ptk-days-heading">' . esc_html( PTK_Looking_For_Copy::chart_heading() ) . '</h3>';
            $out .= '<div class="ptk-days">';
            foreach ( $daily_data as $day ) {
                $count = (int) $day->search_count;
                $pct   = max( 4, (int) round( ( $count / $max ) * 100 ) );
                $label = gmdate( 'M j', strtotime( $day->search_date ) );
                $out  .= '<div class="ptk-days-row">';
                $out  .= '<span class="ptk-days-label">' . esc_html( $label ) . '</span>';
                $out  .= '<span class="ptk-days-track">';
                $out  .= '<span class="ptk-days-bar" style="width:' . esc_attr( $pct ) . '%;"></span>';
                $out  .= '</span>';
                $out  .= '<span class="ptk-days-count">' . esc_html( $count ) . '</span>';
                $out  .= '</div>';
            }
            $out .= '</div>';
        }

        // The export button -- same admin_init handler, same nonce action, unchanged.
        $export_url = add_query_arg( array(
            'range'          => $range,
            'ptk_export_csv' => '1',
            '_wpnonce'       => wp_create_nonce( 'ptk_export_csv' ),
        ), $base_url );
        $out .= '<p class="ptk-days-export">' . PTK_Hub_UI::button( PTK_Looking_For_Copy::export_button_label(), $export_url ) . '</p>';

        return $out;
    }

    /** The body of the folded "recent searches" section. */
    private static function render_recent_fold_body( $recent_searches, $now ) {
        if ( empty( $recent_searches ) ) {
            return '<p class="ptk-help">Nothing yet.</p>';
        }
        $out = '<div class="ptk-cards">';
        foreach ( $recent_searches as $row ) {
            $when = PTK_Looking_For_Copy::when_word( $now, strtotime( $row->searched_at ) );
            $meta = PTK_Looking_For_Copy::recent_result_meta( (int) $row->results_count ) . ' · ' . $when;
            $out .= PTK_Hub_UI::card( array(
                'title' => $row->query,
                'meta'  => $meta,
            ) );
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * Handle CSV export of search data.
     */
    public static function handle_csv_export() {
        if ( empty( $_GET['ptk_export_csv'] ) || empty( $_GET['page'] ) || 'ptk-search-analytics' !== $_GET['page'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ptk_export_csv' ) ) {
            wp_die( 'Unauthorized.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ptk_search_log';

        $range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '30';
        $valid_ranges = array( '7', '30', '90', '365', 'all' );
        if ( ! in_array( $range, $valid_ranges, true ) ) {
            $range = '30';
        }

        $where_date = 'all' === $range
            ? ''
            : $wpdb->prepare( 'WHERE searched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $range );

        $rows = $wpdb->get_results(
            "SELECT query, COUNT(*) AS search_count, ROUND(AVG(results_count)) AS avg_results,
                    MIN(searched_at) AS first_searched, MAX(searched_at) AS last_searched
             FROM {$table}
             {$where_date}
             GROUP BY query
             ORDER BY search_count DESC",
            ARRAY_A
        ); // phpcs:ignore

        $filename = 'pta-search-analytics-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'Search Term', 'Times Searched', 'Avg Results', 'First Searched', 'Last Searched' ) );

        foreach ( $rows as $row ) {
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Get client IP with proxy support.
     */
    private static function get_client_ip() {
        // Use REMOTE_ADDR only — X-Forwarded-For and similar headers can be
        // spoofed by any client and are not trustworthy without a reverse-proxy
        // configuration that strips/overwrites them.
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}
