<?php
/**
 * The switch that decides whether a site sees the redesigned Hub at all.
 *
 * Ships OFF. While it is off nothing about the admin changes: the new
 * stylesheet and fonts are never enqueued, no body class is added, and
 * PTK_Welcome renders exactly what it rendered before.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Hub_Look {

    /** Per-site option. '1' = on. Absent or '0' = off. */
    const OPTION = 'ptk_hub_new_look';

    /**
     * Hub pages registered by this plugin, by their page slug. Later phases
     * migrate more screens (vendor approvals, the content wizard, suggestions,
     * analytics) -- each one is added here when its screen is migrated, never
     * before, so a half-styled screen can't appear.
     */
    const PAGES = array(
        'ptk-welcome',
        'ptk-newsletter-builder',
        'ptk-share-settings',
        'ptk-content-wizard',
        'ptk-written',
        'ptk-vendor-approvals',
    );

    /** Post types the Hub owns. */
    const POST_TYPES = array( 'pta_knowledge', 'pta_newsletter' );

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
    }

    /** Pure: is the stored option value "on"? */
    public static function on_for( $stored ) {
        return '1' === (string) $stored;
    }

    /** Is the new look on for this site? */
    public static function on() {
        return self::on_for( get_option( self::OPTION, '0' ) );
    }

    /**
     * Pure: does this admin screen belong to the Hub? $hook is
     * get_current_screen()->id / the admin_enqueue_scripts hook suffix;
     * $post_type is the screen's post type (may be '').
     */
    public static function is_hub_screen( $hook, $post_type ) {
        $hook      = (string) $hook;
        $post_type = (string) $post_type;

        // A Hub post type counts only on WordPress's own list and editor
        // screens. Every submenu page under the PTA Hub menu also reports
        // post_type = pta_knowledge, and those pages (the content wizard,
        // vendor approvals, analytics...) are migrated one at a time via
        // PAGES -- never by accident through their parent menu.
        $core = array( 'edit.php', 'post.php', 'post-new.php', 'edit', 'post', 'edit-' . $post_type, $post_type );
        if ( in_array( $post_type, self::POST_TYPES, true ) && in_array( $hook, $core, true ) ) {
            return true;
        }
        foreach ( self::PAGES as $page ) {
            if ( '' !== $page && false !== strpos( $hook, $page ) ) {
                return true;
            }
        }
        return false;
    }

    /** Pure: what a submitted checkbox should store. */
    public static function sanitize_choice( $submitted ) {
        $submitted = is_scalar( $submitted ) ? (string) $submitted : '';
        return ( 'on' === $submitted || '1' === $submitted ) ? '1' : '0';
    }

    /** WCAG relative-luminance contrast between two #rrggbb colors. */
    public static function contrast_ratio( $fg, $bg ) {
        $lum = function ( $hex ) {
            $hex = ltrim( (string) $hex, '#' );
            $out = 0.0;
            $channels = array( 0.2126, 0.7152, 0.0722 );
            foreach ( array( 0, 2, 4 ) as $i => $offset ) {
                $c = hexdec( substr( $hex, $offset, 2 ) ) / 255;
                $c = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
                $out += $c * $channels[ $i ];
            }
            return $out;
        };
        $a = $lum( $fg );
        $b = $lum( $bg );
        $light = max( $a, $b );
        $dark  = min( $a, $b );
        return ( $light + 0.05 ) / ( $dark + 0.05 );
    }

    /** True only when the look is on AND we are on a Hub screen. */
    public static function active() {
        if ( ! self::on() || ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }
        return self::is_hub_screen( $screen->id, isset( $screen->post_type ) ? $screen->post_type : '' );
    }

    public static function enqueue( $hook ) {
        if ( ! self::active() ) {
            return;
        }
        wp_enqueue_style( 'ptk-hub', PTK_PLUGIN_URL . 'assets/css/hub.css', array(), PTK_VERSION );
    }

    public static function body_class( $classes ) {
        return self::active() ? $classes . ' ptk-hub-look' : $classes;
    }
}
