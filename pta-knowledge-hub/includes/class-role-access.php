<?php
/**
 * Role-based content visibility for PTA Knowledge entries.
 *
 * Each entry can be restricted to specific WordPress roles.
 * If no roles are set, the entry is visible to everyone (backward compatible).
 * Administrators always see everything regardless of restrictions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Role_Access {

    /** @var string Meta key storing allowed role slugs. */
    const META_KEY = 'ptk_visible_roles';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_meta' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_pta_knowledge', array( __CLASS__, 'save_meta' ), 10, 2 );
    }

    /**
     * Register the meta field.
     */
    public static function register_meta() {
        register_post_meta( 'pta_knowledge', self::META_KEY, array(
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => function () {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }

    /**
     * Add the visibility meta box.
     */
    public static function add_meta_box() {
        add_meta_box(
            'ptk_role_access',
            'Content Visibility',
            array( __CLASS__, 'render_meta_box' ),
            'pta_knowledge',
            'side',
            'default'
        );
    }

    /**
     * Render the meta box with role checkboxes.
     */
    public static function render_meta_box( $post ) {
        wp_nonce_field( 'ptk_role_access', 'ptk_role_access_nonce' );

        $saved_roles = self::get_visible_roles( $post->ID );
        $all_roles   = wp_roles()->get_names();

        ?>
        <p class="description" style="margin-bottom:10px;">
            Leave all unchecked to make this visible to everyone.
        </p>
        <div class="ptk-role-checkboxes" style="max-height:200px;overflow-y:auto;">
            <?php foreach ( $all_roles as $slug => $name ) : ?>
                <label style="display:block;padding:3px 0;font-size:13px;">
                    <input type="checkbox"
                           name="ptk_visible_roles[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                           <?php checked( in_array( $slug, $saved_roles, true ) ); ?>>
                    <?php echo esc_html( translate_user_role( $name ) ); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Save the selected roles on post save.
     */
    public static function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ptk_role_access_nonce'] ) || ! wp_verify_nonce( $_POST['ptk_role_access_nonce'], 'ptk_role_access' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! empty( $_POST['ptk_visible_roles'] ) && is_array( $_POST['ptk_visible_roles'] ) ) {
            $roles = array_map( 'sanitize_text_field', wp_unslash( $_POST['ptk_visible_roles'] ) );
            // Validate against actual WordPress roles.
            $valid_roles = array_keys( wp_roles()->get_names() );
            $roles       = array_values( array_intersect( $roles, $valid_roles ) );
            update_post_meta( $post_id, self::META_KEY, maybe_serialize( $roles ) );
        } else {
            // No roles selected — visible to everyone.
            delete_post_meta( $post_id, self::META_KEY );
        }
    }

    /**
     * Check if the current user can view a specific post.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function can_user_view( $post_id ) {
        $roles = self::get_visible_roles( $post_id );

        // No restrictions — visible to everyone.
        if ( empty( $roles ) ) {
            return true;
        }

        // Not logged in but roles are set — deny.
        if ( ! is_user_logged_in() ) {
            return false;
        }

        // Administrators always see everything.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Check if user has any of the allowed roles.
        $user = wp_get_current_user();
        foreach ( $user->roles as $user_role ) {
            if ( in_array( $user_role, $roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter search results by role access.
     *
     * Removes entries the current user cannot view.
     *
     * @param array $scored_results Array of scored results with 'post' key.
     * @return array Filtered results.
     */
    public static function filter_search_results( $scored_results ) {
        return array_values( array_filter( $scored_results, function ( $entry ) {
            return PTK_Role_Access::can_user_view( $entry['post']->ID );
        } ) );
    }

    /**
     * Render the visibility value in the admin column.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function render_admin_column( $column, $post_id ) {
        if ( 'ptk_visibility' !== $column ) {
            return;
        }

        $roles = self::get_visible_roles( $post_id );

        if ( empty( $roles ) ) {
            echo '<span class="ptk-muted">Everyone</span>';
            return;
        }

        $all_role_names = wp_roles()->get_names();
        $badges = array();
        foreach ( $roles as $slug ) {
            $name = isset( $all_role_names[ $slug ] ) ? translate_user_role( $all_role_names[ $slug ] ) : $slug;
            $badges[] = '<span class="ptk-tag-chip" style="background:#fef3c7;color:#92400e;">' . esc_html( $name ) . '</span>';
        }
        echo implode( ' ', $badges );
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the visible roles for a post.
     *
     * @param int $post_id Post ID.
     * @return array Array of role slugs, or empty array if unrestricted.
     */
    private static function get_visible_roles( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_KEY, true );

        if ( empty( $raw ) ) {
            return array();
        }

        $roles = maybe_unserialize( $raw );

        if ( is_array( $roles ) ) {
            return $roles;
        }

        return array();
    }
}
