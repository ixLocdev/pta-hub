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

// Remove plugin options.
delete_option( 'ptk_require_login' );
delete_option( 'ptk_starter_content_imported' );
delete_option( 'ptk_show_importer' );
delete_option( 'ptk_enable_network_sharing' );
delete_option( 'ptk_provision_ver' );

// Remove network-wide options (vendor directory cache version).
delete_site_option( 'ptk_vendor_cache_ver' );

// Remove any transients created by the search engine and glossary.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_ptk_search_%'
        OR option_name LIKE '_transient_timeout_ptk_search_%'
        OR option_name LIKE '_transient_ptk_subsites%'
        OR option_name LIKE '_transient_timeout_ptk_subsites%'
        OR option_name LIKE '_transient_ptk_vendors%'
        OR option_name LIKE '_transient_timeout_ptk_vendors%'
        OR option_name LIKE '_transient_ptk_vendor_%'
        OR option_name LIKE '_transient_timeout_ptk_vendor_%'"
);
delete_transient( 'ptk_glossary_terms' );

// Remove vendor rate-limit counters — these are SITE transients, stored in
// sitemeta on multisite (wp_options on single-site), so the query above
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

// Remove the analytics table if it exists.
$table_name = $wpdb->prefix . 'ptk_search_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove the feedback table if it exists.
$feedback_table = $wpdb->prefix . 'ptk_feedback';
$wpdb->query( "DROP TABLE IF EXISTS {$feedback_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove the network-wide vendor reviews table if it exists.
// Vendor posts themselves are preserved, like knowledge posts, so data is
// recoverable if the plugin is reinstalled.
$vendor_reviews_table = $wpdb->base_prefix . 'ptk_vendor_reviews';
$wpdb->query( "DROP TABLE IF EXISTS {$vendor_reviews_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clean up post meta added by new features.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('ptk_share_network', 'ptk_network_source', 'ptk_network_source_blog', 'ptk_suggested_from_blog', 'ptk_suggested_from_post', 'ptk_visible_roles')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clean up user meta for notifications.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'ptk_last_kb_visit'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Flush rewrite rules to clean up custom post type rewrites.
flush_rewrite_rules();
