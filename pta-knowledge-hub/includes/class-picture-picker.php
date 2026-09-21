<?php
/**
 * "Which picture?" -- our own picture picker, replacing wp.media's whole
 * media-library modal on the screens that opt in (see PAGES below).
 *
 * Built fresh rather than restyled: fighting core's media-modal markup is
 * exactly the trap the profile screen hit before. This class renders its
 * own modal (modal_markup()) once per page, enqueues its own JS/CSS, and
 * owns two AJAX actions:
 *
 *  - ptk_picture_list  Paged, searchable list of pictures already on the
 *                       site, share squares excluded. Read-only.
 *                       Capability: edit_posts.
 *  - ptk_picture_save  Either adds a new picture (a real file is posted,
 *                       via media_handle_upload() -- capability
 *                       upload_files) or records the "what's in this
 *                       picture?" answer as alt text on a picture already
 *                       chosen from the grid (no file posted -- capability
 *                       edit_posts, since nothing is being written to
 *                       disk). Both paths return the same
 *                       {id, url, alt} shape the JS hands back to whatever
 *                       opened the picker.
 *
 * Only active when PTK_Hub_Look::on() -- with the look off this class's
 * hooks still fire, but is_picker_screen()/enqueue() are inert, and the
 * old wp.media path in content-wizard.js runs exactly as it always has.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-picture-copy.php';

class PTK_Picture_Picker {

    const NONCE_ACTION = 'ptk_picture_picker';
    const PER_PAGE      = 24;

    /** page_ hook-suffix fragments (matched the way PTK_Hub_Look matches PAGES) that load the picker. */
    const PAGES = array( 'ptk-content-wizard', 'ptk-newsletter-builder' );

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_footer', array( __CLASS__, 'print_modal' ) );
        add_action( 'wp_ajax_ptk_picture_list', array( __CLASS__, 'ajax_list' ) );
        add_action( 'wp_ajax_ptk_picture_save', array( __CLASS__, 'ajax_save' ) );
    }

    /** Pure: does this admin hook suffix belong to a screen that uses the picker? */
    public static function is_picker_screen( $hook ) {
        $hook = (string) $hook;
        foreach ( self::PAGES as $page ) {
            if ( '' !== $page && false !== strpos( $hook, $page ) ) {
                return true;
            }
        }
        return false;
    }

    public static function enqueue( $hook ) {
        if ( ! PTK_Hub_Look::on() || ! self::is_picker_screen( $hook ) ) {
            return;
        }

        wp_enqueue_style( 'ptk-hub', PTK_PLUGIN_URL . 'assets/css/hub.css', array(), PTK_VERSION );

        // The framing surface (drag/pinch/wheel dot + crop frame) is the
        // same engine the Newsletter Builder already uses -- registering
        // the handles here (WP dedupes by handle) means Create Entry gets
        // it too, without the Builder's screen loading anything twice.
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
            'ptk-picture-picker',
            PTK_PLUGIN_URL . 'assets/js/picture-picker.js',
            array( 'jquery', 'ptk-focal-point-picker' ),
            PTK_VERSION,
            true
        );

        wp_localize_script( 'ptk-picture-picker', 'ptkPicturePicker', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'perPage' => self::PER_PAGE,
            'copy'    => PTK_Picture_Copy::strings(),
        ) );
    }

    /** Prints the (hidden) modal markup once, only on a picker screen with the look on. */
    public static function print_modal() {
        if ( ! PTK_Hub_Look::on() || ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! self::is_picker_screen( $screen->id ) ) {
            return;
        }
        echo self::modal_markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- modal_markup() escapes its own text.
    }

    /**
     * The modal's markup: a "choose" step (device + grid) and an "alt
     * text" step, both present in the DOM and toggled by JS with
     * [hidden]. Escaping matches PTK_Hub_UI's convention: every piece of
     * text is escaped here; nothing trusted-HTML is printed.
     */
    public static function modal_markup() {
        $c = PTK_Picture_Copy::strings();
        ob_start();
        ?>
        <div class="ptk-pic-modal" id="ptk-pic-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ptk-pic-modal-title">
            <div class="ptk-pic-modal-backdrop" data-ptk-pic-close></div>
            <div class="ptk-pic-modal-panel" role="document">
                <button type="button" class="ptk-pic-modal-close" data-ptk-pic-close aria-label="<?php echo esc_attr( $c['close'] ); ?>">&times;</button>
                <h2 id="ptk-pic-modal-title" class="ptk-pic-modal-title"><?php echo esc_html( $c['modal_title'] ); ?></h2>

                <div class="ptk-pic-step" id="ptk-pic-choose-step">
                    <div class="ptk-pic-drop" id="ptk-pic-drop">
                        <p class="ptk-pic-drop-label"><?php echo esc_html( $c['device_heading'] ); ?></p>
                        <p class="ptk-help"><?php echo esc_html( $c['device_help'] ); ?></p>
                        <label class="ptk-btn ptk-btn-primary ptk-pic-pick-btn" for="ptk-pic-file-input">
                            <?php echo esc_html( $c['pick_button'] ); ?>
                        </label>
                        <input type="file" accept="image/*" id="ptk-pic-file-input" class="screen-reader-text">
                        <p class="ptk-pic-progress" id="ptk-pic-progress" role="status" hidden></p>
                    </div>

                    <div class="ptk-pic-previous">
                        <p class="ptk-pic-previous-heading"><?php echo esc_html( $c['previous_heading'] ); ?></p>
                        <label class="screen-reader-text" for="ptk-pic-search"><?php echo esc_html( $c['search_label'] ); ?></label>
                        <input type="search" id="ptk-pic-search" class="ptk-pic-search" placeholder="<?php echo esc_attr( $c['search_placeholder'] ); ?>">
                        <div class="ptk-pic-grid" id="ptk-pic-grid" aria-live="polite"></div>
                        <button type="button" class="ptk-btn ptk-pic-more" id="ptk-pic-load-more" hidden><?php echo esc_html( $c['load_more'] ); ?></button>
                    </div>
                </div>

                <div class="ptk-pic-step" id="ptk-pic-alt-step" hidden>
                    <button type="button" class="ptk-pic-back" id="ptk-pic-back"><?php echo esc_html( $c['back_link'] ); ?></button>
                    <div class="ptk-pic-alt-preview" id="ptk-pic-alt-preview"></div>

                    <!-- Only shown when the caller opens with frame:true
                         (Create Entry). Off by default so the Newsletter
                         Builder -- which already has its own framing under
                         its own field -- keeps exactly one framing surface
                         on screen. See assets/js/picture-picker.js. -->
                    <div class="ptk-pic-frame-block" id="ptk-pic-frame-block" hidden>
                        <input type="hidden" data-field="image_focal_x" value="50">
                        <input type="hidden" data-field="image_focal_y" value="50">
                        <input type="hidden" data-field="image_zoom" value="0">
                        <input type="hidden" data-field="image_fit" id="ptk-pic-image-fit" value="whole">
                        <div class="ptk-pic-fit-toggle" id="ptk-pic-fit-toggle" role="group" aria-label="<?php echo esc_attr( $c['fit_group_label'] ); ?>">
                            <button type="button" class="ptk-pic-fit-btn" data-fit-value="whole"><?php echo esc_html( $c['fit_whole'] ); ?></button>
                            <button type="button" class="ptk-pic-fit-btn" data-fit-value="crop"><?php echo esc_html( $c['fit_crop'] ); ?></button>
                        </div>
                    </div>

                    <div class="ptk-field">
                        <label for="ptk-pic-alt-input"><?php echo esc_html( $c['alt_question'] ); ?></label>
                        <textarea id="ptk-pic-alt-input" rows="2"></textarea>
                        <p class="ptk-help"><?php echo esc_html( $c['alt_help'] ); ?></p>
                    </div>
                    <button type="button" class="ptk-btn ptk-btn-primary" id="ptk-pic-use-btn"><?php echo esc_html( $c['use_button'] ); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** AJAX: paged, searchable list of pictures, share squares excluded. Capability: edit_posts. */
    public static function ajax_list() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => PTK_Picture_Copy::strings()['no_access'] ), 403 );
        }

        $search = isset( $_REQUEST['search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search'] ) ) : '';
        $page   = isset( $_REQUEST['page'] ) ? max( 1, absint( $_REQUEST['page'] ) ) : 1;

        wp_send_json_success( self::query_pictures( $search, $page, self::PER_PAGE ) );
    }

    /**
     * Runs the actual attachment query and applies the share-square
     * filter. Over-fetches (batch = per_page * 3) so that filtering out
     * share squares still fills a page when the library holds many of
     * them; "hasMore" is therefore a slight over-estimate on a page where
     * filtering removes a lot, which only costs an extra, empty "load
     * more" click -- never a picture that silently never appears.
     */
    public static function query_pictures( $search, $page, $per_page ) {
        $batch = max( $per_page * 3, 30 );
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $batch,
            'paged'          => $page,
            's'              => $search,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $items = array();
        foreach ( $query->posts as $post ) {
            $file = get_attached_file( $post->ID );
            if ( PTK_Picture_Copy::is_share_square( wp_basename( (string) $file ), $post->post_title, $post->post_mime_type ) ) {
                continue;
            }
            $thumb   = wp_get_attachment_image_src( $post->ID, 'medium' );
            $items[] = array(
                'id'  => $post->ID,
                'url' => $thumb ? $thumb[0] : wp_get_attachment_url( $post->ID ),
                'alt' => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
            );
            if ( count( $items ) >= $per_page ) {
                break;
            }
        }

        return array(
            'items'   => $items,
            'hasMore' => (int) $query->max_num_pages > $page,
        );
    }

    /**
     * AJAX: adds a new picture (a real file posted as 'file') or records
     * the alt text for a picture already chosen from the grid ('attachment_id',
     * no file). Either way, saves alt with
     * update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field(...) )
     * and returns { id, url, alt }.
     */
    public static function ajax_save() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        $copy = PTK_Picture_Copy::strings();

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => $copy['no_access'] ), 403 );
        }

        $alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

        if ( ! empty( $_FILES['file'] ) ) {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error( array( 'message' => $copy['no_add_access'] ), 403 );
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            // media_handle_upload() calls wp_check_filetype_and_ext()
            // internally, which sniffs the real bytes rather than trusting
            // the extension or mime type the browser claims.
            $attachment_id = media_handle_upload( 'file', 0, array(), array( 'test_form' => false ) );
            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => $copy['add_failed'] ) );
            }

            $mime = get_post_mime_type( $attachment_id );
            if ( 0 !== strpos( (string) $mime, 'image/' ) ) {
                wp_delete_attachment( $attachment_id, true );
                wp_send_json_error( array( 'message' => $copy['not_a_picture'] ) );
            }
        } else {
            $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
            // Never trust a client-supplied id without confirming it really
            // is an image attachment.
            if ( ! $attachment_id
                || 'attachment' !== get_post_type( $attachment_id )
                || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
                wp_send_json_error( array( 'message' => $copy['not_found'] ) );
            }
        }

        // Choosing a picture is not the same as being allowed to rewrite
        // it. Someone who can write entries but not edit other people's
        // (a contributor) may still use any picture on the site -- they
        // just cannot change what one already says. WordPress's own media
        // screen draws the line in the same place.
        if ( current_user_can( 'edit_post', $attachment_id ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        } else {
            $existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            $alt      = is_string( $existing ) ? $existing : '';
        }

        $thumb = wp_get_attachment_image_src( $attachment_id, 'medium' );
        wp_send_json_success( array(
            'id'  => $attachment_id,
            'url' => $thumb ? $thumb[0] : wp_get_attachment_url( $attachment_id ),
            'alt' => $alt,
        ) );
    }
}
