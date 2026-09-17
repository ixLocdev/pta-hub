<?php
/**
 * PTK_Newsletter_SEO's PURE selection logic only (round 3.2, Part 2) --
 * which image, which description, and the shared truncate/strip-emoji
 * helpers. The WordPress-integration half (Rank Math/Yoast filters,
 * wp_head fallback) needs a real request context and is verified live /
 * in Playground instead -- see the round 3.2 spec's "Verify" section.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-text.php';
require __DIR__ . '/../includes/class-newsletter-seo.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Newsletter_SEO';

// ---------------------------------------------------------------------
// choose_image_source(): top story photo, else share picture's own
// background photo, else the generated/uploaded share picture, else none.
// ---------------------------------------------------------------------

$featured_block = array( 'type' => 'featured', 'data' => array( 'image_id' => 42 ) );
$square         = array( 'image_id' => 7, 'custom' => false );
$square_photo   = array( 'photo_id' => 9 );

ptk_test_ok(
    $t::choose_image_source( array( $featured_block ), $square, $square_photo ) === array( 'type' => 'featured', 'id' => 42 ),
    'the top story photo wins when present'
);

$no_featured = array( array( 'type' => 'featured', 'data' => array( 'image_id' => 0 ) ) );
ptk_test_ok(
    $t::choose_image_source( $no_featured, $square, $square_photo ) === array( 'type' => 'square_photo', 'id' => 9 ),
    'falls back to the share picture\'s background photo'
);

ptk_test_ok(
    $t::choose_image_source( $no_featured, $square, array( 'photo_id' => 0 ) ) === array( 'type' => 'square', 'id' => 7 ),
    'falls back to the generated/uploaded share picture'
);

ptk_test_ok(
    $t::choose_image_source( $no_featured, array( 'image_id' => 0, 'custom' => false ), array( 'photo_id' => 0 ) ) === array( 'type' => 'none', 'id' => 0 ),
    'none when nothing at all is set -- caller leaves the SEO plugin\'s default alone'
);

ptk_test_ok(
    $t::choose_image_source( array(), $square, $square_photo ) === array( 'type' => 'square_photo', 'id' => 9 ),
    'no featured block at all is treated the same as an empty one'
);

// ---------------------------------------------------------------------
// choose_description(): the one-line summary, else the announcement
// headline, else "Newsletter № 041 · Week of September 14".
// ---------------------------------------------------------------------

$with_summary = array(
    array( 'type' => 'header', 'data' => array( 'summary' => 'ASE registration is open this week.' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Never used' ) ),
);
ptk_test_ok(
    $t::choose_description( $with_summary, '041', 'Week of September 14' ) === 'ASE registration is open this week.',
    'the one-line summary wins when present'
);

$with_announce = array(
    array( 'type' => 'header', 'data' => array( 'summary' => '' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Film on the Field moves to Friday.' ) ),
);
ptk_test_ok(
    $t::choose_description( $with_announce, '041', 'Week of September 14' ) === 'Film on the Field moves to Friday.',
    'falls back to the announcement headline'
);

ptk_test_ok(
    $t::choose_description( array(), '041', 'Week of September 14' ) === 'Newsletter № 041 · Week of September 14',
    'falls back to "Newsletter № 041 · Week of September 14"'
);

ptk_test_ok(
    $t::choose_description( array(), '', 'Week of September 14' ) === 'Week of September 14',
    'the dateline alone is used when there is no issue number'
);

ptk_test_ok(
    $t::choose_description( array(), '', '' ) === '',
    'empty string ("nothing to say") when everything is blank'
);

// A summary that is only whitespace, or HTML that strips to nothing, must
// not win over a real announcement headline.
$blank_summary = array(
    array( 'type' => 'header', 'data' => array( 'summary' => '   ' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Real headline.' ) ),
);
ptk_test_ok(
    $t::choose_description( $blank_summary, '041', 'Week of September 14' ) === 'Real headline.',
    'a blank summary does not win over a real announcement headline'
);

// ---------------------------------------------------------------------
// truncate() / strip_emoji() / build_description()
// ---------------------------------------------------------------------

ptk_test_ok( $t::truncate( 'Short.' ) === 'Short.', 'a short string is untouched' );

$long = str_repeat( 'word ', 40 ); // 200 chars
$cut  = $t::truncate( $long, 40 );
ptk_test_ok( mb_strlen( $cut ) <= 41, 'truncate stays near the requested length (plus the ellipsis)' );
ptk_test_ok( mb_substr( $cut, -1 ) === "\xE2\x80\xA6" || substr( $cut, -3 ) === '...', 'truncate ends with an ellipsis' );
ptk_test_ok( strpos( $cut, ' word' ) === false || substr_count( $cut, 'word' ) < substr_count( $long, 'word' ), 'truncate cuts at a word boundary, not mid-word' );

ptk_test_ok( $t::strip_emoji( 'Great news! 🎉🎈' ) === 'Great news! ', 'emoji are stripped' );

ptk_test_ok(
    $t::build_description( array(), '041', 'Week of September 14' ) === 'Newsletter № 041 · Week of September 14',
    'build_description() runs choose_description() through strip_emoji()+truncate() and returns it unshortened when it already fits'
);

ptk_test_ok( $t::build_description( array(), '', '' ) === '', 'build_description() is "" when there is nothing to say' );

$long_summary = array(
    array( 'type' => 'header', 'data' => array( 'summary' => str_repeat( 'Registration is open for After School Enrichment. ', 5 ) ) ),
);
$built = $t::build_description( $long_summary, '041', 'Week of September 14' );
ptk_test_ok( mb_strlen( $built ) <= PTK_Newsletter_SEO::DESC_MAX + 1, 'a long summary is trimmed to roughly DESC_MAX characters' );

ptk_test_done();
