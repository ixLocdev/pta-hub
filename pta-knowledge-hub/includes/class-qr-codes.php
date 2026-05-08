<?php
/**
 * Per-entry QR codes.
 *
 * Adds a sidebar meta box on pta_knowledge posts showing a QR code
 * encoding the entry's permalink, plus a "Download PNG" button that
 * streams a 600x600 PNG.
 *
 * Uses the bundled PHPQRCode library at vendor/phpqrcode/phpqrcode.php.
 * The library is loaded lazily — only when the meta box renders or the
 * download endpoint fires.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_QR_Codes {

    const LIB_PATH = 'vendor/phpqrcode/phpqrcode.php';

    public static function init() {
        add_action( 'add_meta_boxes_pta_knowledge', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'admin_post_ptk_download_qr', array( __CLASS__, 'handle_download' ) );
    }

    public static function add_meta_box() {
        add_meta_box(
            'ptk_qr_code',
            'QR Code',
            array( __CLASS__, 'render_meta_box' ),
            'pta_knowledge',
            'side',
            'default'
        );
    }

    /**
     * Render an inline base64-PNG preview plus a "Download PNG" form.
     */
    public static function render_meta_box( $post ) {
        if ( 'publish' !== $post->post_status ) {
            echo '<p class="description">Publish this entry to generate its QR code.</p>';
            return;
        }

        if ( ! self::lib_available() ) {
            echo '<p class="description">QR library missing. Place <code>phpqrcode.php</code> in <code>' . esc_html( self::LIB_PATH ) . '</code>.</p>';
            return;
        }

        $url = get_permalink( $post );
        $img = self::generate_png_data_url( $url, 4, 3 );

        if ( ! $img ) {
            echo '<p class="description">QR generation failed.</p>';
            return;
        }
        ?>
        <p style="text-align:center;margin:8px 0;">
            <img src="<?php echo esc_attr( $img ); ?>" alt="QR code for this entry" style="max-width:160px;width:100%;height:auto;display:inline-block;" />
        </p>
        <p class="description" style="word-break:break-all;font-size:11px;color:#6b7280;"><?php echo esc_html( $url ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="text-align:center;margin-top:8px;">
            <?php wp_nonce_field( 'ptk_download_qr_' . $post->ID, 'ptk_qr_nonce' ); ?>
            <input type="hidden" name="action" value="ptk_download_qr">
            <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>">
            <button type="submit" class="button button-secondary">Download PNG</button>
        </form>
        <p class="description" style="margin-top:8px;font-size:11px;">Tip: print on table tents or flyers so people can scan it on their phones.</p>
        <?php
    }

    /**
     * Stream a 600x600 PNG attachment for the requested post.
     */
    public static function handle_download() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
        }
        if ( ! isset( $_POST['ptk_qr_nonce'] ) || ! wp_verify_nonce( $_POST['ptk_qr_nonce'], 'ptk_download_qr_' . $post_id ) ) {
            wp_die( 'Security check failed.', '', array( 'response' => 403 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'pta_knowledge' !== $post->post_type ) {
            wp_die( 'Entry not found.', '', array( 'response' => 404 ) );
        }
        if ( ! self::lib_available() ) {
            wp_die( 'QR library not installed.', '', array( 'response' => 500 ) );
        }

        require_once PTK_PLUGIN_DIR . self::LIB_PATH;

        $url      = get_permalink( $post );
        $slug     = sanitize_file_name( $post->post_name ? $post->post_name : 'entry-' . $post_id );
        $filename = 'pta-qr-' . $slug . '.png';

        // QRcode::png(text, outfile=false, ec_level=L, size=3, margin=4)
        // size=14 with margin=4 produces ~600x600 for typical content.
        nocache_headers();
        header( 'Content-Type: image/png' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Suppress deprecation notices from the bundled library on PHP 8.1+.
        $prev = error_reporting( E_ERROR | E_PARSE );
        QRcode::png( $url, false, QR_ECLEVEL_M, 14, 4 );
        error_reporting( $prev );

        exit;
    }

    /* -------------------------------------------------------------- */

    private static function lib_available(): bool {
        return file_exists( PTK_PLUGIN_DIR . self::LIB_PATH );
    }

    /**
     * Generate a base64 data URL for a small inline preview PNG.
     */
    private static function generate_png_data_url( string $text, int $margin, int $size ): string {
        require_once PTK_PLUGIN_DIR . self::LIB_PATH;

        ob_start();
        $prev = error_reporting( E_ERROR | E_PARSE );
        QRcode::png( $text, false, QR_ECLEVEL_M, $size, $margin );
        error_reporting( $prev );
        $png = ob_get_clean();

        if ( ! $png ) {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode( $png );
    }
}
