<?php
/**
 * "EXAMPLE — a finished newsletter (do not publish)" (round 3.1, spec item 9).
 *
 * A finished-looking example newsletter, so a first-time volunteer has
 * something to look at before writing their own instead of an empty form
 * and a guess. Created once per site, as a DRAFT, filled with realistic
 * #040-style content (announcement callout, top story, two shorter stories,
 * quick notes, three events, footer) with three bundled stock photos
 * (Pexels, free to use) so it looks like a real issue. 4.8.0 added the
 * photos; sites whose example was created before that get them once via
 * maybe_add_photos().
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

    /** Set once the bundled photos have been added to an existing example. Never cleared. */
    const OPTION_PHOTOS = 'ptk_example_newsletter_photos_v1';

    /** Attachment meta marking a media item as one of the example's bundled photos (value = its key). */
    const META_PHOTO_KEY = '_ptk_example_photo';

    /** Bundled photos: key => file in assets/images/example/, alt text. */
    const PHOTOS = array(
        'first-week'    => array( 'file' => 'first-week.jpg', 'alt' => 'Students cheering in a classroom' ),
        'homework-club' => array( 'file' => 'homework-club.jpg', 'alt' => 'Students working on worksheets at a table' ),
        'supply-drive'  => array( 'file' => 'supply-drive.jpg', 'alt' => 'A student unpacking school supplies at her desk' ),
    );

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_create' ) );

        // Round 3.1 fix (item 3): PTK_Newsletter_Builder::handle_submission()
        // only blocks publishing the example through the Builder's OWN form.
        // Quick Edit, Bulk Edit, the block editor, and the REST API all save
        // a post through wp_insert_post()/wp_update_post() directly, none of
        // which go anywhere near the Builder -- so the same guard has to
        // live here too, at the one filter every one of those paths runs
        // through right before the database write.
        add_filter( 'wp_insert_post_data', array( __CLASS__, 'force_draft' ), 10, 2 );
    }

    /**
     * The example newsletter must never become publicly visible, no matter
     * which of WordPress's several "save a post" paths is used. Runs on
     * every post save (not just pta_newsletter), so it starts by checking
     * this IS an already-flagged example -- a brand-new post has no ID yet
     * and can never be the example, so this only ever affects a save to an
     * existing example post.
     *
     * @param array $data    Slashed post data about to be saved.
     * @param array $postarr Raw $_POST-derived array, including ID for an update.
     * @return array
     */
    public static function force_draft( array $data, array $postarr ) {
        $post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
        if ( ! $post_id || ! self::is_example( $post_id ) ) {
            return $data;
        }

        // Anything that isn't already a non-public status gets forced back
        // to draft -- 'publish', 'future', 'private', and 'pending' (pending
        // still shows to anyone with edit_others_posts) all count as "could
        // go live," so all of them are caught, not just 'publish'. Trash and
        // auto-draft pass through untouched: deleting the example must
        // still work.
        $already_safe = array( 'draft', 'auto-draft', 'trash' );
        if ( ! in_array( $data['post_status'], $already_safe, true ) ) {
            $data['post_status'] = 'draft';
        }

        return $data;
    }

    /**
     * Create the example newsletter once per site, ever. Never fatal: any
     * failure (post type not yet registered, a blocked insert) just means
     * there is no example this time — it is not required for the Builder
     * to work, so nothing here should be allowed to take the page down.
     */
    public static function maybe_create() {
        if ( get_option( self::OPTION_CREATED ) ) {
            self::maybe_add_photos();
            return;
        }
        // A brand-new example already gets its photos in create().
        update_option( self::OPTION_PHOTOS, current_time( 'mysql' ) );

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
        $photos  = self::import_photos();
        $blocks  = self::example_blocks( $photos );
        $post_id = wp_insert_post( array(
            'post_type'    => 'pta_newsletter',
            'post_title'   => self::TITLE,
            'post_content' => '',
            'post_status'  => 'draft',
        ), true );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return;
        }

        update_post_meta( $post_id, self::META_EXAMPLE, 1 );
        // Deliberately NO ptk_nl_issue meta: most_recent_newsletter_id() /
        // next_issue_number() order by that meta key, and a fictional
        // "issue 40" must never be mistaken for a real most-recent issue
        // or push a school's real numbering off by one.
        self::save_example( $post_id, $blocks, $photos );
    }

    /**
     * 4.8.0: an example created before the photos existed gets them once.
     * Left alone if someone has already put their own photos in it.
     */
    public static function maybe_add_photos() {
        if ( get_option( self::OPTION_PHOTOS ) ) {
            return;
        }
        update_option( self::OPTION_PHOTOS, current_time( 'mysql' ) );

        if ( ! post_type_exists( 'pta_newsletter' ) || ! class_exists( 'PTK_Newsletter_Renderer' ) ) {
            return;
        }
        $post_id = self::example_id();
        if ( ! $post_id ) {
            return;
        }
        $stored = json_decode( (string) get_post_meta( $post_id, 'ptk_nl_blocks', true ), true );
        if ( is_array( $stored ) && class_exists( 'PTK_Newsletter_Data' ) && PTK_Newsletter_Data::blocks_image_ids( $stored ) ) {
            return;
        }

        $photos = self::import_photos();
        if ( ! $photos ) {
            return;
        }
        self::save_example( $post_id, self::example_blocks( $photos ), $photos );
    }

    /**
     * Render and store the example's content and meta, the same way a real
     * newsletter's is saved, plus the share picture's photo.
     */
    protected static function save_example( $post_id, array $blocks, array $photos ) {
        $school_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $today       = current_time( 'Y-m-d' );
        $theme       = class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::DEFAULT_THEME : 'harbor-navy';

        $rendered = PTK_Newsletter_Renderer::render( $blocks, array(
            'issue'         => 40,
            'date'          => $today,
            'today'         => $today,
            'theme'         => $theme,
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
            wp_update_post( array(
                'ID'           => $post_id,
                'post_content' => $rendered,
            ) );
        } finally {
            kses_init_filters();
        }

        update_post_meta( $post_id, 'ptk_nl_date', $today );
        update_post_meta( $post_id, 'ptk_nl_theme', $theme );
        update_post_meta( $post_id, 'ptk_nl_blocks', wp_slash( wp_json_encode( $blocks ) ) );

        if ( ! empty( $photos['first-week'] ) && class_exists( 'PTK_Share_Data' ) ) {
            PTK_Share_Data::save_square_photo( $post_id, $photos['first-week'], 50, 30, 0 );
        }
    }

    /**
     * Copy the bundled photos into this site's media library (once -- an
     * existing copy is reused) and return key => attachment id. Missing
     * files or a failed upload just leave that photo out.
     *
     * @return array<string,int>
     */
    protected static function import_photos() {
        $ids = array();
        $dir = trailingslashit( defined( 'PTK_PLUGIN_DIR' ) ? PTK_PLUGIN_DIR : dirname( __DIR__ ) . '/' ) . 'assets/images/example/';

        foreach ( self::PHOTOS as $key => $photo ) {
            $existing = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'meta_key'       => self::META_PHOTO_KEY,
                'meta_value'     => $key,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
            if ( $existing ) {
                $ids[ $key ] = (int) $existing[0];
                continue;
            }

            $path = $dir . $photo['file'];
            if ( ! is_readable( $path ) ) {
                continue;
            }
            $upload = wp_upload_bits( 'example-newsletter-' . $photo['file'], null, file_get_contents( $path ) );
            if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
                continue;
            }
            $attachment_id = wp_insert_attachment( array(
                'post_mime_type' => 'image/jpeg',
                'post_title'     => 'Example newsletter photo (' . $key . ')',
                'post_content'   => '',
                'post_status'    => 'inherit',
            ), $upload['file'] );
            if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                continue;
            }
            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $photo['alt'] );
            update_post_meta( $attachment_id, self::META_PHOTO_KEY, $key );
            $ids[ $key ] = (int) $attachment_id;
        }

        return $ids;
    }

    /**
     * Realistic #040-style content: announcement callout, top story, two
     * shorter stories, quick notes, three events, footer, with photos when
     * they could be imported.
     *
     * @param array<string,int> $photos From import_photos().
     * @return array[]
     */
    protected static function example_blocks( array $photos = array() ) {
        $photo = function ( $key, $fit, $x, $y ) use ( $photos ) {
            if ( empty( $photos[ $key ] ) ) {
                return array( 'image_id' => 0 );
            }
            return array( 'image_id' => $photos[ $key ], 'image_fit' => $fit, 'image_focal_x' => $x, 'image_focal_y' => $y, 'image_zoom' => 0 );
        };

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
                'data' => array_merge( array(
                    'eyebrow'   => 'Top story',
                    'headline'  => 'What a first week!',
                    'body'      => "Thank you to the more than 200 families who stopped by for coffee and donuts on the first day, and to our teachers for such a warm welcome back. Classrooms are settling in and the halls are full again. Here's to a great year together.",
                    'link_url'  => 'https://example.org/photos',
                    'link_text' => 'See more first-week photos',
                ), $photo( 'first-week', 'crop', 50, 42 ) ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_STORY_CARDS,
                'data' => array(
                    'cards' => array(
                        array_merge( array(
                            'eyebrow'   => 'New this year',
                            'heading'   => 'Homework Club starts October 1',
                            'body'      => 'Grades 2–5 can stay in the library Tuesdays and Thursdays until 4 PM for homework help from teachers and parent volunteers. It is free, but space is limited.',
                            'link_url'  => 'https://example.org/homework-club',
                            'link_text' => 'Save a spot',
                        ), $photo( 'homework-club', 'crop', 55, 45 ) ),
                        array_merge( array(
                            'eyebrow'   => 'Volunteers needed',
                            'heading'   => 'Help stock the classroom supply closet',
                            'body'      => 'Teachers are running low on glue sticks, folders and tissues. Drop donations in the bins by the front office through Friday, or grab a shift sorting them.',
                            'link_url'  => 'https://example.org/volunteer',
                            'link_text' => 'Sign up for a shift',
                        ), $photo( 'supply-drive', 'whole', 50, 50 ) ),
                    ),
                ),
            ),
            array(
                'type' => PTK_Newsletter_Data::TYPE_QUICK_NOTES,
                'data' => array(
                    'label' => 'Good to know',
                    'items' => array(
                        array( 'heading' => 'Early dismissal Friday', 'body' => 'All students dismiss at 1 PM for a staff training afternoon. Aftercare runs as usual.', 'link_url' => '', 'link_text' => '' ),
                        array( 'heading' => 'Lunch menu', 'body' => "This week's menu is posted on the school website.", 'link_url' => '', 'link_text' => '' ),
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
