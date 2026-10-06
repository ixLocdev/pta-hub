<?php
/**
 * Plugin Name: PTA Knowledge Hub
 * Plugin URI:  https://github.com/your-pta/knowledge-hub
 * Description: A searchable knowledge base for your PTA. Volunteers add content through WordPress, parents and members find answers instantly via a smart search bar.
 * Version:     4.33.0
 * Author:      Lucas Deichl
 * License:     GPL-2.0-or-later
 * Text Domain: pta-knowledge-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PTK_VERSION', '4.33.0' );
define( 'PTK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PTK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'ptk_hub_url' ) ) {
    /**
     * Resolve the public PTA Hub URL.
     *
     * Defaults to /knowledge-base on the current site. Customizable via
     * the `ptk_hub_slug` option or the `ptk_hub_url` filter.
     */
    function ptk_hub_url() {
        return apply_filters(
            'ptk_hub_url',
            home_url( '/' . ltrim( get_option( 'ptk_hub_slug', 'knowledge-base' ), '/' ) )
        );
    }
}

if ( ! function_exists( 'ptk_glossary_url' ) ) {
    /**
     * Resolve the public Glossary URL.
     *
     * Defaults to /glossary on the current site (the slug the activation
     * routine creates). Customizable via the `ptk_glossary_slug` option or
     * the `ptk_glossary_url` filter — mirrors ptk_hub_url().
     */
    function ptk_glossary_url() {
        return apply_filters(
            'ptk_glossary_url',
            home_url( '/' . ltrim( get_option( 'ptk_glossary_slug', 'glossary' ), '/' ) )
        );
    }
}

/**
 * Load plugin classes.
 */
require_once PTK_PLUGIN_DIR . 'includes/class-focal-point.php';
require_once PTK_PLUGIN_DIR . 'includes/class-hub-look.php';
require_once PTK_PLUGIN_DIR . 'includes/class-hub-ui.php';
require_once PTK_PLUGIN_DIR . 'includes/class-picture-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-picture-picker.php';
require_once PTK_PLUGIN_DIR . 'includes/class-hub-router.php';
require_once PTK_PLUGIN_DIR . 'includes/class-approvals-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-simple-mode.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-type.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-post-type.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-builder.php';
require_once PTK_PLUGIN_DIR . 'includes/class-example-newsletter.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-text.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-data.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-color.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-image.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-panel.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-page.php';
require_once PTK_PLUGIN_DIR . 'includes/class-share-settings.php';
require_once PTK_PLUGIN_DIR . 'includes/class-calendar-source.php';
require_once PTK_PLUGIN_DIR . 'includes/class-ics-reader.php';
require_once PTK_PLUGIN_DIR . 'includes/class-ics-events-ajax.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-importer.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-import-ajax.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-seo.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-news-listing.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-linked-post.php';
require_once PTK_PLUGIN_DIR . 'includes/class-search-engine.php';
require_once PTK_PLUGIN_DIR . 'includes/class-single-enhancements.php';
require_once PTK_PLUGIN_DIR . 'includes/class-qr-codes.php';
require_once PTK_PLUGIN_DIR . 'includes/class-review-reminders.php';
require_once PTK_PLUGIN_DIR . 'includes/class-public-preview.php';
require_once PTK_PLUGIN_DIR . 'includes/class-suggestions.php';
require_once PTK_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once PTK_PLUGIN_DIR . 'includes/class-admin-helpers.php';
require_once PTK_PLUGIN_DIR . 'includes/class-block-patterns.php';
require_once PTK_PLUGIN_DIR . 'includes/class-meta-fields.php';
require_once PTK_PLUGIN_DIR . 'includes/class-analytics.php';
require_once PTK_PLUGIN_DIR . 'includes/class-content-wizard.php';
require_once PTK_PLUGIN_DIR . 'includes/class-written-list.php';
require_once PTK_PLUGIN_DIR . 'includes/class-asked-for-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-asked-for-list.php';
require_once PTK_PLUGIN_DIR . 'includes/class-looking-for-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-words-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-words-list.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-parts.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-renderer.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-writer.php';
require_once PTK_PLUGIN_DIR . 'includes/class-posts-list.php';
require_once PTK_PLUGIN_DIR . 'includes/class-post-banner.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletters-copy.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletters-list.php';
require_once PTK_PLUGIN_DIR . 'includes/class-glossary-tooltips.php';
require_once PTK_PLUGIN_DIR . 'includes/class-glossary-page.php';
require_once PTK_PLUGIN_DIR . 'includes/class-content-importer.php';
require_once PTK_PLUGIN_DIR . 'includes/class-feedback.php';
require_once PTK_PLUGIN_DIR . 'includes/class-notifications.php';
require_once PTK_PLUGIN_DIR . 'includes/class-role-access.php';
require_once PTK_PLUGIN_DIR . 'includes/class-multisite.php';
require_once PTK_PLUGIN_DIR . 'includes/class-content-lock.php';
require_once PTK_PLUGIN_DIR . 'includes/class-site-colors.php';
require_once PTK_PLUGIN_DIR . 'includes/class-auto-updater.php';
require_once PTK_PLUGIN_DIR . 'includes/class-network-provisioning.php';
require_once PTK_PLUGIN_DIR . 'includes/class-vendor-directory.php';
require_once PTK_PLUGIN_DIR . 'includes/class-vendor-reviews.php';
require_once PTK_PLUGIN_DIR . 'includes/class-vendor-moderation.php';
require_once PTK_PLUGIN_DIR . 'includes/class-welcome.php';

