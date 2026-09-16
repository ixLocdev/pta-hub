<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';
require __DIR__ . '/../includes/class-newsletter-renderer.php';

$blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Northeast PTA', 'greeting' => 'Hi families' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday', 'text' => 'Last day of school' ) ),
    array( 'type' => 'events', 'data' => array( 'rows' => array(
        array( 'date' => '2026-06-25', 'title' => 'Last Day of School', 'desc' => 'Early dismissal' ),
    ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'See you soon', 'links' => array() ) ),
);
$html = PTK_Newsletter_Renderer::render( $blocks, array(
    'issue' => 39, 'date' => '2026-06-22', 'today' => '2026-06-22',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Northeast PTA',
) );

ptk_test_ok( strpos( $html, 'Northeast PTA' ) !== false, 'renders school name' );
ptk_test_ok( strpos( $html, 'Last Day of School' ) !== false, 'renders event title' );
ptk_test_ok( strpos( $html, '#1a2f5c' ) !== false, 'uses Harbor Navy primary color' );
ptk_test_ok( strpos( $html, '&#8470;&nbsp;039' ) !== false, 'renders issue number zero-padded as № 039' );
ptk_test_ok( strpos( $html, 'No.&nbsp;' ) === false, 'no old "No." label' );
ptk_test_ok( strpos( $html, '<script' ) === false, 'no raw script tags in output' );

// --- Masthead: a provided headline renders as the big H1. ------------------
$headline_blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Northeast PTA', 'headline' => 'Week of June 22', 'greeting' => 'Hi families' ) ),
);
$headline_html = PTK_Newsletter_Renderer::render( $headline_blocks, array(
    'issue' => 39, 'date' => '2026-06-22', 'today' => '2026-06-22',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Northeast PTA',
) );
ptk_test_ok( strpos( $headline_html, 'Week of June 22' ) !== false, 'provided headline renders' );
ptk_test_ok( strpos( $headline_html, 'Northeast PTA' ) !== false, 'school name still renders as the eyebrow' );

// --- Masthead: a blank headline auto-derives "Week of {Month} {day}". ------
$blank_headline_blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Northeast PTA', 'headline' => '', 'greeting' => 'Hi families' ) ),
);
$blank_headline_html = PTK_Newsletter_Renderer::render( $blank_headline_blocks, array(
    'issue' => 39, 'date' => '2026-07-16', 'today' => '2026-07-16',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Northeast PTA',
) );
ptk_test_ok( strpos( $blank_headline_html, 'Week of July 13' ) !== false, 'blank headline auto-derives "Week of {Monday}" (Thu Jul 16 -> Jul 13)' );

// --- Masthead: the issue date renders friendly, not raw ISO. ---------------
ptk_test_ok( strpos( $blank_headline_html, 'July 16, 2026' ) !== false, 'friendly formatted issue date renders' );
ptk_test_ok( strpos( $blank_headline_html, '2026-07-16' ) === false, 'raw ISO date is not shown as the date line' );

// --- Empty blocks must render nothing (no empty bars). ---------------------
// The default layout is entirely empty; only the header should render.
$empty_opts  = array(
    'issue' => 39, 'date' => '2026-06-22', 'today' => '2026-06-22',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Demo PTA',
);
$empty_html = PTK_Newsletter_Renderer::render( PTK_Newsletter_Data::default_blocks(), $empty_opts );

// Header still renders (issue number present) — proves we didn't skip everything.
ptk_test_ok( strpos( $empty_html, '&#8470;&nbsp;039' ) !== false, 'empty layout still renders header issue number' );
// Announcement + featured are the only blocks that use a navy background fill;
// absence proves both empty blocks were skipped (header uses navy as a text color only).
ptk_test_ok( strpos( $empty_html, 'background:#1a2f5c' ) === false, 'empty announcement/featured navy bars are skipped' );
// The events heading must not render on its own when there are no rows.
ptk_test_ok( strpos( $empty_html, 'Coming up' ) === false, 'empty events block skips the heading' );
// An all-empty footer bar must not render its top border chrome.
ptk_test_ok( strpos( $empty_html, 'padding:40px 20px 32px' ) === false, 'empty footer bar is skipped' );

