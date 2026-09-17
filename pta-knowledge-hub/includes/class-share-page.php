<?php
/**
 * The phone page: `/?ptk_share=<newsletter id>`.
 *
 * The share panel's QR code opens this, because Instagram can only really be
 * posted from a phone. It shows the square picture (press and hold to save),
 * the Instagram caption, and the WhatsApp text -- nothing else.
 *
 * WHO CAN SEE IT: anyone with the link. That is safe only because it is
 * limited to PUBLISHED pta_newsletter posts, which are public already. The
 * post-type check is not optional: without it a guessed id could leak the
 * title of a restricted pta_knowledge entry. There is no token, and
 * PTK_Public_Preview's token is deliberately not reused -- it only matches
 * unpublished posts and is deleted on publish, the exact opposite of this.
 *
 * STRICTLY READ-ONLY. An anonymous request must never make the server draw
 * an image or write to the media library, so this class never calls
 * PTK_Share_Image::ensure_square(), never inserts an attachment and never
 * writes post meta. It shows the square the admin panel already stored, or
 * no picture at all. Caption TEXT comes from PTK_Share_Data::resolve_caption(),
 * which is stateless and writes nothing.
 *
 * A standalone HTML document, not a theme template: it is a tool page for a
 * volunteer's phone, and the theme's header and menu would crowd it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Page {

    const QUERY_VAR = 'ptk_share';

    public static function init() {
        // Without registration get_query_var() returns nothing.
        add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
    }

    public static function register_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * The newsletter id from a raw query value, or 0. Only a plain run of
     * digits counts: "7abc" is not 7.
     *
     * @param mixed $raw
     * @return int
     */
    public static function parse_id( $raw ) {
        if ( ! is_scalar( $raw ) ) {
            return 0;
        }
        $raw = trim( (string) $raw );
        if ( ! preg_match( '/^[0-9]{1,20}$/', $raw ) ) {
            return 0;
        }
        return absint( $raw );
    }

    /**
     * Is this post one the phone page may show to anybody?
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_shareable( $post_id ) {
        if ( ! $post_id ) {
            return false;
        }
        if ( 'pta_newsletter' !== get_post_type( $post_id ) ) {
            return false;
        }
        if ( 'publish' !== get_post_status( $post_id ) ) {
            return false;
        }
        // A password-protected newsletter is not public; don't hand its
        // captions to anyone who guesses the id.
        $post = get_post( $post_id );
        return $post && '' === (string) $post->post_password;
    }

    public static function maybe_render() {
        $raw = get_query_var( self::QUERY_VAR );
        if ( '' === $raw || null === $raw ) {
            return;
        }

        $post_id = self::parse_id( $raw );

        if ( ! self::is_shareable( $post_id ) ) {
            self::not_found();
            return;
        }

        self::render( $post_id );
        exit;
    }

    /**
     * A real 404: the theme's own 404 template renders afterwards.
     */
    protected static function not_found() {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
    }

    /**
     * The stored square's full-size image, read only. Never generates one.
     *
     * @param int $post_id
     * @return array{url:string,width:int,height:int}|null
     */
    protected static function stored_square( $post_id ) {
        $square        = PTK_Share_Data::get_square( $post_id );
        $attachment_id = (int) $square['image_id'];

        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
            return null;
        }

        $src = wp_get_attachment_image_src( $attachment_id, 'full' );
        if ( ! $src || empty( $src[0] ) ) {
            return null;
        }

        return array(
            'url'    => (string) $src[0],
            'width'  => (int) $src[1],
            'height' => (int) $src[2],
        );
    }

    /**
     * Print the page.
     *
     * @param int $post_id A shareable newsletter.
     */
    protected static function render( $post_id ) {
        $ctx       = PTK_Share_Panel::context( $post_id );
        $opts      = $ctx['opts'];
        $instagram = PTK_Share_Data::resolve_caption( $post_id, 'instagram', $ctx['blocks'], $opts );
        $facebook  = PTK_Share_Data::resolve_caption( $post_id, 'facebook', $ctx['blocks'], $opts );
        $whatsapp  = PTK_Share_Data::resolve_caption( $post_id, 'whatsapp', $ctx['blocks'], $opts );
        $square    = self::stored_square( $post_id );

        $school   = (string) $opts['school_name'];
        $issue    = PTK_Share_Text::issue_label( $opts['issue'] );
        $dateline = PTK_Share_Image::dateline( $opts['date'] );
        $headline = '' !== $issue ? 'Share Newsletter №' . "\xC2\xA0" . $issue : 'Share this newsletter';
        $title    = $headline . ( '' !== $school ? ' · ' . $school : '' );

        // Only ever our own wa.me link; anything else is dropped, not printed.
        $wa_href = PTK_Share_Panel::whatsapp_url( $whatsapp['text'] );
        if ( 0 !== strpos( $wa_href, 'https://wa.me/?text=' ) ) {
            $wa_href = '';
        }

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );

        $css = PTK_PLUGIN_URL . 'assets/css/share-page.css?ver=' . rawurlencode( PTK_VERSION );
        $js  = PTK_PLUGIN_URL . 'assets/js/share-page.js?ver=' . rawurlencode( PTK_VERSION );
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1a2f5c">
<title><?php echo esc_html( $title ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;600;700;800&amp;family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&amp;display=swap">
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
</head>
<body class="ptk-sp">
<main class="ptk-sp-page">

    <header class="ptk-sp-head">
        <?php if ( '' !== $school ) : ?>
            <p class="ptk-sp-label"><?php echo esc_html( $school ); ?></p>
        <?php endif; ?>
        <h1 class="ptk-sp-title"><?php echo esc_html( $headline ); ?></h1>
        <?php if ( '' !== $dateline ) : ?>
            <p class="ptk-sp-dateline"><?php echo esc_html( $dateline ); ?></p>
        <?php endif; ?>
        <p class="ptk-sp-intro"><?php echo $square
            ? 'Save the picture to your phone, then copy the words into Instagram, Facebook or WhatsApp. Nothing is posted until you post it.'
            : 'Copy the words below into Instagram, Facebook or WhatsApp. Nothing is posted until you post it.'; ?></p>
    </header>

    <?php if ( $square ) : ?>
        <section class="ptk-sp-section" aria-labelledby="ptk-sp-picture">
            <p class="ptk-sp-rule"><span>§ Share picture</span></p>
            <h2 class="ptk-sp-h2" id="ptk-sp-picture">Save the picture</h2>
            <img class="ptk-sp-square" src="<?php echo esc_url( $square['url'] ); ?>"<?php if ( $square['width'] && $square['height'] ) : ?> width="<?php echo esc_attr( $square['width'] ); ?>" height="<?php echo esc_attr( $square['height'] ); ?>"<?php endif; ?> alt="<?php echo esc_attr( 'Share picture' . ( '' !== $issue ? ', Newsletter № ' . $issue : '' ) ); ?>">
            <p class="ptk-sp-help">Press and hold the picture, then choose <strong>Save to Photos</strong> (on Android, <strong>Download image</strong>). Use it on Instagram, Facebook or WhatsApp.</p>
        </section>
    <?php endif; ?>

    <section class="ptk-sp-section" aria-labelledby="ptk-sp-instagram" data-sp-channel>
        <p class="ptk-sp-rule"><span>§ Instagram</span></p>
        <h2 class="ptk-sp-h2" id="ptk-sp-instagram">Instagram caption</h2>
        <div class="ptk-sp-text" data-sp-text><?php echo esc_html( $instagram['text'] ); ?></div>
        <button type="button" class="ptk-sp-button" data-sp-copy>Copy Instagram caption</button>
        <p class="ptk-sp-status" data-sp-status role="status" aria-live="polite"></p>
    </section>

    <section class="ptk-sp-section" aria-labelledby="ptk-sp-facebook" data-sp-channel>
        <p class="ptk-sp-rule"><span>§ Facebook</span></p>
        <h2 class="ptk-sp-h2" id="ptk-sp-facebook">Facebook post</h2>
        <?php if ( $square ) : ?><p class="ptk-sp-tip">Post the share picture with this text — picture posts get noticed more in groups.</p><?php endif; ?>
        <div class="ptk-sp-text" data-sp-text><?php echo esc_html( $facebook['text'] ); ?></div>
        <button type="button" class="ptk-sp-button" data-sp-copy>Copy Facebook post</button>
        <p class="ptk-sp-status" data-sp-status role="status" aria-live="polite"></p>
    </section>

    <section class="ptk-sp-section" aria-labelledby="ptk-sp-whatsapp" data-sp-channel>
        <p class="ptk-sp-rule"><span>§ WhatsApp</span></p>
        <h2 class="ptk-sp-h2" id="ptk-sp-whatsapp">WhatsApp message</h2>
        <?php if ( $square ) : ?><p class="ptk-sp-tip">Paste the text — WhatsApp shows a preview of the newsletter from the link. Adding the picture is optional.</p><?php endif; ?>
        <div class="ptk-sp-text" data-sp-text><?php echo esc_html( $whatsapp['text'] ); ?></div>
        <div class="ptk-sp-links">
            <button type="button" class="ptk-sp-link" data-sp-copy>Copy WhatsApp message</button>
            <?php if ( '' !== $wa_href ) : ?>
            <a class="ptk-sp-link" href="<?php /* esc_attr, NOT esc_url: esc_url strips %0A and glues the lines together. Built by whatsapp_url(): fixed https://wa.me/ prefix + rawurlencode(). */ echo esc_attr( $wa_href ); ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp →</a>
            <?php endif; ?>
        </div>
        <p class="ptk-sp-status" data-sp-status role="status" aria-live="polite"></p>
    </section>

    <footer class="ptk-sp-foot">
        <?php if ( '' !== $opts['url'] ) : ?>
            <a class="ptk-sp-link" href="<?php echo esc_url( $opts['url'] ); ?>">Read the full newsletter →</a>
        <?php endif; ?>
    </footer>

</main>
<script src="<?php echo esc_url( $js ); ?>"></script>
</body>
</html>
<?php
    }
}
