<?php
/**
 * Newsletters > Sharing settings: the two per-school settings the share
 * panel needs.
 *
 *   ptk_share_color         the colour on the Instagram square
 *   ptk_share_facebook_url  the PTA's Facebook group (https only, may be empty)
 *
 * Both are BLOG options: each school sets its own, on its own site, from the
 * menu where it already works on newsletters. This is a separate page from
 * PTK_Site_Colors' "School Colors" screen on purpose -- that one is the
 * Council's network palette, and it feeds the owner dots on every site's
 * Knowledge Hub list. Nothing here reads or writes `ptk_site_colors`, and
 * PTK_Site_Colors::color_for() never looks at `ptk_share_color`.
 *
 * Kept out of PTK_Share_Color, which stays pure contrast maths plus one thin
 * option read. The validation and wording helpers below are pure PHP too, so
 * tests/test-share-settings.php covers them without WordPress.
 *
 * The contrast guard is visible: a colour that can't be read on the navy
 * square is adjusted, the ADJUSTED colour is what gets saved, and the page
 * says so in plain words. Nothing is swapped silently.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Settings {

    const PAGE_SLUG    = 'ptk-share-settings';
    const ACTION       = 'ptk_share_settings';
    const FB_OPTION    = 'ptk_share_facebook_url';
    const NOTICE_KEY   = 'ptk_share_settings_notice_';

    /** The ground the square's accent colour sits on. */
    const GROUND = '#1a2f5c';

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
     * What to tell someone after they pick a colour.
     *
     * @param string $picked What they chose ('#rrggbb').
     * @param string $saved  What was stored after the contrast guard.
     * @return string
     */
    public static function color_message( $picked, $saved ) {
        $picked = PTK_Share_Color::normalize_hex( $picked );
        $saved  = PTK_Share_Color::normalize_hex( $saved );

        if ( $picked === $saved ) {
            return sprintf( 'Saved. Your colour %s reads clearly on the navy square.', $saved );
        }

        if ( PTK_Share_Color::relative_luminance( $saved ) > PTK_Share_Color::relative_luminance( $picked ) ) {
            return sprintf(
                'That colour (%1$s) was too dark to read on the navy square, so we lightened it to %2$s and saved that instead.',
                $picked,
                $saved
            );
        }

        return sprintf(
            'That colour (%1$s) was too hard to read on the navy square, so we darkened it to %2$s and saved that instead.',
            $picked,
            $saved
        );
    }

    /**
     * Which of the three places the colour came from, in plain words.
     *
     * @param string $source 'own' | 'council' | 'default'
     * @return string
     */
    public static function source_label( $source ) {
        switch ( $source ) {
            case 'own':
                return 'your school’s own pick';
            case 'council':
                return 'the colour the Council chose for your school';
            default:
                return 'the standard colour for your school';
        }
    }

    /* ------------------------------------------------------------------
     * WordPress
     * ----------------------------------------------------------------*/

    /**
     * Where the colour in use right now comes from.
     *
     * @return string 'own' | 'council' | 'default'
     */
    public static function color_source() {
        if ( PTK_Share_Color::is_hex( get_option( PTK_Share_Color::OPTION, '' ) ) ) {
            return 'own';
        }
        $network = get_site_option( 'ptk_site_colors', array() );
        $blog_id = (int) get_current_blog_id();
        if ( is_array( $network ) && isset( $network[ $blog_id ] ) && PTK_Share_Color::is_hex( $network[ $blog_id ] ) ) {
            return 'council';
        }
        return 'default';
    }

    /**
     * The colour this school falls back to when it has no pick of its own.
     * Read-only use of the Council's palette.
     *
     * @return string '#rrggbb'
     */
    public static function council_color() {
        if ( class_exists( 'PTK_Site_Colors' ) ) {
            return PTK_Share_Color::normalize_hex( PTK_Site_Colors::color_for( get_current_blog_id() ) );
        }
        return PTK_Share_Color::FALLBACK;
    }

    public static function page_url( $args = array() ) {
        return add_query_arg(
            array_merge( array( 'post_type' => 'pta_newsletter', 'page' => self::PAGE_SLUG ), $args ),
            admin_url( 'edit.php' )
        );
    }

    public static function add_page() {
        self::$hook = (string) add_submenu_page(
            'edit.php?post_type=pta_newsletter',
            'Sharing settings',
            'Sharing settings',
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
     * Save both fields. Nonce-checked POST to admin-post.php.
     */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Only this site’s administrators can change the sharing settings.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::ACTION );

        $notice = array(
            'messages' => array(),
            'fb_error' => '',
            'fb_typed' => '',
        );

        // ---- Colour ----
        $mode = isset( $_POST['ptk_share_color_mode'] ) ? sanitize_key( wp_unslash( $_POST['ptk_share_color_mode'] ) ) : '';

        if ( 'council' === $mode ) {
            $had_own = PTK_Share_Color::is_hex( get_option( PTK_Share_Color::OPTION, '' ) );
            delete_option( PTK_Share_Color::OPTION );
            if ( $had_own ) {
                $notice['messages'][] = array( 'ok', 'Your own colour is cleared. The square now uses the Council’s colour for your school.' );
            }
        } elseif ( 'own' === $mode ) {
            $picked = isset( $_POST['ptk_share_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['ptk_share_color'] ) ) : '';
            if ( ! $picked ) {
                $notice['messages'][] = array( 'error', 'Please pick a colour, or choose “Use the Council’s colour”.' );
            } else {
                $picked   = PTK_Share_Color::normalize_hex( $picked );
                $saved    = PTK_Share_Color::readable_pair( $picked, self::GROUND );
                $previous = get_option( PTK_Share_Color::OPTION, '' );
                update_option( PTK_Share_Color::OPTION, $saved );
                if ( $picked !== $saved || ! PTK_Share_Color::is_hex( $previous ) || PTK_Share_Color::normalize_hex( $previous ) !== $saved ) {
                    $notice['messages'][] = array( $picked === $saved ? 'ok' : 'warn', self::color_message( $picked, $saved ) );
                }
            }
        }

        // ---- Facebook group link ----
        // Not sanitize_text_field(): it would strip %XX from a pasted link.
        $typed  = isset( $_POST['ptk_share_facebook_url'] ) ? (string) wp_unslash( $_POST['ptk_share_facebook_url'] ) : '';
        $typed  = wp_check_invalid_utf8( $typed, true );
        $result = self::validate_facebook_url( $typed );
        $before = (string) get_option( self::FB_OPTION, '' );

        if ( '' !== $result['error'] ) {
            // Keep what they had; show what they typed so they can fix it.
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

        if ( empty( $notice['messages'] ) ) {
            $notice['messages'][] = array( 'ok', 'Settings saved. Nothing needed changing.' );
        }

        set_transient( self::NOTICE_KEY . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( self::page_url( array( 'saved' => 1 ) ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Only this site’s administrators can change the sharing settings.', 'Not allowed', array( 'response' => 403 ) );
        }

        $notice = get_transient( self::NOTICE_KEY . get_current_user_id() );
        if ( false !== $notice ) {
            delete_transient( self::NOTICE_KEY . get_current_user_id() );
        }
        if ( ! is_array( $notice ) ) {
            $notice = array( 'messages' => array(), 'fb_error' => '', 'fb_typed' => '' );
        }

        $own_raw  = get_option( PTK_Share_Color::OPTION, '' );
        $has_own  = PTK_Share_Color::is_hex( $own_raw );
        $council  = self::council_color();
        $current  = PTK_Share_Color::share_color();
        $drawn    = PTK_Share_Color::readable_pair( $current, self::GROUND );
        $source   = self::color_source();
        $picker   = $has_own ? PTK_Share_Color::normalize_hex( $own_raw ) : $council;

        $fb_value = '' !== $notice['fb_error'] ? (string) $notice['fb_typed'] : (string) get_option( self::FB_OPTION, '' );
        ?>
        <div class="wrap ptk-share-settings">
            <h1>Sharing settings</h1>
            <p class="ptk-ss-intro">These two settings are used by <strong>Share this newsletter</strong>, on the last step of the newsletter builder. They only affect this school’s site.</p>

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

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate data-ptk-share-settings data-ground="<?php echo esc_attr( self::GROUND ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php wp_nonce_field( self::ACTION ); ?>

                <h2>Colour on the Instagram square</h2>
                <p>
                    Right now the square uses
                    <span class="ptk-ss-chip" style="background:<?php echo esc_attr( $current ); ?>;" aria-hidden="true"></span>
                    <code><?php echo esc_html( $current ); ?></code>,
                    <?php echo esc_html( self::source_label( $source ) ); ?>.
                    <?php if ( $drawn !== $current ) : ?>
                        On the navy square it is lightened to <code><?php echo esc_html( $drawn ); ?></code> so it can be read.
                    <?php endif; ?>
                </p>

                <div class="ptk-ss-color">
                    <fieldset class="ptk-ss-choices">
                        <legend class="screen-reader-text">Which colour to use</legend>
                        <label class="ptk-ss-choice">
                            <input type="radio" name="ptk_share_color_mode" value="council" data-color="<?php echo esc_attr( $council ); ?>" <?php checked( ! $has_own ); ?>>
                            Use the Council’s colour
                            <span class="ptk-ss-chip" style="background:<?php echo esc_attr( $council ); ?>;" aria-hidden="true"></span>
                            <code><?php echo esc_html( $council ); ?></code>
                        </label>
                        <label class="ptk-ss-choice">
                            <input type="radio" name="ptk_share_color_mode" value="own" <?php checked( $has_own ); ?>>
                            Use our own colour
                        </label>
                        <p class="ptk-ss-picker">
                            <label for="ptk-share-color">Our colour</label>
                            <input type="color" id="ptk-share-color" name="ptk_share_color" value="<?php echo esc_attr( $picker ); ?>">
                        </p>
                    </fieldset>

                    <div class="ptk-ss-preview-wrap">
                        <div class="ptk-ss-preview" data-preview aria-hidden="true" style="--ptk-ss-accent:<?php echo esc_attr( $drawn ); ?>;">
                            <span class="ptk-ss-preview-eyebrow">NEWSLETTER</span>
                            <span class="ptk-ss-preview-rule"></span>
                            <span class="ptk-ss-preview-issue">№ 041</span>
                            <span class="ptk-ss-preview-date">Week of September 21</span>
                        </div>
                        <p class="ptk-ss-preview-note" data-preview-note aria-live="polite">How the colour looks on the navy square.</p>
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

                <?php submit_button( 'Save settings' ); ?>
            </form>
        </div>
        <?php
    }
}
