<?php
/**
 * Plugin Name: PTA Knowledge Hub
 * Plugin URI:  https://github.com/your-pta/knowledge-hub
 * Description: A searchable knowledge base for your PTA. Volunteers add content through WordPress, parents and members find answers instantly via a smart search bar.
 * Version:     2.8.0
 * Author:      Lucas Deichl
 * License:     GPL-2.0-or-later
 * Text Domain: pta-knowledge-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PTK_VERSION', '2.8.0' );
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

/**
 * Load plugin classes.
 */
require_once PTK_PLUGIN_DIR . 'includes/class-post-type.php';
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
require_once PTK_PLUGIN_DIR . 'includes/class-glossary-tooltips.php';
require_once PTK_PLUGIN_DIR . 'includes/class-glossary-page.php';
require_once PTK_PLUGIN_DIR . 'includes/class-content-importer.php';
require_once PTK_PLUGIN_DIR . 'includes/class-feedback.php';
require_once PTK_PLUGIN_DIR . 'includes/class-notifications.php';
require_once PTK_PLUGIN_DIR . 'includes/class-role-access.php';
require_once PTK_PLUGIN_DIR . 'includes/class-multisite.php';
require_once PTK_PLUGIN_DIR . 'includes/class-auto-updater.php';

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
        $login_url = wp_login_url( get_permalink() );
        ?>
        <style>
            .ptk-login-required{text-align:center;max-width:480px;margin:60px auto;padding:48px 32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
            .ptk-login-icon{font-size:48px;margin-bottom:12px}
            .ptk-login-required h2{font-size:22px;font-weight:700;color:#111827;margin:0 0 8px}
            .ptk-login-required p{font-size:15px;color:#6b7280;line-height:1.6;margin:0 0 24px}
            .ptk-login-btn{display:inline-block;background:#4f46e5;color:#fff!important;text-decoration:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;transition:background .15s}
            .ptk-login-btn:hover{background:#4338ca;color:#fff!important}
        </style>
        <div class="ptk-login-required">
            <div class="ptk-login-icon">&#128274;</div>
            <h2>Members Only</h2>
            <p>The PTA Hub is for PTA members and volunteers. Please log in with your WordPress account to view this content.</p>
            <a href="<?php echo esc_url( $login_url ); ?>" class="ptk-login-btn">Log In</a>
        </div>
        <?php
    }

    return false;
}

/**
 * Initialize everything on plugins_loaded.
 */
function ptk_init() {
    PTK_Post_Type::init();
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
    PTK_Glossary_Tooltips::init();
    PTK_Glossary_Page::init();
    PTK_Content_Importer::init();
    PTK_Feedback::init();
    PTK_Notifications::init();
    PTK_Role_Access::init();
    PTK_Multisite::init();
    PTK_Auto_Updater::init();
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
 * On activation: create default categories and a Knowledge Base page.
 */
function ptk_activate() {
    // Register post type first so taxonomy exists.
    PTK_Post_Type::register_post_type();
    PTK_Post_Type::register_taxonomy();

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
        wp_enqueue_style(
            'ptk-single',
            PTK_PLUGIN_URL . 'assets/css/single.css',
            array(),
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
}
register_deactivation_hook( __FILE__, 'ptk_deactivate' );
