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

ptk_test_done();
