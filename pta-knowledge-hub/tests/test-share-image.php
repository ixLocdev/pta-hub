<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-focal-point.php';
require __DIR__ . '/../includes/class-share-color.php';
require __DIR__ . '/../includes/class-share-text.php';
require __DIR__ . '/../includes/class-share-data.php';
require __DIR__ . '/../includes/class-newsletter-data.php';
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
    'background'  => '#1a2f5c',
    'text'        => '#d97706',
);

// ---------------------------------------------------------------------
// capabilities() -- two separate checks, not one
// ---------------------------------------------------------------------

$caps = $t::capabilities();
ptk_test_ok( is_array( $caps ), 'capabilities() returns an array' );
ptk_test_ok( array_key_exists( 'gd', $caps ) && is_bool( $caps['gd'] ), 'capabilities()[gd] is a boolean' );
ptk_test_ok( array_key_exists( 'freetype', $caps ) && is_bool( $caps['freetype'] ), 'capabilities()[freetype] is a boolean' );
ptk_test_ok( $caps['gd'] === function_exists( 'imagecreatetruecolor' ), 'capabilities()[gd] reports imagecreatetruecolor()' );
$ft_fonts    = $t::font_files();
$ft_expected = function_exists( 'imagettftext' ) && function_exists( 'imagettfbbox' ) && is_array( @imagettfbbox( 12, 0, $ft_fonts['issue'], 'A' ) );
ptk_test_ok( $caps['freetype'] === $ft_expected, 'capabilities()[freetype] means type can really be measured, not just that imagettftext() exists' );

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
    $t::attachment_filename( 12, '042', 'a1b2c3d4' ) === 'share-square-12-042-a1b2c3d4.png',
    'attachment_filename adds the drawing stamp so a redraw gets a new address'
);
ptk_test_ok(
    $t::attachment_filename( 12, '042', '../x' ) === 'share-square-12-042.png',
    'attachment_filename drops a stamp that is not plain hex'
);
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
ptk_test_ok( count( $seen ) > 12, 'the square has real content, not one flat color (' . count( $seen ) . ' distinct sampled colors)' );

// The ground is navy where nothing is drawn (top-left corner inset).
$corner = imagecolorat( $im, 6, 6 );
$rgb    = array( ( $corner >> 16 ) & 0xFF, ( $corner >> 8 ) & 0xFF, $corner & 0xFF );
ptk_test_ok( $rgb === array( 0x1a, 0x2f, 0x5c ), 'the ground is navy #1a2f5c' );

// ---------------------------------------------------------------------
// The color it draws with has been through the contrast guard
// ---------------------------------------------------------------------

$accent = $t::text_for( '#d97706', '#1a2f5c' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $accent, '#1a2f5c' ) >= 4.5, 'the text color is readable on the navy background' );
$accent_bad = $t::text_for( '#4338ca', '#1a2f5c' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $accent_bad, '#1a2f5c' ) >= 4.5, 'a text color that fails on navy (1.66:1) is corrected before drawing' );
$accent_junk = $t::text_for( '#zzz', '#1a2f5c' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $accent_junk, '#1a2f5c' ) >= 4.5, 'a junk text color still comes out readable' );

// text_for() against a LIGHT background darkens instead of lightening.
$on_light = $t::text_for( '#ffffff', '#efece6' );
ptk_test_ok( PTK_Share_Color::contrast_ratio( $on_light, '#efece6' ) >= 4.5, 'text_for() darkens white on a light background rather than trying to lighten it further' );
ptk_test_ok( '#ffffff' !== $on_light, 'text_for() actually changed the failing color' );

// ---------------------------------------------------------------------
// It must survive the awkward real inputs, not just the tidy one
// ---------------------------------------------------------------------

