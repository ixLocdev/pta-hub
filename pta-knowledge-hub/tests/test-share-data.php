<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-focal-point.php';
require __DIR__ . '/../includes/class-share-text.php';
require __DIR__ . '/../includes/class-share-data.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Data';

// ---------------------------------------------------------------------
// caption_inputs_hash()
// ---------------------------------------------------------------------

$blocks_a = array( array( 'type' => 'header', 'data' => array( 'headline' => 'Hi' ) ) );
$blocks_b = array( array( 'type' => 'header', 'data' => array( 'headline' => 'Bye' ) ) );

$hash_a = $t::caption_inputs_hash( $blocks_a, 'https://x.test/n/1', 1, '2026-09-16', 'Northeast Elementary' );
$hash_b = $t::caption_inputs_hash( $blocks_b, 'https://x.test/n/1', 1, '2026-09-16', 'Northeast Elementary' );

ptk_test_ok( is_string( $hash_a ) && '' !== $hash_a, 'caption_inputs_hash returns a non-empty string' );
ptk_test_ok( $hash_a !== $hash_b, 'caption_inputs_hash: different blocks give different hashes' );

$hash_a_again = $t::caption_inputs_hash( $blocks_a, 'https://x.test/n/1', 1, '2026-09-16', 'Northeast Elementary' );
ptk_test_ok( $hash_a === $hash_a_again, 'caption_inputs_hash: same inputs give the same hash' );

$hash_diff_url = $t::caption_inputs_hash( $blocks_a, 'https://x.test/n/2', 1, '2026-09-16', 'Northeast Elementary' );
ptk_test_ok( $hash_a !== $hash_diff_url, 'caption_inputs_hash: different url gives a different hash' );

$hash_diff_issue = $t::caption_inputs_hash( $blocks_a, 'https://x.test/n/1', 2, '2026-09-16', 'Northeast Elementary' );
ptk_test_ok( $hash_a !== $hash_diff_issue, 'caption_inputs_hash: different issue gives a different hash' );

// ---------------------------------------------------------------------
// square_inputs_hash()
// ---------------------------------------------------------------------

$sq_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.4.0' );
$sq_b = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#c0392b', '#ffffff', 0, 50, 50, 0, '1.4.0' );

ptk_test_ok( is_string( $sq_a ) && '' !== $sq_a, 'square_inputs_hash returns a non-empty string' );
ptk_test_ok( $sq_a !== $sq_b, 'square_inputs_hash: different background gives a different hash' );

$sq_diff_text = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#000000', 0, 50, 50, 0, '1.4.0' );
ptk_test_ok( $sq_a !== $sq_diff_text, 'square_inputs_hash: different text color gives a different hash' );

$sq_diff_version = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.5.0' );
ptk_test_ok( $sq_a !== $sq_diff_version, 'square_inputs_hash: different version gives a different hash' );

$sq_a_again = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.4.0' );
ptk_test_ok( $sq_a === $sq_a_again, 'square_inputs_hash: same inputs give the same hash' );

// Both colors changing but nothing else unchanged still equals itself.
$sq_both_same = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.4.0' );
ptk_test_ok( $sq_a === $sq_both_same, 'square_inputs_hash: both colors unchanged -> hash unchanged' );

// The whole point of two hashes: editing a story block must NOT move the
// square hash, or the stale-caption warning would never fire on the common
// edit (changing a story) while pretending the square (which never reads
// story content) also went stale.
$sq_from_blocks_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.4.0' );
$sq_from_blocks_b = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.4.0' );
ptk_test_ok( $sq_from_blocks_a === $sq_from_blocks_b, 'square_inputs_hash: ignores block content entirely (same call, same result regardless of any story edit)' );

ptk_test_ok( $hash_a !== $sq_a, 'caption hash and square hash are different functions producing different values' );

// Round 3: the four photo inputs join the hash. No normalization when
// photo_id is 0 -- every input is hashed as given, accepting that a
// no-op focal/zoom edit on a photo-less square could theoretically mark
// it stale even though nothing visible changed (the class's existing
// "when in doubt, regenerate" bias, matching is_stale()'s own "no
// baseline -> stale by definition").
$sq_photo_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 12, 50, 50, 0, '1.4.0' );
$sq_photo_b = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 13, 50, 50, 0, '1.4.0' );
ptk_test_ok( $sq_photo_a !== $sq_photo_b, 'square_inputs_hash: different photo_id gives a different hash' );

$sq_photo_focal_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 12, 30, 50, 0, '1.4.0' );
ptk_test_ok( $sq_photo_a !== $sq_photo_focal_a, 'square_inputs_hash: different photo_focal_x gives a different hash' );

$sq_photo_zoom_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 12, 50, 50, 175, '1.4.0' );
ptk_test_ok( $sq_photo_a !== $sq_photo_zoom_a, 'square_inputs_hash: different photo_zoom gives a different hash' );

// No photo (photo_id 0) with different focal/zoom still hashes as given
// (not normalized away) -- see the comment above.
$sq_no_photo_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 10, 90, 200, '1.4.0' );
$sq_no_photo_b = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', 0, 50, 50, 0, '1.4.0' );
ptk_test_ok( $sq_no_photo_a !== $sq_no_photo_b, 'square_inputs_hash: photo_id 0 does NOT normalize focal/zoom out of the hash' );

// ---------------------------------------------------------------------
// The square's background-photo storage (get_square_photo() reads via
// WordPress meta -- see class-share-image.php's tests for render_png()'s
// use of the returned values; here we only check the pure clamp/sanitize
// PTK_Focal_Point already tests is what get_square_photo() relies on).
// ---------------------------------------------------------------------

ptk_test_ok( PTK_Focal_Point::clamp_percent( '' ) === 50, 'get_square_photo() building block: blank meta value clamps to center' );
ptk_test_ok( PTK_Focal_Point::sanitize_zoom( '' ) === 0, 'get_square_photo() building block: blank meta value sanitizes to unzoomed' );

// ---------------------------------------------------------------------
// is_stale()
// ---------------------------------------------------------------------

ptk_test_ok( $t::is_stale( 'abc', 'abc' ) === false, 'is_stale: matching hashes are not stale' );
ptk_test_ok( $t::is_stale( 'abc', 'def' ) === true, 'is_stale: differing hashes are stale' );
ptk_test_ok( $t::is_stale( '', 'def' ) === true, 'is_stale: empty stored hash is stale' );
ptk_test_ok( $t::is_stale( null, 'def' ) === true, 'is_stale: null stored hash is stale' );

ptk_test_done();