/**
 * Check whether the current visitor must log in to access the knowledge base.
 *
 * Returns true if access is allowed. When access is denied, if $render_message
 * is true it outputs a friendly login prompt (for template/shortcode use).
 *
 * @param bool $render_message Whether to output the "please log in" block.
 * @return bool True = access granted.
 */
function ptk_check_access( $render_message = false ) {
    // If the setting is off (default), everyone can see the content.
    if ( ! get_option( 'ptk_require_login', false ) ) {
        return true;
    }

    // Logged-in users always have access.
    if ( is_user_logged_in() ) {
        return true;
    }

    // Access denied — optionally render a message.
    if ( $render_message ) {
        echo ptk_members_only_markup( 'The PTA Hub is for PTA members and volunteers. Sign in to see it.' ); // Escaped inside.
    }

    return false;
}

/**
 * The "members only" card every public Hub page shows to someone signed out.
 *
 * Its styles are inline on purpose: this prints before -- or instead of --
 * the page's own stylesheet, so it cannot lean on public.css. The values are
 * the same tokens, written out.
 *
 * @param string $why One plain sentence saying what is behind the sign-in.
 * @return string
 */
function ptk_members_only_markup( $why ) {
    $login_url = wp_login_url( get_permalink() );
    $css = '.ptk-login-required{box-sizing:border-box;text-align:center;max-width:460px;margin:56px auto;padding:36px 28px;background:#FFFFFF;border:1px solid #E2E6E4;border-radius:12px;font-family:Karla,system-ui,-apple-system,sans-serif;color:#243039}'
        . '.ptk-login-required .ptk-login-icon{width:30px;height:30px;color:#356F8A;margin:0 auto 12px;display:block}'
        . '.ptk-login-required h2{font:600 22px/1.3 Literata,Georgia,serif;color:#243039;margin:0 0 8px}'
        . '.ptk-login-required p{font-size:15px;line-height:1.55;color:#68747C;margin:0 auto 22px;max-width:40ch}'
        . '.ptk-login-required .ptk-login-btn{display:inline-block;background:#356F8A;color:#FFFFFF!important;text-decoration:none;padding:12px 22px;border-radius:9px;font:600 14.5px/1 Karla,system-ui,sans-serif}'
        . '.ptk-login-required .ptk-login-btn:hover{background:#2C5E75}'
        . '.ptk-login-required .ptk-login-btn:focus-visible{outline:2px solid #356F8A;outline-offset:2px}';

    return '<style>' . $css . '</style>'
        . '<div class="ptk-login-required">'
        . '<svg class="ptk-login-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
        . '<h2>For PTA members</h2>'
        . '<p>' . esc_html( $why ) . '</p>'
        . '<a href="' . esc_url( $login_url ) . '" class="ptk-login-btn">Sign in</a>'
        . '</div>';
}

/**
 * Initialize everything on plugins_loaded.
 */
