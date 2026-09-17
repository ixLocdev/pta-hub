<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-post-importer.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Post_Importer';

// ---------------------------------------------------------------------
// strip_emoji()
// ---------------------------------------------------------------------
ptk_test_ok( 'Bake Sale Friday' === $t::strip_emoji( '🧁 Bake Sale 🎉 Friday' ), 'emoji stripped, words collapsed' );
ptk_test_ok( 'Plain title' === $t::strip_emoji( 'Plain title' ), 'no emoji: unchanged' );

// ---------------------------------------------------------------------
// parse_date_from_title() -- handoff §8 real-world patterns
// ---------------------------------------------------------------------
$issue = '2026-11-10';

$r = $t::parse_date_from_title( '11/4 | Election Day Bake Sale', $issue );
ptk_test_ok( '2026-11-04' === $r['date'], 'Watchung-style "11/4 | Title" -> date parsed (year inferred close to issue)' );
ptk_test_ok( 'Election Day Bake Sale' === $r['title'], 'leading M/D stripped from title' );

$r = $t::parse_date_from_title( 'Bake Sale – Friday March 13th 7-9pm', '2026-03-01' );
ptk_test_ok( '2026-03-13' === $r['date'], 'trailing "– Weekday Month Dth time" parsed' );
ptk_test_ok( 'Bake Sale' === $r['title'], 'trailing date clause stripped from title' );

$r = $t::parse_date_from_title( 'JUNE 25: Field Day', '2026-06-01' );
ptk_test_ok( '2026-06-25' === $r['date'], 'Montclair-High-style "MONTH D: Title" parsed' );
ptk_test_ok( 'Field Day' === $r['title'], 'leading MONTH D: stripped from title' );

$r = $t::parse_date_from_title( 'Week of September 14', '2026-09-01' );
ptk_test_ok( '' === $r['date'], '"Week of …" is left alone, no single date extracted' );
ptk_test_ok( 'Week of September 14' === $r['title'], '"Week of …" title unchanged' );

$r = $t::parse_date_from_title( 'Just a regular title', $issue );
ptk_test_ok( '' === $r['date'], 'no date pattern -> no date' );
ptk_test_ok( 'Just a regular title' === $r['title'], 'no date pattern -> title unchanged' );

// ---------------------------------------------------------------------
// infer_year() -- next occurrence on/after (issue date - 7 days)
// ---------------------------------------------------------------------
ptk_test_ok( '2026-11-04' === $t::infer_year( 11, 4, '2026-11-10' ), 'date just before issue (within the 7-day back-window) stays this year' );
ptk_test_ok( '2027-01-15' === $t::infer_year( 1, 15, '2026-11-10' ), 'date well before issue rolls to next year' );
ptk_test_ok( '2026-12-25' === $t::infer_year( 12, 25, '2026-11-10' ), 'date after issue stays this year' );
ptk_test_ok( '' === $t::infer_year( 2, 30, '2026-11-10' ), 'impossible date (Feb 30) -> empty' );

// ---------------------------------------------------------------------
// parse_when_where()
// ---------------------------------------------------------------------
$text = "Join us for the fall festival.\n\nWhen: Saturday, Oct 3, 10am–2pm\nWhere: Northeast gym\n\nSee you there!";
$ww   = $t::parse_when_where( $text, '2026-09-20' );
ptk_test_ok( '2026-10-03' === $ww['date'], 'When: line date parsed' );
ptk_test_ok( false !== strpos( $ww['time'], '10am' ), 'When: line time parsed' );
ptk_test_ok( 'Northeast gym' === $ww['where'], 'Where: line parsed' );

// ---------------------------------------------------------------------
// html_to_text() / is_trivial_content() / usable_text()
// ---------------------------------------------------------------------
$html = '<p>First paragraph.</p><p>Second one.</p><ul><li>One</li><li>Two</li></ul>';
$text = $t::html_to_text( $html );
ptk_test_ok( false !== strpos( $text, "First paragraph.\n\nSecond one." ), 'paragraphs separated by a blank line' );
ptk_test_ok( false !== strpos( $text, '• One' ), 'list items become bullet lines' );

ptk_test_ok( true === $t::is_trivial_content( '' ), 'empty content is trivial' );
ptk_test_ok( true === $t::is_trivial_content( '   ' ), 'whitespace-only content is trivial' );
ptk_test_ok( true === $t::is_trivial_content( '[fl_builder_insert_layout id="123"]' ), 'Beaver Builder shortcode wrapper is trivial' );
ptk_test_ok( false === $t::is_trivial_content( '<p>Real words here.</p>' ), 'real paragraph content is not trivial' );

