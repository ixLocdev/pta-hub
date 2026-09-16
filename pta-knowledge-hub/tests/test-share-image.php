<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-color.php';
require __DIR__ . '/../includes/class-share-text.php';
require __DIR__ . '/../includes/class-share-data.php';
require __DIR__ . '/../includes/class-share-image.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Image';

$args = array(
    'issue'       => '042',
    'date'        => 'September 16, 2026',
    'school_name' => 'Northeast Elementary School PTA',
    'color'       => '#d97706',
);

// ---------------------------------------------------------------------
// capabilities() -- two separate checks, not one
// ---------------------------------------------------------------------

$caps = $t::capabilities();
ptk_test_ok( is_array( $caps ), 'capabilities() returns an array' );
ptk_test_ok( array_key_exists( 'gd', $caps ) && is_bool( $caps['gd'] ), 'capabilities()[gd] is a boolean' );
ptk_test_ok( array_key_exists( 'freetype', $caps ) && is_bool( $caps['freetype'] ), 'capabilities()[freetype] is a boolean' );
ptk_test_ok( $caps['gd'] === function_exists( 'imagecreatetruecolor' ), 'capabilities()[gd] reports imagecreatetruecolor()' );
ptk_test_ok( $caps['freetype'] === function_exists( 'imagettftext' ), 'capabilities()[freetype] reports imagettftext()' );

// ---------------------------------------------------------------------
// The fonts have to actually be on disk, or nothing below can draw.
// ---------------------------------------------------------------------

foreach ( $t::font_files() as $label => $path ) {
    ptk_test_ok( is_readable( $path ), "bundled font present and readable: $label" );
}

// ---------------------------------------------------------------------
// Degradation -- injected caps, because function_exists() cannot be stubbed
// ---------------------------------------------------------------------

$no_ft = $t::render_png( $args, array( 'gd' => true, 'freetype' => false ) );
ptk_test_ok( $no_ft === false, 'render_png returns false (not a fatal, not a blank image) without FreeType' );

$no_gd = $t::render_png( $args, array( 'gd' => false, 'freetype' => false ) );
ptk_test_ok( $no_gd === false, 'render_png returns false without GD' );

$no_gd_yes_ft = $t::render_png( $args, array( 'gd' => false, 'freetype' => true ) );
ptk_test_ok( $no_gd_yes_ft === false, 'render_png returns false when GD is missing even if FreeType is claimed' );

// ---------------------------------------------------------------------
// The happy path -- GD and FreeType are both present in this CLI PHP
// ---------------------------------------------------------------------

if ( ! $caps['gd'] || ! $caps['freetype'] ) {
    echo "  ..  - SKIP: this PHP has no GD/FreeType, so the drawing tests cannot run\n";
    // ---------------------------------------------------------------------
// should_regenerate() -- the decision ensure_square() makes, pulled out
// so it can be tested without a WordPress runtime.
// ---------------------------------------------------------------------

ptk_test_ok(
    $t::should_regenerate( 'abc', 'abc', true, false ) === false,
    'should_regenerate: hash matches and the attachment is still there -> reuse it'
);
ptk_test_ok(
    $t::should_regenerate( 'abc', 'def', true, false ) === true,
    'should_regenerate: the inputs moved -> redraw'
);
ptk_test_ok(
    $t::should_regenerate( 'abc', 'abc', false, false ) === true,
    'should_regenerate: matching hash but the attachment is gone (a volunteer deleted it) -> redraw'
);
ptk_test_ok(
    $t::should_regenerate( '', 'abc', false, false ) === true,
    'should_regenerate: nothing stored yet -> draw the first one'
);
ptk_test_ok(
    $t::should_regenerate( 'abc', 'def', true, true ) === false,
    'should_regenerate: a school uploaded its own square -> never overwrite it, however stale our hash looks'
);
ptk_test_ok(
    $t::should_regenerate( '', 'abc', false, true ) === false,
    'should_regenerate: custom wins even when there is no generated square at all'
);

// ---------------------------------------------------------------------
// should_clean_up() -- what before_delete_post is allowed to delete
// ---------------------------------------------------------------------

ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 77, true, false ) === true,
    'should_clean_up: our generated square goes with the newsletter'
);
ptk_test_ok(
    $t::should_clean_up( 'attachment', 77, true, false ) === false,
    'should_clean_up: before_delete_post fires for the attachment itself -- do not recurse'
);
ptk_test_ok(
    $t::should_clean_up( 'post', 77, true, false ) === false,
    'should_clean_up: an ordinary post is not ours'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_knowledge', 77, true, false ) === false,
    'should_clean_up: the plugin\'s other post type is not ours either'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 77, true, true ) === false,
    'should_clean_up: never delete a square the school uploaded -- it may be in use elsewhere'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 77, false, false ) === false,
    'should_clean_up: the attachment is already gone, so there is nothing to delete'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 0, false, false ) === false,
    'should_clean_up: no stored attachment id at all'
);
ptk_test_ok(
    $t::should_clean_up( false, 77, true, false ) === false,
    'should_clean_up: get_post_type() returning false is not a match'
);

