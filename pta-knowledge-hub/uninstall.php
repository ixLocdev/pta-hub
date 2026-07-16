<?php
/**
 * PTA Knowledge Hub — Uninstall
 *
 * Cleans up plugin options and transients on deletion.
 * Posts are preserved so data is recoverable if the plugin is reinstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

/**
 * Per-site cleanup: options, per-site tables, transients, postmeta, usermeta.
 *
 * On multisite this runs once per site inside a switch_to_blog() context, so
 * $wpdb->prefix / options / postmeta / usermeta all resolve to the current
 * site. CPT posts are intentionally preserved (recoverable on reinstall).
 */
$ptk_cleanup_site = function () {
    global $wpdb;

    // Remove per-site plugin options.
    delete_option( 'ptk_require_login' );
    delete_option( 'ptk_starter_content_imported' );
    delete_option( 'ptk_show_importer' );
    delete_option( 'ptk_enable_network_sharing' );
    delete_option( 'ptk_provision_ver' );
    delete_option( 'ptk_v4_audience_migrated' );
    delete_option( 'ptk_vendor_notify_emails' );
    delete_option( 'ptk_installed_version' );
    delete_option( 'ptk_hub_slug' );
    delete_option( 'ptk_glossary_slug' );
    delete_option( 'ptk_rewrite_flushed' );
    delete_option( 'ptk_rewrite_ver' );

    // Remove per-site tables if they exist.
    $search_table   = $wpdb->prefix . 'ptk_search_log';
    $feedback_table = $wpdb->prefix . 'ptk_feedback';
    $click_table    = $wpdb->prefix . 'ptk_click_log';
    $wpdb->query( "DROP TABLE IF EXISTS {$search_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$feedback_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$click_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    // Remove transients created by the search engine, glossary, autocomplete,
    // popularity map, and the updater manifest.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_ptk_search_%'
            OR option_name LIKE '_transient_timeout_ptk_search_%'
            OR option_name LIKE '_transient_ptk_subsites%'
            OR option_name LIKE '_transient_timeout_ptk_subsites%'
            OR option_name LIKE '_transient_ptk_vendors%'
            OR option_name LIKE '_transient_timeout_ptk_vendors%'
            OR option_name LIKE '_transient_ptk_vendor_%'
            OR option_name LIKE '_transient_timeout_ptk_vendor_%'
            OR option_name LIKE '_transient_ptk_ac_%'
            OR option_name LIKE '_transient_timeout_ptk_ac_%'
            OR option_name LIKE '_transient_ptk_popularity%'
            OR option_name LIKE '_transient_timeout_ptk_popularity%'
            OR option_name LIKE '_transient_ptk_update%'
            OR option_name LIKE '_transient_timeout_ptk_update%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    delete_transient( 'ptk_glossary_terms' );

    // Clean up post meta added by plugin features (posts themselves preserved).
    //
    // Newsletters (pta_newsletter) follow the same policy as knowledge and vendor
    // posts: the posts AND their structured content meta (ptk_nl_blocks / ptk_nl_issue
    // / ptk_nl_date / ptk_nl_theme) are intentionally preserved so a newsletter stays
    // viewable and re-editable if the plugin is reinstalled. Their share-a-preview
    // tokens ARE cleared here via the shared ptk_preview_token / ptk_preview_expires
    // keys below (the same keys knowledge entries use).
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('ptk_share_network', 'ptk_network_source', 'ptk_network_source_blog', 'ptk_suggested_from_blog', 'ptk_suggested_from_post', 'ptk_visible_roles', 'ptk_audience_mode', 'ptk_share_sites', 'ptk_preview_token', 'ptk_preview_expires')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    // Clean up user meta for notifications.
    $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'ptk_last_kb_visit'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    // Flush rewrite rules to clean up custom post type rewrites.
    flush_rewrite_rules();
};

if ( is_multisite() ) {
    $ptk_site_ids = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
    foreach ( $ptk_site_ids as $ptk_site_id ) {
        switch_to_blog( $ptk_site_id );
        $ptk_cleanup_site();
        restore_current_blog();
    }
} else {
    $ptk_cleanup_site();
}

// ---------------------------------------------------------------------------
// Network-wide cleanup — runs ONCE, not per site.
// ---------------------------------------------------------------------------

// Remove vendor rate-limit counters — these are SITE transients, stored in
// sitemeta on multisite (wp_options on single-site), so the per-site query
// never matches them.
if ( is_multisite() ) {
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta}
         WHERE meta_key LIKE '_site_transient_ptk_vendor_rate_%'
            OR meta_key LIKE '_site_transient_timeout_ptk_vendor_rate_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
} else {
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_site_transient_ptk_vendor_rate_%'
            OR option_name LIKE '_site_transient_timeout_ptk_vendor_rate_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Remove network-wide options (vendor directory cache version + school colors).
delete_site_option( 'ptk_vendor_cache_ver' );
delete_site_option( 'ptk_site_colors' );

// Remove the network-wide vendor reviews table if it exists.
// Vendor posts themselves are preserved, like knowledge posts, so data is
// recoverable if the plugin is reinstalled.
$vendor_reviews_table = $wpdb->base_prefix . 'ptk_vendor_reviews';
$wpdb->query( "DROP TABLE IF EXISTS {$vendor_reviews_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
