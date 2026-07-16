<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';

// Any PHP warning/notice (e.g. Array-to-string conversion) fails the run.
set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

// default_blocks() returns the suggested layout, header first + footer last.
$blocks = PTK_Newsletter_Data::default_blocks();
$types  = array_column( $blocks, 'type' );
ptk_test_ok( $types[0] === 'header', 'default layout starts with header' );
ptk_test_ok( end( $types ) === 'footer', 'default layout ends with footer' );
ptk_test_ok( in_array( 'events', $types, true ), 'default layout includes events' );

// sanitize_blocks() drops unknown types and forces header-first/footer-last.
$raw = array(
    array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => '2026-06-25', 'title' => 'Last Day <script>x</script>', 'desc' => 'Bye' ) ) ) ),
    array( 'type' => 'evil',   'data' => array() ),
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'greeting' => 'Hi' ) ),
);
$clean = PTK_Newsletter_Data::sanitize_blocks( $raw );
$ctypes = array_column( $clean, 'type' );
ptk_test_ok( $ctypes[0] === 'header', 'sanitize forces header first' );
ptk_test_ok( end( $ctypes ) === 'footer', 'sanitize appends footer if missing' );
ptk_test_ok( ! in_array( 'evil', $ctypes, true ), 'sanitize drops unknown block types' );
$eventRow = $clean[ array_search( 'events', $ctypes, true ) ]['data']['rows'][0];
ptk_test_ok( strpos( $eventRow['title'], '<script>' ) === false, 'sanitize strips scripts from titles' );

// sanitize_blocks() handles non-scalar (array) leaf fields without warnings.
$hostile = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => array( 'x' ), 'greeting' => 'Hi' ) ),
    array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => array( 'x' ), 'title' => array( 'x' ), 'desc' => 'Bye' ) ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'See ya', 'links' => array( array( 'label' => 'Home', 'url' => array( 'x' ) ) ) ) ),
);
$hclean  = PTK_Newsletter_Data::sanitize_blocks( $hostile );
$htypes  = array_column( $hclean, 'type' );
$hheader = $hclean[ array_search( 'header', $htypes, true ) ]['data'];
$hevent  = $hclean[ array_search( 'events', $htypes, true ) ]['data']['rows'][0];
$hlink   = $hclean[ array_search( 'footer', $htypes, true ) ]['data']['links'][0];
ptk_test_ok( $hheader['school_name'] === '', 'array header school_name becomes empty string' );
ptk_test_ok( $hevent['date'] === '', 'array event date becomes empty string' );
ptk_test_ok( $hevent['title'] === '', 'array event title becomes empty string' );
ptk_test_ok( $hlink['url'] === '', 'array footer link url becomes empty string' );

ptk_test_done();