$awkward = array(
    'a very long school name' => array( 'issue' => '140', 'date' => 'September 16, 2026', 'school_name' => 'Northeast Elementary School PTA', 'background' => '#1a2f5c', 'text' => '#2563eb' ),
    'a very short one'        => array( 'issue' => '001', 'date' => 'September 2, 2026', 'school_name' => 'NE PTA', 'background' => '#1a2f5c', 'text' => '#16a34a' ),
    'a silly long name'       => array( 'issue' => '7', 'date' => '', 'school_name' => 'The Parent Teacher Association of Northeast Elementary School, Montclair', 'background' => '', 'text' => '' ),
    'a light background'      => array( 'issue' => '9', 'date' => 'September 16, 2026', 'school_name' => 'Sample PTA', 'background' => '#efece6', 'text' => '#1a2f5c' ),
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
ptk_test_ok( $t::render_png( array( 'issue' => '', 'date' => '', 'school_name' => '', 'background' => '#1a2f5c', 'text' => '#2563eb' ), array( 'gd' => true, 'freetype' => true ) ) === false, 'colors alone are not content' );

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
// Round 3: the square's "photo behind the words" layer.
// ---------------------------------------------------------------------

// render_png() with no photo_id (or photo_id => 0): unchanged behavior --
// the existing "the ground is navy" assertion above already proves this
// path draws nothing new; this just confirms the arg is accepted as a
// no-op.
$no_photo = $t::render_png( array_merge( $args, array( 'photo_id' => 0 ) ), array( 'gd' => true, 'freetype' => true ) );
ptk_test_ok( is_string( $no_photo ) && substr( $no_photo, 0, 4 ) === "\x89PNG", 'render_png with photo_id 0 still returns a valid PNG (existing squares are untouched)' );

// A photo_id pointing at a file that does not exist (no WordPress
// runtime here, so get_attached_file() -- and every WP-coupled path --
// is unreachable from this plain-php test; draw_square_photo() must
// treat that exactly like "no photo," never a fatal). We exercise the
// pure fallback by pointing at a photo_id it cannot resolve.
$missing_photo = $t::render_png( array_merge( $args, array( 'photo_id' => 999999 ) ), array( 'gd' => true, 'freetype' => true ) );
ptk_test_ok( is_string( $missing_photo ) && substr( $missing_photo, 0, 4 ) === "\x89PNG", 'render_png with an unresolvable photo_id still returns a valid PNG -- never fatal, never blank (falls back to the flat square)' );

// draw_square_photo() itself, called directly on a real GD image + a real
// JPEG fixture built inline with imagecreatetruecolor()+imagejpeg() (no
// fixture file in tests/fixtures/ to reuse) -- confirms it draws real
// photo pixels using PTK_Focal_Point::square_crop_rect()'s already-tested
// math, without going through get_attached_file()/WordPress at all.
$photo_path = sys_get_temp_dir() . '/ptk-test-square-photo-' . uniqid() . '.jpg';
$photo_im   = imagecreatetruecolor( 200, 100 ); // 2:1 landscape, like the spec's worked example.
$red        = imagecolorallocate( $photo_im, 220, 20, 20 );
$blue       = imagecolorallocate( $photo_im, 20, 20, 220 );
imagefilledrectangle( $photo_im, 0, 0, 99, 99, $red );   // left half
imagefilledrectangle( $photo_im, 100, 0, 199, 99, $blue ); // right half
imagejpeg( $photo_im, $photo_path, 95 );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $photo_im ); }

$canvas = imagecreatetruecolor( $t::SIZE, $t::SIZE );
imagefilledrectangle( $canvas, 0, 0, $t::SIZE - 1, $t::SIZE - 1, imagecolorallocate( $canvas, 26, 47, 92 ) );
// Focal far right (100%), unzoomed: square_crop_rect() on a 200x100
// source takes the full height (crop_side 100) and slides the window to
// the right edge (avail_x = 200-100 = 100 -> crop_x = 100), i.e. a slice
// that is ENTIRELY inside the blue half.
$t::draw_square_photo_from_file( $canvas, $photo_path, 'image/jpeg', array( 'photo_focal_x' => 100, 'photo_focal_y' => 50, 'photo_zoom' => 0 ) );
$sample = imagecolorat( $canvas, (int) ( $t::SIZE / 2 ), (int) ( $t::SIZE / 2 ) );
$srgb   = array( ( $sample >> 16 ) & 0xFF, ( $sample >> 8 ) & 0xFF, $sample & 0xFF );
ptk_test_ok( $srgb[2] > $srgb[0], 'draw_square_photo: focal_x=100 crops the blue (right) half onto the canvas center, per square_crop_rect() math' );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $canvas ); }

