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

// Remove any transients created by the search engine and glossary.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_ptk_search_%'
        OR option_name LIKE '_transient_timeout_ptk_search_%'"
);
delete_transient( 'ptk_glossary_terms' );

// Remove the analytics table if it exists.
$table_name = $wpdb->prefix . 'ptk_search_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Flush rewrite rules to clean up custom post type rewrites.
flush_rewrite_rules();
