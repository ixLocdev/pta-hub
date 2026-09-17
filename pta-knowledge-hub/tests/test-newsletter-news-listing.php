<?php
/**
 * PTK_Newsletter_News_Listing's PURE decision helpers only (round 8, Part 1)
 * -- the should-include query decisions, category resolution, and the
 * thumbnail-overwrite guard. The WordPress integration (pre_get_posts,
 * fl_builder_loop_query_args, uabb_blog_posts_query_args, save_post,
 * register_taxonomy_for_object_type, wp_insert_term) needs a real request
 * and is verified in Playground instead -- see the round 8 spec's
 * "Testing" section.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-newsletter-data.php';
require_once __DIR__ . '/../includes/class-share-text.php';
require_once __DIR__ . '/../includes/class-newsletter-seo.php';
require_once __DIR__ . '/../includes/class-focal-point.php';
require_once __DIR__ . '/../includes/class-share-data.php';
require_once __DIR__ . '/../includes/class-newsletter-news-listing.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Newsletter_News_Listing';

// ---------------------------------------------------------------------
// post_type_is_post_or_empty()
// ---------------------------------------------------------------------

ptk_test_ok( $t::post_type_is_post_or_empty( '' ) === true, 'empty string counts as post-or-empty' );
ptk_test_ok( $t::post_type_is_post_or_empty( null ) === true, 'null counts as post-or-empty' );
ptk_test_ok( $t::post_type_is_post_or_empty( 'post' ) === true, "'post' counts as post-or-empty" );
ptk_test_ok( $t::post_type_is_post_or_empty( array( 'post' ) ) === true, "['post'] counts as post-or-empty" );
ptk_test_ok( $t::post_type_is_post_or_empty( 'page' ) === false, "'page' is not post-or-empty" );
ptk_test_ok( $t::post_type_is_post_or_empty( 'pta_knowledge' ) === false, 'another CPT is never touched' );
ptk_test_ok( $t::post_type_is_post_or_empty( array( 'post', 'page' ) ) === false, 'a mixed array is not post-or-empty' );
ptk_test_ok( $t::post_type_is_post_or_empty( 'any' ) === false, "'any' is left alone (never narrowed)" );

// ---------------------------------------------------------------------
// slug_is_newsletter_category()
// ---------------------------------------------------------------------

ptk_test_ok( $t::slug_is_newsletter_category( 'newsletter' ) === true, "'newsletter' matches" );
ptk_test_ok( $t::slug_is_newsletter_category( 'Newsletters' ) === true, "'Newsletters' matches case-insensitively" );
ptk_test_ok( $t::slug_is_newsletter_category( ' newsletter ' ) === true, 'whitespace is trimmed' );
ptk_test_ok( $t::slug_is_newsletter_category( 'events' ) === false, 'a different category slug does not match' );
ptk_test_ok( $t::slug_is_newsletter_category( '' ) === false, 'empty slug does not match' );
ptk_test_ok( $t::slug_is_newsletter_category( null ) === false, 'null does not match' );

// ---------------------------------------------------------------------
// should_include_main_query()
// ---------------------------------------------------------------------

function ctx( $overrides = array() ) {
    return array_merge( array(
        'setting_on'             => true,
        'is_admin'               => false,
        'is_main_query'          => true,
        'post_type'              => '',
        'is_home'                => false,
        'is_date'                => false,
        'is_author'              => false,
        'is_newsletter_category' => false,
        'is_feed'                => false,
        'is_singular'            => false,
        'is_search'              => false,
    ), $overrides );
}

ptk_test_ok( $t::should_include_main_query( ctx( array( 'setting_on' => false, 'is_home' => true ) ) ) === false, 'setting off -- never included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_admin' => true, 'is_home' => true ) ) ) === false, 'admin -- never included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_main_query' => false, 'is_home' => true ) ) ) === false, 'a secondary query is never touched' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'post_type' => 'pta_knowledge', 'is_home' => true ) ) ) === false, 'a query already naming another CPT is never touched' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_search' => true ) ) ) === false, 'search is deliberately left alone (see spec)' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_home' => true ) ) ) === true, 'blog home/posts page is included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_date' => true ) ) ) === true, 'a date archive is included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_author' => true ) ) ) === true, 'an author archive is included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_newsletter_category' => true ) ) ) === true, 'the Newsletter category archive is included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_feed' => true ) ) ) === true, 'a main feed request is included' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'is_feed' => true, 'is_singular' => true ) ) ) === false, "a single post's own comment feed is not included" );
ptk_test_ok( $t::should_include_main_query( ctx() ) === false, 'a query matching nothing in particular is left alone' );
ptk_test_ok( $t::should_include_main_query( ctx( array( 'post_type' => 'post', 'is_home' => true ) ) ) === true, "post_type explicitly 'post' still counts as post-or-empty" );

// ---------------------------------------------------------------------
// should_include_builder_query()
// ---------------------------------------------------------------------

ptk_test_ok( $t::should_include_builder_query( 'post' ) === true, "exactly 'post' is included" );
ptk_test_ok( $t::should_include_builder_query( '' ) === false, 'empty post_type is left alone (module may resolve its own default)' );
ptk_test_ok( $t::should_include_builder_query( array( 'post' ) ) === false, 'an array post_type is left alone' );
ptk_test_ok( $t::should_include_builder_query( 'page' ) === false, "a module configured for 'page' is left alone" );
ptk_test_ok( $t::should_include_builder_query( 'pta_knowledge' ) === false, "a module configured for another CPT is left alone" );

// ---------------------------------------------------------------------
// should_auto_update_thumbnail()
// ---------------------------------------------------------------------

ptk_test_ok( $t::should_auto_update_thumbnail( 0, 0, 0 ) === 'leave', 'nothing set, nothing to set -- leave' );
ptk_test_ok( $t::should_auto_update_thumbnail( 0, 0, 42 ) === 'set', 'empty current, a candidate exists -- set it' );
ptk_test_ok( $t::should_auto_update_thumbnail( 42, 42, 42 ) === 'leave', 'already the candidate -- nothing to do' );
ptk_test_ok( $t::should_auto_update_thumbnail( 42, 42, 99 ) === 'set', 'the auto choice changed -- follow it' );
ptk_test_ok( $t::should_auto_update_thumbnail( 7, 42, 99 ) === 'leave', 'current does not match what we set last -- a manual pick, never overwritten' );
ptk_test_ok( $t::should_auto_update_thumbnail( 7, 0, 99 ) === 'leave', 'a thumbnail we never set (auto_prev 0) is treated as manual' );
ptk_test_ok( $t::should_auto_update_thumbnail( 42, 42, 0 ) === 'clear', 'our own thumbnail, no candidate any more -- clear it' );
ptk_test_ok( $t::should_auto_update_thumbnail( 0, 0, 0 ) === 'leave', 'nothing to clear -- leave (idempotent)' );

// ---------------------------------------------------------------------
// resolve_category_choice()
// ---------------------------------------------------------------------

$cats = array(
    array( 'term_id' => 3, 'name' => 'Events', 'slug' => 'events' ),
    array( 'term_id' => 9, 'name' => 'Newsletter', 'slug' => 'newsletter' ),
);
ptk_test_ok( $t::resolve_category_choice( $cats ) === 9, 'an exact slug match is found' );

$cats2 = array(
    array( 'term_id' => 3, 'name' => 'Events', 'slug' => 'events' ),
    array( 'term_id' => 11, 'name' => 'Newsletters', 'slug' => 'nl-updates' ),
);
ptk_test_ok( $t::resolve_category_choice( $cats2 ) === 11, 'a name-only match (plural, different slug) is found' );

$cats3 = array( array( 'term_id' => 5, 'name' => 'NEWSLETTER', 'slug' => 'newsletter-x' ) );
ptk_test_ok( $t::resolve_category_choice( $cats3 ) === 5, 'matching is case-insensitive' );

ptk_test_ok( $t::resolve_category_choice( array( array( 'term_id' => 3, 'name' => 'Events', 'slug' => 'events' ) ) ) === 0, 'no match -- 0 means create one' );
ptk_test_ok( $t::resolve_category_choice( array() ) === 0, 'no categories at all -- 0' );

ptk_test_done();
