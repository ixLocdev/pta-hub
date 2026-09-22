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

        // Get "What's New" entries for the notification section.
        $new_entries = array();
        if ( class_exists( 'PTK_Notifications' ) ) {
            $new_entries = PTK_Notifications::get_new_entries( 5 );
        }

        ob_start();
        include PTK_PLUGIN_DIR . 'templates/search-page.php';

        // Don't update last visit here — wait until user dismisses the section.
        // This prevents "I clicked one link, came back, and they all vanished."

        return ob_get_clean();
    }

    /**
     * Enqueue CSS and JS for the search page.
     */
    private static function enqueue_assets() {
        // The shared public look -- tokens and fonts. Every public surface
        // loads this first; the page's own sheet declares no color of its own.
        wp_enqueue_style(
            'ptk-public',
            PTK_PLUGIN_URL . 'assets/css/public.css',
            array(),
            PTK_VERSION
        );

        wp_enqueue_style(
            'ptk-search-page',
            PTK_PLUGIN_URL . 'assets/css/search-page.css',
            array( 'ptk-public' ),
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
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'ptk_search_nonce' ),
            // "Ask us to write this" posts to the suggestion endpoint that
            // PTK_Suggestions already owns -- same nonce, same honeypot, same
            // rate limit, and it lands in "What families have asked for".
            'suggestNonce'  => wp_create_nonce( 'ptk_submit_suggestion' ),
            'categoryNames' => self::category_labels(),
            'linkText'      => self::card_link_texts(),
        ) );

        wp_enqueue_script(
            'ptk-copy-button',
            PTK_PLUGIN_URL . 'assets/js/copy-button.js',
            array(),
            PTK_VERSION,
            true
        );
    }
    /* -------------------------------------------------------------- */
    /*  What a family should read                                     */
    /* -------------------------------------------------------------- */

    /**
     * Family-facing names for the categories this plugin ships.
     *
     * Presentation only. The taxonomy terms themselves are stored data on
     * eleven school sites and are never renamed here -- a category we do not
     * know about keeps whatever name its site gave it.
     */
    public static function category_labels(): array {
        return array(
            'faq'            => 'Questions families ask',
            'how-to-guide'   => 'How to do something',
            'event-playbook' => 'Running an event',
            'resource'       => 'Forms and files',
            'glossary'       => 'Words we use',
            'checklist'      => 'Step-by-step lists',
            'policy'         => 'Rules we follow',
        );
    }

    /**
     * One category's family-facing name, or its own name if we don't know it.
     */
    public static function category_label( string $slug, string $fallback = '' ): string {
        $labels = self::category_labels();
        if ( isset( $labels[ $slug ] ) ) {
            return $labels[ $slug ];
        }
        return '' !== $fallback ? $fallback : $slug;
    }

    /**
     * What the link at the bottom of a card says. Plain English, and it tells
     * you what you get -- never a bare "View".
     */
    public static function card_link_texts(): array {
        return array(
            'faq'            => 'Read the answer',
            'how-to-guide'   => 'See the steps',
            'event-playbook' => 'See the steps',
            'resource'       => 'Open it',
            'glossary'       => 'Read the answer',
            'checklist'      => 'See the list',
            'policy'         => 'Read it',
        );
    }

    public static function card_link_text( string $slug ): string {
        $texts = self::card_link_texts();
        return isset( $texts[ $slug ] ) ? $texts[ $slug ] : 'Read it';
    }
}