// --- Populated optional blocks must STILL render (guard against over-skip). -
$full_blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Demo PTA', 'greeting' => 'Hi' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Friday', 'text' => 'Bake sale today' ) ),
    array( 'type' => 'events', 'data' => array( 'rows' => array(
        array( 'date' => '2026-06-25', 'title' => 'Field Day', 'desc' => 'Bring water' ),
    ) ) ),
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'Year in review', 'headline' => 'What a year', 'body' => 'Thanks all', 'image_id' => 0 ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        array( 'heading' => 'Volunteers wanted', 'body' => 'Sign up now', 'image_id' => 0, 'link_url' => 'https://example.org', 'link_text' => 'Sign up' ),
    ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Thanks', 'links' => array(
        array( 'label' => 'Website', 'url' => 'https://example.org' ),
    ) ) ),
);
$full_html = PTK_Newsletter_Renderer::render( $full_blocks, $empty_opts );
ptk_test_ok( strpos( $full_html, 'Bake sale today' ) !== false, 'populated announcement still renders' );
ptk_test_ok( strpos( $full_html, 'Field Day' ) !== false && strpos( $full_html, 'Coming up' ) !== false, 'populated events still render' );
ptk_test_ok( strpos( $full_html, 'What a year' ) !== false, 'populated top story still renders' );
ptk_test_ok( strpos( $full_html, 'Volunteers wanted' ) !== false, 'populated story card still renders' );
ptk_test_ok( strpos( $full_html, 'Thanks' ) !== false && strpos( $full_html, 'Website' ) !== false, 'populated footer still renders' );

// --- Hostile input must come out escaped in every context. -----------------
$hostile_blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Demo PTA', 'greeting' => 'Hi' ) ),
    array( 'type' => 'events', 'data' => array( 'rows' => array(
        // Text node + attribute context: a script title and a date with a quote.
        array( 'date' => '2026-06-25" onmouseover="alert(1)', 'title' => '<script>alert(1)</script>', 'desc' => 'x' ),
    ) ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        // href context: a javascript: scheme must be neutralized by esc_url.
        array( 'heading' => 'H', 'body' => 'B', 'image_id' => 0, 'link_url' => 'javascript:alert(1)', 'link_text' => 'Go' ),
    ) ) ),
);
$hostile_html = PTK_Newsletter_Renderer::render( $hostile_blocks, $empty_opts );

// Text-node escaping: raw <script> must not survive; escaped form must appear.
ptk_test_ok( strpos( $hostile_html, '<script>alert(1)</script>' ) === false, 'hostile text node: raw <script> not present' );
ptk_test_ok( strpos( $hostile_html, '&lt;script&gt;' ) !== false, 'hostile text node: escaped &lt;script&gt; present' );

// Attribute escaping: the injected date lands in data-event-date="..."; the
// quote must be escaped so it cannot break out of the attribute.
ptk_test_ok( strpos( $hostile_html, 'onmouseover="alert(1)"' ) === false, 'hostile attribute: no attribute breakout' );
ptk_test_ok( strpos( $hostile_html, '&quot;' ) !== false, 'hostile attribute: double-quote escaped to &quot;' );

// href escaping: esc_url must strip the javascript: scheme.
ptk_test_ok( strpos( $hostile_html, 'javascript:alert(1)' ) === false, 'hostile href: javascript: scheme neutralized' );

// Every rendered block carries a data-ptk-block hook for the preview highlight.
$blocks2 = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => 'Week of X', 'greeting' => 'Hi' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thu', 'text' => 'Last day' ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Bye', 'links' => array() ) ),
);
$html2 = PTK_Newsletter_Renderer::render( $blocks2, array(
    'issue' => 5, 'date' => '2026-07-16', 'today' => '2026-07-16',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE PTA',
) );
ptk_test_ok( strpos( $html2, 'data-ptk-block="header"' ) !== false, 'header carries data-ptk-block' );
ptk_test_ok( strpos( $html2, 'data-ptk-block="announcement"' ) !== false, 'announcement carries data-ptk-block' );
ptk_test_ok( strpos( $html2, 'data-ptk-block="footer"' ) !== false, 'footer carries data-ptk-block' );

