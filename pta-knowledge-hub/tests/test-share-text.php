<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-text.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Text';

ptk_test_ok( $t::html_to_text( '<p>One</p><p>Two</p>' ) === "One\nTwo", 'paragraphs become newlines' );
ptk_test_ok( $t::html_to_text( 'A<br>B' ) === "A\nB", 'br becomes a newline' );
ptk_test_ok( $t::html_to_text( '<strong>Bold</strong> text' ) === 'Bold text', 'inline tags are dropped' );
ptk_test_ok( $t::html_to_text( 'Join <a href="https://x.test/j">here</a>' ) === 'Join here (https://x.test/j)', 'links become text plus a bare URL' );
ptk_test_ok( $t::html_to_text( 'Mum&nbsp;Sale &amp; more' ) === 'Mum Sale & more', 'entities decoded, nbsp becomes a space' );
ptk_test_ok( $t::html_to_text( "<p>A</p>\n\n\n<p>B</p>" ) === "A\nB", 'runs of blank lines collapse' );
ptk_test_ok( $t::html_to_text( '' ) === '', 'empty input stays empty' );
ptk_test_ok( $t::html_to_text( array( 'x' ) ) === '', 'array input becomes empty, no warning' );

// Northeast's headings are already whole sentences carrying the fact.
$card = array( 'heading' => 'Film on the Field moves to Friday, October 16.', 'body' => '<p>Bring a blanket. Rain date October 23.</p>' );
ptk_test_ok( $t::story_line( $card ) === 'Film on the Field moves to Friday, October 16.', 'a sentence heading is the whole line' );

// A label-style heading is too thin on its own, so the body completes it.
$label = array( 'heading' => 'Mum Sale', 'body' => '<p>Open through September 25. Pickup at the car wash.</p>' );
ptk_test_ok( $label_line = $t::story_line( $label ), 'label heading returns something' );
ptk_test_ok( strpos( $label_line, 'Mum Sale' ) === 0, 'label heading still leads' );
ptk_test_ok( strpos( $label_line, 'Open through September 25.' ) !== false, 'label heading gains the first sentence' );

ptk_test_ok( $t::story_line( array( 'heading' => '', 'body' => '<p>First one. Second one.</p>' ) ) === 'First one.', 'no heading falls back to first sentence' );
ptk_test_ok( $t::story_line( array( 'heading' => '', 'body' => '' ) ) === '', 'an empty card yields nothing' );

$long = array( 'heading' => '', 'body' => '<p>' . str_repeat( 'word ', 60 ) . '</p>' );
$cut  = $t::story_line( $long );
// Count CHARACTERS, not bytes: the ellipsis is three bytes in UTF-8.
ptk_test_ok( mb_strlen( $cut ) <= 121, 'long text is truncated near 120 chars' );
ptk_test_ok( mb_substr( $cut, -1 ) === '…', 'truncation is marked with an ellipsis' );
ptk_test_ok( strpos( $cut, 'wor…' ) === false, 'truncation lands on a word boundary' );

// A cut landing mid-character must not emit broken UTF-8 into a caption.
$dashes = array( 'heading' => '', 'body' => '<p>' . str_repeat( 'a—b ', 40 ) . '</p>' );
$dcut   = $t::story_line( $dashes );
ptk_test_ok( mb_check_encoding( $dcut, 'UTF-8' ), 'truncation never splits a multibyte character' );

// ---------------------------------------------------------------------
// generate() -- the three captions
// ---------------------------------------------------------------------

