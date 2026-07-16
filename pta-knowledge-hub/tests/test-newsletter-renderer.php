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
ptk_test_ok( strpos( $html, 'No.&nbsp;39' ) !== false || strpos( $html, '39' ) !== false, 'renders issue number' );
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
ptk_test_ok( strpos( $blank_headline_html, 'Week of July 16' ) !== false, 'blank headline auto-derives "Week of {Month} {day}"' );

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
ptk_test_ok( strpos( $empty_html, 'No.&nbsp;39' ) !== false, 'empty layout still renders header issue number' );
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

ptk_test_done();
