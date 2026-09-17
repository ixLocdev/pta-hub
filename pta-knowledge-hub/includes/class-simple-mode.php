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

    public static function init() {
        // Wrappers around the pure helpers below are added in later tasks
        // (menu, admin bar, redirects, notices). This file stays limited to
        // the decision itself plus its WordPress-facing accessors.
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
}
