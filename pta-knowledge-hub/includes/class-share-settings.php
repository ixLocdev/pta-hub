<?php
/**
 * Newsletters > Newsletter settings: the school-wide settings that used to
 * be typed every issue. Renamed in place from "Sharing settings" (4.3.0) --
 * same file, same class, same PAGE_SLUG, so an old bookmark still lands
 * here (spec Decision 4).
 *
 *   ptk_share_bg_color      the Instagram square's background
 *   ptk_share_color         the Instagram square's text color
 *   ptk_share_facebook_url  the PTA's Facebook group (https only, may be empty)
 *   ptk_join_url            "Join the PTA" link, masthead
 *   ptk_news_url            "Got news?" submission link
 *   ptk_calendar_url        "See full calendar" link, Coming up
 *   ptk_contact_email       contact email for the "Got news?" closing
 *
 * All seven are BLOG options: each school sets its own, on its own site,
 * from the menu where it already works on newsletters.
 *
 * 4.3.0 amendment (the controller's call, overriding the original round-2
 * design doc): the square's two colors do NOT fall back to the Council's
 * network palette (PTK_Site_Colors) at all. Background defaults to navy,
 * text to white, full stop -- see PTK_Share_Color::square_background_color()
 * / square_text_color(). This page no longer offers an "own color" vs
 * "council color" choice for the square; it offers two independent
 * picker+hex pairs, each with its own plain default.
 *
 * Kept out of PTK_Share_Color, which stays pure contrast maths plus thin
 * option reads. The validation and wording helpers below are pure PHP too,
 * so tests/test-share-settings.php covers them without WordPress.
 *
 * The contrast guard is visible: a color pair that can't be read together is
 * adjusted (the TEXT color moves, never the background -- 4.1.1's existing
 * pattern of always moving the SAME side), the ADJUSTED color is what gets
 * saved, and the page says so in plain words. Nothing is swapped silently.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Settings {

    const PAGE_SLUG    = 'ptk-share-settings';
    const ACTION       = 'ptk_share_settings';
    const FB_OPTION    = 'ptk_share_facebook_url';
    const JOIN_OPTION  = 'ptk_join_url';
    const NEWS_OPTION  = 'ptk_news_url';
    const CAL_OPTION   = 'ptk_calendar_url';
    const EMAIL_OPTION = 'ptk_contact_email';
    const NOTICE_KEY   = 'ptk_share_settings_notice_';

    /** @var string */
    private static $hook = '';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /* ------------------------------------------------------------------
     * Pure helpers (no WordPress) -- unit-tested.
     * ----------------------------------------------------------------*/

    /**
     * Check a pasted Facebook group link.
     *
     * @param mixed $raw
     * @return array{value:string,error:string} value '' with no error means "clear it".
     */
    public static function validate_facebook_url( $raw ) {
        $url = is_string( $raw ) ? trim( $raw ) : '';

        if ( '' === $url ) {
            return array( 'value' => '', 'error' => '' );
        }

        if ( preg_match( '#^http://#i', $url ) ) {
            return array(
                'value' => '',
                'error' => 'That link starts with http://, which isn’t secure. Please use the address that starts with https:// -- open your group in a browser and copy the address from the top of the window.',
            );
        }

        $parts = preg_match( '#^https://#i', $url ) ? parse_url( $url ) : false;
        $valid = is_array( $parts )
            && isset( $parts['scheme'], $parts['host'] )
            && 'https' === strtolower( $parts['scheme'] )
            && false !== strpos( $parts['host'], '.' )
            && ! preg_match( '/[\s<>"\'`]/', $url )
            && false !== filter_var( $url, FILTER_VALIDATE_URL );

        if ( ! $valid ) {
            return array(
                'value' => '',
                'error' => 'That doesn’t look like a full web address. Please paste the whole link, starting with https:// -- for example https://www.facebook.com/groups/yourgroup',
            );
        }

        return array( 'value' => $url, 'error' => '' );
    }

    /**
     * Check a Join / News / Calendar link: a web address or a bare email
     * (round 1 Decision 6 -- a bare email becomes a mailto: link, nobody
     * has to know the word "mailto"). Reuses
     * PTK_Newsletter_Data::sanitize_link_url() for the actual cleaning, and
     * adds the same "not saved, here's why" plain-English error the
     * Facebook field already has.
     *
     * @param mixed  $raw
     * @param string $example Shown in the error, e.g. "https://yourschool.org/join".
     * @return array{value:string,error:string}
     */
    public static function validate_link_field( $raw, $example ) {
        $url = is_string( $raw ) ? trim( $raw ) : '';

        if ( '' === $url ) {
            return array( 'value' => '', 'error' => '' );
        }

        $clean = PTK_Newsletter_Data::sanitize_link_url( $url );

        if ( '' === $clean ) {
            return array(
                'value' => '',
                'error' => 'That doesn’t look like a web address or an email. Please paste a full link starting with https://, or type an email address -- for example ' . $example . '.',
            );
        }

        return array( 'value' => $clean, 'error' => '' );
    }

    /**
     * Check a contact email: plain address, no mailto: prefix (it's used
     * both as a mailto: link and as plain display text).
     *
     * @param mixed $raw
     * @return array{value:string,error:string}
     */
    public static function validate_contact_email( $raw ) {
        $email = is_string( $raw ) ? trim( $raw ) : '';

        if ( '' === $email ) {
            return array( 'value' => '', 'error' => '' );
        }

        $clean = sanitize_email( $email );

        if ( '' === $clean || ! is_email( $clean ) ) {
            return array(
                'value' => '',
                'error' => 'That doesn’t look like an email address. Please type one like office@yourschool.org.',
            );
        }

        return array( 'value' => $clean, 'error' => '' );
    }

    /**
     * What to tell someone after they pick a color pair. The direction
     * (lightened/darkened) is read off the two ACTUAL hex values, so this
     * works the same whether it is being told about the text color moving
     * against a navy background or a custom one.
     *
     * @param string $picked What they chose ('#rrggbb').
     * @param string $saved  What was stored after the contrast guard.
     * @return string
     */
    public static function color_message( $picked, $saved ) {
        $picked = PTK_Share_Color::normalize_hex( $picked );
        $saved  = PTK_Share_Color::normalize_hex( $saved );

        if ( $picked === $saved ) {
            return sprintf( 'Saved. Your color %s reads clearly against your background.', $saved );
        }

        if ( PTK_Share_Color::relative_luminance( $saved ) > PTK_Share_Color::relative_luminance( $picked ) ) {
            return sprintf(
                'That color (%1$s) was too dark to read on your square, so we lightened it to %2$s and saved that instead.',
                $picked,
                $saved
            );
        }

        return sprintf(
            'That color (%1$s) was too hard to read on your square, so we darkened it to %2$s and saved that instead.',
            $picked,
            $saved
        );
    }

    /**
     * Work out which color was submitted: the typed color code or the
     * picker. Both are sent, and JavaScript keeps them in step -- but without
     * JavaScript they can disagree, so the one the person actually CHANGED
     * wins: the typed code if it differs from what the page showed
     * ($initial), otherwise the picker.
     *
     * Typed codes are forgiving: "1a2f5c", "#1a2f5c" and "#abc" all work.
     *
     * @param mixed $picker  The native color input's value.
     * @param mixed $typed   The text field's value.
     * @param mixed $initial The color the page was rendered with.
     * @return array{value:string,error:string} value '#rrggbb', or '' with an error.
     */
    public static function submitted_color( $picker, $typed, $initial ) {
        $typed   = is_string( $typed ) ? trim( $typed ) : '';
        $initial = PTK_Share_Color::is_hex( $initial ) ? PTK_Share_Color::normalize_hex( $initial ) : '';

        $typed_changed = '' !== $typed
            && ( ! PTK_Share_Color::is_hex( $typed ) || PTK_Share_Color::normalize_hex( $typed ) !== $initial );

        if ( $typed_changed ) {
            if ( PTK_Share_Color::is_hex( $typed ) ) {
                return array( 'value' => PTK_Share_Color::normalize_hex( $typed ), 'error' => '' );
            }
            return array(
                'value' => '',
                'error' => sprintf( '“%s” isn’t a color code. Type six letters and numbers, like 1a2f5c.', substr( $typed, 0, 40 ) ),
            );
        }

        if ( PTK_Share_Color::is_hex( $picker ) ) {
            return array( 'value' => PTK_Share_Color::normalize_hex( $picker ), 'error' => '' );
        }

        return array( 'value' => '', 'error' => 'Please pick a color or type its code, like 1a2f5c.' );
    }

    /**
     * Which of the three places a color came from, in plain words. Kept for
     * the general "why is this the color you see" wording; the square
     * itself no longer has a Council-fallback source (4.3.0 amendment) --
     * this is not called for the square's two fields any more, but stays
     * available (and tested) as a small, reusable piece of copy.
     *
     * @param string $source 'own' | 'council' | 'default'
     * @return string
     */
    public static function source_label( $source ) {
        switch ( $source ) {
            case 'own':
                return 'your school’s own pick';
            case 'council':
                return 'the color the district council set for your school';
            default:
                return 'the color your school’s website came with, since nobody has set one yet';
        }
    }

    /* ------------------------------------------------------------------
     * WordPress
     * ----------------------------------------------------------------*/

    public static function page_url( $args = array() ) {
        return add_query_arg(
            array_merge( array( 'post_type' => 'pta_newsletter', 'page' => self::PAGE_SLUG ), $args ),
            admin_url( 'edit.php' )
        );
    }

    public static function add_page() {
        self::$hook = (string) add_submenu_page(
            'edit.php?post_type=pta_newsletter',
            'Newsletter settings',
            'Newsletter settings',
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( '' === self::$hook || $hook !== self::$hook ) {
            return;
        }
        wp_enqueue_style( 'ptk-share-settings', PTK_PLUGIN_URL . 'assets/css/share-settings.css', array(), PTK_VERSION );
        wp_enqueue_script( 'ptk-share-settings', PTK_PLUGIN_URL . 'assets/js/share-settings.js', array(), PTK_VERSION, true );
    }

    /**
     * Save all seven fields. Nonce-checked POST to admin-post.php.
     */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Only this site’s administrators can change the newsletter settings.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::ACTION );

        $notice = array(
            'messages'      => array(),
            'fb_error'      => '',
            'fb_typed'      => '',
            'bg_error'      => '',
            'bg_typed'      => '',
            'color_error'   => '',
            'color_typed'   => '',
            'join_error'    => '',
            'join_typed'    => '',
            'news_error'    => '',
            'news_typed'    => '',
            'cal_error'     => '',
            'cal_typed'     => '',
            'email_error'   => '',
            'email_typed'   => '',
        );

        // ---- Background color ----
        $bg_choice = self::submitted_color(
            isset( $_POST['ptk_share_bg_color'] ) ? (string) wp_unslash( $_POST['ptk_share_bg_color'] ) : '',
            isset( $_POST['ptk_share_bg_color_hex'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_share_bg_color_hex'] ) ) : '',
            isset( $_POST['ptk_share_bg_color_initial'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_share_bg_color_initial'] ) ) : ''
        );
        if ( '' !== $bg_choice['error'] ) {
            $notice['bg_error'] = $bg_choice['error'];
            $notice['bg_typed'] = isset( $_POST['ptk_share_bg_color_hex'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['ptk_share_bg_color_hex'] ) ), 0, 40 ) : '';
            $notice['messages'][] = array( 'error', 'The background color was not saved. ' . $bg_choice['error'] );
        } else {
            $previous = get_option( PTK_Share_Color::BG_OPTION, '' );
            update_option( PTK_Share_Color::BG_OPTION, $bg_choice['value'] );
            if ( $previous !== $bg_choice['value'] ) {
                $notice['messages'][] = array( 'ok', sprintf( 'Background color saved: %s.', $bg_choice['value'] ) );
            }
        }

        // ---- Text color -- checked for readability against the background ----
        $background = ( '' !== $bg_choice['error'] )
            ? PTK_Share_Color::square_background_color()
            : PTK_Share_Color::normalize_hex( $bg_choice['value'] );

        $text_choice = self::submitted_color(
            isset( $_POST['ptk_share_color'] ) ? (string) wp_unslash( $_POST['ptk_share_color'] ) : '',
            isset( $_POST['ptk_share_color_hex'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_share_color_hex'] ) ) : '',
            isset( $_POST['ptk_share_color_initial'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_share_color_initial'] ) ) : ''
        );
        if ( '' !== $text_choice['error'] ) {
            $notice['color_error'] = $text_choice['error'];
            $notice['color_typed'] = isset( $_POST['ptk_share_color_hex'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['ptk_share_color_hex'] ) ), 0, 40 ) : '';
            $notice['messages'][] = array( 'error', 'The text color was not saved. ' . $text_choice['error'] );
        } else {
            $picked   = $text_choice['value'];
            $saved    = PTK_Share_Color::readable_pair( $picked, $background );
            $previous = get_option( PTK_Share_Color::OPTION, '' );
            update_option( PTK_Share_Color::OPTION, $saved );
            if ( $previous !== $saved ) {
                $notice['messages'][] = array( $picked === $saved ? 'ok' : 'warn', self::color_message( $picked, $saved ) );
            }
        }

        // ---- Facebook group link ----
        // Not sanitize_text_field(): it would strip %XX from a pasted link.
        $typed  = isset( $_POST['ptk_share_facebook_url'] ) ? (string) wp_unslash( $_POST['ptk_share_facebook_url'] ) : '';
        $typed  = wp_check_invalid_utf8( $typed, true );
        $result = self::validate_facebook_url( $typed );
        $before = (string) get_option( self::FB_OPTION, '' );

        if ( '' !== $result['error'] ) {
            $notice['fb_error'] = $result['error'];
            $notice['fb_typed'] = substr( $typed, 0, 2000 );
            $notice['messages'][] = array( 'error', 'The Facebook group link was not saved. ' . $result['error'] );
        } elseif ( '' === $result['value'] ) {
            delete_option( self::FB_OPTION );
            if ( '' !== $before ) {
                $notice['messages'][] = array( 'ok', 'The Facebook group link is removed. The share panel won’t show a Facebook button.' );
            }
        } else {
            $clean = esc_url_raw( $result['value'], array( 'https' ) );
            if ( '' === $clean ) {
                $notice['fb_error'] = 'That link could not be saved. Please copy it again from your browser’s address bar.';
                $notice['fb_typed'] = substr( $typed, 0, 2000 );
                $notice['messages'][] = array( 'error', $notice['fb_error'] );
            } else {
                update_option( self::FB_OPTION, $clean );
                if ( $clean !== $before ) {
                    $notice['messages'][] = array( 'ok', 'Facebook group link saved.' );
                }
            }
        }

        // ---- Join / News / Calendar links ----
        $link_fields = array(
            'join'  => array( 'option' => self::JOIN_OPTION, 'post' => 'ptk_join_url', 'label' => 'Join the PTA link', 'example' => 'join@yourschool.org' ),
            'news'  => array( 'option' => self::NEWS_OPTION, 'post' => 'ptk_news_url', 'label' => 'Send us your news link', 'example' => 'news@yourschool.org' ),
            'cal'   => array( 'option' => self::CAL_OPTION, 'post' => 'ptk_calendar_url', 'label' => 'Calendar page link', 'example' => 'https://yourschool.org/calendar' ),
        );
        foreach ( $link_fields as $key => $field ) {
            $typed  = isset( $_POST[ $field['post'] ] ) ? (string) wp_unslash( $_POST[ $field['post'] ] ) : '';
            $typed  = wp_check_invalid_utf8( $typed, true );
            $result = self::validate_link_field( $typed, $field['example'] );
            $before = (string) get_option( $field['option'], '' );

            if ( '' !== $result['error'] ) {
                $notice[ $key . '_error' ] = $result['error'];
                $notice[ $key . '_typed' ] = substr( $typed, 0, 2000 );
                $notice['messages'][] = array( 'error', 'The ' . $field['label'] . ' was not saved. ' . $result['error'] );
            } elseif ( '' === $result['value'] ) {
                delete_option( $field['option'] );
                if ( '' !== $before ) {
                    $notice['messages'][] = array( 'ok', $field['label'] . ' removed.' );
                }
            } else {
                update_option( $field['option'], $result['value'] );
                if ( $result['value'] !== $before ) {
                    $notice['messages'][] = array( 'ok', $field['label'] . ' saved.' );
                }
            }
        }

        // ---- Contact email ----
        $typed  = isset( $_POST['ptk_contact_email'] ) ? sanitize_text_field( wp_unslash( $_POST['ptk_contact_email'] ) ) : '';
        $result = self::validate_contact_email( $typed );
        $before = (string) get_option( self::EMAIL_OPTION, '' );

        if ( '' !== $result['error'] ) {
            $notice['email_error'] = $result['error'];
            $notice['email_typed'] = substr( $typed, 0, 200 );
            $notice['messages'][] = array( 'error', 'The contact email was not saved. ' . $result['error'] );
        } elseif ( '' === $result['value'] ) {
            delete_option( self::EMAIL_OPTION );
            if ( '' !== $before ) {
                $notice['messages'][] = array( 'ok', 'Contact email removed.' );
            }
        } else {
            update_option( self::EMAIL_OPTION, $result['value'] );
            if ( $result['value'] !== $before ) {
                $notice['messages'][] = array( 'ok', 'Contact email saved.' );
            }
        }

        if ( empty( $notice['messages'] ) ) {
            $notice['messages'][] = array( 'ok', 'Settings saved. Nothing needed changing.' );
        }

        set_transient( self::NOTICE_KEY . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( self::page_url( array( 'saved' => 1 ) ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Only this site’s administrators can change the newsletter settings.', 'Not allowed', array( 'response' => 403 ) );
        }

        $notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
        if ( false !== $notice ) {
            delete_transient( self::NOTICE_KEY . get_current_user_id() );
        }
        if ( ! is_array( $notice ) ) {
            $notice = array();
        }
        $notice = array_merge( array(
            'fb_error' => '', 'fb_typed' => '',
            'bg_error' => '', 'bg_typed' => '',
            'color_error' => '', 'color_typed' => '',
            'join_error' => '', 'join_typed' => '',
            'news_error' => '', 'news_typed' => '',
            'cal_error' => '', 'cal_typed' => '',
            'email_error' => '', 'email_typed' => '',
            'messages' => array(),
        ), $notice );

        $bg_raw   = get_option( PTK_Share_Color::BG_OPTION, '' );
        $bg_has   = PTK_Share_Color::is_hex( $bg_raw );
        $bg_value = PTK_Share_Color::square_background_color();
        $bg_typed = '' !== $notice['bg_error'] ? (string) $notice['bg_typed'] : $bg_value;

        $text_raw   = get_option( PTK_Share_Color::OPTION, '' );
        $text_has   = PTK_Share_Color::is_hex( $text_raw );
        $text_value = PTK_Share_Color::square_text_color();
        $drawn      = PTK_Share_Color::readable_pair( $text_value, $bg_value );
        $text_typed = '' !== $notice['color_error'] ? (string) $notice['color_typed'] : $text_value;

        $fb_value    = '' !== $notice['fb_error'] ? (string) $notice['fb_typed'] : (string) get_option( self::FB_OPTION, '' );
        $join_value  = '' !== $notice['join_error'] ? (string) $notice['join_typed'] : (string) get_option( self::JOIN_OPTION, '' );
        $news_value  = '' !== $notice['news_error'] ? (string) $notice['news_typed'] : (string) get_option( self::NEWS_OPTION, '' );
        $cal_value   = '' !== $notice['cal_error'] ? (string) $notice['cal_typed'] : (string) get_option( self::CAL_OPTION, '' );
        $email_value = '' !== $notice['email_error'] ? (string) $notice['email_typed'] : (string) get_option( self::EMAIL_OPTION, '' );
        ?>
        <div class="wrap ptk-share-settings">
            <h1>Newsletter settings</h1>
            <p class="ptk-ss-intro">Set these once and every newsletter carries them automatically: the two colors on the Instagram square, your Facebook group, the links families use to join, send news, and see the calendar, and where questions should go. Leave anything blank to leave it out of the newsletter -- nothing here is required.</p>

            <?php foreach ( (array) $notice['messages'] as $msg ) :
                if ( ! is_array( $msg ) || count( $msg ) < 2 ) {
                    continue;
                }
                $kind = in_array( $msg[0], array( 'ok', 'warn', 'error' ), true ) ? $msg[0] : 'ok';
                ?>
                <div class="ptk-ss-msg ptk-ss-msg-<?php echo esc_attr( $kind ); ?>" role="<?php echo 'error' === $kind ? 'alert' : 'status'; ?>">
                    <p><?php echo esc_html( $msg[1] ); ?></p>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate data-ptk-share-settings>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php wp_nonce_field( self::ACTION ); ?>

                <h2>Colors on the Instagram square</h2>
                <p>Two colors: the square's background and its text. Leave either one alone and it uses the default shown below.</p>

                <div class="ptk-ss-color">
                    <div class="ptk-ss-colorfield">
                        <label for="ptk-share-bg-color"><strong>Background</strong> -- default navy (#1a2f5c)</label>
                        <div class="ptk-ss-picker">
                            <input type="color" id="ptk-share-bg-color" name="ptk_share_bg_color" value="<?php echo esc_attr( $bg_value ); ?>" data-ptk-bg-picker>
                            <label for="ptk-share-bg-color-hex" class="screen-reader-text">Background color code</label>
                            <input type="text" id="ptk-share-bg-color-hex" name="ptk_share_bg_color_hex" class="ptk-ss-hex" value="<?php echo esc_attr( $bg_typed ); ?>" maxlength="7" spellcheck="false" autocomplete="off" autocapitalize="off" placeholder="1a2f5c" data-ptk-bg-hex aria-describedby="ptk-share-bg-error"<?php echo '' !== $notice['bg_error'] ? ' aria-invalid="true"' : ''; ?>>
                            <input type="hidden" name="ptk_share_bg_color_initial" value="<?php echo esc_attr( $bg_value ); ?>">
                        </div>
                        <p class="ptk-ss-field-error" id="ptk-share-bg-error" role="alert"><?php echo esc_html( $notice['bg_error'] ); ?></p>
                    </div>

                    <div class="ptk-ss-colorfield">
                        <label for="ptk-share-color"><strong>Text</strong> -- default white (#ffffff)</label>
                        <div class="ptk-ss-picker">
                            <input type="color" id="ptk-share-color" name="ptk_share_color" value="<?php echo esc_attr( $text_value ); ?>" data-ptk-text-picker>
                            <label for="ptk-share-color-hex" class="screen-reader-text">Text color code</label>
                            <input type="text" id="ptk-share-color-hex" name="ptk_share_color_hex" class="ptk-ss-hex" value="<?php echo esc_attr( $text_typed ); ?>" maxlength="7" spellcheck="false" autocomplete="off" autocapitalize="off" placeholder="ffffff" data-ptk-text-hex aria-describedby="ptk-share-color-error"<?php echo '' !== $notice['color_error'] ? ' aria-invalid="true"' : ''; ?>>
                            <input type="hidden" name="ptk_share_color_initial" value="<?php echo esc_attr( $text_value ); ?>">
                        </div>
                        <p class="ptk-ss-field-error" id="ptk-share-color-error" role="alert"><?php echo esc_html( $notice['color_error'] ); ?></p>
                        <?php if ( $text_has && $drawn !== $text_value ) : ?>
                            <p class="description">On your square it is adjusted to <code><?php echo esc_html( $drawn ); ?></code> so it can be read against the background.</p>
                        <?php endif; ?>
                    </div>

                    <div class="ptk-ss-preview-wrap">
                        <div class="ptk-ss-preview" data-preview aria-hidden="true" style="--ptk-ss-bg:<?php echo esc_attr( $bg_value ); ?>;--ptk-ss-accent:<?php echo esc_attr( $drawn ); ?>;">
                            <span class="ptk-ss-preview-eyebrow">NEWSLETTER</span>
                            <span class="ptk-ss-preview-rule"></span>
                            <span class="ptk-ss-preview-issue">№ 041</span>
                            <span class="ptk-ss-preview-date">Week of September 21</span>
                        </div>
                        <p class="ptk-ss-preview-note" data-preview-note aria-live="polite">How the colors look on the square.</p>
                    </div>
                </div>

                <h2>Facebook group link</h2>
                <p>
                    <label for="ptk-share-facebook-url">Your PTA’s Facebook group</label><br>
                    <input type="url" class="regular-text" id="ptk-share-facebook-url" name="ptk_share_facebook_url" value="<?php echo esc_attr( $fb_value ); ?>" placeholder="https://www.facebook.com/groups/yourgroup" inputmode="url" autocomplete="off"<?php echo '' !== $notice['fb_error'] ? ' aria-invalid="true" aria-describedby="ptk-share-facebook-error"' : ''; ?>>
                </p>
                <?php if ( '' !== $notice['fb_error'] ) : ?>
                    <p class="ptk-ss-field-error" id="ptk-share-facebook-error"><?php echo esc_html( $notice['fb_error'] ); ?></p>
                <?php endif; ?>
                <p class="description">Open your group in a browser and copy the address from the top of the window. It must start with https://. Leave it empty if your PTA has no group -- the share panel then leaves out the Facebook button.</p>

                <h2>Join the PTA</h2>
                <p>
                    <label for="ptk-join-url">Where families go to join</label><br>
                    <input type="text" inputmode="url" class="regular-text" id="ptk-join-url" name="ptk_join_url" value="<?php echo esc_attr( $join_value ); ?>" placeholder="https://yourschool.org/join or join@yourschool.org" autocomplete="off"<?php echo '' !== $notice['join_error'] ? ' aria-invalid="true" aria-describedby="ptk-join-error"' : ''; ?>>
                </p>
                <?php if ( '' !== $notice['join_error'] ) : ?>
                    <p class="ptk-ss-field-error" id="ptk-join-error"><?php echo esc_html( $notice['join_error'] ); ?></p>
                <?php endif; ?>
                <p class="description">A web address, or a plain email address. Shows as "Join the PTA for {school year} →" in the masthead of every newsletter. Leave it empty to leave the link out.</p>

                <h2>Send us your news</h2>
                <p>
                    <label for="ptk-news-url">Where families send news to include</label><br>
                    <input type="text" inputmode="url" class="regular-text" id="ptk-news-url" name="ptk_news_url" value="<?php echo esc_attr( $news_value ); ?>" placeholder="https://yourschool.org/submit-news or news@yourschool.org" autocomplete="off"<?php echo '' !== $notice['news_error'] ? ' aria-invalid="true" aria-describedby="ptk-news-error"' : ''; ?>>
                </p>
                <?php if ( '' !== $notice['news_error'] ) : ?>
                    <p class="ptk-ss-field-error" id="ptk-news-error"><?php echo esc_html( $notice['news_error'] ); ?></p>
                <?php endif; ?>
                <p class="description">Adds a "Got news? Put it in the newsletter." closing to every newsletter, right before the footer. Leave it empty and that closing is left out entirely.</p>

                <h2>Calendar page</h2>
                <p>
                    <label for="ptk-calendar-url">Where families see the full calendar</label><br>
                    <input type="text" inputmode="url" class="regular-text" id="ptk-calendar-url" name="ptk_calendar_url" value="<?php echo esc_attr( $cal_value ); ?>" placeholder="https://yourschool.org/calendar or calendar@yourschool.org" autocomplete="off"<?php echo '' !== $notice['cal_error'] ? ' aria-invalid="true" aria-describedby="ptk-calendar-error"' : ''; ?>>
                </p>
                <?php if ( '' !== $notice['cal_error'] ) : ?>
                    <p class="ptk-ss-field-error" id="ptk-calendar-error"><?php echo esc_html( $notice['cal_error'] ); ?></p>
                <?php endif; ?>
                <p class="description">Adds a "See full calendar →" link next to "What's coming up" -- only shown once there's at least one date listed. Leave it empty to leave the link out.</p>

                <h2>Contact email</h2>
                <p>
                    <label for="ptk-contact-email">Where questions about the newsletter go</label><br>
                    <input type="email" class="regular-text" id="ptk-contact-email" name="ptk_contact_email" value="<?php echo esc_attr( $email_value ); ?>" placeholder="office@yourschool.org" autocomplete="off"<?php echo '' !== $notice['email_error'] ? ' aria-invalid="true" aria-describedby="ptk-contact-email-error"' : ''; ?>>
                </p>
                <?php if ( '' !== $notice['email_error'] ) : ?>
                    <p class="ptk-ss-field-error" id="ptk-contact-email-error"><?php echo esc_html( $notice['email_error'] ); ?></p>
                <?php endif; ?>
                <p class="description">Added to the "Got news?" closing as "Questions? Email {address}." Only shown when a news link above is also set. Leave it empty to leave it out.</p>

                <?php submit_button( 'Save settings' ); ?>
            </form>
        </div>
        <?php
    }
}
