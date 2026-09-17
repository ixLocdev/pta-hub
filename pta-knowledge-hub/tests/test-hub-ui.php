<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-hub-ui.php';

$u = 'PTK_Hub_UI';

$stamp = $u::stamp( 'Waiting for you', 'warning' );
ptk_test_ok( false !== strpos( $stamp, 'ptk-stamp' ), 'stamp carries its class' );
ptk_test_ok( false !== strpos( $stamp, 'ptk-stamp--warning' ), 'stamp carries its state modifier' );
ptk_test_ok( false !== strpos( $stamp, 'Waiting for you' ), 'the meaning is in the text, not only the color' );
ptk_test_ok( false === strpos( $stamp, '<script' ), 'no markup smuggled through' );

$escaped = $u::stamp( '<b>Sent</b>', 'success' );
ptk_test_ok( false === strpos( $escaped, '<b>' ), 'stamp text is escaped' );

$bad = $u::stamp( 'Whatever', 'nonsense' );
ptk_test_ok( false === strpos( $bad, 'ptk-stamp--nonsense' ), 'an unknown state falls back, never prints itself' );
ptk_test_ok( false !== strpos( $bad, 'class="ptk-stamp"' ), 'an unknown state renders the plain stamp' );

$card = $u::card( array( 'title' => 'Tell families what\'s happening', 'meta' => 'Last one went out Sep 14', 'url' => 'https://example.org/b' ) );
ptk_test_ok( false !== strpos( $card, 'ptk-card' ) && false !== strpos( $card, 'Sep&nbsp;14' ), 'card renders title and meta (last two words tied together)' );
ptk_test_ok( false !== strpos( $card, 'href="https://example.org/b"' ), 'a card with a url is a link' );
ptk_test_ok( false !== strpos( $card, 'Tell families what&#039;s&nbsp;happening' ), 'card title is escaped' );
ptk_test_ok( false === strpos( $card, 'ptk-card--soft' ), 'a card is not soft unless asked' );
$soft = $u::card( array( 'title' => 'T', 'url' => 'https://example.org', 'soft' => true ) );
ptk_test_ok( false !== strpos( $soft, 'ptk-card--soft' ), 'a soft card carries the modifier' );
$evil = $u::card( array( 'title' => 'T', 'url' => 'javascript:alert(1)' ) );
ptk_test_ok( false === strpos( $evil, 'javascript:' ), 'a bad url is neutralized' );

$expand = $u::card( array( 'title' => "I'm not sure where to start", 'body' => '<p class="ptk-help">cue</p>' ) );
ptk_test_ok( false !== strpos( $expand, '<details' ) && false !== strpos( $expand, '<summary' ), 'a card with a body and no url expands in place' );
ptk_test_ok( false !== strpos( $expand, 'cue' ), 'the expandable body is rendered' );

$steps = $u::next_steps( array( array( 'label' => 'Add another', 'url' => 'https://example.org/a' ) ) );
ptk_test_ok( false !== strpos( $steps, 'Add another' ) && false !== strpos( $steps, 'https://example.org/a' ), 'next steps render' );
ptk_test_ok( false !== strpos( $steps, 'ptk-next-steps' ), 'next steps carry their class' );

$row = $u::waiting_row( '2 vendor recommendations are waiting for a look.', 'https://example.org/queue', 'Have a look' );
ptk_test_ok( 1 === substr_count( $row, 'class="ptk-stamp' ), 'a waiting row carries exactly one stamp' );
ptk_test_ok( false !== strpos( $row, 'ptk-stamp--warning' ) && false !== strpos( $row, 'Waiting for you' ), 'the waiting stamp says so in words' );
ptk_test_ok( false !== strpos( $row, '2 vendor recommendations' ) && false !== strpos( $row, 'https://example.org/queue' ), 'the sentence and link render' );

$open = $u::page_open( 'What would you like to do?', 'Nothing goes out to families until you say so.' );
ptk_test_ok( false !== strpos( $open, 'ptk-page' ) && false !== strpos( $open, 'ptk-page-title' ) && false !== strpos( $open, 'Nothing goes out' ), 'page open renders title and lead' );
ptk_test_ok( false !== strpos( $open, '<h1' ), 'the page title is the h1' );
ptk_test_ok( 1 === substr_count( $u::page_open( 'T' ) . $u::page_close(), 'ptk-page' ) || false !== strpos( $u::page_close(), '</div>' ), 'page close closes the wrapper' );

$empty = $u::empty_state( 'Nothing here yet', 'When something needs you, it shows up here.' );
ptk_test_ok( false !== strpos( $empty, 'ptk-empty' ) && false !== strpos( $empty, 'shows up here' ), 'empty state renders' );

$btn = $u::primary_button( 'Send it out', 'https://example.org/send' );
ptk_test_ok( false !== strpos( $btn, 'ptk-btn-primary' ) && false !== strpos( $btn, 'Send it out' ), 'primary button renders' );
ptk_test_ok( false === strpos( $btn, 'ptk-stamp' ), 'never a stamp on a button' );

$field = $u::field( array( 'id' => 'ptk-when', 'label' => 'When is it?', 'name' => 'when', 'type' => 'text', 'value' => 'Thu "5"', 'help' => 'A date or a day.' ) );
ptk_test_ok( false !== strpos( $field, 'for="ptk-when"' ) && false !== strpos( $field, 'id="ptk-when"' ), 'a field has a real label bound to its input' );
ptk_test_ok( false !== strpos( $field, 'Thu &quot;5&quot;' ), 'field value is attribute-escaped' );
ptk_test_ok( false !== strpos( $field, 'A date or a day.' ), 'field help renders' );

