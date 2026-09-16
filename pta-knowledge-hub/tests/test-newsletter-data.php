<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';

// Any PHP warning/notice (e.g. Array-to-string conversion) fails the run.
set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

// default_blocks() returns the suggested layout, header first + footer last.
$blocks = PTK_Newsletter_Data::default_blocks();
$types  = array_column( $blocks, 'type' );
ptk_test_ok( $types[0] === 'header', 'default layout starts with header' );
ptk_test_ok( end( $types ) === 'footer', 'default layout ends with footer' );
ptk_test_ok( in_array( 'events', $types, true ), 'default layout includes events' );

// sanitize_blocks() drops unknown types and forces header-first/footer-last.
$raw = array(
    array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => '2026-06-25', 'title' => 'Last Day <script>x</script>', 'desc' => 'Bye' ) ) ) ),
    array( 'type' => 'evil',   'data' => array() ),
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'greeting' => 'Hi' ) ),
);
$clean = PTK_Newsletter_Data::sanitize_blocks( $raw );
$ctypes = array_column( $clean, 'type' );
ptk_test_ok( $ctypes[0] === 'header', 'sanitize forces header first' );
ptk_test_ok( end( $ctypes ) === 'footer', 'sanitize appends footer if missing' );
ptk_test_ok( ! in_array( 'evil', $ctypes, true ), 'sanitize drops unknown block types' );
$eventRow = $clean[ array_search( 'events', $ctypes, true ) ]['data']['rows'][0];
ptk_test_ok( strpos( $eventRow['title'], '<script>' ) === false, 'sanitize strips scripts from titles' );

// sanitize_blocks() handles non-scalar (array) leaf fields without warnings.
$hostile = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => array( 'x' ), 'greeting' => 'Hi' ) ),
    array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => array( 'x' ), 'title' => array( 'x' ), 'desc' => 'Bye' ) ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'See ya', 'links' => array( array( 'label' => 'Home', 'url' => array( 'x' ) ) ) ) ),
);
$hclean  = PTK_Newsletter_Data::sanitize_blocks( $hostile );
$htypes  = array_column( $hclean, 'type' );
$hheader = $hclean[ array_search( 'header', $htypes, true ) ]['data'];
$hevent  = $hclean[ array_search( 'events', $htypes, true ) ]['data']['rows'][0];
$hlink   = $hclean[ array_search( 'footer', $htypes, true ) ]['data']['links'][0];
ptk_test_ok( $hheader['school_name'] === '', 'array header school_name becomes empty string' );
ptk_test_ok( $hevent['date'] === '', 'array event date becomes empty string' );
ptk_test_ok( $hevent['title'] === '', 'array event title becomes empty string' );
ptk_test_ok( $hlink['url'] === '', 'array footer link url becomes empty string' );

// --- blocks_have_images(): the photo check only applies with a photo. ----
$no_img = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'X' ) ),
    array( 'type' => 'featured', 'data' => array( 'headline' => 'Hi', 'image_id' => 0 ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array( array( 'heading' => 'A', 'image_id' => 0 ) ) ) ),
);
ptk_test_ok( false === PTK_Newsletter_Data::blocks_have_images( $no_img ), 'no image_id above 0 -> no images' );
ptk_test_ok( false === PTK_Newsletter_Data::blocks_have_images( array() ), 'empty blocks -> no images' );
ptk_test_ok( false === PTK_Newsletter_Data::blocks_have_images( 'junk' ), 'non-array -> no images' );
$feat = $no_img; $feat[1]['data']['image_id'] = 12;
ptk_test_ok( true === PTK_Newsletter_Data::blocks_have_images( $feat ), 'featured image counts' );
$card = $no_img; $card[2]['data']['cards'][] = array( 'heading' => 'B', 'image_id' => '7' );
ptk_test_ok( true === PTK_Newsletter_Data::blocks_have_images( $card ), 'a story card image counts (string id too)' );

// --- 4.2.0 shape: new fields, quick notes, and the pill -> when migration. ---
$d = 'PTK_Newsletter_Data';