// A non-image file (or a deleted/unreadable path): never fatal, the flat
// fill already drawn stands unchanged.
$canvas2 = imagecreatetruecolor( $t::SIZE, $t::SIZE );
imagefilledrectangle( $canvas2, 0, 0, $t::SIZE - 1, $t::SIZE - 1, imagecolorallocate( $canvas2, 26, 47, 92 ) );
$bogus_path = sys_get_temp_dir() . '/ptk-test-not-an-image-' . uniqid() . '.txt';
file_put_contents( $bogus_path, 'not a photo' );
$t::draw_square_photo_from_file( $canvas2, $bogus_path, 'text/plain', array( 'photo_focal_x' => 50, 'photo_focal_y' => 50, 'photo_zoom' => 0 ) );
$corner2 = imagecolorat( $canvas2, 6, 6 );
$rgb2    = array( ( $corner2 >> 16 ) & 0xFF, ( $corner2 >> 8 ) & 0xFF, $corner2 & 0xFF );
ptk_test_ok( $rgb2 === array( 26, 47, 92 ), 'draw_square_photo: a non-image mime is a no-op, the flat fill is untouched -- never a fatal' );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $canvas2 ); }

@unlink( $photo_path );
@unlink( $bogus_path );

// ---------------------------------------------------------------------
// Round 3.1 (spec item 4): the bar drawn behind a photo square's text
// uses the CHOSEN photo_bar color, not a fixed 50% black -- confirms the
// fix for "dark text unreadable over a photo" actually changes what gets
// drawn, not just what color is asked for.
// ---------------------------------------------------------------------
$photo_path2 = sys_get_temp_dir() . '/ptk-test-square-photo-bar-' . uniqid() . '.jpg';
$photo_im2   = imagecreatetruecolor( 100, 100 );
imagefilledrectangle( $photo_im2, 0, 0, 99, 99, imagecolorallocate( $photo_im2, 0, 255, 0 ) ); // solid green
imagejpeg( $photo_im2, $photo_path2, 95 );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $photo_im2 ); }

$canvas3 = imagecreatetruecolor( $t::SIZE, $t::SIZE );
imagefilledrectangle( $canvas3, 0, 0, $t::SIZE - 1, $t::SIZE - 1, imagecolorallocate( $canvas3, 255, 255, 255 ) );
$t::draw_square_photo_from_file( $canvas3, $photo_path2, 'image/jpeg', array(
    'photo_focal_x' => 50,
    'photo_focal_y' => 50,
    'photo_zoom'    => 0,
    'photo_bar'     => '#ff0000', // pure red
) );
$sample3 = imagecolorat( $canvas3, (int) ( $t::SIZE / 2 ), $t::SIZE - 100 ); // inside the text band
$rgb3    = array( ( $sample3 >> 16 ) & 0xFF, ( $sample3 >> 8 ) & 0xFF, $sample3 & 0xFF );
ptk_test_ok( $rgb3[0] > 200 && $rgb3[1] < 40, 'draw_square_photo_from_file: the solid RED band holds the words below the photo' );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $canvas3 ); }
@unlink( $photo_path2 );

// ---------------------------------------------------------------------
// Round 3.1 fix (item 1): the bar is a BAND, not the whole canvas -- the
// photo must stay clearly visible above PTK_Share_Image::PHOTO_BAND_TOP,
// with at most a light (<=25%) overall darkening, while a pixel BELOW
// that line is still dominated by the bar color as tested above.
// ---------------------------------------------------------------------
$photo_path3 = sys_get_temp_dir() . '/ptk-test-square-photo-band-' . uniqid() . '.jpg';
$photo_im3   = imagecreatetruecolor( 100, 100 );
imagefilledrectangle( $photo_im3, 0, 0, 99, 99, imagecolorallocate( $photo_im3, 0, 255, 0 ) ); // solid green
imagejpeg( $photo_im3, $photo_path3, 95 );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $photo_im3 ); }

