<?php
/**
 * Newsletter renderer: turns structured block data into newsletter HTML.
 *
 * Mirrors the visual language of the source Northeast newsletter design
 * (masthead, navy announcement strip, hairline-ruled event rows with a
 * relabeling date pill, a navy hero, story cards, and a signoff footer) but
 * parametrized by a palette and driven entirely by block data. Every style
 * is applied inline so the same markup is safe to reuse in a future email
 * renderer. WordPress-free beyond the sanitizing/escaping helpers shimmed in
 * tests/bootstrap.php, so it can be unit-tested with plain php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// issue_label() lives with the share text so every surface pads the issue
// number the same way. require_once resolves real paths, so this and the
// plugin bootstrap's own require never load it twice.
require_once __DIR__ . '/class-share-text.php';

class PTK_Newsletter_Renderer {

    /**
     * Harbor Navy palette — the only theme shipped in the MVP.
     */
    const PALETTE = array(
        'primary'  => '#1a2f5c',
        'accent'   => '#ffd166',
        'emphasis' => '#a51d23',
        'bg'       => '#efece6',
        'surface'  => '#ffffff',
        'text'     => '#111111',
        'muted'    => '#4a4a4a',
        'hairline' => '#e6e3dc',
    );

    /**
     * Display label for each relabel_for_date() bucket.
     */
    const EVENT_LABELS = array(
        'past'      => 'Past',
        'this-week' => 'This week',
        'next-week' => 'Next week',
        'upcoming'  => 'Upcoming',
    );

    const FONT_SANS   = "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
    const FONT_SERIF  = "'Fraunces',Georgia,serif";

    /**
     * Render an ordered array of { type, data } blocks into newsletter HTML.
     *
     * @param array $blocks Ordered block list (see class docblock in
     *                       class-newsletter-data.php for the shape).
     * @param array $opts   Render context: issue, date, today, theme,
     *                       logo_url, school_name, and optionally
     *                       image_url_cb( $image_id ) : string.
     * @return string
     */
    public static function render( array $blocks, array $opts ) {
        $out = '<div style="background:' . esc_attr( self::PALETTE['bg'] ) . ';">';

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || empty( $block['type'] ) ) {
                continue;
            }

            $type   = self::str( $block['type'] );
            $data   = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : array();
            // Dynamic dispatch: only render_<blocktype> methods are reachable
            // targets here. Do NOT name a private helper "render_<something>"
            // unless <something> is meant to be a valid block type.
            $method = 'render_' . $type;

            if ( method_exists( __CLASS__, $method ) && is_callable( array( __CLASS__, $method ) ) ) {
                $out .= self::$method( $data, $opts );
            }
        }

        $out .= '</div>';

        return $out;
    }

    /**
     * Masthead: optional logo, school name, issue/date, and greeting.
     */
    private static function render_header( array $data, array $opts ) {
        $school_name = isset( $data['school_name'] ) ? self::str( $data['school_name'] ) : '';
        $headline    = isset( $data['headline'] ) ? self::str( $data['headline'] ) : '';
        $greeting    = isset( $data['greeting'] ) ? self::str( $data['greeting'] ) : '';
        $issue       = isset( $opts['issue'] ) ? $opts['issue'] : '';
        $date        = isset( $opts['date'] ) ? self::str( $opts['date'] ) : '';
        $logo_url    = isset( $opts['logo_url'] ) ? self::str( $opts['logo_url'] ) : '';

        // Blank headline: auto-derive "Week of {Month} {day}" from the issue
        // date so the masthead works out of the box with zero effort. If the
        // date can't be parsed, omit the H1 entirely rather than print
        // something broken.
        if ( '' === trim( $headline ) ) {
            $headline = ( '' !== trim( $date ) ) ? self::derive_week_of_headline( $date ) : '';
        }

        $date_display = ( '' !== trim( $date ) ) ? self::format_issue_date( $date ) : '';

        $logo_html = '';
        if ( '' !== trim( $logo_url ) ) {
            $logo_html = '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $school_name ) . '" style="width:32px;height:32px;display:block;flex-shrink:0;" />';
        }

        $issue_html = '';
        if ( '' !== trim( self::str( $issue ) ) ) {
            // "№ 042": the house style, and the same label the share page and
            // settings preview use (PTK_Share_Text::issue_label pads to 3).
            $issue_html = '<div style="font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr( self::PALETTE['muted'] ) . ';font-weight:600;margin-bottom:10px;">&#8470;&nbsp;' . esc_html( PTK_Share_Text::issue_label( self::str( $issue ) ) ) . '</div>';
        }

        $headline_html = '';
        if ( '' !== trim( $headline ) ) {
            $headline_html = '<h1 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(30px,7vw,44px);line-height:1.02;letter-spacing:-0.025em;margin:0;color:' . esc_attr( self::PALETTE['text'] ) . ';">' . esc_html( $headline ) . '</h1>';
        }

        $date_html = '';
        if ( '' !== trim( $date_display ) ) {
            $date_html = '<div style="font-weight:600;color:' . esc_attr( self::PALETTE['text'] ) . ';">' . esc_html( $date_display ) . '</div>';
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'header' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:32px 20px;border-bottom:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap;">';
        $html .= $logo_html;
        $html .= '<div style="font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:' . esc_attr( self::PALETTE['primary'] ) . ';font-weight:700;line-height:1.4;">' . esc_html( $school_name ) . '</div>';
        $html .= '</div>';
        $html .= '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;border-top:1px solid ' . esc_attr( self::PALETTE['text'] ) . ';padding-top:20px;">';
        $html .= '<div style="flex:1 1 240px;min-width:240px;">';
        $html .= $issue_html;
        $html .= $headline_html;
        $html .= '</div>';
        $html .= '<div style="font-size:13px;color:' . esc_attr( self::PALETTE['muted'] ) . ';line-height:1.5;">';
        $html .= $date_html;
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<p style="font-size:16px;line-height:1.65;color:' . esc_attr( self::PALETTE['muted'] ) . ';margin:24px 0 0;max-width:620px;">' . wp_kses_post( $greeting ) . '</p>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Full-width navy announcement strip: pill badge + text.
     */
    private static function render_announcement( array $data, array $opts ) {
        $pill = isset( $data['pill'] ) ? self::str( $data['pill'] ) : '';
        $text = isset( $data['text'] ) ? self::str( $data['text'] ) : '';

        // Nothing to announce: emit nothing (no empty navy bar), unless
        // preview mode wants an outlinable placeholder.
        if ( '' === trim( $pill ) && '' === trim( $text ) ) {
            return self::placeholder( 'announcement', 'Your key announcement will appear here.', $opts );
        }

        $pill_html = '';
        if ( '' !== trim( $pill ) ) {
            $pill_html = '<span style="font-size:10px;letter-spacing:0.18em;text-transform:uppercase;font-weight:700;background:rgba(255,255,255,0.18);padding:5px 10px;border-radius:4px;">' . esc_html( $pill ) . '</span>';
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'announcement' ) . '" style="font-family:' . self::FONT_SANS . ';background:' . esc_attr( self::PALETTE['primary'] ) . ';color:#ffffff;padding:16px 20px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">';
        $html .= $pill_html;
        $html .= '<span style="font-size:15px;font-weight:500;line-height:1.5;flex:1 1 240px;min-width:200px;">' . wp_kses_post( $text ) . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Upcoming-events list: date number, title, desc, and a relabeling pill.
     */
    private static function render_events( array $data, array $opts ) {
        $rows  = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
        $today = isset( $opts['today'] ) ? self::str( $opts['today'] ) : '';

        // Keep only rows that are arrays with at least one non-blank field.
        // No content-bearing rows: skip the whole block, heading included.
        $valid_rows = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $date  = isset( $row['date'] ) ? self::str( $row['date'] ) : '';
            $title = isset( $row['title'] ) ? self::str( $row['title'] ) : '';
            $desc  = isset( $row['desc'] ) ? self::str( $row['desc'] ) : '';
            if ( '' === trim( $date ) && '' === trim( $title ) && '' === trim( $desc ) ) {
                continue;
            }
            $valid_rows[] = $row;
        }
        if ( empty( $valid_rows ) ) {
            return self::placeholder( 'events', 'Your upcoming events will appear here.', $opts );
        }
        $rows = $valid_rows;

        $html  = '<div data-ptk-block="' . esc_attr( 'events' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:20px 20px 40px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';
        $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0 0 28px;color:' . esc_attr( self::PALETTE['text'] ) . ';">Upcoming</h2>';

        $count = count( $rows );
        foreach ( $rows as $i => $row ) {
            $date  = isset( $row['date'] ) ? self::str( $row['date'] ) : '';
            $title = isset( $row['title'] ) ? self::str( $row['title'] ) : '';
            $desc  = isset( $row['desc'] ) ? self::str( $row['desc'] ) : '';

            $bucket = ( '' !== $date && class_exists( 'PTK_Newsletter_Data' ) )
                ? PTK_Newsletter_Data::relabel_for_date( $date, $today )
                : 'upcoming';
            $label  = isset( self::EVENT_LABELS[ $bucket ] ) ? self::EVENT_LABELS[ $bucket ] : self::EVENT_LABELS['upcoming'];

            $date_color = ( 'past' === $bucket ) ? self::PALETTE['emphasis'] : self::PALETTE['primary'];
            $date_display = '' !== $date ? self::format_event_date( $date ) : '';

            $top_border    = ( 0 === $i ) ? '1px solid ' . self::PALETTE['text'] : '1px solid ' . self::PALETTE['hairline'];
            $bottom_border = ( $count - 1 === $i ) ? 'border-bottom:1px solid ' . self::PALETTE['hairline'] . ';' : '';

            $html .= '<div style="display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-start;padding:18px 0;border-top:' . esc_attr( $top_border ) . ';' . $bottom_border . '">';
            // 112px fits the widest realistic date, "May 28" (~100px at 30px
            // serif). At 88px two-digit days wrapped: "Sep" / "24". nowrap so
            // a wider font fallback overflows a little instead of breaking.
            $html .= '<div style="flex:0 0 112px;">';
            $html .= '<div style="font-family:' . self::FONT_SERIF . ';font-weight:500;font-size:30px;line-height:0.95;white-space:nowrap;color:' . esc_attr( $date_color ) . ';">' . esc_html( $date_display ) . '</div>';
            $html .= '</div>';
            $html .= '<div style="flex:1 1 220px;min-width:200px;">';
            $html .= '<div style="font-size:17px;font-weight:600;line-height:1.35;margin-bottom:4px;">' . esc_html( $title ) . '</div>';
            $html .= '<div style="font-size:14px;color:' . esc_attr( self::PALETTE['muted'] ) . ';line-height:1.5;">' . wp_kses_post( $desc ) . '</div>';
            $html .= '</div>';
            $html .= '<span data-event-date="' . esc_attr( $date ) . '" data-default="' . esc_attr( $label ) . '" style="font-size:10px;letter-spacing:0.16em;text-transform:uppercase;font-weight:700;color:' . esc_attr( self::PALETTE['primary'] ) . ';background:#f0eee7;padding:5px 9px;border-radius:4px;white-space:nowrap;align-self:flex-start;">' . esc_html( $label ) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Featured hero: navy block with eyebrow, headline, body, optional image.
     */
    private static function render_featured( array $data, array $opts ) {
        $eyebrow  = isset( $data['eyebrow'] ) ? self::str( $data['eyebrow'] ) : '';
        $headline = isset( $data['headline'] ) ? self::str( $data['headline'] ) : '';
        $body     = isset( $data['body'] ) ? self::str( $data['body'] ) : '';
        $image_id = isset( $data['image_id'] ) ? (int) $data['image_id'] : 0;

        $image_html = self::maybe_image( $image_id, $opts, $headline );

        // Nothing to feature (no text and no rendered image): skip the hero.
        if ( '' === trim( $eyebrow ) && '' === trim( $headline ) && '' === trim( $body ) && '' === $image_html ) {
            return self::placeholder( 'featured', 'Your featured story will appear here.', $opts );
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'featured' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['primary'] ) . ';padding:48px 20px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';

        if ( '' !== trim( $eyebrow ) ) {
            $html .= '<div style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-weight:500;font-size:14px;color:' . esc_attr( self::PALETTE['accent'] ) . ';margin-bottom:14px;">' . esc_html( $eyebrow ) . '</div>';
        }

        if ( '' !== trim( $headline ) ) {
            $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(32px,6vw,52px);line-height:1.0;letter-spacing:-0.03em;margin:0 0 18px;color:#ffffff;max-width:720px;">' . esc_html( $headline ) . '</h2>';
        }

        $html .= $image_html;

        if ( '' !== trim( $body ) ) {
            $html .= '<div style="font-size:16px;line-height:1.65;color:#cfd8e3;max-width:660px;">' . wp_kses_post( $body ) . '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Story cards: each a bordered card with heading, body, optional image,
     * and an optional read-more link.
     */
    private static function render_story_cards( array $data, array $opts ) {
        $cards = isset( $data['cards'] ) && is_array( $data['cards'] ) ? $data['cards'] : array();

        // Keep only cards that are arrays with some content: a non-blank
        // heading/body/link, or an image. All-blank cards are dropped so an
        // empty bordered box never renders.
        $valid_cards = array();
        foreach ( $cards as $card ) {
            if ( ! is_array( $card ) ) {
                continue;
            }
            $heading   = isset( $card['heading'] ) ? self::str( $card['heading'] ) : '';
            $body      = isset( $card['body'] ) ? self::str( $card['body'] ) : '';
            $image_id  = isset( $card['image_id'] ) ? (int) $card['image_id'] : 0;
            $link_url  = isset( $card['link_url'] ) ? self::str( $card['link_url'] ) : '';
            $link_text = isset( $card['link_text'] ) ? self::str( $card['link_text'] ) : '';
            if ( '' === trim( $heading ) && '' === trim( $body ) && '' === trim( $link_url ) && '' === trim( $link_text ) && $image_id <= 0 ) {
                continue;
            }
            $valid_cards[] = $card;
        }

        if ( empty( $valid_cards ) ) {
            return self::placeholder( 'story_cards', 'Your story cards will appear here.', $opts );
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'story_cards' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:24px 20px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';

        foreach ( $valid_cards as $card ) {
            $heading   = isset( $card['heading'] ) ? self::str( $card['heading'] ) : '';
            $body      = isset( $card['body'] ) ? self::str( $card['body'] ) : '';
            $image_id  = isset( $card['image_id'] ) ? (int) $card['image_id'] : 0;
            $link_url  = isset( $card['link_url'] ) ? self::str( $card['link_url'] ) : '';
            $link_text = isset( $card['link_text'] ) ? self::str( $card['link_text'] ) : '';

            $image_html = self::maybe_image( $image_id, $opts, $heading );

            $html .= '<div style="border:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';border-radius:14px;padding:36px 28px;background:#f6f4ef;margin-bottom:20px;">';

            if ( '' !== trim( $heading ) ) {
                $html .= '<h3 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(24px,4.5vw,32px);line-height:1.06;letter-spacing:-0.02em;margin:0 0 14px;color:' . esc_attr( self::PALETTE['text'] ) . ';">' . esc_html( $heading ) . '</h3>';
            }

            $html .= $image_html;

            if ( '' !== trim( $body ) ) {
                $html .= '<div style="font-size:16px;line-height:1.65;color:' . esc_attr( self::PALETTE['muted'] ) . ';max-width:620px;">' . wp_kses_post( $body ) . '</div>';
            }

            if ( '' !== trim( $link_url ) && '' !== trim( $link_text ) ) {
                $html .= '<p style="margin:14px 0 0;"><a href="' . esc_url( $link_url ) . '" style="font-size:14px;font-weight:600;color:' . esc_attr( self::PALETTE['primary'] ) . ';text-decoration:none;border-bottom:1px solid ' . esc_attr( self::PALETTE['primary'] ) . ';padding-bottom:1px;">' . esc_html( $link_text ) . '</a></p>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Signoff footer: closing line + a list of links.
     */
    private static function render_footer( array $data, array $opts ) {
        $signoff = isset( $data['signoff'] ) ? self::str( $data['signoff'] ) : '';
        $links   = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array();

        // Collect only links with both a label and a url.
        $valid_links = array();
        foreach ( $links as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            $label = isset( $link['label'] ) ? self::str( $link['label'] ) : '';
            $url   = isset( $link['url'] ) ? self::str( $link['url'] ) : '';
            if ( '' === trim( $label ) || '' === trim( $url ) ) {
                continue;
            }
            $valid_links[] = array( 'label' => $label, 'url' => $url );
        }

        // Nothing to sign off with and no usable links: skip the footer bar.
        if ( '' === trim( $signoff ) && empty( $valid_links ) ) {
            return self::placeholder( 'footer', 'Your sign-off and links will appear here.', $opts );
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'footer' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:40px 20px 32px;border-top:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';

        if ( '' !== trim( $signoff ) ) {
            $html .= '<p style="font-size:17px;line-height:1.65;color:' . esc_attr( self::PALETTE['text'] ) . ';margin:0 0 28px;">' . wp_kses_post( $signoff ) . '</p>';
        }

        if ( ! empty( $valid_links ) ) {
            $html .= '<div style="border-top:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';padding-top:24px;display:flex;flex-wrap:wrap;gap:24px;font-size:13px;color:' . esc_attr( self::PALETTE['muted'] ) . ';line-height:1.55;">';
            foreach ( $valid_links as $link ) {
                $html .= '<div><a href="' . esc_url( $link['url'] ) . '" style="color:' . esc_attr( self::PALETTE['primary'] ) . ';text-decoration:none;border-bottom:1px solid ' . esc_attr( self::PALETTE['primary'] ) . ';word-break:break-word;">' . esc_html( $link['label'] ) . '</a></div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Preview-only stub for a block the user hasn't written yet, so the builder can
     * outline it and show where it will land. NEVER used by the save path — the
     * published newsletter still renders nothing for an empty block.
     */
    private static function placeholder( $type, $label, array $opts ) {
        if ( empty( $opts['preview_placeholders'] ) ) {
            return '';
        }
        return '<div data-ptk-block="' . esc_attr( $type ) . '" style="font-family:' . self::FONT_SANS
            . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';color:#9a9482;padding:26px 20px;'
            . 'box-sizing:border-box;border:2px dashed ' . esc_attr( self::PALETTE['hairline'] ) . ';'
            . 'text-align:center;font-size:14px;">' . esc_html( $label ) . '</div>';
    }

    /**
     * Render an optional attachment image if a URL resolver was supplied via
     * $opts['image_url_cb']( $image_id ) : string. Skipped entirely if no
     * callback is available (pure renderer has no attachment access).
     *
     * @param int    $image_id Attachment ID, 0 to skip.
     * @param array  $opts     Render options, possibly containing image_url_cb.
     * @param string $alt      Alt text fallback.
     * @return string
     */
    private static function maybe_image( $image_id, array $opts, $alt = '' ) {
        if ( $image_id <= 0 ) {
            return '';
        }

        if ( ! isset( $opts['image_url_cb'] ) || ! is_callable( $opts['image_url_cb'] ) ) {
            return '';
        }

        $url = call_user_func( $opts['image_url_cb'], $image_id );
        if ( ! is_string( $url ) || '' === trim( $url ) ) {
            return '';
        }

        return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" style="width:100%;height:auto;display:block;border-radius:10px;margin:0 0 18px;" />';
    }

    /**
     * Coerce a leaf value to a string, guarding against non-scalar (array or
     * object) input that would otherwise warn on cast or FATAL on an object
     * without __toString. Mirrors PTK_Newsletter_Data::str_field() so the
     * renderer is safe to call standalone (e.g. a future email renderer).
     *
     * @param mixed $v Raw value.
     * @return string
     */
    private static function str( $v ) {
        return is_scalar( $v ) ? (string) $v : '';
    }

    /**
     * Format a 'YYYY-MM-DD' date as "Jun 25" for the event date number.
     *
     * @param string $date 'YYYY-MM-DD'.
     * @return string
     */
    private static function format_event_date( $date ) {
        $dt = DateTime::createFromFormat( '!Y-m-d', $date );
        if ( ! $dt ) {
            return $date;
        }
        return $dt->format( 'M j' );
    }

    /**
     * Format a 'YYYY-MM-DD' date as "Thursday, July 16, 2026" for the
     * masthead's friendly issue date line.
     *
     * @param string $date 'YYYY-MM-DD'.
     * @return string Formatted date, or '' if unparseable.
     */
    private static function format_issue_date( $date ) {
        $dt = DateTime::createFromFormat( '!Y-m-d', $date );
        if ( ! $dt ) {
            return '';
        }
        return $dt->format( 'l, F j, Y' );
    }

    /**
     * Derive the default masthead headline "Week of {Month} {day}" from a
     * 'YYYY-MM-DD' issue date, naming that week's Monday.
     *
     * @param string $date 'YYYY-MM-DD'.
     * @return string Derived headline, or '' if the date is unparseable.
     */
    private static function derive_week_of_headline( $date ) {
        // Named for the week's Monday, the way the newsletters are titled:
        // an issue dated Wed Sep 16 is "Week of September 14", and one sent
        // on Sunday Sep 13 is also "Week of September 14".
        $dt = DateTime::createFromFormat( '!Y-m-d', PTK_Newsletter_Data::issue_week_monday( $date ) );
        if ( ! $dt ) {
            return '';
        }
        return 'Week of ' . $dt->format( 'F j' );
    }
}