$defaults = $d::default_blocks();
$dtypes   = array_column( $defaults, 'type' );
ptk_test_ok( in_array( 'quick_notes', $dtypes, true ), 'default layout includes quick notes' );
ptk_test_ok( array_search( 'quick_notes', $dtypes, true ) === array_search( 'story_cards', $dtypes, true ) + 1, 'quick notes sits right after stories' );
ptk_test_ok( end( $dtypes ) === 'footer', 'footer is still last' );
$dh = $defaults[ array_search( 'header', $dtypes, true ) ]['data'];
ptk_test_ok( array_key_exists( 'summary', $dh ), 'header default has a summary key' );
$da = $defaults[ array_search( 'announcement', $dtypes, true ) ]['data'];
foreach ( array( 'when', 'headline', 'text', 'button_text', 'button_url', 'timeline' ) as $k ) {
    ptk_test_ok( array_key_exists( $k, $da ), "announcement default has $k" );
}
ptk_test_ok( ! array_key_exists( 'pill', $da ), 'announcement default no longer has pill' );

// A literal 4.1.x newsletter, exactly as the old plugin saved it.
$old = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => '', 'greeting' => 'Hi' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday · Jun 25', 'text' => 'Last day of school.' ) ),
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'Year in review', 'headline' => 'What a year', 'body' => 'Thanks', 'image_id' => 3 ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array( array( 'heading' => 'Mum Sale', 'body' => 'Open', 'image_id' => 0, 'link_url' => 'mailto:x@y.org', 'link_text' => 'Email' ) ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Bye', 'links' => array() ) ),
);
$mig   = $d::sanitize_blocks( $old );
$mtype = array_column( $mig, 'type' );
$ma    = $mig[ array_search( 'announcement', $mtype, true ) ]['data'];
ptk_test_ok( $ma['when'] === 'Thursday · Jun 25', 'old pill becomes the when line' );
ptk_test_ok( ! isset( $ma['pill'] ), 'pill key is not carried forward' );
ptk_test_ok( $ma['headline'] === '' && $ma['button_text'] === '' && $ma['button_url'] === '' && $ma['timeline'] === array(), 'new announcement keys default blank' );
ptk_test_ok( $mig[ array_search( 'header', $mtype, true ) ]['data']['summary'] === '', 'old header gains a blank summary' );
$mf = $mig[ array_search( 'featured', $mtype, true ) ]['data'];
ptk_test_ok( $mf['eyebrow'] === 'Year in review' && $mf['image_id'] === 3 && $mf['link_url'] === '' && $mf['link_text'] === '', 'old featured keeps its values and gains blank link keys' );
$mc = $mig[ array_search( 'story_cards', $mtype, true ) ]['data']['cards'][0];
ptk_test_ok( $mc['eyebrow'] === '' && $mc['link_url'] === 'mailto:x@y.org', 'old card gains a blank eyebrow and keeps a mailto link' );
ptk_test_ok( ! in_array( 'quick_notes', $mtype, true ), 'sanitize does not invent a quick notes block' );

// When both keys arrive (a tab open across the update), the new key wins.
$both = $d::sanitize_blocks( array( array( 'type' => 'announcement', 'data' => array( 'pill' => 'old', 'when' => 'new', 'text' => '' ) ) ) );
ptk_test_ok( $both[1]['data']['when'] === 'new', 'when wins over pill when both are posted' );

// Timeline rows and links.
$ann = $d::sanitize_blocks( array( array( 'type' => 'announcement', 'data' => array(
    'headline'    => 'ASE <b>opens</b>',
    'button_text' => 'Go',
    'button_url'  => 'javascript:alert(1)',
    'timeline'    => array(
        array( 'date' => '2026-09-14', 'time' => '8:30 AM–12:30 PM', 'what' => 'Members only' ),
        array( 'date' => 'nope', 'time' => array( 'x' ), 'what' => '' ),
        'junk',
    ),
) ) ) );
$aa = $ann[1]['data'];
ptk_test_ok( $aa['headline'] === 'ASE opens', 'announcement headline is plain text' );
ptk_test_ok( $aa['button_url'] === '', 'a javascript: button link is blanked' );
ptk_test_ok( count( $aa['timeline'] ) === 2 && $aa['timeline'][0]['time'] === '8:30 AM–12:30 PM', 'timeline rows are kept in order, non-arrays dropped' );
ptk_test_ok( $aa['timeline'][1]['date'] === '' && $aa['timeline'][1]['time'] === '', 'bad date and array time become empty strings' );

