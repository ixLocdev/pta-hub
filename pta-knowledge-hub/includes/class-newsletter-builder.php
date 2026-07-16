<?php
/**
 * Newsletter Builder — guided form for creating a newsletter issue.
 *
 * Renders the "Add New" screen for the pta_newsletter post type as a
 * plain-English, section-by-section form instead of the block editor.
 * The fixed fields (Header/Announcement/Featured/Footer) and the
 * repeatable-row placeholders (Events/Story Cards) are rendered
 * server-side with a `data-field` scheme a client-side JS task
 * reads/writes, and a hidden `ptk_nl_blocks` field carries the layout as
 * JSON for handle_submission() to consume on save.
 *
 * handle_submission() renders the blocks to HTML via PTK_Newsletter_Renderer
 * and creates/updates a pta_newsletter post as draft, preview (saved as
 * draft), or published — publishing is gated behind a photo/PII
 * confirmation checkbox in the form; if it's unchecked the save is forced
 * to draft instead.
 *
 * "Add New" and row-list "Edit" are routed through this builder (see
 * init()), and render_page() reconstructs an existing newsletter's exact
 * saved section set + order when opened in edit mode.
 *
 * In edit mode, render_page() also renders a small "share a preview link"
 * panel backed by PTK_Public_Preview — the same no-login token system used
 * for knowledge entries.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-data.php';
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-renderer.php';

class PTK_Newsletter_Builder {

    const PAGE_SLUG = 'ptk-newsletter-builder';

    /** The only theme shipped in Phase 1. */
    const DEFAULT_THEME = 'harbor-navy';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_menu', array( __CLASS__, 'remove_default_add_new' ), 99 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
        add_action( 'load-post-new.php', array( __CLASS__, 'redirect_add_new' ) );
        add_action( 'load-post.php', array( __CLASS__, 'redirect_edit_to_builder' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'add_edit_row_action' ), 10, 2 );
    }

    /**
     * Route the pta_newsletter row-list "Edit" link to the builder instead
     * of the block editor, so reopening a saved newsletter always goes
     * through render_page()'s edit-mode reconstruction.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    The row's post.
     * @return array
     */
    public static function add_edit_row_action( $actions, $post ) {
        if ( 'pta_newsletter' !== $post->post_type ) {
            return $actions;
        }

        if ( isset( $actions['edit'] ) ) {
            $actions['edit'] = sprintf(
                '<a href="%s">Edit</a>',
                esc_url( add_query_arg( 'ptk_nl_edit_id', $post->ID, self::url() ) )
            );
        }

        return $actions;
    }

    /**
     * Send "Add New" newsletter to the builder instead of the block editor.
     */
    public static function redirect_add_new() {
        global $typenow;

        if ( 'pta_newsletter' !== $typenow ) {
            return;
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        wp_safe_redirect( self::url() );
        exit;
    }

    /**
     * If someone lands on the block/classic editor for an existing
     * pta_newsletter (e.g. a bookmarked link, or a link from elsewhere in
     * wp-admin), send them to the builder in edit mode instead — the
     * builder is the only supported way to edit a newsletter.
     */
    public static function redirect_edit_to_builder() {
        if ( ! isset( $_GET['action'], $_GET['post'] ) || 'edit' !== $_GET['action'] ) {
            return;
        }

        $post_id = self::validate_edit_id( $_GET['post'] );
        if ( ! $post_id ) {
            return;
        }

        wp_safe_redirect( add_query_arg( 'ptk_nl_edit_id', $post_id, self::url() ) );
        exit;
    }

    /**
     * Remove the default "Add New" submenu (which points at the block
     * editor's post-new.php) now that add_page() supplies a builder-backed
     * "Add New" in its place.
     */
    public static function remove_default_add_new() {
        remove_submenu_page( 'edit.php?post_type=pta_newsletter', 'post-new.php?post_type=pta_newsletter' );
    }

    /**
     * Handle the builder form submission: sanitize + render the blocks,
     * gate Publish behind the photo/PII confirmation, and create/update the
     * pta_newsletter post (draft, preview-as-draft, or published).
     */
    public static function handle_submission() {
        if ( ! isset( $_POST['ptk_nl_status'] ) || ! isset( $_POST['ptk_nl_nonce'] ) ) {
            return;
        }

        check_admin_referer( 'ptk_nl_save', 'ptk_nl_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to create newsletters.', 'PTA Hub', array( 'back_link' => true ) );
        }

        // Validate the edit target fully BEFORE the expensive sanitize/render:
        // confirm it exists and is a pta_newsletter, then that this user may
        // edit it. Fail fast so we never render HTML we'd throw away.
        $edit_id = isset( $_POST['ptk_nl_edit_id'] ) ? absint( $_POST['ptk_nl_edit_id'] ) : 0;
        if ( $edit_id ) {
            $existing = get_post( $edit_id );
            if ( ! $existing || 'pta_newsletter' !== $existing->post_type ) {
                wp_die( 'That newsletter no longer exists — it may have been deleted.', 'PTA Hub', array( 'back_link' => true ) );
            }
            if ( ! current_user_can( 'edit_post', $edit_id ) ) {
                wp_die( 'You do not have permission to edit this newsletter.', 'PTA Hub', array( 'back_link' => true ) );
            }
        }

        $raw    = json_decode( wp_unslash( $_POST['ptk_nl_blocks'] ?? '' ), true );
        $blocks = PTK_Newsletter_Data::sanitize_blocks( $raw );

        // Floor the issue number: absint() yields 0 on a missing/malformed
        // value, which would produce a "Newsletter No. 0" title. Fall back to
        // the next issue number instead.
        $issue = absint( $_POST['ptk_nl_issue'] ?? 0 );
        if ( $issue < 1 ) {
            $issue = self::next_issue_number();
        }

        $date_posted = isset( $_POST['ptk_nl_date'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_nl_date'] ) ) : '';
        $date        = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_posted ) ? $date_posted : current_time( 'Y-m-d' );

        $status_req = sanitize_key( $_POST['ptk_nl_status'] );
        if ( ! in_array( $status_req, array( 'draft', 'preview', 'publish' ), true ) ) {
            $status_req = 'draft';
        }

        // PII gate: never publish without the photo/privacy confirmation.
        // Silently downgrade to draft and flag it so the redirect notice can
        // explain why nothing went live.
        $pii_ok       = ! empty( $_POST['ptk_nl_pii_ok'] );
        $forced_draft = false;
        if ( 'publish' === $status_req && ! $pii_ok ) {
            $status_req   = 'draft';
            $forced_draft = true;
        }

        $post_status = ( 'publish' === $status_req ) ? 'publish' : 'draft';

        // Build the post, write it, and persist the structured meta.
        $post_id = self::persist_newsletter( $blocks, $issue, $date, $post_status, $edit_id );

        if ( 'preview' === $status_req ) {
            wp_safe_redirect( get_preview_post_link( $post_id ) );
            exit;
        }

        $msg = $forced_draft ? 'pii' : ( 'publish' === $post_status ? 'published' : 'saved' );

        wp_safe_redirect( add_query_arg( array(
            'page'           => self::PAGE_SLUG,
            'post_type'      => 'pta_newsletter',
            'ptk_nl_edit_id' => $post_id,
            'ptk_nl_msg'     => $msg,
        ), admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Render the blocks to HTML, create or update the pta_newsletter post,
     * and save the structured meta. wp_die()s (never returns) on a failed
     * insert/update. The caller is responsible for auth, the PII gate, and
     * redirecting.
     *
     * @param array  $blocks      Sanitized blocks.
     * @param int    $issue       Issue number (already floored to >= 1).
     * @param string $date        Issue date 'YYYY-MM-DD'.
     * @param string $post_status 'draft' or 'publish'.
     * @param int    $edit_id     Existing post id to update, or 0 to insert.
     * @return int The saved post id.
     */
    private static function persist_newsletter( array $blocks, $issue, $date, $post_status, $edit_id ) {
        $rendered = PTK_Newsletter_Renderer::render( $blocks, array(
            'issue'        => $issue,
            'date'         => $date,
            'today'        => current_time( 'Y-m-d' ),
            'theme'        => self::DEFAULT_THEME,
            'logo_url'     => get_site_icon_url() ?: '',
            'school_name'  => self::school_name_from_blocks( $blocks ),
            'image_url_cb' => function( $id ) {
                return wp_get_attachment_image_url( $id, 'large' );
            },
        ) );

        $title = sprintf( 'Newsletter No. %d — %s', $issue, date_i18n( 'F j, Y', strtotime( $date ) ) );

        $post_data = array(
            'post_type'    => 'pta_newsletter',
            'post_title'   => $title,
            'post_content' => $rendered,
            'post_status'  => $post_status,
        );

        // The newsletter HTML is generated by PTK_Newsletter_Renderer from
        // already-sanitized, per-field-escaped structured data — it contains
        // no raw user HTML. Authors without unfiltered_html (every
        // non-super-admin, e.g. on multisite) would otherwise have wp_kses
        // strip the flexbox inline styles and the data-event-date attributes
        // the relabel script needs. Bypass kses for THIS trusted write only,
        // then restore filtering (via finally, so it's always restored even
        // if the insert throws) for the rest of the request.
        if ( $edit_id ) {
            $post_data['ID'] = $edit_id;
        }

        kses_remove_filters();
        try {
            $post_id = $edit_id ? wp_update_post( $post_data, true ) : wp_insert_post( $post_data, true );
        } finally {
            kses_init_filters();
        }

        // Guard both WP_Error and a falsy 0: a save_post filter can
        // short-circuit the insert to 0, which must NOT fall through to a
        // meta write on post 0 or a bogus "success" redirect.
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $detail = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'the save was blocked.';
            wp_die(
                esc_html( 'Sorry — the newsletter could not be saved (' . $detail . '). Please go back and try again.' ),
                'PTA Hub',
                array( 'back_link' => true )
            );
        }

        update_post_meta( $post_id, 'ptk_nl_issue', $issue );
        update_post_meta( $post_id, 'ptk_nl_date', $date );
        update_post_meta( $post_id, 'ptk_nl_theme', self::DEFAULT_THEME );
        update_post_meta( $post_id, 'ptk_nl_blocks', wp_json_encode( $blocks ) );

        return $post_id;
    }

    /**
     * The school name to render into the header: the header block's
     * school_name field if set, else the site name.
     *
     * @param array $blocks Sanitized blocks.
     * @return string
     */
    private static function school_name_from_blocks( array $blocks ) {
        foreach ( $blocks as $block ) {
            if ( isset( $block['type'] ) && PTK_Newsletter_Data::TYPE_HEADER === $block['type'] ) {
                $name = isset( $block['data']['school_name'] ) ? trim( (string) $block['data']['school_name'] ) : '';
                if ( '' !== $name ) {
                    return $name;
                }
                break;
            }
        }

        return get_bloginfo( 'name' );
    }

    /**
     * Render an admin notice for ?ptk_nl_msg= after a save/publish redirect.
     */
    private static function render_notice() {
        if ( empty( $_GET['ptk_nl_msg'] ) ) {
            return;
        }

        $msg     = sanitize_key( wp_unslash( $_GET['ptk_nl_msg'] ) );
        $notices = array(
            'saved'     => array( 'success', 'Draft saved.' ),
            'published' => array( 'success', 'Newsletter published.' ),
            'pii'       => array( 'warning', 'Confirm the photo/privacy check before publishing. Your newsletter was saved as a draft instead.' ),
        );

        if ( ! isset( $notices[ $msg ] ) ) {
            return;
        }

        list( $type, $text ) = $notices[ $msg ];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            esc_html( $text )
        );
    }

    /**
     * Canonical admin URL of the Newsletter Builder page.
     *
     * @return string
     */
    public static function url() {
        return admin_url( 'edit.php?post_type=pta_newsletter&page=' . self::PAGE_SLUG );
    }

    /**
     * Add the builder as a submenu under Newsletters, in the "Add New" slot.
     */
    public static function add_page() {
        add_submenu_page(
            'edit.php?post_type=pta_newsletter',
            'New Newsletter',
            'Add New',
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Enqueue builder assets only on our page.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'pta_newsletter_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'ptk-newsletter-builder',
            PTK_PLUGIN_URL . 'assets/css/newsletter-builder.css',
            array(),
            PTK_VERSION
        );

        wp_enqueue_script(
            'ptk-newsletter-builder',
            PTK_PLUGIN_URL . 'assets/js/newsletter-builder.js',
            array( 'jquery', 'media-upload' ),
            PTK_VERSION,
            true
        );

        wp_localize_script( 'ptk-newsletter-builder', 'ptkNlData', array(
            'blocks' => self::blocks_for_js(),
        ) );
    }

    /**
     * The blocks array to hand to the front-end JS for prefilling the
     * builder form: the saved ptk_nl_blocks meta when editing an existing
     * newsletter the current user may edit, otherwise the suggested
     * default layout for a brand-new one.
     *
     * @return array[]
     */
    private static function blocks_for_js() {
        $edit_id = self::validate_edit_id( isset( $_GET['ptk_nl_edit_id'] ) ? $_GET['ptk_nl_edit_id'] : 0 );

        if ( $edit_id ) {
            // Sanitize the saved meta the same way render_page() does, so the
            // JS prefill data and the server-rendered shell set are provably
            // identical rather than merely identical-by-construction.
            return PTK_Newsletter_Data::sanitize_blocks( json_decode( get_post_meta( $edit_id, 'ptk_nl_blocks', true ), true ) );
        }

        return PTK_Newsletter_Data::default_blocks();
    }

    /**
     * Validate a candidate newsletter edit id: returns the id as an int when
     * it points at an existing pta_newsletter the current user may edit,
     * otherwise 0. Central home for the check shared by
     * redirect_edit_to_builder(), blocks_for_js(), and render_page().
     *
     * @param mixed $id Candidate id (raw request value or int).
     * @return int Validated post id, or 0.
     */
    private static function validate_edit_id( $id ) {
        $id = absint( $id );
        if ( ! $id ) {
            return 0;
        }

        $post = get_post( $id );
        if ( ! $post || 'pta_newsletter' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
            return 0;
        }

        return $id;
    }

    /**
     * Work out the next issue number: one more than the highest
     * `ptk_nl_issue` meta value found on any pta_newsletter post
     * (any status), or 1 if there are no newsletters yet.
     *
     * @return int
     */
    public static function next_issue_number() {
        $recent = get_posts( array(
            'post_type'      => 'pta_newsletter',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_key'       => 'ptk_nl_issue',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        if ( empty( $recent ) ) {
            return 1;
        }

        $last = get_post_meta( $recent[0], 'ptk_nl_issue', true );

        return PTK_Newsletter_Data::compute_next_issue( $last );
    }

    /**
     * Plain-English heading for a block type.
     *
     * @param string $type Block type slug.
     * @return string
     */
    protected static function label_for_type( $type ) {
        $labels = array(
            'header'       => 'Header',
            'announcement' => 'Key announcement',
            'events'       => 'Upcoming events',
            'featured'     => 'Featured story',
            'story_cards'  => 'Story cards',
            'footer'       => 'Footer',
        );

        return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
    }

    /**
     * Render the Newsletter Builder page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to create newsletters.', 'PTA Hub', array( 'back_link' => true ) );
        }

        // Validate the edit target: it must exist, be a pta_newsletter, and
        // be editable by the current user. Anything else falls back to a
        // brand-new newsletter rather than erroring out.
        $edit_id = self::validate_edit_id( isset( $_GET['ptk_nl_edit_id'] ) ? $_GET['ptk_nl_edit_id'] : 0 );

        // Choose the blocks to render server-side: for edit mode, reconstruct
        // the SAVED section set + order (sanitize_blocks guarantees a valid
        // header-first/footer-last shape and drops any junk). These render as
        // empty shells only — blocks_for_js() returns this SAME saved data for
        // the JS prefill, which fills in field values and repeatable rows.
        // Rendering values here too would double-fill/duplicate rows.
        if ( $edit_id ) {
            $blocks       = PTK_Newsletter_Data::sanitize_blocks( json_decode( get_post_meta( $edit_id, 'ptk_nl_blocks', true ), true ) );
            $issue_number = get_post_meta( $edit_id, 'ptk_nl_issue', true );
            $issue_number = $issue_number ? absint( $issue_number ) : self::next_issue_number();
            $date_value   = get_post_meta( $edit_id, 'ptk_nl_date', true );
            $date_value   = $date_value ? $date_value : date_i18n( 'Y-m-d' );
        } else {
            $blocks       = PTK_Newsletter_Data::default_blocks();
            $issue_number = self::next_issue_number();
            $date_value   = date_i18n( 'Y-m-d' );
        }

        ?>
        <div class="wrap ptk-nl-builder">
            <h1><?php echo $edit_id ? 'Edit Newsletter' : 'New Newsletter'; ?></h1>
            <?php self::render_notice(); ?>
            <p class="ptk-nl-intro"><?php echo $edit_id ? 'Update the sections below — header and footer are always included, and you can add, remove, and reorder the sections in between.' : 'Fill in the sections below — header and footer are always included, and you can add, remove, and reorder the sections in between.'; ?></p>

            <form method="post" id="ptk-nl-form">
                <?php wp_nonce_field( 'ptk_nl_save', 'ptk_nl_nonce' ); ?>
                <input type="hidden" name="ptk_nl_edit_id" value="<?php echo esc_attr( $edit_id ); ?>">

                <div class="ptk-nl-meta-row">
                    <div class="ptk-nl-field-group">
                        <label for="ptk-nl-issue">Issue number</label>
                        <input type="number" id="ptk-nl-issue" name="ptk_nl_issue" value="<?php echo esc_attr( $issue_number ); ?>" min="1">
                    </div>
                    <div class="ptk-nl-field-group">
                        <label for="ptk-nl-date">Issue date</label>
                        <input type="date" id="ptk-nl-date" name="ptk_nl_date" value="<?php echo esc_attr( $date_value ); ?>">
                    </div>
                </div>

                <div id="ptk-nl-blocks">
                    <?php foreach ( $blocks as $block ) : ?>
                        <?php self::render_block_section( $block ); ?>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" name="ptk_nl_blocks" id="ptk-nl-blocks-json" value="<?php echo esc_attr( wp_json_encode( $blocks ) ); ?>">

                <div class="ptk-nl-pii-gate">
                    <label>
                        <input type="checkbox" name="ptk_nl_pii_ok" value="1">
                        These photos are OK to share publicly — no student faces or personal info.
                    </label>
                </div>

                <div class="ptk-nl-submit-row">
                    <button type="submit" name="ptk_nl_status" value="draft" class="button button-secondary">Save draft</button>
                    <button type="submit" name="ptk_nl_status" value="preview" class="button button-secondary">Preview</button>
                    <button type="submit" name="ptk_nl_status" value="publish" class="button button-primary">Publish</button>
                </div>
            </form>

            <?php if ( $edit_id ) : ?>
                <?php self::render_preview_panel( $edit_id ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the "share a preview link" panel for an existing newsletter,
     * backed by the same no-login token system used for knowledge entries
     * (PTK_Public_Preview). This is a SEPARATE <form> from the main builder
     * form above — it posts to admin.php's generate/revoke actions (see the
     * inline note by $post_action below), not the builder's own save
     * handler — and only appears in edit mode,
     * since a brand-new (unsaved) newsletter has no post id to attach a
     * token to.
     *
     * @param int $edit_id Existing, validated pta_newsletter post id.
     */
    protected static function render_preview_panel( $edit_id ) {
        $preview_url = PTK_Public_Preview::active_preview_url( $edit_id );
        $return_url  = add_query_arg( 'ptk_nl_edit_id', $edit_id, self::url() );
        // Matches render_publish_box()'s form action exactly: the generate/
        // revoke handlers are registered on the `admin_action_{$action}`
        // hooks, which are only fired by wp-admin/admin.php (NOT
        // admin-post.php, whose corresponding hooks are named
        // `admin_post_{$action}` and are never registered here).
        $post_action = admin_url( 'admin.php' );
        ?>
        <div class="ptk-nl-preview-panel" style="margin-top:24px;padding:16px;border:1px solid #ddd;border-radius:8px;background:#fff;max-width:640px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Share a preview link', 'pta-knowledge-hub' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Let someone — like a principal or PTA president — see this draft before it\'s published, without needing a login. The link stops working after 7 days.', 'pta-knowledge-hub' ); ?>
            </p>

            <?php if ( $preview_url ) : ?>
                <p class="description"><?php esc_html_e( 'This link is live right now:', 'pta-knowledge-hub' ); ?></p>
                <input type="text" readonly value="<?php echo esc_attr( $preview_url ); ?>" id="ptk-nl-preview-url" style="width:100%;font-size:12px;margin-bottom:8px;" onclick="this.select();" />
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('ptk-nl-preview-url').value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy link',1500);"><?php esc_html_e( 'Copy link', 'pta-knowledge-hub' ); ?></button>

                <form method="post" action="<?php echo esc_url( $post_action ); ?>" style="display:inline;margin-left:6px;">
                    <?php wp_nonce_field( 'ptk_revoke_preview_' . $edit_id ); ?>
                    <input type="hidden" name="action" value="ptk_revoke_preview">
                    <input type="hidden" name="post" value="<?php echo esc_attr( $edit_id ); ?>">
                    <input type="hidden" name="ptk_preview_return" value="<?php echo esc_attr( $return_url ); ?>">
                    <button type="submit" class="button button-link-delete"><?php esc_html_e( 'Stop sharing', 'pta-knowledge-hub' ); ?></button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( $post_action ); ?>">
                    <?php wp_nonce_field( 'ptk_generate_preview_' . $edit_id ); ?>
                    <input type="hidden" name="action" value="ptk_generate_preview">
                    <input type="hidden" name="post" value="<?php echo esc_attr( $edit_id ); ?>">
                    <input type="hidden" name="ptk_preview_return" value="<?php echo esc_attr( $return_url ); ?>">
                    <button type="submit" class="button"><?php esc_html_e( 'Create a preview link (no login needed)', 'pta-knowledge-hub' ); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render one server-side block section as an empty shell (type + order
     * only). Field values and repeatable rows are intentionally NOT rendered
     * here even in edit mode — the JS prefill (ptkNlData.blocks, from
     * blocks_for_js()) is the single source of truth for values, so it can
     * fill every section the same way whether the layout came from
     * default_blocks() or a saved newsletter. Rendering values here too
     * would risk double-filling/duplicating rows.
     *
     * @param array $block Block with a 'type' key ('data' is deliberately
     *                      ignored — see above).
     */
    protected static function render_block_section( $block ) {
        $type   = isset( $block['type'] ) ? $block['type'] : '';
        $pinned = in_array( $type, array( 'header', 'footer' ), true );
        $label  = self::label_for_type( $type );
        ?>
        <section class="ptk-nl-block" data-type="<?php echo esc_attr( $type ); ?>"<?php echo $pinned ? ' data-pinned="1"' : ''; ?>>
            <div class="ptk-nl-block-header">
                <h2><?php echo esc_html( $label ); ?></h2>
                <?php if ( $pinned ) : ?>
                    <span class="ptk-nl-pinned-note">Always shown</span>
                <?php else : ?>
                    <div class="ptk-nl-block-actions">
                        <button type="button" class="button ptk-nl-move-up">Move up</button>
                        <button type="button" class="button ptk-nl-move-down">Move down</button>
                        <button type="button" class="button ptk-nl-remove-block">Remove</button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ptk-nl-block-body">
                <?php self::render_block_fields( $type, array() ); ?>
            </div>
        </section>
        <?php
    }

    /**
     * Render the fixed fields (or repeatable-row scaffolding) for one block type.
     *
     * @param string $type Block type slug.
     * @param array  $data Block data.
     */
    protected static function render_block_fields( $type, $data ) {
        switch ( $type ) {

            case 'header':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-header-school_name">School name</label>
                    <input type="text" id="ptk-nl-header-school_name" data-field="school_name" value="<?php echo esc_attr( isset( $data['school_name'] ) ? $data['school_name'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-header-headline">Headline</label>
                    <input type="text" id="ptk-nl-header-headline" data-field="headline" value="<?php echo esc_attr( isset( $data['headline'] ) ? $data['headline'] : '' ); ?>">
                    <p class="description">The big title at the top — for example "Week of July 16." Leave blank and we'll use the week of your issue date.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-header-greeting">Greeting</label>
                    <textarea id="ptk-nl-header-greeting" data-field="greeting" rows="2"><?php echo esc_textarea( isset( $data['greeting'] ) ? $data['greeting'] : '' ); ?></textarea>
                </div>
                <?php
                break;

            case 'announcement':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-pill">Pill label</label>
                    <input type="text" id="ptk-nl-announcement-pill" data-field="pill" value="<?php echo esc_attr( isset( $data['pill'] ) ? $data['pill'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-text">Announcement text</label>
                    <textarea id="ptk-nl-announcement-text" data-field="text" rows="3"><?php echo esc_textarea( isset( $data['text'] ) ? $data['text'] : '' ); ?></textarea>
                </div>
                <?php
                break;

            case 'events':
                ?>
                <div class="ptk-nl-rows" data-rows data-rows-for="rows"></div>
                <button type="button" class="button ptk-nl-add">+ Add event</button>
                <template data-row-template>
                    <!-- Row fields intentionally have no static ids: the later JS task assigns a unique id per cloned row and points each label's for at it. -->
                    <div class="ptk-nl-row" data-row>
                        <div class="ptk-nl-field-group">
                            <label>Date</label>
                            <input type="date" data-field="date">
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Title</label>
                            <input type="text" data-field="title">
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Description</label>
                            <textarea data-field="desc" rows="2"></textarea>
                        </div>
                        <button type="button" class="button ptk-nl-remove-row">Remove</button>
                    </div>
                </template>
                <?php
                break;

            case 'featured':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-eyebrow">Eyebrow</label>
                    <input type="text" id="ptk-nl-featured-eyebrow" data-field="eyebrow" value="<?php echo esc_attr( isset( $data['eyebrow'] ) ? $data['eyebrow'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-headline">Headline</label>
                    <input type="text" id="ptk-nl-featured-headline" data-field="headline" value="<?php echo esc_attr( isset( $data['headline'] ) ? $data['headline'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-body">Story</label>
                    <textarea id="ptk-nl-featured-body" data-field="body" rows="4"><?php echo esc_textarea( isset( $data['body'] ) ? $data['body'] : '' ); ?></textarea>
                </div>
                <div class="ptk-nl-field-group">
                    <label>Image</label>
                    <input type="hidden" data-field="image_id" value="<?php echo esc_attr( isset( $data['image_id'] ) ? $data['image_id'] : 0 ); ?>">
                    <button type="button" class="button ptk-nl-add-image">Add image</button>
                </div>
                <?php
                break;

            case 'story_cards':
                ?>
                <div class="ptk-nl-rows" data-rows data-rows-for="cards"></div>
                <button type="button" class="button ptk-nl-add">+ Add story</button>
                <template data-row-template>
                    <!-- Row fields intentionally have no static ids: the later JS task assigns a unique id per cloned row and points each label's for at it. -->
                    <div class="ptk-nl-row" data-row>
                        <div class="ptk-nl-field-group">
                            <label>Heading</label>
                            <input type="text" data-field="heading">
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Story</label>
                            <textarea data-field="body" rows="3"></textarea>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Image</label>
                            <input type="hidden" data-field="image_id" value="0">
                            <button type="button" class="button ptk-nl-add-image">Add image</button>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Link URL</label>
                            <input type="url" data-field="link_url">
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Link text</label>
                            <input type="text" data-field="link_text">
                        </div>
                        <button type="button" class="button ptk-nl-remove-row">Remove</button>
                    </div>
                </template>
                <?php
                break;

            case 'footer':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-footer-signoff">Sign-off</label>
                    <textarea id="ptk-nl-footer-signoff" data-field="signoff" rows="2"><?php echo esc_textarea( isset( $data['signoff'] ) ? $data['signoff'] : '' ); ?></textarea>
                </div>
                <div class="ptk-nl-field-group">
                    <label>Links</label>
                    <div class="ptk-nl-rows" data-rows data-rows-for="links"></div>
                    <button type="button" class="button ptk-nl-add">+ Add link</button>
                    <template data-row-template>
                        <!-- Row fields intentionally have no static ids: the later JS task assigns a unique id per cloned row and points each label's for at it. -->
                        <div class="ptk-nl-row" data-row>
                            <div class="ptk-nl-field-group">
                                <label>Label</label>
                                <input type="text" data-field="label">
                            </div>
                            <div class="ptk-nl-field-group">
                                <label>URL</label>
                                <input type="url" data-field="url">
                            </div>
                            <button type="button" class="button ptk-nl-remove-row">Remove</button>
                        </div>
                    </template>
                </div>
                <?php
                break;
        }
    }
}
