<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';
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
ptk_test_ok( false !== strpos( $msg, 'too dark' ) && false !== strpos( $msg, 'lightened' ) && false !== strpos( $msg, $saved ), 'adjusted color: says too dark, lightened, and names the saved color' );

$msg = $t::color_message( '#ffd166', '#ffd166' );
ptk_test_ok( 0 === strpos( $msg, 'Saved.' ) && false !== strpos( $msg, '#ffd166' ), 'passing color: plain "Saved" message' );

$msg = $t::color_message( '#eeeeee', '#555555' );
ptk_test_ok( false !== strpos( $msg, 'darkened' ), 'a darker saved color is described as darkened' );

ptk_test_ok( false !== strpos( $t::source_label( 'council' ), 'district council set' ), 'source label: council, in plain words' );
ptk_test_ok( false !== strpos( $t::source_label( 'own' ), 'own pick' ), 'source label: own' );
ptk_test_ok( false !== strpos( $t::source_label( 'nonsense' ), 'website came with' ), 'source label: anything else is described as what it is' );

// ---------------------------------------------------------------------
// submitted_color()
// ---------------------------------------------------------------------
$c = $t::submitted_color( '#336699', '1a2f5c', '#336699' );
ptk_test_ok( '#1a2f5c' === $c['value'] && '' === $c['error'], 'typed 1a2f5c (no #) wins over an unchanged picker' );
$c = $t::submitted_color( '#336699', '#1A2F5C', '#336699' );
ptk_test_ok( '#1a2f5c' === $c['value'], 'typed #1A2F5C is normalized' );
$c = $t::submitted_color( '#336699', '#abc', '#336699' );
ptk_test_ok( '#aabbcc' === $c['value'], 'typed #abc expands' );
$c = $t::submitted_color( '#ff0000', '#336699', '#336699' );
ptk_test_ok( '#ff0000' === $c['value'], 'picker wins when the text field was left as it was (no JavaScript)' );
$c = $t::submitted_color( '#ff0000', '', '#336699' );
ptk_test_ok( '#ff0000' === $c['value'], 'empty text falls back to the picker' );
$c = $t::submitted_color( '#ff0000', 'blue', '#336699' );
ptk_test_ok( '' === $c['value'] && false !== strpos( $c['error'], '1a2f5c' ), 'a non-code is refused with an example' );
$c = $t::submitted_color( 'junk', '', '' );
ptk_test_ok( '' === $c['value'] && '' !== $c['error'], 'no usable color at all is an error, never navy by accident' );

// ---------------------------------------------------------------------
// 4.3.0: submitted_color() is reusable for the background field with
// zero changes -- same helper, a background-flavored scenario.
// ---------------------------------------------------------------------
$c = $t::submitted_color( '#1a2f5c', '2a3f6c', '#1a2f5c' );
ptk_test_ok( '#2a3f6c' === $c['value'] && '' === $c['error'], 'submitted_color() works unchanged for a background-color scenario' );

// ---------------------------------------------------------------------
// 4.3.0: PTK_Share_Color::BG_OPTION / TEXT_FALLBACK
// ---------------------------------------------------------------------
ptk_test_ok( 'ptk_share_bg_color' === PTK_Share_Color::BG_OPTION, 'BG_OPTION is the expected option key' );
ptk_test_ok( '#ffffff' === PTK_Share_Color::TEXT_FALLBACK, 'TEXT_FALLBACK is white' );

// ---------------------------------------------------------------------
// 4.3.0: validate_link_field() -- Join / News / Calendar links
// ---------------------------------------------------------------------
$l = $t::validate_link_field( 'https://yourschool.org/join', 'join@yourschool.org' );
ptk_test_ok( 'https://yourschool.org/join' === $l['value'] && '' === $l['error'], 'a plain https link is accepted' );

$l = $t::validate_link_field( 'join@yourschool.org', 'join@yourschool.org' );
ptk_test_ok( 'mailto:join@yourschool.org' === $l['value'] && '' === $l['error'], 'a bare email becomes a mailto: link' );

$l = $t::validate_link_field( '', 'join@yourschool.org' );
ptk_test_ok( '' === $l['value'] && '' === $l['error'], 'empty is allowed (means: clear it)' );

foreach ( array( 'javascript:alert(1)', 'not a link at all', 'ftp://x.test/y' ) as $bad ) {
    $l = $t::validate_link_field( $bad, 'join@yourschool.org' );
    ptk_test_ok( '' === $l['value'] && '' !== $l['error'], 'validate_link_field rejects: ' . $bad );
}

// ---------------------------------------------------------------------
// 4.3.0: validate_contact_email()
// ---------------------------------------------------------------------
$e = $t::validate_contact_email( 'office@yourschool.org' );
ptk_test_ok( 'office@yourschool.org' === $e['value'] && '' === $e['error'], 'a normal email is accepted' );

$e = $t::validate_contact_email( '' );
ptk_test_ok( '' === $e['value'] && '' === $e['error'], 'empty email is allowed (means: clear it)' );

$e = $t::validate_contact_email( 'not an email' );
ptk_test_ok( '' === $e['value'] && '' !== $e['error'], 'a non-email is refused with a plain message' );

// The admin CSS must never draw a one-sided accent bar.
$css = file_get_contents( __DIR__ . '/../assets/css/share-settings.css' ) . file_get_contents( __DIR__ . '/../assets/css/share-page.css' );
ptk_test_ok( ! preg_match( '/border-(left|right|inline-start|inline-end)\s*:/i', $css ), 'no one-sided borders in the share page / settings CSS' );

ptk_test_done();