// Preview mode: empty blocks become outlinable placeholder stubs...
$empty = PTK_Newsletter_Data::default_blocks(); // all fields blank
$opts_base = array( 'issue' => 1, 'date' => '2026-07-16', 'today' => '2026-07-16',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Demo PTA' );

$preview = PTK_Newsletter_Renderer::render( $empty, array_merge( $opts_base, array( 'preview_placeholders' => true ) ) );
ptk_test_ok( strpos( $preview, 'data-ptk-block="announcement"' ) !== false, 'preview mode: empty announcement still outlinable' );
ptk_test_ok( strpos( $preview, 'data-ptk-block="events"' ) !== false, 'preview mode: empty events still outlinable' );
ptk_test_ok( strpos( $preview, 'data-ptk-block="featured"' ) !== false, 'preview mode: empty featured still outlinable' );
ptk_test_ok( strpos( $preview, 'data-ptk-block="story_cards"' ) !== false, 'preview mode: empty story cards still outlinable' );
ptk_test_ok( strpos( $preview, 'data-ptk-block="footer"' ) !== false, 'preview mode: empty footer still outlinable' );
ptk_test_ok( stripos( $preview, 'will appear here' ) !== false, 'preview mode: placeholder tells the user what goes here' );

// ...but published output is UNCHANGED — empty blocks still render nothing.
$published = PTK_Newsletter_Renderer::render( $empty, $opts_base );
ptk_test_ok( strpos( $published, 'data-ptk-block="featured"' ) === false, 'published: empty featured renders nothing' );
ptk_test_ok( strpos( $published, 'data-ptk-block="story_cards"' ) === false, 'published: empty story cards render nothing' );
ptk_test_ok( stripos( $published, 'will appear here' ) === false, 'published: no placeholder text leaks out' );

// --- Event dates never wrap ("Sep" / "24"). ------------------------------
ptk_test_ok( strpos( $html, 'flex:0 0 112px;' ) !== false, 'event date column is wide enough for "May 28"' );
ptk_test_ok( strpos( $html, 'font-size:30px;line-height:0.95;white-space:nowrap;' ) !== false, 'event date does not wrap' );

// --- "Week of" names the week's Monday; Sunday looks ahead. ---------------
foreach ( array(
    '2026-09-16' => 'Week of September 14', // Wednesday
    '2026-09-14' => 'Week of September 14', // Monday
    '2026-09-13' => 'Week of September 14', // Sunday (#040 went out this day)
    '2026-09-20' => 'Week of September 21', // Sunday -> the following Monday
    '2026-09-19' => 'Week of September 14', // Saturday stays in its own week
    '2026-10-01' => 'Week of September 28', // across a month boundary
) as $d => $want ) {
    $h = PTK_Newsletter_Renderer::render(
        array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '' ) ) ),
        array( 'issue' => 40, 'date' => $d, 'today' => $d, 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
    );
    ptk_test_ok( strpos( $h, $want . '<' ) !== false, "issue dated $d is headed \"$want\"" );
}

// --- 4.2.0 masthead and Coming up. ----------------------------------------
$m = PTK_Newsletter_Renderer::render(
    array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '', 'summary' => 'ASE registration is open this week', 'greeting' => 'Hi' ) ) ),
    array( 'issue' => 41, 'date' => '2026-09-20', 'today' => '2026-09-20', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
);
ptk_test_ok( strpos( $m, 'Newsletter&nbsp;&#8470;&nbsp;041 · 2026–2027' ) !== false, 'masthead eyebrow reads "Newsletter № 041 · 2026–2027"' );
ptk_test_ok( strpos( $m, 'ASE registration is open this week' ) !== false, 'summary line renders under the date' );
ptk_test_ok( strpos( $m, "'Libre Franklin'" ) !== false && strpos( $m, "'Inter'" ) === false, 'Libre Franklin replaces Inter' );
ptk_test_ok( strpos( $m, 'Fraunces' ) === false, 'Fraunces is gone' );
ptk_test_ok( substr_count( $m, '<h1' ) === 1, 'exactly one h1' );
$m2 = PTK_Newsletter_Renderer::render(
    array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '', 'summary' => '', 'greeting' => '' ) ) ),
    array( 'issue' => '', 'date' => '', 'today' => '2026-09-20', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
);
ptk_test_ok( strpos( $m2, '&#8470;' ) === false && strpos( $m2, '2026' ) === false, 'no issue and no date: no eyebrow at all' );

