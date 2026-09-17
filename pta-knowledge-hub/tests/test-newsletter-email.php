<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';
require __DIR__ . '/../includes/class-newsletter-renderer.php';
require __DIR__ . '/../includes/class-newsletter-email.php';

$fixture_blocks = json_decode( file_get_contents( __DIR__ . '/fixtures/newsletter-040-blocks.json' ), true );
$blocks          = PTK_Newsletter_Data::sanitize_blocks( $fixture_blocks );

$opts = array(
    'issue'         => 41,
    'date'          => '2026-09-14',
    'today'         => '2026-09-14',
    'school_name'   => 'Northeast Elementary PTA',
    'permalink'     => 'https://northeastpta.org/2026/09/14/pta-newsletter-041/',
    'site_url'      => 'https://northeastpta.org',
    'news_url'      => 'https://northeastpta.org/newsletter-submission-form/',
    'contact_email' => 'NortheastPTA@northeastpta.org',
);

$html = PTK_Newsletter_Email::generate( $blocks, $opts );

// --- Structure ---------------------------------------------------------
ptk_test_ok( strpos( $html, '<!DOCTYPE html>' ) === 0, 'starts with a DOCTYPE (a full paste-ready document)' );
ptk_test_ok( strpos( $html, '</html>' ) !== false, 'closes the document' );
ptk_test_ok( strpos( $html, '<table' ) !== false, 'uses table layout (email-safe)' );
ptk_test_ok( strpos( $html, 'display:flex' ) === false, 'no flexbox anywhere (email-unsafe)' );
ptk_test_ok( strpos( $html, 'grid-template' ) === false, 'no CSS grid anywhere (email-unsafe)' );
ptk_test_ok( strpos( $html, '<img' ) === false, 'no <img> tags at all (teaser format has no images)' );
ptk_test_ok( strpos( $html, '<script' ) === false, 'no script tags' );

// --- No one-sided borders (hard house rule) -----------------------------
ptk_test_ok( ! preg_match( '/border-left\s*:/i', $html ), 'no border-left anywhere' );
ptk_test_ok( ! preg_match( '/border-right\s*:/i', $html ), 'no border-right anywhere' );

// --- Masthead / headline -------------------------------------------------
ptk_test_ok( strpos( $html, 'Newsletter &#8470;&nbsp;041' ) !== false, 'issue number renders zero-padded' );
ptk_test_ok( strpos( $html, 'Northeast Elementary PTA' ) !== false, 'school name renders' );
ptk_test_ok( strpos( $html, 'ASE registration opens Monday, Sept 14. PTA members go first.' ) !== false, 'lead headline is the announcement headline' );

// --- The one navy callout -------------------------------------------------
ptk_test_ok( strpos( $html, '#1a2f5c' ) !== false, 'navy callout color present' );
ptk_test_ok( strpos( $html, 'Go to ASE registration' ) !== false, 'callout button text renders' );
ptk_test_ok( strpos( $html, 'https://app.givebacks.gives/c691c4' ) !== false, 'callout button links to the announcement url' );
ptk_test_ok( substr_count( $html, 'background-color:#1a2f5c; border-radius:10px' ) === 1, 'exactly one navy callout' );

// --- Inside this issue: links point at the permalink + anchor ------------
ptk_test_ok( strpos( $html, '#block-' ) !== false, 'row links carry a #block- anchor' );
ptk_test_ok( strpos( $html, 'https://northeastpta.org/2026/09/14/pta-newsletter-041/#block-' ) !== false, 'row anchors are built from the permalink' );
ptk_test_ok( strpos( $html, 'Can you help on Tuesdays' ) !== false, 'the top story is listed' );
ptk_test_ok( strpos( $html, 'Film on the Field moves to Friday' ) !== false, 'a story card is listed' );
ptk_test_ok( strpos( $html, 'Good to know' ) !== false, 'the quick notes section is listed by its own label' );
ptk_test_ok( strpos( $html, "What&#039;s coming up" ) !== false || strpos( $html, "What's coming up" ) !== false, 'the dates group is listed' );
ptk_test_ok( strpos( $html, 'Got news? Put it in the newsletter' ) !== false, '"Got news?" row is listed when a news_url is set' );

