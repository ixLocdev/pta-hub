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
    const PROVISION_VER = '4'; // 2: repair_missing_slugs (v3.0.1); 3: ensure KB+Glossary pages on subsites (v3.1.1); 4: v4.0 audience migration (one-time flag, below)

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

            // v4.0: normalize legacy ptk_share_network booleans into the new
            // audience model. Guarded by its OWN one-time flag (NOT the
            // provision-version gate) because migrate_audience_meta() also
            // force-enables ptk_enable_network_sharing — a bare version gate
            // would re-run it on any future PROVISION_VER bump and silently
            // re-enable sharing an admin had deliberately turned off.
            if ( ! get_option( 'ptk_v4_audience_migrated' ) ) {
                PTK_Multisite::migrate_audience_meta();
                update_option( 'ptk_v4_audience_migrated', 1 );
            }
        }

        // The three PTA Hub front-end pages. The Knowledge Base + Glossary
        // pages were previously created ONLY by the activation hook (main
        // site only), so subsites 404'd on /knowledge-base and /glossary
        // (and the Welcome screen's "See the live Hub" / "Open glossary"
        // buttons). Create all three here so every site has them.
        self::ensure_content_pages();
        PTK_Vendor_Directory::ensure_directory_page();

        update_option( self::PROVISION_VER_OPTION, self::PROVISION_VER );
    }

    /**
     * Create this site's Knowledge Base + Glossary pages if missing, at the
     * slugs ptk_hub_url()/ptk_glossary_url() resolve to (so the links work).
     * Idempotent — skips any page that already exists.
     */
    public static function ensure_content_pages() {
        $pages = array(
            ltrim( (string) get_option( 'ptk_hub_slug', 'knowledge-base' ), '/' ) => array(
                'title'   => 'Knowledge Base',
                'content' => '<!-- wp:shortcode -->[pta_search]<!-- /wp:shortcode -->',
            ),
            ltrim( (string) get_option( 'ptk_glossary_slug', 'glossary' ), '/' ) => array(
                'title'   => 'Glossary',
                'content' => '<!-- wp:shortcode -->[pta_glossary]<!-- /wp:shortcode -->',
            ),
        );
        foreach ( $pages as $slug => $page ) {
            if ( '' === $slug || get_page_by_path( $slug ) ) {
                continue;
            }
            wp_insert_post( array(
                'post_title'   => $page['title'],
                'post_name'    => $slug,
                'post_content' => $page['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ) );
        }
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