$ev = PTK_Newsletter_Renderer::render(
    array( array( 'type' => 'events', 'data' => array( 'rows' => array(
        array( 'date' => '2026-09-14', 'title' => 'ASE registration opens', 'desc' => 'Members first' ),
        array( 'date' => '2026-09-10', 'title' => 'Already happened', 'desc' => '' ),
    ) ) ) ),
    array( 'issue' => 41, 'date' => '2026-09-13', 'today' => '2026-09-13', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
);
ptk_test_ok( strpos( $ev, '§ Coming up' ) !== false, 'events open with the § Coming up rule' );
ptk_test_ok( strpos( $ev, ">What's coming up<" ) !== false, 'events heading is "What\'s coming up"' );
ptk_test_ok( strpos( $ev, '>Monday<' ) !== false, 'weekday renders under the numeral' );
ptk_test_ok( strpos( $ev, 'data-event-row' ) !== false && strpos( $ev, 'data-event-numeral' ) !== false, 'rows and numerals carry hooks for the relabel script' );
ptk_test_ok( strpos( $ev, '#a51d23' ) === false, 'a past date is never red' );
ptk_test_ok( strpos( $ev, 'opacity:0.45' ) !== false, 'a past row is faded' );
ptk_test_ok( strpos( $ev, 'border-top:1px solid #111' ) === false, 'no strong line on the first row; the § rule is the strong line' );
ptk_test_ok( strpos( $ev, 'lining-nums tabular-nums' ) !== false, 'numerals use lining figures' );

// --- 4.2.0 announcement: when line, headline, text, timeline, button. -------
$an_opts = array( 'issue' => 40, 'date' => '2026-09-13', 'today' => '2026-09-15', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' );
$an = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array(
    'when'        => 'Opens Monday, Sept 14',
    'headline'    => 'ASE registration opens Monday.',
    'text'        => 'Twelve classes for grades K–5.',
    'button_text' => 'Go to ASE registration',
    'button_url'  => 'https://app.givebacks.gives/c691c4',
    'timeline'    => array(
        array( 'date' => '2026-09-14', 'time' => '8:30 AM–12:30 PM', 'what' => 'PTA members only' ),
        array( 'date' => '2026-09-17', 'time' => '12:00 noon', 'what' => 'Registration closes' ),
    ),
) ) ), $an_opts );
ptk_test_ok( substr_count( $an, 'background:#1a2f5c' ) === 1, 'the announcement is one navy fill' );
ptk_test_ok( strpos( $an, 'color:#ffd166' ) !== false && strpos( $an, 'Opens Monday, Sept 14' ) !== false, 'the when line is yellow' );
ptk_test_ok( strpos( $an, '<h2' ) !== false && strpos( $an, 'color:#ffffff;max-width:720px;">ASE registration opens Monday.' ) !== false, 'the headline is a white h2' );
ptk_test_ok( strpos( $an, 'color:#cfd8e3' ) !== false, 'the text is on-navy grey' );
ptk_test_ok( substr_count( $an, 'data-timeline-date="' ) === 2, 'two timeline rows carry their date' );
ptk_test_ok( substr_count( $an, 'data-timeline-deadline' ) === 1 && preg_match( '/data-timeline-date="2026-09-17" data-timeline-deadline/', $an ) === 1, 'the last row, and only it, is the deadline' );
ptk_test_ok( strpos( $an, 'Mon, Sep 14' ) !== false, 'timeline date reads "Mon, Sep 14"' );
ptk_test_ok( preg_match( '/data-timeline-date="2026-09-14"[^>]*opacity:0\.45/', $an ) === 1, 'a past timeline row is faded on the server' );
ptk_test_ok( preg_match( '/data-timeline-date="2026-09-17"[^>]*opacity/', $an ) === 0, 'a future row is not faded' );
ptk_test_ok( strpos( $an, 'background:#ffffff;color:#1a2f5c' ) !== false && strpos( $an, '>Go to ASE registration<' ) !== false, 'the button is white on navy' );
ptk_test_ok( strpos( $an, 'rgba(255,255,255,0.18)' ) !== false, 'timeline hairlines are translucent white' );
ptk_test_ok( strpos( $an, 'border-left' ) === false && strpos( $an, 'border-right' ) === false, 'no one-sided borders' );

