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
 * panel is rendered by PTK_Newsletter_Builder::render_page() on the
 * "Publish & share" step (round 3.1 split this off step 4's old, more
 * crowded "Finish & publish" step), as a SIBLING of #ptk-nl-form (forms
 * cannot nest) carrying data-step="5", exactly like the preview-link
 * panel. The Builder's showStep() owns its visibility; nothing here may
 * hide it or make it display:flex.
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
        add_action( 'wp_ajax_ptk_nl_share_photo_colors', array( __CLASS__, 'ajax_photo_colors' ) );
    }

    /**
     * Assets for the Builder page only.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets( $hook ) {
        // Compares against the Builder's OWN captured hook, never a
        // hand-built 'pta_newsletter_page_...' string — the share panel
        // has no add_page() of its own (it renders on the Builder's step
        // 4), and that string stopped being derivable by hand once the
        // 4.3.0 menu move nested pta_newsletter under PTA Hub.
        $builder_hook = PTK_Newsletter_Builder::page_hook();
        if ( '' === $builder_hook || $hook !== $builder_hook ) {
            return;
        }

        wp_enqueue_style(
            'ptk-share-panel',
            PTK_PLUGIN_URL . 'assets/css/share-panel.css',
            array( 'ptk-newsletter-builder' ),
            PTK_VERSION
        );

        // The same picker the Builder's image fields use (round 3), at a
        // 1:1 frame for the square's background photo. wp_enqueue_media()
        // is already called by the Builder page this panel always shares
        // (class docblock: rendered by render_page() on step 4).
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

        // Its OWN file, deliberately not part of newsletter-builder.js: a throw
        // in the Builder's boot block leaves the whole wizard inert, and the
        // share panel is optional where the Builder is not.
        wp_enqueue_script(
            'ptk-share-panel',
            PTK_PLUGIN_URL . 'assets/js/share-panel.js',
            array( 'jquery', 'ptk-focal-point-picker' ),
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
     * Switch the Instagram picture: mode=custom with an attachment_id,
     * mode=generated, or (round 3) mode=photo/photo_from_featured/
     * no_photo for the "photo behind the words" background layer.
     * Replies with the re-rendered picture area.
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

            // A school's own finished upload has already made every layout
            // decision GD would otherwise make -- a leftover "photo behind
            // the words" choice from before this upload must not resurrect
            // itself if the school later switches back to "generated".
            PTK_Share_Data::clear_square_photo( $post_id );
        } elseif ( 'generated' === $mode ) {
            PTK_Share_Image::use_generated_square( $post_id );
        } elseif ( 'photo' === $mode || 'photo_from_featured' === $mode ) {
            // Round 3.1 fix (item 2): adding or switching the square's
            // background photo always requires a FRESH tick of this
            // request's consent checkbox -- a past confirmation, even one
            // still on file, covered a photo set that this action is about
            // to change, so it can never be enough on its own.
            self::require_photo_consent( $post_id );

            if ( 'photo_from_featured' === $mode ) {
                // Never a value round-tripped from the client: read the
                // newsletter's OWN saved top-story photo server-side, so
                // there is no way this action can attach a photo the
                // newsletter doesn't already contain.
                $ctx      = self::context( $post_id );
                $photo_id = 0;
                foreach ( $ctx['blocks'] as $block ) {
                    if ( isset( $block['type'] ) && PTK_Newsletter_Data::TYPE_FEATURED === $block['type'] ) {
                        $photo_id = isset( $block['data']['image_id'] ) ? absint( $block['data']['image_id'] ) : 0;
                        break;
                    }
                }
                if ( ! $photo_id ) {
                    wp_send_json_error( array( 'message' => 'This newsletter has no top-story photo to use yet.' ), 400 );
                }
                PTK_Share_Data::save_square_photo( $post_id, $photo_id, 50, 50, 0 );
            } else {
                $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
                if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
                    wp_send_json_error( array( 'message' => 'Please choose a picture.' ), 400 );
                }
                $focal_x = isset( $_POST['focal_x'] ) ? $_POST['focal_x'] : 50;
                $focal_y = isset( $_POST['focal_y'] ) ? $_POST['focal_y'] : 50;
                $zoom    = isset( $_POST['zoom'] ) ? $_POST['zoom'] : 0;
                PTK_Share_Data::save_square_photo( $post_id, $attachment_id, $focal_x, $focal_y, $zoom );
            }

            // The consent just given above covers exactly the photos on the
            // newsletter now -- record that set (and bump the date: this
            // IS a fresh confirmation), so the next comparison is accurate.
            $confirmed_now = PTK_Newsletter_Builder::current_photo_ids( self::context( $post_id )['blocks'], $post_id );
            update_post_meta( $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED, current_time( 'Y-m-d' ) );
            update_post_meta( $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED_PHOTOS, wp_json_encode( $confirmed_now ) );
        } elseif ( 'photo_reframe' === $mode ) {
            // Adjust ONLY the focal point/zoom of the photo already chosen
            // (the picker fires this on every drag/wheel/keyboard change) --
            // no new consent needed, this doesn't add or change WHICH photo.
            $current = PTK_Share_Data::get_square_photo( $post_id );
            if ( ! $current['photo_id'] ) {
                wp_send_json_error( array( 'message' => 'Choose a photo first.' ), 400 );
            }
            $focal_x = isset( $_POST['focal_x'] ) ? $_POST['focal_x'] : 50;
            $focal_y = isset( $_POST['focal_y'] ) ? $_POST['focal_y'] : 50;
            $zoom    = isset( $_POST['zoom'] ) ? $_POST['zoom'] : 0;
            PTK_Share_Data::save_square_photo( $post_id, $current['photo_id'], $focal_x, $focal_y, $zoom );
        } elseif ( 'no_photo' === $mode ) {
            PTK_Share_Data::clear_square_photo( $post_id );

            // Removing a photo needs no fresh consent, but the confirmed
            // set on file must drop it too -- otherwise re-adding the SAME
            // attachment later would wrongly read back as "already
            // confirmed" via a stale set that happens to still list it.
            if ( get_post_meta( $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED, true ) ) {
                $remaining = PTK_Newsletter_Builder::current_photo_ids( self::context( $post_id )['blocks'], $post_id );
                update_post_meta( $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED_PHOTOS, wp_json_encode( $remaining ) );
            }
        } else {
            wp_send_json_error( array( 'message' => 'Unknown picture choice.' ), 400 );
        }

        wp_send_json_success( array(
            'html' => self::square_html( $post_id, self::context( $post_id ) ),
        ) );
    }

    /**
     * The photo-privacy gate, extended to the square's background photo
     * (round 3): setting/changing one is reachable via this AJAX endpoint
     * entirely outside the main form's Publish-time gate (a newsletter with
     * no OTHER photos never trips it), so this checks consent independently,
     * reusing the SAME meta key -- see class docblock's "no second consent
     * mechanism" decision.
     *
     * Round 3.1 fix (item 2): this ALWAYS requires this request's own
     * `pii_ok` -- it never treats an existing META_PII_CONFIRMED as
     * sufficient on its own, because the whole point of calling this is
     * that the photo is about to change, which is exactly the case the old
     * "already confirmed, skip" shortcut let slip through (a stale
     * confirmation carrying over onto a photo it never covered). The
     * caller records the fresh confirmation (date + exact photo set) once
     * the new photo is actually saved.
     *
     * Sends a 409 and stops the request if confirmation is missing.
     *
     * @param int $post_id
     */
    protected static function require_photo_consent( $post_id ) {
        if ( empty( $_POST['pii_ok'] ) ) {
            wp_send_json_error(
                array(
                    'message'  => 'Please confirm the photos are OK before using one on the Instagram square — the same checkbox as Publish.',
                    'needsPii' => true,
                    'piiLabel' => PTK_Newsletter_Builder::PII_CHECKBOX_LABEL,
                ),
                409
            );
        }
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
        <div class="ptk-nl-share-panel" data-step="5" data-share-panel data-post-id="<?php echo esc_attr( $post_id ); ?>">
            <div class="ptk-nl-share-intro">
                <h3>Share this newsletter</h3>
                <p class="description">Ready-to-paste posts for your PTA&#8217;s pages and groups. Nothing is posted for you &#8212; copy, paste, and change anything you like. Your changes are kept.</p>
                <?php if ( ! $published ) : ?>
                    <p class="ptk-nl-share-note">These are previews. Once this newsletter is published, the link is added to each post and you can change and copy them here.</p>
                <?php endif; ?>
            </div>

            <?php
            // Round 3.1 (spec item 5): collapsible channels, one open at a
            // time, Facebook open by default -- progressive disclosure so
            // "Publish & share" doesn't show three full post-texts at once.
            // <details>/<summary> is the native, no-JS-required collapse;
            // share-panel.js's bindChannelDisclosures() is the only bit
            // that enforces "one at a time" (closing the others when one
            // opens) and needs no changes to anything else in this file.
            $first_channel = true;
            foreach ( self::channel_labels() as $channel => $label ) :
                $caption = PTK_Share_Data::resolve_caption( $post_id, $channel, $ctx['blocks'], $ctx['opts'] );
                $field   = 'ptk-nl-share-' . $channel;
                ?>
                <details class="ptk-nl-share-channel" data-share-channel="<?php echo esc_attr( $channel ); ?>"<?php echo $caption['stored'] ? ' data-dirty="1"' : ''; ?><?php echo $first_channel ? ' open' : ''; ?>>
                    <summary><h4><?php echo esc_html( $label ); ?></h4></summary>
                    <?php $first_channel = false; ?>

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
                </details>
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
        $photo      = PTK_Share_Data::get_square_photo( $post_id );
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
                // Round 3: "photo behind the words". photo_id 0 is a no-op
                // -- the flat square, exactly as before.
                'photo_id'      => $photo['photo_id'],
                'photo_focal_x' => $photo['focal_x'],
                'photo_focal_y' => $photo['focal_y'],
                'photo_zoom'    => $photo['zoom'],
                // Round 3.1: the photo-only text/bar pair (item 4) -- only
                // used by render_png() when a photo is actually drawn.
                'photo_text'    => PTK_Share_Color::square_photo_text_color(),
                'photo_bar'     => PTK_Share_Color::square_photo_bar_color(),
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

        <?php if ( ! $square['custom'] ) : ?>
            <?php echo self::square_photo_html( $post_id, $ctx, $photo ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped below. ?>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * "Use a photo behind the words": the section that lets a volunteer
     * put a real photo under the generated square's text instead of a
     * flat color. A school's own finished upload (`$square['custom']`)
     * has nothing to layer a photo behind, so square_html() only calls
     * this when there is a generated square to modify (spec Part E).
     *
     * @param int   $post_id
     * @param array $ctx   From context().
     * @param array $photo From PTK_Share_Data::get_square_photo().
     * @return string
     */
    protected static function square_photo_html( $post_id, array $ctx, array $photo ) {
        $featured_image_id = 0;
        foreach ( $ctx['blocks'] as $block ) {
            if ( isset( $block['type'] ) && PTK_Newsletter_Data::TYPE_FEATURED === $block['type'] ) {
                $featured_image_id = isset( $block['data']['image_id'] ) ? absint( $block['data']['image_id'] ) : 0;
                break;
            }
        }

        // Round 3.1 fix (item 2): "confirmed" here means confirmed for the
        // EXACT set of photos this newsletter has right now -- not just
        // "confirmed at some point in the past" (PTK_Newsletter_Builder::
        // photo_ids_confirmed()'s whole point). A different photo added
        // since the last confirmation must re-show the consent checkbox,
        // exactly like step 5's own gate.
        $pii_confirmed = PTK_Newsletter_Builder::photo_ids_confirmed(
            $post_id,
            PTK_Newsletter_Builder::current_photo_ids( $ctx['blocks'], $post_id )
        );

        ob_start();
        ?>
        <div class="ptk-nl-share-photo" data-share-photo data-has-photo="<?php echo $photo['photo_id'] ? '1' : '0'; ?>" data-pii-confirmed="<?php echo $pii_confirmed ? '1' : '0'; ?>" data-featured-image-id="<?php echo (int) $featured_image_id; ?>">
            <p class="ptk-nl-share-photo-label">Use a photo behind the words</p>

            <?php self::render_photo_color_fields(); ?>

            <?php /* Round 3.1 fix (item 2): always in the DOM (never
                    conditional on $pii_confirmed) so the JS that unchecks
                    this the moment a DIFFERENT photo is picked always has a
                    real checkbox to act on -- see share-panel.js's
                    requireFreshPhotoConsent(). Pre-checked only when
                    consent is on file for the CURRENT photo. */ ?>
            <div class="ptk-nl-share-photo-consent" data-share-photo-consent>
                <p class="description">Please confirm the photos are OK before adding or changing one here — the same checkbox as Publish.</p>
                <label>
                    <input type="checkbox" data-share-photo-pii-ok<?php checked( $pii_confirmed ); ?>>
                    <?php echo esc_html( PTK_Newsletter_Builder::PII_CHECKBOX_LABEL ); ?>
                </label>
            </div>

            <?php if ( ! $photo['photo_id'] ) : ?>
                <div class="ptk-nl-share-actions" data-share-photo-choices>
                    <?php if ( $featured_image_id > 0 ) : ?>
                        <button type="button" class="button" data-share-photo-featured<?php echo $pii_confirmed ? '' : ' disabled'; ?>>Use the top story&#8217;s photo</button>
                    <?php endif; ?>
                    <button type="button" class="button" data-share-photo-choose<?php echo $pii_confirmed ? '' : ' disabled'; ?>>Choose a different photo</button>
                </div>
            <?php else : ?>
                <div class="ptk-nl-share-photo-picker" data-share-photo-picker-mount data-photo-id="<?php echo (int) $photo['photo_id']; ?>">
                    <input type="hidden" data-field="image_focal_x" value="<?php echo esc_attr( $photo['focal_x'] ); ?>">
                    <input type="hidden" data-field="image_focal_y" value="<?php echo esc_attr( $photo['focal_y'] ); ?>">
                    <input type="hidden" data-field="image_zoom" value="<?php echo esc_attr( $photo['zoom'] ); ?>">
                </div>
                <div class="ptk-nl-share-actions">
                    <button type="button" class="button" data-share-photo-choose>Choose a different photo</button>
                    <button type="button" class="button-link" data-share-photo-remove>Remove photo</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Round 3.1 (spec item 4): "Text color" and "Bar color" for photo
     * squares only -- same color-input + hex box pattern as Newsletter
     * settings' square colors, shown right next to "Use a photo behind the
     * words" since that's the only place they matter. Set once for the
     * whole site (like the flat square's colors), saved via
     * ptk_nl_share_photo_colors. If the pair fails WCAG AA, a plain warning
     * is shown -- the colors are still used exactly as picked (spec: "don't
     * block").
     */
    protected static function render_photo_color_fields() {
        $text_value = PTK_Share_Color::square_photo_text_color();
        $bar_value  = PTK_Share_Color::square_photo_bar_color();
        $ratio      = PTK_Share_Color::contrast_ratio( $text_value, $bar_value );
        $low        = $ratio < PTK_Share_Color::MIN_CONTRAST;
        ?>
        <div class="ptk-nl-photo-colors" data-photo-colors>
            <div class="ptk-nl-photo-colorfield">
                <label for="ptk-nl-photo-text-color">Text color</label>
                <input type="color" id="ptk-nl-photo-text-color" value="<?php echo esc_attr( $text_value ); ?>" data-photo-color-picker="text">
                <label for="ptk-nl-photo-text-color-hex" class="screen-reader-text">Text color code</label>
                <input type="text" id="ptk-nl-photo-text-color-hex" class="ptk-nl-photo-hex" value="<?php echo esc_attr( $text_value ); ?>" maxlength="7" spellcheck="false" autocomplete="off" autocapitalize="off" data-photo-color-hex="text">
            </div>
            <div class="ptk-nl-photo-colorfield">
                <label for="ptk-nl-photo-bar-color">Bar color</label>
                <input type="color" id="ptk-nl-photo-bar-color" value="<?php echo esc_attr( $bar_value ); ?>" data-photo-color-picker="bar">
                <label for="ptk-nl-photo-bar-color-hex" class="screen-reader-text">Bar color code</label>
                <input type="text" id="ptk-nl-photo-bar-color-hex" class="ptk-nl-photo-hex" value="<?php echo esc_attr( $bar_value ); ?>" maxlength="7" spellcheck="false" autocomplete="off" autocapitalize="off" data-photo-color-hex="bar">
            </div>
            <p class="ptk-nl-photo-colors-warning" data-photo-colors-warning<?php echo $low ? '' : ' hidden'; ?>>These colors are hard to read together.</p>
        </div>
        <?php
    }

    /**
     * Save the photo-square text/bar colors (round 3.1). Set-once site
     * settings, like the flat square's own two colors, so this is a plain
     * option save -- not tied to one newsletter -- that replies with the
     * re-rendered square so the change is visible right away.
     */
    public static function ajax_photo_colors() {
        $post_id = self::authorize_request();

        $text = isset( $_POST['text_color'] ) ? sanitize_text_field( wp_unslash( $_POST['text_color'] ) ) : '';
        $bar  = isset( $_POST['bar_color'] ) ? sanitize_text_field( wp_unslash( $_POST['bar_color'] ) ) : '';

        if ( PTK_Share_Color::is_hex( $text ) ) {
            update_option( PTK_Share_Color::PHOTO_TEXT_OPTION, PTK_Share_Color::normalize_hex( $text ) );
        }
        if ( PTK_Share_Color::is_hex( $bar ) ) {
            update_option( PTK_Share_Color::PHOTO_BAR_OPTION, PTK_Share_Color::normalize_hex( $bar ) );
        }

        $ratio = PTK_Share_Color::contrast_ratio(
            PTK_Share_Color::square_photo_text_color(),
            PTK_Share_Color::square_photo_bar_color()
        );

        wp_send_json_success( array(
            'html' => self::square_html( $post_id, self::context( $post_id ) ),
            'low'  => $ratio < PTK_Share_Color::MIN_CONTRAST,
        ) );
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