ptk_test_ok( $d::sanitize_link_url( ' https://x.test/a ' ) === 'https://x.test/a', 'http url: trimmed and kept' );
ptk_test_ok( $d::sanitize_link_url( 'HTTP://x.test' ) !== '', 'http url: an upper-case scheme is still a web address' );
ptk_test_ok( $d::sanitize_link_url( 'mailto:a@b.org' ) === 'mailto:a@b.org', 'link url: an email link is kept (#040 uses one)' );
ptk_test_ok( $d::sanitize_link_url( 'leslie@example.org' ) === 'mailto:leslie@example.org', 'link url: a bare email address becomes an email link' );
ptk_test_ok( $d::sanitize_link_url( 'data:text/html,x' ) === '', 'link url: data is rejected' );
ptk_test_ok( $d::sanitize_link_url( 'javascript:alert(1)' ) === '', 'http url: javascript is rejected' );
ptk_test_ok( $d::sanitize_link_url( array( 'x' ) ) === '', 'http url: array becomes empty, no warning' );
// Not asserted: a bare "x.test/page". Real esc_url_raw() prepends "http://"
// to a scheme-less address, so production KEEPS it; the test shim does not,
// so an assertion either way would describe the wrong environment.

$qn = $d::sanitize_blocks( array( array( 'type' => 'quick_notes', 'data' => array(
    'label' => 'Good to <i>know</i>',
    'items' => array(
        array( 'heading' => 'Lunch menu', 'body' => 'On the <strong>site</strong>. <script>x</script>', 'link_url' => 'https://x.test/lunch', 'link_text' => 'See the menu' ),
        array( 'heading' => array( 'x' ), 'body' => '', 'link_url' => 'ftp://x', 'link_text' => '' ),
    ),
) ) ) );
$qd = $qn[1]['data'];

// Only one of each section type: first one wins, like PTK_Share_Text::generate() reads them.
$dup = $d::sanitize_blocks( array(
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'First' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Second' ) ),
) );
$dup_types = array_column( $dup, 'type' );
ptk_test_ok( count( array_keys( $dup_types, 'announcement', true ) ) === 1, 'two announcements become one' );
ptk_test_ok( $dup[ array_search( 'announcement', $dup_types, true ) ]['data']['headline'] === 'First', 'the first announcement is the one kept' );
ptk_test_ok( $qn[1]['type'] === 'quick_notes', 'quick notes is a known type' );
ptk_test_ok( $qd['label'] === 'Good to know', 'quick notes label is plain text' );
ptk_test_ok( strpos( $qd['items'][0]['body'], '<strong>' ) !== false && strpos( $qd['items'][0]['body'], '<script>' ) === false, 'note body keeps safe html, drops scripts' );
ptk_test_ok( $qd['items'][1]['heading'] === '' && $qd['items'][1]['link_url'] === '', 'note: array heading and ftp link become empty' );

// --- 4.3.0: "start from last issue" merge rule. ---
$d = 'PTK_Newsletter_Data';

$defaults = $d::default_blocks();
$last = $d::sanitize_blocks( array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Stale news' ) ),
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'Date change', 'headline' => 'Old story' ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array( array( 'eyebrow' => 'Old card label', 'heading' => 'x' ) ) ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array( array( 'heading' => 'Old note' ) ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Thanks!', 'links' => array( array( 'label' => 'Site', 'url' => 'https://x.org' ) ) ) ),
) );

$merged = $d::merge_start_from_last( $defaults, $last );
$mtype  = array_column( $merged, 'type' );

$mf = $merged[ array_search( 'featured', $mtype, true ) ]['data'];
ptk_test_ok( $mf['eyebrow'] === 'Date change', 'top story label copies from last issue' );
ptk_test_ok( $mf['headline'] === '', 'top story headline does NOT copy' );

$mq = $merged[ array_search( 'quick_notes', $mtype, true ) ]['data'];
ptk_test_ok( $mq['label'] === 'Good to know', 'quick notes label copies from last issue' );
ptk_test_ok( $mq['items'] === array(), 'quick notes items do NOT copy' );

$ma = $merged[ array_search( 'announcement', $mtype, true ) ]['data'];
ptk_test_ok( $ma['headline'] === '', 'announcement does NOT copy' );

$mc = $merged[ array_search( 'story_cards', $mtype, true ) ]['data'];
ptk_test_ok( $mc['cards'] === array(), 'story cards do NOT copy (card eyebrow is per-card, not a section label)' );

$mfoot = $merged[ array_search( 'footer', $mtype, true ) ]['data'];
ptk_test_ok( $mfoot['signoff'] === 'Thanks!', 'footer signoff copies wholesale' );
ptk_test_ok( count( $mfoot['links'] ) === 1 && $mfoot['links'][0]['url'] === 'https://x.org', 'footer links copy wholesale' );

