<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-calendar-source.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Calendar_Source';
$expect = 'https://calendar.google.com/calendar/ical/c_fff6acf39aef90218f4e35915d4a2ec3fa80d2e83b6886c824e6b3594f80a285%40group.calendar.google.com/public/basic.ics';
$id     = 'c_fff6acf39aef90218f4e35915d4a2ec3fa80d2e83b6886c824e6b3594f80a285@group.calendar.google.com';

// Already a public .ics address.
$r = $t::normalize( $expect );
ptk_test_ok( $expect === $r['url'] && '' === $r['error'], 'a public .ics address passes through unchanged' );

// Bare calendar id.
$r = $t::normalize( $id );
ptk_test_ok( $expect === $r['url'] && '' === $r['error'], 'a bare @group.calendar.google.com id becomes the public .ics url' );

// gmail-style personal calendar id.
$r = $t::normalize( 'someone@gmail.com' );
ptk_test_ok( 'https://calendar.google.com/calendar/ical/someone%40gmail.com/public/basic.ics' === $r['url'], 'a gmail.com calendar id is accepted' );

// Embed / share link with src=.
$r = $t::normalize( 'https://calendar.google.com/calendar/embed?src=' . rawurlencode( $id ) . '&ctz=America%2FNew_York' );
ptk_test_ok( $expect === $r['url'] && '' === $r['error'], 'an embed link with src= is normalized to the public .ics url' );

// cid= share link (base64, URL-safe, unpadded).
$b64 = rtrim( strtr( base64_encode( $id ), '+/', '-_' ), '=' );
$r   = $t::normalize( 'https://calendar.google.com/calendar/u/0/r?cid=' . $b64 );
ptk_test_ok( $expect === $r['url'] && '' === $r['error'], 'a cid= share link decodes to the public .ics url' );

// Whitespace around any of the above is trimmed.
$r = $t::normalize( "  $id  \n" );
ptk_test_ok( $expect === $r['url'], 'surrounding whitespace is trimmed' );

// Empty means "clear it," not an error.
$r = $t::normalize( '' );
ptk_test_ok( '' === $r['url'] && '' === $r['error'], 'empty input is allowed (means: clear it)' );
$r = $t::normalize( '   ' );
ptk_test_ok( '' === $r['url'] && '' === $r['error'], 'whitespace-only input is allowed (means: clear it)' );

// Garbage is rejected with a plain, actionable message.
foreach ( array(
    'not a calendar at all',
    'https://example.com/whatever',
    'https://yourschool.org/calendar',
    'javascript:alert(1)',
) as $bad ) {
    $r = $t::normalize( $bad );
    ptk_test_ok( '' === $r['url'] && '' !== $r['error'] && false !== strpos( $r['error'], 'iCal format' ), 'rejected with a fix-it message: ' . $bad );
}

// Non-string input never passes.
$r = $t::normalize( array( 'x' ) );
ptk_test_ok( '' === $r['url'], 'non-string input never passes' );

ptk_test_done();
