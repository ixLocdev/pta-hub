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

        // Quick Edit support.
        add_action( 'quick_edit_custom_box', array( __CLASS__, 'render_quick_edit' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_quick_edit_js' ) );
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

        // Hidden element for Quick Edit JS to read current roles.
        echo '<span class="ptk-role-data" data-roles="' . esc_attr( implode( ',', $roles ) ) . '" style="display:none;"></span>';

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
     * Quick Edit
     * ----------------------------------------------------------------*/

    /**
     * Render the Quick Edit field for content visibility.
     *
     * @param string $column_name Column being rendered.
     * @param string $post_type   Post type.
     */
    public static function render_quick_edit( $column_name, $post_type ) {
        if ( 'ptk_visibility' !== $column_name || 'pta_knowledge' !== $post_type ) {
            return;
        }

        $all_roles = wp_roles()->get_names();
        wp_nonce_field( 'ptk_role_access', 'ptk_role_access_nonce' );
        ?>
        <fieldset class="inline-edit-col-right" style="clear:both;">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title" style="width:auto;font-weight:600;">Content Visibility</span>
                    <span class="description" style="margin-left:0;font-style:normal;color:#6b7280;font-size:12px;">
                        Leave all unchecked = visible to everyone
                    </span>
                </label>
                <div class="ptk-qe-roles" style="margin-top:6px;max-height:120px;overflow-y:auto;">
                    <?php foreach ( $all_roles as $slug => $name ) : ?>
                        <label style="display:block;padding:2px 0;font-size:13px;">
                            <input type="checkbox"
                                   name="ptk_visible_roles[]"
                                   value="<?php echo esc_attr( $slug ); ?>">
                            <?php echo esc_html( translate_user_role( $name ) ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Enqueue inline JS that populates Quick Edit checkboxes
     * with the current row's role data.
     */
    public static function enqueue_quick_edit_js() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-pta_knowledge' !== $screen->id ) {
            return;
        }

        wp_add_inline_script( 'inline-edit-post', '
            (function() {
                var origInlineEdit = inlineEditPost.edit;
                inlineEditPost.edit = function( id ) {
                    origInlineEdit.apply( this, arguments );
                    var postId = 0;
                    if ( typeof id === "object" ) {
                        postId = parseInt( this.getId( id ) );
                    }
                    if ( postId < 1 ) return;

                    var row = document.getElementById( "post-" + postId );
                    if ( ! row ) return;

                    var rolesData = row.querySelector( ".ptk-role-data" );
                    var roles = rolesData ? rolesData.getAttribute( "data-roles" ).split( "," ).filter(Boolean) : [];

                    var editRow = document.getElementById( "edit-" + postId );
                    if ( ! editRow ) return;

                    var checkboxes = editRow.querySelectorAll( "input[name=\\"ptk_visible_roles[]\\"]" );
                    checkboxes.forEach( function( cb ) {
                        cb.checked = roles.indexOf( cb.value ) !== -1;
                    });
                };
            })();
        ' );
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
