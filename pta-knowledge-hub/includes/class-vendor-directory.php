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

    /** Category tile emoji by term slug (spec: Member experience). */
    private static $category_emoji = array(
        'food-catering'    => '🍕',
        'entertainment'    => '🎉',
        'printing-apparel' => '👕',
        'fundraising'      => '💰',
        'event-supplies'   => '🎪',
        'photography'      => '📸',
        'other-services'   => '🔧',
    );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_content_types' ) );

        // Member-facing pieces run on EVERY site — subsites render the
        // shared directory locally and members suggest vendors from there.
        add_shortcode( 'pta_vendors', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_ajax_ptk_suggest_vendor', array( __CLASS__, 'handle_suggest' ) );
        // NO nopriv registration — members-only by construction.

        // The shortcode draws its own centered header, so the theme's page
        // title above it is redundant — hide it on the directory page only.
        add_filter( 'the_title', array( __CLASS__, 'hide_directory_page_title' ), 10, 2 );
        add_action( 'wp_head', array( __CLASS__, 'directory_page_title_css' ) );

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
    /*  Redundant page-title suppression                              */
    /* -------------------------------------------------------------- */

    /** Per-request memo: does a given page hold the [pta_vendors] shortcode? */
    private static $vendor_page_cache = array();

    /**
     * True if the page's content contains the [pta_vendors] shortcode.
     *
     * @param int $post_id Page ID.
     * @return bool
     */
    private static function page_has_vendor_shortcode( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! isset( self::$vendor_page_cache[ $post_id ] ) ) {
            $content = get_post_field( 'post_content', $post_id );
            self::$vendor_page_cache[ $post_id ] =
                ( is_string( $content ) && has_shortcode( $content, 'pta_vendors' ) );
        }
        return self::$vendor_page_cache[ $post_id ];
    }

    /**
     * Blank the theme's displayed page title on the Vendor Directory page.
     *
     * Tightly scoped: front-end only, main query, in the loop, and only the
     * queried page's OWN title when that page holds the shortcode. Never
     * affects the browser tab title, menus, breadcrumbs, admin, or any other
     * page — those don't satisfy all four guards.
     *
     * @param string $title   The title.
     * @param int    $post_id The post ID (WP passes this on the front end).
     * @return string
     */
    public static function hide_directory_page_title( $title, $post_id = 0 ) {
        if ( is_admin() || ! $post_id || ! is_main_query() || ! in_the_loop() ) {
            return $title;
        }
        if ( (int) get_queried_object_id() === (int) $post_id
            && self::page_has_vendor_shortcode( $post_id ) ) {
            return '';
        }
        return $title;
    }

    /**
     * Belt-and-suspenders: some themes still render an (now empty) title
     * element, leaving a gap or a stray border. Hide the common title
     * containers on the directory page. Printed ONLY on that page, so the
     * blast radius is a single page; our own header uses .ptk-vd-* classes
     * and is untouched.
     */
    public static function directory_page_title_css() {
        if ( is_admin() || ! is_page() || ! is_main_query() ) {
            return;
        }
        if ( ! self::page_has_vendor_shortcode( get_queried_object_id() ) ) {
            return;
        }
        echo "<style id=\"ptk-vd-hide-title\">.entry-title,.page-title,.wp-block-post-title,header.entry-header{display:none!important}.ptk-vd-wrap .ptk-vd-title{display:block!important}</style>\n";
    }

    /* -------------------------------------------------------------- */
    /*  Directory page ensure                                         */
    /* -------------------------------------------------------------- */

    /**
     * Create this site's Vendor Directory page if missing.
     * (Mirrors the Knowledge Base page block in ptk_activate().)
     */
    public static function ensure_directory_page() {
        if ( get_page_by_path( 'vendors' ) ) {
            return;
        }
        wp_insert_post( array(
            'post_title'   => 'Vendor Directory',
            'post_name'    => 'vendors',
            'post_content' => '<!-- wp:shortcode -->[pta_vendors]<!-- /wp:shortcode -->',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
    }

    /**
     * Backfill slugs for published vendors that have none.
     *
     * Vendors approved before v3.0.1 went live via wp_publish_post(), which
     * transitions status without generating a slug — leaving pending-created
     * vendors with an empty post_name and a dead ?vendor= directory link.
     * wp_update_post() re-runs the full pipeline, which fills post_name.
     * Runs on the main site from provisioning; idempotent and cheap (only
     * touches rows whose post_name is empty).
     */
    public static function repair_missing_slugs() {
        $vendors = get_posts( array(
            'post_type'      => 'ptk_vendor',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );
        $repaired = false;
        foreach ( $vendors as $vendor ) {
            if ( '' === $vendor->post_name ) {
                wp_update_post( array( 'ID' => $vendor->ID ) );
                $repaired = true;
            }
        }
        if ( $repaired ) {
            PTK_Network_Provisioning::bump_cache_version();
        }
    }

    /* -------------------------------------------------------------- */
    /*  Cached cross-site data layer (spec: Cross-site reads, Caching) */
    /* -------------------------------------------------------------- */

    /**
     * Register the CPT + taxonomy on demand inside a main-site context.
     *
     * On subsite requests registration never ran (it is Council-only and
     * per-request, not per-site), so after switch_to_blog( main ) the types
     * are still unknown. register_content_types()'s own guard passes here
     * because PTK_Multisite::is_main_site() checks the CURRENT blog.
     */
    private static function ensure_types_registered() {
        if ( ! post_type_exists( 'ptk_vendor' ) || ! taxonomy_exists( 'vendor_category' ) ) {
            self::register_content_types();
        }
    }

    /** Stats shape for vendors get_all_stats() omits (zero approved reviews). */
    private static function zero_stats() {
        return array(
            'review_count'           => 0,
            'avg_price'              => 0,
            'avg_quality'            => 0,
            'reviewer_recommend_pct' => 0,
            'ptas_total'             => 0,
            'ptas_recommend'         => 0,
        );
    }

    /**
     * The full directory payload: published vendors (with category, contact
     * meta, and stats) ranked by reviewer recommend-%, plus all categories.
     *
     * Cached per-site for an hour under a version-keyed transient. The
     * payload contains ONLY approved data, identical for every member.
     * Old-version transients expire naturally (1h TTL).
     *
     * @return array { vendors: array, categories: slug => { name, count } }
     */
    public static function get_directory_data() {
        // Login gate BEFORE cache read — callers must have checked already;
        // this is the defensive backstop (spec: Access gate).
        if ( ! is_user_logged_in() ) {
            return array( 'vendors' => array(), 'categories' => array() );
        }

        $key  = 'ptk_vendors_' . PTK_Network_Provisioning::get_cache_version();
        $data = get_transient( $key );
        if ( is_array( $data ) ) {
            return $data;
        }

        $data = PTK_Network_Provisioning::on_main_site( function() {
            self::ensure_types_registered();

            // All categories (including empty — the suggest form offers
            // every term, tiles only render non-empty ones).
            $categories = array();
            $terms      = get_terms( array(
                'taxonomy'   => 'vendor_category',
                'hide_empty' => false,
            ) );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $categories[ $term->slug ] = array(
                        'name'  => $term->name,
                        'count' => 0,
                    );
                }
            }

            $vendors = array();
            $posts   = get_posts( array(
                'post_type'   => 'ptk_vendor',
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'title',
                'order'       => 'ASC',
            ) );
            foreach ( $posts as $post ) {
                $cat_slug   = '';
                $cat_name   = '';
                $post_terms = get_the_terms( $post, 'vendor_category' );
                if ( $post_terms && ! is_wp_error( $post_terms ) ) {
                    $cat_slug = $post_terms[0]->slug;
                    $cat_name = $post_terms[0]->name;
                    if ( isset( $categories[ $cat_slug ] ) ) {
                        $categories[ $cat_slug ]['count']++;
                    }
                }
                $vendors[] = array(
                    'id'            => $post->ID,
                    'name'          => $post->post_title,
                    'slug'          => $post->post_name,
                    'category_slug' => $cat_slug,
                    'category_name' => $cat_name,
                    'phone'         => get_post_meta( $post->ID, 'ptk_vendor_phone', true ),
                    'email'         => get_post_meta( $post->ID, 'ptk_vendor_email', true ),
                    'website'       => get_post_meta( $post->ID, 'ptk_vendor_website', true ),
                );
            }

            return array( 'vendors' => $vendors, 'categories' => $categories );
        } );

        // Merge stats. The reviews table is base_prefix-shared, readable
        // from any site context — no blog switch needed.
        $all_stats = PTK_Vendor_Reviews::get_all_stats();
        foreach ( $data['vendors'] as &$vendor ) {
            $vendor['stats'] = isset( $all_stats[ $vendor['id'] ] )
                ? $all_stats[ $vendor['id'] ]
                : self::zero_stats();
        }
        unset( $vendor );

        // Rank by reviewer-level recommend-% (spec: card ranking), then
        // review count, then name.
        usort( $data['vendors'], function( $a, $b ) {
            if ( $a['stats']['reviewer_recommend_pct'] !== $b['stats']['reviewer_recommend_pct'] ) {
                return $b['stats']['reviewer_recommend_pct'] - $a['stats']['reviewer_recommend_pct'];
            }
            if ( $a['stats']['review_count'] !== $b['stats']['review_count'] ) {
                return $b['stats']['review_count'] - $a['stats']['review_count'];
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        set_transient( $key, $data, HOUR_IN_SECONDS );
        return $data;
    }

    /**
     * One vendor's detail payload: fields + approved reviews + stats.
     * Cached like the directory. Attribution and the viewer's own pending
     * review are NEVER cached — both are resolved at render time.
     *
     * @return array|null Null when no published vendor matches the slug.
     */
    public static function get_vendor_detail( $slug ) {
        // Login gate BEFORE cache read (spec: Access gate).
        if ( ! is_user_logged_in() || '' === $slug ) {
            return null;
        }

        $key  = 'ptk_vendor_' . $slug . '_' . PTK_Network_Provisioning::get_cache_version();
        $data = get_transient( $key );
        if ( is_array( $data ) ) {
            return $data;
        }

        $vendor = PTK_Network_Provisioning::on_main_site( function() use ( $slug ) {
            self::ensure_types_registered();

            $posts = get_posts( array(
                'post_type'   => 'ptk_vendor',
                'post_status' => 'publish',
                'name'        => $slug,
                'numberposts' => 1,
            ) );
            if ( ! $posts ) {
                return null;
            }
            $post = $posts[0];

            $cat_slug   = '';
            $cat_name   = '';
            $post_terms = get_the_terms( $post, 'vendor_category' );
            if ( $post_terms && ! is_wp_error( $post_terms ) ) {
                $cat_slug = $post_terms[0]->slug;
                $cat_name = $post_terms[0]->name;
            }

            return array(
                'id'            => $post->ID,
                'name'          => $post->post_title,
                'slug'          => $post->post_name,
                'category_slug' => $cat_slug,
                'category_name' => $cat_name,
                'phone'         => get_post_meta( $post->ID, 'ptk_vendor_phone', true ),
                'email'         => get_post_meta( $post->ID, 'ptk_vendor_email', true ),
                'website'       => get_post_meta( $post->ID, 'ptk_vendor_website', true ),
            );
        } );

        if ( ! $vendor ) {
            return null; // Misses are cheap and rare — not worth caching.
        }

        $data = array(
            'vendor'  => $vendor,
            'reviews' => PTK_Vendor_Reviews::get_approved_reviews( $vendor['id'] ),
            'stats'   => PTK_Vendor_Reviews::get_vendor_stats( $vendor['id'] ),
        );

        set_transient( $key, $data, HOUR_IN_SECONDS );
        return $data;
    }

    /* -------------------------------------------------------------- */
    /*  [pta_vendors] shortcode + routing (spec: Routing)             */
    /* -------------------------------------------------------------- */

    public static function render_shortcode() {
        // Hard login gate, independent of ptk_require_login. Deliberately
        // NOT ptk_check_access() — that returns true for everyone when the
        // hub-wide login setting is off (spec: Access gate).
        if ( ! is_user_logged_in() ) {
            return self::render_login_prompt();
        }

        self::enqueue_assets();

        $vendor_slug = isset( $_GET['vendor'] ) ? sanitize_title( wp_unslash( $_GET['vendor'] ) ) : '';

        ob_start();
        if ( '' !== $vendor_slug ) {
            $detail = self::get_vendor_detail( $vendor_slug );
            if ( $detail ) {
                self::render_detail( $detail );
            } else {
                self::render_not_found();
            }
        } else {
            self::render_directory( self::get_directory_data() );
        }
        return ob_get_clean();
    }

    /**
     * Members-Only prompt with vendor copy. Markup copied from
     * ptk_check_access() in pta-knowledge-hub.php — the function itself is
     * deliberately not called (see render_shortcode()).
     */
    private static function render_login_prompt() {
        $login_url = wp_login_url( get_permalink() );
        ob_start();
        ?>
        <style>
            .ptk-login-required{text-align:center;max-width:480px;margin:60px auto;padding:48px 32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
            .ptk-login-icon{font-size:48px;margin-bottom:12px}
            .ptk-login-required h2{font-size:22px;font-weight:700;color:#111827;margin:0 0 8px}
            .ptk-login-required p{font-size:15px;color:#6b7280;line-height:1.6;margin:0 0 24px}
            .ptk-login-btn{display:inline-block;background:#4f46e5;color:#fff!important;text-decoration:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;transition:background .15s}
            .ptk-login-btn:hover{background:#4338ca;color:#fff!important}
        </style>
        <div class="ptk-login-required">
            <div class="ptk-login-icon">&#128274;</div>
            <h2>Members Only</h2>
            <p>The Vendor Directory is for PTA members. Please log in to see vendor reviews from all our PTAs.</p>
            <a href="<?php echo esc_url( $login_url ); ?>" class="ptk-login-btn">Log In</a>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function enqueue_assets() {
        wp_enqueue_style(
            'ptk-vendor-directory',
            PTK_PLUGIN_URL . 'assets/css/vendor-directory.css',
            array(),
            PTK_VERSION
        );
        wp_enqueue_script(
            'ptk-vendor-directory',
            PTK_PLUGIN_URL . 'assets/js/vendor-directory.js',
            array(),
            PTK_VERSION,
            true
        );

        wp_localize_script( 'ptk-vendor-directory', 'ptkVendors', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ptk_vendor_nonce' ),
        ) );
    }

    /* -------------------------------------------------------------- */
    /*  Rendering helpers                                             */
    /* -------------------------------------------------------------- */

    /**
     * Read-only star row with an aria-label ("Price: 4.2 of 5 stars").
     */
    private static function stars_markup( $value, $label ) {
        $value   = (float) $value;
        $rounded = max( 0, min( 5, (int) round( $value ) ) );

        $aria = $value > 0
            ? sprintf( '%s: %s of 5 stars', $label, $value )
            : sprintf( '%s: no ratings yet', $label );

        $out = '<span class="ptk-vd-stars-display" role="img" aria-label="' . esc_attr( $aria ) . '">';
        for ( $i = 1; $i <= 5; $i++ ) {
            $out .= '<span class="' . ( $i <= $rounded ? 'ptk-vd-star-on' : 'ptk-vd-star-off' ) . '" aria-hidden="true">&#9733;</span>';
        }
        $out .= '</span>';
        return $out;
    }

    /**
     * The ONE plain-English verdict line (spec: no-overload principle).
     */
    private static function verdict_line( $stats, $detail = false ) {
        if ( (int) $stats['review_count'] < 1 ) {
            return 'No reviews yet';
        }
        if ( 1 === (int) $stats['ptas_total'] ) {
            return 'Used by 1 PTA';
        }
        return sprintf(
            $detail ? '%1$d of %2$d PTAs would use them again' : '%1$d of %2$d PTAs would use again',
            (int) $stats['ptas_recommend'],
            (int) $stats['ptas_total']
        );
    }

    /** "3 days ago" from a GMT MySQL datetime. */
    private static function relative_date( $mysql_gmt ) {
        $ts = strtotime( $mysql_gmt . ' UTC' );
        if ( ! $ts || $ts > time() ) {
            return 'just now';
        }
        return human_time_diff( $ts, time() ) . ' ago';
    }

    /* -------------------------------------------------------------- */
    /*  Directory view (mockup: directory-layout option B + search)   */
    /* -------------------------------------------------------------- */

    private static function render_directory( $data ) {
        $vendors    = $data['vendors'];
        $categories = $data['categories'];
        ?>
        <div class="ptk-vd-wrap">
            <div class="ptk-vd-hero">
                <h2 class="ptk-vd-title">Vendor Directory</h2>
                <p class="ptk-vd-subtitle">Vendors our PTAs have actually used — with honest reviews from every school.</p>
            </div>

            <div class="ptk-vd-search-box">
                <input type="search" id="ptk-vd-search" class="ptk-vd-search"
                       aria-label="Search vendors"
                       placeholder="&#128269;&nbsp; Search vendors&hellip; e.g. pizza, DJ, t-shirts">
            </div>

            <?php
            $tiles = array_filter( $categories, function( $cat ) {
                return $cat['count'] > 0;
            } );
            if ( $tiles ) :
                ?>
                <div class="ptk-vd-tiles" role="group" aria-label="Filter vendors by category">
                    <?php foreach ( $tiles as $slug => $cat ) :
                        $emoji = isset( self::$category_emoji[ $slug ] ) ? self::$category_emoji[ $slug ] : '🔧';
                        ?>
                        <button type="button" class="ptk-vd-tile"
                                data-category="<?php echo esc_attr( $slug ); ?>" aria-pressed="false">
                            <span class="ptk-vd-tile-emoji" aria-hidden="true"><?php echo esc_html( $emoji ); ?></span>
                            <span class="ptk-vd-tile-name"><?php echo esc_html( $cat['name'] ); ?></span>
                            <span class="ptk-vd-tile-count"><?php echo esc_html( $cat['count'] . ( 1 === $cat['count'] ? ' vendor' : ' vendors' ) ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $vendors ) : ?>
                <div class="ptk-vd-cards">
                    <?php foreach ( $vendors as $vendor ) :
                        $stats    = $vendor['stats'];
                        $combined = $stats['review_count'] ? round( ( $stats['avg_price'] + $stats['avg_quality'] ) / 2, 1 ) : 0;
                        $count    = (int) $stats['review_count'];
                        ?>
                        <a class="ptk-vd-card"
                           href="<?php echo esc_url( add_query_arg( 'vendor', $vendor['slug'] ) ); ?>"
                           data-category="<?php echo esc_attr( $vendor['category_slug'] ); ?>"
                           data-search="<?php echo esc_attr( mb_strtolower( $vendor['name'] . ' ' . $vendor['category_name'] ) ); ?>">
                            <span class="ptk-vd-card-name"><?php echo esc_html( $vendor['name'] ); ?></span>
                            <span class="ptk-vd-card-stars">
                                <?php echo self::stars_markup( $combined, 'Average rating' ); // phpcs:ignore -- escaped inside ?>
                            </span>
                            <span class="ptk-vd-card-verdict"><?php echo esc_html( self::verdict_line( $stats ) ); ?></span>
                            <span class="ptk-vd-card-count"><?php echo esc_html( $count . ( 1 === $count ? ' review' : ' reviews' ) ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="ptk-vd-empty ptk-vd-empty-search" hidden>
                    <p class="ptk-vd-empty-title">No vendors match your search.</p>
                    <button type="button" class="ptk-vd-clear-search ptk-vd-btn-secondary">Clear search</button>
                </div>
            <?php else : ?>
                <div class="ptk-vd-empty">
                    <p class="ptk-vd-empty-title">No vendors yet.</p>
                    <p class="ptk-vd-empty-text">Be the first to suggest one — the button below is waiting.</p>
                </div>
            <?php endif; ?>

            <div class="ptk-vd-suggest-section">
                <button type="button" class="ptk-vd-reveal ptk-vd-btn-primary"
                        data-target="ptk-vd-suggest-form">&#9997;&#65039; Suggest a vendor</button>
                <?php self::render_suggest_form( $categories ); ?>
            </div>
        </div>
        <?php
    }

    /* -------------------------------------------------------------- */
    /*  Detail view (mockup: vendor-detail option B, two-column)      */
    /* -------------------------------------------------------------- */

    private static function render_not_found() {
        $back_url = remove_query_arg( 'vendor' );
        ?>
        <div class="ptk-vd-wrap">
            <div class="ptk-vd-empty">
                <p class="ptk-vd-empty-title">That vendor isn't in the directory.</p>
                <p class="ptk-vd-empty-text">It may still be awaiting approval by the PTA Council.</p>
                <p><a class="ptk-vd-back-link" href="<?php echo esc_url( $back_url ); ?>">&larr; All vendors</a></p>
            </div>
        </div>
        <?php
    }

    private static function render_detail( $detail ) {
        $vendor  = $detail['vendor'];
        $stats   = $detail['stats'];
        $reviews = $detail['reviews'];

        // The viewer's own review — per-request, NEVER cached (spec: Caching).
        $own         = PTK_Vendor_Reviews::get_user_review( $vendor['id'], get_current_user_id() );
        $own_pending = ( $own && 'pending' === $own->status );

        $back_url     = remove_query_arg( 'vendor' );
        $review_count = count( $reviews );
        ?>
        <div class="ptk-vd-wrap">
            <p class="ptk-vd-back"><a class="ptk-vd-back-link" href="<?php echo esc_url( $back_url ); ?>">&larr; All vendors</a></p>

            <div class="ptk-vd-detail">
                <div class="ptk-vd-main">
                    <h2 class="ptk-vd-reviews-title">Reviews (<?php echo esc_html( $review_count ); ?>)</h2>

                    <?php if ( $own_pending ) : ?>
                        <?php self::render_review_card( $own, true ); ?>
                    <?php endif; ?>

                    <?php if ( $reviews ) : ?>
                        <?php foreach ( $reviews as $row ) : ?>
                            <?php self::render_review_card( $row, false ); ?>
                        <?php endforeach; ?>
                    <?php elseif ( ! $own_pending ) : ?>
                        <div class="ptk-vd-empty">
                            <p class="ptk-vd-empty-title">No reviews yet — be the first.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="ptk-vd-sidebar">
                    <div class="ptk-vd-sidebar-card">
                        <h2 class="ptk-vd-vendor-name"><?php echo esc_html( $vendor['name'] ); ?></h2>
                        <?php if ( $vendor['category_name'] ) : ?>
                            <span class="ptk-vd-chip"><?php echo esc_html( $vendor['category_name'] ); ?></span>
                        <?php endif; ?>

                        <p class="ptk-vd-verdict"><?php echo esc_html( self::verdict_line( $stats, true ) ); ?></p>

                        <?php if ( $stats['review_count'] > 0 ) : ?>
                            <div class="ptk-vd-avg-row">
                                <span class="ptk-vd-avg-label">Price</span>
                                <?php echo self::stars_markup( $stats['avg_price'], 'Price' ); // phpcs:ignore -- escaped inside ?>
                            </div>
                            <div class="ptk-vd-avg-row">
                                <span class="ptk-vd-avg-label">Quality</span>
                                <?php echo self::stars_markup( $stats['avg_quality'], 'Quality' ); // phpcs:ignore -- escaped inside ?>
                            </div>
                        <?php endif; ?>

                        <?php
                        $contacts = array();
                        if ( $vendor['phone'] ) {
                            $tel        = preg_replace( '/[^0-9+]/', '', $vendor['phone'] );
                            $contacts[] = '<li>&#128222; <a href="' . esc_url( 'tel:' . $tel ) . '">' . esc_html( $vendor['phone'] ) . '</a></li>';
                        }
                        if ( $vendor['email'] ) {
                            $contacts[] = '<li>&#9993;&#65039; <a href="' . esc_url( 'mailto:' . $vendor['email'] ) . '">' . esc_html( $vendor['email'] ) . '</a></li>';
                        }
                        if ( $vendor['website'] ) {
                            $display    = preg_replace( '#^https?://#i', '', untrailingslashit( $vendor['website'] ) );
                            $contacts[] = '<li>&#127760; <a href="' . esc_url( $vendor['website'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $display ) . '</a></li>';
                        }
                        if ( $contacts ) {
                            echo '<ul class="ptk-vd-contacts">' . implode( '', $contacts ) . '</ul>'; // Each row escaped above.
                        }
                        ?>

                        <button type="button" class="ptk-vd-reveal ptk-vd-btn-primary ptk-vd-write-btn"
                                data-target="ptk-vd-review-form">&#9997;&#65039; <?php echo $own ? 'Update my review' : 'Write a review'; ?></button>
                    </div>

                    <?php self::render_review_form( $vendor['id'], $own ); ?>
                </aside>
            </div>
        </div>
        <?php
    }

    /** One review card. $pending renders the viewer's own amber-chip card. */
    private static function render_review_card( $row, $pending ) {
        $att       = PTK_Vendor_Reviews::format_attribution( $row );
        $recommend = (int) $row->recommend;
        ?>
        <article class="ptk-vd-review<?php echo $pending ? ' ptk-vd-review-pending' : ''; ?>">
            <header class="ptk-vd-review-head">
                <strong><?php echo esc_html( $att['pta'] . ' · ' . $att['name'] ); ?></strong>
                <?php if ( $pending ) : ?>
                    <span class="ptk-vd-pending-chip">Waiting for Council approval</span>
                <?php endif; ?>
            </header>
            <p class="ptk-vd-review-verdict <?php echo $recommend ? 'ptk-vd-verdict-yes' : 'ptk-vd-verdict-no'; ?>">
                <?php echo $recommend ? '&#128077; Would use again' : '&#128078; Would not use again'; ?>
            </p>
            <p class="ptk-vd-review-stars">
                <span class="ptk-vd-review-stars-label">Price</span>
                <?php echo self::stars_markup( (int) $row->price_rating, 'Price' ); // phpcs:ignore -- escaped inside ?>
                <span aria-hidden="true">&middot;</span>
                <span class="ptk-vd-review-stars-label">Quality</span>
                <?php echo self::stars_markup( (int) $row->quality_rating, 'Quality' ); // phpcs:ignore -- escaped inside ?>
            </p>
            <p class="ptk-vd-review-comment"><?php echo esc_html( $row->comment ); ?></p>
            <p class="ptk-vd-review-date"><?php echo esc_html( self::relative_date( $row->created_at ) ); ?></p>
        </article>
        <?php
    }

    /* -------------------------------------------------------------- */
    /*  Forms (mockup: review-form ✓; Task 5)                          */
    /* -------------------------------------------------------------- */

    /** "Posting as Maria G. · Hillside PTA (from your account)" banner. */
    private static function render_posting_as() {
        $user = wp_get_current_user();
        $name = $user->display_name ? $user->display_name : 'A PTA member';
        ?>
        <div class="ptk-vd-posting-as">
            Posting as <strong><?php echo esc_html( $name . ' · ' . get_bloginfo( 'name' ) ); ?></strong> (from your account)
        </div>
        <?php
    }

    /**
     * The shared review field block: thumbs, Price stars, Quality stars,
     * comment + live counter. Used by both the review form and the suggest
     * form (never on the same page, so the fixed input names are safe).
     *
     * @param string      $prefix  Unique id prefix ('review'|'suggest').
     * @param object|null $prefill The viewer's existing review row, if any.
     */
    private static function render_review_fields( $prefix, $prefill = null ) {
        $recommend = ( $prefill && '' !== $prefill->recommend ) ? (string) (int) $prefill->recommend : '';
        $price     = $prefill ? (int) $prefill->price_rating : 0;
        $quality   = $prefill ? (int) $prefill->quality_rating : 0;
        $comment   = $prefill ? $prefill->comment : '';
        ?>
        <fieldset class="ptk-vd-thumbs">
            <legend class="ptk-vd-field-label"><strong>Would you use them again?</strong></legend>
            <input type="hidden" name="ptk_recommend" value="<?php echo esc_attr( $recommend ); ?>">
            <div class="ptk-vd-thumbs-row">
                <button type="button" class="ptk-vd-thumb" data-value="1"
                        aria-pressed="<?php echo '1' === $recommend ? 'true' : 'false'; ?>">&#128077; Yes, would use again</button>
                <button type="button" class="ptk-vd-thumb" data-value="0"
                        aria-pressed="<?php echo '0' === $recommend ? 'true' : 'false'; ?>">&#128078; No, would not</button>
            </div>
        </fieldset>

        <?php
        $star_fields = array(
            'ptk_price'   => array( 'label' => 'Price', 'hint' => '(1 = overpriced, 5 = great deal)', 'value' => $price ),
            'ptk_quality' => array( 'label' => 'Quality', 'hint' => '(how happy were you with what you got?)', 'value' => $quality ),
        );
        foreach ( $star_fields as $name => $field ) :
            $value = $field['value'];
            $aria  = $value
                ? sprintf( '%s: %d of 5 stars', $field['label'], $value )
                : sprintf( '%s: not rated yet', $field['label'] );
            ?>
            <div class="ptk-vd-star-field">
                <p class="ptk-vd-field-label" id="<?php echo esc_attr( $prefix . '-' . $name . '-label' ); ?>">
                    <strong><?php echo esc_html( $field['label'] ); ?></strong>
                    <span class="ptk-vd-field-hint"><?php echo esc_html( $field['hint'] ); ?></span>
                </p>
                <div class="ptk-vd-stars" role="group"
                     data-label="<?php echo esc_attr( $field['label'] ); ?>"
                     aria-label="<?php echo esc_attr( $aria ); ?>">
                    <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ? $value : '' ); ?>">
                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                        <button type="button"
                                class="ptk-vd-star<?php echo $value && $i <= $value ? ' ptk-vd-star-on' : ''; ?>"
                                data-value="<?php echo esc_attr( $i ); ?>"
                                aria-pressed="<?php echo $i === $value ? 'true' : 'false'; ?>"
                                aria-label="<?php echo esc_attr( sprintf( '%s: %d of 5 stars', $field['label'], $i ) ); ?>">&#9733;</button>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <p class="ptk-vd-field">
            <label for="<?php echo esc_attr( $prefix . '-comment' ); ?>" class="ptk-vd-field-label">
                <strong>What service did they provide, and how did it go?</strong>
            </label>
            <textarea id="<?php echo esc_attr( $prefix . '-comment' ); ?>" name="ptk_comment" rows="4"
                      maxlength="2000" required
                      placeholder="e.g. Catered our Fall Fest — 40 pizzas, on time, gave us a PTA discount…"><?php echo esc_textarea( $comment ); ?></textarea>
            <span class="ptk-vd-count" data-target="<?php echo esc_attr( $prefix . '-comment' ); ?>" data-max="2000">0 / 2000</span>
        </p>
        <?php
    }

    /** Off-screen honeypot — bots fill the hidden "website" field. */
    private static function render_honeypot( $prefix ) {
        ?>
        <p class="ptk-vd-hp" style="position:absolute;left:-9999px;" aria-hidden="true">
            <label for="<?php echo esc_attr( $prefix . '-hp' ); ?>">Website</label>
            <input type="text" id="<?php echo esc_attr( $prefix . '-hp' ); ?>" name="vendor_website_hp" tabindex="-1" autocomplete="off">
        </p>
        <?php
    }

    /**
     * The write/update review form (hidden until the reveal button).
     * Pre-fills from the viewer's existing review; the button becomes
     * "Update my review" (one review per member per vendor).
     */
    private static function render_review_form( $vendor_id, $user_review ) {
        ?>
        <form id="ptk-vd-review-form" class="ptk-vd-form ptk-vd-review-form"
              data-action="ptk_submit_vendor_review" hidden novalidate>
            <h3 class="ptk-vd-form-title"><?php echo $user_review ? 'Update your review' : 'Write a review'; ?></h3>
            <input type="hidden" name="vendor_id" value="<?php echo esc_attr( $vendor_id ); ?>">

            <?php self::render_posting_as(); ?>
            <?php self::render_review_fields( 'review', $user_review ); ?>
            <?php self::render_honeypot( 'review' ); ?>

            <p class="ptk-vd-expectation">Your review is checked by the PTA Council before it appears — usually within a few days.</p>
            <div class="ptk-vd-form-error" role="alert" hidden></div>
            <button type="submit" class="ptk-vd-btn-primary ptk-vd-submit"><?php echo $user_review ? 'Update my review' : 'Send review'; ?></button>
        </form>
        <?php
    }

    /**
     * The suggest-a-vendor form: vendor basics + the SAME review block
     * (a vendor with zero reviews isn't useful — spec: Member experience 4).
     */
    private static function render_suggest_form( $categories ) {
        ?>
        <form id="ptk-vd-suggest-form" class="ptk-vd-form ptk-vd-suggest-form"
              data-action="ptk_suggest_vendor" hidden novalidate>
            <h3 class="ptk-vd-form-title">Suggest a vendor</h3>

            <?php self::render_posting_as(); ?>

            <p class="ptk-vd-field">
                <label for="suggest-vendor-name" class="ptk-vd-field-label"><strong>Vendor name</strong></label>
                <input type="text" id="suggest-vendor-name" name="vendor_name" maxlength="120" required
                       placeholder="e.g. John's Pizza">
            </p>

            <p class="ptk-vd-field">
                <label for="suggest-vendor-category" class="ptk-vd-field-label"><strong>Category</strong></label>
                <select id="suggest-vendor-category" name="vendor_category" required>
                    <option value="">Pick a category&hellip;</option>
                    <?php foreach ( $categories as $slug => $cat ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $cat['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="ptk-vd-contact-note">How can PTAs reach them? At least one is required.</p>
            <p class="ptk-vd-field">
                <label for="suggest-vendor-phone" class="ptk-vd-field-label"><strong>Phone</strong> <span class="ptk-vd-field-hint">(optional)</span></label>
                <input type="tel" id="suggest-vendor-phone" name="vendor_phone" maxlength="40" placeholder="(555) 555-0100">
            </p>
            <p class="ptk-vd-field">
                <label for="suggest-vendor-email" class="ptk-vd-field-label"><strong>Email</strong> <span class="ptk-vd-field-hint">(optional)</span></label>
                <input type="email" id="suggest-vendor-email" name="vendor_email" maxlength="200" placeholder="name@example.com">
            </p>
            <p class="ptk-vd-field">
                <label for="suggest-vendor-website" class="ptk-vd-field-label"><strong>Website</strong> <span class="ptk-vd-field-hint">(optional)</span></label>
                <input type="url" id="suggest-vendor-website" name="vendor_website" maxlength="200" placeholder="https://">
            </p>

            <h4 class="ptk-vd-form-subtitle">Your review</h4>
            <p class="ptk-vd-field-hint">A vendor with zero reviews isn't useful — tell everyone how it went.</p>
            <?php self::render_review_fields( 'suggest' ); ?>
            <?php self::render_honeypot( 'suggest' ); ?>

            <p class="ptk-vd-expectation">Your suggestion is checked by the PTA Council before it appears — usually within a few days.</p>
            <div class="ptk-vd-form-error" role="alert" hidden></div>
            <button type="submit" class="ptk-vd-btn-primary ptk-vd-submit">Send suggestion</button>
        </form>
        <?php
    }

    /* -------------------------------------------------------------- */
    /*  Suggest-a-vendor AJAX (spec: Member experience item 4)        */
    /* -------------------------------------------------------------- */

    /**
     * Atomically create a pending vendor + its bundled pending first review.
     * The bundled review is the SOLE exception to the "reviews only against
     * publish vendors" rule — it calls save_review() directly, never
     * PTK_Vendor_Reviews::handle_submit().
     */
    public static function handle_suggest() {
        // 1. Nonce.
        check_ajax_referer( 'ptk_vendor_nonce', '_wpnonce' );

        // 2. Members only.
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Please log in to suggest a vendor.' ), 403 );
        }

        // 3. Honeypot — pretend success so the bot moves on.
        if ( ! empty( $_POST['vendor_website_hp'] ) ) {
            wp_send_json_success( array( 'message' => 'Thanks!' ) );
        }

        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id(); // Captured BEFORE any blog switch.

        // 4. Vendor fields.
        $name = isset( $_POST['vendor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['vendor_name'] ) ) : '';
        if ( '' === trim( $name ) ) {
            wp_send_json_error( array( 'message' => 'Please enter the vendor\'s name.' ) );
        }
        if ( mb_strlen( $name ) > 120 ) {
            wp_send_json_error( array( 'message' => 'The vendor name is a little long — please keep it under 120 characters.' ) );
        }
        if ( preg_match( '#https?://#i', $name ) ) {
            wp_send_json_error( array( 'message' => 'Please use plain text for the vendor name (no URLs).' ) );
        }

        $category = isset( $_POST['vendor_category'] ) ? sanitize_title( wp_unslash( $_POST['vendor_category'] ) ) : '';
        if ( '' === $category ) {
            wp_send_json_error( array( 'message' => 'Please pick a category.' ) );
        }

        $phone   = isset( $_POST['vendor_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['vendor_phone'] ) ) : '';
        $email   = isset( $_POST['vendor_email'] ) ? sanitize_email( wp_unslash( $_POST['vendor_email'] ) ) : '';
        $website = isset( $_POST['vendor_website'] ) ? esc_url_raw( wp_unslash( $_POST['vendor_website'] ) ) : '';
        if ( '' === $phone && '' === $email && '' === $website ) {
            wp_send_json_error( array( 'message' => 'Please add at least one way to contact them — a phone number, email, or website.' ) );
        }

        // 5. Bundled review fields — same rules as the standalone endpoint.
        $recommend_raw = isset( $_POST['ptk_recommend'] ) ? (string) wp_unslash( $_POST['ptk_recommend'] ) : '';
        if ( '1' !== $recommend_raw && '0' !== $recommend_raw ) {
            wp_send_json_error( array( 'message' => 'Please choose whether you\'d use them again.' ) );
        }
        $recommend = (int) $recommend_raw;

        $price = isset( $_POST['ptk_price'] ) ? absint( $_POST['ptk_price'] ) : 0;
        if ( $price < 1 || $price > 5 ) {
            wp_send_json_error( array( 'message' => 'Please rate Price from 1 to 5 stars.' ) );
        }

        $quality = isset( $_POST['ptk_quality'] ) ? absint( $_POST['ptk_quality'] ) : 0;
        if ( $quality < 1 || $quality > 5 ) {
            wp_send_json_error( array( 'message' => 'Please rate Quality from 1 to 5 stars.' ) );
        }

        $comment = isset( $_POST['ptk_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ptk_comment'] ) ) : '';
        if ( '' === trim( $comment ) ) {
            wp_send_json_error( array( 'message' => 'Please share a few words about your experience.' ) );
        }
        if ( mb_strlen( $comment ) > 2000 ) {
            wp_send_json_error( array( 'message' => 'Your comment is a little long — please keep it under 2,000 characters.' ) );
        }

        // 6. Category must be an existing term — validated BEFORE the rate
        //    limit so a bad pick doesn't burn quota.
        $term_id = (int) PTK_Network_Provisioning::on_main_site( function() use ( $category ) {
            self::ensure_types_registered();
            $term = get_term_by( 'slug', $category, 'vendor_category' );
            return $term ? $term->term_id : 0;
        } );
        if ( ! $term_id ) {
            wp_send_json_error( array( 'message' => 'Please pick one of the listed categories.' ) );
        }

        // 7. Rate limit — same network-wide per-user budget as review
        //    submissions (checked after validation so typos don't burn quota).
        $transient_id = 'ptk_vendor_rate_' . $user_id;
        $count        = (int) get_site_transient( $transient_id );
        if ( $count >= PTK_Vendor_Reviews::RATE_LIMIT ) {
            wp_send_json_error( array( 'message' => 'You\'ve sent a few reviews recently — please try again in an hour.' ) );
        }
        set_site_transient( $transient_id, $count + 1, PTK_Vendor_Reviews::RATE_WINDOW );

        // 8. Pending vendor on the Council site.
        $vendor_id = PTK_Network_Provisioning::on_main_site( function() use ( $name, $term_id, $phone, $email, $website ) {
            self::ensure_types_registered();

            $post_id = wp_insert_post( array(
                'post_type'   => 'ptk_vendor',
                'post_status' => 'pending',
                'post_title'  => $name,
            ), true );
            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            if ( $phone ) {
                update_post_meta( $post_id, 'ptk_vendor_phone', $phone );
            }
            if ( $email ) {
                update_post_meta( $post_id, 'ptk_vendor_email', $email );
            }
            if ( $website ) {
                update_post_meta( $post_id, 'ptk_vendor_website', $website );
            }
            wp_set_object_terms( $post_id, $term_id, 'vendor_category' );

            return $post_id;
        } );

        if ( is_wp_error( $vendor_id ) ) {
            wp_send_json_error( array( 'message' => 'Sorry — your suggestion could not be saved just now. Please try again.' ) );
        }

        // 9. Bundled first review (pending) — direct save_review() call, the
        //    sole exception to the publish check.
        $review_id = PTK_Vendor_Reviews::save_review( $vendor_id, $user_id, $blog_id, $recommend, $price, $quality, $comment, 'pending' );
        if ( ! $review_id ) {
            // Keep the pair atomic: no orphaned review-less pending vendor.
            PTK_Network_Provisioning::on_main_site( function() use ( $vendor_id ) {
                wp_delete_post( $vendor_id, true );
            } );
            wp_send_json_error( array( 'message' => 'Sorry — your suggestion could not be saved just now. Please try again.' ), 500 );
        }

        // 10. A new pending vendor changes nothing public, but the moderation
        //     count + any same-request reads must see fresh data.
        PTK_Network_Provisioning::bump_cache_version();

        // 11. Moderation notification email hooks this (Task 6).
        do_action( 'ptk_vendor_suggested', $vendor_id, $user_id );

        wp_send_json_success( array(
            'message' => sprintf( 'Thanks! %s was sent to the PTA Council for approval.', $name ),
        ) );
    }
}
