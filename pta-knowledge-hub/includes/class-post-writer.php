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
 * This task renders. Saving, the confirmation and editing come next; the
 * form posts back to this same screen, where nothing yet reads it.
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

    /** The nonce that will guard the save (next task). */
    const NONCE_ACTION = 'ptk_post_save';
    const NONCE_NAME   = 'ptk_post_nonce';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
     * The screen.
     * ----------------------------------------------------------------*/

    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $chips    = PTK_Post_Copy::chips();
        $hub_url  = admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-welcome' );
        $can_pub  = current_user_can( 'publish_posts' );
        ?>
        <div class="wrap ptk-wizard-wrap ptk-qf-wrap ptk-post-wrap">
            <a class="ptk-qf-back" href="<?php echo esc_url( $hub_url ); ?>">&larr; Back to the Hub</a>

            <p class="ptk-qf-intro"><?php echo esc_html( PTK_Post_Copy::intro() ); ?></p>

            <form method="post" action="" id="ptk-post-form" class="ptk-qf-form">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

                <!-- The card: what families will read, in the order they
                     will read it -- the small line above, the headline, the
                     words -- then whatever the chips below have added. -->
                <div class="ptk-qf-card">

                    <!-- The small line above the headline. Its hint stays out
                         of the way until the field is being used (hub.css). -->
                    <div class="ptk-qf-field ptk-post-kicker-field">
                        <label for="ptk-post-kicker" class="screen-reader-text"><?php echo esc_html( PTK_Post_Copy::kicker_field_label() ); ?></label>
                        <input type="text" id="ptk-post-kicker" name="ptk_post_kicker" class="ptk-post-kicker-input"
                               placeholder="<?php echo esc_attr( PTK_Post_Copy::kicker_field_label() ); ?>">
                        <p class="ptk-post-hint"><?php echo esc_html( PTK_Post_Copy::kicker_help() ); ?></p>
                    </div>

                    <!-- The headline: the words families click. -->
                    <div class="ptk-qf-field">
                        <label for="ptk-post-headline" class="screen-reader-text"><?php echo esc_html( PTK_Post_Copy::headline_field_label() ); ?></label>
                        <input type="text" id="ptk-post-headline" name="ptk_post_headline" class="ptk-qf-question-input" required
                               placeholder="<?php echo esc_attr( PTK_Post_Copy::headline_placeholder() ); ?>">
                    </div>

                    <!-- The words. -->
                    <div class="ptk-qf-field">
                        <label for="ptk-post-words" class="screen-reader-text"><?php echo esc_html( PTK_Post_Copy::words_label() ); ?></label>
                        <textarea id="ptk-post-words" name="ptk_post_words" class="ptk-qf-answer-input" rows="4"
                                  placeholder="<?php echo esc_attr( PTK_Post_Copy::words_placeholder() ); ?>"></textarea>
                    </div>

                    <?php
                    echo self::picture_block();  // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    echo self::steps_block();    // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    echo self::date_block();     // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    echo self::button_block();   // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                    ?>

                    <div class="ptk-qf-chips">
                        <button type="button" class="ptk-qf-chip" data-block="image"><?php echo esc_html( $chips[0] ); ?></button>
                        <button type="button" class="ptk-qf-chip" data-block="steps"><?php echo esc_html( $chips[1] ); ?></button>
                        <button type="button" class="ptk-qf-chip" data-block="date"><?php echo esc_html( $chips[2] ); ?></button>
                        <button type="button" class="ptk-qf-chip" data-block="link"><?php echo esc_html( $chips[3] ); ?></button>
                    </div>
                </div>

                <?php
                echo self::actions( $can_pub ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes its own text.
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * The picture, through "Which picture?" -- the id and all four framing
     * fields the picker hands back, so the saved post can be shown the way
     * it was framed. Hidden until a picture is chosen.
     */
    private static function picture_block() {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-image" id="ptk-post-image-block" data-block="image" hidden>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="image" aria-label="Remove picture">&times;</button>';
        $out .= '<input type="hidden" name="ptk_post_image_id" id="ptk-post-image-id" value="">';
        $out .= '<input type="hidden" name="ptk_post_image_focal_x" id="ptk-post-image-focal-x" value="">';
        $out .= '<input type="hidden" name="ptk_post_image_focal_y" id="ptk-post-image-focal-y" value="">';
        $out .= '<input type="hidden" name="ptk_post_image_zoom" id="ptk-post-image-zoom" value="">';
        $out .= '<input type="hidden" name="ptk_post_image_fit" id="ptk-post-image-fit" value="">';
        $out .= '<div class="ptk-image-preview" id="ptk-post-image-preview"></div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * The steps. One row is a heading and the words under it; post-writer.js
     * adds and removes rows, and the numbering is the stylesheet's counter,
     * so nothing here has to renumber anything.
     */
    private static function steps_block() {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-steps" id="ptk-post-steps-block" data-block="steps" hidden>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="steps" aria-label="Remove steps">&times;</button>';
        $out .= '<div class="ptk-repeater ptk-qf-steps-repeater ptk-post-steps" id="ptk-post-steps" data-min="0"></div>';
        $out .= '<button type="button" class="ptk-qf-add-more" id="ptk-post-add-step">+ another step</button>';
        $out .= '</div>';
        return $out;
    }

    /** The one thing a family must not miss: a date, and a line about it. */
    private static function date_block() {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-date ptk-post-date-block" id="ptk-post-date-block" data-block="date" hidden>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="date" aria-label="Remove date">&times;</button>';
        $out .= '<span class="dashicons dashicons-calendar-alt"></span>';
        $out .= '<label for="ptk-post-date-label" class="screen-reader-text">When is it?</label>';
        $out .= '<input type="text" id="ptk-post-date-label" name="ptk_post_date_label" class="ptk-qf-block-input" placeholder="Friday, October 3">';
        $out .= '<label for="ptk-post-date-note" class="screen-reader-text">Anything else about it?</label>';
        $out .= '<input type="text" id="ptk-post-date-note" name="ptk_post_date_note" class="ptk-qf-block-input" placeholder="Sign up before then.">';
        $out .= '</div>';
        return $out;
    }

    /** The single button. Everything else in a post is a plain text link. */
    private static function button_block() {
        $out  = '<div class="ptk-qf-added-block ptk-qf-block-link ptk-post-link-block" id="ptk-post-link-block" data-block="link" hidden>';
        $out .= '<button type="button" class="ptk-qf-block-remove" data-block="link" aria-label="Remove button">&times;</button>';
        $out .= '<label for="ptk-post-link-text" class="screen-reader-text">What should the button say?</label>';
        $out .= '<input type="text" id="ptk-post-link-text" name="ptk_post_link_text" class="ptk-qf-block-input" placeholder="Sign up">';
        $out .= '<label for="ptk-post-link-url" class="screen-reader-text">Where does it go?</label>';
        $out .= '<input type="url" id="ptk-post-link-url" name="ptk_post_link_url" class="ptk-qf-block-input" placeholder="Paste a link&hellip;">';
        $out .= '</div>';
        return $out;
    }

    /**
     * The two buttons. Someone whose role cannot publish is never offered
     * the one that would fail -- they get the quiet one, and a line saying
     * who presses the other.
     *
     * A separate method rather than an inline branch: PHP's alternate
     * if/endif indentation leaks into the output.
     */
    private static function actions( $can_publish ) {
        $out = '<div class="ptk-qf-actions">';

        if ( $can_publish ) {
            $out .= '<button type="submit" name="ptk_post_status" value="publish" id="ptk-post-submit-publish" class="ptk-btn ptk-btn-primary">'
                . esc_html( PTK_Post_Copy::primary_button_label() ) . '</button>';
        }

        $out .= '<button type="submit" name="ptk_post_status" value="draft" id="ptk-post-submit-draft" class="ptk-btn ptk-qf-btn-quiet">'
            . esc_html( PTK_Post_Copy::secondary_button_label() ) . '</button>';

        $reassure = $can_publish ? PTK_Post_Copy::under_buttons() : PTK_Post_Copy::cannot_publish();
        $out .= '<p class="ptk-qf-reassure">' . esc_html( $reassure ) . '</p>';

        $out .= '</div>';
        return $out;
    }
}
