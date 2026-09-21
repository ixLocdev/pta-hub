<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-post-copy.php';

$c = 'PTK_Post_Copy';

// --- the writing screen ---
ptk_test_ok( 'One announcement, on the website. Families see it under Latest news.' === $c::intro(), 'the intro explains what a post is' );
ptk_test_ok( "What's the news?" === $c::headline_field_label(), 'the headline field is labeled plainly' );
ptk_test_ok( 'We still need class parents.' === $c::headline_placeholder(), 'the headline placeholder is an example' );
ptk_test_ok( 'A small line above it (optional)' === $c::kicker_field_label(), 'the kicker field label describes it' );
ptk_test_ok( 'Only worth adding when it tells families something: a date, a deadline, which year it is about.' === $c::kicker_help(), 'the kicker help explains when to use it' );
ptk_test_ok( 'Tell it in a paragraph or two' === $c::words_label(), 'the words field is labeled' );
ptk_test_ok( 'A sentence or two is plenty.' === $c::words_placeholder(), 'the words placeholder sets expectations' );

$chips = $c::chips();
ptk_test_ok( 4 === count( $chips ), 'there are four optional-feature chips' );
ptk_test_ok( '+ a picture' === $chips[0], 'the first chip is a picture' );
ptk_test_ok( '+ steps' === $chips[1], 'the second chip is steps' );
ptk_test_ok( '+ a date' === $chips[2], 'the third chip is a date' );
ptk_test_ok( '+ a button' === $chips[3], 'the fourth chip is a button' );

ptk_test_ok( 'Put it on the website' === $c::primary_button_label(), 'the primary button publishes' );
ptk_test_ok( 'Keep it to myself for now' === $c::secondary_button_label(), 'the secondary button saves privately' );
ptk_test_ok( 'Nobody sees it until you do.' === $c::under_buttons(), 'the reassurance line under the buttons' );

// --- when someone cannot publish ---
ptk_test_ok( 'You can write it, but someone with a webmaster account has to put it on the website.' === $c::cannot_publish(), 'the message when the user cannot publish' );

// --- validation ---
ptk_test_ok( 'This needs a headline, so families know what it is about.' === $c::no_headline(), 'the validation message for missing headline' );
ptk_test_ok( 'There is nothing here yet. Write a headline and a sentence or two.' === $c::nothing_at_all(), 'the validation message for empty post' );

// --- after saving ---
ptk_test_ok( 'It is on the website.' === $c::published(), 'the confirmation when published' );
ptk_test_ok( 'Saved. Nobody sees it yet.' === $c::kept_private(), 'the confirmation when saved privately' );

$next_steps = $c::next_steps();
ptk_test_ok( 3 === count( $next_steps ), 'there are three next steps' );
ptk_test_ok( 'See it the way families see it' === $next_steps[0], 'the first next step is to view it' );
ptk_test_ok( 'Put it in the next newsletter' === $next_steps[1], 'the second next step is to add to newsletter' );
ptk_test_ok( 'Write another' === $next_steps[2], 'the third next step is to write again' );

// --- the "Your posts" list ---
ptk_test_ok( 'Your posts' === $c::list_title(), 'the list title' );
ptk_test_ok( 'Everything this school has put on the website, newest first.' === $c::list_lead(), 'the list lead' );
ptk_test_ok( 'Write a post' === $c::list_add_button(), 'the list add button' );
ptk_test_ok( 'Open it' === $c::card_open(), 'the card open action' );
ptk_test_ok( 'See what families see' === $c::card_view(), 'the card view action' );
ptk_test_ok( 'Remove it' === $c::card_remove(), 'the card remove action' );
ptk_test_ok( 'Written in WordPress, so it opens there.' === $c::card_wordpress(), 'the note when a post is from WordPress' );
ptk_test_ok( 'Open it in WordPress' === $c::card_open_wordpress(), 'the action to open in WordPress' );

$empty = $c::empty_state();
ptk_test_ok( 'Nothing here yet.' === $empty['title'], 'the empty state title' );
ptk_test_ok( 'When you put something on the website, it lands here.' === $empty['text'], 'the empty state text' );

// --- the guard when a post was rewritten in WordPress ---
ptk_test_ok( 'This was changed in WordPress.' === $c::guard_title(), 'the guard title' );
ptk_test_ok( 'Someone edited this outside the Hub. Opening it here would replace what they wrote.' === $c::guard_body(), 'the guard body' );
ptk_test_ok( 'Open it in WordPress' === $c::guard_open_wordpress(), 'the action to open in WordPress' );
ptk_test_ok( 'Go back' === $c::guard_go_back(), 'the action to go back' );

// --- no WordPress or database words anywhere in the screen's words ---
$all = implode( ' ', array(
	$c::intro(),
	$c::headline_field_label(),
	$c::headline_placeholder(),
	$c::kicker_field_label(),
	$c::kicker_help(),
	$c::words_label(),
	$c::words_placeholder(),
	implode( ' ', $c::chips() ),
	$c::primary_button_label(),
	$c::secondary_button_label(),
	$c::under_buttons(),
	$c::cannot_publish(),
	$c::no_headline(),
	$c::nothing_at_all(),
	$c::could_not_save(),
	$c::published(),
	$c::kept_private(),
	implode( ' ', $c::next_steps() ),
	$c::list_title(),
	$c::list_lead(),
	$c::list_add_button(),
	$c::card_open(),
	$c::card_view(),
	$c::card_remove(),
	$c::card_wordpress(),
	$c::card_open_wordpress(),
	$empty['title'],
	$empty['text'],
	$c::guard_title(),
	$c::guard_body(),
	$c::guard_open_wordpress(),
	$c::guard_go_back(),
) );
ptk_test_ok( '' !== $c::could_not_save(), 'a save that failed says so' );
ptk_test_ok( false !== stripos( $c::could_not_save(), 'nothing' ), 'and says nothing went out' );

foreach ( array( 'post type', 'meta', 'excerpt', 'trash', 'publish', 'attachment' ) as $word ) {
	ptk_test_ok( false === stripos( $all, $word ), 'the screen never says "' . $word . '"' );
}

ptk_test_done();