function ptk_share_test_blocks() {
    return array(
        array(
            'type' => 'header',
            'data' => array(
                'school_name' => 'Northeast Elementary',
                'headline'    => 'Back to School',
                'greeting'    => '<p>Hi families,</p>',
            ),
        ),
        array(
            'type' => 'announcement',
            'data' => array(
                'pill' => 'Reminder',
                'text' => '<p>Picture day is <strong>Friday</strong>.</p>',
            ),
        ),
        array(
            'type' => 'events',
            'data' => array(
                'rows' => array(
                    array( 'date' => '2026-09-01', 'title' => 'Past Bake Sale', 'desc' => '<p>Already happened.</p>' ),
                    array( 'date' => '2026-09-20', 'title' => 'Fall Festival', 'desc' => '<p>Bring the family.</p>' ),
                ),
            ),
        ),
        array(
            'type' => 'featured',
            'data' => array(
                'eyebrow'  => 'Spotlight',
                'headline' => 'Film on the Field moves to Friday, October 16.',
                'body'     => '<p>Bring a blanket. Rain date October 23.</p>',
                'image_id' => 0,
            ),
        ),
        array(
            'type' => 'story_cards',
            'data' => array(
                'cards' => array(
                    array( 'heading' => 'Mum Sale', 'body' => '<p>Open through September 25.</p>', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
                    array( 'heading' => 'Book Fair returns next week.', 'body' => '<p>See you there.</p>', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
                ),
            ),
        ),
        array(
            'type' => 'footer',
            'data' => array(
                'signoff' => '<p>Thanks,<br>The PTA</p>',
                'links'   => array(
                    array( 'label' => 'Volunteer', 'url' => 'https://x.test/volunteer' ),
                ),
            ),
        ),
    );
}

$opts = array(
    'url'         => 'https://x.test/newsletter/40',
    'issue'       => 40,
    'date'        => '2026-09-16',
    'school_name' => 'Northeast Elementary',
    'today'       => '2026-09-16',
);

$captions = $t::generate( ptk_share_test_blocks(), $opts );

ptk_test_ok( is_array( $captions ) && isset( $captions['facebook'], $captions['instagram'], $captions['whatsapp'] ), 'generate() returns all three channels' );

$fb = $captions['facebook'];
$ig = $captions['instagram'];
$wa = $captions['whatsapp'];

ptk_test_ok( strpos( $fb, 'Film on the Field moves to Friday, October 16.' ) !== false, 'facebook: featured headline appears' );
ptk_test_ok( strpos( $fb, 'Film on the Field' ) < strpos( $fb, 'Mum Sale' ), 'facebook: featured headline leads before story cards' );

$mum_line   = $t::story_line( array( 'heading' => 'Mum Sale', 'body' => '<p>Open through September 25.</p>' ) );
$book_line  = $t::story_line( array( 'heading' => 'Book Fair returns next week.', 'body' => '<p>See you there.</p>' ) );
ptk_test_ok( substr_count( $fb, $mum_line ) === 1, 'facebook: mum sale card contributes exactly one line' );
ptk_test_ok( substr_count( $fb, $book_line ) === 1, 'facebook: book fair card contributes exactly one line' );

ptk_test_ok( substr_count( $fb, $opts['url'] ) === 1, 'facebook: url appears exactly once' );

ptk_test_ok( strpos( $fb, 'Fall Festival' ) !== false, 'facebook: future event is included' );
ptk_test_ok( strpos( $fb, 'Past Bake Sale' ) === false, 'facebook: past event is excluded' );

ptk_test_ok( strpos( $fb, 'Volunteer' ) !== false && strpos( $fb, 'https://x.test/volunteer' ) !== false, 'facebook: footer link is present as label and url' );

foreach ( array( 'facebook' => $fb, 'instagram' => $ig, 'whatsapp' => $wa ) as $label => $text ) {
    ptk_test_ok( preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text ) === 0, "$label: no emoji" );
}

ptk_test_ok( strpos( $ig, 'http' ) === false, 'instagram: no bare url' );
ptk_test_ok( stripos( $ig, 'link in bio' ) !== false, 'instagram: has a link-in-bio pointer' );

ptk_test_ok( strlen( $wa ) < 400, 'whatsapp: under 400 chars' );
ptk_test_ok( strpos( $wa, $opts['url'] ) !== false, 'whatsapp: contains the url' );

// A bare-bones newsletter (no featured, no cards, no events) still yields text, no warnings.
$bare_blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Northeast Elementary', 'headline' => 'Hi', 'greeting' => '' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => '', 'text' => '' ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => '', 'links' => array() ) ),
);
$bare = $t::generate( $bare_blocks, $opts );
ptk_test_ok( '' !== trim( $bare['facebook'] ), 'facebook text is non-empty even with no featured/cards/events' );

