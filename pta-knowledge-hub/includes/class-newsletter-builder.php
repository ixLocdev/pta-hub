<?php
/**
 * Newsletter Builder — guided form for creating a newsletter issue.
 *
 * Renders the "Add New" screen for the pta_newsletter post type as a
 * plain-English, section-by-section form instead of the block editor.
 * This is the FORM SKELETON only: the fixed fields (Header/Announcement/
 * Featured/Footer) and the repeatable-row placeholders (Events/Story
 * Cards) are rendered server-side with a `data-field` scheme a later JS
 * task will read/write, and a hidden `ptk_nl_blocks` field carries the
 * default layout as JSON for a later save handler to consume.
 *
 * No submission handling, no JS, and no PII controls live here yet —
 * those are separate, later tasks.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-data.php';

class PTK_Newsletter_Builder {

    const PAGE_SLUG = 'ptk-newsletter-builder';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
                    <label>School name</label>
                    <input type="text" data-field="school_name" value="<?php echo esc_attr( isset( $data['school_name'] ) ? $data['school_name'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label>Greeting</label>
                    <textarea data-field="greeting" rows="2"><?php echo esc_textarea( isset( $data['greeting'] ) ? $data['greeting'] : '' ); ?></textarea>
                </div>
                <?php
                break;

            case 'announcement':
                ?>
                <div class="ptk-nl-field-group">
                    <label>Pill label</label>
                    <input type="text" data-field="pill" value="<?php echo esc_attr( isset( $data['pill'] ) ? $data['pill'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label>Announcement text</label>
                    <textarea data-field="text" rows="3"><?php echo esc_textarea( isset( $data['text'] ) ? $data['text'] : '' ); ?></textarea>
                </div>
                <?php
                break;

            case 'events':
                ?>
                <div class="ptk-nl-rows" data-rows></div>
                <button type="button" class="button ptk-nl-add">+ Add event</button>
                <template data-row-template>
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
                    <label>Eyebrow</label>
                    <input type="text" data-field="eyebrow" value="<?php echo esc_attr( isset( $data['eyebrow'] ) ? $data['eyebrow'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label>Headline</label>
                    <input type="text" data-field="headline" value="<?php echo esc_attr( isset( $data['headline'] ) ? $data['headline'] : '' ); ?>">
                </div>
                <div class="ptk-nl-field-group">
                    <label>Story</label>
                    <textarea data-field="body" rows="4"><?php echo esc_textarea( isset( $data['body'] ) ? $data['body'] : '' ); ?></textarea>
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
                <div class="ptk-nl-rows" data-rows></div>
                <button type="button" class="button ptk-nl-add">+ Add story</button>
                <template data-row-template>
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
                    <label>Sign-off</label>
                    <textarea data-field="signoff" rows="2"><?php echo esc_textarea( isset( $data['signoff'] ) ? $data['signoff'] : '' ); ?></textarea>
                </div>
                <div class="ptk-nl-field-group">
                    <label>Links</label>
                    <div class="ptk-nl-rows" data-rows data-rows-for="links"></div>
                    <button type="button" class="button ptk-nl-add">+ Add link</button>
                    <template data-row-template>
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
