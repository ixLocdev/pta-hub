<?php
/**
 * The decision: is Simple mode on for this person, on this site, right now?
 *
 * Simple mode trims the admin down to Hub tasks for volunteers who have no
 * business in the rest of WordPress. It only ever hides chrome -- every
 * screen keeps its own capability checks, and a typed URL still works.
 *
 * Nothing in this file has any effect while PTK_Hub_Look::on() is false: a
 * site that has not turned the new look on is untouched, byte for byte.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Simple_Mode {

    /** Per-user meta. '1' = on, '0' = off, absent = "use the site default". */
    const USER_META = 'ptk_simple_mode';

    /** Per-site option: the role slugs Simple mode defaults on for. */
    const ROLES_OPTION = 'ptk_simple_mode_roles';

    /** The Hub's own top-level menu slug -- the one top-level item Simple mode keeps. */
    const HUB_TOP_SLUG = 'edit.php?post_type=pta_knowledge';

    /**
     * The admin_post_{ACTION} hook the toggle listens on, and the nonce
     * action/name it checks. Deliberately the same string as USER_META --
     * both spell out "this is the Simple mode switch" -- but they are two
     * separate constants for two separate purposes.
     */
    const TOGGLE_ACTION = 'ptk_simple_mode';

    public static function init() {
        // Late priority: run after every other plugin (and WordPress itself)
        // has added its own menu items and admin-bar nodes, so there is
        // something to trim.
        add_action( 'admin_menu', array( __CLASS__, 'trim_admin_menu' ), 999 );
        add_action( 'admin_bar_menu', array( __CLASS__, 'trim_admin_bar' ), 999 );

        // Hub screens only: hide Screen Options and the help tab. These
        // filters always run their check internally -- see the
        // "nothing runs" note on active_for_user() and on_hub_screen().
        add_filter( 'screen_options_show_screen', array( __CLASS__, 'hide_screen_options' ) );
        add_filter( 'contextual_help', array( __CLASS__, 'hide_contextual_help' ), 10, 3 );

        // Task 3: the way out (the toggle) and the landing rules.
        add_action( 'admin_post_' . self::TOGGLE_ACTION, array( __CLASS__, 'handle_toggle' ) );
        add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 10, 3 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_leave_dashboard' ) );

        // Task 4: quieter Hub screens -- clear other plugins' notices before
        // WordPress prints them.
        add_action( 'in_admin_header', array( __CLASS__, 'clear_foreign_notices' ) );
    }

    /**
     * Pure: does this person see Simple mode, given their own choice (if
     * any), their roles, and the roles it defaults on for? The person's own
     * choice always wins; absent means "on if any of their roles is in the
     * default list."
     *
     * @param string $user_meta '1', '0', or '' (absent).
     * @param array  $roles The person's own role slugs.
     * @param array  $default_roles Role slugs Simple mode defaults on for.
     */
    public static function on_for( $user_meta, $roles, $default_roles ) {
        $user_meta = (string) $user_meta;
        if ( '1' === $user_meta ) {
            return true;
        }
        if ( '0' === $user_meta ) {
            return false;
        }
        $roles = is_array( $roles ) ? $roles : array();
        $default_roles = is_array( $default_roles ) ? $default_roles : array();
        foreach ( $roles as $role ) {
            if ( in_array( $role, $default_roles, true ) ) {
                return true;
            }
        }
        return false;
    }

    /** Pure: the roles Simple mode defaults on for -- everyone but administrators. */
    public static function default_roles() {
        return array( 'editor', 'author', 'contributor', 'subscriber' );
    }

    /**
     * Pure: only real role slugs survive a submitted list. `administrator`
     * may be included if a site chooses to -- nothing forbids it here.
     *
     * @param array $submitted Role slugs a settings form posted.
     * @param array $all_roles Every role slug that actually exists on this site.
     */
    public static function sanitize_roles( $submitted, $all_roles ) {
        $submitted = is_array( $submitted ) ? $submitted : array();
        $all_roles = is_array( $all_roles ) ? $all_roles : array();
        $kept = array();
        foreach ( $submitted as $role ) {
            $role = is_scalar( $role ) ? (string) $role : '';
            if ( '' !== $role && in_array( $role, $all_roles, true ) && ! in_array( $role, $kept, true ) ) {
                $kept[] = $role;
            }
        }
        return $kept;
    }

    /**
     * Pure: is this person too small for the Hub at all -- lacking
     * edit_posts, so they never belong in wp-admin? $caps is their
     * capability => bool map (as WP_User::allcaps looks).
     */
    public static function too_small_for_hub( $caps ) {
        $caps = is_array( $caps ) ? $caps : array();
        return empty( $caps['edit_posts'] );
    }

    /**
     * Pure: is Simple mode active at all, given whether the new look is on?
     * Nothing in this phase runs unless the new look is on for the site --
     * this is the single gate every WordPress-facing wrapper must check
     * first, whatever else is true.
     *
     * @param bool   $look_on Whatever PTK_Hub_Look::on() returned.
     * @param string $user_meta '1', '0', or '' (absent).
     * @param array  $roles The person's own role slugs.
     * @param array  $default_roles Role slugs Simple mode defaults on for.
     */
    public static function active( $look_on, $user_meta, $roles, $default_roles ) {
        if ( ! $look_on ) {
            return false;
        }
        return self::on_for( $user_meta, $roles, $default_roles );
    }

    /**
     * Is Simple mode on for the given user (current user if omitted)? Reads
     * live WordPress state and calls the pure helpers above.
     *
     * @param int|null $user_id Defaults to the current user.
     */
    public static function active_for_user( $user_id = null ) {
        if ( ! class_exists( 'PTK_Hub_Look' ) || ! PTK_Hub_Look::on() ) {
            return false;
        }
        $user = null === $user_id ? wp_get_current_user() : get_userdata( $user_id );
        if ( ! $user || ! $user->exists() ) {
            return false;
        }
        $meta = get_user_meta( $user->ID, self::USER_META, true );
        return self::active( true, $meta, (array) $user->roles, self::default_roles_for_site() );
    }

    /** The roles Simple mode defaults on for on this site, from the option (or the built-in default). */
    public static function default_roles_for_site() {
        $stored = get_option( self::ROLES_OPTION, '' );
        if ( ! is_array( $stored ) || empty( $stored ) ) {
            return self::default_roles();
        }
        return $stored;
    }

    /**
     * Pure: does this menu or admin-bar slug/id survive the trim? A plain
     * allow-list check, kept as its own function so the menu the plugin
     * ends up building can be tested without WordPress at all.
     *
     * @param string $slug The menu slug, submenu slug, or admin-bar node id.
     * @param array  $hub_slugs The slugs/ids allowed to survive.
     */
    public static function keep_menu_slug( $slug, $hub_slugs ) {
        return in_array( (string) $slug, is_array( $hub_slugs ) ? $hub_slugs : array(), true );
    }

    /** Top-level menu slugs Simple mode always keeps. */
    public static function top_level_keep_slugs() {
        return array( self::HUB_TOP_SLUG, 'profile.php' );
    }

    /**
     * Submenu slugs, under the Hub's own top-level menu, that count as a
     * Hub task and stay. Everything else the Hub registers under its own
     * menu (Settings, Import Starter Content, Network Sync, School Colors,
     * Search Analytics, Vendor Approvals, Newsletter settings) is a
     * once-in-a-while admin job, not a volunteer's day-to-day task -- the
     * home screen's "Set up the basics (once)" quiet link and direct
     * "waiting for you" links reach them without a menu entry.
     */
    public static function hub_task_submenu_slugs() {
        return array(
            'ptk-welcome',
            'ptk-newsletter-builder',
            // Newsletter settings holds the switch that turns the whole new
            // look off again -- it must never be more than one click away,
            // even though it's otherwise a once-in-a-while admin screen.
            'ptk-share-settings',
            'ptk-content-wizard',
            self::HUB_TOP_SLUG,
            'edit.php?post_type=pta_newsletter',
        );
    }

    /** Admin-bar node ids, at the top level, that Simple mode always keeps. */
    public static function admin_bar_keep_ids() {
        return array( 'site-name', 'my-sites', 'my-account', 'ptk-simple-mode' );
    }

    /**
     * admin_menu (priority 999): remove every top-level menu except the
     * Hub's and Profile, and every Hub submenu that isn't a Hub task. Never
     * CSS hiding -- these are the real WordPress menu-removal functions, so
     * a hidden screen's URL still works if someone types it; only the menu
     * entry is gone.
     */
    public static function trim_admin_menu() {
        if ( is_network_admin() || is_user_admin() ) {
            return;
        }
        if ( ! self::active_for_user() ) {
            return;
        }

        global $menu, $submenu;

        $top_keep = self::top_level_keep_slugs();

        // "Profile" is only its own top-level item for people who lack
        // list_users -- someone who can see the Users menu (most
        // administrators) has it nested under users.php instead. Removing
        // every other top-level menu would take that nesting with it, so
        // add a plain top-level Profile item first when one isn't already
        // there; the keep list below then leaves it alone like any other.
        if ( is_array( $menu ) ) {
            $has_profile_top = false;
            foreach ( $menu as $item ) {
                if ( ! empty( $item[2] ) && 'profile.php' === $item[2] ) {
                    $has_profile_top = true;
                    break;
                }
            }
            if ( ! $has_profile_top && function_exists( 'add_menu_page' ) ) {
                add_menu_page( 'Profile', 'Profile', 'read', 'profile.php', '', 'dashicons-admin-users', 80 );
            }
        }

        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                if ( empty( $item[2] ) || self::keep_menu_slug( $item[2], $top_keep ) ) {
                    continue;
                }
                remove_menu_page( $item[2] );
            }
        }

        if ( ! empty( $submenu[ self::HUB_TOP_SLUG ] ) ) {
            $task_keep = self::hub_task_submenu_slugs();
            foreach ( $submenu[ self::HUB_TOP_SLUG ] as $item ) {
                if ( empty( $item[2] ) || self::keep_menu_slug( $item[2], $task_keep ) ) {
                    continue;
                }
                remove_submenu_page( self::HUB_TOP_SLUG, $item[2] );
            }
        }
    }

    /**
     * admin_bar_menu (priority 999): strip the admin bar down to the site
     * name, the person's account (and, on multisite, the site switcher),
     * then add "Show all of WordPress" -- a real link to the toggle, wired
     * to bring the person back to the page they clicked it from.
     */
    public static function trim_admin_bar( $wp_admin_bar ) {
        if ( is_network_admin() || is_user_admin() ) {
            return;
        }
        if ( ! self::active_for_user() ) {
            return;
        }

        $keep = self::admin_bar_keep_ids();
        foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
            $parent = isset( $node->parent ) ? (string) $node->parent : '';
            // Only top-level nodes are judged directly; removing a
            // top-level node takes its children with it.
            if ( '' !== $parent && 'root' !== $parent ) {
                continue;
            }
            if ( self::keep_menu_slug( $node->id, $keep ) ) {
                continue;
            }
            $wp_admin_bar->remove_node( $node->id );
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'ptk-simple-mode',
            'title' => self::toggle_link_label(),
            'href'  => self::toggle_url(),
        ) );
    }

    /** True when the current screen is a Hub screen AND Simple mode is on for the current user. */
    public static function on_hub_screen_for_user() {
        if ( ! self::active_for_user() ) {
            return false;
        }
        return class_exists( 'PTK_Hub_Look' ) && PTK_Hub_Look::active();
    }

    /** screen_options_show_screen: hide Screen Options on Hub screens in Simple mode. */
    public static function hide_screen_options( $show_screen ) {
        return self::on_hub_screen_for_user() ? false : $show_screen;
    }

    /** contextual_help: hide the help tab on Hub screens in Simple mode. */
    public static function hide_contextual_help( $old_help, $screen_id, $screen ) {
        return self::on_hub_screen_for_user() ? '' : $old_help;
    }

    /* ------------------------------------------------------------------
     * Task 3: landing and the way out.
     * ----------------------------------------------------------------*/

    /** The Hub's own home screen -- where someone in Simple mode with edit_posts lands. */
    public static function hub_home_url() {
        return admin_url( self::HUB_TOP_SLUG . '&page=ptk-welcome' );
    }

    /**
     * Pure: is a redirect target safe to send someone to, given the site's
     * own home url? A bare same-site path, or a full url on the same host,
     * survives; anything else (another host, a scheme-relative "//host/..."
     * trick, empty input) falls back. Guards the toggle's "come back here"
     * link -- and the login landing, which starts from whatever WordPress
     * itself proposed -- against being turned into an open redirect.
     *
     * @param string $requested The candidate redirect target.
     * @param string $home_url  This site's own home url.
     * @param string $fallback  What to use when $requested isn't safe.
     */
    public static function redirect_target( $requested, $home_url, $fallback ) {
        $requested = is_string( $requested ) ? trim( $requested ) : '';
        $fallback  = is_string( $fallback ) ? $fallback : '';
        if ( '' === $requested ) {
            return $fallback;
        }

        // Browsers treat a leading backslash like a leading slash, so
        // normalize before judging "bare path" vs. "off-site".
        $normalized = str_replace( '\\', '/', $requested );

        // A bare path (not "//host/..." -- that's scheme-relative, i.e. off-site).
        if ( 0 === strpos( $normalized, '/' ) && 0 !== strpos( $normalized, '//' ) ) {
            return $requested;
        }

        $req_host  = parse_url( $normalized, PHP_URL_HOST );
        $home_host = parse_url( (string) $home_url, PHP_URL_HOST );
        if ( $req_host && $home_host && strtolower( $req_host ) === strtolower( $home_host ) ) {
            return $requested;
        }

        return $fallback;
    }

    /**
     * Pure: where login_redirect should send someone. Simple mode off
     * leaves WordPress's own default alone; on, edit_posts goes to the Hub
     * home, anyone smaller goes to the PUBLIC Hub page -- never wp-admin.
     *
     * @param string $default_redirect What WordPress (or another plugin) proposed.
     * @param bool   $simple_on        Simple mode active for this person.
     * @param bool   $too_small        too_small_for_hub() for this person.
     * @param string $hub_home_url     hub_home_url().
     * @param string $public_hub_url   The public-facing Hub page.
     */
    public static function login_redirect_target( $default_redirect, $simple_on, $too_small, $hub_home_url, $public_hub_url ) {
        if ( ! $simple_on ) {
            return $default_redirect;
        }
        return $too_small ? $public_hub_url : $hub_home_url;
    }

    /**
     * Pure: should an admin_init request visiting index.php (the dashboard)
     * be sent to the Hub home instead? Never for an AJAX, cron, or
     * network-admin request -- typed URLs everywhere else still work; only
     * the plain dashboard landing is moved.
     *
     * @param string $pagenow   The global $pagenow value.
     * @param bool   $simple_on Simple mode active for this person.
     * @param bool   $is_ajax   wp_doing_ajax() (or DOING_AJAX).
     * @param bool   $is_cron   wp_doing_cron() (or DOING_CRON).
     * @param bool   $is_network_admin is_network_admin().
     */
    public static function should_leave_dashboard( $pagenow, $simple_on, $is_ajax, $is_cron, $is_network_admin ) {
        if ( ! $simple_on || $is_ajax || $is_cron || $is_network_admin ) {
            return false;
        }
        return 'index.php' === (string) $pagenow;
    }

    /** login_redirect filter: WordPress-coupled thin wrapper around login_redirect_target(). */
    public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( ! is_object( $user ) || ! isset( $user->ID ) || ! method_exists( $user, 'exists' ) || ! $user->exists() ) {
            return $redirect_to;
        }
        if ( ! self::active_for_user( $user->ID ) ) {
            return $redirect_to;
        }
        $caps      = isset( $user->allcaps ) && is_array( $user->allcaps ) ? $user->allcaps : array();
        $too_small = self::too_small_for_hub( $caps );
        $public    = function_exists( 'ptk_hub_url' ) ? ptk_hub_url() : home_url( '/' );

        return self::login_redirect_target( $redirect_to, true, $too_small, self::hub_home_url(), $public );
    }

    /**
     * admin_init: move someone in Simple mode off the plain dashboard onto
     * the Hub home. Never for AJAX, REST, cron, or network-admin requests --
     * REST requests never reach admin_init at all, but the check stays
     * explicit rather than relying on that.
     */
    public static function maybe_leave_dashboard() {
        if ( ! self::active_for_user() ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        $is_ajax          = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' ) && DOING_AJAX;
        $is_cron          = function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : defined( 'DOING_CRON' ) && DOING_CRON;
        $is_network_admin = function_exists( 'is_network_admin' ) && is_network_admin();

        global $pagenow;
        if ( ! self::should_leave_dashboard( (string) $pagenow, true, $is_ajax, $is_cron, $is_network_admin ) ) {
            return;
        }

        wp_safe_redirect( self::hub_home_url() );
        exit;
    }

    /** The current request's full url -- used as the toggle's "come back here" target. */
    private static function current_request_url() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        return home_url( $uri );
    }

    /**
     * The toggle's href, wherever it's placed -- the admin-bar node, or the
     * home screen's quiet link. Nothing runs when the new look is off: the
     * link falls back to the plain wp-admin home, same as it always was.
     *
     * @param string $redirect_to Where to send the person back to; defaults to the current page.
     */
    public static function toggle_url( $redirect_to = '' ) {
        if ( ! class_exists( 'PTK_Hub_Look' ) || ! PTK_Hub_Look::on() ) {
            return admin_url();
        }
        $redirect_to = '' !== $redirect_to ? $redirect_to : self::current_request_url();
        $url = add_query_arg(
            array(
                'action'      => self::TOGGLE_ACTION,
                'redirect_to' => rawurlencode( $redirect_to ),
            ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, self::TOGGLE_ACTION );
    }

    /** The toggle link's label -- what clicking it will do next, not the current state. */
    public static function toggle_link_label() {
        return self::active_for_user() ? 'Show all of WordPress' : 'Back to the simple view';
    }

    /**
     * admin_post_ptk_simple_mode: flip the current user's own Simple mode
     * meta, then send them back where they came from. Anyone signed in can
     * flip it for themselves -- this is a personal display preference, not
     * a permission, so the only capability check is "are you a real,
     * signed-in user at all."
     */
    public static function handle_toggle() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( 'You must be signed in to do that.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::TOGGLE_ACTION );

        $user_id = get_current_user_id();
        $turn_on = ! self::active_for_user( $user_id );
        update_user_meta( $user_id, self::USER_META, $turn_on ? '1' : '0' );

        $requested = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : (string) wp_get_referer();
        $target    = self::redirect_target( $requested, home_url(), self::hub_home_url() );

        wp_safe_redirect( $target );
        exit;
    }

    /* ------------------------------------------------------------------
     * Task 4: quieter Hub screens.
     * ----------------------------------------------------------------*/

    /**
     * Pure: should other plugins' admin_notices/all_admin_notices callbacks
     * be cleared right now? Only on a Hub screen, only with Simple mode on.
     */
    public static function should_clear_notices( $is_hub_screen, $simple_on ) {
        return (bool) $is_hub_screen && (bool) $simple_on;
    }

    /**
     * in_admin_header: strip every other plugin's (and WordPress's own
     * update-nag's) admin_notices/all_admin_notices callback before they
     * print, keeping only this plugin's own. Runs before WordPress fires
     * those hooks -- in_admin_header fires first in wp-admin's own template.
     */
    public static function clear_foreign_notices() {
        $is_hub_screen = class_exists( 'PTK_Hub_Look' ) && PTK_Hub_Look::active();
        if ( ! self::should_clear_notices( $is_hub_screen, self::active_for_user() ) ) {
            return;
        }
        self::strip_notice_hooks( 'admin_notices' );
        self::strip_notice_hooks( 'all_admin_notices' );
    }

    /** Remove every callback on $hook except ones belonging to this plugin's own PTK_* classes. */
    private static function strip_notice_hooks( $hook ) {
        global $wp_filter;
        if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
            return;
        }
        foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $id => $cb ) {
                if ( isset( $cb['function'] ) && self::callback_belongs_to_hub( $cb['function'] ) ) {
                    continue;
                }
                unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
            }
            if ( empty( $wp_filter[ $hook ]->callbacks[ $priority ] ) ) {
                unset( $wp_filter[ $hook ]->callbacks[ $priority ] );
            }
        }
    }

    /** Does a hooked callback belong to one of this plugin's own PTK_* classes (or a plain ptk_-prefixed function)? */
    private static function callback_belongs_to_hub( $function ) {
        $target = $function;
        if ( is_array( $target ) ) {
            $target = $target[0];
        }
        if ( is_object( $target ) ) {
            $target = get_class( $target );
        }
        if ( ! is_string( $target ) ) {
            return false;
        }
        return 0 === strpos( $target, 'PTK_' ) || 0 === strpos( $target, 'ptk_' );
    }
}