$canvas4 = imagecreatetruecolor( $t::SIZE, $t::SIZE );
imagefilledrectangle( $canvas4, 0, 0, $t::SIZE - 1, $t::SIZE - 1, imagecolorallocate( $canvas4, 255, 255, 255 ) );
$t::draw_square_photo_from_file( $canvas4, $photo_path3, 'image/jpeg', array(
    'photo_focal_x' => 50,
    'photo_focal_y' => 50,
    'photo_zoom'    => 0,
    'photo_bar'     => '#ff0000', // pure red
) );

// Above the band: still recognizably the solid green photo (green channel
// clearly dominant, blue/red both low), never near-solid red.
$above = imagecolorat( $canvas4, (int) ( $t::SIZE / 2 ), max( 0, $t::PHOTO_BAND_TOP - 20 ) );
$argb  = array( ( $above >> 16 ) & 0xFF, ( $above >> 8 ) & 0xFF, $above & 0xFF );
ptk_test_ok( $argb[1] > $argb[0] && $argb[1] > 150, "draw_square_photo_from_file: above the band the photo's own green stays dominant (sampled rgb " . implode( ',', $argb ) . ')' );

// The darkening above the band is light -- at most 25% opacity black, so
// every channel should still be at least 75% of the undarkened value
// (green's undarkened channel is 255, so >= 191).
ptk_test_ok( $argb[1] >= round( 255 * 0.75 ), 'draw_square_photo_from_file: the overall darkening above the band is at most ~25% (green channel ' . $argb[1] . ' >= ' . round( 255 * 0.75 ) . ')' );

// Below the band: dominated by the bar color, same assertion shape as the
// center-pixel test above, at a point clearly inside the band.
$below = imagecolorat( $canvas4, (int) ( $t::SIZE / 2 ), $t::PHOTO_BAND_TOP + 20 );
$brgb  = array( ( $below >> 16 ) & 0xFF, ( $below >> 8 ) & 0xFF, $below & 0xFF );
ptk_test_ok( $brgb[0] > 200 && $brgb[1] < 40, 'draw_square_photo_from_file: just below PHOTO_BAND_TOP the red bar already dominates' );

// 4.5.2: the band is solid -- exactly the bar color, so the text/bar
// contrast check is what gets drawn -- and the photo above is undarkened.
ptk_test_ok( 255 === $brgb[0] && 0 === $brgb[1] && 0 === $brgb[2], 'draw_square_photo_from_file: the band is exactly the bar color (solid)' );
ptk_test_ok( $argb[1] > 245, 'draw_square_photo_from_file: the photo above the band is not darkened' );
ptk_test_ok( $t::PHOTO_BAND_TOP === (int) round( $t::SIZE * 9 / 16 ), 'PHOTO_BAND_TOP makes the photo strip 16:9, matching the adjuster' );

if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $canvas4 ); }
@unlink( $photo_path3 );

// render_png() itself can only reach draw_square_photo() through
// get_attached_file(), which needs WordPress (see the "unresolvable
// photo_id" test above) -- so render_png()'s use of photo_text/photo_bar
// is covered live (Playground GD can't draw text at all, per the spec's
// note). The drawing primitive that actually paints the bar color
// (draw_square_photo_from_file, tested above) is proven correct here.

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
if ( class_exists( 'PTK_Newsletter_Data' ) ) {
    ptk_test_ok( PTK_Share_Image::dateline( '2026-09-16' ) === 'Week of September 14', 'square names the week\'s Monday, like the masthead' );
    ptk_test_ok( PTK_Share_Image::dateline( '2026-09-13' ) === 'Week of September 14', 'a Sunday date looks ahead to the coming week' );
}
ptk_test_ok( PTK_Share_Image::dateline( 'Winter term' ) === 'Winter term', 'a non-ISO date is left alone' );
ptk_test_ok( PTK_Share_Image::dateline( '' ) === '', 'an empty date stays empty' );

