<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-post-parts.php';
require __DIR__ . '/../includes/class-post-renderer.php';
require __DIR__ . '/../includes/class-post-writer.php';

$w = 'PTK_Post_Writer';
$r = 'PTK_Post_Renderer';

$html = $r::render( PTK_Post_Parts::sanitize( array( 'headline' => 'Bake sale Tuesday' ) ) );

// The ordinary case: what we wrote is what is there.
ptk_test_ok( false === $w::is_stale( $r::hash( $html ), $html ), 'untouched since we wrote it' );

// Somebody edited it in WordPress.
ptk_test_ok( true === $w::is_stale( $r::hash( $html ), $html . '<p>Added by hand.</p>' ), 'a hand edit is seen' );
ptk_test_ok( true === $w::is_stale( $r::hash( $html ), '' ), 'so is content emptied out' );

// Even one character. A hash that only caught big changes would be no guard at all.
ptk_test_ok( true === $w::is_stale( $r::hash( $html ), str_replace( 'Tuesday', 'Wednesday', $html ) ), 'one word changed is enough' );

// No stored hash: we cannot tell, so we do not touch it.
ptk_test_ok( true === $w::is_stale( '', $html ), 'no stored hash means leave it alone' );
ptk_test_ok( true === $w::is_stale( null, $html ), 'and so does nothing at all' );

ptk_test_done();
