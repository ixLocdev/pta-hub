<?php
/**
 * Vendor Directory: CPT + taxonomy, [pta_vendors] shortcode, cross-site
 * reads and caching, suggest-a-vendor endpoint, directory page ensure.
 *
 * Vendors live ONLY on the Council (main) site as a `ptk_vendor` custom
 * post type with a `vendor_category` taxonomy and three contact meta
 * fields. Subsites render the shared list through the shortcode (Task 4).
 *
 * Task 2 of the v3.0 plan: registration, seeded categories, contact meta
 * box, cache bumps on vendor edits. Tasks 4-5 fill in the shortcode,
 * cross-site reads, and the suggest-a-vendor endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Vendor_Directory {

    /** Seeded vendor categories (spec: Member experience, Directory page). */
    private static $default_categories = array(
        'food-catering'    => 'Food & Catering',
        'entertainment'    => 'Entertainment',
        'printing-apparel' => 'Printing & Apparel',
        'fundraising'      => 'Fundraising',
        'event-supplies'   => 'Event Supplies & Rentals',
        'photography'      => 'Photography',
        'other-services'   => 'Other Services',
    );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_content_types' ) );

        // Everything below is vendor-admin behavior. Vendor posts exist only
        // on the Council site (or the lone site of a single-site install),
        // so the hooks are pointless elsewhere — same guard as registration.
        if ( is_multisite() && ! PTK_Multisite::is_main_site() ) {
            return;
        }

        // Contact meta box (pattern: PTK_Meta_Fields).
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_contact_meta_box' ) );
        add_action( 'save_post_ptk_vendor', array( __CLASS__, 'save_contact_meta' ), 10, 2 );

        // Cache bumps for vendor changes made outside moderation
        // (spec: Caching — "approve/edit/delete"). Editing a published
        // vendor's title/category/contact, or trashing/deleting one, must
        // invalidate every site's cached directory.
        add_action( 'save_post_ptk_vendor', array( __CLASS__, 'bump_cache_on_change' ) );
        add_action( 'trashed_post', array( __CLASS__, 'bump_cache_on_trash_delete' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'bump_cache_on_trash_delete' ) );
    }

    /* -------------------------------------------------------------- */
    /*  CPT + taxonomy (Council site only)                            */
    /* -------------------------------------------------------------- */

    /**
     * Register the vendor CPT and category taxonomy.
     *
     * Main site only on multisite. On single-site installs
     * PTK_Multisite::is_main_site() returns false, so the guard must be
     * prefixed with is_multisite() — single-site still gets the CPT.
     */
    public static function register_content_types() {
        if ( is_multisite() && ! PTK_Multisite::is_main_site() ) {
            return;
        }

        register_post_type( 'ptk_vendor', array(
            'labels'              => array(
                'name'               => 'Vendors',
                'singular_name'      => 'Vendor',
                'menu_name'          => 'Vendors',
                'all_items'          => 'Vendors',
                'add_new'            => 'Add New Vendor',
                'add_new_item'       => 'Add New Vendor',
                'edit_item'          => 'Edit Vendor',
                'new_item'           => 'New Vendor',
                'view_item'          => 'View Vendor',
                'search_items'       => 'Search Vendors',
                'not_found'          => 'No vendors found.',
                'not_found_in_trash' => 'No vendors found in Trash.',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=pta_knowledge', // Lives under the PTA Hub menu.
            'supports'            => array( 'title' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
        ) );

        register_taxonomy( 'vendor_category', 'ptk_vendor', array(
            'labels'            => array(
                'name'          => 'Vendor Categories',
                'singular_name' => 'Vendor Category',
                'search_items'  => 'Search Vendor Categories',
                'all_items'     => 'All Vendor Categories',
                'edit_item'     => 'Edit Vendor Category',
                'update_item'   => 'Update Vendor Category',
                'add_new_item'  => 'Add New Vendor Category',
                'new_item_name' => 'New Vendor Category Name',
                'menu_name'     => 'Vendor Categories',
            ),
            'hierarchical'      => true, // Like knowledge_category.
            'public'            => false,
            'show_ui'           => true, // Council can add categories later.
            'show_admin_column' => true,
            'show_in_rest'      => false,
            'rewrite'           => false,
        ) );
    }

    /**
     * Seed the default vendor categories. Idempotent — called from
     * PTK_Network_Provisioning::provision_site() (gated there on core
     * is_main_site()) and, via that, from activation.
     *
     * Activation runs AFTER the `init` hook has already fired (the plugin
     * wasn't active during bootstrap), so the taxonomy may not be
     * registered yet in that request — register on demand first.
     */
    public static function ensure_default_categories() {
        if ( ! taxonomy_exists( 'vendor_category' ) ) {
            self::register_content_types();
        }
        if ( ! taxonomy_exists( 'vendor_category' ) ) {
            // Still missing: subsite context, where registration is
            // Council-only. Nothing to seed here.
            return;
        }

        foreach ( self::$default_categories as $slug => $name ) {
            if ( ! term_exists( $slug, 'vendor_category' ) ) {
                wp_insert_term( $name, 'vendor_category', array( 'slug' => $slug ) );
            }
        }
    }

    /* -------------------------------------------------------------- */
    /*  Contact meta box (pattern: PTK_Meta_Fields)                   */
    /* -------------------------------------------------------------- */

    public static function add_contact_meta_box() {
        add_meta_box(
            'ptk_vendor_contact',
            'Vendor Contact',
            array( __CLASS__, 'render_contact_meta_box' ),
            'ptk_vendor',
            'side',
            'high'
        );
    }

    public static function render_contact_meta_box( $post ) {
        wp_nonce_field( 'ptk_vendor_contact', 'ptk_vendor_contact_nonce' );

        $fields = array(
            'ptk_vendor_phone'   => array( 'label' => 'Phone', 'type' => 'text', 'placeholder' => '(555) 555-0100' ),
            'ptk_vendor_email'   => array( 'label' => 'Email', 'type' => 'email', 'placeholder' => 'name@example.com' ),
            'ptk_vendor_website' => array( 'label' => 'Website', 'type' => 'url', 'placeholder' => 'https://' ),
        );

        echo '<div class="ptk-vendor-contact-fields">';
        foreach ( $fields as $key => $def ) {
            $id    = esc_attr( $key );
            $value = get_post_meta( $post->ID, $key, true );

            echo '<p>';
            echo '<label for="' . $id . '"><strong>' . esc_html( $def['label'] ) . '</strong></label><br>';
            echo '<input type="' . esc_attr( $def['type'] ) . '" name="' . $id . '" id="' . $id . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $def['placeholder'] ) . '" style="width:100%">';
            echo '</p>';
        }
        echo '</div>';

        echo '<p class="description">Reviews are managed under Vendor Approvals.</p>';
    }

    public static function save_contact_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ptk_vendor_contact_nonce'] ) || ! wp_verify_nonce( $_POST['ptk_vendor_contact_nonce'], 'ptk_vendor_contact' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['ptk_vendor_phone'] ) ) {
            update_post_meta( $post_id, 'ptk_vendor_phone', sanitize_text_field( wp_unslash( $_POST['ptk_vendor_phone'] ) ) );
        }
        if ( isset( $_POST['ptk_vendor_email'] ) ) {
            update_post_meta( $post_id, 'ptk_vendor_email', sanitize_email( wp_unslash( $_POST['ptk_vendor_email'] ) ) );
        }
        if ( isset( $_POST['ptk_vendor_website'] ) ) {
            update_post_meta( $post_id, 'ptk_vendor_website', esc_url_raw( wp_unslash( $_POST['ptk_vendor_website'] ) ) );
        }
    }

    /* -------------------------------------------------------------- */
    /*  Cache invalidation (spec: Caching)                            */
    /* -------------------------------------------------------------- */

    /**
     * save_post_ptk_vendor: any vendor edit in wp-admin invalidates the
     * network-wide vendor cache version.
     */
    public static function bump_cache_on_change( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        PTK_Network_Provisioning::bump_cache_version();
    }

    /**
     * trashed_post / before_delete_post fire for every post type —
     * only vendors need a cache bump.
     */
    public static function bump_cache_on_trash_delete( $post_id ) {
        if ( get_post_type( $post_id ) !== 'ptk_vendor' ) {
            return;
        }
        PTK_Network_Provisioning::bump_cache_version();
    }

    /* -------------------------------------------------------------- */
    /*  Directory page ensure                                         */
    /* -------------------------------------------------------------- */

    /**
     * Create this site's Vendor Directory page if missing.
     * Stub — filled in by v3.0 plan Task 4.
     */
    public static function ensure_directory_page() {}
}