// No widows. A wrapped school name must never leave one word alone on its
// last line -- "Watchung Elementary / PTA" is a real Montclair school and it
// did exactly that at 54pt before this was fixed. Width is SIZE - 2 * PAD.
$school_font = PTK_Share_Image::font_files();
$school_font = $school_font['school'];
$school_w    = PTK_Share_Image::SIZE - 2 * PTK_Share_Image::PAD;
$names = array(
    'Watchung Elementary PTA',
    'Glenfield Middle School PTA',
    'Northeast Elementary School PTA',
    'Bradford Avenue Elementary School Parent Teacher Association',
    'Charles H. Bullock Elementary School PTA',
    'Hillside Elementary School Parent Teacher Association',
    'Buzz Aldrin Middle School Parent Teacher Organization',
    'Montclair High School Parent Teacher Student Association',
    'Renaissance Middle School at Rand PTA',
    'Montclair Community Pre-K PTA',
    'Nishuane Elementary School PTA',
);
foreach ( $names as $name ) {
    list( $lines, $size ) = PTK_Share_Image::fit_block( $name, $school_font, 54, 22, $school_w, 2 );
    $last_words = preg_split( '/\s+/', trim( end( $lines ) ), -1, PREG_SPLIT_NO_EMPTY );
    ptk_test_ok(
        count( $lines ) === 1 || count( $last_words ) >= 2,
        'no widow: ' . $name . ' => ' . implode( ' / ', $lines ) . ' @' . $size . 'pt'
    );
    // Nothing lost or reordered in the process.
    ptk_test_ok( implode( ' ', $lines ) === $name, 'every word kept, in order: ' . $name );
    foreach ( $lines as $line ) {
        ptk_test_ok(
            PTK_Share_Image::text_width( $line, $school_font, $size ) <= $school_w,
            'fits the square: ' . $line . ' @' . $size . 'pt'
        );
    }
}

// Short names should not be broken just to dodge a widow -- if a name fits
// on one line at a size close to the maximum, keep it on one line.
list( $wl, $ws ) = PTK_Share_Image::fit_block( 'Watchung Elementary PTA', $school_font, 54, 22, $school_w, 2 );
ptk_test_ok( 1 === count( $wl ), 'a short name that nearly fits stays on one line rather than breaking' );
ptk_test_ok( $ws >= 40, 'and it is not shrunk to nothing to get there' );

// The rebalancing path, forced deterministically. Real names rarely hit it
// once the one-line rule has run, so choose a width that fits "Alpha Beta"
// but not "Alpha Beta Gamma": greedy wrapping would leave "Gamma" alone.
ptk_test_ok( PTK_Share_Image::is_widowed( array( 'Alpha Beta', 'Gamma' ) ) === true, 'is_widowed: one word on the last line' );
ptk_test_ok( PTK_Share_Image::is_widowed( array( 'Alpha', 'Beta Gamma' ) ) === false, 'is_widowed: two words on the last line' );
ptk_test_ok( PTK_Share_Image::is_widowed( array( 'Alpha Beta Gamma' ) ) === false, 'is_widowed: a single line is never a widow' );

$pair_w = PTK_Share_Image::text_width( 'Alpha Beta', $school_font, 54 ) + 4;
ptk_test_ok( PTK_Share_Image::text_width( 'Alpha Beta Gamma', $school_font, 54 ) > $pair_w, 'fixture: the three words really do overflow that width' );
$rw = PTK_Share_Image::wrap_text( 'Alpha Beta Gamma', $school_font, 54, $pair_w, 2 );
ptk_test_ok( $rw === array( 'Alpha', 'Beta Gamma' ), 'wrap_text pulls a word down instead of widowing: ' . implode( ' / ', $rw ) );

// Two words cannot be rebalanced one-and-one, so the size shrinks until the
// name sits on one line instead.
$two_w = PTK_Share_Image::text_width( 'Alpha Beta', $school_font, 40 ) + 2;
list( $tl, $ts ) = PTK_Share_Image::fit_block( 'Alpha Beta', $school_font, 54, 22, $two_w, 2 );
ptk_test_ok( 1 === count( $tl ), 'a two-word name shrinks onto one line rather than splitting one-and-one' );
ptk_test_ok( ! PTK_Share_Image::is_widowed( $tl ), 'and is not widowed' );

ptk_test_done();
