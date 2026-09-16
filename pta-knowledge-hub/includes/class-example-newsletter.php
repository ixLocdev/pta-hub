<?php
/**
 * "EXAMPLE — a finished newsletter (do not publish)" (round 3.1, spec item 9).
 *
 * A finished-looking example newsletter, so a first-time volunteer has
 * something to look at before writing their own instead of an empty form
 * and a guess. Created once per site, as a DRAFT, filled with realistic
 * #040-style content (announcement callout, top story, two shorter stories,
 * quick notes, three events, footer) and no photos -- a bundled photo would
 * be one more thing to keep in sync across every school site this plugin
 * runs on.
 *
 * WHY admin_init, not an activation hook: this plugin is deployed by
 * Network Admin > Plugins > Upload > "Replace current with uploaded",
 * which does NOT fire activation hooks (see the handoff doc and every
 * other version-gated migration in this codebase). admin_init runs on
 * every admin request after the upload, so it is the reliable place to
 * run "do this once, ever" work.
 *
 * WHY a persistent option, not a version check: the newsletter is meant to
 * be deletable. A volunteer (or Lucas) may remove it once real newsletters
 * exist; it must never come back on the next update just because the
 * version string changed. So the guard is "has this ever run", forever,
 * not "does the installed version want it".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Example_Newsletter {

    /** Set (to the day it ran) once creation has been attempted. Never cleared. */
    const OPTION_CREATED = 'ptk_example_newsletter_created';

    /** Post meta flagging a newsletter as the example -- checked to block publishing it. */
    const META_EXAMPLE = 'ptk_nl_is_example';

    const TITLE = 'EXAMPLE — a finished newsletter (do not publish)';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_create' ) );
    }

    /**
     * Create the example newsletter once per site, ever. Never fatal: any
     * failure (post type not yet registered, a blocked insert) just means
     * there is no example this time — it is not required for the Builder
     * to work, so nothing here should be allowed to take the page down.
     */
    public static function maybe_create() {
        if ( get_option( self::OPTION_CREATED ) ) {
            return;
        }

        // Set the flag FIRST: whether create() below succeeds or not, this
        // must never be retried on every single admin request, and must
        // never recreate a copy someone deliberately deleted.
        update_option( self::OPTION_CREATED, current_time( 'mysql' ) );

        if ( ! post_type_exists( 'pta_newsletter' ) || ! class_exists( 'PTK_Newsletter_Renderer' ) ) {
            return;
        }

        self::create();
    }

    /**
     * Is this post the example newsletter? Used by
     * PTK_Newsletter_Builder::handle_submission() to keep it from ever
     * being published by accident, and by render_page() to show the
     * explanatory notice instead of the normal Publish button.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_example( $post_id ) {
        return (bool) get_post_meta( absint( $post_id ), self::META_EXAMPLE, true );
    }

    /**
     * The example's own newsletter id on this site, if it still exists (it
     * is a normal post — a volunteer or Lucas may delete it). Used by the
     * Builder's start screen to link to its preview.
     *
     * @return int 0 if there is no example (never created, or deleted).
     */
    public static function example_id() {
        $posts = get_posts( array(
            'post_type'      => 'pta_newsletter',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_key'       => self::META_EXAMPLE,
            'meta_value'     => '1',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        return empty( $posts ) ? 0 : (int) $posts[0];
    }

    /**
     * Insert the example as a draft, with its structured meta saved the
     * same way a real newsletter's is, so opening it in the Builder shows
     * real, editable-looking content (even though publishing it is
     * blocked).
     */
    protected static function create() {
        $blocks = self::example_blocks();

        $school_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $today       = current_time( 'Y-m-d' );

        $rendered = PTK_Newsletter_Renderer::render( $blocks, array(
            'issue'         => 40,
            'date'          => $today,
            'today'         => $today,
            'theme'         => class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::DEFAULT_THEME : 'harbor-navy',
            'logo_url'      => '',
            'school_name'   => $school_name,
            'join_url'      => '',
            'calendar_url'  => '',
            'news_url'      => '',
            'contact_email' => '',
            'image_url_cb'  => function ( $id ) {
                return wp_get_attachment_image_url( $id, 'large' );
            },
        ) );

        kses_remove_filters();
        try {
            $post_id = wp_insert_post( array(
                'post_type'    => 'pta_newsletter',
                'post_title'   => self::TITLE,
                'post_content' => $rendered,
                'post_status'  => 'draft',
            ), true );
        } finally {
            kses_init_filters();
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return;
        }

        update_post_meta( $post_id, self::META_EXAMPLE, 1 );
        update_post_meta( $post_id, 'ptk_nl_date', $today );
        update_post_meta( $post_id, 'ptk_nl_theme', class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::DEFAULT_THEME : 'harbor-navy' );
        // Deliberately NO ptk_nl_issue meta: most_recent_newsletter_id() /
        // next_issue_number() order by that meta key, and a fictional
        // "issue 40" must never be mistaken for a real most-recent issue
        // or push a school's real numbering off by one.
        update_post_meta( $post_id, 'ptk_nl_blocks', wp_slash( wp_json_encode( $blocks ) ) );
    }

    /**
     * Realistic #040-style content: announcement callout, top story, two
     * shorter stories, quick notes, three events, footer. No photos —
     * see the class docblock.
     *
     * @return array[]
     */
    protected static function example_blocks() {
        return array(
            array(
                'type' => PTK_Newsletter_Data::TYPE_HEADER,
                'data' => array(
                    'school_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
                    'headline'    => 'Week of September 14',
                    'summary'     => 'This is an EXAMPLE newsletter — a finished one to look at, not to publish.',
                    'greeting'    => "Hi families — welcome back to another great week! Here's what's happening.",
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_ANNOUNCEMENT,
                'data' => array(
                    'when'        => 'Closes Thursday, Sept 17 at noon',
                    'headline'    => 'After School Enrichment registration opens Monday',
                    'text'        => 'Twelve classes for grades K–5, Tuesdays and Wednesdays, 3 to 4 PM. PTA members get first pick — join before you register.',
                    'button_text' => 'Go to ASE registration',
                    'button_url'  => 'https://example.org/ase',
                    'timeline'    => array(
                        array( 'date' => '2026-09-14', 'time' => '9:00 AM', 'what' => 'Registration opens to PTA members' ),
                        array( 'date' => '2026-09-16', 'time' => '9:00 AM', 'what' => 'Registration opens to everyone' ),
                        array( 'date' => '2026-09-17', 'time' => 'Noon', 'what' => 'Registration closes' ),
                    ),
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_EVENTS,
                'data' => array(
                    'rows' => array(
                        array( 'date' => '2026-09-18', 'title' => 'Back to School Picnic', 'desc' => 'On the front lawn, 5–7 PM. Bring a blanket.' ),
                        array( 'date' => '2026-09-22', 'title' => 'PTA General Meeting', 'desc' => 'Library, 7 PM. All families welcome.' ),
                        array( 'date' => '2026-09-25', 'title' => 'Picture Day', 'desc' => 'Order forms went home Monday.' ),
                    ),
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_FEATURED,
                'data' => array(
                    'eyebrow'   => 'Top story',
                    'headline'  => 'Our library got a refresh this summer',
                    'body'      => "Over the summer, volunteers repainted the library, added a reading nook, and sorted almost a thousand books onto new shelves. Stop by during drop-off this week to see it — and thank you to everyone who helped.",
                    'link_url'  => 'https://example.org/volunteer',
                    'link_text' => 'See how to help next time',
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_STORY_CARDS,
                'data' => array(
                    'cards' => array(
                        array(
                            'eyebrow'   => 'Reminder',
                            'heading'   => 'Early dismissal is Friday at 1 PM',
                            'body'      => 'All students dismiss at 1 PM for a staff training afternoon. Aftercare still runs as usual.',
                            'link_url'  => '',
                            'link_text' => '',
                        ),
                        array(
                            'eyebrow'   => 'Volunteers needed',
                            'heading'   => 'Picnic setup crew, Friday afternoon',
                            'body'      => 'We need six volunteers from 3 to 5 PM to set up tables and the sign-in table before the picnic.',
                            'link_url'  => 'https://example.org/volunteer',
                            'link_text' => 'Sign up for a shift',
                        ),
                    ),
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_QUICK_NOTES,
                'data' => array(
                    'label' => 'Good to know',
                    'items' => array(
                        array( 'heading' => 'Lunch menu', 'body' => "This week's menu is posted on the school website.", 'link_url' => '', 'link_text' => '' ),
                        array( 'heading' => 'Box Tops', 'body' => 'Scan your receipts in the app — every dollar goes straight to the PTA.', 'link_url' => '', 'link_text' => '' ),
                        array( 'heading' => 'Lost and found', 'body' => 'Filling up fast — check the bin by the front office before it goes to donation.', 'link_url' => '', 'link_text' => '' ),
                    ),
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_FOOTER,
                'data' => array(
                    'signoff' => 'With gratitude, Your PTA Board',
                    'links'   => array(
                        array( 'label' => 'Full calendar', 'url' => 'https://example.org/calendar' ),
                    ),
                ),
            ),
        );
    }
}
