<?php
/**
 * Makes network-copied Council articles read-only on school sites.
 *
 * The Council is the single source of truth; a school admin may view a shared
 * article but not edit or delete it. Enforced at the capability layer (not just
 * hidden UI) via map_meta_cap. Transparent to the sync process, which writes
 * copies with wp_insert/update/trash_post and never checks capabilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Content_Lock {

    public static function init() {
        // Only subsites hold locked copies; the Council owns the originals.
        if ( ! is_multisite() || is_main_site() ) {
            return;
        }
        add_filter( 'map_meta_cap', array( __CLASS__, 'lock_network_copies' ), 10, 4 );
        add_filter( 'post_row_actions', array( __CLASS__, 'view_not_edit' ), 20, 2 );
    }

    /**
     * Deny edit/delete of a network-copy pta_knowledge post.
     */
    public static function lock_network_copies( $caps, $cap, $user_id, $args ) {
        if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ) {
            return $caps;
        }
        $post_id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( $post_id
            && 'pta_knowledge' === get_post_type( $post_id )
            && class_exists( 'PTK_Multisite' )
            && PTK_Multisite::is_network_copy( $post_id ) ) {
            return array( 'do_not_allow' );
        }
        return $caps;
    }

    /**
     * Swap the Edit/Trash row actions for a plain "View" on locked copies.
     */
    public static function view_not_edit( $actions, $post ) {
        if ( 'pta_knowledge' !== $post->post_type
            || ! class_exists( 'PTK_Multisite' )
            || ! PTK_Multisite::is_network_copy( $post->ID ) ) {
            return $actions;
        }
        return array(
            'view' => '<a href="' . esc_url( get_permalink( $post->ID ) ) . '" target="_blank" rel="noopener">View</a>',
        );
    }
}
