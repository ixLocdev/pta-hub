<?php
/**
 * Newsletter Builder — guided form for creating a newsletter issue.
 *
 * Renders the "Add New" screen for the pta_newsletter post type as a
 * plain-English, four-step wizard instead of the block editor. The steps
 * are presentation only: every step-owned element carries `data-step` and
 * the JS shows/hides by that attribute, so the six block sections stay a
 * FLAT list of direct children of #ptk-nl-blocks — the JS reads
 * '#ptk-nl-blocks > .ptk-nl-block' and takes the newsletter's order from
 * DOM order. Which step edits a section is a property of its TYPE
 * (step_for_type()); its position in the newsletter is separate and
 * mutable. The fixed fields (Header/Announcement/Top story/Footer) and the
 * repeatable-row placeholders (Coming up/Stories/Quick notes, plus the
 * announcement's dates and the footer's links) are rendered
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

    /** Post meta: the day (Y-m-d) the photo check was confirmed on publish. */
    const META_PII_CONFIRMED = 'ptk_nl_pii_confirmed';

    /**
     * The main gate's checkbox copy, pulled out so PTK_Share_Panel's own
     * consent prompt (round 3 -- the square's background photo is
     * reachable outside this form, see class-share-panel.php) can reuse
     * the exact wording rather than drift from it over time.
     */
    const PII_CHECKBOX_LABEL = 'These photos are OK to share publicly — no student faces or personal info.';

    /** The only theme shipped in Phase 1. */
    const DEFAULT_THEME = 'harbor-navy';

    /**
     * The hook suffix add_submenu_page() actually returned for this page,
     * captured once in add_page(). enqueue_assets() compares against THIS,
     * never a hand-built 'pta_newsletter_page_...' string — see page_hook()
     * and the 4.3.0 menu move (class-newsletter-post-type.php nests
     * pta_newsletter under PTA Hub, which changes what that string would
     * have to be; a captured value is correct regardless of where the
     * CPT's menu lives).
     *
     * @var string
     */
    private static $hook = '';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_menu', array( __CLASS__, 'remove_default_add_new' ), 99 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_ptk_nl_preview', array( __CLASS__, 'handle_preview_ajax' ) );
        add_action( 'wp_ajax_ptk_nl_preview_link', array( __CLASS__, 'handle_preview_link_ajax' ) );
        add_action( 'load-post-new.php', array( __CLASS__, 'redirect_add_new' ) );
        add_action( 'load-post.php', array( __CLASS__, 'redirect_edit_to_builder' ) );
        // admin_init, not load-edit.php: an old-shape URL (post_type=
        // pta_newsletter&page=...) no longer resolves to a registered admin
        // page at all (see add_page()'s docblock), so WordPress's own
        // "Cannot load …" / access-denied wp_die() fires from inside
        // wp-admin/admin.php BEFORE any load-{hook} action would — this has
        // to run earlier, on admin_init, to catch the request first.
        add_action( 'admin_init', array( __CLASS__, 'redirect_old_bookmark' ) );
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
        // the next issue number -- but on a site with no newsletters yet there
        // is no "next" to guess (a PTA on issue 41 must not become No. 1), so
        // ask. The field is required in the form, so this is a last resort.
        $issue = absint( $_POST['ptk_nl_issue'] ?? 0 );
        if ( $issue < 1 ) {
            $issue = self::next_issue_number();
        }
        if ( $issue < 1 ) {
            wp_die(
                'Please fill in the issue number on step 1 (The basics). If you&#8217;ve sent newsletters before, use the next number. Go back to return to your newsletter.',
                'PTA Hub',
                array( 'back_link' => true )
            );
        }

        $date_posted = isset( $_POST['ptk_nl_date'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_nl_date'] ) ) : '';
        $date        = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_posted ) ? $date_posted : current_time( 'Y-m-d' );

        $status_req = sanitize_key( $_POST['ptk_nl_status'] );
        if ( ! in_array( $status_req, array( 'draft', 'preview', 'publish' ), true ) ) {
            $status_req = 'draft';
        }

        // PII gate: never publish photos without the photo/privacy
        // confirmation. With no photos there is nothing to confirm, so the
        // gate is skipped entirely. Otherwise downgrade to draft and flag it
        // so step 4 can explain why nothing went live, next to the checkbox.
        $pii_ok       = ! empty( $_POST['ptk_nl_pii_ok'] );
        // The Instagram square's own background photo (round 3) never
        // appears in $blocks -- it lives in PTK_Share_Data's post meta,
        // set via a separate AJAX endpoint the main gate can't see on its
        // own. OR it in here so a newsletter whose ONLY photo is the
        // square's background photo still requires confirmation to publish.
        $has_images   = PTK_Newsletter_Data::blocks_have_images( $blocks )
            || PTK_Share_Data::square_has_custom_photo( $edit_id );
        $forced_draft = false;
        if ( 'publish' === $status_req && $has_images && ! $pii_ok ) {
            $status_req   = 'draft';
            $forced_draft = true;
        }

        // Once a newsletter is live, this form must never take it down again.
        // Families already have its link, and the share captions point at it.
        // So whatever button was posted -- including Save draft or Preview
        // from a tab opened before this rule existed -- an already-published
        // newsletter is saved as published. The step 4 buttons say "Update"
        // for these, but the rule lives HERE, not in the buttons.
        $was_published = $edit_id && isset( $existing ) && 'publish' === $existing->post_status;

        if ( $was_published ) {
            $post_status  = 'publish';
            $forced_draft = false;
        } else {
            $post_status = ( 'publish' === $status_req ) ? 'publish' : 'draft';
        }

        // Build the post, write it, and persist the structured meta.
        $post_id = self::persist_newsletter( $blocks, $issue, $date, $post_status, $edit_id );

        // Remember the photo confirmation, with the day it was given, so the
        // box shows ticked when this newsletter is opened again instead of
        // looking like it was never done. The first confirmation is kept.
        if ( 'publish' === $post_status && $has_images && $pii_ok && ! get_post_meta( $post_id, self::META_PII_CONFIRMED, true ) ) {
            update_post_meta( $post_id, self::META_PII_CONFIRMED, current_time( 'Y-m-d' ) );
        }

        // Preview is only offered for drafts. A published newsletter has no
        // Preview button (View newsletter does that job), so a stray preview
        // request for one lands back on the builder like an update.
        if ( 'preview' === $status_req && ! $was_published ) {
            wp_safe_redirect( get_preview_post_link( $post_id ) );
            exit;
        }

        if ( $was_published ) {
            $msg = 'updated';
        } else {
            $msg = $forced_draft ? 'pii' : ( 'publish' === $post_status ? 'published' : 'saved' );
        }

        wp_safe_redirect( add_query_arg( array(
            'page'           => self::PAGE_SLUG,
            'post_type'      => 'pta_newsletter',
            'ptk_nl_edit_id' => $post_id,
            'ptk_nl_msg'     => $msg,
        ), admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Render the live preview for the builder. Runs the SAME sanitize + render
     * path as a real save, so what the volunteer sees is what families will get.
     */
    public static function handle_preview_ajax() {
        check_ajax_referer( 'ptk_nl_preview', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $raw    = json_decode( wp_unslash( isset( $_POST['blocks'] ) ? $_POST['blocks'] : '' ), true );
        $blocks = PTK_Newsletter_Data::sanitize_blocks( $raw );

        // Issue + date live OUTSIDE the blocks JSON and the renderer reads them
        // from opts, so they must be posted separately or the masthead would show
        // no issue number, no date, and no auto-derived "Week of ..." headline.
        // No number yet (a site's very first newsletter): show none rather
        // than invent "1".
        $issue = absint( isset( $_POST['issue'] ) ? $_POST['issue'] : 0 );
        if ( $issue < 1 ) {
            $issue = '';
        }
        $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = current_time( 'Y-m-d' );
        }

        $html = PTK_Newsletter_Renderer::render(
            $blocks,
            self::render_opts( $blocks, $issue, $date, array( 'preview_placeholders' => true ) )
        );

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Create or stop a no-login preview link without leaving the page.
     *
     * The old way was a separate <form> that posted to admin.php and reloaded
     * the builder -- throwing away anything typed but not yet saved, and
     * dropping the volunteer back on step 1. This replies with the panel's
     * new contents instead. (The form still works without JavaScript; see
     * render_preview_panel().)
     *
     * Same checks as PTK_Share_Panel's handlers: nonce, then that the post
     * really is a pta_newsletter, then edit_post on THIS post.
     */
    public static function handle_preview_link_ajax() {
        check_ajax_referer( 'ptk_nl_preview_link', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || 'pta_newsletter' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'That newsletter could not be found.' ), 404 );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to edit this newsletter.' ), 403 );
        }

        if ( 'publish' === get_post_status( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'This newsletter is already published, so share its real link instead. Reload the page to see it.' ), 409 );
        }

        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

        if ( 'generate' === $mode ) {
            PTK_Public_Preview::create_token( $post_id );
        } elseif ( 'revoke' === $mode ) {
            PTK_Public_Preview::revoke_token( $post_id );
        } else {
            wp_send_json_error( array( 'message' => 'Something went wrong. Please reload the page and try again.' ), 400 );
        }

        wp_send_json_success( array(
            'html'    => self::preview_link_body_html( $post_id ),
            'message' => 'generate' === $mode ? 'Preview link ready.' : 'That preview link no longer works.',
        ) );
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
        $rendered = PTK_Newsletter_Renderer::render( $blocks, self::render_opts( $blocks, $issue, $date ) );

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

        // wp_slash() is REQUIRED here: update_post_meta() runs the value through
        // wp_unslash() internally, which would strip the backslashes out of the
        // JSON's \uXXXX escapes (wp_json_encode escapes non-ASCII by default).
        // Without this, an em dash saves as the literal text "u2014" and every
        // curly quote, accent, or emoji in a newsletter gets corrupted on reopen.
        update_post_meta( $post_id, 'ptk_nl_blocks', wp_slash( wp_json_encode( $blocks ) ) );

        return $post_id;
    }

    /**
     * The render options for a newsletter — ONE definition, used by both the
     * save path and the live-preview endpoint so the preview can never drift
     * from what actually gets published.
     *
     * @param array  $blocks Sanitized blocks.
     * @param int    $issue  Issue number.
     * @param string $date   Issue date 'YYYY-MM-DD'.
     * @param array  $extra  Preview-only additions (e.g. preview_placeholders => true).
     * @return array
     */
    private static function render_opts( array $blocks, $issue, $date, array $extra = array() ) {
        return array_merge( array(
            'issue'         => $issue,
            'date'          => $date,
            'today'         => current_time( 'Y-m-d' ),
            'theme'         => self::DEFAULT_THEME,
            'logo_url'      => self::masthead_logo_url(),
            'school_name'   => self::school_name_from_blocks( $blocks ),
            // 4.3.0 "set it once" settings — every value already sanitized on
            // the way in (PTK_Share_Settings::handle_save()); run through the
            // same sanitizer again here, matching how every other value in
            // this array is treated as untrusted at render time.
            'join_url'      => PTK_Newsletter_Data::sanitize_link_url( get_option( 'ptk_join_url', '' ) ),
            'calendar_url'  => PTK_Newsletter_Data::sanitize_link_url( get_option( 'ptk_calendar_url', '' ) ),
            'news_url'      => PTK_Newsletter_Data::sanitize_link_url( get_option( 'ptk_news_url', '' ) ),
            'contact_email' => sanitize_email( (string) get_option( 'ptk_contact_email', '' ) ),
            'image_url_cb' => function( $id ) {
                return wp_get_attachment_image_url( $id, 'large' );
            },
        ), $extra );
    }

    /**
     * The masthead logo: the site's own custom logo (Appearance > Customize
     * > Site Identity), else its Site Icon (the favicon round 1 used), else
     * none. A school that only ever set a favicon keeps seeing it — this
     * only ADDS a better source, it never removes the fallback.
     *
     * @return string
     */
    private static function masthead_logo_url() {
        $logo_id = (int) get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $url = wp_get_attachment_image_url( $logo_id, 'thumbnail' );
            if ( $url ) {
                return $url;
            }
        }
        return get_site_icon_url() ?: '';
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
     * The message after a save/publish redirect (?ptk_nl_msg=).
     *
     * Deliberately NOT WordPress's .notice: that draws a one-sided accent bar,
     * which the house style never uses, and WordPress's own script moves
     * .notice elements around the page. .ptk-nl-msg is a full four-sided box
     * (same pattern as the Sharing settings messages).
     *
     * @param int $edit_id The newsletter just saved, or 0.
     */
    private static function render_notice( $edit_id = 0 ) {
        if ( empty( $_GET['ptk_nl_msg'] ) ) {
            return;
        }

        $msg     = sanitize_key( wp_unslash( $_GET['ptk_nl_msg'] ) );
        $notices = array(
            'saved'     => array( 'ok', 'Draft saved.' ),
            'published' => array( 'ok', 'Newsletter published.' ),
            'updated'   => array( 'ok', 'Newsletter updated.' ),
            // 'pii' has no message up here on purpose: it is shown next to the
            // photo checkbox on step 4, which gets focus (see render_page()).
        );

        if ( ! isset( $notices[ $msg ] ) ) {
            return;
        }

        list( $kind, $text ) = $notices[ $msg ];

        $live_url = ( $edit_id && in_array( $msg, array( 'published', 'updated' ), true ) && 'publish' === get_post_status( $edit_id ) )
            ? (string) get_permalink( $edit_id )
            : '';
        ?>
        <div class="ptk-nl-msg ptk-nl-msg-<?php echo esc_attr( $kind ); ?>" role="status">
            <p>
                <strong><?php echo esc_html( $text ); ?></strong>
                <?php if ( 'published' === $msg && '' !== $live_url ) : ?>
                    Now share it below.
                <?php endif; ?>
            </p>
            <?php if ( '' !== $live_url ) : ?>
                <p class="ptk-nl-msg-actions">
                    <a class="button button-primary" id="ptk-nl-msg-view" href="<?php echo esc_url( $live_url ); ?>" target="_blank" rel="noopener">View newsletter</a>
                    <button type="button" class="button" data-ptk-copy="#ptk-nl-msg-view">Copy link</button>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Canonical admin URL of the Newsletter Builder page.
     *
     * @return string
     */
    public static function url() {
        return admin_url( 'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG );
    }

    /**
     * Add the builder as a submenu under PTA Hub.
     *
     * 4.3.0: parented on 'edit.php?post_type=pta_knowledge' (the real,
     * stable top-level PTA Hub menu), NOT 'edit.php?post_type=pta_newsletter'.
     * Once pta_newsletter itself became a NESTED post type (the menu move),
     * WordPress's own admin-page access check (wp-admin/admin.php,
     * user_can_access_admin_page()) stops resolving a submenu parented on a
     * non-top-level post type's edit.php consistently — confirmed in
     * Playground: it 403's every request for a good, capability-passing
     * user. Parenting directly on the real top-level slug sidesteps that
     * core quirk entirely (verified working). old_bookmark_redirect() below
     * keeps the OLD `edit.php?post_type=pta_newsletter&page=...` URL shape
     * working for existing bookmarks/links.
     */
    public static function add_page() {
        self::$hook = (string) add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'New Newsletter',
            // Not "Add New": this menu also creates Hub entries and vendors.
            'New newsletter',
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Old bookmarks/links used `edit.php?post_type=pta_newsletter&page=...`
     * (the shape the Builder's own URL had before the 4.3.0 menu move).
     * That shape no longer resolves (see add_page()'s docblock), so send it
     * to the current, working URL instead — carrying over every other query
     * arg (ptk_nl_edit_id, ptk_nl_msg, ptk_nl_step, saved, …) unchanged.
     * Hooked to 'load-edit.php', which fires for exactly this request shape.
     *
     * @return void
     */
    public static function redirect_old_bookmark() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        if ( 'pta_newsletter' !== $post_type ) {
            return;
        }
        if ( ! in_array( $page, array( self::PAGE_SLUG, PTK_Share_Settings::PAGE_SLUG ), true ) ) {
            return;
        }

        // Carry over every other query arg unchanged (ptk_nl_edit_id,
        // ptk_nl_msg, ptk_nl_step, saved, …) — only post_type changes.
        $args = wp_unslash( $_GET );
        $args['post_type'] = 'pta_knowledge';

        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * The Builder page's real hook suffix, for other classes that enqueue
     * assets on this same page but have no add_page() of their own to
     * capture it from (PTK_Share_Panel — the share panel is rendered on
     * step 4 of THIS page, not its own).
     *
     * @return string
     */
    public static function page_hook() {
        return self::$hook;
    }

    /**
     * Enqueue builder assets only on our page.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets( $hook ) {
        if ( '' === self::$hook || $hook !== self::$hook ) {
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
            'ptk-focal-point',
            PTK_PLUGIN_URL . 'assets/js/focal-point.js',
            array(),
            PTK_VERSION,
            true
        );

        wp_enqueue_script(
            'ptk-focal-point-picker',
            PTK_PLUGIN_URL . 'assets/js/focal-point-picker.js',
            array( 'jquery', 'ptk-focal-point' ),
            PTK_VERSION,
            true
        );

        wp_enqueue_script(
            'ptk-newsletter-builder',
            PTK_PLUGIN_URL . 'assets/js/newsletter-builder.js',
            // jquery-ui-sortable powers step 4's drag-to-reorder. Dragging is
            // never the only way to reorder — the arrange list's Move up/down
            // buttons do the same thing from the keyboard. ptk-focal-point-picker
            // must load first: the boot block calls into it (initFocalPickers()).
            array( 'jquery', 'media-upload', 'jquery-ui-sortable', 'ptk-focal-point-picker' ),
            PTK_VERSION,
            true
        );

        $nl_data = array(
            'blocks'       => self::blocks_for_js(),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'previewNonce' => wp_create_nonce( 'ptk_nl_preview' ),
        );

        $nl_data['previewLinkNonce'] = wp_create_nonce( 'ptk_nl_preview_link' );

        // Just saved or published: land on the last step, where the notice's
        // follow-up lives (the share panel, the photo check). Without this the
        // wizard boots on step 1 and the share panel sits hidden on step 4.
        // ptk_nl_step does the same for the no-JavaScript preview-link forms,
        // which reload the page.
        // wp_localize_script() stringifies this to "4" -- the JS parseInt()s it.
        if ( ! empty( $_GET['ptk_nl_msg'] ) ) {
            $nl_data['startStep'] = 4;
            // Publishing was refused for want of the photo check: put the
            // volunteer ON the checkbox, where the explanation is.
            if ( 'pii' === sanitize_key( wp_unslash( $_GET['ptk_nl_msg'] ) ) ) {
                $nl_data['focusConsent'] = 1;
            }
        } elseif ( ! empty( $_GET['ptk_nl_step'] ) ) {
            $step = absint( $_GET['ptk_nl_step'] );
            if ( $step >= 1 && $step <= count( self::steps() ) ) {
                $nl_data['startStep'] = $step;
            }
        }

        wp_localize_script( 'ptk-newsletter-builder', 'ptkNlData', $nl_data );
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

        return self::default_blocks_for_site();
    }

    /**
     * The suggested layout for a NEW newsletter, pre-filled with what this
     * school's own site already knows about itself — one less thing for a
     * volunteer to type. On the network each school is its own sub-site, so
     * get_bloginfo() resolves to that school's name.
     *
     * Everything stays editable; this only supplies a sensible starting value.
     */
    private static function default_blocks_for_site() {
        $blocks = PTK_Newsletter_Data::default_blocks();

        // Decode entities (e.g. "Smith &amp; Jones") so the form shows the real
        // name rather than the encoded one — it gets re-escaped on render.
        $school_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        foreach ( $blocks as $i => $block ) {
            if ( isset( $block['type'] ) && PTK_Newsletter_Data::TYPE_HEADER === $block['type'] ) {
                $blocks[ $i ]['data']['school_name'] = $school_name;
                break;
            }
        }

        // "Start from last issue" (4.3.0): footer + the two § labels carry
        // over from the most recent newsletter, everything else stays
        // blank. See PTK_Newsletter_Data::merge_start_from_last() for the
        // (unit-tested) copy rule itself.
        $last_id = self::most_recent_newsletter_id();
        if ( $last_id ) {
            $last_blocks = PTK_Newsletter_Data::sanitize_blocks( json_decode( get_post_meta( $last_id, 'ptk_nl_blocks', true ), true ) );
            $blocks      = PTK_Newsletter_Data::merge_start_from_last( $blocks, $last_blocks );
        }

        return $blocks;
    }

    /**
     * The most recent pta_newsletter post on this site, any status -- 0 if
     * there are none yet. Shared by next_issue_number() (which issue comes
     * next), default_blocks_for_site() ("start from last issue"), and
     * PTK_Welcome's Start Here card (the "Last issue: …" line), so the
     * query runs once per concept instead of being hand-rolled three times.
     *
     * @return int 0 when there is no previous newsletter.
     */
    public static function most_recent_newsletter_id() {
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

        return empty( $recent ) ? 0 : (int) $recent[0];
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
     * (any status), or 0 if there are no newsletters yet.
     *
     * 0, never 1, for a first run: most PTAs were sending newsletters long
     * before this site, so the builder leaves the number blank and asks
     * rather than publishing "No. 1" for what is really issue 41.
     *
     * @return int 0 when unknown.
     */
    public static function next_issue_number() {
        $last_id = self::most_recent_newsletter_id();

        if ( ! $last_id ) {
            return 0;
        }

        $last = get_post_meta( $last_id, 'ptk_nl_issue', true );

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
            'announcement' => 'Announcement',
            'events'       => 'Coming up',
            'featured'     => 'Top story',
            'story_cards'  => 'Stories',
            'quick_notes'  => 'Quick notes',
            'footer'       => 'Footer',
        );

        return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
    }

    /**
     * One plain-English line saying what a section is and when you'd use one.
     * The section names alone don't tell a first-timer that, so this renders
     * under each heading. Returned as plain text (curly quotes and dashes
     * included) — the caller escapes it, so never put HTML entities here.
     *
     * @param string $type Block type slug.
     * @return string Empty string if the type has nothing to say.
     */
    protected static function intro_for_type( $type ) {
        $intros = array(
            'header'       => 'The top of every newsletter — your school name, the week, and a hello.',
            'announcement' => 'The one thing families must not miss this week. It\'s the only colored block in the newsletter. Skip it if there isn\'t one.',
            'events'       => 'Dates coming up. Each one gets a “This week” or “Next week” tag that updates itself, and past dates fade out.',
            'featured'     => 'The main story, at the top of the stories. Optional.',
            'story_cards'  => 'Shorter articles, each with its own “§ label” line. Add as many as you need.',
            'quick_notes'  => 'Small reminders and useful links, grouped under one heading. Good for “three things worth bookmarking”.',
            'footer'       => 'Your sign-off and links.',
        );

        return isset( $intros[ $type ] ) ? $intros[ $type ] : '';
    }

    /**
     * Which wizard step edits a given block type. Step is a property of the
     * TYPE — never of position — so a section dragged to the bottom of the
     * newsletter on step 4 is still edited on its own step.
     *
     * @param string $type Block type slug.
     * @return int Step number.
     */
    protected static function step_for_type( $type ) {
        $map = array(
            PTK_Newsletter_Data::TYPE_HEADER       => 1,
            PTK_Newsletter_Data::TYPE_ANNOUNCEMENT => 2,
            PTK_Newsletter_Data::TYPE_EVENTS       => 2,
            PTK_Newsletter_Data::TYPE_FEATURED     => 3,
            PTK_Newsletter_Data::TYPE_STORY_CARDS  => 3,
            PTK_Newsletter_Data::TYPE_QUICK_NOTES  => 3,
            PTK_Newsletter_Data::TYPE_FOOTER       => 4,
        );

        return isset( $map[ $type ] ) ? $map[ $type ] : 2;
    }

    /**
     * The wizard's steps, in order: the sidebar entries and the heading that
     * opens each step. Plain English — this is the first thing a first-time
     * volunteer reads.
     *
     * @return array[] Step number => array( 'title' => string, 'blurb' => string ).
     */
    protected static function steps() {
        return array(
            1 => array(
                'title' => 'The basics',
                'blurb' => "Who it's from and how you say hello. Most of this is filled in already.",
            ),
            2 => array(
                'title' => "What's happening",
                'blurb' => "The one big thing families must not miss, and the dates coming up. Skip anything you don't need.",
            ),
            3 => array(
                'title' => 'Stories',
                'blurb' => 'The top story, shorter stories and quick notes. All optional.',
            ),
            4 => array(
                'title' => 'Finish & publish',
                'blurb' => 'Put it in order, check the photos, and send it out.',
            ),
        );
    }

    /**
     * The sections to render into #ptk-nl-blocks, in DOM order.
     *
     * Edit mode hands us only the types the saved newsletter includes, but
     * every type needs a DOM node: a type with no node could never be listed
     * under "Not included" on step 4, so removing a section and saving would
     * make it unrecoverable forever.
     *
     * The saved blocks supply the order of what IS included; every absent type
     * is inserted immediately BEFORE the footer — the end of the movable run —
     * in default order, marked excluded. Inserting after the footer instead
     * would strand it below the pinned footer, where "Add back" would restore
     * it in a spot Move up could never rescue it from.
     *
     * @param array $blocks Sanitized blocks (header first, footer last).
     * @return array[] Each: array( 'block' => array, 'excluded' => bool ).
     */
    protected static function sections_to_render( array $blocks ) {
        $sections = array();
        $present  = array();

        foreach ( $blocks as $block ) {
            if ( empty( $block['type'] ) ) {
                continue;
            }
            $present[]  = $block['type'];
            $sections[] = array(
                'block'    => $block,
                'excluded' => false,
            );
        }

        $missing = array();
        foreach ( PTK_Newsletter_Data::default_blocks() as $default ) {
            if ( ! in_array( $default['type'], $present, true ) ) {
                $missing[] = array(
                    'block'    => $default,
                    'excluded' => true,
                );
            }
        }

        if ( ! $missing ) {
            return $sections;
        }

        // Splice in before the footer so the excluded shells sit at the end of
        // the movable run. sanitize_blocks() always emits a footer, so the
        // count() fallback is belt-and-braces only.
        $footer_at = count( $sections );
        foreach ( $sections as $i => $section ) {
            if ( PTK_Newsletter_Data::TYPE_FOOTER === $section['block']['type'] ) {
                $footer_at = $i;
                break;
            }
        }

        array_splice( $sections, $footer_at, 0, $missing );

        return $sections;
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

        $is_published = $edit_id && 'publish' === get_post_status( $edit_id );
        // See handle_submission()'s matching OR: the square's own
        // background photo (round 3) doesn't live in $blocks.
        $has_images   = PTK_Newsletter_Data::blocks_have_images( $blocks )
            || PTK_Share_Data::square_has_custom_photo( $edit_id );
        $pii_date     = $edit_id ? (string) get_post_meta( $edit_id, self::META_PII_CONFIRMED, true ) : '';
        $pii_date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pii_date ) ? $pii_date : '';
        $pii_failed   = isset( $_GET['ptk_nl_msg'] ) && 'pii' === sanitize_key( wp_unslash( $_GET['ptk_nl_msg'] ) );

        $steps     = self::steps();
        $step_last = count( $steps );
        $sections  = self::sections_to_render( $blocks );
        ?>
        <div class="wrap ptk-nl-builder">
            <h1><?php echo $edit_id ? 'Edit Newsletter' : 'New Newsletter'; ?></h1>
            <?php self::render_notice( $edit_id ); ?>
            <p class="ptk-nl-intro">Four short steps. We&#8217;ve filled in what we can — you write the news.</p>
            <?php if ( ! $edit_id ) : $last_id = self::most_recent_newsletter_id(); if ( $last_id ) : $last_issue = get_post_meta( $last_id, 'ptk_nl_issue', true ); if ( $last_issue ) : ?>
                <div class="ptk-nl-msg ptk-nl-msg-ok" role="status">
                    <p>We copied your footer and section names from No. <?php echo esc_html( PTK_Share_Text::issue_label( $last_issue ) ); ?>. Everything else — stories, events, the announcement, the greeting — starts blank.</p>
                </div>
            <?php endif; endif; endif; ?>

            <div class="ptk-nl-wizard">
                <nav class="ptk-nl-steps" aria-label="Newsletter steps">
                    <ul>
                        <?php foreach ( $steps as $step_number => $step ) : ?>
                            <li>
                                <button type="button" class="ptk-nl-step-link" data-goto-step="<?php echo (int) $step_number; ?>"<?php echo 1 === $step_number ? ' aria-current="step"' : ''; ?>>
                                    <span class="ptk-nl-step-num"><?php echo (int) $step_number; ?></span>
                                    <span class="ptk-nl-step-name"><?php echo esc_html( $step['title'] ); ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>

                <div class="ptk-nl-fields">
                    <form method="post" id="ptk-nl-form">
                        <?php wp_nonce_field( 'ptk_nl_save', 'ptk_nl_nonce' ); ?>
                        <input type="hidden" name="ptk_nl_edit_id" value="<?php echo esc_attr( $edit_id ); ?>">

                        <?php foreach ( $steps as $step_number => $step ) : ?>
                            <?php /* One step head is visible at a time, so it always reads as the heading for the fields below it. */ ?>
                            <div class="ptk-nl-step-head" data-step="<?php echo (int) $step_number; ?>">
                                <h2 tabindex="-1">
                                    <?php echo esc_html( $step['title'] ); ?>
                                    <span class="ptk-nl-step-count"><?php echo esc_html( sprintf( 'Step %d of %d', $step_number, $step_last ) ); ?></span>
                                </h2>
                                <p class="ptk-nl-step-blurb"><?php echo esc_html( $step['blurb'] ); ?></p>
                            </div>
                        <?php endforeach; ?>

                        <?php /* Sits here, immediately above #ptk-nl-blocks, so step 4 reads in plain DOM
                                order: arrange panel → the footer's fields (the only section shown on step 4)
                                → the photo check and buttons (.ptk-nl-finish) → share a preview link. No CSS
                                ordering needed. It's a SIBLING of #ptk-nl-blocks, never a child — the
                                flat-DOM rule governs that container's children, which stay exactly the six
                                sections. */ ?>
                        <div class="ptk-nl-arrange-panel" data-step="<?php echo (int) $step_last; ?>">
                            <h3>Order of your newsletter</h3>
                            <p class="description">Drag to change the order, or use the arrows.</p>

                            <div class="ptk-nl-arrange-pinned"><span aria-hidden="true">&#128274;</span> Header — always first</div>

                            <?php /* Deliberately empty: the JS builds these rows from the live sections in
                                    #ptk-nl-blocks, so the list always shows the real order. */ ?>
                            <ul class="ptk-nl-arrange" data-arrange></ul>

                            <div class="ptk-nl-arrange-pinned"><span aria-hidden="true">&#128274;</span> Footer — always last</div>

                            <div class="ptk-nl-excluded" data-excluded-list>
                                <h4>Not included</h4>
                                <ul></ul>
                            </div>

                            <?php /* Says what just happened after a Move up/down — "Featured story moved
                                    down. Now 3 of 4." A screen reader announces it because it's aria-live,
                                    and everyone else can simply read it. Must be in the page from the
                                    start: a live region added at the moment of the change isn't announced.
                                    Empty until the JS has something to say. */ ?>
                            <p class="ptk-nl-arrange-status" data-arrange-status role="status" aria-live="polite"></p>

                            <p class="description">Once you save, a section you&#8217;ve left out won&#8217;t keep its text.</p>
                        </div>

                        <?php /* The six sections MUST stay direct children of #ptk-nl-blocks: serialize() reads
                                '#ptk-nl-blocks > .ptk-nl-block' and takes the newsletter's order from DOM order.
                                Steps are presentation only — each section carries data-step and the JS shows/hides
                                by that attribute. Never wrap these in per-step parents. */ ?>
                        <div id="ptk-nl-blocks">
                            <?php foreach ( $sections as $section ) : ?>
                                <?php self::render_block_section( $section['block'], $section['excluded'], $issue_number, $date_value ); ?>
                            <?php endforeach; ?>
                        </div>

                        <input type="hidden" name="ptk_nl_blocks" id="ptk-nl-blocks-json" value="<?php echo esc_attr( wp_json_encode( $blocks ) ); ?>">

                        <div class="ptk-nl-finish" data-step="<?php echo (int) $step_last; ?>">
                            <?php /* Immediately above the buttons it governs. Hidden (the `hidden`
                                    attribute, never CSS) when the newsletter has no photos -- there is
                                    nothing to confirm. newsletter-builder.js shows it again the moment a
                                    photo is added. */ ?>
                            <div class="ptk-nl-pii-gate" data-pii-gate<?php echo $has_images ? '' : ' hidden'; ?>>
                                <?php if ( $pii_failed ) : ?>
                                    <p class="ptk-nl-pii-error" id="ptk-nl-pii-error" role="alert">Your newsletter was saved as a draft, not published. Please tick this box to confirm the photos are OK, then press Publish again.</p>
                                <?php endif; ?>
                                <label>
                                    <input type="checkbox" id="ptk-nl-pii-ok" name="ptk_nl_pii_ok" value="1"<?php checked( '' !== $pii_date ); ?><?php echo $pii_failed ? ' aria-describedby="ptk-nl-pii-error"' : ''; ?>>
                                    <?php echo esc_html( self::PII_CHECKBOX_LABEL ); ?>
                                </label>
                                <?php if ( '' !== $pii_date ) : ?>
                                    <p class="ptk-nl-pii-note">Confirmed when this issue was published on <?php echo esc_html( date_i18n( 'F j, Y', strtotime( $pii_date ) ) ); ?>.</p>
                                <?php endif; ?>
                            </div>

                            <div class="ptk-nl-submit-row">
                                <?php if ( $is_published ) : ?>
                                    <?php /* Live already: no Save draft (it would take the newsletter down) and no
                                            Preview (View newsletter shows the real thing). handle_submission()
                                            keeps it published whatever is posted. */ ?>
                                    <button type="submit" name="ptk_nl_status" value="publish" class="button button-primary">Update</button>
                                    <a class="button button-secondary" href="<?php echo esc_url( get_permalink( $edit_id ) ); ?>" target="_blank" rel="noopener">View newsletter</a>
                                <?php else : ?>
                                    <button type="submit" name="ptk_nl_status" value="draft" class="button button-secondary">Save draft</button>
                                    <button type="submit" name="ptk_nl_status" value="preview" class="button button-secondary">Preview</button>
                                    <button type="submit" name="ptk_nl_status" value="publish" class="button button-primary">Publish</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php /* No data-step: the JS shows/hides these per step. */ ?>
                        <div class="ptk-nl-step-nav">
                            <button type="button" class="button ptk-nl-step-back" data-step-nav="prev">Back</button>
                            <button type="button" class="button button-primary ptk-nl-step-next" data-step-nav="next">Next</button>
                        </div>
                    </form>

                    <?php if ( $edit_id ) : ?>
                        <?php /* Rendered outside #ptk-nl-form on purpose: it has its own <form>s, and forms can't nest. */ ?>
                        <?php self::render_preview_panel( $edit_id ); ?>

                        <?php /* Same pattern as the preview panel: a sibling of #ptk-nl-form with its own
                                data-step="4", so showStep() owns it. It saves by AJAX, never by this form. */ ?>
                        <?php if ( class_exists( 'PTK_Share_Panel' ) ) : ?>
                            <?php PTK_Share_Panel::render( $edit_id ); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php /* The live preview is a COLUMN of the wizard, visible on every step — it must never carry
                        data-step, or the JS's "hide every non-current [data-step]" would hide it on steps 1-3. */ ?>
                <div class="ptk-nl-preview">
                    <?php /* Decorative on purpose: the iframe's own title ("Preview of your newsletter")
                            already names this region for a screen reader, so labelling it a second time
                            here would just make it say the same thing twice. This <p> is what SIGHTED
                            volunteers read — real markup rather than a CSS ::before, which isn't
                            reliably announced and can't be selected or translated. */ ?>
                    <p class="ptk-nl-preview-label" aria-hidden="true">Live preview</p>
                    <iframe id="ptk-nl-preview-frame" title="Preview of your newsletter"></iframe>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the "share a preview link" panel for an existing newsletter,
     * backed by the same no-login token system used for knowledge entries
     * (PTK_Public_Preview). Only appears in edit mode, since a brand-new
     * (unsaved) newsletter has no post id to attach a token to.
     *
     * Sharing a preview is part of finishing up, so the panel carries
     * data-step="4" and the wizard's JS shows it with the rest of that step.
     * It sits alongside #ptk-nl-form rather than inside it because its
     * no-JavaScript fallback is a pair of <form>s, and forms can't nest.
     *
     * With JavaScript, newsletter-builder.js turns those forms into AJAX
     * calls (handle_preview_link_ajax()) and swaps in the new body, so
     * nothing unsaved is lost. Without it, the forms post to admin.php and
     * come back to step 4 via ptk_nl_step.
     *
     * @param int $edit_id Existing, validated pta_newsletter post id.
     */
    protected static function render_preview_panel( $edit_id ) {
        // A preview link only opens drafts (PTK_Public_Preview matches
        // draft/pending/private/future) and its token is deleted on publish,
        // so offering one for a live newsletter would hand out a dead link.
        // A live newsletter's real address is the thing to share instead.
        if ( 'publish' === get_post_status( $edit_id ) ) {
            $live_url = (string) get_permalink( $edit_id );
            ?>
            <div class="ptk-nl-preview-panel" data-step="4">
                <h3>Link to your newsletter</h3>
                <p class="description"><label for="ptk-nl-live-url">Your newsletter is live. Anyone with this link can read it:</label></p>
                <input type="text" readonly value="<?php echo esc_attr( $live_url ); ?>" id="ptk-nl-live-url" onclick="this.select();" />
                <button type="button" class="button" data-ptk-copy="#ptk-nl-live-url">Copy link</button>
                <a class="button" href="<?php echo esc_url( $live_url ); ?>" target="_blank" rel="noopener">View newsletter</a>
            </div>
            <?php
            return;
        }
        ?>
        <div class="ptk-nl-preview-panel" data-step="4" data-preview-link-panel data-post-id="<?php echo esc_attr( $edit_id ); ?>">
            <h3>Share a preview link</h3>
            <p class="description">Let someone &#8212; like a principal or PTA president &#8212; see this draft before it&#8217;s published, without needing a login. The link stops working after 7 days.</p>
            <div data-preview-link-body>
                <?php echo self::preview_link_body_html( $edit_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped as it is built. ?>
            </div>
            <p class="ptk-nl-preview-link-status" data-preview-link-status role="status" aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * The part of the preview-link panel that changes: the live link and
     * Stop sharing, or the button that makes one. Returned as a string so
     * the AJAX handler can send back exactly what the page renders.
     *
     * @param int $edit_id Existing, validated pta_newsletter post id.
     * @return string
     */
    public static function preview_link_body_html( $edit_id ) {
        $preview_url = PTK_Public_Preview::active_preview_url( $edit_id );
        $return_url  = add_query_arg( array(
            'ptk_nl_edit_id' => $edit_id,
            'ptk_nl_step'    => 4,
        ), self::url() );
        // The generate/revoke handlers are registered on the
        // `admin_action_{$action}` hooks, which only wp-admin/admin.php fires
        // (NOT admin-post.php, whose hooks are `admin_post_{$action}`).
        $post_action = admin_url( 'admin.php' );

        ob_start();
        ?>
        <?php if ( $preview_url ) : ?>
            <p class="description"><label for="ptk-nl-preview-url">This link is live right now:</label></p>
            <input type="text" readonly value="<?php echo esc_attr( $preview_url ); ?>" id="ptk-nl-preview-url" onclick="this.select();" />
            <button type="button" class="button" data-ptk-copy="#ptk-nl-preview-url">Copy link</button>

            <form method="post" action="<?php echo esc_url( $post_action ); ?>" style="display:inline;margin-left:6px;" data-preview-link-action="revoke">
                <?php wp_nonce_field( 'ptk_revoke_preview_' . $edit_id ); ?>
                <input type="hidden" name="action" value="ptk_revoke_preview">
                <input type="hidden" name="post" value="<?php echo esc_attr( $edit_id ); ?>">
                <input type="hidden" name="ptk_preview_return" value="<?php echo esc_attr( $return_url ); ?>">
                <button type="submit" class="button button-link-delete">Stop sharing</button>
            </form>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( $post_action ); ?>" data-preview-link-action="generate">
                <?php wp_nonce_field( 'ptk_generate_preview_' . $edit_id ); ?>
                <input type="hidden" name="action" value="ptk_generate_preview">
                <input type="hidden" name="post" value="<?php echo esc_attr( $edit_id ); ?>">
                <input type="hidden" name="ptk_preview_return" value="<?php echo esc_attr( $return_url ); ?>">
                <button type="submit" class="button">Create a preview link (no login needed)</button>
            </form>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
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
     * Ordering and inclusion are NOT edited here — they live on step 4's
     * arrange list — so this renders no Move/Remove controls. The section
     * carries data-step for the type that owns it; it stays a direct child of
     * #ptk-nl-blocks either way.
     *
     * The header section is the one exception to "no values here": it ends
     * with the issue number and the issue date, which are real saved values
     * and belong to the newsletter as a whole rather than to any block's
     * JSON — see render_issue_details() for why they live in this card.
     *
     * @param array      $block    Block with a 'type' key ('data' is deliberately
     *                             ignored — see above).
     * @param bool       $excluded Whether this section is left out of the newsletter
     *                             (rendered anyway so it can be added back).
     * @param int|string $issue    Issue number to prefill (header section only).
     * @param string     $date     Issue date, Y-m-d (header section only).
     */
    protected static function render_block_section( $block, $excluded = false, $issue = '', $date = '' ) {
        $type   = isset( $block['type'] ) ? $block['type'] : '';
        $pinned = in_array( $type, array( 'header', 'footer' ), true );
        $label  = self::label_for_type( $type );
        ?>
        <section class="ptk-nl-block" data-type="<?php echo esc_attr( $type ); ?>" data-step="<?php echo (int) self::step_for_type( $type ); ?>"<?php echo $pinned ? ' data-pinned="1"' : ''; ?><?php echo $excluded ? ' data-excluded="1"' : ''; ?>>
            <div class="ptk-nl-block-header">
                <h3><?php echo esc_html( $label ); ?></h3>
                <?php if ( $pinned ) : ?>
                    <span class="ptk-nl-pinned-note">Always shown</span>
                <?php endif; ?>
            </div>

            <?php /* Sibling of the header, never inside it: the arrange list reads
                    '.ptk-nl-block-header h3' for this section's name, so nothing but
                    the name belongs in there. */ ?>
            <?php $intro = self::intro_for_type( $type ); ?>
            <?php if ( $intro ) : ?>
                <p class="description ptk-nl-block-intro"><?php echo esc_html( $intro ); ?></p>
            <?php endif; ?>

            <div class="ptk-nl-block-body">
                <?php self::render_block_fields( $type, array() ); ?>
                <?php if ( 'header' === $type ) : ?>
                    <?php self::render_issue_details( $issue, $date ); ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * The issue number and the issue date, at the FOOT of the Header card.
     *
     * They used to be a big card of their own above everything, which put
     * the two fields nobody edits in the most valuable space on the screen —
     * volunteers opened the builder and met paperwork instead of a place to
     * start writing. They're both filled in for us (next number, today), and
     * they both print inside the masthead, so this is where they belong:
     * last in the header, after the school name, headline and greeting.
     *
     * THREE THINGS THESE INPUTS MUST KEEP, or data goes missing quietly:
     *   - name="ptk_nl_issue" / name="ptk_nl_date" exactly. handle_submission()
     *     reads $_POST by those names, and the live preview binds its refresh
     *     to [name="ptk_nl_issue"], [name="ptk_nl_date"].
     *   - NO data-field attribute. serialize() collects every [data-field]
     *     inside a .ptk-nl-block into that block's JSON; one here would
     *     invent bogus keys in the header block's data. Issue and date are
     *     newsletter-level meta and travel as their own POST fields.
     *   - NO data-step of their own. The header section already carries
     *     data-step="1", which is where these belong anyway.
     * The wrapper is never toggled by JS, so its flex row is safe.
     *
     * @param int|string $issue Issue number.
     * @param string     $date  Issue date, Y-m-d.
     */
    protected static function render_issue_details( $issue, $date ) {
        // Unknown number (this site's first newsletter): blank and required,
        // with a prompt, instead of a confident but wrong "1".
        $unknown = absint( $issue ) < 1;
        ?>
        <div class="ptk-nl-issue-details">
            <h4><?php echo $unknown ? 'Issue details' : 'Issue details &#8212; we filled these in'; ?></h4>
            <div class="ptk-nl-issue-details-row">
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-issue">Issue number</label>
                    <?php if ( $unknown ) : ?>
                        <input type="number" id="ptk-nl-issue" name="ptk_nl_issue" value="" min="1" required aria-describedby="ptk-nl-issue-hint">
                        <p class="description" id="ptk-nl-issue-hint">What number is this issue? If you&#8217;ve sent newsletters before, use the next number.</p>
                    <?php else : ?>
                        <input type="number" id="ptk-nl-issue" name="ptk_nl_issue" value="<?php echo esc_attr( absint( $issue ) ); ?>" min="1">
                    <?php endif; ?>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-date">Issue date</label>
                    <input type="date" id="ptk-nl-date" name="ptk_nl_date" value="<?php echo esc_attr( $date ); ?>">
                </div>
            </div>
        </div>
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
                    <input type="text" id="ptk-nl-header-school_name" data-field="school_name" value="<?php echo esc_attr( isset( $data['school_name'] ) ? $data['school_name'] : '' ); ?>" aria-describedby="ptk-nl-header-school_name-hint">
                    <p class="description" id="ptk-nl-header-school_name-hint">Shown at the top of every newsletter.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-header-headline">Headline</label>
                    <input type="text" id="ptk-nl-header-headline" data-field="headline" value="<?php echo esc_attr( isset( $data['headline'] ) ? $data['headline'] : '' ); ?>" aria-describedby="ptk-nl-header-headline-hint">
                    <p class="description" id="ptk-nl-header-headline-hint">The big title at the top — for example "Week of September 14." Leave blank and we'll use the week of your issue date.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-header-summary">One-line summary</label>
                    <input type="text" id="ptk-nl-header-summary" data-field="summary" value="<?php echo esc_attr( isset( $data['summary'] ) ? $data['summary'] : '' ); ?>" aria-describedby="ptk-nl-header-summary-hint">
                    <p class="description" id="ptk-nl-header-summary-hint">Optional. One short line under the date saying what the issue is about. For example: ASE registration is open this week. About 60 characters fits on one line.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-header-greeting">Greeting</label>
                    <textarea id="ptk-nl-header-greeting" data-field="greeting" rows="2" aria-describedby="ptk-nl-header-greeting-hint"><?php echo esc_textarea( isset( $data['greeting'] ) ? $data['greeting'] : '' ); ?></textarea>
                    <p class="description" id="ptk-nl-header-greeting-hint">A friendly hello and what&#8217;s coming up. For example: Hi Northeast families &#8212; it&#8217;s the last week of school!</p>
                </div>
                <?php
                break;

            case 'announcement':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-when">When</label>
                    <input type="text" id="ptk-nl-announcement-when" data-field="when" value="<?php echo esc_attr( isset( $data['when'] ) ? $data['when'] : '' ); ?>" aria-describedby="ptk-nl-announcement-when-hint">
                    <p class="description" id="ptk-nl-announcement-when-hint">Optional. When it happens or closes, in a few words. For example: Closes Thursday, Sept 17 at noon. About 60 characters fits on one line.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-headline">Headline</label>
                    <input type="text" id="ptk-nl-announcement-headline" data-field="headline" value="<?php echo esc_attr( isset( $data['headline'] ) ? $data['headline'] : '' ); ?>" aria-describedby="ptk-nl-announcement-headline-hint">
                    <p class="description" id="ptk-nl-announcement-headline-hint">One sentence that says the news. For example: ASE registration opens Monday. PTA members go first. About 30 characters reads best at this size; longer still works.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-text">Text</label>
                    <textarea id="ptk-nl-announcement-text" data-field="text" rows="3" aria-describedby="ptk-nl-announcement-text-hint"><?php echo esc_textarea( isset( $data['text'] ) ? $data['text'] : '' ); ?></textarea>
                    <p class="description" id="ptk-nl-announcement-text-hint">A sentence or two with the details. For example: Twelve classes for grades K&#8211;5, Tuesdays and Wednesdays, 3 to 4 PM. About 90 characters keeps it to two lines.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-button_text">Button words</label>
                    <input type="text" id="ptk-nl-announcement-button_text" data-field="button_text" value="<?php echo esc_attr( isset( $data['button_text'] ) ? $data['button_text'] : '' ); ?>" aria-describedby="ptk-nl-announcement-button_text-hint">
                    <p class="description" id="ptk-nl-announcement-button_text-hint">Optional. What the button says. For example: Go to ASE registration</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-announcement-button_url">Button link</label>
                    <?php /* type="text", not "url": the browser would reject a bare email
                            address (leslie@example.org) and block the save before
                            sanitize_link_url() could turn it into an email link. */ ?>
                    <input type="text" inputmode="url" id="ptk-nl-announcement-button_url" data-field="button_url" value="<?php echo esc_attr( isset( $data['button_url'] ) ? $data['button_url'] : '' ); ?>" aria-describedby="ptk-nl-announcement-button_url-hint">
                    <p class="description" id="ptk-nl-announcement-button_url-hint">A web address (https://&#8230;) or an email address.</p>
                </div>
                <details class="ptk-nl-disclosure" data-disclosure>
                    <summary>Add dates to this announcement</summary>
                    <p class="description">Optional. One row for each date that matters. Rows in the past grey out by themselves, and the last row is shown as the deadline.</p>
                    <div class="ptk-nl-rows" data-rows data-rows-for="timeline"></div>
                    <button type="button" class="button ptk-nl-add">+ Add a date</button>
                    <template data-row-template>
                        <!-- Row fields intentionally have no static ids: assignRowIds() gives them out. -->
                        <div class="ptk-nl-row" data-row>
                            <div class="ptk-nl-field-group">
                                <label>Date</label>
                                <input type="date" data-field="date">
                                <p class="description">When.</p>
                            </div>
                            <div class="ptk-nl-field-group">
                                <label>Time</label>
                                <input type="text" data-field="time">
                                <p class="description">Optional. For example: 8:30 AM&#8211;12:30 PM, or noon.</p>
                            </div>
                            <div class="ptk-nl-field-group">
                                <label>What happens</label>
                                <input type="text" data-field="what">
                                <p class="description">For example: PTA members only, or Registration closes.</p>
                            </div>
                            <button type="button" class="button ptk-nl-remove-row">Remove</button>
                        </div>
                    </template>
                </details>
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
                            <p class="description">When it happens.</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Title</label>
                            <input type="text" data-field="title">
                            <p class="description">What it&#8217;s called. For example: Last Day of School</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Description</label>
                            <textarea data-field="desc" rows="2"></textarea>
                            <p class="description">One short line. For example: Early dismissal for students.</p>
                        </div>
                        <button type="button" class="button ptk-nl-remove-row">Remove</button>
                    </div>
                </template>
                <?php
                break;

            case 'featured':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-eyebrow">Short label</label>
                    <input type="text" id="ptk-nl-featured-eyebrow" data-field="eyebrow" value="<?php echo esc_attr( isset( $data['eyebrow'] ) ? $data['eyebrow'] : '' ); ?>" aria-describedby="ptk-nl-featured-eyebrow-hint">
                    <p class="description" id="ptk-nl-featured-eyebrow-hint">Optional. Two or three words naming the section, shown as &#8220;&#167; &#8230;&#8221; above the headline. For example: Date change. If you leave it blank we use &#8220;Top story&#8221;.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-headline">Headline</label>
                    <input type="text" id="ptk-nl-featured-headline" data-field="headline" value="<?php echo esc_attr( isset( $data['headline'] ) ? $data['headline'] : '' ); ?>" aria-describedby="ptk-nl-featured-headline-hint">
                    <p class="description" id="ptk-nl-featured-headline-hint">A whole sentence that carries the news. For example: Film on the Field moves to Friday, October 16. About 40 characters fits on one line.</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-body">Story</label>
                    <textarea id="ptk-nl-featured-body" data-field="body" rows="4" aria-describedby="ptk-nl-featured-body-hint"><?php echo esc_textarea( isset( $data['body'] ) ? $data['body'] : '' ); ?></textarea>
                    <p class="description" id="ptk-nl-featured-body-hint">A paragraph or two in your own words.</p>
                </div>
                <div class="ptk-nl-field-group ptk-nl-image-group" data-image-group>
                    <label>Image</label>
                    <input type="hidden" data-field="image_id" value="<?php echo esc_attr( isset( $data['image_id'] ) ? $data['image_id'] : 0 ); ?>">
                    <input type="hidden" data-field="image_focal_x" value="<?php echo esc_attr( isset( $data['image_focal_x'] ) ? $data['image_focal_x'] : 50 ); ?>">
                    <input type="hidden" data-field="image_focal_y" value="<?php echo esc_attr( isset( $data['image_focal_y'] ) ? $data['image_focal_y'] : 50 ); ?>">
                    <input type="hidden" data-field="image_zoom" value="<?php echo esc_attr( isset( $data['image_zoom'] ) ? $data['image_zoom'] : 0 ); ?>">
                    <?php /* Above the button, not below it: refreshImageChip() appends the
                            "Image #N selected" chip to the END of this group. The hint describes
                            the Add image button below it, not the hidden input above — a hidden
                            input is never exposed to assistive tech, so aria-describedby belongs
                            on the button, the only real control in this group. */ ?>
                    <p class="description" id="ptk-nl-featured-image-hint">Optional. Please don&#8217;t use photos of students&#8217; faces.</p>
                    <button type="button" class="button ptk-nl-add-image" aria-describedby="ptk-nl-featured-image-hint">Add image</button>
                    <?php /* image_fit is the ONE crop field with a visible control: a plain
                            select, so it needs no special-casing in getFieldValue/setFieldValue
                            (falls through to .val() like every non-image_id field). Hidden by
                            default; refreshFocalPicker() (assets/js/newsletter-builder.js) shows
                            it once an image is chosen, and shows/builds the focal-point picker
                            surface below it when the value is "crop". */ ?>
                    <select class="ptk-nl-image-fit" data-field="image_fit" style="display:none;">
                        <option value="whole"<?php selected( ! isset( $data['image_fit'] ) || 'crop' !== $data['image_fit'] ); ?>>Show whole photo</option>
                        <option value="crop"<?php selected( isset( $data['image_fit'] ) && 'crop' === $data['image_fit'] ); ?>>Crop to fit (16:9)</option>
                    </select>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-link_url">Link address</label>
                    <input type="url" id="ptk-nl-featured-link_url" data-field="link_url" value="<?php echo esc_attr( isset( $data['link_url'] ) ? $data['link_url'] : '' ); ?>" aria-describedby="ptk-nl-featured-link_url-hint">
                    <p class="description" id="ptk-nl-featured-link_url-hint">Optional. Where the link goes. For example: https://northeastpta.org/volunteer/</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-featured-link_text">Link wording</label>
                    <input type="text" id="ptk-nl-featured-link_text" data-field="link_text" value="<?php echo esc_attr( isset( $data['link_text'] ) ? $data['link_text'] : '' ); ?>" aria-describedby="ptk-nl-featured-link_text-hint">
                    <p class="description" id="ptk-nl-featured-link_text-hint">What the link says. For example: Sign up for a shift</p>
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
                            <label>Short label</label>
                            <input type="text" data-field="eyebrow">
                            <p class="description">Optional. Two or three words naming the section, shown as &#8220;&#167; &#8230;&#8221; above the headline. For example: Date change. If you leave it blank we use &#8220;More news&#8221;.</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Heading</label>
                            <input type="text" data-field="heading">
                            <p class="description">A whole sentence that carries the news. For example: Film on the Field moves to Friday, October 16. About 40 characters fits on one line.</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Story</label>
                            <textarea data-field="body" rows="3"></textarea>
                            <p class="description">A paragraph or two in your own words.</p>
                        </div>
                        <div class="ptk-nl-field-group ptk-nl-image-group" data-image-group>
                            <label>Image</label>
                            <input type="hidden" data-field="image_id" value="0">
                            <input type="hidden" data-field="image_focal_x" value="50">
                            <input type="hidden" data-field="image_focal_y" value="50">
                            <input type="hidden" data-field="image_zoom" value="0">
                            <?php /* Above the button, not below it: refreshImageChip() appends the
                                    "Image #N selected" chip to the END of this group. */ ?>
                            <p class="description">Optional. Please don&#8217;t use photos of students&#8217; faces.</p>
                            <button type="button" class="button ptk-nl-add-image">Add image</button>
                            <select class="ptk-nl-image-fit" data-field="image_fit" style="display:none;">
                                <option value="whole" selected>Show whole photo</option>
                                <option value="crop">Crop to fit (16:9)</option>
                            </select>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Link address</label>
                            <input type="url" data-field="link_url">
                            <p class="description">Where the link goes. For example: https://northeastpta.org/volunteer/</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Link wording</label>
                            <input type="text" data-field="link_text">
                            <p class="description">What the link says. For example: Sign up for a shift</p>
                        </div>
                        <button type="button" class="button ptk-nl-remove-row">Remove</button>
                    </div>
                </template>
                <?php
                break;

            case 'quick_notes':
                ?>
                <div class="ptk-nl-field-group">
                    <label for="ptk-nl-quick_notes-label">Group label</label>
                    <input type="text" id="ptk-nl-quick_notes-label" data-field="label" value="<?php echo esc_attr( isset( $data['label'] ) ? $data['label'] : '' ); ?>" aria-describedby="ptk-nl-quick_notes-label-hint">
                    <p class="description" id="ptk-nl-quick_notes-label-hint">Shown as &#8220;&#167; &#8230;&#8221; above the notes. For example: Good to know. If you leave it blank we use &#8220;Quick notes&#8221;.</p>
                </div>
                <div class="ptk-nl-rows" data-rows data-rows-for="items"></div>
                <button type="button" class="button ptk-nl-add">+ Add note</button>
                <template data-row-template>
                    <!-- Row fields intentionally have no static ids: assignRowIds() gives them out. -->
                    <div class="ptk-nl-row" data-row>
                        <div class="ptk-nl-field-group">
                            <label>Headline</label>
                            <input type="text" data-field="heading">
                            <p class="description">A few words. For example: Lunch menu. About 40 characters fits on one line.</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Text</label>
                            <textarea data-field="body" rows="2"></textarea>
                            <p class="description">A sentence or two. For example: This week&#8217;s menus are always on the site.</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Link address</label>
                            <?php /* type="text", not "url": an email address must be accepted (see the button link). */ ?>
                            <input type="text" inputmode="url" data-field="link_url">
                            <p class="description">Optional. A web address (https://&#8230;) or an email address.</p>
                        </div>
                        <div class="ptk-nl-field-group">
                            <label>Link wording</label>
                            <input type="text" data-field="link_text">
                            <p class="description">What the link says. For example: See the menu</p>
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
                    <textarea id="ptk-nl-footer-signoff" data-field="signoff" rows="2" aria-describedby="ptk-nl-footer-signoff-hint"><?php echo esc_textarea( isset( $data['signoff'] ) ? $data['signoff'] : '' ); ?></textarea>
                    <p class="description" id="ptk-nl-footer-signoff-hint">How you sign off. For example: With gratitude, Your PTA Board</p>
                </div>
                <div class="ptk-nl-field-group">
                    <label>Links</label>
                    <div class="ptk-nl-rows" data-rows data-rows-for="links"></div>
                    <button type="button" class="button ptk-nl-add">+ Add link</button>
                    <template data-row-template>
                        <!-- Row fields intentionally have no static ids: the later JS task assigns a unique id per cloned row and points each label's for at it. -->
                        <div class="ptk-nl-row" data-row>
                            <div class="ptk-nl-field-group">
                                <label>Link wording</label>
                                <input type="text" data-field="label">
                                <p class="description">What it says. For example: Full calendar</p>
                            </div>
                            <div class="ptk-nl-field-group">
                                <label>Link address</label>
                                <input type="url" data-field="url">
                                <p class="description">Where it goes.</p>
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
