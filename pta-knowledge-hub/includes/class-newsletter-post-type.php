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
            'show_in_menu'       => true,
            'show_in_rest'       => false,
            'menu_position'      => 6,
            'menu_icon'          => 'dashicons-email',
            'supports'           => array( 'title', 'thumbnail', 'revisions', 'author' ),
            'has_archive'        => 'newsletters',
            'rewrite'            => array( 'slug' => 'newsletters' ),
            'capability_type'    => 'post',
        ) );
    }
}