// ---------------------------------------------------------------------
// attachment_filename() -- predictable, and safe for a filesystem
// ---------------------------------------------------------------------

ptk_test_ok(
    $t::attachment_filename( 12, '042' ) === 'share-square-12-042.png',
    'attachment_filename names the file after the post and issue'
);
ptk_test_ok(
    $t::attachment_filename( 12, '' ) === 'share-square-12.png',
    'attachment_filename copes with no issue number'
);
ptk_test_ok(
    $t::attachment_filename( 12, '../../etc/passwd' ) === 'share-square-12-etcpasswd.png',
    'attachment_filename strips anything that is not a letter, digit or dash'
);

ptk_test_done();
}

$png = $t::render_png( $args, array( 'gd' => true, 'freetype' => true ) );
ptk_test_ok( is_string( $png ) && '' !== $png, 'render_png returns a non-empty string' );
ptk_test_ok( is_string( $png ) && substr( $png, 0, 4 ) === "\x89PNG", 'render_png returns something starting with the PNG signature' );

$size = getimagesizefromstring( $png );
ptk_test_ok( is_array( $size ), 'the bytes parse as an image' );
ptk_test_ok( is_array( $size ) && 1080 === $size[0], 'the image is 1080 wide' );
ptk_test_ok( is_array( $size ) && 1080 === $size[1], 'the image is 1080 tall' );
ptk_test_ok( is_array( $size ) && IMAGETYPE_PNG === $size[2], 'the image really is a PNG' );

// Detect-for-real: $caps of null must behave the same here.
$auto = $t::render_png( $args, null );
ptk_test_ok( is_string( $auto ) && substr( $auto, 0, 4 ) === "\x89PNG", 'render_png( $args, null ) detects capabilities for real and draws' );

// Not a blank image: the navy ground, the accent and white text must all
// be in there, so the pixels cannot be one flat fill.
$im = imagecreatefromstring( $png );
ptk_test_ok( false !== $im, 'GD can read back what we produced' );
$seen = array();
for ( $x = 0; $x < 1080; $x += 9 ) {
    for ( $y = 0; $y < 1080; $y += 9 ) {
        $seen[ imagecolorat( $im, $x, $y ) ] = true;
    }
}
ptk_test_ok( count( $seen ) > 12, 'the square has real content, not one flat colour (' . count( $seen ) . ' distinct sampled colours)' );

// The ground is navy where nothing is drawn (top-left corner inset).
$corner = imagecolorat( $im, 6, 6 );
$rgb    = array( ( $corner >> 16 ) & 0xFF, ( $corner >> 8 ) & 0xFF, $corner & 0xFF );
ptk_test_ok( $rgb === array( 0x1a, 0x2f, 0x5c ), 'the ground is navy #1a2f5c' );

// ---------------------------------------------------------------------
// The colour it draws with has been through the contrast guard
// ---------------------------------------------------------------------

$accent = $t::accent_for( '#d97706' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $accent, '#1a2f5c' ) >= 4.5, 'the accent is readable on the navy ground' );
$accent_bad = $t::accent_for( '#4338ca' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $accent_bad, '#1a2f5c' ) >= 4.5, 'an accent that fails on navy (1.66:1) is corrected before drawing' );
$accent_junk = $t::accent_for( '#zzz' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $accent_junk, '#1a2f5c' ) >= 4.5, 'a junk accent still comes out readable' );

// ---------------------------------------------------------------------
// It must survive the awkward real inputs, not just the tidy one
// ---------------------------------------------------------------------

$awkward = array(
    'a very long school name' => array( 'issue' => '140', 'date' => 'September 16, 2026', 'school_name' => 'Northeast Elementary School PTA', 'color' => '#2563eb' ),
    'a very short one'        => array( 'issue' => '001', 'date' => 'September 2, 2026', 'school_name' => 'NE PTA', 'color' => '#16a34a' ),
    'a silly long name'       => array( 'issue' => '7', 'date' => '', 'school_name' => 'The Parent Teacher Association of Northeast Elementary School, Montclair', 'color' => '' ),
);
foreach ( $awkward as $label => $case ) {
    $out = $t::render_png( $case, array( 'gd' => true, 'freetype' => true ) );
    ptk_test_ok( is_string( $out ) && substr( $out, 0, 4 ) === "\x89PNG", "render_png survives $label" );
    $s = is_string( $out ) ? getimagesizefromstring( $out ) : false;
    ptk_test_ok( is_array( $s ) && 1080 === $s[0] && 1080 === $s[1], "still 1080x1080 for $label" );
}

