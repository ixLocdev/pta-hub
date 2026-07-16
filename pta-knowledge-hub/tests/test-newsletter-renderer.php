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

ptk_test_done();
