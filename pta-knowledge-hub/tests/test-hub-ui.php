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
ptk_test_ok( false !== strpos( $card, 'ptk-card' ) && false !== strpos( $card, 'Sep 14' ), 'card renders title and meta' );
ptk_test_ok( false !== strpos( $card, 'href="https://example.org/b"' ), 'a card with a url is a link' );
ptk_test_ok( false !== strpos( $card, 'Tell families what&#039;s happening' ), 'card title is escaped' );
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

ptk_test_done();