ptk_test_ok( 'From the excerpt.' === $t::usable_text( '[fl_builder_insert_layout id="1"]', 'From the excerpt.' ), 'trivial content falls back to the excerpt' );
ptk_test_ok( 'Real content.' === $t::usable_text( '<p>Real content.</p>', 'Ignored excerpt.' ), 'non-trivial content wins over the excerpt' );

// ---------------------------------------------------------------------
// first_link() / is_pointer_post()
// ---------------------------------------------------------------------
$html = '<p>Register: <a href="https://forms.gle/abc123">here</a></p>';
ptk_test_ok( 'https://forms.gle/abc123' === $t::first_link( $html ), 'first external link found' );

$html2 = '<p>See our <a href="/volunteer/">volunteer page</a> and then <a href="https://forms.gle/xyz">sign up</a>.</p>';
ptk_test_ok( 'https://forms.gle/xyz' === $t::first_link( $html2, 'northeastpta.org' ), 'internal link skipped in favor of external when home_host given' );

ptk_test_ok( true === $t::is_pointer_post( 'Register: https://forms.gle/abc123', 'https://forms.gle/abc123' ), 'short body + link = pointer post' );
ptk_test_ok( false === $t::is_pointer_post( str_repeat( 'word ', 100 ), 'https://forms.gle/abc123' ), 'long body is not a pointer post even with a link' );

// ---------------------------------------------------------------------
// shorten()
// ---------------------------------------------------------------------
$short = $t::shorten( "One paragraph." );
ptk_test_ok( 'One paragraph.' === $short['text'] && false === $short['shortened'], 'short text passes through unshortened' );

$three_paras = "Para one is short.\n\nPara two is also short.\n\nPara three should be dropped.";
$s = $t::shorten( $three_paras );
ptk_test_ok( false === strpos( $s['text'], 'Para three' ), 'only the first two paragraphs are kept' );
ptk_test_ok( true === $s['shortened'], 'dropping a paragraph marks shortened=true' );

$long = 'This is a sentence that goes on. ' . str_repeat( 'More filler words here to pad it out. ', 20 ) . 'Final sentence.';
$s2   = $t::shorten( $long, 100 );
ptk_test_ok( true === $s2['shortened'], 'long text is marked shortened' );
ptk_test_ok( '.' === substr( trim( $s2['text'] ), -1 ) || '…' === mb_substr( trim( $s2['text'] ), -1 ), 'shortened text ends at a sentence boundary or an ellipsis' );

// ---------------------------------------------------------------------
// detect_sections() -- Bradford (H1s) and Montclair High (ALL-CAPS) styles
// ---------------------------------------------------------------------
$bradford = '<h1>BAKE SALE</h1><p>When: Friday. Where: Cafeteria.</p><h1>BOOK FAIR</h1><p>All week in the library.</p><h1>PICTURE DAY</h1><p>Bring a smile.</p>';
$sections = $t::detect_sections( $bradford );
ptk_test_ok( 3 === count( $sections ), 'Bradford-style: 3 H1 sections detected' );
ptk_test_ok( 'BAKE SALE' === $sections[0]['heading'], 'first H1 section heading captured' );
ptk_test_ok( false !== strpos( $sections[0]['text'], 'Where: Cafeteria' ), 'first H1 section body captured' );

$montclair = "BAKE SALE\n\nDetails about the bake sale go here.\n\nBOOK FAIR\n\nDetails about the book fair go here in more than a few words.";
$sections2 = $t::detect_sections( $montclair );
ptk_test_ok( 2 === count( $sections2 ), 'Montclair-High-style: 2 ALL-CAPS sections detected (no HTML headings)' );
ptk_test_ok( 'BAKE SALE' === $sections2[0]['heading'], 'ALL-CAPS heading captured' );

$single = '<h1>Just one heading</h1><p>Body text.</p>';
ptk_test_ok( array() === $t::detect_sections( $single ), 'a single heading is not treated as a roundup' );

$plain = '<p>Just a normal single-topic post with a couple of paragraphs.</p><p>Nothing that looks like a section heading here.</p>';
ptk_test_ok( array() === $t::detect_sections( $plain ), 'ordinary post has no detected sections' );