$an_min = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array( 'when' => '', 'headline' => 'Just a headline', 'text' => '', 'button_text' => 'Go', 'button_url' => '', 'timeline' => array() ) ) ), $an_opts );
ptk_test_ok( strpos( $an_min, 'Just a headline' ) !== false && strpos( $an_min, '>Go<' ) === false && strpos( $an_min, 'border-top:1px solid rgba' ) === false, 'headline alone renders; no button without a link; no empty timeline' );
// A migrated 4.1.x announcement: text only. The text takes the headline slot; no empty h2, no lone paragraph.
$an_old = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array( 'when' => 'Thursday · Jun 25', 'headline' => '', 'text' => '<p>Last <strong>day</strong> of school.</p>', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ) ), $an_opts );
ptk_test_ok( preg_match( '/<h2[^>]*>Last day of school\.<\/h2>/', $an_old ) === 1, 'old text-only announcement: the text becomes the headline, tags stripped' );
ptk_test_ok( strpos( $an_old, 'color:#cfd8e3' ) === false, 'old text-only announcement: no separate paragraph' );
$an_none = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array( 'when' => '', 'headline' => '', 'text' => '', 'button_text' => '', 'button_url' => '', 'timeline' => array( array( 'date' => '', 'time' => '', 'what' => '' ) ) ) ) ), $an_opts );
ptk_test_ok( strpos( $an_none, 'data-ptk-block="announcement"' ) === false, 'all-blank announcement (blank timeline row included) renders nothing' );

// --- 4.2.0 stories on white, with § labels; quick notes. --------------------
$st_opts = array( 'issue' => 40, 'date' => '2026-09-13', 'today' => '2026-09-13', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE',
    'image_url_cb' => function ( $id ) { return 'https://x.test/img-' . $id . '.jpg'; } );
$st = PTK_Newsletter_Renderer::render( array(
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'ASE volunteers', 'headline' => 'Can you help on Tuesdays?', 'body' => '<p>Free class.</p>', 'image_id' => 7, 'link_url' => 'https://x.test/v', 'link_text' => 'Email Leslie' ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        array( 'eyebrow' => 'Date change', 'heading' => 'Film on the Field moves.', 'body' => 'Oct 16.', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
        array( 'eyebrow' => '', 'heading' => 'Second story', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    ) ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array(
        array( 'heading' => 'Lunch menu', 'body' => 'On the site.', 'link_url' => 'https://x.test/lunch', 'link_text' => 'See the menu' ),
        array( 'heading' => 'Handbook', 'body' => '', 'link_url' => '', 'link_text' => '' ),
    ) ) ),
), $st_opts );
ptk_test_ok( strpos( $st, 'background:#1a2f5c' ) === false, 'no navy band among the stories' );
ptk_test_ok( strpos( $st, '#f6f4ef' ) === false && strpos( $st, 'border-radius:14px' ) === false, 'no beige card boxes' );
ptk_test_ok( strpos( $st, '§ ASE volunteers' ) !== false, 'top story uses its label as the § mark' );
ptk_test_ok( strpos( $st, '§ Date change' ) !== false, 'a story uses its label as the § mark' );
ptk_test_ok( strpos( $st, '§ More news' ) !== false, 'a story with no label falls back to "More news"' );
ptk_test_ok( strpos( $st, '§ Good to know' ) !== false, 'quick notes use the group label' );
ptk_test_ok( substr_count( $st, '<h2' ) === 3, 'top story and each story are h2s' );
ptk_test_ok( substr_count( $st, '<h3' ) === 2, 'quick-note items are h3s' );
ptk_test_ok( strpos( $st, 'https://x.test/img-7.jpg' ) !== false && strpos( $st, 'border-radius:4px' ) !== false, 'the photo renders with 4px corners' );
ptk_test_ok( strpos( $st, '<figure' ) > strpos( $st, 'Free class.' ), 'the photo sits below the text' );
ptk_test_ok( strpos( $st, 'text-underline-offset:3px' ) !== false && strpos( $st, 'border-bottom:1px solid #1a2f5c' ) === false, 'links are underlined, not bordered' );
ptk_test_ok( strpos( $st, '>See the menu<' ) !== false, 'a quick note link renders' );
ptk_test_ok( strpos( $st, 'data-ptk-block="quick_notes"' ) !== false, 'quick notes carry the preview hook' );
ptk_test_ok( strpos( $st, 'border-left' ) === false && strpos( $st, 'border-right' ) === false, 'no one-sided borders' );

