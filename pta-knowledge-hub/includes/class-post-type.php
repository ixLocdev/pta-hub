<?php
/**
 * Registers the PTA Knowledge custom post type and taxonomy.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Post_Type {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
    }

    /**
     * Register the pta_knowledge post type.
     */
    public static function register_post_type() {
        $labels = array(
            'name'                  => 'PTA Hub',
            'singular_name'         => 'Hub Entry',
            'menu_name'             => 'PTA Hub',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Knowledge Entry',
            'edit_item'             => 'Edit Knowledge Entry',
            'new_item'              => 'New Knowledge Entry',
            'view_item'             => 'View Knowledge Entry',
            'search_items'          => 'Search PTA Hub',
            'not_found'             => 'No knowledge entries found.',
            'not_found_in_trash'    => 'No knowledge entries found in Trash.',
            'all_items'             => 'All Entries',
            'archives'              => 'Knowledge Archives',
            'insert_into_item'      => 'Insert into entry',
            'uploaded_to_this_item' => 'Uploaded to this entry',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true, // Gutenberg support
            'menu_position'       => 5,
            'menu_icon'           => 'dashicons-welcome-learn-more',
            'supports'            => array(
                'title',
                'editor',
                'excerpt',
                'thumbnail',
                'revisions',
                'author',
            ),
            'has_archive'         => true,
            'rewrite'             => array( 'slug' => 'knowledge' ),
            'capability_type'     => 'post',
            'taxonomies'          => array( 'knowledge_category', 'post_tag' ),
        );

        register_post_type( 'pta_knowledge', $args );
    }

    /**
     * Register the knowledge_category taxonomy.
     */
    public static function register_taxonomy() {
        $labels = array(
            'name'              => 'Knowledge Categories',
            'singular_name'     => 'Category',
            'search_items'      => 'Search Categories',
            'all_items'         => 'All Categories',
            'parent_item'       => 'Parent Category',
            'parent_item_colon' => 'Parent Category:',
            'edit_item'         => 'Edit Category',
            'update_item'       => 'Update Category',
            'add_new_item'      => 'Add New Category',
            'new_item_name'     => 'New Category Name',
            'menu_name'         => 'Categories',
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'knowledge-category' ),
        );

        register_taxonomy( 'knowledge_category', 'pta_knowledge', $args );
    }
}
