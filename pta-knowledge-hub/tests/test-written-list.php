<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-written-list.php';

$t = 'PTK_Written_List';

// PAGE_SLUG is its own admin page, not a list-table view.
ptk_test_ok( 'ptk-written' === $t::PAGE_SLUG, 'page slug is ptk-written' );
ptk_test_ok( 'ptk_written_trash' === $t::TRASH_ACTION, 'trash action is ptk_written_trash' );
ptk_test_ok( 'ptk_written_untrash' === $t::UNTRASH_ACTION, 'untrash action is ptk_written_untrash' );

// trim_answer(): trimmed at a word boundary, never mid-word.
ptk_test_ok( 'Short answer' === $t::trim_answer( 'Short answer' ), 'a short answer is returned untouched' );
ptk_test_ok( '' === $t::trim_answer( '' ), 'an empty answer stays empty' );
ptk_test_ok( '' === $t::trim_answer( '   ' ), 'whitespace only trims to empty' );
$long = str_repeat( 'word ', 40 ); // 200 chars
$trimmed = $t::trim_answer( $long, 20 );
ptk_test_ok( mb_strlen( $trimmed ) <= 21, 'trim_answer respects the max length (' . mb_strlen( $trimmed ) . ')' );
ptk_test_ok( "\xE2\x80\xA6" === mb_substr( $trimmed, -1 ), 'a trimmed answer ends with an ellipsis' );
ptk_test_ok( false === strpos( $trimmed, 'wor' . "\xE2\x80\xA6" ), 'trim_answer never cuts a word in half' );
ptk_test_ok( 'collapses   extra whitespace' !== $t::trim_answer( "collapses   extra\nwhitespace" ), 'whitespace is collapsed to single spaces' );
ptk_test_ok( 'collapses extra whitespace' === $t::trim_answer( "collapses   extra\nwhitespace" ), 'runs of whitespace collapse to one space' );

// meta_line(): the quiet line, plus "From the Council" only when it applies.
ptk_test_ok( "Filed as a how-to guide \xC2\xB7 updated Sep 14" === $t::meta_line( 'how-to guide', 'Sep 14', false ), 'own entry meta line has no Council suffix' );
ptk_test_ok( "Filed as a FAQ \xC2\xB7 updated Sep 14 \xC2\xB7 From the Council" === $t::meta_line( 'FAQ', 'Sep 14', true ), 'a Council entry gets the suffix' );
ptk_test_ok( "Filed as a entry \xC2\xB7 updated Sep 14" === $t::meta_line( '', 'Sep 14', false ), 'an unknown type label falls back to "entry"' );

// available_filters(): "From the Council" only where it can apply.
ptk_test_ok( array( 'all', 'ours', 'draft' ) === $t::available_filters( false ), 'no Council filter on a site that cannot have Council entries' );
ptk_test_ok( array( 'all', 'ours', 'council', 'draft' ) === $t::available_filters( true ), 'the Council filter appears where it applies' );

// filter_label().
ptk_test_ok( 'Everything' === $t::filter_label( 'all' ), 'all => Everything' );
ptk_test_ok( 'Ours' === $t::filter_label( 'ours' ), 'ours => Ours' );
ptk_test_ok( 'From the Council' === $t::filter_label( 'council' ), 'council => From the Council' );
ptk_test_ok( 'Not sent yet' === $t::filter_label( 'draft' ), 'draft => Not sent yet' );
ptk_test_ok( 'Everything' === $t::filter_label( 'nonsense' ), 'an unknown key falls back to Everything' );

// matches_filter(): the quiet filter decision, pure.
$ours_published = array( 'is_draft' => false, 'is_council' => false );
$ours_draft     = array( 'is_draft' => true, 'is_council' => false );
$council_entry  = array( 'is_draft' => false, 'is_council' => true );
foreach ( array( $ours_published, $ours_draft, $council_entry ) as $e ) {
    ptk_test_ok( true === $t::matches_filter( $e, 'all' ), '"all" matches everything' );
}
ptk_test_ok( true === $t::matches_filter( $ours_published, 'ours' ), '"ours" matches a non-Council entry' );
ptk_test_ok( false === $t::matches_filter( $council_entry, 'ours' ), '"ours" excludes a Council entry' );
ptk_test_ok( true === $t::matches_filter( $council_entry, 'council' ), '"council" matches a Council entry' );
ptk_test_ok( false === $t::matches_filter( $ours_published, 'council' ), '"council" excludes our own entry' );
ptk_test_ok( true === $t::matches_filter( $ours_draft, 'draft' ), '"draft" matches a draft' );
ptk_test_ok( false === $t::matches_filter( $ours_published, 'draft' ), '"draft" excludes a published entry' );

// search_haystack() + matches_search(): the free-text search decision.
$haystack = $t::search_haystack( 'Where do I park for drop-off?', 'Use the north lot after 8am.', 'FAQ' );
ptk_test_ok( false !== strpos( $haystack, 'drop-off' ), 'search_haystack includes the question' );
ptk_test_ok( false !== strpos( $haystack, 'north lot' ), 'search_haystack includes the answer' );
ptk_test_ok( $haystack === strtolower( $haystack ), 'search_haystack is lowercased' );
ptk_test_ok( true === $t::matches_search( $haystack, '' ), 'an empty query always matches -- nothing typed is not a filter' );
ptk_test_ok( true === $t::matches_search( $haystack, 'Drop-Off' ), 'matches_search is case-insensitive' );
ptk_test_ok( true === $t::matches_search( $haystack, 'north' ), 'matches_search matches the answer text' );
ptk_test_ok( false === $t::matches_search( $haystack, 'carnival' ), 'a word that is not there does not match' );

// can_change(): the "can this person change it" decision.
ptk_test_ok( true === $t::can_change( true ), 'can_change mirrors a true edit_post capability check' );
ptk_test_ok( false === $t::can_change( false ), 'can_change mirrors a false edit_post capability check (a locked Council copy)' );

// empty_state_copy(): two different messages.
$none_yet = $t::empty_state_copy( false, '' );
ptk_test_ok( 'Nothing here yet.' === $none_yet['title'], 'nothing written yet at all gets its own title' );
$no_match = $t::empty_state_copy( true, 'carnival' );
ptk_test_ok( false !== strpos( $no_match['title'], 'carnival' ), 'a search with no match names the search term' );
ptk_test_ok( 'Nothing here yet.' !== $no_match['title'], 'a no-match state is not the "nothing yet" state' );

ptk_test_done();
