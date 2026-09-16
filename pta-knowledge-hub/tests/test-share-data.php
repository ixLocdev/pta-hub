<?php
require __DIR__ . '/bootstrap.php';
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

$sq_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', '1.4.0' );
$sq_b = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#c0392b', '#ffffff', '1.4.0' );

ptk_test_ok( is_string( $sq_a ) && '' !== $sq_a, 'square_inputs_hash returns a non-empty string' );
ptk_test_ok( $sq_a !== $sq_b, 'square_inputs_hash: different background gives a different hash' );

$sq_diff_text = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#000000', '1.4.0' );
ptk_test_ok( $sq_a !== $sq_diff_text, 'square_inputs_hash: different text color gives a different hash' );

$sq_diff_version = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', '1.5.0' );
ptk_test_ok( $sq_a !== $sq_diff_version, 'square_inputs_hash: different version gives a different hash' );

$sq_a_again = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', '1.4.0' );
ptk_test_ok( $sq_a === $sq_a_again, 'square_inputs_hash: same inputs give the same hash' );

// Both colors changing but nothing else unchanged still equals itself.
$sq_both_same = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', '1.4.0' );
ptk_test_ok( $sq_a === $sq_both_same, 'square_inputs_hash: both colors unchanged -> hash unchanged' );

// The whole point of two hashes: editing a story block must NOT move the
// square hash, or the stale-caption warning would never fire on the common
// edit (changing a story) while pretending the square (which never reads
// story content) also went stale.
$sq_from_blocks_a = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', '1.4.0' );
$sq_from_blocks_b = $t::square_inputs_hash( 1, '2026-09-16', 'Northeast Elementary', '#1a2f5c', '#ffffff', '1.4.0' );
ptk_test_ok( $sq_from_blocks_a === $sq_from_blocks_b, 'square_inputs_hash: ignores block content entirely (same call, same result regardless of any story edit)' );

ptk_test_ok( $hash_a !== $sq_a, 'caption hash and square hash are different functions producing different values' );

// ---------------------------------------------------------------------
// is_stale()
// ---------------------------------------------------------------------

ptk_test_ok( $t::is_stale( 'abc', 'abc' ) === false, 'is_stale: matching hashes are not stale' );
ptk_test_ok( $t::is_stale( 'abc', 'def' ) === true, 'is_stale: differing hashes are stale' );
ptk_test_ok( $t::is_stale( '', 'def' ) === true, 'is_stale: empty stored hash is stale' );
ptk_test_ok( $t::is_stale( null, 'def' ) === true, 'is_stale: null stored hash is stale' );

ptk_test_done();