// Plain-text fields carry entities too -- a featured headline, an event title
// and a footer label must decode exactly like an HTML body does, or the same
// post shows "K&ndash;5" in one line and "K-5" in the next.
$ent = PTK_Share_Text::generate(
    array(
        array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => '', 'greeting' => '' ) ),
        array( 'type' => 'featured', 'data' => array( 'eyebrow' => '', 'headline' => 'Grades K&ndash;5 start September&nbsp;22.', 'body' => '', 'image_id' => 0 ) ),
        array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => '2026-09-26', 'title' => 'Car Wash &amp; Mum Pickup', 'desc' => '' ) ) ) ),
        array( 'type' => 'footer', 'data' => array( 'signoff' => '', 'links' => array( array( 'label' => 'Join &amp; Renew', 'url' => 'https://x.test/j' ) ) ) ),
    ),
    array( 'url' => 'https://x.test/n', 'issue' => '40', 'date' => '2026-09-14', 'school_name' => 'NE PTA', 'today' => '2026-09-14' )
);
ptk_test_ok( strpos( $ent['facebook'], '&ndash;' ) === false, 'featured headline decodes entities' );
ptk_test_ok( strpos( $ent['facebook'], '&nbsp;' ) === false, 'featured headline decodes nbsp' );
ptk_test_ok( strpos( $ent['facebook'], 'Car Wash & Mum Pickup' ) !== false, 'event title decodes entities' );
ptk_test_ok( strpos( $ent['facebook'], 'Join & Renew' ) !== false, 'footer label decodes entities' );
ptk_test_ok( strpos( $ent['instagram'], '&ndash;' ) === false, 'instagram decodes entities too' );
ptk_test_ok( strpos( $ent['whatsapp'], '&ndash;' ) === false, 'whatsapp decodes entities too' );

// Issue numbers read as "040" in our newsletters, and a PTA that names its
// issues in words keeps whatever it typed.
ptk_test_ok( strpos( $ent['facebook'], 'PTA Newsletter #040 is out.' ) === 0, 'issue number is zero-padded to three digits' );
$named = PTK_Share_Text::generate(
    array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => '', 'greeting' => '' ) ) ),
    array( 'url' => 'https://x.test/n', 'issue' => 'Winter', 'date' => '2026-01-05', 'school_name' => 'NE PTA', 'today' => '2026-01-05' )
);
ptk_test_ok( strpos( $named['facebook'], 'PTA Newsletter #Winter is out.' ) === 0, 'a non-numeric issue is left alone' );
ptk_test_ok( mb_strlen( $ent['instagram'] ) < mb_strlen( $ent['facebook'] ), 'instagram is shorter than facebook' );

