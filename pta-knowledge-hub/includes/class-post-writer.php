<?php
/**
 * "Put one thing on the website" -- the screen a volunteer writes a single
 * news post on, without meeting the block editor.
 *
 * Shaped like Create Entry's question-first card (PTK_Content_Wizard::
 * render_question_first_wizard()): the words a family will read, sized the
 * way they will read them, with four chips underneath that add the parts
 * most posts don't need. Every block is a real field, so nothing depends on
 * JavaScript having run to be submitted.
 *
 * Registered only when PTK_Hub_Look::on() -- see add_page() -- the same gate
 * PTK_Words_List::add_page() uses. With the look off this screen does not
 * exist: no menu entry, no page, no assets.
 *
 * The screen renders the parts and the save handler writes them: the parts
 * are kept as meta, and the finished HTML PTK_Post_Renderer makes from them
 * goes into the post itself, so the post is an ordinary post that any theme,
 * feed reader or Facebook can read.
 *
 * Every sentence on screen comes from PTK_Post_Copy. Layout lives here,
 * wording lives there.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Post_Writer {

    /** This screen's own page slug. */
    const PAGE_SLUG = 'ptk-post-writer';

    /** The nonce that guards the save. */
    const NONCE_ACTION = 'ptk_post_save';
    const NONCE_NAME   = 'ptk_post_nonce';

    /** The parts this screen wrote, kept beside the post they were rendered into. */
    const PARTS_META = '_ptk_post_parts';

    /** A hash of the HTML we rendered, so an edit made outside the Hub can be seen. */
    const HASH_META = '_ptk_post_hash';

    /** Set when a save was refused, so the screen can say why and give back what was typed. */
    private static $error = '';

    /**
     * Set when a save was refused because the post had been worked on
     * elsewhere. The screen then shows the guard rather than the form:
     * offering to save again is offering to overwrite somebody's work.
     */
    private static $guard_post_id = 0;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
    }

    /* ------------------------------------------------------------------
     * WordPress-facing.
     * ----------------------------------------------------------------*/

    /** Canonical admin URL of this screen -- the same edit.php submenu shape as the others. */
    public static function url() {
        return admin_url( 'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG );
    }

    /**
     * Register this screen -- only while the new look is on. With it off,
     * this never runs.
     */
    public static function add_page() {
        if ( ! class_exists( 'PTK_Hub_Look' ) || ! PTK_Hub_Look::on() ) {
            return;
        }
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Write a post',
            'Write a post',
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /**
     * The card reuses Create Entry's own stylesheet (every rule in it is
     * .ptk- scoped and gated on body.ptk-hub-look), so this screen inherits
     * the card, the chips and the buttons without a second copy of them.
     * hub.css arrives on its own, through PTK_Hub_Look::PAGES.
     */
    public static function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
            return;
        }

        wp_enqueue_style(
            'ptk-content-wizard',
            PTK_PLUGIN_URL . 'assets/css/content-wizard.css',
            array(),
            PTK_VERSION
        );

        // 'ptk-picture-picker' is registered by PTK_Picture_Picker::enqueue()
        // on this screen (its own PAGES list carries this slug), so naming it
        // as a dependency only fixes the order -- it never loads a second copy.
        wp_enqueue_script(
            'ptk-post-writer',
            PTK_PLUGIN_URL . 'assets/js/post-writer.js',
            array( 'jquery', 'ptk-picture-picker' ),
            PTK_VERSION,
            true
        );
    }

    /* ------------------------------------------------------------------
     * Saving.
     * ----------------------------------------------------------------*/

    /**
     * Take what was written and put it on the website.
     *
     * The post is an ordinary `post`: the headline is its title, the summary
     * is its excerpt (which is what Latest news and Facebook actually show),
     * the chosen picture is its featured image, and the finished HTML is its
     * content. The parts are kept beside it as meta so the screen can open
     * it again, along with a hash of what we rendered.
     */
    public static function handle_submission() {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            wp_die(
                'Your session expired while this was open, so the save was stopped. Go back and press the button again &mdash; what you wrote is still there.',
                'PTA Hub',
                array( 'back_link' => true )
            );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to write posts.', 'PTA Hub', array( 'back_link' => true ) );
        }

        $parts = self::posted_parts();

        if ( ! PTK_Post_Parts::has_content( $parts ) ) {
            self::$error = PTK_Post_Copy::nothing_at_all();
            return;
        }

        if ( '' === $parts['headline'] ) {
            self::$error = PTK_Post_Copy::no_headline();
            return;
        }

        // Editing one that already exists, or writing a new one?
        $edit_id = isset( $_POST['ptk_post_id'] ) ? absint( $_POST['ptk_post_id'] ) : 0;
        if ( $edit_id ) {
            if ( ! self::is_hub_post( $edit_id ) || ! current_user_can( 'edit_post', $edit_id ) ) {
                wp_die( 'You do not have permission to change that post.', 'PTA Hub', array( 'back_link' => true ) );
            }
            // Checked again here, not only when the form was opened:
            // somebody may have edited the post in WordPress while this
            // was sitting open, and the whole point of the guard is that
            // their work is never replaced by ours.
            if ( self::changed_elsewhere( $edit_id ) ) {
                self::$guard_post_id = $edit_id;
                return;
            }
        }

        // Never publish for somebody whose role cannot. The screen already
        // offers them only the quiet button; this is the same rule where it
        // is actually enforced, for a form that arrives any other way.
        $asked   = isset( $_POST['ptk_post_status'] ) ? (string) wp_unslash( $_POST['ptk_post_status'] ) : 'draft';
        $publish = ( 'publish' === $asked ) && current_user_can( 'publish_posts' );

        // A post already on the website stays on it. Taking one down is
        // Remove it, on "Your posts", where it can be undone -- not a
        // side effect of saving a change to the words.
        if ( $edit_id && 'publish' === get_post_status( $edit_id ) ) {
            $publish = true;
        }

        $picture = self::posted_picture( $parts['image_id'] );
        $signoff = (string) get_option( PTK_Share_Settings::SIGNOFF_OPTION, '' );
        $html    = PTK_Post_Renderer::render( $parts, $signoff, $picture );

        $post_data = array(
            'post_type'    => 'post',
            'post_status'  => $publish ? 'publish' : 'draft',
            'post_title'   => $parts['headline'],
            'post_content' => $html,
            'post_excerpt' => PTK_Post_Parts::summary( $parts ),
        );
        if ( $edit_id ) {
            $post_data['ID'] = $edit_id;
        }
        $post_data = wp_slash( $post_data );

        // This HTML is PTK_Post_Renderer's, built from parts that
        // PTK_Post_Parts::sanitize() cleaned and the renderer escaped by
        // hand -- there is no raw user HTML in it. Anyone without
        // unfiltered_html (every non-super-admin, so every school admin on
        // multisite) would otherwise have wp_kses strip the inline styles
        // that are the entire point. Bypass kses for THIS write only, and
        // restore filtering in a finally so the rest of the request is
        // filtered even if the insert throws. Same reasoning, same shape as
        // PTK_Newsletter_Builder::handle_save().
        kses_remove_filters();
        try {
            $post_id = $edit_id ? wp_update_post( $post_data, true ) : wp_insert_post( $post_data, true );
        } finally {
            kses_init_filters();
        }

        // Both a WP_Error and a falsy 0: a save_post filter can short-circuit
        // the insert, and that must not fall through to writing meta on post
        // 0 or claiming success.
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            self::$error = PTK_Post_Copy::could_not_save();
            return;
        }

        self::save_picture( $post_id, $parts['image_id'] );

        update_post_meta( $post_id, self::PARTS_META, $parts );
        update_post_meta( $post_id, self::HASH_META, PTK_Post_Renderer::hash( $html ) );

        wp_safe_redirect( add_query_arg( 'ptk_post_saved', $post_id, self::url() ) );
        exit;
    }

    /**
     * The parts, as posted. Every field this screen renders, cleaned by the
     * one class that owns what clean means.
     */
    private static function posted_parts() {
        $headings = isset( $_POST['ptk_post_step_heading'] ) ? (array) wp_unslash( $_POST['ptk_post_step_heading'] ) : array();
        $bodies   = isset( $_POST['ptk_post_step_body'] ) ? (array) wp_unslash( $_POST['ptk_post_step_body'] ) : array();

        $steps = array();
        foreach ( array_keys( $headings + $bodies ) as $i ) {
            $steps[] = array(
                'heading' => isset( $headings[ $i ] ) ? $headings[ $i ] : '',
                'body'    => isset( $bodies[ $i ] ) ? $bodies[ $i ] : '',
            );
        }

        return PTK_Post_Parts::sanitize(
            array(
                'kicker'     => isset( $_POST['ptk_post_kicker'] ) ? wp_unslash( $_POST['ptk_post_kicker'] ) : '',
                'headline'   => isset( $_POST['ptk_post_headline'] ) ? wp_unslash( $_POST['ptk_post_headline'] ) : '',
                'words'      => isset( $_POST['ptk_post_words'] ) ? wp_unslash( $_POST['ptk_post_words'] ) : '',
                'image_id'   => isset( $_POST['ptk_post_image_id'] ) ? $_POST['ptk_post_image_id'] : 0,
                'date_label' => isset( $_POST['ptk_post_date_label'] ) ? wp_unslash( $_POST['ptk_post_date_label'] ) : '',
                'date_note'  => isset( $_POST['ptk_post_date_note'] ) ? wp_unslash( $_POST['ptk_post_date_note'] ) : '',
                'steps'      => $steps,
                'link_url'   => isset( $_POST['ptk_post_link_url'] ) ? wp_unslash( $_POST['ptk_post_link_url'] ) : '',
                'link_text'  => isset( $_POST['ptk_post_link_text'] ) ? wp_unslash( $_POST['ptk_post_link_text'] ) : '',
            )
        );
    }

    /**
     * An id is only a picture if it is really a picture on this site. A
     * number posted by hand could be any attachment at all -- a PDF, a
     * newsletter's own file -- and featuring one would put something
     * nobody chose at the top of a post.
     */
    private static function picture_id( $image_id ) {
        $image_id = (int) $image_id;
        if ( $image_id <= 0 || ! wp_attachment_is_image( $image_id ) ) {
            return 0;
        }
        return $image_id;
    }

    /**
     * How the picture should be shown, in the shape the renderer takes --
     * the same shape PTK_Search_Engine::format_result() builds for a card,
     * from the same four framing fields the picker posts.
     *
     * A picture nobody framed (no fields posted at all) is shown the way
     * every picture was shown before framing existed: centered and cropped.
     */
    private static function posted_picture( $image_id ) {
        $image_id = self::picture_id( $image_id );
        if ( ! $image_id ) {
            return array();
        }

        $url = wp_get_attachment_image_url( $image_id, 'large' );
        if ( ! $url ) {
            return array();
        }

        $x    = PTK_Focal_Point::clamp_percent( isset( $_POST['ptk_post_image_focal_x'] ) ? $_POST['ptk_post_image_focal_x'] : null );
        $y    = PTK_Focal_Point::clamp_percent( isset( $_POST['ptk_post_image_focal_y'] ) ? $_POST['ptk_post_image_focal_y'] : null );
        $zoom = PTK_Focal_Point::sanitize_zoom( isset( $_POST['ptk_post_image_zoom'] ) ? $_POST['ptk_post_image_zoom'] : null );
        $fit  = ( isset( $_POST['ptk_post_image_fit'] ) && 'whole' === $_POST['ptk_post_image_fit'] ) ? 'whole' : 'crop';

        return array(
            'url'      => $url,
            'alt'      => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
            'fit'      => $fit,
            'position' => PTK_Focal_Point::object_position( $x, $y ),
            'zoom'     => PTK_Focal_Point::css_zoom_style( $x, $y, $zoom ),
        );
    }

    /**
     * The featured image and the four framing fields, saved as meta under
     * the same names every other Hub picture uses, so anything that already
     * knows how to show a framed picture can show this one.
     */
    private static function save_picture( $post_id, $image_id ) {
        $image_id = self::picture_id( $image_id );

        if ( ! $image_id ) {
            delete_post_thumbnail( $post_id );
            delete_post_meta( $post_id, 'ptk_image_focal_x' );
            delete_post_meta( $post_id, 'ptk_image_focal_y' );
            delete_post_meta( $post_id, 'ptk_image_zoom' );
            delete_post_meta( $post_id, 'ptk_image_fit' );
            return;
        }

        set_post_thumbnail( $post_id, $image_id );
        update_post_meta( $post_id, 'ptk_image_focal_x', PTK_Focal_Point::clamp_percent( isset( $_POST['ptk_post_image_focal_x'] ) ? $_POST['ptk_post_image_focal_x'] : null ) );
        update_post_meta( $post_id, 'ptk_image_focal_y', PTK_Focal_Point::clamp_percent( isset( $_POST['ptk_post_image_focal_y'] ) ? $_POST['ptk_post_image_focal_y'] : null ) );
        update_post_meta( $post_id, 'ptk_image_zoom', PTK_Focal_Point::sanitize_zoom( isset( $_POST['ptk_post_image_zoom'] ) ? $_POST['ptk_post_image_zoom'] : null ) );
        update_post_meta( $post_id, 'ptk_image_fit', ( isset( $_POST['ptk_post_image_fit'] ) && 'whole' === $_POST['ptk_post_image_fit'] ) ? 'whole' : 'crop' );
    }

    /* ------------------------------------------------------------------
     * The screen.
     * ----------------------------------------------------------------*/

    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        // A save refused because the post changed under us: say so, and
        // do not offer the form again.
        if ( self::$guard_post_id ) {
            self::render_guard( self::$guard_post_id );
            return;
        }

        // Straight after a save, the screen says what happened instead of
        // offering an empty form again.
        $saved = isset( $_GET['ptk_post_saved'] ) ? absint( $_GET['ptk_post_saved'] ) : 0;
        if ( $saved && self::is_hub_post( $saved ) && current_user_can( 'edit_post', $saved ) ) {
            self::render_confirmation( $saved );
            return;
        }

        // Opening one that already exists. Refuse anything that is not
        // ours to open, rather than showing an empty form that would
        // replace it.
        // On a refused save the id comes back in the form, not the
        // address -- without it, saving again would write a second post
        // instead of changing the one being edited.
        $edit_id = isset( $_GET['ptk_post_edit_id'] ) ? absint( $_GET['ptk_post_edit_id'] ) : 0;
        if ( ! $edit_id && '' !== self::$error && isset( $_POST['ptk_post_id'] ) ) {
            $edit_id = absint( $_POST['ptk_post_id'] );
        }
        if ( $edit_id && ( ! self::is_hub_post( $edit_id ) || ! current_user_can( 'edit_post', $edit_id ) ) ) {
            $edit_id = 0;
        }

        // The guard: if the post no longer matches what we rendered,
        // somebody has worked on it elsewhere, and loading the form here
        // would offer to throw that away.
        if ( $edit_id && self::changed_elsewhere( $edit_id ) ) {
            self::render_guard( $edit_id );
            return;
        }

        // A refused save gives back everything that was typed, exactly as
        // it was typed, with the blocks that were in use still open. Losing
        // somebody's writing because they left the headline out would be a
        // far worse thing than the mistake itself.
        if ( '' !== self::$error ) {
            $parts   = self::posted_parts();
            $framing = self::posted_framing();
        } elseif ( $edit_id ) {
            $parts   = array_merge( PTK_Post_Parts::defaults(), (array) get_post_meta( $edit_id, self::PARTS_META, true ) );
            $framing = self::saved_framing( $edit_id );
        } else {
            $parts   = PTK_Post_Parts::defaults();
            $framing = array();
        }
        $open = self::blocks_in_use( $parts );

        $chips   = PTK_Post_Copy::chips();
        $hub_url = admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-welcome' );
        $can_pub = current_user_can( 'publish_posts' );
        ?>
        <div class="wrap ptk-wizard-wrap ptk-qf-wrap ptk-post-wrap">
            <a class="ptk-qf-back" href="<?php echo esc_url( $hub_url ); ?>">&larr; Back to the Hub</a>

            <p class="ptk-qf-intro"><?php echo esc_html( PTK_Post_Copy::intro() ); ?></p>

            <?php echo self::error_notice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text. ?>

            <form method="post" action="" id="ptk-post-form" class="ptk-qf-form">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                <input type="hidden" name="ptk_post_id" value="<?php echo esc_attr( $edit_id ); ?>">

                <!-- The card: what families will read, in the order they
                     will read it -- the small line above, the headline, the
                     words -- then whatever the chips below have added. -->
                <div class="ptk-qf-card">

                    <!-- The small line above the headline. Its hint stays out
                         of the way until the field is being used (hub.css). -->
                    <div class="ptk-qf-field ptk-post-kicker-field">
                        <label for="ptk-post-kicker" class="screen-reader-text"><?php echo esc_html( PTK_Post_Copy::kicker_field_label() ); ?></label>
                        <input type="text" id="ptk-post-kicker" name="ptk_post_kicker" class="ptk-post-kicker-input"
                               value="<?php echo esc_attr( $parts['kicker'] ); ?>"
                               placeholder="<?php echo esc_attr( PTK_Post_Copy::kicker_field_label() ); ?>">
                        <p class="ptk-post-hint"><?php echo esc_html( PTK_Post_Copy::kicker_help() ); ?></p>
                    </div>

                    <!-- The headline: the words families click. -->
                    <div class="ptk-qf-field">
                        <label for="ptk-post-headline" class="screen-reader-text"><?php echo esc_html( PTK_Post_Copy::headline_field_label() ); ?></label>
                        <input type="text" id="ptk-post-headline" name="ptk_post_headline" class="ptk-qf-question-input" required
                               value="<?php echo esc_attr( $parts['headline'] ); ?>"
                               placeholder="<?php echo esc_attr( PTK_Post_Copy::headline_placeholder() ); ?>">
                    </div>

                    <!-- The words. -->
                    <div class="ptk-qf-field">
                        <label for="ptk-post-words" class="screen-reader-text"><?php echo esc_html( PTK_Post_Copy::words_label() ); ?></label>
                        <textarea id="ptk-post-words" name="ptk_post_words" class="ptk-qf-answer-input" rows="4"
                                  placeholder="<?php echo esc_attr( PTK_Post_Copy::words_placeholder() ); ?>"><?php echo esc_textarea( $parts['words'] ); ?></textarea>
                    </div>

                    <?php
                    echo self::picture_block( $parts, $open['image'], $framing ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    echo self::steps_block( $parts, $open['steps'] );   // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    echo self::date_block( $parts, $open['date'] );     // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    echo self::button_block( $parts, $open['link'] );   // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    ?>

                    <div class="ptk-qf-chips">
                        <?php
                        echo self::chip( 'image', $chips[0], $open['image'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                        echo self::chip( 'steps', $chips[1], $open['steps'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                        echo self::chip( 'date', $chips[2], $open['date'] );   // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                        echo self::chip( 'link', $chips[3], $open['link'] );   // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                        ?>
                    </div>
                </div>

                <?php
                echo self::actions( $can_pub, $edit_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Is this a post this screen wrote? Only those carry the parts; a post
     * written in WordPress has none, and this screen must never claim one.
     */
    public static function is_hub_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return false;
        }
        return is_array( get_post_meta( $post_id, self::PARTS_META, true ) );
    }

    /**
     * What happened, once it has happened: the post as families will read
     * it, one stamp, and the three things somebody usually wants next.
     *
     * Read back from the saved parts rather than from $_POST, which is gone
     * after the redirect -- the same reader the edit screen will use, so
     * what is shown here is what was actually kept.
     */
    private static function render_confirmation( $post_id ) {
        $parts   = array_merge( PTK_Post_Parts::defaults(), (array) get_post_meta( $post_id, self::PARTS_META, true ) );
        $on_site = ( 'publish' === get_post_status( $post_id ) );
        $stamp   = PTK_Post_Copy::stamp( $on_site );
        $message = $on_site ? PTK_Post_Copy::published() : PTK_Post_Copy::kept_private();
        $labels  = PTK_Post_Copy::next_steps();

        $view_url = $on_site ? get_permalink( $post_id ) : get_preview_post_link( $post_id );
        $steps    = array(
            array( 'label' => $labels[0], 'url' => $view_url ),
            array( 'label' => $labels[1], 'url' => class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::url() : '' ),
            array( 'label' => $labels[2], 'url' => self::url() ),
        );
        ?>
        <div class="wrap ptk-wizard-wrap ptk-qf-wrap ptk-post-wrap">
            <a class="ptk-qf-back" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-welcome' ) ); ?>">&larr; Back to the Hub</a>

            <div class="ptk-qf-card ptk-qf-card--done">
                <div class="ptk-qf-stamp-row"><?php echo PTK_Hub_UI::stamp( $stamp[0], $stamp[1] ); // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped by PTK_Hub_UI::stamp(). ?></div>
                <?php echo self::written_card( $parts, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text. ?>
            </div>

            <p class="ptk-qf-done-message"><?php echo esc_html( $message ); ?></p>

            <?php echo PTK_Hub_UI::next_steps( $steps ); // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped by PTK_Hub_UI::next_steps(). ?>
        </div>
        <?php
    }

    /**
     * The post, read-only, in the shape it was written in. Not the rendered
     * HTML itself: that is set for a page 63 characters wide and a 44px
     * headline, and squeezing it into an admin column would show somebody
     * something that is not what families will see.
     */
    private static function written_card( array $parts, $post_id ) {
        $out = '';

        if ( '' !== $parts['kicker'] ) {
            $out .= '<p class="ptk-post-kicker-readonly">' . esc_html( $parts['kicker'] ) . '</p>';
        }

        $out .= '<h2 class="ptk-qf-question ptk-qf-readonly">' . esc_html( $parts['headline'] ) . '</h2>';

        if ( '' !== $parts['words'] ) {
            $out .= '<div class="ptk-qf-answer ptk-qf-readonly">';
            foreach ( preg_split( '/\n\s*\n/', $parts['words'] ) as $paragraph ) {
                $paragraph = trim( $paragraph );
                if ( '' !== $paragraph ) {
                    $out .= '<p>' . esc_html( $paragraph ) . '</p>';
                }
            }
            $out .= '</div>';
        }

        $thumb = get_the_post_thumbnail_url( $post_id, 'medium' );
        if ( $thumb ) {
            $out .= '<div class="ptk-qf-block-image ptk-qf-readonly"><img src="' . esc_url( $thumb ) . '" alt=""></div>';
        }

        if ( '' !== $parts['date_label'] || '' !== $parts['date_note'] ) {
            $out .= '<p class="ptk-qf-block-date ptk-qf-readonly"><span class="dashicons dashicons-calendar-alt"></span> ';
            $out .= esc_html( $parts['date_label'] );
            if ( '' !== $parts['date_label'] && '' !== $parts['date_note'] ) {
                $out .= ' &mdash; ';
            }
            $out .= esc_html( $parts['date_note'] ) . '</p>';
        }

        if ( ! empty( $parts['steps'] ) ) {
            $out .= '<ol class="ptk-qf-block-steps ptk-qf-readonly">';
            foreach ( $parts['steps'] as $step ) {
                $heading = isset( $step['heading'] ) ? (string) $step['heading'] : '';
                $body    = isset( $step['body'] ) ? (string) $step['body'] : '';
                if ( '' === trim( $heading . $body ) ) {
                    continue;
                }
                $out .= '<li>' . esc_html( $heading );
                if ( '' !== $heading && '' !== $body ) {
                    $out .= ' &mdash; ';
                }
                $out .= esc_html( $body ) . '</li>';
            }
            $out .= '</ol>';
        }

        if ( '' !== $parts['link_url'] && '' !== $parts['link_text'] ) {
            $out .= '<p class="ptk-qf-block-link ptk-qf-readonly"><span class="dashicons dashicons-media-default"></span> <a href="'
                . esc_url( $parts['link_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $parts['link_text'] ) . '</a></p>';
        }

        return $out;
    }

    /**
     * Has this post been worked on outside the Hub since we wrote it?
     *
     * We keep a hash of exactly the HTML we rendered. If what is in the
     * post no longer hashes to it, somebody edited it in WordPress, and
     * the parts we would load are stale: saving them back would quietly
     * replace their writing with an older version of it.
     *
     * A post with no stored hash at all -- there should be none, but a
     * failed write or an old row could leave one -- counts as changed. The
     * safe answer to "I am not sure" is to leave it alone.
     */
    public static function changed_elsewhere( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return true;
        }
        return self::is_stale(
            (string) get_post_meta( $post_id, self::HASH_META, true ),
            (string) $post->post_content
        );
    }

    /**
     * Pure: does this content still match the hash we stored for it?
     *
     * No stored hash means we cannot tell, and the safe answer to "I am
     * not sure whether somebody rewrote this" is to leave it alone.
     *
     * @param string $stored_hash What we recorded when we last wrote it.
     * @param string $content     What is in the post now.
     * @return bool True when the post must not be loaded back into the form.
     */
    public static function is_stale( $stored_hash, $content ) {
        $stored_hash = (string) $stored_hash;
        if ( '' === $stored_hash ) {
            return true;
        }
        return PTK_Post_Renderer::hash( $content ) !== $stored_hash;
    }

    /**
     * Say so, plainly, and offer the two ways out -- open it where the
     * work was done, or go back. Never a third option that overwrites.
     */
    private static function render_guard( $post_id ) {
        $wp_url   = (string) get_edit_post_link( $post_id, '' );
        $back_url = PTK_Posts_List::url();
        ?>
        <div class="wrap ptk-wizard-wrap ptk-qf-wrap ptk-post-wrap">
            <a class="ptk-qf-back" href="<?php echo esc_url( $back_url ); ?>">&larr; Back to the Hub</a>

            <div class="ptk-qf-card">
                <h2 class="ptk-qf-question ptk-qf-readonly"><?php echo esc_html( PTK_Post_Copy::guard_title() ); ?></h2>
                <p class="ptk-qf-answer ptk-qf-readonly"><?php echo esc_html( PTK_Post_Copy::guard_body() ); ?></p>
                <div class="ptk-entry-actions">
                    <?php if ( '' !== $wp_url ) : ?>
                        <a class="ptk-btn" href="<?php echo esc_url( $wp_url ); ?>"><?php echo esc_html( PTK_Post_Copy::guard_open_wordpress() ); ?></a>
                    <?php endif; ?>
                    <a class="ptk-btn" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( PTK_Post_Copy::guard_go_back() ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /** The four framing fields as they were posted. */
    private static function posted_framing() {
        if ( ! isset( $_POST['ptk_post_image_fit'] ) && ! isset( $_POST['ptk_post_image_focal_x'] ) ) {
            return array();
        }
        return array(
            'x'    => PTK_Focal_Point::clamp_percent( isset( $_POST['ptk_post_image_focal_x'] ) ? $_POST['ptk_post_image_focal_x'] : null ),
            'y'    => PTK_Focal_Point::clamp_percent( isset( $_POST['ptk_post_image_focal_y'] ) ? $_POST['ptk_post_image_focal_y'] : null ),
            'zoom' => PTK_Focal_Point::sanitize_zoom( isset( $_POST['ptk_post_image_zoom'] ) ? $_POST['ptk_post_image_zoom'] : null ),
            'fit'  => ( isset( $_POST['ptk_post_image_fit'] ) && 'whole' === $_POST['ptk_post_image_fit'] ) ? 'whole' : 'crop',
        );
    }

    /**
     * The four framing fields as they were saved. A post framed before is
     * reopened framed the same way -- only the screen that owns a field
     * may write it, and that cuts both ways: this screen must hand back
     * what it was given, not a fresh set of defaults.
     */
    private static function saved_framing( $post_id ) {
        $x = get_post_meta( $post_id, 'ptk_image_focal_x', true );
        if ( '' === $x ) {
            return array();
        }
        return array(
            'x'    => PTK_Focal_Point::clamp_percent( $x ),
            'y'    => PTK_Focal_Point::clamp_percent( get_post_meta( $post_id, 'ptk_image_focal_y', true ) ),
            'zoom' => PTK_Focal_Point::sanitize_zoom( get_post_meta( $post_id, 'ptk_image_zoom', true ) ),
            'fit'  => ( 'whole' === get_post_meta( $post_id, 'ptk_image_fit', true ) ) ? 'whole' : 'crop',
        );
    }

    /** Pure: which of the four blocks this post actually uses. */
    public static function blocks_in_use( array $parts ) {
        $parts = array_merge( PTK_Post_Parts::defaults(), $parts );
        return array(
            'image' => (int) $parts['image_id'] > 0,
            'steps' => ! empty( $parts['steps'] ),
            'date'  => '' !== $parts['date_label'] || '' !== $parts['date_note'],
            'link'  => '' !== $parts['link_url'] || '' !== $parts['link_text'],
        );
    }

    /** What went wrong, said once, above the card. Nothing at all when nothing did. */
    private static function error_notice() {
        if ( '' === self::$error ) {
            return '';
        }
        return '<div class="notice notice-error inline" style="margin:16px 0;padding:12px 16px;"><p style="margin:0;">'
            . esc_html( self::$error ) . '</p></div>';
    }

    /** One chip, hidden while the block it adds is already open. */
    private static function chip( $block, $label, $in_use ) {
        return '<button type="button" class="ptk-qf-chip" data-block="' . esc_attr( $block ) . '"'
            . ( $in_use ? ' style="display:none;"' : '' ) . '>' . esc_html( $label ) . '</button>';
    }

    /** `hidden` unless the block is in use. */
    private static function block_state( $in_use ) {
        return $in_use ? '' : ' hidden';
    }

    /**
     * The picture, through "Which picture?" -- the id and all four framing
     * fields the picker hands back, so the saved post can be shown the way
     * it was framed. Hidden until a picture is chosen.
     */
    private static function picture_block( array $parts, $in_use, array $framing = array() ) {
        $image_id = (int) $parts['image_id'];
        $fields   = array(
            'ptk_post_image_focal_x' => isset( $framing['x'] ) ? $framing['x'] : '',
            'ptk_post_image_focal_y' => isset( $framing['y'] ) ? $framing['y'] : '',
            'ptk_post_image_zoom'    => isset( $framing['zoom'] ) ? $framing['zoom'] : '',
            'ptk_post_image_fit'     => isset( $framing['fit'] ) ? $framing['fit'] : '',
        );

        $out  = '<div class="ptk-qf-added-block ptk-qf-block-image" id="ptk-post-image-block" data-block="image"' . self::block_state( $in_use ) . '>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="image" aria-label="Remove picture">&times;</button>';
        $out .= '<input type="hidden" name="ptk_post_image_id" id="ptk-post-image-id" value="' . esc_attr( $image_id ? $image_id : '' ) . '">';
        foreach ( $fields as $name => $value ) {
            $id   = str_replace( '_', '-', $name );
            $out .= '<input type="hidden" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '">';
        }
        $out .= '<div class="ptk-image-preview" id="ptk-post-image-preview">';
        if ( $image_id ) {
            $url = wp_get_attachment_image_url( $image_id, 'large' );
            if ( $url ) {
                $out .= '<img src="' . esc_url( $url ) . '" alt="">';
            }
        }
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * The steps. One row is a heading and the words under it; post-writer.js
     * adds and removes rows, and the numbering is the stylesheet's counter,
     * so nothing here has to renumber anything.
     */
    private static function steps_block( array $parts, $in_use ) {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-steps" id="ptk-post-steps-block" data-block="steps"' . self::block_state( $in_use ) . '>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="steps" aria-label="Remove steps">&times;</button>';
        $out .= '<div class="ptk-repeater ptk-qf-steps-repeater ptk-post-steps" id="ptk-post-steps" data-min="0">';
        foreach ( (array) $parts['steps'] as $step ) {
            $out .= self::step_row( isset( $step['heading'] ) ? $step['heading'] : '', isset( $step['body'] ) ? $step['body'] : '' );
        }
        $out .= '</div>';
        $out .= '<button type="button" class="ptk-qf-add-more" id="ptk-post-add-step">+ another step</button>';
        $out .= '</div>';
        return $out;
    }

    /** One step, the same shape post-writer.js builds for a new one. */
    private static function step_row( $heading, $body ) {
        $out  = '<div class="ptk-repeater-item">';
        $out .= '<div class="ptk-repeater-header">';
        $out .= '<button type="button" class="ptk-repeater-remove ptk-post-step-remove" title="Remove this step" aria-label="Remove this step"><span class="dashicons dashicons-trash"></span></button>';
        $out .= '</div>';
        $out .= '<div class="ptk-repeater-body">';
        $out .= '<input type="text" name="ptk_post_step_heading[]" class="ptk-post-step-heading" placeholder="What this step is" value="' . esc_attr( $heading ) . '">';
        $out .= '<textarea name="ptk_post_step_body[]" rows="2" placeholder="What a family does.">' . esc_textarea( $body ) . '</textarea>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    /** The one thing a family must not miss: a date, and a line about it. */
    private static function date_block( array $parts, $in_use ) {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-date ptk-post-date-block" id="ptk-post-date-block" data-block="date"' . self::block_state( $in_use ) . '>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="date" aria-label="Remove date">&times;</button>';
        $out .= '<span class="dashicons dashicons-calendar-alt"></span>';
        $out .= '<label for="ptk-post-date-label" class="screen-reader-text">When is it?</label>';
        $out .= '<input type="text" id="ptk-post-date-label" name="ptk_post_date_label" class="ptk-qf-block-input" placeholder="Friday, October 3" value="' . esc_attr( $parts['date_label'] ) . '">';
        $out .= '<label for="ptk-post-date-note" class="screen-reader-text">Anything else about it?</label>';
        $out .= '<input type="text" id="ptk-post-date-note" name="ptk_post_date_note" class="ptk-qf-block-input" placeholder="Sign up before then." value="' . esc_attr( $parts['date_note'] ) . '">';
        $out .= '</div>';
        return $out;
    }

    /** The single button. Everything else in a post is a plain text link. */
    private static function button_block( array $parts, $in_use ) {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-link ptk-post-link-block" id="ptk-post-link-block" data-block="link"' . self::block_state( $in_use ) . '>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="link" aria-label="Remove button">&times;</button>';
        $out .= '<label for="ptk-post-link-text" class="screen-reader-text">What should the button say?</label>';
        $out .= '<input type="text" id="ptk-post-link-text" name="ptk_post_link_text" class="ptk-qf-block-input" placeholder="Sign up" value="' . esc_attr( $parts['link_text'] ) . '">';
        $out .= '<label for="ptk-post-link-url" class="screen-reader-text">Where does it go?</label>';
        $out .= '<input type="url" id="ptk-post-link-url" name="ptk_post_link_url" class="ptk-qf-block-input" placeholder="Paste a link&hellip;" value="' . esc_attr( $parts['link_url'] ) . '">';
        $out .= '</div>';
        return $out;
    }

    private static function actions( $can_publish, $edit_id = 0 ) {
        // A post that is already on the website is never taken off it from
        // here: "Keep it to myself for now" would quietly pull something
        // families can already read. Taking one down is Remove it, on
        // "Your posts", where it can be undone.
        $already_up = $edit_id && 'publish' === get_post_status( $edit_id );

        $out = '<div class="ptk-qf-actions">';

        if ( $can_publish ) {
            $out .= '<button type="submit" name="ptk_post_status" value="publish" id="ptk-post-submit-publish" class="ptk-btn ptk-btn-primary">'
                . esc_html( PTK_Post_Copy::primary_button_label() ) . '</button>';
        }

        if ( ! $already_up ) {
            $out .= '<button type="submit" name="ptk_post_status" value="draft" id="ptk-post-submit-draft" class="ptk-btn ptk-qf-btn-quiet">'
                . esc_html( PTK_Post_Copy::secondary_button_label() ) . '</button>';
        }

        $reassure = $can_publish ? PTK_Post_Copy::under_buttons() : PTK_Post_Copy::cannot_publish();
        $out .= '<p class="ptk-qf-reassure">' . esc_html( $reassure ) . '</p>';

        $out .= '</div>';
        return $out;
    }
}
