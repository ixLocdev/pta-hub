<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-hub-router.php';

$r   = 'PTK_Hub_Router';
$all = array( 'newsletter', 'answer', 'vendor', 'word', 'fix' );

// --- the five cue sentences the home screen already shows must each land
// on their own screen, and land there confidently. ---
$cues = array(
    'We have a PTA meeting next Thursday'      => 'newsletter',
    'Parents keep emailing about pickup'       => 'answer',
    'The DJ from the spring dance was great'   => 'vendor',
    'Someone asked what "Title I" means'       => 'word',
    "There's a typo on the website"            => 'fix',
);
foreach ( $cues as $sentence => $expected ) {
    $out = $r::route( $sentence, $all );
    ptk_test_ok( 'confident' === $out['state'], 'confident about: ' . $sentence );
    ptk_test_ok( $expected === $out['matches'][0]['key'], $sentence . ' -> ' . $expected );
}

// --- sentences a volunteer would really write ---
$real = array(
    'I need parents to volunteer for the book fair'   => 'newsletter',
    'We need to send out the newsletter'              => 'newsletter',
    'How do I sign my child up for after school'      => 'answer',
    'Families keep asking about the dress code'       => 'answer',
    'The photographer we hired did a great job'       => 'vendor',
    'What does ASE stand for'                         => 'word',
    'The pickup time on the site is wrong'            => 'fix',
);
foreach ( $real as $sentence => $expected ) {
    $out = $r::route( $sentence, $all );
    ptk_test_ok(
        ! empty( $out['matches'] ) && $expected === $out['matches'][0]['key'],
        'best guess for "' . $sentence . '" is ' . $expected
    );
}

// --- it never guesses when it cannot tell ---
$blank = $r::route( '   ', $all );
ptk_test_ok( 'none' === $blank['state'] && empty( $blank['matches'] ), 'an empty sentence routes nowhere' );

$noise = $r::route( 'purple elephant xylophone', $all );
ptk_test_ok( 'none' === $noise['state'], 'words that mean nothing here give no false confidence' );
ptk_test_ok( false !== strpos( $r::heading( 'none' ), "couldn't tell" ), 'and the screen says so plainly' );

// --- it never offers a screen the volunteer does not have ---
$limited = $r::route( 'The DJ from the spring dance was great', array( 'answer', 'word' ) );
ptk_test_ok(
    ! in_array( 'vendor', array_column( $limited['matches'], 'key' ), true ),
    'an unavailable intention is never offered'
);

// --- a muddled sentence offers a short list instead of one answer ---
$muddled = $r::route( 'The meeting question about the dance', $all );
ptk_test_ok( 'choices' === $muddled['state'], 'a muddled sentence asks which one' );
ptk_test_ok( count( $muddled['matches'] ) <= 3, 'never more than three guesses at once' );
ptk_test_ok( false !== strpos( $r::heading( 'choices' ), 'sounds right' ), 'and asks in the assistant voice' );

// --- the same sentence always gives the same answer ---
$a = $r::route( 'The meeting question about the dance', $all );
$b = $r::route( 'The meeting question about the dance', $all );
ptk_test_ok( $a === $b, 'routing is deterministic' );

// --- punctuation, capitals and curly quotes do not change the answer ---
$plain  = $r::route( 'there is a typo on the website', $all );
$fussy  = $r::route( "THERE'S A TYPO \xe2\x80\x94 on the Website!!", $all );
ptk_test_ok( $plain['matches'][0]['key'] === $fussy['matches'][0]['key'], 'punctuation and capitals are ignored' );

// --- whole words only ---
ptk_test_ok( empty( $r::score( 'household chores' ) ), '"old" does not fire inside "household"' );

// --- their words follow them ---
$urls = array(
    'answer'     => 'https://x.test/wp-admin/admin.php?page=ptk-new',
    'word'       => 'https://x.test/wp-admin/admin.php?page=ptk-new&ptk_for=word',
    'fix'        => 'https://x.test/wp-admin/admin.php?page=ptk-written',
    'newsletter' => 'https://x.test/wp-admin/admin.php?page=ptk-newsletter',
    'vendor'     => 'https://x.test/wp-admin/edit.php?post_type=ptk_vendor',
);
$to_answer = $r::destination( 'answer', 'Where do I park for drop-off?', $urls );
ptk_test_ok( false !== strpos( $to_answer, 'ptk_prefill_title=' ), 'the question screen is prefilled' );
ptk_test_ok( false !== strpos( $to_answer, 'drop-off' ) || false !== strpos( $to_answer, 'drop-off' ), 'with the words as typed' );
ptk_test_ok( false === strpos( $to_answer, ' ' ), 'the prefilled url is encoded, never left with spaces' );

$short_word = $r::destination( 'word', 'room parent', $urls );
ptk_test_ok( false !== strpos( $short_word, 'ptk_prefill_title=room%20parent' ), 'a short phrase is carried into the word screen' );
$long_word = $r::destination( 'word', 'Someone asked me what ASE means', $urls );
ptk_test_ok( false === strpos( $long_word, 'ptk_prefill_title' ), 'a whole sentence is not dropped into a field that wants one word' );

$to_fix = $r::destination( 'fix', 'The pickup time is wrong', $urls );
ptk_test_ok( false !== strpos( $to_fix, 's=pickup' ), 'a fix searches for the distinctive word: ' . $to_fix );
ptk_test_ok( false === strpos( $to_fix, 'wrong' ), 'the words that only said "this is broken" are left out of the search' );

$long_fix = $r::destination( 'fix', 'The spring carnival ticket price and the pickup time are both wrong', $urls );
ptk_test_ok( false === strpos( $long_fix, 's=' ), 'too many words means no search at all, not an empty result' );

$to_news = $r::destination( 'newsletter', 'We have a PTA meeting next Thursday', $urls );
ptk_test_ok( $urls['newsletter'] === $to_news, 'the newsletter opens as it always does' );

ptk_test_ok( '' === $r::destination( 'answer', 'anything', array() ), 'no url means no link, never a broken one' );

ptk_test_done();
