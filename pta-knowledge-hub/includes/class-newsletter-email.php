<?php
/**
 * Turns a newsletter's saved blocks into the GiveBacks email: the "teaser"
 * format Lucas approved 2026-09-13 (see NEPTANewsletter/EMAIL-TEMPLATE-GUIDE.md
 * and email-pta-newsletter-040.html, THE reference this class follows almost
 * line for line) — the navy announcement as the one callout, "§ Inside this
 * issue" linked rows (one per section/story/dates group, driven by
 * PTK_Newsletter_Renderer::last_sections() so the anchors can never drift
 * from the ones actually rendered onto the published page), and ONE button
 * to the newsletter's permalink. No images anywhere.
 *
 * Round 7 ("Copy email for GiveBacks"). Pure PHP, WordPress-free beyond the
 * sanitizing/escaping shims in tests/bootstrap.php — same contract as
 * PTK_Newsletter_Renderer, so it can be unit-tested with plain php and so a
 * future non-WP reuse (e.g. a CLI export) costs nothing extra.
 *
 * The generated markup is a SINGLE self-contained HTML document (DOCTYPE,
 * head, body) meant to be pasted whole into GiveBacks' Unlayer "HTML" block
 * (see the Publish & share panel's instructions) — not a fragment.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-newsletter-renderer.php';
require_once __DIR__ . '/class-newsletter-data.php';
require_once __DIR__ . '/class-share-text.php';

class PTK_Newsletter_Email {

    const FONT_SANS  = "'Libre Franklin', Helvetica, Arial, sans-serif";
    const FONT_SERIF = "Newsreader, Georgia, 'Times New Roman', serif";

    const COLOR_NAVY     = '#1a2f5c';
    const COLOR_INK      = '#111111';
    const COLOR_BODY     = '#4a4a4a';
    const COLOR_MUTED    = '#6b6b6b';
    const COLOR_HAIRLINE = '#e6e3dc';
    const COLOR_YELLOW   = '#ffd166';
    const COLOR_ON_NAVY  = '#cfd8e3';

    /**
     * Build the full GiveBacks-ready email HTML.
     *
     * @param array $blocks Sanitized newsletter blocks (PTK_Newsletter_Data::sanitize_blocks()).
     * @param array $opts   {
     *     @type int|string $issue         Issue number.
     *     @type string      $date          Issue date 'YYYY-MM-DD'.
     *     @type string      $today         'YYYY-MM-DD', for section teasers that depend on "today" (none currently do).
     *     @type string      $school_name   School/PTA name for the masthead and sign-off.
     *     @type string      $permalink     The published newsletter's URL. Required — every link in the
     *                                       email is built from it (button + every "Inside this issue" row).
     *     @type string      $site_url      Home URL, for the masthead name's link and the footer domain line.
     *     @type string      $news_url      Settings "Send us your news" link (mailto: or a form URL), optional.
     *     @type string      $contact_email Settings contact email, optional.
     * }
     * @return string Full HTML document, or '' if $opts['permalink'] is blank
     *                 (the email is meaningless without a working link — the
     *                 UI is responsible for gating this behind "published").
     */
    public static function generate( array $blocks, array $opts ) {
        $permalink = isset( $opts['permalink'] ) ? trim( (string) $opts['permalink'] ) : '';
        if ( '' === $permalink ) {
            return '';
        }

        $school_name = isset( $opts['school_name'] ) ? (string) $opts['school_name'] : '';
        $site_url    = isset( $opts['site_url'] ) ? (string) $opts['site_url'] : '';
        $issue       = isset( $opts['issue'] ) ? $opts['issue'] : '';
        $date        = isset( $opts['date'] ) ? (string) $opts['date'] : '';
        $news_url    = isset( $opts['news_url'] ) ? (string) $opts['news_url'] : '';
        $contact     = isset( $opts['contact_email'] ) ? (string) $opts['contact_email'] : '';

        $announcement = self::find_block( $blocks, PTK_Newsletter_Data::TYPE_ANNOUNCEMENT );
        $lead         = self::lead_headline( $blocks, $date );

        // Render the full newsletter once — never to show its HTML, only to
        // collect last_sections() (see that method's docblock): the exact
        // same anchor ids and content rules the published page will use.
        PTK_Newsletter_Renderer::render( $blocks, array(
            'issue'         => $issue,
            'date'          => $date,
            'today'         => isset( $opts['today'] ) ? $opts['today'] : $date,
            'news_url'      => $news_url,
            'contact_email' => $contact,
        ) );
        $sections = PTK_Newsletter_Renderer::last_sections();

        $preheader = self::preheader( $announcement, $sections );

        $html  = self::doc_open( $issue, $date );
        $html .= self::hidden_preheader( $preheader );
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#ffffff;"><tr><td align="center" style="padding:0;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px; width:100%;">';

        $html .= self::masthead( $school_name, $site_url, $issue );
        $html .= self::headline_block( $school_name, $date, $lead );

        if ( $announcement ) {
            $html .= self::callout( $announcement );
        }

        if ( ! empty( $sections ) ) {
            $html .= self::inside_this_issue( $sections, $permalink );
        }

        $html .= self::button( $permalink );
        $html .= self::footer( $school_name, $site_url, $news_url, $contact );

        $html .= '</table>';
        $html .= '</td></tr></table>';
        $html .= self::doc_close();

        return $html;
    }

    /**
     * A plain Subject line suggestion: the announcement's headline, else the
     * top story's headline, else "Week of {Month Day}". Never empty when a
     * date is present.
     *
     * @param array $blocks
     * @param array $opts { issue, date }
     * @return string
     */
    public static function subject( array $blocks, array $opts ) {
        $date  = isset( $opts['date'] ) ? (string) $opts['date'] : '';
        $issue = isset( $opts['issue'] ) ? $opts['issue'] : '';
        $lead  = self::lead_headline( $blocks, $date );

        $prefix = '' !== trim( (string) $issue )
            ? 'PTA Newsletter № ' . PTK_Share_Text::issue_label( $issue ) . ' — '
            : 'PTA Newsletter — ';

        return $prefix . $lead;
    }

    /* ------------------------------------------------------------------
     * Content lookups
     * ------------------------------------------------------------------ */

    /**
     * The first block of a given type, or null.
     *
     * @param array  $blocks
     * @param string $type
     * @return array|null
     */
    private static function find_block( array $blocks, $type ) {
        foreach ( $blocks as $block ) {
            if ( is_array( $block ) && isset( $block['type'] ) && $type === $block['type'] ) {
                $data = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : array();
                // An announcement block with nothing typed into it yet isn't
                // "present" for email purposes — same emptiness rule the
                // renderer uses before it will show the navy callout.
                if ( PTK_Newsletter_Data::TYPE_ANNOUNCEMENT === $type ) {
                    $has_content = '' !== trim( self::v( $data, 'when' ) )
                        || '' !== trim( self::v( $data, 'headline' ) )
                        || '' !== trim( self::v( $data, 'text' ) )
                        || ( '' !== trim( self::v( $data, 'button_text' ) ) && '' !== trim( self::v( $data, 'button_url' ) ) );
                    if ( ! $has_content ) {
                        return null;
                    }
                }
                return $data;
            }
        }
        return null;
    }

    /**
     * The email's headline: the announcement's headline (its own text
     * becomes its headline the same way the renderer treats a 4.1.x
     * announcement — see class-newsletter-renderer.php render_announcement()),
     * else the top story's headline, else "Week of {Month Day}".
     *
     * @param array  $blocks
     * @param string $date
     * @return string
     */
    private static function lead_headline( array $blocks, $date ) {
        $announcement = self::find_block( $blocks, PTK_Newsletter_Data::TYPE_ANNOUNCEMENT );
        if ( $announcement ) {
            $headline = trim( self::v( $announcement, 'headline' ) );
            if ( '' === $headline ) {
                $headline = trim( html_entity_decode( wp_strip_all_tags( self::v( $announcement, 'text' ) ), ENT_QUOTES, 'UTF-8' ) );
            }
            if ( '' !== $headline ) {
                return $headline;
            }
        }

        $featured = self::find_block( $blocks, PTK_Newsletter_Data::TYPE_FEATURED );
        if ( $featured && '' !== trim( self::v( $featured, 'headline' ) ) ) {
            return trim( self::v( $featured, 'headline' ) );
        }

        if ( '' !== trim( (string) $date ) ) {
            $dt = DateTime::createFromFormat( '!Y-m-d', PTK_Newsletter_Data::issue_week_monday( $date ) );
            if ( $dt ) {
                return 'Week of ' . $dt->format( 'F j' );
            }
        }

        return 'This week&#8217;s newsletter';
    }

    /**
     * The hidden preheader line: the announcement's "when" + a plain-text
     * clip of its details, else a comma list of the first few section
     * headlines.
     *
     * @param array|null $announcement
     * @param array      $sections
     * @return string
     */
    private static function preheader( $announcement, array $sections ) {
        if ( $announcement ) {
            $bits = array_filter( array(
                trim( self::v( $announcement, 'when' ) ),
                trim( html_entity_decode( wp_strip_all_tags( self::v( $announcement, 'text' ) ), ENT_QUOTES, 'UTF-8' ) ),
            ) );
            if ( ! empty( $bits ) ) {
                return implode( '. ', $bits );
            }
        }
        $heads = array();
        foreach ( array_slice( $sections, 0, 3 ) as $s ) {
            $heads[] = $s['headline'];
        }
        return implode( ', ', $heads );
    }

    /**
     * A field from a block's data array, coerced to a string.
     *
     * @param array  $data
     * @param string $key
     * @return string
     */
    private static function v( array $data, $key ) {
        return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
    }

    /* ------------------------------------------------------------------
     * Rich text (same house-style treatment as the renderer's rich(), kept
     * as its own small copy so this class stays independently reusable —
     * see the class docblock).
     * ------------------------------------------------------------------ */

    /**
     * @param string $html
     * @param bool   $on_navy
     * @return string
     */
    private static function rich( $html, $on_navy = false ) {
        $html   = wp_kses_post( $html );
        $link   = $on_navy
            ? 'color:#ffffff;text-decoration:underline;'
            : 'color:' . self::COLOR_NAVY . ';text-decoration:underline;';
        $strong = 'color:' . ( $on_navy ? '#ffffff' : self::COLOR_INK ) . ';';
        $html   = preg_replace( '/<a(\s+href="[^"]*")\s*>/i', '<a$1 target="_blank" style="' . $link . '">', $html );
        $html   = preg_replace( '/<strong>/i', '<strong style="' . $strong . '">', $html );
        return $html;
    }

    /* ------------------------------------------------------------------
     * Document shell
     * ------------------------------------------------------------------ */

    private static function doc_open( $issue, $date ) {
        $title = 'PTA Newsletter';
        if ( '' !== trim( (string) $issue ) ) {
            $title .= ' #' . PTK_Share_Text::issue_label( $issue );
        }
        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en"><head>' . "\n"
            . '<meta charset="UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge">' . "\n"
            . '<meta name="x-apple-disable-message-reformatting">' . "\n"
            . '<meta name="color-scheme" content="light">' . "\n"
            . '<meta name="supported-color-schemes" content="light">' . "\n"
            . '<title>' . esc_html( $title ) . '</title>' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap" rel="stylesheet">' . "\n"
            . '<style>' . "\n"
            . 'body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }' . "\n"
            . 'body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #ffffff; }' . "\n"
            . 'table { border-collapse: collapse; }' . "\n"
            . 'a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }' . "\n"
            . 'u + #body a { color: inherit; text-decoration: none; }' . "\n"
            . '@media only screen and (max-width: 520px) {' . "\n"
            . '  .px { padding-left: 20px !important; padding-right: 20px !important; }' . "\n"
            . '  .h1 { font-size: 30px !important; line-height: 32px !important; }' . "\n"
            . '  .btn, .btn a { display: block !important; text-align: center !important; }' . "\n"
            . '}' . "\n"
            . '</style>' . "\n"
            . '</head><body id="body" style="margin:0; padding:0; background-color:#ffffff;">';
    }

    private static function doc_close() {
        return '</body></html>';
    }

    private static function hidden_preheader( $text ) {
        return '<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#ffffff;">'
            . esc_html( $text )
            . '</div>';
    }

    /* ------------------------------------------------------------------
     * Blocks
     * ------------------------------------------------------------------ */

    private static function masthead( $school_name, $site_url, $issue ) {
        $name_html = esc_html( $school_name );
        if ( '' !== trim( $site_url ) ) {
            $name_html = '<a href="' . esc_url( $site_url ) . '" target="_blank" style="color:' . self::COLOR_INK . '; text-decoration:none;">' . $name_html . '</a>';
        }
        $issue_html = '' !== trim( (string) $issue )
            ? 'Newsletter &#8470;&nbsp;' . esc_html( PTK_Share_Text::issue_label( $issue ) )
            : '';

        $html  = '<tr><td class="px" style="padding:28px 24px 12px;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>';
        $html .= '<td style="font-family:' . self::FONT_SANS . '; font-size:17px; line-height:22px; font-weight:800; letter-spacing:-0.01em; color:' . self::COLOR_INK . ';">' . $name_html . '</td>';
        $html .= '<td align="right" style="font-family:' . self::FONT_SERIF . '; font-style:italic; font-size:15px; line-height:22px; font-weight:500; color:' . self::COLOR_NAVY . '; white-space:nowrap;">' . $issue_html . '</td>';
        $html .= '</tr></table></td></tr>';
        $html .= '<tr><td class="px" style="padding:0 24px;"><div style="height:1px; line-height:1px; font-size:1px; background-color:' . self::COLOR_INK . ';">&nbsp;</div></td></tr>';

        return $html;
    }

    private static function headline_block( $school_name, $date, $lead ) {
        $dateline = '';
        if ( '' !== trim( (string) $date ) ) {
            $dt = DateTime::createFromFormat( '!Y-m-d', PTK_Newsletter_Data::issue_week_monday( $date ) );
            if ( $dt ) {
                $dateline = 'Week of ' . $dt->format( 'F j, Y' );
            }
        }

        $html  = '<tr><td class="px" style="padding:28px 24px 0;">';
        if ( '' !== $dateline ) {
            $html .= '<p style="margin:0 0 8px; font-family:' . self::FONT_SERIF . '; font-style:italic; font-size:15px; line-height:20px; font-weight:500; color:' . self::COLOR_NAVY . ';">' . esc_html( $dateline ) . '</p>';
        }
        $html .= '<h1 class="h1" style="margin:0 0 16px; font-family:' . self::FONT_SANS . '; font-size:36px; line-height:38px; font-weight:800; letter-spacing:-0.025em; color:' . self::COLOR_INK . ';">' . esc_html( $lead ) . '</h1>';
        $html .= '<p style="margin:0 0 14px; font-family:' . self::FONT_SANS . '; font-size:16px; line-height:26px; color:' . self::COLOR_BODY . ';">Hi ' . esc_html( $school_name ) . ' Families,</p>';
        $html .= '<p style="margin:0; font-family:' . self::FONT_SANS . '; font-size:16px; line-height:26px; color:' . self::COLOR_BODY . ';">This week&#8217;s newsletter is on the website. Here&#8217;s what&#8217;s inside.</p>';
        $html .= '</td></tr>';

        return $html;
    }

    private static function callout( array $announcement ) {
        $when     = trim( self::v( $announcement, 'when' ) );
        $headline = trim( self::v( $announcement, 'headline' ) );
        $text     = trim( self::v( $announcement, 'text' ) );
        $btn_text = trim( self::v( $announcement, 'button_text' ) );
        $btn_url  = trim( self::v( $announcement, 'button_url' ) );

        // A 4.1.x announcement (text only, no headline) — same rule the
        // renderer applies: the text becomes the headline.
        if ( '' === $headline && '' !== $text ) {
            $headline = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
            $text     = '';
        }

        $html  = '<tr><td class="px" style="padding:28px 24px 0;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>';
        $html .= '<td style="background-color:' . self::COLOR_NAVY . '; border-radius:10px; padding:24px 24px 22px;">';
        if ( '' !== $when ) {
            $html .= '<p style="margin:0 0 8px; font-family:' . self::FONT_SANS . '; font-size:11px; line-height:14px; font-weight:700; letter-spacing:0.16em; text-transform:uppercase; color:' . self::COLOR_YELLOW . ';">' . esc_html( $when ) . '</p>';
        }
        if ( '' !== $headline ) {
            $html .= '<p style="margin:0 0 10px; font-family:' . self::FONT_SANS . '; font-size:24px; line-height:28px; font-weight:800; letter-spacing:-0.02em; color:#ffffff;">' . esc_html( $headline ) . '</p>';
        }
        if ( '' !== $text ) {
            $html .= '<p style="margin:0 0 14px; font-family:' . self::FONT_SANS . '; font-size:15px; line-height:24px; color:' . self::COLOR_ON_NAVY . ';">' . self::rich( $text, true ) . '</p>';
        }
        if ( '' !== $btn_text && '' !== trim( esc_url( $btn_url ) ) ) {
            $html .= '<p style="margin:0; font-family:' . self::FONT_SANS . '; font-size:15px; line-height:22px; font-weight:700;"><a href="' . esc_url( $btn_url ) . '" target="_blank" style="color:#ffffff; text-decoration:underline;">' . esc_html( $btn_text ) . '</a></p>';
        }
        $html .= '</td></tr></table></td></tr>';

        return $html;
    }

    /**
     * @param array  $sections  From PTK_Newsletter_Renderer::last_sections().
     * @param string $permalink
     * @return string
     */
    private static function inside_this_issue( array $sections, $permalink ) {
        $html  = '<tr><td class="px" style="padding:40px 24px 0;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>';
        $html .= '<td style="white-space:nowrap; padding-right:14px; font-family:' . self::FONT_SERIF . '; font-style:italic; font-size:15px; line-height:20px; font-weight:500; color:' . self::COLOR_NAVY . ';">&sect;&nbsp;Inside this issue</td>';
        $html .= '<td width="100%" valign="middle"><div style="height:1px; line-height:1px; font-size:1px; background-color:' . self::COLOR_INK . ';">&nbsp;</div></td>';
        $html .= '</tr></table>';

        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">';
        foreach ( $sections as $section ) {
            $url = rtrim( $permalink, '/' ) . '/#' . $section['id'];
            $html .= '<tr><td style="padding:14px 0; border-bottom:1px solid ' . self::COLOR_HAIRLINE . '; font-family:' . self::FONT_SANS . '; font-size:15px; line-height:24px; color:' . self::COLOR_BODY . ';">';
            $html .= '<a href="' . esc_url( $url ) . '" target="_blank" style="display:block; font-size:16px; line-height:22px; font-weight:700; color:' . self::COLOR_INK . '; text-decoration:none;">' . esc_html( $section['headline'] ) . '</a>';
            if ( '' !== $section['teaser'] ) {
                $html .= esc_html( $section['teaser'] );
            }
            $html .= '</td></tr>';
        }
        $html .= '</table></td></tr>';

        return $html;
    }

    private static function button( $permalink ) {
        $html  = '<tr><td class="px" style="padding:36px 24px 0;">';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" class="btn"><tr>';
        $html .= '<td style="background-color:' . self::COLOR_NAVY . '; border-radius:8px;">';
        $html .= '<a href="' . esc_url( $permalink ) . '" target="_blank" style="display:inline-block; padding:15px 26px; font-family:' . self::FONT_SANS . '; font-size:15px; line-height:18px; font-weight:700; letter-spacing:0.02em; color:#ffffff; text-decoration:none; border-radius:8px;">Read the full newsletter &rarr;</a>';
        $html .= '</td></tr></table></td></tr>';

        return $html;
    }

    private static function footer( $school_name, $site_url, $news_url, $contact_email ) {
        $html  = '<tr><td class="px" style="padding:40px 24px 0;">';
        $html .= '<p style="margin:0; font-family:' . self::FONT_SANS . '; font-size:16px; line-height:24px; color:' . self::COLOR_BODY . ';">Warmly,</p>';
        $html .= '<p style="margin:0; font-family:' . self::FONT_SANS . '; font-size:16px; line-height:24px; font-weight:700; color:' . self::COLOR_INK . ';">' . esc_html( $school_name ) . '</p>';
        $html .= '</td></tr>';

        $html .= '<tr><td class="px" style="padding:28px 24px 36px;">';
        $html .= '<div style="height:1px; line-height:1px; font-size:1px; background-color:' . self::COLOR_HAIRLINE . ';">&nbsp;</div>';

        if ( '' !== trim( (string) $news_url ) ) {
            $news_email = 0 === stripos( $news_url, 'mailto:' ) ? strtok( substr( $news_url, 7 ), '?' ) : '';
            $label      = '' !== $news_email ? 'Email your news to ' . $news_email : 'Submit it here';
            $html      .= '<p style="margin:14px 0 0; font-family:' . self::FONT_SANS . '; font-size:13px; line-height:20px; color:' . self::COLOR_MUTED . ';">Have news for the newsletter? <a href="' . esc_url( $news_url ) . '" target="_blank" style="color:' . self::COLOR_NAVY . '; text-decoration:underline;">' . esc_html( $label ) . '</a>.</p>';
        } elseif ( '' !== trim( (string) $contact_email ) ) {
            $html .= '<p style="margin:14px 0 0; font-family:' . self::FONT_SANS . '; font-size:13px; line-height:20px; color:' . self::COLOR_MUTED . ';">Have news for the newsletter? Email <a href="' . esc_url( 'mailto:' . $contact_email ) . '" style="color:' . self::COLOR_NAVY . '; text-decoration:underline;">' . esc_html( $contact_email ) . '</a>.</p>';
        }

        $domain_line = esc_html( $school_name );
        if ( '' !== trim( (string) $site_url ) ) {
            $domain = preg_replace( '#^https?://(www\.)?#i', '', rtrim( $site_url, '/' ) );
            $domain_line .= ' &middot; <a href="' . esc_url( $site_url ) . '" target="_blank" style="color:' . self::COLOR_NAVY . '; text-decoration:underline;">' . esc_html( $domain ) . '</a>';
        }
        $html .= '<p style="margin:6px 0 0; font-family:' . self::FONT_SANS . '; font-size:13px; line-height:20px; color:' . self::COLOR_MUTED . ';">' . $domain_line . '</p>';
        $html .= '</td></tr>';

        return $html;
    }
}