// --- 4.2.0: announcement headline + when, quick notes as "also" lines. ------
$v2 = PTK_Share_Text::generate( array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '', 'summary' => '', 'greeting' => '' ) ),
    array( 'type' => 'announcement', 'data' => array( 'when' => 'Closes Thursday at noon', 'headline' => 'ASE registration opens Monday.', 'text' => '<p>Members go first.</p>', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        array( 'eyebrow' => '', 'heading' => 'Film on the Field moves to Friday, October 16.', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    ) ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array(
        array( 'heading' => 'Lunch menu', 'body' => '<p>This week\'s menus are on the site.</p>', 'link_url' => 'https://x.test/l', 'link_text' => 'See the menu' ),
        array( 'heading' => '', 'body' => '', 'link_url' => '', 'link_text' => '' ),
    ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => '', 'links' => array() ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 41, 'date' => '2026-09-20', 'school_name' => 'NE', 'today' => '2026-09-20' ) );
$fb2 = $v2['facebook'];
ptk_test_ok( strpos( $fb2, "ASE registration opens Monday.\nMembers go first.\nCloses Thursday at noon" ) !== false, 'facebook: announcement is headline, text, when' );
ptk_test_ok( strpos( $fb2, 'Film on the Field moves to Friday, October 16.' ) !== false, 'facebook: story line still there' );
ptk_test_ok( strpos( $fb2, 'Lunch menu' ) !== false && strpos( $fb2, "This week's menus are on the site." ) !== false, 'facebook: a quick note becomes an also-line via the heading rule' );
ptk_test_ok( strpos( $fb2, 'Film on the Field' ) < strpos( $fb2, 'Lunch menu' ), 'facebook: stories come before quick notes' );
ptk_test_ok( substr_count( $fb2, "\nLunch menu" ) === 1, 'facebook: the blank note adds nothing' );
ptk_test_ok( strpos( $v2['instagram'], 'ASE registration opens Monday.' ) !== false, 'instagram: leads with the announcement headline' );
ptk_test_ok( strpos( $v2['whatsapp'], 'ASE registration opens Monday.' ) !== false, 'whatsapp: with no top story the announcement headline is the middle line' );

// WhatsApp carries one line, and it goes to the time-sensitive announcement
// even when a top story exists -- #040 would otherwise have sent families
// "Can you help on Tuesdays?" instead of "registration opens Monday".
$both = PTK_Share_Text::generate( array(
    array( 'type' => 'announcement', 'data' => array( 'when' => '', 'headline' => 'ASE registration opens Monday.', 'text' => '', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ),
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => '', 'headline' => 'Can you help on Tuesdays?', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 41, 'date' => '2026-09-20', 'school_name' => 'NE', 'today' => '2026-09-20' ) );
ptk_test_ok( strpos( $both['whatsapp'], 'ASE registration opens Monday.' ) !== false, 'whatsapp: the announcement wins over the top story' );
ptk_test_ok( strpos( $both['whatsapp'], 'Can you help on Tuesdays?' ) === false, 'whatsapp: the top story is not the one line' );

// A migrated announcement with no headline: the text leads, the When line follows.
$nohead = PTK_Share_Text::generate( array(
    array( 'type' => 'announcement', 'data' => array( 'when' => 'Thursday · Jun 25', 'headline' => '', 'text' => 'Last day of school.', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 1, 'date' => '2026-06-22', 'school_name' => 'NE', 'today' => '2026-06-22' ) );
ptk_test_ok( strpos( $nohead['facebook'], "Last day of school.\nThursday · Jun 25" ) !== false, 'no headline: the text is the lead line and the When line follows it' );

// Un-resaved 4.1.x meta still carries pill; captions must not lose it.
$v1 = PTK_Share_Text::generate( array(
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday', 'text' => 'Last day.' ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 1, 'date' => '2026-06-22', 'school_name' => 'NE', 'today' => '2026-06-22' ) );
ptk_test_ok( strpos( $v1['facebook'], "Last day.\nThursday" ) !== false, 'an old pill still reaches the caption as the when line' );

// The cap: 5 cards + 5 notes -> 8 also-lines, cards first.
$many_cards = array(); $many_notes = array();
for ( $i = 1; $i <= 5; $i++ ) {
    $many_cards[] = array( 'eyebrow' => '', 'heading' => "Card number $i is a whole sentence here.", 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' );
    $many_notes[] = array( 'heading' => "Note number $i is a whole sentence here.", 'body' => '', 'link_url' => '', 'link_text' => '' );
}
$cap = PTK_Share_Text::generate( array(
    array( 'type' => 'story_cards', 'data' => array( 'cards' => $many_cards ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => '', 'items' => $many_notes ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 1, 'date' => '2026-06-22', 'school_name' => 'NE', 'today' => '2026-06-22' ) );
ptk_test_ok( substr_count( $cap['facebook'], 'Card number' ) === 5 && substr_count( $cap['facebook'], 'Note number' ) === 3, 'also-lines cap at 8, cards first' );
ptk_test_ok( strpos( $cap['facebook'], 'Note number 4' ) === false, 'the ninth line is dropped' );

ptk_test_done();
