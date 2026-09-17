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

        // Redirects and the toggle itself are task 3; notice-clearing is task 4.
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
     * then add "Show all of WordPress". The toggle behind that link is
     * wired up in task 3; for now it opens the ordinary wp-admin home,
     * which still works for anyone who lands there.
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
            'title' => 'Show all of WordPress',
            'href'  => admin_url(),
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
}
