<?php
/**
 * PTK_Newsletter_Linked_Post's PURE decision helpers only (round 8, Part 2)
 * -- which post statuses should create/update vs. draft the linked post.
 * The WordPress integration (save_post, wp_insert_post/wp_update_post,
 * before_delete_post, category/thumbnail assignment) needs a real request
 * and is verified in Playground instead -- see the round 8 spec's
 * "Testing" section. Title/content generation is pure reuse of
 * PTK_Newsletter_Email::subject() (tested in tests/test-newsletter-email.php)
 * and PTK_Newsletter_Renderer::last_sections() (tested in
 * tests/test-newsletter-renderer.php), so it is not re-tested here.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-newsletter-linked-post.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Newsletter_Linked_Post';

// ---------------------------------------------------------------------
// is_publishable_status() / should_draft_for_status()
// ---------------------------------------------------------------------

ptk_test_ok( $t::is_publishable_status( 'publish' ) === true, 'publish is publishable' );
ptk_test_ok( $t::is_publishable_status( 'draft' ) === false, 'draft is not publishable' );
ptk_test_ok( $t::is_publishable_status( 'future' ) === false, 'a scheduled post is not treated as publishable yet' );
ptk_test_ok( $t::is_publishable_status( 'trash' ) === false, 'trash is not publishable' );

foreach ( array( 'draft', 'pending', 'private', 'trash', 'auto-draft' ) as $status ) {
    ptk_test_ok( $t::should_draft_for_status( $status ) === true, "'$status' should draft an existing linked post" );
}
ptk_test_ok( $t::should_draft_for_status( 'publish' ) === false, 'publish never drafts the linked post' );
ptk_test_ok( $t::should_draft_for_status( 'future' ) === false, 'a scheduled newsletter does not yet draft the linked post (not published, not one of the draft-triggering statuses either -- caller only drafts when a linked post already exists)' );

// A status must be exactly one or the other, never both -- otherwise a
// newsletter could both create/update AND immediately draft its own
// linked post on the same save.
foreach ( array( 'publish', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'future' ) as $status ) {
    $both = $t::is_publishable_status( $status ) && $t::should_draft_for_status( $status );
    ptk_test_ok( $both === false, "'$status' is never both publishable and draft-triggering" );
}

ptk_test_done();
