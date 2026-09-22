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

// ---------------------------------------------------------------------
// Only "ours" may be removed from here. Offering to bin somebody else's
// work, beside a card we cannot even open, would be a nasty surprise.
// ---------------------------------------------------------------------

ptk_test_ok( true === $l::removable( 'ours' ), 'we may remove what we wrote' );
ptk_test_ok( false === $l::removable( 'wordpress' ), 'not a post written in WordPress' );
ptk_test_ok( false === $l::removable( 'newsletter' ), "and not a newsletter's own post" );

// ---------------------------------------------------------------------
// Undo has to put a post back as it was. wp_untrash_post() lands one on
// 'draft' whatever it was before (4.21.0 learned this on suggestions), so
// a post that was on the website must be forced back onto it.
// ---------------------------------------------------------------------

ptk_test_ok( 'publish' === $l::status_after_undo( 'publish', 'draft' ), 'one that was up goes back up' );
ptk_test_ok( '' === $l::status_after_undo( 'draft', 'draft' ), 'one that was a draft is already right' );
ptk_test_ok( '' === $l::status_after_undo( 'publish', 'publish' ), 'and nothing is forced when WordPress got it right' );
ptk_test_ok( 'pending' === $l::status_after_undo( 'pending', 'draft' ), 'whatever it was, that is what it goes back to' );

// Nothing recorded, or something nonsensical: leave WordPress's answer alone
// rather than inventing a status for somebody's post.
ptk_test_ok( '' === $l::status_after_undo( '', 'draft' ), 'no recorded status, no forcing' );
ptk_test_ok( '' === $l::status_after_undo( 'trash', 'draft' ), 'and never back into the trash' );

// The CSS this screen leans on must never draw a one-sided accent bar.
$css = file_get_contents( __DIR__ . '/../assets/css/hub.css' );
ptk_test_ok( ! preg_match( '/border-(left|right|inline-start|inline-end)\s*:/i', $css ), 'no one-sided borders in hub.css' );

ptk_test_done();
