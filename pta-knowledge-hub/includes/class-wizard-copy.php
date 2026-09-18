<?php
/**
 * The Content Wizard's words — Phase 4, task 1.
 *
 * A pure map, no WordPress calls, no state, same shape as PTK_Builder_Copy
 * (class-builder-copy.php): PTK_Wizard_Copy::label() picks a field's
 * question (new look on) or its current label (new look off) from labels()
 * below. PTK_Content_Wizard renders every label this table covers through
 * this instead of a hardcoded string, so with the new look off the output
 * is byte-for-byte what it always was — the 'off' string in every entry
 * below IS today's literal label text.
 *
 * Kept in its own file, with zero WordPress dependency, so it can be
 * unit-tested with plain php and no plugin bootstrap (see
 * tests/test-wizard-copy.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Wizard_Copy {

    /**
     * Words the spec (§6) says the redesigned Hub never shows a volunteer.
     * Checked against every 'on' string in labels()/meta()/notices() by the
     * test suite. Same list as PTK_Builder_Copy::BANNED_WORDS.
     */
    const BANNED_WORDS = array(
        'Add New',
        'Edit',
        'Publish',
        'Status',
        'Metadata',
        'Category',
        'Taxonomy',
        'Template',
        'Permalink',
        'Slug',
        'Featured Image',
        'Excerpt',
        'Visibility',
        'Author',
        'Draft',
        'Trash',
        'Revision',
        'Custom Fields',
        'Screen Options',
    );

    /**
     * The field label map: array( field => array( 'off' => today's literal
     * label, 'on' => the new-look question ) ). One flat table -- unlike the
     * Builder's map there's no per-block-type nesting here, because the
     * wizard's field keys are already unique across the whole form (the
     * plan's table, §2).
     *
     * Only fields whose wording actually changes are listed. A field with no
     * entry here keeps printing its original hardcoded label untouched.
     * 'introduction' is shared by every field whose literal label today is
     * the bare word "Introduction" (the how-to guide's and the checklist's)
     * -- the plan's table gives it once, not once per category. Every
     * category-specific field not in the plan's table -- File Type, Last
     * Reviewed, Effective Date, and so on -- is deliberately left alone.
     *
     * @return array
     */
    public static function labels() {
        return array(
            'title'          => array( 'off' => 'Title', 'on' => "What's the question families ask?" ),
            'excerpt'        => array( 'off' => 'Short Summary', 'on' => 'If someone reads one line, what should it say?' ),
            'tags'           => array( 'off' => 'Tags', 'on' => 'What words might someone search for?' ),
            'featured_image' => array( 'off' => 'Featured Image', 'on' => 'Would a picture help?' ),
            'introduction'    => array( 'off' => 'Introduction', 'on' => 'How would you explain it in a sentence?' ),
            'difficulty'     => array( 'off' => 'Difficulty', 'on' => 'How hard is it?' ),
            'time_estimate'  => array( 'off' => 'Time Estimate', 'on' => 'How long does it take?' ),
            'materials'      => array( 'off' => "What You'll Need", 'on' => 'Does anyone need anything first?' ),
            'steps'          => array( 'off' => 'Steps', 'on' => 'What are the steps?' ),
            'resource_url'   => array( 'off' => 'Resource URL', 'on' => 'Where is the file or page?' ),
            'resource_howto' => array( 'off' => 'How to Use', 'on' => 'How should families use it?' ),
        );
    }

    /**
     * A field's label: the new-look question when $on is true, else today's
     * literal label. Falls back to $default (or '') when the field has no
     * entry in labels() -- i.e. its wording never changes.
     *
     * @param bool   $on      PTK_Hub_Look::on().
     * @param string $field   Field key (see labels()).
     * @param string $default What to return when this field has no entry.
     * @return string
     */
    public static function label( $on, $field, $default = '' ) {
        $map = self::labels();
        if ( ! isset( $map[ $field ] ) ) {
            return $default;
        }
        return $on ? $map[ $field ]['on'] : $map[ $field ]['off'];
    }

    /**
     * The wizard-wide bits: the "4 quick steps" meta line, the category
     * step's own heading, and the two save-as choices.
     *
     * @return array
     */
    public static function meta() {
        return array(
            'intro_meta'  => array( 'off' => '4 quick steps &middot; takes about 5 minutes', 'on' => 'Four short questions. About five minutes.' ),
            'category'    => array( 'off' => 'What type of entry are you creating?', 'on' => 'What kind of thing is this?' ),
            'save_draft'  => array( 'off' => 'Draft (recommended &mdash; review before publishing)', 'on' => 'Keep it to myself for now' ),
            'save_publish' => array( 'off' => 'Publish now (visible immediately)', 'on' => 'Put it on the Hub' ),
        );
    }

    /**
     * A meta string: the new-look wording when $on is true, else today's
     * literal text. Falls back to $default when $key has no entry.
     *
     * @param bool   $on      PTK_Hub_Look::on().
     * @param string $key     A meta() key.
     * @param string $default What to return when this key has no entry.
     * @return string
     */
    public static function meta_text( $on, $key, $default = '' ) {
        $map = self::meta();
        if ( ! isset( $map[ $key ] ) ) {
            return $default;
        }
        return $on ? $map[ $key ]['on'] : $map[ $key ]['off'];
    }

    /**
     * Every entry in labels() plus meta(), flattened to key => array('off',
     * 'on'), for the test suite's one-pass banned-word / question-shape
     * sweep. Not used by production code.
     *
     * @return array
     */
    public static function all_entries() {
        return array_merge( self::labels(), self::meta() );
    }

    /**
     * Task 3: the confirmation screen's headline + stamp, keyed by
     * 'created' (a brand-new entry, published), 'updated' (an existing
     * entry, re-saved while published) and 'draft' (saved as a draft,
     * either way). 'off' is today's literal heading text (unused by
     * notice_text()'s $default path but kept here so the whole table is
     * self-documenting); 'on' is the plan's plain-English confirmation;
     * 'stamp' is array( text, state ) for PTK_Hub_UI::stamp(), or null for
     * no stamp.
     *
     * @return array
     */
    public static function notices() {
        return array(
            'created' => array(
                'off'   => 'Entry Created Successfully!',
                'on'    => "You're all set. Families can find this on the Hub.",
                'stamp' => array( 'SENT', 'success' ),
            ),
            'updated' => array(
                'off'   => 'Entry Updated Successfully!',
                'on'    => 'Updated. Families see the new version now.',
                'stamp' => array( 'SENT', 'success' ),
            ),
            'draft'   => array(
                'off'   => 'Entry Created Successfully!',
                'on'    => 'Saved. Nobody sees it yet.',
                'stamp' => array( 'NOT SENT YET', 'dim' ),
            ),
        );
    }

    /**
     * A confirmation heading: the new-look sentence when $on is true, else
     * $default (today's literal heading -- callers pass it explicitly
     * since 'created' and 'updated' already differ in the off-look and
     * $default carries that, rather than notices()'s own 'off' value).
     *
     * @param bool   $on      PTK_Hub_Look::on().
     * @param string $key     A notices() key ('created', 'updated', 'draft').
     * @param string $default What to return when off, or when $key has no entry.
     * @return string
     */
    public static function notice_text( $on, $key, $default = '' ) {
        $map = self::notices();
        if ( ! $on || ! isset( $map[ $key ] ) ) {
            return $default;
        }
        return $map[ $key ]['on'];
    }

    /**
     * A confirmation's stamp: array( text, state ) for PTK_Hub_UI::stamp(),
     * or null when $on is false or $key has no entry.
     *
     * @param bool   $on  PTK_Hub_Look::on().
     * @param string $key A notices() key.
     * @return array|null
     */
    public static function notice_stamp( $on, $key ) {
        $map = self::notices();
        if ( ! $on || ! isset( $map[ $key ]['stamp'] ) ) {
            return null;
        }
        return $map[ $key ]['stamp'];
    }

    /**
     * Task 3: server-side validation messages, keyed by today's exact
     * literal $submission_error string (PTK_Content_Wizard sets that
     * property before it knows PTK_Hub_Look::on(), so there's no semantic
     * key to look up by -- the off string itself is the key, same
     * off-is-the-source-of-truth convention as the rest of this class).
     *
     * @return array
     */
    public static function validation() {
        return array(
            'Please add a Title and pick a category, then save again.'
                => 'This needs a title so families can find it. Pick a category too, then save again.',
            'Please provide a Quick Answer for your FAQ entry.'
                => 'This needs a quick answer so families have something to read.',
            'Please provide a Description for your Resource.'
                => 'This needs a description so families know what it is.',
            'Please provide a Definition for your Glossary Term.'
                => 'This needs a definition so families know what it means.',
            'Please provide a Summary for your Policy entry.'
                => 'This needs a summary so families know what it says.',
            'That entry no longer exists — it may have been deleted. Nothing was saved.'
                => 'This entry is gone — someone may have deleted it. Nothing was saved.',
        );
    }

    /**
     * A validation message: the new-look sentence when $on is true and
     * $text is a known literal, else $text unchanged.
     *
     * @param bool   $on   PTK_Hub_Look::on().
     * @param string $text The literal $submission_error text.
     * @return string
     */
    public static function validation_text( $on, $text ) {
        if ( ! $on ) {
            return $text;
        }
        $map = self::validation();
        return isset( $map[ $text ] ) ? $map[ $text ] : $text;
    }
}
