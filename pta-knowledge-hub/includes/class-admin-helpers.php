<?php
/**
 * Admin UX improvements for volunteer content editors.
 *
 * Adds helpful guidance, streamlines the editor, and customizes
 * the admin list view so non-technical volunteers can manage
 * content confidently.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Admin_Helpers {

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_css' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_tips_meta_box' ) );
        add_action( 'edit_form_after_title', array( __CLASS__, 'add_excerpt_guidance' ) );
        add_filter( 'enter_title_here', array( __CLASS__, 'custom_title_placeholder' ), 10, 2 );
        add_filter( 'manage_pta_knowledge_posts_columns', array( __CLASS__, 'custom_columns' ) );
        add_action( 'manage_pta_knowledge_posts_custom_column', array( __CLASS__, 'render_custom_columns' ), 10, 2 );
        add_filter( 'manage_edit-pta_knowledge_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
        add_action( 'admin_head', array( __CLASS__, 'add_admin_inline_styles' ) );
        add_filter( 'post_updated_messages', array( __CLASS__, 'custom_messages' ) );
        add_action( 'admin_notices', array( __CLASS__, 'wizard_banner' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
    }

    /**
     * Register plugin settings.
     */
    public static function register_settings() {
        register_setting( 'ptk_settings', 'ptk_require_login', array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );
        register_setting( 'ptk_settings', 'ptk_show_importer', array(
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        if ( is_multisite() ) {
            register_setting( 'ptk_settings', 'ptk_enable_network_sharing', array(
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ) );
        }
    }

    /**
     * Add a Settings submenu page under PTA Knowledge.
     */
    public static function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Settings',
            'Settings',
            'manage_options',
            'ptk-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    /**
     * Render the settings page.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        // Handle cache clear.
        if ( ! empty( $_POST['ptk_clear_search_cache'] ) && check_admin_referer( 'ptk_clear_cache', 'ptk_clear_cache_nonce' ) ) {
            PTK_Search_Engine::invalidate_cache();
            echo '<div class="notice notice-success is-dismissible"><p>Search cache cleared.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>PTA Hub Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'ptk_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Require Login</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ptk_require_login" value="1"
                                    <?php checked( get_option( 'ptk_require_login', false ) ); ?>>
                                Only logged-in users can view the PTA Hub
                            </label>
                            <p class="description">
                                When enabled, visitors who aren't logged in to WordPress will see a "please log in" message
                                instead of the PTA Hub content. Anyone with a WordPress account on your site can access it
                                &mdash; no special role needed.
                            </p>
                        </td>
                    </tr>
                    <?php if ( get_option( 'ptk_starter_content_imported', false ) ) : ?>
                    <tr>
                        <th scope="row">Starter Content Importer</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ptk_show_importer" value="1"
                                    <?php checked( get_option( 'ptk_show_importer', true ) ); ?>>
                                Show "Import Starter Content" in the menu
                            </label>
                            <p class="description">
                                The importer is hidden automatically after the first import. Enable this to show it again
                                (e.g., if you want to re-import on a fresh site).
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( is_multisite() && is_main_site() ) : ?>
                    <tr>
                        <th scope="row">Network Sharing</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ptk_enable_network_sharing" value="1"
                                    <?php checked( get_option( 'ptk_enable_network_sharing', false ) ); ?>>
                                Enable sharing knowledge entries to all school sites
                            </label>
                            <p class="description">
                                When enabled, you can mark individual entries to be shared across all sites in
                                the network. Shared entries appear on school sites automatically and stay in sync.
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Maintenance</h2>
            <form method="post">
                <?php wp_nonce_field( 'ptk_clear_cache', 'ptk_clear_cache_nonce' ); ?>
                <p>If search results show stale categories or outdated content, clear the cache.</p>
                <button type="submit" name="ptk_clear_search_cache" class="button">Clear Search Cache</button>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue admin stylesheet.
     */
    public static function enqueue_admin_css( $hook ) {
        global $post_type;
        if ( 'pta_knowledge' === $post_type ) {
            wp_enqueue_style(
                'ptk-admin',
                PTK_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                PTK_VERSION
            );
        }
    }

    /**
     * Custom placeholder for the title field.
     */
    public static function custom_title_placeholder( $title, $post ) {
        if ( 'pta_knowledge' === $post->post_type ) {
            return 'Write a clear, searchable title (e.g., "How to Set Up for a Bake Sale")';
        }
        return $title;
    }

    /**
     * Add guidance text below the title, above the editor.
     */
    public static function add_excerpt_guidance( $post ) {
        if ( 'pta_knowledge' !== $post->post_type ) {
            return;
        }

        $cat_slug = '';
        $terms = wp_get_post_terms( $post->ID, 'knowledge_category', array( 'fields' => 'slugs' ) );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $cat_slug = $terms[0];
        }

        ?>
        <div class="ptk-editor-guidance">
            <p><strong>Writing tips for great search results:</strong></p>
            <ul>
                <li><strong>Title</strong> &mdash; Keep it specific and searchable. Think: "What would someone type to find this?"</li>
                <li><strong>Excerpt</strong> &mdash; Write a 1&ndash;2 sentence summary. This appears on search result cards.</li>
                <li><strong>Featured Image</strong> &mdash; Add a photo or graphic. It shows as the card thumbnail.</li>
                <li><strong>Tags</strong> &mdash; Add keywords people might search for (e.g., "bake sale", "fundraiser", "spring").</li>
            </ul>

            <?php if ( $cat_slug ) : ?>
            <hr style="margin: 10px 0;">
            <p><strong>
                <?php
                switch ( $cat_slug ) {
                    case 'how-to-guide':
                        echo 'How-To Guide tips:';
                        break;
                    case 'event-playbook':
                        echo 'Event Playbook tips:';
                        break;
                    case 'faq':
                        echo 'FAQ tips:';
                        break;
                    case 'resource':
                        echo 'Resource tips:';
                        break;
                    default:
                        echo 'Content tips:';
                }
                ?>
            </strong></p>
            <ul>
                <?php
                switch ( $cat_slug ) {
                    case 'how-to-guide':
                        ?>
                        <li>Start with a "What You'll Need" list of materials or prerequisites</li>
                        <li>Use a numbered list for the step-by-step instructions</li>
                        <li>End with tips, common mistakes, or helpful notes</li>
                        <li>Use the <strong>How-To Guide</strong> block pattern for a ready-made structure</li>
                        <?php
                        break;
                    case 'event-playbook':
                        ?>
                        <li>Include the event date, location, and a brief overview</li>
                        <li>Add a timeline working backwards from the event date</li>
                        <li>List supplies needed with quantities and estimated costs</li>
                        <li>Include key contacts and volunteer roles</li>
                        <li>Use the <strong>Event Playbook</strong> block pattern for a ready-made structure</li>
                        <?php
                        break;
                    case 'faq':
                        ?>
                        <li>Write the question as the post title</li>
                        <li>Put a short, copy-friendly answer in the Excerpt field</li>
                        <li>Use the content area for a longer, detailed explanation</li>
                        <li>Use the <strong>FAQ Entry</strong> block pattern for a ready-made structure</li>
                        <?php
                        break;
                    case 'resource':
                        ?>
                        <li>Describe what the resource is and why it&rsquo;s useful</li>
                        <li>Explain how to download or access it</li>
                        <li>Include the file type (PDF, video, template, etc.) in the Entry Details</li>
                        <li>Use the <strong>Resource</strong> block pattern for a ready-made structure</li>
                        <?php
                        break;
                }
                ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add a "Quick Tips" meta box in the sidebar.
     */
    public static function add_tips_meta_box() {
        add_meta_box(
            'ptk_quick_tips',
            'Quick Tips',
            array( __CLASS__, 'render_tips_meta_box' ),
            'pta_knowledge',
            'side',
            'high'
        );
    }

    /**
     * Render the tips meta box content.
     */
    public static function render_tips_meta_box( $post ) {
        ?>
        <div class="ptk-tips">
            <p><strong>Category guide:</strong></p>
            <ul>
                <li><span class="ptk-cat-badge ptk-cat-howto">How-To Guide</span> Step-by-step procedures</li>
                <li><span class="ptk-cat-badge ptk-cat-event">Event Playbook</span> Event plans &amp; timelines</li>
                <li><span class="ptk-cat-badge ptk-cat-faq">FAQ</span> Questions &amp; ready answers</li>
                <li><span class="ptk-cat-badge ptk-cat-resource">Resource</span> Videos, templates, flyers</li>
                <li><span class="ptk-cat-badge ptk-cat-glossary">Glossary</span> Term definitions</li>
                <li><span class="ptk-cat-badge ptk-cat-checklist">Checklist</span> To-do lists</li>
                <li><span class="ptk-cat-badge ptk-cat-policy">Policy</span> Rules &amp; guidelines</li>
            </ul>
            <hr>
            <p><strong>Making content findable:</strong></p>
            <ol>
                <li>Use a descriptive title</li>
                <li>Pick the right category</li>
                <li>Write a short excerpt (summary)</li>
                <li>Add 3&ndash;5 relevant tags</li>
                <li>Add a featured image if you have one</li>
            </ol>
        </div>
        <?php
    }

    /**
     * Customize admin list columns.
     */
    public static function custom_columns( $columns ) {
        $new_columns = array();
        $new_columns['cb']             = $columns['cb'];
        $new_columns['title']          = $columns['title'];
        $new_columns['category']       = 'Category';
        $new_columns['ptk_tags']       = 'Tags';
        $new_columns['ptk_visibility'] = 'Visibility';
        $new_columns['ptk_feedback']   = 'Feedback';
        $new_columns['date']           = $columns['date'];
        return $new_columns;
    }

    /**
     * Render custom column content.
     */
    public static function render_custom_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'category':
                $terms = wp_get_post_terms( $post_id, 'knowledge_category', array( 'fields' => 'names' ) );
                echo ! empty( $terms ) ? esc_html( implode( ', ', $terms ) ) : '<span class="ptk-muted">No category</span>';
                break;

            case 'ptk_tags':
                $tags = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
                if ( ! empty( $tags ) ) {
                    $badges = array_map( function( $tag ) {
                        return '<span class="ptk-tag-chip">' . esc_html( $tag ) . '</span>';
                    }, array_slice( $tags, 0, 5 ) );
                    echo implode( ' ', $badges );
                    if ( count( $tags ) > 5 ) {
                        echo ' <span class="ptk-muted">+' . ( count( $tags ) - 5 ) . ' more</span>';
                    }
                } else {
                    echo '<span class="ptk-muted">No tags</span>';
                }
                break;

            case 'ptk_visibility':
                if ( class_exists( 'PTK_Role_Access' ) ) {
                    PTK_Role_Access::render_admin_column( $column, $post_id );
                } else {
                    echo '<span class="ptk-muted">Everyone</span>';
                }
                break;

            case 'ptk_feedback':
                if ( class_exists( 'PTK_Feedback' ) ) {
                    $counts = PTK_Feedback::get_feedback_counts( $post_id );
                    if ( $counts['helpful'] > 0 || $counts['not_helpful'] > 0 ) {
                        echo '<span style="color:#059669;" title="Helpful">&#128077; ' . esc_html( $counts['helpful'] ) . '</span> ';
                        echo '<span style="color:#dc2626;" title="Not helpful">&#128078; ' . esc_html( $counts['not_helpful'] ) . '</span>';
                    } else {
                        echo '<span class="ptk-muted">No votes</span>';
                    }
                }
                break;
        }
    }

    /**
     * Make columns sortable.
     */
    public static function sortable_columns( $columns ) {
        $columns['category'] = 'category';
        return $columns;
    }

    /**
     * Add inline admin styles for the guidance and badges.
     */
    public static function add_admin_inline_styles() {
        global $post_type;
        if ( 'pta_knowledge' !== $post_type ) {
            return;
        }
        ?>
        <style>
            .ptk-editor-guidance {
                background: #f0f6fc;
                border: 1px solid #c3dafe;
                border-radius: 6px;
                padding: 12px 16px;
                margin: 12px 0 0;
                font-size: 13px;
                line-height: 1.5;
            }
            .ptk-editor-guidance ul { margin: 6px 0 0 18px; }
            .ptk-editor-guidance li { margin-bottom: 4px; }

            .ptk-tips ul, .ptk-tips ol { margin: 6px 0 0 18px; font-size: 12px; }
            .ptk-tips li { margin-bottom: 4px; }

            .ptk-cat-badge {
                display: inline-block;
                font-size: 10px;
                font-weight: 600;
                padding: 2px 6px;
                border-radius: 4px;
                margin-right: 4px;
                vertical-align: middle;
            }
            .ptk-cat-howto { background: #dbeafe; color: #1e40af; }
            .ptk-cat-event { background: #d1fae5; color: #065f46; }
            .ptk-cat-faq   { background: #ede9fe; color: #5b21b6; }
            .ptk-cat-resource { background: #ffedd5; color: #9a3412; }
            .ptk-cat-glossary { background: #ccfbf1; color: #115e59; }
            .ptk-cat-checklist { background: #dcfce7; color: #166534; }
            .ptk-cat-policy { background: #e2e8f0; color: #334155; }

            .ptk-tag-chip {
                display: inline-block;
                background: #f3f4f6;
                color: #374151;
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 10px;
                margin: 1px 2px;
            }
            .ptk-muted { color: #9ca3af; font-style: italic; }
        </style>
        <?php
    }

    /**
     * Show a banner on the knowledge entries list encouraging use of the wizard.
     */
    public static function wizard_banner() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-pta_knowledge' !== $screen->id ) {
            return;
        }
        $wizard_url = admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-content-wizard' );
        ?>
        <div class="notice notice-info ptk-wizard-banner" style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-left-color:#2271b1;">
            <span class="dashicons dashicons-welcome-learn-more" style="font-size:24px;color:#2271b1;"></span>
            <div style="flex:1;">
                <strong>Want an easier way to add content?</strong>
                Use the guided wizard — just fill in the blanks and everything is formatted for you.
            </div>
            <a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary">Open Content Wizard</a>
        </div>
        <?php
    }

    /**
     * Custom update messages for the post type.
     */
    public static function custom_messages( $messages ) {
        global $post;
        if ( ! $post || 'pta_knowledge' !== $post->post_type ) {
            return $messages;
        }

        $permalink = get_permalink( $post->ID );

        $messages['pta_knowledge'] = array(
            0  => '',
            1  => sprintf( 'Knowledge entry updated. <a href="%s">View entry</a>', esc_url( $permalink ) ),
            2  => 'Custom field updated.',
            3  => 'Custom field deleted.',
            4  => 'Knowledge entry updated.',
            5  => isset( $_GET['revision'] ) ? sprintf( 'Entry restored to revision from %s', wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
            6  => sprintf( 'Knowledge entry published! <a href="%s">View entry</a>', esc_url( $permalink ) ),
            7  => 'Entry saved.',
            8  => sprintf( 'Entry submitted. <a target="_blank" href="%s">Preview entry</a>', esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
            9  => sprintf( 'Entry scheduled for: <strong>%s</strong>.', date_i18n( 'M j, Y @ G:i', strtotime( $post->post_date ) ) ),
            10 => 'Entry draft updated.',
        );

        return $messages;
    }
}