function ptk_init() {
    PTK_Post_Type::init();
    PTK_Newsletter_Post_Type::init();
    PTK_Newsletter_Builder::init();
    PTK_Example_Newsletter::init();
    PTK_Share_Image::init();
    PTK_Share_Panel::init();
    PTK_Share_Page::init();
    PTK_Share_Settings::init();
    PTK_Ics_Events_Ajax::init();
    PTK_Post_Import_Ajax::init();
    PTK_Newsletter_SEO::init();
    PTK_Newsletter_News_Listing::init();
    PTK_Newsletter_Linked_Post::init();
    PTK_Search_Engine::init();
    PTK_Single_Enhancements::init();
    PTK_QR_Codes::init();
    PTK_Review_Reminders::init();
    PTK_Public_Preview::init();
    PTK_Suggestions::init();
    PTK_Shortcode::init();
    PTK_Admin_Helpers::init();
    PTK_Block_Patterns::init();
    PTK_Meta_Fields::init();
    PTK_Analytics::init();
    PTK_Content_Wizard::init();
    PTK_Written_List::init();
    PTK_Asked_For_List::init();
    PTK_Words_List::init();
    PTK_Newsletters_List::init();
    PTK_Post_Writer::init();
    PTK_Posts_List::init();
    PTK_Post_Banner::init();
    PTK_Glossary_Tooltips::init();
    PTK_Glossary_Page::init();
    PTK_Content_Importer::init();
    PTK_Feedback::init();
    PTK_Notifications::init();
    PTK_Role_Access::init();
    PTK_Multisite::init();
    PTK_Content_Lock::init();
    PTK_Hub_Look::init();
    PTK_Picture_Picker::init();
    PTK_Simple_Mode::init();
    PTK_Site_Colors::init();
    PTK_Auto_Updater::init();
    PTK_Network_Provisioning::init();
    PTK_Vendor_Directory::init();
    PTK_Vendor_Reviews::init();
    PTK_Vendor_Moderation::init();
    PTK_Welcome::init();
}
add_action( 'plugins_loaded', 'ptk_init' );

/**
 * Clear search cache when the plugin is updated to a new version.
 * This catches updates that don't trigger the activation hook.
 */
function ptk_maybe_clear_cache_on_update() {
    $stored_version = get_option( 'ptk_installed_version', '' );
    if ( $stored_version !== PTK_VERSION ) {
        PTK_Search_Engine::invalidate_cache();
        update_option( 'ptk_installed_version', PTK_VERSION );
    }
}
add_action( 'init', 'ptk_maybe_clear_cache_on_update' );

/**
 * Flush rewrite rules once after the plugin's rewrite-affecting version changes.
 *
 * The activation hook does not fire when a plugin is UPDATED, so a new custom
 * post type (e.g. Newsletters and its /newsletters/ archive) would 404 until
 * someone manually re-saved Settings → Permalinks. PTA volunteers will never
 * know to do that, so we flush for them, once, automatically.
 *
 * Runs late on `init` (priority 99) so every post type is registered first.
 */
function ptk_maybe_flush_rewrites_on_update() {
    if ( get_option( 'ptk_rewrite_ver', '' ) !== PTK_VERSION ) {
        flush_rewrite_rules();
        update_option( 'ptk_rewrite_ver', PTK_VERSION );
    }
}
add_action( 'init', 'ptk_maybe_flush_rewrites_on_update', 99 );

/**
 * On activation: create default categories and a Knowledge Base page.
 */
