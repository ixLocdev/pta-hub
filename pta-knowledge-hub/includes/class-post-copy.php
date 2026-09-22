<?php
/**
 * The words on "Your posts" and the single post writing and viewing screens.
 * One announcement, on the website. Pure helper for screen logic to stay about
 * layout and nothing else.
 *
 * Pure: no database, no capabilities, no WordPress functions, so the wording
 * is testable on its own (see tests/test-post-copy.php) and the screen files
 * stay about layout.
 *
 * No database or WordPress word ("post type", "meta", "excerpt", "trash",
 * "publish", "attachment") appears in what a volunteer reads -- a test
 * asserts it. "WordPress" alone IS allowed when honest ("Open it in WordPress").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PTK_Post_Copy {

	/**
	 * The home screen's "Tell families what's happening" card, which is one
	 * intention with two ways out of it. "Just one thing" comes first and
	 * filled: a one-off is the more frequent job, and the weekly is the one
	 * whoever wants it already knows how to find.
	 */
	public static function branch_meta() {
		return 'The weekly newsletter, or a single announcement on the website.';
	}

	/** The filled button on that card. */
	public static function branch_post_button() {
		return 'Just one thing';
	}

	/** The plain one beside it. */
	public static function branch_newsletter_button() {
		return "This week's newsletter";
	}

	/** The intro line on the writing screen. */
	public static function intro() {
		return 'One announcement, on the website. Families see it under Latest news.';
	}

	/** Label for the headline field. */
	public static function headline_field_label() {
		return "What's the news?";
	}

	/** Placeholder for the headline field. */
	public static function headline_placeholder() {
		return 'We still need class parents.';
	}

	/** Label for the kicker field. */
	public static function kicker_field_label() {
		return 'A small line above it (optional)';
	}

	/** Help text for the kicker field. */
	public static function kicker_help() {
		return 'Only worth adding when it tells families something: a date, a deadline, which year it is about.';
	}

	/** Label for the words (body) field. */
	public static function words_label() {
		return 'Tell it in a paragraph or two';
	}

	/** Placeholder for the words (body) field. */
	public static function words_placeholder() {
		return 'A sentence or two is plenty.';
	}

	/**
	 * The four optional-feature chips: picture, steps, date, button.
	 *
	 * @return array
	 */
	public static function chips() {
		return array(
			'+ a picture',
			'+ steps',
			'+ a date',
			'+ a button',
		);
	}

	/** The primary button label on the writing screen. */
	public static function primary_button_label() {
		return 'Put it on the website';
	}

	/** The secondary button label on the writing screen. */
	public static function secondary_button_label() {
		return 'Keep it to myself for now';
	}

	/** The reassurance line under the buttons. */
	public static function under_buttons() {
		return 'Nobody sees it until you do.';
	}

	/** The message when someone cannot publish. */
	public static function cannot_publish() {
		return 'You can write it, but someone with a webmaster account has to put it on the website.';
	}

	/** Validation message when headline is missing. */
	public static function no_headline() {
		return 'This needs a headline, so families know what it is about.';
	}

	/** Validation message when the post is completely empty. */
	public static function nothing_at_all() {
		return 'There is nothing here yet. Write a headline and a sentence or two.';
	}

	/** The message when the save itself failed -- nothing was written. */
	public static function could_not_save() {
		return "That didn't save, and nothing went on the website. Try again.";
	}

	/**
	 * The one stamp the confirmation carries: array( text, state ) for
	 * PTK_Hub_UI::stamp(). Same vocabulary as a newsletter's SENT / NOT
	 * SENT YET, said the way a post is said.
	 *
	 * @param bool $on_the_website
	 * @return array
	 */
	public static function stamp( $on_the_website ) {
		return $on_the_website
			? array( 'ON THE WEBSITE', 'success' )
			: array( 'NOT ON THE WEBSITE YET', 'dim' );
	}

	/** Confirmation message when the post is published. */
	public static function published() {
		return 'It is on the website.';
	}

	/** Confirmation message when the post is saved privately. */
	public static function kept_private() {
		return 'Saved. Nobody sees it yet.';
	}

	/**
	 * The three next-step suggestions after saving.
	 *
	 * @return array
	 */
	public static function next_steps() {
		return array(
			'See it the way families see it',
			'Put it in the next newsletter',
			'Write another',
		);
	}

	/** The "Your posts" list page title. */
	public static function list_title() {
		return 'Your posts';
	}

	/** The lead text on the "Your posts" list page. */
	public static function list_lead() {
		return 'Everything this school has put on the website, newest first.';
	}

	/** The primary action button on the "Your posts" list page. */
	public static function list_add_button() {
		return 'Write a post';
	}

	/** The action to open a post for editing. */
	public static function card_open() {
		return 'Open it';
	}

	/** The action to view a post the way families see it. */
	public static function card_view() {
		return 'See what families see';
	}

	/** The action to remove/delete a post. */
	public static function card_remove() {
		return 'Remove it';
	}

	/** The banner after removing one. Said in one word, because Undo is right there. */
	public static function removed_notice() {
		return 'Removed.';
	}

	/** The way back from that. */
	public static function undo_label() {
		return 'Undo';
	}

	/** The note when a post was written in WordPress. */
	public static function card_wordpress() {
		return 'Written in WordPress, so it opens there.';
	}

	/** The action to open a post that was written in WordPress. */
	public static function card_open_wordpress() {
		return 'Open it in WordPress';
	}

	/**
	 * The empty state for the "Your posts" list, said in the positive.
	 *
	 * @return array
	 */
	public static function empty_state() {
		return array(
			'title' => 'Nothing here yet.',
			'text'  => 'When you put something on the website, it lands here.',
		);
	}

	/** The guard title when a post was rewritten in WordPress. */
	public static function guard_title() {
		return 'This was changed in WordPress.';
	}

	/** The guard body when a post was rewritten in WordPress. */
	public static function guard_body() {
		return 'Someone edited this outside the Hub. Opening it here would replace what they wrote.';
	}

	/** The action to open in WordPress from the guard. */
	public static function guard_open_wordpress() {
		return 'Open it in WordPress';
	}

	/** The action to go back from the guard. */
	public static function guard_go_back() {
		return 'Go back';
	}
}