// With nothing to draw, a square would be the navy ground and two rules --
// the blank image the contract forbids. It says so rather than shipping one.
ptk_test_ok( $t::render_png( array(), array( 'gd' => true, 'freetype' => true ) ) === false, 'render_png returns false when there is nothing to draw' );
ptk_test_ok( $t::render_png( array( 'issue' => '', 'date' => '', 'school_name' => '', 'color' => '#2563eb' ), array( 'gd' => true, 'freetype' => true ) ) === false, 'a colour alone is not content' );

// ---------------------------------------------------------------------
// fit_text() -- the shrink-to-fit the long names depend on
// ---------------------------------------------------------------------

$font = $t::font_files();
$big  = $t::fit_text( 'Northeast Elementary School PTA', $font['school'], 64, 24, 880 );
ptk_test_ok( $big > 0 && $big <= 64, 'fit_text never grows past the size asked for' );
ptk_test_ok( $big >= 24, 'fit_text never shrinks past the floor' );
$w = $t::text_width( 'Northeast Elementary School PTA', $font['school'], $big );
ptk_test_ok( $w <= 880, "fit_text got the long name inside 880px (measured {$w}px at {$big}pt)" );
$short = $t::fit_text( 'NE PTA', $font['school'], 64, 24, 880 );
ptk_test_ok( 64 === $short, 'fit_text leaves a short name at full size' );

// ---------------------------------------------------------------------
// should_regenerate() -- the decision ensure_square() makes, pulled out
// so it can be tested without a WordPress runtime.
// ---------------------------------------------------------------------

ptk_test_ok(
    $t::should_regenerate( 'abc', 'abc', true, false ) === false,
    'should_regenerate: hash matches and the attachment is still there -> reuse it'
);
ptk_test_ok(
    $t::should_regenerate( 'abc', 'def', true, false ) === true,
    'should_regenerate: the inputs moved -> redraw'
);
ptk_test_ok(
    $t::should_regenerate( 'abc', 'abc', false, false ) === true,
    'should_regenerate: matching hash but the attachment is gone (a volunteer deleted it) -> redraw'
);
ptk_test_ok(
    $t::should_regenerate( '', 'abc', false, false ) === true,
    'should_regenerate: nothing stored yet -> draw the first one'
);
ptk_test_ok(
    $t::should_regenerate( 'abc', 'def', true, true ) === false,
    'should_regenerate: a school uploaded its own square -> never overwrite it, however stale our hash looks'
);
ptk_test_ok(
    $t::should_regenerate( '', 'abc', false, true ) === false,
    'should_regenerate: custom wins even when there is no generated square at all'
);

// ---------------------------------------------------------------------
// should_clean_up() -- what before_delete_post is allowed to delete
// ---------------------------------------------------------------------

ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 77, true, false ) === true,
    'should_clean_up: our generated square goes with the newsletter'
);
ptk_test_ok(
    $t::should_clean_up( 'attachment', 77, true, false ) === false,
    'should_clean_up: before_delete_post fires for the attachment itself -- do not recurse'
);
ptk_test_ok(
    $t::should_clean_up( 'post', 77, true, false ) === false,
    'should_clean_up: an ordinary post is not ours'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_knowledge', 77, true, false ) === false,
    'should_clean_up: the plugin\'s other post type is not ours either'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 77, true, true ) === false,
    'should_clean_up: never delete a square the school uploaded -- it may be in use elsewhere'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 77, false, false ) === false,
    'should_clean_up: the attachment is already gone, so there is nothing to delete'
);
ptk_test_ok(
    $t::should_clean_up( 'pta_newsletter', 0, false, false ) === false,
    'should_clean_up: no stored attachment id at all'
);
ptk_test_ok(
    $t::should_clean_up( false, 77, true, false ) === false,
    'should_clean_up: get_post_type() returning false is not a match'
);

// ---------------------------------------------------------------------
// attachment_filename() -- predictable, and safe for a filesystem
// ---------------------------------------------------------------------

ptk_test_ok(
    $t::attachment_filename( 12, '042' ) === 'share-square-12-042.png',
    'attachment_filename names the file after the post and issue'
);
ptk_test_ok(
    $t::attachment_filename( 12, '' ) === 'share-square-12.png',
    'attachment_filename copes with no issue number'
);
ptk_test_ok(
    $t::attachment_filename( 12, '../../etc/passwd' ) === 'share-square-12-etcpasswd.png',
    'attachment_filename strips anything that is not a letter, digit or dash'
);

// The square is a masthead, not a log line: "2026-09-14" reads like a filename.
ptk_test_ok( PTK_Share_Image::dateline( '2026-09-14' ) === 'Week of September 14', 'an ISO date becomes a week' );
ptk_test_ok( PTK_Share_Image::dateline( '2026-01-05' ) === 'Week of January 5', 'no leading zero on the day' );
ptk_test_ok( PTK_Share_Image::dateline( 'Winter term' ) === 'Winter term', 'a non-ISO date is left alone' );
ptk_test_ok( PTK_Share_Image::dateline( '' ) === '', 'an empty date stays empty' );

ptk_test_done();
