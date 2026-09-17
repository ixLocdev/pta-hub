<?php
/**
 * The Newsletter Builder's words — Phase 3, task 1.
 *
 * A pure map, no WordPress calls, no state: PTK_Builder_Copy::label() picks
 * a field's question (new look on) or its current label (new look off) from
 * labels() below. PTK_Newsletter_Builder renders every field label through
 * this instead of a hardcoded string, so with the new look off the output is
 * byte-for-byte what it always was — the 'off' string in every entry below
 * IS today's literal label text.
 *
 * Kept in its own file, with zero WordPress dependency, so it can be
 * unit-tested with plain php and no plugin bootstrap (see
 * tests/test-builder-copy.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Builder_Copy {

    /**
     * Words the spec (§6) says the redesigned Hub never shows a volunteer.
     * Checked against every 'on' string in labels()/step_titles() by the
     * test suite.
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
     * The field label map: array( type => array( field => array( 'off' =>
     * today's literal label, 'on' => the new-look question ) ) ).
     *
     * Only fields whose wording actually changes are listed — a field with
     * no entry here keeps printing its original hardcoded label untouched
     * (e.g. "School name", the announcement's per-date rows, "Group label").
     * 'story_cards' isn't in the spec's own table (it only names "Top
     * story"), but its fields are structurally identical to 'featured' --
     * same eyebrow/heading/body/image/link shape -- so it reuses the same
     * questions rather than leaving half the Stories step untranslated.
     * Likewise the generic "Link address"/"Link wording" question pair is
     * reused everywhere that literal pair of labels appears today (top
     * story, stories, quick notes, footer links) — the spec's table gives
     * it once, not once per block.
     *
     * @return array
     */
    public static function labels() {
        return array(
            'header'       => array(
                'headline' => array( 'off' => 'Headline', 'on' => "What's the big news this week?" ),
                'summary'  => array( 'off' => 'One-line summary', 'on' => 'If families read one line, what should it say?' ),
                'greeting' => array( 'off' => 'Greeting', 'on' => 'How do you want to say hello?' ),
            ),
            'announcement' => array(
                'when'        => array( 'off' => 'When', 'on' => 'When does it happen, or close?' ),
                'headline'    => array( 'off' => 'Headline', 'on' => "What's the one thing families must not miss?" ),
                'text'        => array( 'off' => 'Text', 'on' => 'What should families know about it?' ),
                'button_text' => array( 'off' => 'Button words', 'on' => 'What should the button say?' ),
                'button_url'  => array( 'off' => 'Button link', 'on' => 'Where should it take them?' ),
            ),
            'events'       => array(
                'date'  => array( 'off' => 'Date', 'on' => 'When is it?' ),
                'title' => array( 'off' => 'Title', 'on' => "What's it called?" ),
                'desc'  => array( 'off' => 'Description', 'on' => 'One short line about it' ),
            ),
            'featured'     => array(
                'eyebrow'  => array( 'off' => 'Short label', 'on' => 'What kind of story is this?' ),
                'headline' => array( 'off' => 'Headline', 'on' => "What's the news?" ),
                'body'     => array( 'off' => 'Story', 'on' => 'Tell it in a paragraph or two' ),
                'image'    => array( 'off' => 'Image', 'on' => 'Would a photo help?' ),
                'link_url' => array( 'off' => 'Link address', 'on' => 'Where should families go for more?' ),
                'link_text' => array( 'off' => 'Link wording', 'on' => 'What should the link say?' ),
            ),
            'story_cards'  => array(
                'eyebrow'   => array( 'off' => 'Short label', 'on' => 'What kind of story is this?' ),
                'heading'   => array( 'off' => 'Heading', 'on' => "What's the news?" ),
                'body'      => array( 'off' => 'Story', 'on' => 'Tell it in a paragraph or two' ),
                'image'     => array( 'off' => 'Image', 'on' => 'Would a photo help?' ),
                'link_url'  => array( 'off' => 'Link address', 'on' => 'Where should families go for more?' ),
                'link_text' => array( 'off' => 'Link wording', 'on' => 'What should the link say?' ),
            ),
            'quick_notes'  => array(
                'body'      => array( 'off' => 'Text', 'on' => 'Anything else families should know?' ),
                'link_url'  => array( 'off' => 'Link address', 'on' => 'Where should families go for more?' ),
                'link_text' => array( 'off' => 'Link wording', 'on' => 'What should the link say?' ),
            ),
            'footer'       => array(
                'signoff'    => array( 'off' => 'Sign-off', 'on' => 'How do you want to sign off?' ),
                'link_label' => array( 'off' => 'Link wording', 'on' => 'What should the link say?' ),
                'link_url'   => array( 'off' => 'Link address', 'on' => 'Where should families go for more?' ),
            ),
        );
    }

    /**
     * A field's label: the new-look question when $on is true, else today's
     * literal label. Falls back to $default (or '') when the field has no
     * entry in labels() — i.e. its wording never changes.
     *
     * @param bool   $on      PTK_Hub_Look::on().
     * @param string $type    Block type slug (header, announcement, events, …).
     * @param string $field   Field key.
     * @param string $default What to return when this field has no entry.
     * @return string
     */
    public static function label( $on, $type, $field, $default = '' ) {
        $map = self::labels();
        if ( ! isset( $map[ $type ][ $field ] ) ) {
            return $default;
        }
        return $on ? $map[ $type ][ $field ]['on'] : $map[ $type ][ $field ]['off'];
    }

    /**
     * Step 4 and 5's plainer names (spec: "Put it in order" / "Send it
     * out"). Steps 1-3 already read the same in both looks, so they aren't
     * listed here — steps() keeps their literal titles untouched.
     *
     * @return array
     */
    public static function step_titles() {
        return array(
            4 => array( 'off' => 'Finish editing', 'on' => 'Put it in order' ),
            5 => array( 'off' => 'Publish & share', 'on' => 'Send it out' ),
        );
    }

    /**
     * A step's title: the new-look name when $on is true, else today's
     * literal title.
     *
     * @param bool $on          PTK_Hub_Look::on().
     * @param int  $step_number Step number.
     * @param string $default   What to return when this step has no entry.
     * @return string
     */
    public static function step_title( $on, $step_number, $default = '' ) {
        $map = self::step_titles();
        if ( ! isset( $map[ $step_number ] ) ) {
            return $default;
        }
        return $on ? $map[ $step_number ]['on'] : $map[ $step_number ]['off'];
    }

    /**
     * Block types that fold on the new look (task 2). Header stays out of
     * this list on purpose -- it's the only thing on step 1, so a fold
     * around it would just be a wrapper. Everything else the spec names
     * (Announcement, Coming up, Top story, Stories, Quick notes, Footer)
     * folds inside its step.
     *
     * @return string[]
     */
    public static function foldable_types() {
        return array( 'announcement', 'events', 'featured', 'story_cards', 'quick_notes', 'footer' );
    }

    /**
     * Trim to $len chars on a word boundary, with a trailing "…" when cut.
     *
     * @param string $text
     * @param int    $len
     * @return string
     */
    private static function shorten( $text, $len ) {
        $text = trim( (string) $text );
        if ( '' === $text || mb_strlen( $text ) <= $len ) {
            return $text;
        }
        $cut = mb_substr( $text, 0, $len );
        $at  = mb_strrpos( $cut, ' ' );
        if ( false !== $at && $at > 0 ) {
            $cut = mb_substr( $cut, 0, $at );
        }
        return rtrim( $cut ) . '…';
    }

    /**
     * The one-line summary a closed section shows -- "3 dates added",
     * "Ready — ASE registration opens Monday", "Nothing yet". Pure: takes
     * exactly the block's sanitized 'data' array, no WordPress calls, no
     * escaping (the caller escapes, same convention as the rest of this
     * file's plain-text helpers).
     *
     * @param string $type Block type slug (see foldable_types()).
     * @param array  $data Sanitized block data.
     * @return string
     */
    public static function section_summary( $type, array $data ) {
        switch ( $type ) {

            case 'announcement':
                $headline = isset( $data['headline'] ) ? trim( (string) $data['headline'] ) : '';
                $when     = isset( $data['when'] ) ? trim( (string) $data['when'] ) : '';
                $text     = isset( $data['text'] ) ? trim( (string) $data['text'] ) : '';
                $gist     = '' !== $headline ? $headline : ( '' !== $when ? $when : $text );
                return '' !== $gist ? 'Ready — ' . self::shorten( $gist, 60 ) : 'Nothing yet';

            case 'events':
                $rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? count( $data['rows'] ) : 0;
                if ( 0 === $rows ) {
                    return 'Nothing yet';
                }
                return 1 === $rows ? '1 date added' : $rows . ' dates added';

            case 'featured':
                $headline = isset( $data['headline'] ) ? trim( (string) $data['headline'] ) : '';
                $body     = isset( $data['body'] ) ? trim( (string) $data['body'] ) : '';
                $gist     = '' !== $headline ? $headline : $body;
                return '' !== $gist ? 'Ready — ' . self::shorten( $gist, 60 ) : 'Nothing yet';

            case 'story_cards':
                $cards = isset( $data['cards'] ) && is_array( $data['cards'] ) ? count( $data['cards'] ) : 0;
                if ( 0 === $cards ) {
                    return 'Nothing yet';
                }
                return 1 === $cards ? '1 story added' : $cards . ' stories added';

            case 'quick_notes':
                $items = isset( $data['items'] ) && is_array( $data['items'] ) ? count( $data['items'] ) : 0;
                if ( 0 === $items ) {
                    return 'Nothing yet';
                }
                return 1 === $items ? '1 note added' : $items . ' notes added';

            case 'footer':
                $signoff = isset( $data['signoff'] ) ? trim( (string) $data['signoff'] ) : '';
                if ( '' !== $signoff ) {
                    return 'Signed off';
                }
                $links = isset( $data['links'] ) && is_array( $data['links'] ) ? count( $data['links'] ) : 0;
                if ( $links > 0 ) {
                    return 1 === $links ? '1 link added' : $links . ' links added';
                }
                return 'Nothing yet';
        }

        return '';
    }

    /**
     * Is this block's content empty, per the same rule section_summary()
     * uses to say "Nothing yet"? Used to decide whether a step's first
     * foldable section should default open.
     *
     * @param string $type Block type slug.
     * @param array  $data Sanitized block data.
     * @return bool
     */
    public static function section_is_empty( $type, array $data ) {
        return 'Nothing yet' === self::section_summary( $type, $data );
    }
}
