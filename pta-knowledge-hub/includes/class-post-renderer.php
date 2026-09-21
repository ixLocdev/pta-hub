<?php
/**
 * Turns a post's parts (see class-post-parts.php) into finished HTML with
 * inline styles, in the house look -- rules, not boxes; at most one navy
 * callout; one primary button; everything else a plain text link.
 *
 * This HTML is saved straight into post_content, so it has to survive any
 * school's theme -- no external stylesheet, every style inline.
 *
 * Pure: no WordPress functions. Escaping is done by hand with
 * htmlspecialchars(), never esc_html()/esc_url()/wp_kses().
 *
 * Plain English throughout -- "headline", "picture", "steps", not "title",
 * "attachment", "procedure".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Post_Renderer {

    const NAVY   = '#1a2f5c';
    const INK    = '#111111';
    const BODY   = '#4a4a4a';
    const MUTED  = '#6b6b6b';
    const RULE   = '#e6e3dc';
    const RED    = '#a51d23';
    const YELLOW = '#ffd166';
    const ONNAVY = '#cfd8e3';

    const FONT_SANS   = "'Libre Franklin',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
    const FONT_SERIF  = "Newsreader,Georgia,serif";

    /**
     * Render a post's parts into HTML.
     *
     * @param array  $parts   Sanitized parts (see PTK_Post_Parts::sanitize()).
     * @param string $signoff A school-wide sign-off sentence, e.g.
     *                        "Thank you, as always,\nYour Northeast PTA".
     *                        Rendered only when non-empty; a blank signoff
     *                        renders nothing at all.
     * @param array  $picture Picture data: array( 'url' => …, 'alt' => …,
     *                        'fit' => 'crop'|'whole', 'position' => '50% 50%',
     *                        'zoom' => '' ). Empty or missing picture renders
     *                        no <figure>.
     * @return string
     */
    public static function render( array $parts, $signoff = '', array $picture = array() ) {
        $parts = array_merge(
            array(
                'kicker'     => '',
                'headline'   => '',
                'words'      => '',
                'image_id'   => 0,
                'date_label' => '',
                'date_note'  => '',
                'steps'      => array(),
                'link_url'   => '',
                'link_text'  => '',
            ),
            $parts
        );

        $kicker     = self::str( $parts['kicker'] );
        $headline   = self::str( $parts['headline'] );
        $words      = self::str( $parts['words'] );
        $date_label = self::str( $parts['date_label'] );
        $date_note  = self::str( $parts['date_note'] );
        $steps      = is_array( $parts['steps'] ) ? $parts['steps'] : array();
        $link_url   = self::clean_url( $parts['link_url'] );
        $link_text  = self::str( $parts['link_text'] );
        $signoff    = self::str( $signoff );

        $html = '';

        if ( '' !== $kicker ) {
            $html .= '<p style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-weight:500;font-size:15px;color:' . self::NAVY . ';margin:0 0 6px;">' . self::esc( $kicker ) . '</p>';
        }

        if ( '' !== $headline ) {
            $html .= '<h1 style="font-family:' . self::FONT_SANS . ';font-size:44px;font-weight:800;line-height:1.02;letter-spacing:-0.025em;color:' . self::INK . ';margin:0;">' . self::esc( $headline ) . '</h1>';
            $html .= '<div style="height:3px;width:56px;background:' . self::RED . ';margin:14px 0 22px;"></div>';
        }

        // Render picture if provided.
        $picture_url = self::clean_url( isset( $picture['url'] ) ? $picture['url'] : '' );
        if ( '' !== $picture_url ) {
            $picture_alt = self::str( isset( $picture['alt'] ) ? $picture['alt'] : '' );
            $picture_fit = self::str( isset( $picture['fit'] ) ? $picture['fit'] : 'crop' );
            $picture_position = self::str( isset( $picture['position'] ) ? $picture['position'] : '50% 50%' );
            $picture_zoom = self::str( isset( $picture['zoom'] ) ? $picture['zoom'] : '' );

            $img_style = 'display:block;width:100%;height:260px;border-radius:10px;';

            if ( 'whole' === $picture_fit ) {
                $img_style .= 'object-fit:contain;';
            } else {
                $img_style .= 'object-fit:cover;object-position:' . self::esc( $picture_position ) . ';';
                if ( '' !== $picture_zoom ) {
                    $img_style .= $picture_zoom;
                }
            }

            $html .= '<figure style="margin:0 0 26px;">';
            $html .= '<img alt="' . self::esc( $picture_alt ) . '" src="' . self::esc( $picture_url ) . '" style="' . $img_style . '">';
            $html .= '</figure>';
        }

        if ( '' !== $words ) {
            $paragraphs = preg_split( '/\n\s*\n/', $words );
            foreach ( $paragraphs as $paragraph ) {
                $paragraph = trim( $paragraph );
                if ( '' === $paragraph ) {
                    continue;
                }
                $html .= '<p style="font-family:' . self::FONT_SANS . ';font-size:17px;line-height:1.65;color:' . self::BODY . ';margin:0 0 16px;max-width:63ch;">' . nl2br( self::esc( $paragraph ) ) . '</p>';
            }
        }

        if ( '' !== $date_label || '' !== $date_note ) {
            $html .= '<div style="background:' . self::NAVY . ';border-radius:10px;padding:22px 24px;margin:0 0 26px;">';
            if ( '' !== $date_label ) {
                $html .= '<div style="font-family:' . self::FONT_SANS . ';font-size:11px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:' . self::YELLOW . ';">' . self::esc( $date_label ) . '</div>';
            }
            if ( '' !== $date_note ) {
                $html .= '<p style="font-family:' . self::FONT_SANS . ';color:' . self::ONNAVY . ';font-size:15px;margin:6px 0 0;max-width:56ch;">' . self::esc( $date_note ) . '</p>';
            }
            $html .= '</div>';
        }

        $clean_steps = array();
        foreach ( $steps as $step ) {
            $step    = (array) $step;
            $heading = isset( $step['heading'] ) ? self::str( $step['heading'] ) : '';
            $body    = isset( $step['body'] ) ? self::str( $step['body'] ) : '';
            if ( '' === $heading && '' === $body ) {
                continue;
            }
            $clean_steps[] = array(
                'heading' => $heading,
                'body'    => $body,
            );
        }

        if ( ! empty( $clean_steps ) ) {
            $html .= '<div style="display:flex;align-items:center;gap:12px;margin:38px 0 14px;">';
            $html .= '<span style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-weight:500;font-size:15px;color:' . self::NAVY . ';white-space:nowrap;">&sect; How it works</span>';
            $html .= '<i style="display:block;flex:1;height:1px;background:' . self::INK . ';"></i>';
            $html .= '</div>';

            foreach ( $clean_steps as $i => $step ) {
                $border = ( 0 === $i ) ? '' : 'border-top:1px solid ' . self::RULE . ';';
                $html  .= '<div style="display:flex;gap:16px;padding:16px 0;' . $border . '">';
                $html  .= '<div style="font-family:' . self::FONT_SERIF . ';font-weight:500;font-size:30px;color:' . self::NAVY . ';line-height:1;min-width:34px;">' . ( $i + 1 ) . '</div>';
                $html  .= '<div>';
                if ( '' !== $step['heading'] ) {
                    $html .= '<h3 style="font-family:' . self::FONT_SANS . ';font-size:19px;font-weight:700;line-height:1.3;color:' . self::INK . ';margin:0 0 4px;">' . self::esc( $step['heading'] ) . '</h3>';
                }
                if ( '' !== $step['body'] ) {
                    $html .= '<p style="font-family:' . self::FONT_SANS . ';font-size:16px;line-height:1.65;color:' . self::BODY . ';margin:6px 0 0;">' . self::esc( $step['body'] ) . '</p>';
                }
                $html .= '</div>';
                $html .= '</div>';
            }
        }

        if ( '' !== $link_url && '' !== $link_text ) {
            $html .= '<a href="' . self::esc( $link_url ) . '" style="display:inline-block;background:' . self::NAVY . ';color:#ffffff;text-decoration:none;font-family:' . self::FONT_SANS . ';font-size:14px;font-weight:700;letter-spacing:0.02em;padding:14px 24px;border-radius:8px;margin-top:30px;">' . self::esc( $link_text ) . '</a>';
        }

        if ( '' !== $signoff ) {
            $html .= '<p style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-size:16px;color:' . self::NAVY . ';margin-top:30px;">' . nl2br( self::esc( $signoff ) ) . '</p>';
        }

        return $html;
    }

    /**
     * Coerce a value to a trimmed string.
     *
     * @param mixed $s
     * @return string
     */
    private static function str( $s ) {
        return trim( (string) $s );
    }

    /**
     * Escape text for HTML output by hand -- no WordPress functions here.
     *
     * @param string $s
     * @return string
     */
    private static function esc( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Refuse anything that isn't http://, https://, mailto:, or a local path.
     * Belt-and-braces: PTK_Post_Parts::sanitize() already does this, but the
     * renderer never trusts its input to have been cleaned upstream.
     *
     * @param string $url
     * @return string
     */
    private static function clean_url( $url ) {
        $url = self::str( $url );

        if ( '' === $url ) {
            return '';
        }

        if ( 0 === strpos( $url, '//' ) ) {
            return '';
        }

        if ( 0 === strpos( $url, '/' ) ) {
            return $url;
        }

        if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $m ) ) {
            $allowed = array( 'http', 'https', 'mailto' );
            if ( ! in_array( strtolower( $m[1] ), $allowed, true ) ) {
                return '';
            }
            return $url;
        }

        return '';
    }

    /**
     * Hash the rendered HTML with sha1. Used to detect when a post has been
     * modified outside the Hub (e.g., directly in WordPress).
     *
     * @param string $html
     * @return string A 40-character lowercase hex string.
     */
    public static function hash( $html ) {
        return sha1( (string) $html );
    }
}
