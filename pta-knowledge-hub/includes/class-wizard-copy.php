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
}
