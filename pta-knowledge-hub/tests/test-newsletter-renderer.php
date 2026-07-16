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

ptk_test_done();
