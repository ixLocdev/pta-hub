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
 * A share-a-preview token link and the "Add New" redirect/edit-row-action
 * are a later task and not implemented here.
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
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
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

        if ( $edit_id ) {
            $post_data['ID'] = $edit_id;
            $post_id         = wp_update_post( $post_data, true );
        } else {
            $post_id = wp_insert_post( $post_data, true );
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

        $blocks       = PTK_Newsletter_Data::default_blocks();
        $issue_number = self::next_issue_number();
        $today        = date_i18n( 'Y-m-d' );

        ?>
        <div class="wrap ptk-nl-builder">
            <h1>New Newsletter</h1>
            <?php self::render_notice(); ?>
            <p class="ptk-nl-intro">Fill in the sections below — header and footer are always included, and you can add, remove, and reorder the sections in between.</p>

            <form method="post" id="ptk-nl-form">
                <?php wp_nonce_field( 'ptk_nl_save', 'ptk_nl_nonce' ); ?>

                <div class="ptk-nl-meta-row">
                    <div class="ptk-nl-field-group">
                        <label for="ptk-nl-issue">Issue number</label>
                        <input type="number" id="ptk-nl-issue" name="ptk_nl_issue" value="<?php echo esc_attr( $issue_number ); ?>" min="1">
                    </div>
                    <div class="ptk-nl-field-group">
                        <label for="ptk-nl-date">Issue date</label>
                        <input type="date" id="ptk-nl-date" name="ptk_nl_date" value="<?php echo esc_attr( $today ); ?>">
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
        </div>
        <?php
    }

    /**
     * Render one server-side block section.
     *
     * @param array $block Block with 'type' and 'data' keys.
     */
    protected static function render_block_section( $block ) {
        $type    = isset( $block['type'] ) ? $block['type'] : '';
        $data    = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : array();
        $pinned  = in_array( $type, array( 'header', 'footer' ), true );
        $label   = self::label_for_type( $type );
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
                <?php self::render_block_fields( $type, $data ); ?>
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