// "§ More news" never repeats: only the first of a run of unlabeled stories gets it.
$mn = PTK_Newsletter_Renderer::render( array( array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
    array( 'eyebrow' => '', 'heading' => 'One', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => '', 'heading' => 'Two', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => 'Membership', 'heading' => 'Three', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => '', 'heading' => 'Four', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => '', 'heading' => 'Five', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
) ) ) ), $st_opts );
ptk_test_ok( substr_count( $mn, '§ More news' ) === 2, 'More news: once per run of unlabeled stories' );
ptk_test_ok( strpos( $mn, '§ Membership' ) !== false, 'a labeled story always shows its own mark' );
ptk_test_ok( substr_count( $mn, '<h2' ) === 5, 'every story still renders' );

$qn_empty = PTK_Newsletter_Renderer::render( array( array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array() ) ) ), $st_opts );
ptk_test_ok( strpos( $qn_empty, 'data-ptk-block' ) === false, 'a label with no items renders nothing when published' );
$qn_ph = PTK_Newsletter_Renderer::render( array( array( 'type' => 'quick_notes', 'data' => array( 'label' => '', 'items' => array() ) ) ), array_merge( $st_opts, array( 'preview_placeholders' => true ) ) );
ptk_test_ok( strpos( $qn_ph, 'data-ptk-block="quick_notes"' ) !== false, 'preview mode outlines empty quick notes' );
$ft_default = PTK_Newsletter_Renderer::render( array( array( 'type' => 'featured', 'data' => array( 'eyebrow' => '', 'headline' => 'X', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ) ) ), $st_opts );
ptk_test_ok( strpos( $ft_default, '§ Top story' ) !== false, 'top story with no label falls back to "Top story"' );

// --- The #040 fixture: whole-newsletter guarantees, and the HTML for eyeballing. ---
$fx = json_decode( file_get_contents( __DIR__ . '/fixtures/newsletter-040-blocks.json' ), true );
ptk_test_ok( is_array( $fx ), 'the #040 fixture parses' );
$fx = PTK_Newsletter_Data::sanitize_blocks( $fx );
$fx_html = PTK_Newsletter_Renderer::render( $fx, array( 'issue' => 40, 'date' => '2026-09-13', 'today' => '2026-09-13', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Northeast Elementary PTA' ) );
ptk_test_ok( substr_count( $fx_html, 'background:#1a2f5c' ) === 1, '#040: exactly one navy band' );
ptk_test_ok( strpos( $fx_html, 'border-left' ) === false && strpos( $fx_html, 'border-right' ) === false, '#040: no one-sided borders anywhere' );
ptk_test_ok( substr_count( $fx_html, '<h1' ) === 1, '#040: one h1' );
ptk_test_ok( strpos( $fx_html, 'Inter' ) === false && strpos( $fx_html, 'Fraunces' ) === false, '#040: old fonts gone' );
ptk_test_ok( preg_match_all( '/#ffd166/', $fx_html ) >= 2, '#040: yellow appears (when line, deadline row)' );
ptk_test_ok( strpos( $fx_html, 'Newsletter&nbsp;&#8470;&nbsp;040 · 2026–2027' ) !== false, '#040: the eyebrow' );
$out = getenv( 'PTK_RENDER_OUT' );
if ( $out ) {
    file_put_contents( $out, "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><link href=\"https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap\" rel=\"stylesheet\"><style>body{margin:0}</style></head><body>" . $fx_html . "</body></html>" );
    echo "  (wrote $out)\n";
}

ptk_test_done();
