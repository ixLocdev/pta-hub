<?php
/**
 * Network provisioning for PTA Knowledge Hub.
 *
 * Owns everything that must exist on every site (or once per network):
 * - The shared vendor-reviews table ({base_prefix}ptk_vendor_reviews).
 * - Per-site knowledge tables (analytics/feedback/click log) — previously
 *   only created on the activating site (audit #22).
 * - The Vendor Directory page on each site.
 * Runs for new sites via wp_initialize_site and for existing sites via a
 * version-gated admin_init routine (pattern: ptk_maybe_clear_cache_on_update).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Network_Provisioning {

    /** Option flag: this site has been provisioned for this schema version. */
    const PROVISION_VER_OPTION = 'ptk_provision_ver';

    /** Bump when provisioning requirements change. */
    const PROVISION_VER = '2'; // 2: repair_missing_slugs (v3.0.1)

    public static function init() {
        // Late priority so core finishes initializing the new site first.
        add_action( 'wp_initialize_site', array( __CLASS__, 'provision_new_site' ), 100, 1 );
        // Existing sites: provision on the next admin visit after an update.
        add_action( 'admin_init', array( __CLASS__, 'maybe_provision_current_site' ) );
    }

    /**
     * Network-wide reviews table (created once — base_prefix, not per-site).
     *
     * Note: dbDelta cannot column-diff a `CREATE TABLE IF NOT EXISTS`
     * statement (it misparses the name) — creation is idempotent (matches
     * the PTK_Feedback idiom), but future schema CHANGES need a manual
     * ALTER path, not dbDelta.
     */
    public static function create_reviews_table() {
        global $wpdb;
        $table   = $wpdb->base_prefix . 'ptk_vendor_reviews';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT UNSIGNED NOT NULL,
            blog_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            recommend TINYINT(1) NOT NULL,
            price_rating TINYINT UNSIGNED NOT NULL,
            quality_rating TINYINT UNSIGNED NOT NULL,
            comment TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_vendor (vendor_id, user_id),
            KEY idx_vendor_status (vendor_id, status),
            KEY idx_status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /** Everything one site needs. Idempotent; safe to re-run. */
    public static function provision_site() {
        // Shared reviews table. Also created at activation, but activation
        // hooks do NOT fire on a manual "Replace current with uploaded" zip
        // update — this version-gated path is what guarantees the table
        // exists on manually-updated networks. Idempotent (IF NOT EXISTS).
        self::create_reviews_table();

        // Per-site knowledge tables (audit #22 — previously activation-only).
        PTK_Analytics::create_table();
        PTK_Feedback::create_table();
        PTK_Search_Engine::create_click_table();

        // Seed vendor categories — core is_main_site() (true on single-site,
        // and false here after provision_new_site()'s switch_to_blog), NOT
        // PTK_Multisite::is_main_site(), which is false on single-site and
        // would silently skip seeding there.
        if ( is_main_site() ) {
            PTK_Vendor_Directory::ensure_default_categories();
            // One-time repair: vendors approved via wp_publish_post before
            // v3.0.1 were published without a slug, breaking their links.
            PTK_Vendor_Directory::repair_missing_slugs();
        }

        // Vendor Directory page with the shortcode.
        PTK_Vendor_Directory::ensure_directory_page();

        update_option( self::PROVISION_VER_OPTION, self::PROVISION_VER );
    }

    /** wp_initialize_site handler — runs in the network-admin request context. */
    public static function provision_new_site( $new_site ) {
        switch_to_blog( $new_site->blog_id );
        self::provision_site();
        restore_current_blog();
    }

    /** Version-gated per-site provisioning for already-existing sites. */
    public static function maybe_provision_current_site() {
        if ( get_option( self::PROVISION_VER_OPTION, '' ) !== self::PROVISION_VER ) {
            self::provision_site();
        }
    }

    /**
     * Run a callback in the main site's context.
     * On single-site (where ms-blogs functions don't exist) the current
     * site IS the main site, so the callback just runs directly.
     */
    public static function on_main_site( callable $callback ) {
        if ( ! is_multisite() ) {
            return $callback();
        }
        switch_to_blog( get_main_site_id() );
        $result = $callback();
        restore_current_blog();
        return $result;
    }

    /* Cache-version helpers (spec: Caching). Network-wide so a bump on the
       Council site invalidates every subsite's vendor transients. */

    public static function get_cache_version() {
        return (int) get_site_option( 'ptk_vendor_cache_ver', 1 );
    }

    public static function bump_cache_version() {
        update_site_option( 'ptk_vendor_cache_ver', self::get_cache_version() + 1 );
    }
}
