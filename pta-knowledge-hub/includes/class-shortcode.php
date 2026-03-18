<?php
/**
 * Registers the [pta_search] shortcode and enqueues front-end assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Shortcode {

    public static function init() {
        add_shortcode( 'pta_search', array( __CLASS__, 'render' ) );
    }

    /**
     * Render the search page UI.
     */
    public static function render( $atts ) {
        // Check access — show login prompt if required and user is not logged in.
        if ( ! ptk_check_access() ) {
            ob_start();
            ptk_check_access( true );
            return ob_get_clean();
        }

        // Enqueue assets only when shortcode is actually used.
        self::enqueue_assets();

        $suggested = PTK_Search_Engine::get_suggested_searches( 8 );

        // Get category counts for filter buttons.
        $categories = get_terms( array(
            'taxonomy'   => 'knowledge_category',
            'hide_empty' => true,
        ) );

        ob_start();
        include PTK_PLUGIN_DIR . 'templates/search-page.php';
        return ob_get_clean();
    }

    /**
     * Enqueue CSS and JS for the search page.
     */
    private static function enqueue_assets() {
        wp_enqueue_style(
            'ptk-search-page',
            PTK_PLUGIN_URL . 'assets/css/search-page.css',
            array(),
            PTK_VERSION
        );

        wp_enqueue_script(
            'ptk-search',
            PTK_PLUGIN_URL . 'assets/js/search.js',
            array(),
            PTK_VERSION,
            true
        );

        wp_localize_script( 'ptk-search', 'ptkSearch', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ptk_search_nonce' ),
        ) );

        wp_enqueue_script(
            'ptk-copy-button',
            PTK_PLUGIN_URL . 'assets/js/copy-button.js',
            array(),
            PTK_VERSION,
            true
        );
    }
}
