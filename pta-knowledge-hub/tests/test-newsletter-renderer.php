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
ptk_test_ok( strpos( $empty_html, 'Upcoming' ) === false, 'empty events block skips the heading' );
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
ptk_test_ok( strpos( $full_html, 'Field Day' ) !== false && strpos( $full_html, 'Upcoming' ) !== false, 'populated events still render' );
ptk_test_ok( strpos( $full_html, 'What a year' ) !== false && strpos( $full_html, 'background:#1a2f5c' ) !== false, 'populated featured hero still renders' );
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

ptk_test_done();
