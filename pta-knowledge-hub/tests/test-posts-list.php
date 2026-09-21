<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-posts-list.php';

$l = 'PTK_Posts_List';

// ---------------------------------------------------------------------
// kind() -- which of the three kinds of post this is. A school's `post`
// type is full of things this screen did not write, and each kind is
// treated differently (see the 2026-09-21 spec, "What the list actually
// contains").
// ---------------------------------------------------------------------

ptk_test_ok( 'ours' === $l::kind( true, false ), 'parts and nothing else: we wrote it' );
ptk_test_ok( 'wordpress' === $l::kind( false, false ), 'no parts at all: written in WordPress' );
ptk_test_ok( 'newsletter' === $l::kind( false, true ), 'made by a newsletter' );

// A newsletter's own post is never ours to offer, however it came to have
// parts: it is maintained by the newsletter and rewritten every time that
// newsletter is saved.
ptk_test_ok( 'newsletter' === $l::kind( true, true ), 'a newsletter post with parts is still the newsletter\'s' );

// Anything truthy or falsy, not just booleans -- these come from meta reads.
ptk_test_ok( 'ours' === $l::kind( array( 'headline' => 'x' ), '' ), 'a parts array counts as parts' );
ptk_test_ok( 'newsletter' === $l::kind( '', '41' ), 'a newsletter id as a string counts' );
ptk_test_ok( 'wordpress' === $l::kind( '', '' ), 'two empty strings are a WordPress post' );
ptk_test_ok( 'wordpress' === $l::kind( null, null ), 'so is nothing at all' );
ptk_test_ok( 'wordpress' === $l::kind( '0', '0' ), 'and so is a stored zero' );

// ---------------------------------------------------------------------
// Only "ours" can be opened here or removed here.
// ---------------------------------------------------------------------

ptk_test_ok( true === $l::opens_here( 'ours' ), 'we can open what we wrote' );
ptk_test_ok( false === $l::opens_here( 'wordpress' ), 'a WordPress post opens in WordPress' );
ptk_test_ok( false === $l::opens_here( 'newsletter' ), "and a newsletter's post is not offered at all" );

ptk_test_ok( true === $l::listed( 'ours' ), 'ours is listed' );
ptk_test_ok( true === $l::listed( 'wordpress' ), 'so is a post written in WordPress -- with a reason' );
ptk_test_ok( false === $l::listed( 'newsletter' ), 'a newsletter byproduct is not listed at all' );

// The CSS this screen leans on must never draw a one-sided accent bar.
$css = file_get_contents( __DIR__ . '/../assets/css/hub.css' );
ptk_test_ok( ! preg_match( '/border-(left|right|inline-start|inline-end)\s*:/i', $css ), 'no one-sided borders in hub.css' );

ptk_test_done();