// ---------------------------------------------------------------------
// classify()
// ---------------------------------------------------------------------
ptk_test_ok( 'event' === $t::classify( array( 'has_event_date' => true, 'has_image' => true, 'paragraph_count' => 3 ) ), 'a parseable date always wins -> event' );
ptk_test_ok( 'story' === $t::classify( array( 'has_event_date' => false, 'has_image' => true, 'paragraph_count' => 0 ) ), 'featured image, no date -> story' );
ptk_test_ok( 'story' === $t::classify( array( 'has_event_date' => false, 'has_image' => false, 'paragraph_count' => 2 ) ), '2+ paragraphs, no date/image -> story' );
ptk_test_ok( 'quick_note' === $t::classify( array( 'has_event_date' => false, 'has_image' => false, 'paragraph_count' => 1 ) ), 'short, no date, no image -> quick note' );

// ---------------------------------------------------------------------
// build_suggestion() -- realistic fixture posts
// ---------------------------------------------------------------------

// Watchung-style: flyer-only, no body text, just an image + date title.
$flyer_post = array(
    'id'        => 101,
    'title'     => '11/4 | Election Day Bake Sale',
    'date'      => '2026-11-01',
    'content'   => '',
    'excerpt'   => '',
    'permalink' => 'https://example.org/?p=101',
    'image_id'  => 55,
    'home_host' => 'example.org',
);
$s = $t::build_suggestion( $flyer_post, '2026-11-10' );
ptk_test_ok( 'event' === $s['type'], 'flyer post with a parseable date defaults to event' );
ptk_test_ok( '2026-11-04' === $s['date'], 'flyer post date carried through' );
ptk_test_ok( 'Election Day Bake Sale' === $s['title'], 'flyer post title cleaned' );
ptk_test_ok( 101 === $s['source_post'], 'source_post id carried through' );

// Edgemont/Glenfield-style one-event post: sentence + link + image, no explicit event keywords in title.
$story_post = array(
    'id'        => 102,
    'title'     => 'Fall Festival Fun',
    'date'      => '2026-10-01',
    'content'   => '<p>Come one, come all to the Fall Festival! We will have games, food, and music for the whole family.</p><p>Bring your friends and neighbors for an afternoon of fun on the field.</p>',
    'excerpt'   => '',
    'permalink' => 'https://example.org/?p=102',
    'image_id'  => 60,
    'home_host' => 'example.org',
);
$s = $t::build_suggestion( $story_post, '2026-10-05' );
ptk_test_ok( 'story' === $s['type'], 'story-like post (image + 2 paragraphs) defaults to story' );
ptk_test_ok( 'https://example.org/?p=102' === $s['link_url'], 'story link falls back to the permalink' );
ptk_test_ok( 'Read more' === $s['link_text'], 'story link wording is "Read more"' );

// Pointer post: short body + external registration link.
$pointer_post = array(
    'id'        => 103,
    'title'     => 'Spring Fling Volunteers',
    'date'      => '2026-05-01',
    'content'   => '<p>Register: <a href="https://forms.gle/abc123">sign up here</a></p>',
    'excerpt'   => '',
    'permalink' => 'https://example.org/?p=103',
    'image_id'  => 0,
    'home_host' => 'example.org',
);
$s = $t::build_suggestion( $pointer_post, '2026-05-05' );
ptk_test_ok( 'https://forms.gle/abc123' === $s['link_url'], 'pointer post uses its own link, not the permalink' );
ptk_test_ok( 'quick_note' === $s['type'], 'short pointer post with no date/image defaults to quick note' );

// Weekly roundup: offers a split.
$roundup_post = array(
    'id'        => 104,
    'title'     => 'PTA Newsletter – Week of Nov 10',
    'date'      => '2026-11-08',
    'content'   => $bradford,
    'excerpt'   => '',
    'permalink' => 'https://example.org/?p=104',
    'image_id'  => 0,
    'home_host' => 'example.org',
);
$s = $t::build_suggestion( $roundup_post, '2026-11-10' );
ptk_test_ok( true === $s['can_split'], 'weekly roundup with 3 headings offers a split' );
ptk_test_ok( 3 === count( $s['split_items'] ), 'split_items array has all 3 detected sections, pre-built server-side' );
ptk_test_ok( 'BAKE SALE' === $s['split_items'][0]['title'], 'first split item is its own ready-to-use suggestion' );

ptk_test_done();
