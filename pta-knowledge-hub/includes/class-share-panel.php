<?php
/**
 * "Share this newsletter" panel on the Builder's last step.
 *
 * Hands a volunteer ready-to-paste Facebook / Instagram / WhatsApp text
 * plus a square picture. Nothing posts by itself.
 *
 * WHERE IT LIVES, AND WHY: pta_newsletter has no post-publish screen and no
 * edit-screen sidebar -- redirect_edit_to_builder() sends every edit into
 * the Builder page, and handle_submission() owns the save redirect. So the
 * panel is rendered by PTK_Newsletter_Builder::render_page() on step 4, as
 * a SIBLING of #ptk-nl-form (forms cannot nest) carrying data-step="4",
 * exactly like the preview-link panel. The Builder's showStep() owns its
 * visibility; nothing here may hide it or make it display:flex.
 *
 * It never fatals and never shows a broken image: no GD, no FreeType, or a
 * WP_Error from ensure_square() each degrade to a plain sentence, and the
 * captions keep working in every case.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Panel {

    const NONCE_ACTION = 'ptk_nl_share';

    /** Longest caption we will store. Facebook's own limit is far above any real post. */
    const MAX_CAPTION = 20000;

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_ptk_nl_share_save', array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_ptk_nl_share_reset', array( __CLASS__, 'ajax_reset' ) );
        add_action( 'wp_ajax_ptk_nl_share_square', array( __CLASS__, 'ajax_square' ) );
    }

    /**
     * Assets for the Builder page only.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'pta_newsletter_page_' . PTK_Newsletter_Builder::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'ptk-share-panel',
            PTK_PLUGIN_URL . 'assets/css/share-panel.css',
            array( 'ptk-newsletter-builder' ),
            PTK_VERSION
        );

        // Its OWN file, deliberately not part of newsletter-builder.js: a throw
        // in the Builder's boot block leaves the whole wizard inert, and the
        // share panel is optional where the Builder is not.
        wp_enqueue_script(
            'ptk-share-panel',
            PTK_PLUGIN_URL . 'assets/js/share-panel.js',
            array( 'jquery' ),
            PTK_VERSION,
            true
        );

        wp_localize_script( 'ptk-share-panel', 'ptkNlShare', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
        ) );
    }

    /* ------------------------------------------------------------------
     * AJAX. Every handler: nonce, then edit_post on THIS post, then that
     * the post really is a pta_newsletter. The Builder's preview endpoint
     * checks only edit_posts -- right for a stateless render, wrong for a
     * per-post write, so it is not the model here; handle_submission() is.
     * ------------------------------------------------------------------ */

    /**
     * Refuse the request unless it may write to the posted newsletter.
     *
     * @return int The post id (never returns otherwise).
     */
    protected static function authorize_request() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id || 'pta_newsletter' !== get_post_type( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'That newsletter could not be found.' ), 404 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to edit this newsletter.' ), 403 );
        }

        return $post_id;
    }

    /**
     * The posted channel, or a refusal.
     *
     * @return string
     */
    protected static function request_channel() {
        $channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
        if ( ! in_array( $channel, PTK_Share_Data::CHANNELS, true ) ) {
            wp_send_json_error( array( 'message' => 'Unknown channel.' ), 400 );
        }
        return $channel;
    }

    /**
     * Save one channel's edited caption, with the hash of what it was
     * written against so the stale notice can fire later.
     */
    public static function ajax_save() {
        $post_id = self::authorize_request();
        $channel = self::request_channel();

        // Plain text, never rendered as HTML (esc_textarea on the way out).
        // Not sanitize_textarea_field(): it strips %XX sequences, which
        // would quietly break any link a volunteer pastes.
        $text = isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : '';
        $text = wp_check_invalid_utf8( $text, true );
        $text = str_replace( "\0", '', $text );
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        if ( function_exists( 'mb_substr' ) ) {
            $text = mb_substr( $text, 0, self::MAX_CAPTION );
        } else {
            $text = substr( $text, 0, self::MAX_CAPTION );
        }

        // Belt and braces for the read-only draft preview: a tab left open from
        // before an unpublish, or a pagehide beacon, must not freeze a caption
        // that has no link in it.
        if ( ! self::is_published( $post_id ) ) {
            wp_send_json_error(
                array( 'message' => 'Publish the newsletter first -- the posts get their link when it goes live.' ),
                409
            );
        }

        $ctx = self::context( $post_id );

        if ( '' === trim( $text ) ) {
            // An emptied box has nothing worth keeping; the next load
            // generates afresh rather than showing a blank post.
            PTK_Share_Data::delete_caption( $post_id, $channel );
        } else {
            // update_post_meta() unslashes, so slash first or backslashes vanish.
            PTK_Share_Data::save_caption( $post_id, $channel, wp_slash( $text ), self::caption_hash( $ctx ) );
        }

        wp_send_json_success( array(
            'saved'       => true,
            'whatsappUrl' => self::whatsapp_url( $text ),
        ) );
    }

    /**
     * Forget one channel's edits and hand back freshly generated text.
     */
    public static function ajax_reset() {
        $post_id = self::authorize_request();
        $channel = self::request_channel();

        PTK_Share_Data::delete_caption( $post_id, $channel );

        $ctx     = self::context( $post_id );
        $caption = PTK_Share_Data::resolve_caption( $post_id, $channel, $ctx['blocks'], $ctx['opts'] );

        wp_send_json_success( array(
            'text'        => $caption['text'],
            'whatsappUrl' => self::whatsapp_url( $caption['text'] ),
        ) );
    }

    /**
     * Switch the Instagram picture: mode=custom with an attachment_id, or
     * mode=generated. Replies with the re-rendered picture area.
     */
    public static function ajax_square() {
        $post_id = self::authorize_request();
        $mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

        if ( 'custom' === $mode ) {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error( array( 'message' => 'You do not have permission to add pictures.' ), 403 );
            }

            $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
            if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => 'Please choose a picture.' ), 400 );
            }

            // The generated square this replaces is ours: remove it rather
            // than leave an orphan in the media library. Never an upload.
            $square = PTK_Share_Data::get_square( $post_id );
            if ( ! $square['custom'] && $square['image_id'] && (int) $square['image_id'] !== $attachment_id
                && 'attachment' === get_post_type( $square['image_id'] ) ) {
                wp_delete_attachment( $square['image_id'], true );
            }

            PTK_Share_Data::save_square( $post_id, $attachment_id, true, '' );
        } elseif ( 'generated' === $mode ) {
            PTK_Share_Image::use_generated_square( $post_id );
        } else {
            wp_send_json_error( array( 'message' => 'Unknown picture choice.' ), 400 );
        }

        wp_send_json_success( array(
            'html' => self::square_html( $post_id, self::context( $post_id ) ),
        ) );
    }

    /**
     * Channel => heading.
     *
     * @return array
     */
    protected static function channel_labels() {
        return array(
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'whatsapp'  => 'WhatsApp',
        );
    }

    /**
     * Is this a newsletter families can actually open? Only a published
     * one gets real links and the QR -- a draft's permalink 404s for them.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_published( $post_id ) {
        return 'publish' === get_post_status( $post_id );
    }

    /**
     * Everything the captions and the square are made from, read from the
     * saved newsletter (never from the request).
     *
     * Deliberately NOT PTK_Newsletter_Builder::render_opts(): that is
     * private and has no url.
     *
     * @param int $post_id
     * @return array{blocks:array,opts:array}
     */
    public static function context( $post_id ) {
        $blocks = PTK_Newsletter_Data::sanitize_blocks(
            json_decode( (string) get_post_meta( $post_id, 'ptk_nl_blocks', true ), true )
        );

        $issue = absint( get_post_meta( $post_id, 'ptk_nl_issue', true ) );
        $date  = (string) get_post_meta( $post_id, 'ptk_nl_date', true );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = '';
        }

        $opts = array(
            // A draft gets no url at all: never hand out a link that 404s.
            'url'         => self::is_published( $post_id ) ? (string) get_permalink( $post_id ) : '',
            'issue'       => $issue ? $issue : '',
            'date'        => $date,
            'school_name' => self::school_name( $blocks ),
            'today'       => current_time( 'Y-m-d' ),
        );

        return array(
            'blocks' => $blocks,
            'opts'   => $opts,
        );
    }

    /**
     * The header block's school name, else the site name.
     *
     * @param array $blocks Sanitized blocks.
     * @return string
     */
    protected static function school_name( array $blocks ) {
        foreach ( $blocks as $block ) {
            if ( isset( $block['type'] ) && PTK_Newsletter_Data::TYPE_HEADER === $block['type'] ) {
                $name = isset( $block['data']['school_name'] ) ? trim( (string) $block['data']['school_name'] ) : '';
                if ( '' !== $name ) {
                    return $name;
                }
                break;
            }
        }

        return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    }

    /**
     * The hash stored alongside a caption so the stale notice works.
     *
     * @param array $ctx From context().
     * @return string
     */
    public static function caption_hash( array $ctx ) {
        $o = $ctx['opts'];
        return PTK_Share_Data::caption_inputs_hash( $ctx['blocks'], $o['url'], $o['issue'], $o['date'], $o['school_name'] );
    }

    /**
     * The WhatsApp hand-off link for a caption.
     *
     * @param string $text
     * @return string
     */
    public static function whatsapp_url( $text ) {
        return 'https://wa.me/?text=' . rawurlencode( (string) $text );
    }

    /**
     * The PTA's Facebook group, or '' when none is set (then no link-out
     * is shown at all -- never a dead button).
     *
     * @return string
     */
    public static function facebook_url() {
        $url = trim( (string) get_option( 'ptk_share_facebook_url', '' ) );
        return '' === $url ? '' : esc_url_raw( $url );
    }

    /**
     * Render the panel. Called by PTK_Newsletter_Builder::render_page()
     * OUTSIDE #ptk-nl-form, only for an existing, validated newsletter.
     *
     * @param int $post_id
     */
    public static function render( $post_id ) {
        $post_id   = absint( $post_id );
        $ctx       = self::context( $post_id );
        $published = self::is_published( $post_id );
        $caps      = PTK_Share_Image::capabilities();
        ?>
        <div class="ptk-nl-share-panel" data-step="4" data-share-panel data-post-id="<?php echo esc_attr( $post_id ); ?>">
            <div class="ptk-nl-share-intro">
                <h3>Share this newsletter</h3>
                <p class="description">Ready-to-paste posts for your PTA&#8217;s pages and groups. Nothing is posted for you &#8212; copy, paste, and change anything you like. Your changes are kept.</p>
                <?php if ( ! $published ) : ?>
                    <p class="ptk-nl-share-note">These are previews. Once this newsletter is published, the link is added to each post and you can change and copy them here.</p>
                <?php endif; ?>
            </div>

            <?php foreach ( self::channel_labels() as $channel => $label ) : ?>
                <?php
                $caption = PTK_Share_Data::resolve_caption( $post_id, $channel, $ctx['blocks'], $ctx['opts'] );
                $field   = 'ptk-nl-share-' . $channel;
                ?>
                <section class="ptk-nl-share-channel" data-share-channel="<?php echo esc_attr( $channel ); ?>"<?php echo $caption['stored'] ? ' data-dirty="1"' : ''; ?>>
                    <h4><?php echo esc_html( $label ); ?></h4>

                    <p class="ptk-nl-share-stale" data-share-stale<?php echo ( $caption['stored'] && $caption['stale'] ) ? '' : ' hidden'; ?>>The newsletter changed since you edited this.</p>

                    <label class="screen-reader-text" for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label . ' post text' ); ?></label>
                    <?php
                    // A draft has no permalink yet, so its captions carry no link. Letting
                    // a volunteer edit one would freeze a post that goes out link-less the
                    // moment they press Publish -- which sits on this same step. Previews
                    // only, until the link exists.
                    ?>
                    <textarea id="<?php echo esc_attr( $field ); ?>" class="ptk-nl-share-text" rows="<?php echo 'whatsapp' === $channel ? 5 : 9; ?>" data-share-text<?php echo $published ? '' : ' readonly aria-readonly="true"'; ?>><?php echo esc_textarea( $caption['text'] ); ?></textarea>

                    <?php if ( $published ) : ?>
                    <div class="ptk-nl-share-actions">
                        <button type="button" class="button button-primary" data-share-copy>Copy text</button>
                        <?php if ( 'facebook' === $channel && $published && '' !== self::facebook_url() ) : ?>
                            <a class="button" href="<?php echo esc_url( self::facebook_url() ); ?>" target="_blank" rel="noopener noreferrer">Open your Facebook group</a>
                        <?php endif; ?>
                        <?php if ( 'whatsapp' === $channel && $published ) : ?>
                            <a class="button" data-share-whatsapp href="<?php /* esc_attr, NOT esc_url: esc_url strips %0A and would glue the lines together. We build this URL ourselves: fixed https scheme, rawurlencoded text. */ echo esc_attr( self::whatsapp_url( $caption['text'] ) ); ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp</a>
                        <?php endif; ?>
                        <button type="button" class="button-link ptk-nl-share-reset" data-share-reset>Reset to generated</button>
                        <span class="ptk-nl-share-status" data-share-status role="status" aria-live="polite"></span>
                    </div>
                    <?php endif; ?>

                    <?php if ( 'instagram' === $channel ) : ?>
                        <div class="ptk-nl-share-square" data-share-square>
                            <?php echo self::square_html( $post_id, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in square_html(). ?>
                        </div>
                        <?php self::render_phone_handoff( $post_id, $published, $caps ); ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * The Instagram picture area: the square (generated or uploaded) and
     * its controls, or a plain sentence where a picture cannot be shown.
     * Returned as a string so the AJAX handlers can send back the same
     * markup after a change.
     *
     * @param int   $post_id
     * @param array $ctx From context().
     * @return string
     */
    public static function square_html( $post_id, array $ctx ) {
        $caps       = PTK_Share_Image::capabilities();
        $square     = PTK_Share_Data::get_square( $post_id );
        $can_upload = current_user_can( 'upload_files' );
        $result     = null;

        if ( $square['custom'] || $caps['freetype'] ) {
            $result = PTK_Share_Image::ensure_square( $post_id, array(
                'issue'       => $ctx['opts']['issue'],
                'date'        => $ctx['opts']['date'],
                'school_name' => $ctx['opts']['school_name'],
                // 4.3.0: the square's two colors, neither one falling back
                // to the Council palette (round-2 amendment) -- see
                // PTK_Share_Color::square_background_color()/square_text_color().
                'background'  => PTK_Share_Color::square_background_color(),
                'text'        => PTK_Share_Color::square_text_color(),
            ) );
        }

        $image_url = '';
        $full_url  = '';
        $message   = '';

        if ( is_wp_error( $result ) ) {
            $message = $result->get_error_message();
        } elseif ( $result ) {
            $image_url = (string) wp_get_attachment_image_url( $result, 'medium_large' );
            $full_url  = (string) wp_get_attachment_url( $result );
            if ( '' === $image_url ) {
                $image_url = $full_url;
            }
            if ( '' === $image_url ) {
                $message = 'The square picture could not be found. Upload one instead.';
            }
        } else {
            $message = 'Upload a square picture for Instagram.';
        }

        ob_start();
        ?>
        <?php if ( '' !== $image_url ) : ?>
            <figure class="ptk-nl-share-figure">
                <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $square['custom'] ? 'Your square picture for Instagram' : 'Square picture for Instagram with this issue number and date' ); ?>" width="270" height="270">
                <figcaption><?php echo $square['custom'] ? 'Your own picture.' : 'Made for you from this issue.'; ?></figcaption>
            </figure>
        <?php else : ?>
            <p class="ptk-nl-share-note"><?php echo esc_html( $message ); ?></p>
        <?php endif; ?>

        <div class="ptk-nl-share-actions">
            <?php if ( '' !== $full_url ) : ?>
                <a class="button" href="<?php echo esc_url( $full_url ); ?>" download>Save the picture</a>
            <?php endif; ?>
            <?php if ( $can_upload ) : ?>
                <button type="button" class="button" data-share-upload><?php echo '' !== $image_url ? 'Upload your own instead' : 'Upload a square picture'; ?></button>
            <?php endif; ?>
            <?php if ( $square['custom'] && $caps['freetype'] ) : ?>
                <button type="button" class="button-link" data-share-generated>Use the generated square again</button>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The QR code that hands the kit to a phone -- published newsletters
     * on servers with GD only.
     *
     * @param int   $post_id
     * @param bool  $published
     * @param array $caps From PTK_Share_Image::capabilities().
     */
    protected static function render_phone_handoff( $post_id, $published, array $caps ) {
        if ( ! $caps['gd'] ) {
            echo '<p class="ptk-nl-share-note">Sending this to your phone isn&#8217;t available on this website. Copy the text above instead.</p>';
            return;
        }

        if ( ! $published ) {
            // The panel intro already says the handoff waits for publishing.
            return;
        }

        $target = add_query_arg( 'ptk_share', $post_id, home_url( '/' ) );
        $qr     = class_exists( 'PTK_QR_Codes' ) ? PTK_QR_Codes::png_data_url( $target, 2, 4 ) : '';

        if ( '' === $qr ) {
            echo '<p class="ptk-nl-share-note">The phone code couldn&#8217;t be made just now. Try reloading this page.</p>';
            return;
        }
        ?>
        <div class="ptk-nl-share-phone">
            <img src="<?php echo esc_attr( $qr ); ?>" alt="QR code that opens these posts on your phone">
            <p class="description">Posting from your phone? Scan this to open the picture and the text there.</p>
        </div>
        <?php
    }
}
