<?php
/**
 * Registers the pta_newsletter custom post type (school website newsletters).
 *
 * Content is authored via the Newsletter Builder form and stored as structured
 * post meta; post_content is regenerated from that meta on every save.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Newsletter_Post_Type {

    const POST_TYPE = 'pta_newsletter';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public' ) );
    }

    /**
     * Enqueue the reader's-date relabel script, the stylesheet that hides the
     * theme's doubled title, and the house fonts on the public single
     * newsletter view only (the ?ptk_preview= view counts as singular too).
     */
    public static function enqueue_public() {
        if ( ! is_singular( self::POST_TYPE ) ) {
            return;
        }

        wp_enqueue_script(
            'ptk-newsletter-relabel',
            PTK_PLUGIN_URL . 'assets/js/newsletter-relabel.js',
            array(),
            PTK_VERSION,
            true
        );
        // The newsletter's masthead is the page's title; the theme's own title
        // and date above it are hidden here, never by replacing the template
        // (bb-theme's container and the Themer header/footer depend on it).
        wp_enqueue_style( 'ptk-newsletter-public', PTK_PLUGIN_URL . 'assets/css/newsletter-public.css', array(), PTK_VERSION );

        // The other ten sites don't load the house fonts themselves.
        wp_enqueue_style( 'ptk-newsletter-fonts', 'https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap', array(), null );
    }

    public static function register() {
        $labels = array(
            'name'               => 'Newsletters',
            'singular_name'      => 'Newsletter',
            'menu_name'          => 'Newsletters',
            'add_new'            => 'Add New',
            'add_new_item'       => 'New Newsletter',
            'edit_item'          => 'Edit Newsletter',
            'view_item'          => 'View Newsletter',
            'all_items'          => 'All Newsletters',
            'archives'           => 'Newsletter Archive',
            'not_found'          => 'No newsletters yet.',
            'not_found_in_trash' => 'No newsletters in Trash.',
        );

        register_post_type( self::POST_TYPE, array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            // 4.3.0: nested under the PTA Hub menu instead of its own
            // top-level entry, the same pattern class-vendor-directory.php
            // and class-suggestions.php already use. menu_position and
            // menu_icon only apply to a top-level entry, so they're removed
            // rather than left as dead config.
            'show_in_menu'       => 'edit.php?post_type=pta_knowledge',
            'show_in_rest'       => false,
            // 'excerpt' added round 8: PTK_Newsletter_News_Listing writes a
            // computed excerpt on every save so get_the_excerpt() has
            // something to return wherever a newsletter is listed among
            // posts -- see that class for when that actually happens.
            'supports'           => array( 'title', 'thumbnail', 'excerpt', 'revisions', 'author' ),
            'has_archive'        => 'newsletters',
            'rewrite'            => array( 'slug' => 'newsletters' ),
            'capability_type'    => 'post',
        ) );
    }
}
