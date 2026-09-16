<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-color.php';
require __DIR__ . '/../includes/class-share-settings.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Settings';

// ---------------------------------------------------------------------
// validate_facebook_url()
// ---------------------------------------------------------------------

$ok = $t::validate_facebook_url( 'https://www.facebook.com/groups/northeastpta' );
ptk_test_ok( 'https://www.facebook.com/groups/northeastpta' === $ok['value'] && '' === $ok['error'], 'a normal https group link is accepted' );

$ok = $t::validate_facebook_url( "  https://www.facebook.com/groups/northeastpta/?ref=share%20x  " );
ptk_test_ok( 'https://www.facebook.com/groups/northeastpta/?ref=share%20x' === $ok['value'], 'surrounding spaces trimmed, %XX kept' );

$empty = $t::validate_facebook_url( '   ' );
ptk_test_ok( '' === $empty['value'] && '' === $empty['error'], 'empty is allowed (means: clear it)' );

$http = $t::validate_facebook_url( 'http://www.facebook.com/groups/x' );
ptk_test_ok( '' === $http['value'] && false !== strpos( $http['error'], 'https://' ), 'http:// is rejected with a message naming https://' );

foreach ( array(
    'facebook.com/groups/x',
    'www.facebook.com/groups/x',
    'javascript:alert(1)',
    'https://',
    'https://localhost/x',
    'https://www.facebook.com/groups/x y',
    'https://www.facebook.com/"><script>',
    'ftp://facebook.com/x',
    'data:text/html,hi',
) as $bad ) {
    $r = $t::validate_facebook_url( $bad );
    ptk_test_ok( '' === $r['value'] && '' !== $r['error'], 'rejected: ' . $bad );
}

$arr = $t::validate_facebook_url( array( 'x' ) );
ptk_test_ok( '' === $arr['value'], 'non-string input never passes' );

// ---------------------------------------------------------------------
// color_message()
// ---------------------------------------------------------------------

$saved = PTK_Share_Color::readable_pair( '#475569', '#1a2f5c' );
ptk_test_ok( '#475569' !== $saved, 'slate fails on navy, so it is adjusted' );
$msg = $t::color_message( '#475569', $saved );
ptk_test_ok( false !== strpos( $msg, 'too dark' ) && false !== strpos( $msg, 'lightened' ) && false !== strpos( $msg, $saved ), 'adjusted colour: says too dark, lightened, and names the saved colour' );

$msg = $t::color_message( '#ffd166', '#ffd166' );
ptk_test_ok( 0 === strpos( $msg, 'Saved.' ) && false !== strpos( $msg, '#ffd166' ), 'passing colour: plain "Saved" message' );

$msg = $t::color_message( '#eeeeee', '#555555' );
ptk_test_ok( false !== strpos( $msg, 'darkened' ), 'a darker saved colour is described as darkened' );

ptk_test_ok( false !== strpos( $t::source_label( 'council' ), 'Council' ), 'source label: council' );
ptk_test_ok( false !== strpos( $t::source_label( 'own' ), 'own pick' ), 'source label: own' );
ptk_test_ok( false !== strpos( $t::source_label( 'nonsense' ), 'standard' ), 'source label: anything else is the standard colour' );

// The admin CSS must never draw a one-sided accent bar.
$css = file_get_contents( __DIR__ . '/../assets/css/share-settings.css' ) . file_get_contents( __DIR__ . '/../assets/css/share-page.css' );
ptk_test_ok( ! preg_match( '/border-(left|right|inline-start|inline-end)\s*:/i', $css ), 'no one-sided borders in the share page / settings CSS' );

ptk_test_done();