// --- Empty sections are omitted, not shown blank -------------------------
$minimal_blocks = PTK_Newsletter_Data::sanitize_blocks( array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Sample PTA' ) ),
) );
$minimal_html = PTK_Newsletter_Email::generate( $minimal_blocks, array(
    'issue'       => 5,
    'date'        => '2026-01-05',
    'school_name' => 'Sample PTA',
    'permalink'   => 'https://example.org/newsletter-5/',
    'site_url'    => 'https://example.org',
) );
ptk_test_ok( strpos( $minimal_html, 'Inside this issue' ) === false, 'no "Inside this issue" rule when there is nothing to list' );
ptk_test_ok( strpos( $minimal_html, 'background-color:#1a2f5c; border-radius:10px' ) === false, 'no callout when there is no announcement content' );
ptk_test_ok( strpos( $minimal_html, 'Read the full newsletter' ) !== false, 'the one button still always renders' );

// --- The one button --------------------------------------------------------
ptk_test_ok( substr_count( $html, 'Read the full newsletter' ) === 1, 'exactly one "Read the full newsletter" button' );
ptk_test_ok( strpos( $html, esc_url( $opts['permalink'] ) ) !== false, 'button links to the permalink' );

// --- No permalink: refuses to generate (nothing to link to) --------------
$no_link_html = PTK_Newsletter_Email::generate( $blocks, array( 'issue' => 1, 'date' => '2026-01-01', 'school_name' => 'X' ) );
ptk_test_ok( '' === $no_link_html, 'generate() returns empty string when there is no permalink' );

// --- Escaping: a stray quote/angle bracket in a field never breaks out of an attribute ---
$xss_blocks = PTK_Newsletter_Data::sanitize_blocks( array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Sample" onmouseover="alert(1)' ) ),
    array( 'type' => 'featured', 'data' => array(
        'headline' => 'A "quoted" <b>headline</b>',
        'body'     => 'Body text.',
    ) ),
) );
$xss_html = PTK_Newsletter_Email::generate( $xss_blocks, array(
    'issue' => 6, 'date' => '2026-01-12', 'school_name' => 'Sample" onmouseover="alert(1)',
    'permalink' => 'https://example.org/n/', 'site_url' => 'https://example.org',
) );
ptk_test_ok( strpos( $xss_html, '" onmouseover="alert(1)"' ) === false, 'a quote-breaking school name cannot inject a live attribute (the raw text may still appear, escaped)' );
ptk_test_ok( strpos( $xss_html, '<b>' ) === false, 'a raw <b> tag in a headline is escaped, not rendered' );

// --- Subject line ----------------------------------------------------------
$subject = PTK_Newsletter_Email::subject( $blocks, array( 'issue' => 41, 'date' => '2026-09-14' ) );
ptk_test_ok( strpos( $subject, '041' ) !== false, 'subject includes the padded issue number' );
ptk_test_ok( strpos( $subject, 'ASE registration opens Monday' ) !== false, 'subject uses the announcement headline' );

$subject_no_announcement = PTK_Newsletter_Email::subject( $minimal_blocks, array( 'issue' => 5, 'date' => '2026-01-05' ) );
ptk_test_ok( strpos( $subject_no_announcement, 'Week of' ) !== false, 'subject falls back to "Week of ..." with no announcement or top story' );

$featured_only_blocks = PTK_Newsletter_Data::sanitize_blocks( array(
    array( 'type' => 'featured', 'data' => array( 'headline' => 'The gym floor is finally done', 'body' => 'x' ) ),
) );
$subject_featured = PTK_Newsletter_Email::subject( $featured_only_blocks, array( 'issue' => 7, 'date' => '2026-01-19' ) );
ptk_test_ok( strpos( $subject_featured, 'The gym floor is finally done' ) !== false, 'subject falls back to the top story headline' );

ptk_test_done();