// Blank labels in the source stay blank in the default, not overwritten with ''.
$blank_last = $d::sanitize_blocks( array( array( 'type' => 'featured', 'data' => array( 'eyebrow' => '' ) ) ) );
$merged2 = $d::merge_start_from_last( $d::default_blocks(), $blank_last );
$mf2 = $merged2[ array_search( 'featured', array_column( $merged2, 'type' ), true ) ]['data'];
ptk_test_ok( $mf2['eyebrow'] === '', 'a blank label in the source leaves the default blank (no-op, not an error)' );

// An empty "last" array (no previous newsletter) returns the defaults unchanged.
$merged3 = $d::merge_start_from_last( $d::default_blocks(), array() );
ptk_test_ok( $merged3 === $d::default_blocks(), 'no previous newsletter: defaults pass through unchanged' );

// Round 3: image_fit/focal/zoom sit next to every image_id.

// A saved newsletter with no crop fields at all (pre-4.4.0 data) sanitizes
// to safe defaults -- "existing data is untouched."
$legacy = $d::sanitize_blocks( array(
    array( 'type' => 'featured', 'data' => array( 'image_id' => 12 ) ),
) );
$feat = $legacy[ array_search( 'featured', array_column( $legacy, 'type' ), true ) ]['data'];
ptk_test_ok( $feat['image_id'] === 12, 'legacy image_id survives untouched' );
ptk_test_ok( $feat['image_fit'] === 'whole', 'legacy data defaults to Show whole' );
ptk_test_ok( $feat['image_focal_x'] === 50 && $feat['image_focal_y'] === 50, 'legacy data defaults to centered' );
ptk_test_ok( $feat['image_zoom'] === 0, 'legacy data defaults to unzoomed (the sentinel)' );

// A submitted crop, in range.
$cropped = $d::sanitize_blocks( array(
    array( 'type' => 'featured', 'data' => array( 'image_id' => 12, 'image_fit' => 'crop', 'image_focal_x' => 30, 'image_focal_y' => 80, 'image_zoom' => 175 ) ),
) );
$feat2 = $cropped[ array_search( 'featured', array_column( $cropped, 'type' ), true ) ]['data'];
ptk_test_ok( $feat2['image_fit'] === 'crop' && $feat2['image_focal_x'] === 30 && $feat2['image_focal_y'] === 80 && $feat2['image_zoom'] === 175, 'a valid crop round-trips unchanged' );

// Garbage in, safe defaults out -- never a fatal, never an out-of-range value stored.
$garbage = $d::sanitize_blocks( array(
    array( 'type' => 'featured', 'data' => array( 'image_id' => 12, 'image_fit' => 'whatever', 'image_focal_x' => 'nope', 'image_focal_y' => -900, 'image_zoom' => 99999 ) ),
) );
$feat3 = $garbage[ array_search( 'featured', array_column( $garbage, 'type' ), true ) ]['data'];
ptk_test_ok( $feat3['image_fit'] === 'whole', 'garbage fit value falls back to whole' );
ptk_test_ok( $feat3['image_focal_x'] === 50, 'non-numeric focal_x falls back to center' );
ptk_test_ok( $feat3['image_focal_y'] === 0, 'out-of-range focal_y clamps, does not become garbage' );
ptk_test_ok( $feat3['image_zoom'] === 250, 'over-range zoom clamps to the ceiling, is not dropped' );

// Story cards: same four fields, per card.
$cards = $d::sanitize_blocks( array(
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        array( 'image_id' => 5, 'image_fit' => 'crop', 'image_focal_x' => 10, 'image_focal_y' => 20, 'image_zoom' => 130 ),
        array( 'image_id' => 6 ),
    ) ) ),
) );
$card_data = $cards[ array_search( 'story_cards', array_column( $cards, 'type' ), true ) ]['data']['cards'];
ptk_test_ok( $card_data[0]['image_fit'] === 'crop' && $card_data[0]['image_zoom'] === 130, 'first card keeps its crop' );
ptk_test_ok( $card_data[1]['image_fit'] === 'whole' && $card_data[1]['image_focal_x'] === 50, 'second card (no crop fields posted) defaults' );

// default_blocks() carries the four fields at their defaults on the featured block.
$defaults = $d::default_blocks();
$default_featured = $defaults[ array_search( 'featured', array_column( $defaults, 'type' ), true ) ]['data'];
ptk_test_ok( $default_featured['image_fit'] === 'whole' && $default_featured['image_zoom'] === 0, 'default_blocks() featured starts at Show whole, unzoomed' );

ptk_test_done();
