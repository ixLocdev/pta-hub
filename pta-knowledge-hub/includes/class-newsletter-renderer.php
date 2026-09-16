<?php
/**
 * Newsletter renderer: turns structured block data into newsletter HTML.
 *
 * Mirrors the Northeast house style as issue № 040 set it (masthead with
 * the full issue line, ONE navy announcement callout with a when line,
 * dates and a button, "§ label" sections on white for the top story,
 * stories and quick notes, hairline-ruled event rows with a relabeling date
 * tag, and a signoff footer), driven entirely by block data. Every style is
 * applied inline so the same markup is safe to reuse in a future email
 * renderer. WordPress-free beyond the sanitizing/escaping helpers shimmed in
 * tests/bootstrap.php, so it can be unit-tested with plain php.
 *
 * Depends on PTK_Newsletter_Data (dates, school year, timeline states): the
 * renderer is not usable without it, and does not pretend otherwise.
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
        'on_navy'  => '#cfd8e3',
        'small'    => '#6b6b6b',
        'chip'     => '#f0eee7',
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

    const FONT_SANS   = "'Libre Franklin',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
    const FONT_SERIF  = "Newsreader,Georgia,serif";

    /** Lining, even-width figures for every date numeral (house style "Type"). */
    const NUMERALS    = 'font-variant-numeric:lining-nums tabular-nums;';

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

            $type = self::str( $block['type'] );

            // Round 2: the "Got news?" closing is settings-driven, not a
            // stored block, so it has no data of its own — it is inserted
            // immediately before the footer, here in the dispatch loop
            // itself, rather than as a render_<type> method (see
            // render_news_cta()'s docblock for why it is never reachable
            // via the dynamic dispatch below).
            if ( PTK_Newsletter_Data::TYPE_FOOTER === $type && '' !== trim( self::str( isset( $opts['news_url'] ) ? $opts['news_url'] : '' ) ) ) {
                $out .= self::render_news_cta( $opts );
            }

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
        $summary     = isset( $data['summary'] ) ? self::str( $data['summary'] ) : '';
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

        // Computed once, used both by the issue line below and by the Join
        // link — never parse $date into a school year twice.
        $year = PTK_Newsletter_Data::school_year_label( $date );

        // Round 2: "Join the PTA for {school year} →", right side of the
        // masthead's first row. When $year is '' (unparseable date), the
        // link still renders with just "Join the PTA →" — no dangling
        // " for " with nothing after it, mirroring how the issue line
        // above omits its own "· {year}" fragment when blank.
        $join_url  = isset( $opts['join_url'] ) ? self::str( $opts['join_url'] ) : '';
        $join_html = '';
        if ( '' !== trim( $join_url ) ) {
            $join_text = 'Join the PTA' . ( '' !== $year ? ' for ' . $year : '' ) . ' →';
            $join_html = '<a href="' . esc_url( $join_url ) . '" style="font-size:13px;font-weight:700;color:' . esc_attr( self::PALETTE['primary'] ) . ';text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;white-space:nowrap;">' . esc_html( $join_text ) . '</a>';
        }

        $issue_html = '';
        if ( '' !== trim( self::str( $issue ) ) ) {
            // "Newsletter № 042 · 2026–2027": the house style, and the same
            // number the share page and settings preview use
            // (PTK_Share_Text::issue_label pads to 3). No date, no year.
            $issue_html  = '<div style="font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr( self::PALETTE['muted'] ) . ';font-weight:600;margin-bottom:10px;">';
            $issue_html .= 'Newsletter&nbsp;&#8470;&nbsp;' . esc_html( PTK_Share_Text::issue_label( self::str( $issue ) ) );
            $issue_html .= ( '' !== $year ) ? ' · ' . esc_html( $year ) : '';
            $issue_html .= '</div>';
        }

        $headline_html = '';
        if ( '' !== trim( $headline ) ) {
            $headline_html = '<h1 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(30px,7vw,44px);line-height:1.02;letter-spacing:-0.025em;margin:0;color:' . esc_attr( self::PALETTE['text'] ) . ';">' . esc_html( $headline ) . '</h1>';
        }

        $date_html = '';
        if ( '' !== trim( $date_display ) ) {
            $date_html = '<div style="font-weight:600;color:' . esc_attr( self::PALETTE['text'] ) . ';">' . esc_html( $date_display ) . '</div>';
        }
        if ( '' !== trim( $summary ) ) {
            $date_html .= '<div>' . esc_html( $summary ) . '</div>';
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'header' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:32px 20px;border-bottom:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:24px;flex-wrap:wrap;">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;">';
        $html .= $logo_html;
        $html .= '<div style="font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:' . esc_attr( self::PALETTE['primary'] ) . ';font-weight:700;line-height:1.4;">' . esc_html( $school_name ) . '</div>';
        $html .= '</div>';
        $html .= $join_html;
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
        $html .= '<p style="font-size:16px;line-height:1.65;color:' . esc_attr( self::PALETTE['muted'] ) . ';margin:24px 0 0;max-width:620px;">' . self::rich( $greeting ) . '</p>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * The announcement: the newsletter's one navy callout. A yellow italic
     * "when" line, a white headline, the details, optional dates (past rows
     * faded, the last row yellow as the deadline) and an optional white
     * button. A 4.1.x announcement that only has text shows that text as the
     * headline, so the callout never renders half-built.
     */
    private static function render_announcement( array $data, array $opts ) {
        $when     = isset( $data['when'] ) ? self::str( $data['when'] ) : '';
        $headline = isset( $data['headline'] ) ? self::str( $data['headline'] ) : '';
        $text     = isset( $data['text'] ) ? self::str( $data['text'] ) : '';
        $btn_text = isset( $data['button_text'] ) ? self::str( $data['button_text'] ) : '';
        $btn_url  = isset( $data['button_url'] ) ? self::str( $data['button_url'] ) : '';
        $today    = isset( $opts['today'] ) ? self::str( $opts['today'] ) : '';

        // Un-resaved 4.1.x data still says "pill".
        if ( '' === trim( $when ) && isset( $data['pill'] ) ) {
            $when = self::str( $data['pill'] );
        }

        $rows     = array();
        $timeline = isset( $data['timeline'] ) && is_array( $data['timeline'] ) ? $data['timeline'] : array();
        foreach ( $timeline as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $r = array(
                'date' => isset( $row['date'] ) ? self::str( $row['date'] ) : '',
                'time' => isset( $row['time'] ) ? self::str( $row['time'] ) : '',
                'what' => isset( $row['what'] ) ? self::str( $row['what'] ) : '',
            );
            if ( '' === trim( $r['date'] . $r['time'] . $r['what'] ) ) {
                continue;
            }
            $rows[] = $r;
        }
        $has_button = '' !== trim( $btn_text ) && '' !== trim( esc_url( $btn_url ) );

        if ( '' === trim( $when ) && '' === trim( $headline ) && '' === trim( $text ) && ! $has_button && empty( $rows ) ) {
            return self::placeholder( 'announcement', 'Your announcement will appear here.', $opts );
        }

        // A 4.1.x announcement is one sentence of text and no headline: that
        // sentence IS the headline. Plain text, tags stripped, no paragraph.
        if ( '' === trim( $headline ) && '' !== trim( $text ) ) {
            $headline = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
            $text     = '';
        }

        $has_text  = '' !== trim( $text );
        $has_after = ! empty( $rows ) || $has_button;

        $html  = '<div data-ptk-block="' . esc_attr( 'announcement' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['primary'] ) . ';padding:48px 20px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';

        if ( '' !== trim( $when ) ) {
            $html .= '<div style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-weight:500;font-size:15px;color:' . esc_attr( self::PALETTE['accent'] ) . ';margin-bottom:14px;">' . esc_html( $when ) . '</div>';
        }

        if ( '' !== trim( $headline ) ) {
            $h_margin = ( $has_text || $has_after ) ? '0 0 18px' : '0';
            $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(32px,6vw,52px);line-height:1.0;letter-spacing:-0.03em;margin:' . $h_margin . ';color:#ffffff;max-width:720px;">' . esc_html( $headline ) . '</h2>';
        }

        if ( $has_text ) {
            $t_margin = $has_after ? '0 0 28px' : '0';
            $html .= '<div style="font-size:16px;line-height:1.65;color:' . esc_attr( self::PALETTE['on_navy'] ) . ';margin:' . $t_margin . ';max-width:660px;">' . self::rich( $text, true ) . '</div>';
        }

        if ( ! empty( $rows ) ) {
            $states = PTK_Newsletter_Data::timeline_states( $rows, $today );
            $html  .= '<div style="max-width:660px;border-top:1px solid rgba(255,255,255,0.25);">';
            foreach ( $rows as $i => $r ) {
                $deadline = ! empty( $states[ $i ]['deadline'] );
                $past     = ! empty( $states[ $i ]['past'] );
                $numeral  = $deadline ? self::PALETTE['accent'] : '#ffffff';

                $style = 'display:flex;flex-wrap:wrap;gap:4px 20px;padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.18);' . ( $past ? 'opacity:0.45;' : '' );

                $detail = '';
                if ( '' !== trim( $r['time'] ) ) {
                    $detail .= '<strong style="color:' . esc_attr( $numeral ) . ';">' . esc_html( $r['time'] ) . '</strong>';
                }
                if ( '' !== trim( $r['time'] ) && '' !== trim( $r['what'] ) ) {
                    $detail .= ' · ';
                }
                if ( '' !== trim( $r['what'] ) ) {
                    $detail .= esc_html( $r['what'] );
                }

                // Data attributes first, then the style: the relabel script
                // reads data-timeline-date to fade the row for the reader's day.
                $html .= '<div data-timeline-date="' . esc_attr( $r['date'] ) . '"' . ( $deadline ? ' data-timeline-deadline' : '' ) . ' style="' . $style . '">';
                $html .= '<div style="flex:0 0 190px;font-family:' . self::FONT_SERIF . ';font-weight:500;font-size:20px;line-height:1.3;color:' . esc_attr( $numeral ) . ';' . self::NUMERALS . '">' . esc_html( self::format_timeline_date( $r['date'] ) ) . '</div>';
                $html .= '<div style="flex:1 1 240px;font-size:15px;line-height:1.5;color:' . esc_attr( self::PALETTE['on_navy'] ) . ';">' . $detail . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        if ( $has_button ) {
            $html .= '<div style="margin-top:28px;">';
            $html .= '<a href="' . esc_url( $btn_url ) . '" style="display:inline-block;background:#ffffff;color:' . esc_attr( self::PALETTE['primary'] ) . ';font-size:14px;font-weight:700;letter-spacing:0.02em;padding:14px 24px;border-radius:8px;text-decoration:none;">' . esc_html( $btn_text ) . '</a>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Coming up: "§ Coming up", then one hairline-ruled row per date --
     * numeral and weekday, title and detail, and a relabeling tag. Past rows
     * fade (grey numeral, never red); the relabel script redoes that for the
     * reader's own day via data-event-row / data-event-numeral.
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
            return self::placeholder( 'events', 'Your dates coming up will appear here.', $opts );
        }
        $rows = $valid_rows;

        $calendar_url = isset( $opts['calendar_url'] ) ? self::str( $opts['calendar_url'] ) : '';

        $html  = '<div data-ptk-block="' . esc_attr( 'events' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:40px 20px 40px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';
        $html .= self::section_rule( 'Coming up', '28px' );

        // Round 2: "See full calendar →" only when there's a link AND at
        // least one real row — this method already returned a placeholder
        // above when $valid_rows was empty, so reaching here means there is
        // something to see a full calendar OF. An empty Coming-up block
        // never gets the link, matching round 1's "empty sections render
        // nothing" rule.
        if ( '' !== trim( $calendar_url ) ) {
            $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:28px;">';
            $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0;color:' . esc_attr( self::PALETTE['text'] ) . ';">What\'s coming up</h2>';
            $html .= '<a href="' . esc_url( $calendar_url ) . '" style="font-size:14px;font-weight:700;color:' . esc_attr( self::PALETTE['primary'] ) . ';text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;white-space:nowrap;">See full calendar →</a>';
            $html .= '</div>';
        } else {
            $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0 0 28px;color:' . esc_attr( self::PALETTE['text'] ) . ';">What\'s coming up</h2>';
        }

        $count = count( $rows );
        foreach ( $rows as $i => $row ) {
            $date  = isset( $row['date'] ) ? self::str( $row['date'] ) : '';
            $title = isset( $row['title'] ) ? self::str( $row['title'] ) : '';
            $desc  = isset( $row['desc'] ) ? self::str( $row['desc'] ) : '';

            $bucket = ( '' !== $date )
                ? PTK_Newsletter_Data::relabel_for_date( $date, $today )
                : 'upcoming';
            $label  = isset( self::EVENT_LABELS[ $bucket ] ) ? self::EVENT_LABELS[ $bucket ] : self::EVENT_LABELS['upcoming'];
            $past   = ( 'past' === $bucket );

            // Past is grey, never red: house style keeps red for no school,
            // deadlines and urgent things.
            $date_color   = $past ? self::PALETTE['small'] : self::PALETTE['primary'];
            $date_display = '' !== $date ? self::format_event_date( $date ) : '';
            $weekday      = '' !== $date ? self::format_event_weekday( $date ) : '';

            // The § rule above the list is the only strong line; every row
            // is separated by a hairline.
            $row_style  = 'display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-start;padding:18px 0;border-top:1px solid ' . self::PALETTE['hairline'] . ';';
            $row_style .= ( $count - 1 === $i ) ? 'border-bottom:1px solid ' . self::PALETTE['hairline'] . ';' : '';
            $row_style .= $past ? 'opacity:0.45;' : '';

            $pill_style = 'font-size:10px;letter-spacing:0.16em;text-transform:uppercase;font-weight:700;color:' . ( $past ? self::PALETTE['muted'] : self::PALETTE['primary'] ) . ';background:' . ( $past ? self::PALETTE['hairline'] : self::PALETTE['chip'] ) . ';padding:5px 9px;border-radius:4px;white-space:nowrap;align-self:flex-start;';

            $html .= '<div data-event-row style="' . esc_attr( $row_style ) . '">';
            // 112px fits the widest realistic date, "May 28" (~100px at 30px
            // serif). At 88px two-digit days wrapped: "Sep" / "24". nowrap so
            // a wider font fallback overflows a little instead of breaking.
            $html .= '<div style="flex:0 0 112px;">';
            $html .= '<div data-event-numeral style="font-family:' . self::FONT_SERIF . ';font-weight:500;font-size:30px;line-height:0.95;white-space:nowrap;color:' . esc_attr( $date_color ) . ';' . self::NUMERALS . '">' . esc_html( $date_display ) . '</div>';
            if ( '' !== $weekday ) {
                $html .= '<div style="font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr( self::PALETTE['muted'] ) . ';font-weight:600;margin-top:4px;">' . esc_html( $weekday ) . '</div>';
            }
            $html .= '</div>';
            $html .= '<div style="flex:1 1 200px;min-width:200px;">';
            $html .= '<div style="font-size:17px;font-weight:600;line-height:1.35;margin-bottom:4px;">' . esc_html( $title ) . '</div>';
            $html .= '<div style="font-size:14px;color:' . esc_attr( self::PALETTE['muted'] ) . ';line-height:1.5;">' . self::rich( $desc ) . '</div>';
            $html .= '</div>';
            $html .= '<span data-event-date="' . esc_attr( $date ) . '" data-default="' . esc_attr( $label ) . '" style="' . esc_attr( $pill_style ) . '">' . esc_html( $label ) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Top story: a white section opened by "§ {label}" (default "Top story"),
     * with a section headline, the story, an optional photo below the text
     * and an optional link.
     */
    private static function render_featured( array $data, array $opts ) {
        $eyebrow   = isset( $data['eyebrow'] ) ? self::str( $data['eyebrow'] ) : '';
        $headline  = isset( $data['headline'] ) ? self::str( $data['headline'] ) : '';
        $body      = isset( $data['body'] ) ? self::str( $data['body'] ) : '';
        $image_id  = isset( $data['image_id'] ) ? (int) $data['image_id'] : 0;
        $link_url  = isset( $data['link_url'] ) ? self::str( $data['link_url'] ) : '';
        $link_text = isset( $data['link_text'] ) ? self::str( $data['link_text'] ) : '';

        $image_html = self::maybe_image( $image_id, $opts, $headline );

        // Nothing to feature (no text and no rendered image): skip the section.
        if ( '' === trim( $eyebrow ) && '' === trim( $headline ) && '' === trim( $body ) && '' === $image_html
            && ( '' === trim( $link_url ) || '' === trim( $link_text ) ) ) {
            return self::placeholder( 'featured', 'Your top story will appear here.', $opts );
        }

        $mark  = '' !== trim( $eyebrow ) ? $eyebrow : 'Top story';
        $html  = '<div data-ptk-block="' . esc_attr( 'featured' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:40px 20px 8px;box-sizing:border-box;">';
        $html .= self::story_section( self::section_rule( $mark ), $headline, $body, $image_html, $link_url, $link_text );
        $html .= '</div>';

        return $html;
    }

    /**
     * Stories: every story is its own white section with a "§ {label}" rule
     * (default "More news"). "§ More news" is never repeated: in a run of
     * unlabeled stories only the first carries it, and the rest open with a
     * plain hairline. A labeled story always shows its own mark.
     */
    private static function render_story_cards( array $data, array $opts ) {
        $cards = isset( $data['cards'] ) && is_array( $data['cards'] ) ? $data['cards'] : array();

        // Keep only cards with some content: a label, heading, body or link,
        // or an image. All-blank cards are dropped so nothing empty renders.
        $valid_cards = array();
        foreach ( $cards as $card ) {
            if ( ! is_array( $card ) ) {
                continue;
            }
            $eyebrow   = isset( $card['eyebrow'] ) ? self::str( $card['eyebrow'] ) : '';
            $heading   = isset( $card['heading'] ) ? self::str( $card['heading'] ) : '';
            $body      = isset( $card['body'] ) ? self::str( $card['body'] ) : '';
            $image_id  = isset( $card['image_id'] ) ? (int) $card['image_id'] : 0;
            $link_url  = isset( $card['link_url'] ) ? self::str( $card['link_url'] ) : '';
            $link_text = isset( $card['link_text'] ) ? self::str( $card['link_text'] ) : '';
            if ( '' === trim( $eyebrow ) && '' === trim( $heading ) && '' === trim( $body ) && '' === trim( $link_url ) && '' === trim( $link_text ) && $image_id <= 0 ) {
                continue;
            }
            $valid_cards[] = $card;
        }

        if ( empty( $valid_cards ) ) {
            return self::placeholder( 'story_cards', 'Your stories will appear here.', $opts );
        }

        // One outer div: the Builder's preview highlight keys on it.
        $html = '<div data-ptk-block="' . esc_attr( 'story_cards' ) . '">';

        $previous_unlabeled = false;
        foreach ( $valid_cards as $i => $card ) {
            $eyebrow   = isset( $card['eyebrow'] ) ? self::str( $card['eyebrow'] ) : '';
            $heading   = isset( $card['heading'] ) ? self::str( $card['heading'] ) : '';
            $body      = isset( $card['body'] ) ? self::str( $card['body'] ) : '';
            $image_id  = isset( $card['image_id'] ) ? (int) $card['image_id'] : 0;
            $link_url  = isset( $card['link_url'] ) ? self::str( $card['link_url'] ) : '';
            $link_text = isset( $card['link_text'] ) ? self::str( $card['link_text'] ) : '';

            $labeled = '' !== trim( $eyebrow );
            if ( $labeled ) {
                $rule = self::section_rule( $eyebrow );
            } elseif ( $previous_unlabeled ) {
                $rule = '<div style="height:1px;background:' . esc_attr( self::PALETTE['hairline'] ) . ';margin-bottom:16px;"></div>';
            } else {
                $rule = self::section_rule( 'More news' );
            }
            $previous_unlabeled = ! $labeled;

            $padding = ( 0 === $i ) ? '40px 20px 8px' : '24px 20px 8px';
            $html   .= '<div style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:' . $padding . ';box-sizing:border-box;">';
            $html   .= self::story_section( $rule, $heading, $body, self::maybe_image( $image_id, $opts, $heading ), $link_url, $link_text );
            $html   .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Quick notes: "§ {label}" (default "Quick notes") over a hairline list
     * of short items, each a small headline, a sentence and an optional link.
     */
    private static function render_quick_notes( array $data, array $opts ) {
        $label = isset( $data['label'] ) ? self::str( $data['label'] ) : '';
        $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

        $valid = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $n = array(
                'heading'   => isset( $item['heading'] ) ? self::str( $item['heading'] ) : '',
                'body'      => isset( $item['body'] ) ? self::str( $item['body'] ) : '',
                'link_url'  => isset( $item['link_url'] ) ? self::str( $item['link_url'] ) : '',
                'link_text' => isset( $item['link_text'] ) ? self::str( $item['link_text'] ) : '',
            );
            $has_link = '' !== trim( $n['link_url'] ) && '' !== trim( $n['link_text'] );
            if ( '' === trim( $n['heading'] ) && '' === trim( $n['body'] ) && ! $has_link ) {
                continue;
            }
            $valid[] = $n;
        }

        if ( empty( $valid ) ) {
            return self::placeholder( 'quick_notes', 'Your quick notes will appear here.', $opts );
        }

        $html  = '<div data-ptk-block="' . esc_attr( 'quick_notes' ) . '" style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:40px 20px 24px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';
        $html .= self::section_rule( '' !== trim( $label ) ? $label : 'Quick notes' );
        // House style: the § rule above a list is its only strong line, and
        // rows are separated by hairlines. So no line under the rule and none
        // after the last item -- a hairline only BETWEEN items. Otherwise the
        // first note sat under two lines and the last one above two.
        $html .= '<div style="max-width:700px;">';

        foreach ( array_values( $valid ) as $i => $n ) {
            $between = $i > 0 ? 'border-top:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';' : '';
            $html   .= '<div style="padding:16px 0;' . $between . '">';
            if ( '' !== trim( $n['heading'] ) ) {
                $html .= '<h3 style="font-family:' . self::FONT_SANS . ';font-size:17px;font-weight:700;line-height:1.35;color:' . esc_attr( self::PALETTE['text'] ) . ';margin:0 0 4px;">' . esc_html( $n['heading'] ) . '</h3>';
            }
            if ( '' !== trim( $n['body'] ) ) {
                $html .= '<div style="font-size:15px;line-height:1.6;color:' . esc_attr( self::PALETTE['muted'] ) . ';">' . self::rich( $n['body'] ) . '</div>';
            }
            if ( '' !== trim( $n['link_url'] ) && '' !== trim( $n['link_text'] ) ) {
                $html .= '<p style="margin:8px 0 0;"><a href="' . esc_url( $n['link_url'] ) . '" style="font-size:14px;font-weight:700;' . self::link_style() . '">' . esc_html( $n['link_text'] ) . '</a></p>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * "Got news?" closing (round 2): a plain white § section pointing
     * families at the news-submission link, with an optional "Questions?
     * Email {address}." line. Settings-driven (opts['news_url']), never a
     * stored block — dispatched from render()'s loop, immediately before
     * the footer, not named render_<type> so it is unreachable from the
     * dynamic $method dispatch above. Never navy: the announcement already
     * owns this newsletter's one navy callout (house rule).
     */
    private static function render_news_cta( array $opts ) {
        $news_url = isset( $opts['news_url'] ) ? self::str( $opts['news_url'] ) : '';
        if ( '' === trim( $news_url ) ) {
            return '';
        }

        $email = isset( $opts['contact_email'] ) ? self::str( $opts['contact_email'] ) : '';

        $html  = '<div style="font-family:' . self::FONT_SANS . ';color:' . esc_attr( self::PALETTE['text'] ) . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';padding:40px 20px 8px;box-sizing:border-box;">';
        $html .= '<div style="max-width:840px;margin:0 auto;">';
        $html .= self::section_rule( 'Your news' );
        $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0 0 12px;color:' . esc_attr( self::PALETTE['text'] ) . ';">Got news? Put it in the newsletter.</h2>';
        $html .= '<p style="font-size:16px;line-height:1.65;color:' . esc_attr( self::PALETTE['muted'] ) . ';margin:0 0 20px;max-width:620px;">Send it our way and we\'ll get it in the next issue.</p>';
        $html .= '<p style="margin:20px 0 0;">';
        $html .= '<a href="' . esc_url( $news_url ) . '" style="font-size:16px;font-weight:700;' . self::link_style() . '">Open the submission form →</a>';
        if ( '' !== trim( $email ) ) {
            $html .= ' <span style="font-size:16px;color:' . esc_attr( self::PALETTE['muted'] ) . ';">Questions? Email <a href="' . esc_url( 'mailto:' . $email ) . '" style="' . self::link_style() . '">' . esc_html( $email ) . '</a>.</span>';
        }
        $html .= '</p>';
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
            $html .= '<p style="font-size:17px;line-height:1.65;color:' . esc_attr( self::PALETTE['text'] ) . ';margin:0 0 28px;">' . self::rich( $signoff ) . '</p>';
        }

        if ( ! empty( $valid_links ) ) {
            $html .= '<div style="border-top:1px solid ' . esc_attr( self::PALETTE['hairline'] ) . ';padding-top:24px;display:flex;flex-wrap:wrap;gap:24px;font-size:13px;color:' . esc_attr( self::PALETTE['muted'] ) . ';line-height:1.55;">';
            foreach ( $valid_links as $link ) {
                $html .= '<div><a href="' . esc_url( $link['url'] ) . '" style="' . self::link_style() . 'word-break:break-word;">' . esc_html( $link['label'] ) . '</a></div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * The body of a top story or a story: the opening rule (or hairline),
     * headline, story, photo below the text, and link.
     */
    private static function story_section( $rule_html, $headline, $body, $image_html, $link_url, $link_text ) {
        $html  = '<div style="max-width:840px;margin:0 auto;">';
        $html .= $rule_html;
        if ( '' !== trim( $headline ) ) {
            $html .= '<h2 style="font-family:' . self::FONT_SANS . ';font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0 0 12px;color:' . esc_attr( self::PALETTE['text'] ) . ';">' . esc_html( $headline ) . '</h2>';
        }
        if ( '' !== trim( $body ) ) {
            $html .= '<div style="font-size:16px;line-height:1.65;color:' . esc_attr( self::PALETTE['muted'] ) . ';margin:0 0 20px;max-width:620px;">' . self::rich( $body ) . '</div>';
        }
        if ( '' !== $image_html ) {
            $html .= '<figure style="margin:28px 0 0;">' . $image_html . '</figure>';
        }
        if ( '' !== trim( $link_url ) && '' !== trim( $link_text ) ) {
            $html .= '<p style="margin:20px 0 0;"><a href="' . esc_url( $link_url ) . '" style="font-size:16px;font-weight:700;' . self::link_style() . '">' . esc_html( $link_text ) . '</a></p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * A volunteer's rich text (kses-cleaned) in the house style: links they
     * typed become underlined navy links (white on navy) and bold text is
     * ink (white on navy), as #040 writes them. Only bare <a> and <strong>
     * tags are styled -- a tag that already carries attributes other than
     * href is left exactly as it is.
     */
    private static function rich( $html, $on_navy = false ) {
        $html   = wp_kses_post( $html );
        $link   = $on_navy
            ? 'color:#ffffff;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;'
            : self::link_style();
        $strong = 'color:' . ( $on_navy ? '#ffffff' : self::PALETTE['text'] ) . ';';
        $html   = preg_replace( '/<a(\s+href="[^"]*")\s*>/i', '<a$1 style="' . $link . '">', $html );
        $html   = preg_replace( '/<strong>/i', '<strong style="' . $strong . '">', $html );
        return $html;
    }

    /** Navy text link with the house 1px underline (never a border hack). */
    private static function link_style() {
        return 'color:' . self::PALETTE['primary'] . ';text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;';
    }

    /**
     * The "§ Coming up" mark and its ink line, which opens every white
     * section. Deliberately not named render_*: see the dispatch in render().
     */
    private static function section_rule( $mark, $margin_bottom = '16px' ) {
        return '<div style="display:flex;align-items:center;gap:18px;margin-bottom:' . esc_attr( $margin_bottom ) . ';">'
            . '<div style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-weight:500;font-size:15px;color:' . esc_attr( self::PALETTE['primary'] ) . ';white-space:nowrap;">§ ' . esc_html( $mark ) . '</div>'
            . '<div style="flex:1;height:1px;background:' . esc_attr( self::PALETTE['text'] ) . ';min-width:20px;"></div>'
            . '</div>';
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

        return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" style="display:block;width:100%;height:auto;border-radius:4px;margin:0;" />';
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
     * An announcement date: "Mon, Sep 14" (the same abbreviations as the
     * Coming up numerals). Blank stays blank; an unparseable value is shown
     * as typed rather than dropped.
     *
     * @param string $date 'YYYY-MM-DD'.
     * @return string
     */
    private static function format_timeline_date( $date ) {
        if ( '' === trim( $date ) ) {
            return '';
        }
        $dt = DateTime::createFromFormat( '!Y-m-d', $date );
        if ( ! $dt ) {
            return $date;
        }
        return $dt->format( 'D, M j' );
    }

    /**
     * The weekday under an event numeral: "Monday".
     *
     * @param string $date 'YYYY-MM-DD'.
     * @return string Weekday, or '' if unparseable.
     */
    private static function format_event_weekday( $date ) {
        $dt = DateTime::createFromFormat( '!Y-m-d', $date );
        if ( ! $dt ) {
            return '';
        }
        return $dt->format( 'l' );
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