function ptk_activate() {
    // Register post types first so the taxonomy exists and every rewrite rule
    // (including the Newsletters archive) is present for the flush below.
    PTK_Post_Type::register_post_type();
    PTK_Post_Type::register_taxonomy();
    PTK_Newsletter_Post_Type::register();

    // Insert default categories.
    $defaults = array(
        'how-to-guide'   => array(
            'name'        => 'How-To Guide',
            'description' => 'Step-by-step procedures, setup instructions, and processes.',
        ),
        'event-playbook' => array(
            'name'        => 'Event Playbook',
            'description' => 'Event details, timelines, supply lists, and budgets.',
        ),
        'faq'            => array(
            'name'        => 'FAQ',
            'description' => 'Frequently asked questions with ready-to-copy answers.',
        ),
        'resource'       => array(
            'name'        => 'Resource',
            'description' => 'Videos, images, flyers, templates, and other files.',
        ),
        'glossary'       => array(
            'name'        => 'Glossary Term',
            'description' => 'Plain-English definitions for PTA terms, tools, and acronyms.',
        ),
        'checklist'      => array(
            'name'        => 'Checklist',
            'description' => 'Step-by-step checklists for transitions, setup, or audits.',
        ),
        'policy'         => array(
            'name'        => 'Policy / Rules',
            'description' => 'Bylaws, standing rules, guidelines, and governance documents.',
        ),
    );

    foreach ( $defaults as $slug => $cat ) {
        if ( ! term_exists( $slug, 'knowledge_category' ) ) {
            wp_insert_term( $cat['name'], 'knowledge_category', array(
                'slug'        => $slug,
                'description' => $cat['description'],
            ) );
        }
    }

    // Create a Knowledge Base page with the shortcode if it doesn't exist.
    $existing = get_page_by_path( 'knowledge-base' );
    if ( ! $existing ) {
        wp_insert_post( array(
            'post_title'   => 'Knowledge Base',
            'post_name'    => 'knowledge-base',
            'post_content' => '<!-- wp:shortcode -->[pta_search]<!-- /wp:shortcode -->',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
    }

    // Create a Glossary page if it doesn't exist.
    $glossary_page = get_page_by_path( 'glossary' );
    if ( ! $glossary_page ) {
        wp_insert_post( array(
            'post_title'   => 'Glossary',
            'post_name'    => 'glossary',
            'post_content' => '<!-- wp:shortcode -->[pta_glossary]<!-- /wp:shortcode -->',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
    }

    // Create database tables.
    PTK_Analytics::create_table();
    PTK_Feedback::create_table();
    PTK_Search_Engine::create_click_table();

    // Vendor Directory (v3.0): shared reviews table + this site's provisioning.
    PTK_Network_Provisioning::create_reviews_table();
    PTK_Network_Provisioning::provision_site();

    // Clear search cache so stale results don't persist across updates.
    PTK_Search_Engine::invalidate_cache();

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ptk_activate' );

/**
 * Load our custom single template for pta_knowledge posts.
 * This lets the plugin supply its own template without requiring
 * the theme to have one.
 */
function ptk_single_template( $template ) {
    global $post;
    if ( $post && 'pta_knowledge' === $post->post_type ) {
        $plugin_template = PTK_PLUGIN_DIR . 'templates/single-pta_knowledge.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }
    return $template;
}
add_filter( 'single_template', 'ptk_single_template' );

/**
 * Override the document title for knowledge entries.
 * Shows "Entry Title | PTA Council" instead of "Entry Title | Site Name"
 * so printed pages display the PTA branding, not the site owner's name.
 */
function ptk_document_title_parts( $title_parts ) {
    if ( is_singular( 'pta_knowledge' ) ) {
        $title_parts['site'] = 'PTA Council';
    }
    return $title_parts;
}
add_filter( 'document_title_parts', 'ptk_document_title_parts' );

/**
 * Enqueue styles and scripts on single pta_knowledge pages.
 */
function ptk_single_assets() {
    if ( is_singular( 'pta_knowledge' ) ) {
        // The shared public look -- tokens and fonts -- first.
        wp_enqueue_style(
            'ptk-public',
            PTK_PLUGIN_URL . 'assets/css/public.css',
            array(),
            PTK_VERSION
        );
        wp_enqueue_style(
            'ptk-single',
            PTK_PLUGIN_URL . 'assets/css/single.css',
            array( 'ptk-public' ),
            PTK_VERSION
        );
        wp_enqueue_script(
            'ptk-copy-button',
            PTK_PLUGIN_URL . 'assets/js/copy-button.js',
            array(),
            PTK_VERSION,
            true
        );
    }
}
add_action( 'wp_enqueue_scripts', 'ptk_single_assets' );

/**
 * On deactivation: clean up rewrite rules.
 */
function ptk_deactivate() {
    flush_rewrite_rules();
    wp_clear_scheduled_hook( 'ptk_prune_logs' );
}
register_deactivation_hook( __FILE__, 'ptk_deactivate' );