$fold = $u::section_fold( array( 'title' => 'Coming up', 'summary' => '3 dates added', 'body' => '<p>rows</p>', 'open' => false ) );
ptk_test_ok( false !== strpos( $fold, '<details' ) && false !== strpos( $fold, 'ptk-fold-summary' ) && false !== strpos( $fold, '3 dates added' ), 'a fold renders summary text' );
ptk_test_ok( false === strpos( $fold, ' open' ), 'a closed fold has no open attribute' );
ptk_test_ok( false !== strpos( $u::section_fold( array( 'title' => 'T', 'body' => '', 'open' => true ) ), '<details class="ptk-fold" open' ), 'an open fold is open' );

$multi = $u::waiting_row( array(
    array( 'text' => '2 vendor reviews to approve', 'url' => 'https://example.org/vendors' ),
    array( 'text' => '1 topic suggestion from members', 'url' => 'https://example.org/suggestions' ),
) );
ptk_test_ok( 1 === substr_count( $multi, 'class="ptk-stamp' ), 'array form still renders exactly one stamp' );
ptk_test_ok( false !== strpos( $multi, '2 vendor reviews to approve' ) && false !== strpos( $multi, 'https://example.org/vendors' ), 'array form renders the first item as a link' );
ptk_test_ok( false !== strpos( $multi, '1 topic suggestion from members' ) && false !== strpos( $multi, 'https://example.org/suggestions' ), 'array form renders the second item as a link' );
ptk_test_ok( false !== strpos( $multi, 'ptk-waiting-sep' ), 'array form separates items visually' );

require __DIR__ . '/../includes/class-welcome.php';

// The six intentions: what the volunteer wants, not what the system stores.
$intents = PTK_Welcome::intentions( array( 'edit_posts' => true, 'manage_options' => true ) );
$titles  = array_column( $intents, 'title' );
ptk_test_ok( 6 === count( $intents ), 'six intentions for a full-capability user' );
ptk_test_ok( "Tell families what's happening" === $titles[0], 'the newsletter comes first' );
ptk_test_ok( in_array( "I'm not sure where to start", $titles, true ), 'the unsure route is always offered' );
ptk_test_ok( "I'm not sure where to start" === end( $titles ), 'the unsure route comes last' );
foreach ( $titles as $title ) {
    ptk_test_ok( ! preg_match( '/\b(Add New|Edit|Publish|Manage|Settings|Post|Entry)\b/', $title ), "no system words in: $title" );
}
foreach ( $intents as $i ) {
    ptk_test_ok( isset( $i['key'], $i['title'], $i['meta'], $i['url'] ), "intention has key/title/meta/url: {$i['title']}" );
}

// Someone who can't edit sees only what they can actually do.
$limited = PTK_Welcome::intentions( array( 'edit_posts' => false, 'manage_options' => false ) );
ptk_test_ok( count( $limited ) < count( $intents ), 'fewer choices without editing rights' );
ptk_test_ok( ! in_array( "I'm not sure where to start", array_column( $limited, 'title' ), true ), 'the unsure route needs at least two others' );

// URLs come from the caller, so the list stays pure and testable.
$with_urls = PTK_Welcome::intentions( array( 'edit_posts' => true, 'manage_options' => true ), array( 'newsletter' => 'https://example.org/nl' ) );
ptk_test_ok( 'https://example.org/nl' === $with_urls[0]['url'], 'a supplied url lands on its intention' );

// The "not sure" picker maps a plain sentence to each of the other five.
$cues = PTK_Welcome::cues();
ptk_test_ok( 5 === count( $cues ), 'one cue per real intention' );
foreach ( $cues as $key => $cue ) {
    ptk_test_ok( is_string( $cue ) && '' !== $cue && ! preg_match( '/\b(Add New|Edit|Publish|Manage|Settings|Post|Entry)\b/', $cue ), "cue for $key is plain: $cue" );
}

// The waiting sentence reads right for one and for many.
ptk_test_ok( '1 topic suggestion from members' === PTK_Welcome::waiting_text( 'Topic suggestions from members', 1 ), 'one suggestion reads singular' );
ptk_test_ok( '3 topic suggestions from members' === PTK_Welcome::waiting_text( 'Topic suggestions from members', 3 ), 'three suggestions read plural' );
ptk_test_ok( '1 entry due for a review' === PTK_Welcome::waiting_text( 'Entries due for a review', 1 ), 'one entry reads singular' );
ptk_test_ok( '2 something new' === PTK_Welcome::waiting_text( 'Something new', 2 ), 'an unknown label still gets its count in front' );

// ---------------------------------------------------------------------
// no_widow(): a line never ends with one word on its own.
// ---------------------------------------------------------------------
ptk_test_ok( 'Write it down once, and it lives here for&nbsp;everyone.' === $u::no_widow( 'Write it down once, and it lives here for everyone.' ), 'the last two words are tied together' );
ptk_test_ok( 'Newsletter' === $u::no_widow( 'Newsletter' ), 'a single word is left alone' );
ptk_test_ok( false === strpos( $u::no_widow( 'Find the extraordinarily complicated responsibilities' ), '&nbsp;' ), 'a long last word is not glued to the one before it' );
ptk_test_ok( false === strpos( $u::no_widow( '<b>Sent</b> today' ), '<b>' ), 'no_widow escapes its input' );
ptk_test_ok( false !== strpos( $u::card( array( 'title' => "Tell families what's happening", 'meta' => 'Write this week and next week too' ) ), '&nbsp;' ), 'cards render without widows' );

ptk_test_done();
