<?php
/**
 * Content Wizard — guided form for creating/editing knowledge entries.
 *
 * Provides a simple fill-in-the-blanks interface so volunteers
 * can create properly formatted content without touching the
 * block editor. Each category gets its own form layout:
 *   - How-To Guide: title, intro, dynamic steps (text + optional image + optional link), tips
 *   - Event Playbook: title, overview, date/location/budget, timeline items, supplies, contacts
 *   - FAQ: question (title), short answer, detailed answer
 *   - Resource: title, description, file upload/URL, how-to-use instructions
 *   - Glossary Term: definition, details, example
 *   - Checklist: intro, items, notes
 *   - Policy / Rules: summary, full text, dates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Content_Wizard {

    /**
     * Default knowledge categories with slug => name.
     */
    private static $default_categories = array(
        'how-to-guide'   => 'How-To Guide',
        'faq'            => 'FAQ',
        'glossary'       => 'Glossary Term',
        'checklist'      => 'Checklist',
        'event-playbook' => 'Event Playbook',
        'policy'         => 'Policy / Rules',
        'resource'       => 'Resource',
    );

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_wizard_page' ) );
        add_action( 'admin_menu', array( __CLASS__, 'remove_default_add_new' ), 99 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
        add_action( 'load-post-new.php', array( __CLASS__, 'redirect_add_new_to_wizard' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'add_edit_wizard_row_action' ), 10, 2 );
    }

    /**
     * Add "Edit in Wizard" link to admin list table row actions.
     */
    public static function add_edit_wizard_row_action( $actions, $post ) {
        if ( 'pta_knowledge' !== $post->post_type ) {
            return $actions;
        }

        $wizard_url = add_query_arg(
            array(
                'post_type'   => 'pta_knowledge',
                'page'        => 'ptk-content-wizard',
                'ptk_edit_id' => $post->ID,
            ),
            admin_url( 'edit.php' )
        );

        $actions['edit_wizard'] = sprintf(
            '<a href="%s">Edit in Wizard</a>',
            esc_url( $wizard_url )
        );

        return $actions;
    }

    /**
     * Make the Content Wizard the default landing page for PTA Hub.
     * Reorder submenu: Wizard first, then All Entries, then the rest.
     */
    public static function remove_default_add_new() {
        global $submenu;

        $parent = 'edit.php?post_type=pta_knowledge';

        // Remove the default "Add New" link.
        remove_submenu_page( $parent, 'post-new.php?post_type=pta_knowledge' );

        // Make wizard the first item so clicking "PTA Hub" goes to it.
        if ( isset( $submenu[ $parent ] ) ) {
            $wizard_item   = null;
            $all_entries   = null;
            $others        = array();

            foreach ( $submenu[ $parent ] as $key => $item ) {
                if ( isset( $item[2] ) && 'ptk-content-wizard' === $item[2] ) {
                    $wizard_item = $item;
                } elseif ( isset( $item[2] ) && 'edit.php?post_type=pta_knowledge' === $item[2] ) {
                    $all_entries = $item;
                } else {
                    $others[] = $item;
                }
            }

            // Rebuild: Wizard first (becomes the default), then All Entries, then rest.
            $reordered = array();
            if ( $wizard_item ) {
                $reordered[] = $wizard_item;
            }
            if ( $all_entries ) {
                $reordered[] = $all_entries;
            }
            foreach ( $others as $item ) {
                $reordered[] = $item;
            }

            $submenu[ $parent ] = $reordered;
        }
    }

    /**
     * Redirect "Add New" knowledge entry to the wizard instead of the
     * normal WordPress editor. The wizard has a "Custom" option that
     * sends people to the traditional editor if they need it.
     */
    public static function redirect_add_new_to_wizard() {
        global $typenow;

        if ( 'pta_knowledge' !== $typenow ) {
            return;
        }

        // If the user explicitly chose the traditional editor from the wizard,
        // let them through (the wizard adds ?ptk_classic=1).
        if ( isset( $_GET['ptk_classic'] ) && '1' === $_GET['ptk_classic'] ) {
            return;
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-content-wizard' ) );
        exit;
    }

    /**
     * Add the wizard as a submenu under PTA Knowledge.
     */
    public static function add_wizard_page() {
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'Create New Entry',
            'Create Entry',
            'edit_posts',
            'ptk-content-wizard',
            array( __CLASS__, 'render_wizard' )
        );
    }

    /**
     * Enqueue wizard assets only on our page.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'pta_knowledge_page_ptk-content-wizard' !== $hook ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'ptk-content-wizard',
            PTK_PLUGIN_URL . 'assets/css/content-wizard.css',
            array(),
            PTK_VERSION
        );

        wp_enqueue_script(
            'ptk-content-wizard',
            PTK_PLUGIN_URL . 'assets/js/content-wizard.js',
            array( 'jquery', 'media-upload' ),
            PTK_VERSION,
            true
        );

        // Pass edit data to JS if in edit mode.
        $edit_data = array();
        if ( isset( $_GET['ptk_edit_id'] ) ) {
            $edit_id = absint( $_GET['ptk_edit_id'] );
            if ( $edit_id ) {
                $edit_data = self::get_edit_data( $edit_id );
            }
        }

        wp_localize_script( 'ptk-content-wizard', 'ptkWizardData', array(
            'editMode' => ! empty( $edit_data ),
            'editData' => $edit_data,
        ) );
    }

    /**
     * Ensure all default categories exist in the knowledge_category taxonomy.
     * This is critical for subsites where categories may not have been created yet.
     */
    public static function ensure_default_categories() {
        foreach ( self::$default_categories as $slug => $name ) {
            $term = get_term_by( 'slug', $slug, 'knowledge_category' );
            if ( ! $term ) {
                wp_insert_term( $name, 'knowledge_category', array( 'slug' => $slug ) );
            }
        }
    }

    /**
     * Get edit data for a post to pre-fill the wizard.
     */
    public static function get_edit_data( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'pta_knowledge' !== $post->post_type ) {
            return array();
        }

        // Get category.
        $terms = wp_get_post_terms( $post_id, 'knowledge_category', array( 'fields' => 'slugs' ) );
        $category = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0] : '';

        // Get tags.
        $tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
        $tags_str = is_array( $tags ) ? implode( ', ', $tags ) : '';

        // Get featured image.
        $featured_id = get_post_thumbnail_id( $post_id );
        $featured_url = '';
        if ( $featured_id ) {
            $thumb = wp_get_attachment_image_src( $featured_id, 'thumbnail' );
            $featured_url = $thumb ? $thumb[0] : '';
        }

        $data = array(
            'post_id'       => $post_id,
            'title'         => $post->post_title,
            'excerpt'       => $post->post_excerpt,
            'status'        => $post->post_status,
            'category'      => $category,
            'tags'          => $tags_str,
            'featured_id'   => (int) $featured_id,
            'featured_url'  => $featured_url,
        );

        // Parse category-specific content.
        if ( $category ) {
            $data['fields'] = self::parse_content_for_edit( $post_id, $category );
        }

        return $data;
    }

    /**
     * Parse generated HTML content back into structured field values for editing.
     */
    public static function parse_content_for_edit( $post_id, $category ) {
        $post = get_post( $post_id );
        $content = $post->post_content;
        $fields = array();

        switch ( $category ) {
            case 'how-to-guide':
                $fields['difficulty'] = get_post_meta( $post_id, 'ptk_difficulty', true );
                $fields['time']       = get_post_meta( $post_id, 'ptk_time_estimate', true );

                // Extract intro (first paragraph before any heading).
                if ( preg_match( '/^<!-- wp:paragraph -->\s*<p>(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s', $content, $m ) ) {
                    $fields['intro'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }

                // Extract materials.
                if ( preg_match( "/What You'll Need<\/h2>.*?<ul[^>]*>(.*?)<\/ul>/s", $content, $m ) ) {
                    preg_match_all( '/<li>(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        $fields['materials'] = implode( "\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $items[1] ) );
                    }
                }

                // Extract steps.
                $fields['steps'] = array();
                if ( preg_match_all( '/<h3[^>]*>Step\s+\d+<\/h3>\s*<!-- \/wp:heading -->\s*<!-- wp:paragraph -->\s*<p>(.*?)<\/p>/s', $content, $step_matches ) ) {
                    foreach ( $step_matches[1] as $step_text ) {
                        $fields['steps'][] = html_entity_decode( strip_tags( $step_text ), ENT_QUOTES, 'UTF-8' );
                    }
                }

                // Extract step link URLs and texts from content.
                $fields['step_links'] = array();
                // Look for link buttons after each step paragraph.
                $step_sections = preg_split( '/<h3[^>]*>Step\s+\d+<\/h3>/', $content );
                array_shift( $step_sections ); // Remove content before first step.
                foreach ( $step_sections as $section ) {
                    $link_text = '';
                    $link_url  = '';
                    if ( preg_match( '/ptk-step-link-btn[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $section, $lm ) ) {
                        $link_url  = $lm[1];
                        $link_text = html_entity_decode( strip_tags( $lm[2] ), ENT_QUOTES, 'UTF-8' );
                    }
                    $fields['step_links'][] = array( 'text' => $link_text, 'url' => $link_url );
                }

                // Extract tips.
                if ( preg_match( '/Tips &amp; Notes<\/h2>(.*?)(?=<!-- wp:heading|$)/s', $content, $m ) ) {
                    preg_match_all( '/<li>(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        $fields['tips'] = implode( "\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $items[1] ) );
                    } elseif ( preg_match( '/<p>(.*?)<\/p>/s', $m[1], $pm ) ) {
                        $fields['tips'] = html_entity_decode( strip_tags( $pm[1] ), ENT_QUOTES, 'UTF-8' );
                    }
                }
                break;

            case 'event-playbook':
                $fields['date']     = get_post_meta( $post_id, 'ptk_event_date', true );
                $fields['location'] = get_post_meta( $post_id, 'ptk_location', true );
                $fields['budget']   = get_post_meta( $post_id, 'ptk_budget', true );

                // Extract overview.
                if ( preg_match( '/Overview<\/h2>.*?<p>(.*?)<\/p>/s', $content, $m ) ) {
                    $fields['overview'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }

                // Extract timeline.
                $fields['timeline'] = array();
                if ( preg_match( '/Timeline<\/h2>.*?<ul[^>]*>(.*?)<\/ul>/s', $content, $m ) ) {
                    preg_match_all( '/<li><strong>(.*?):<\/strong>\s*(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        foreach ( $items[1] as $idx => $when ) {
                            $fields['timeline'][] = array(
                                'when' => html_entity_decode( strip_tags( $when ), ENT_QUOTES, 'UTF-8' ),
                                'what' => html_entity_decode( strip_tags( $items[2][ $idx ] ), ENT_QUOTES, 'UTF-8' ),
                            );
                        }
                    }
                }

                // Extract supplies.
                if ( preg_match( '/Supplies Needed<\/h2>.*?<ul[^>]*>(.*?)<\/ul>/s', $content, $m ) ) {
                    preg_match_all( '/<li>(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        $fields['supplies'] = implode( "\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $items[1] ) );
                    }
                }

                // Extract contacts.
                if ( preg_match( '/Key Contacts<\/h2>.*?<ul[^>]*>(.*?)<\/ul>/s', $content, $m ) ) {
                    preg_match_all( '/<li>(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        $fields['contacts'] = implode( "\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $items[1] ) );
                    }
                }
                break;

            case 'faq':
                $fields['reviewed'] = get_post_meta( $post_id, 'ptk_last_reviewed', true );

                if ( preg_match( '/Quick Answer<\/h2>.*?<p[^>]*>(.*?)<\/p>/s', $content, $m ) ) {
                    $fields['short_answer'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }
                if ( preg_match( '/Details<\/h2>(.*?)(?=<!-- wp:heading|<!-- wp:separator|$)/s', $content, $m ) ) {
                    preg_match_all( '/<p>(.*?)<\/p>/s', $m[1], $paras );
                    if ( ! empty( $paras[1] ) ) {
                        $fields['details'] = implode( "\n\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $paras[1] ) );
                    }
                }
                break;

            case 'resource':
                $fields['url']       = get_post_meta( $post_id, 'ptk_resource_url', true );
                $fields['file_type'] = get_post_meta( $post_id, 'ptk_file_type', true );

                if ( preg_match( '/Description<\/h2>.*?<p>(.*?)<\/p>/s', $content, $m ) ) {
                    $fields['description'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }
                if ( preg_match( '/How to Use<\/h2>(.*?)(?=<!-- wp:heading|<!-- wp:separator|$)/s', $content, $m ) ) {
                    preg_match_all( '/<li>(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        $fields['howto'] = implode( "\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $items[1] ) );
                    } elseif ( preg_match( '/<p>(.*?)<\/p>/s', $m[1], $pm ) ) {
                        $fields['howto'] = html_entity_decode( strip_tags( $pm[1] ), ENT_QUOTES, 'UTF-8' );
                    }
                }
                break;

            case 'glossary':
                if ( preg_match( '/What It Means<\/h2>.*?<p[^>]*>(.*?)<\/p>/s', $content, $m ) ) {
                    $fields['definition'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }
                if ( preg_match( '/More Details<\/h2>(.*?)(?=<!-- wp:heading|$)/s', $content, $m ) ) {
                    preg_match_all( '/<p>(.*?)<\/p>/s', $m[1], $paras );
                    if ( ! empty( $paras[1] ) ) {
                        $fields['details'] = implode( "\n\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $paras[1] ) );
                    }
                }
                if ( preg_match( '/Example<\/h2>.*?<p><em>(.*?)<\/em><\/p>/s', $content, $m ) ) {
                    $fields['example'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }
                break;

            case 'checklist':
                // Extract intro.
                if ( preg_match( '/^<!-- wp:paragraph -->\s*<p>(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s', $content, $m ) ) {
                    $fields['intro'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }

                // Extract items.
                $fields['items'] = array();
                if ( preg_match( '/Checklist<\/h2>.*?<ul[^>]*>(.*?)<\/ul>/s', $content, $m ) ) {
                    preg_match_all( '/<li>(.*?)<\/li>/s', $m[1], $items );
                    if ( ! empty( $items[1] ) ) {
                        foreach ( $items[1] as $item ) {
                            // Remove the checkbox character.
                            $clean = preg_replace( '/^[\x{2610}\x{2611}\x{2612}]\s*/u', '', html_entity_decode( strip_tags( $item ), ENT_QUOTES, 'UTF-8' ) );
                            $fields['items'][] = $clean;
                        }
                    }
                }

                // Extract notes.
                if ( preg_match( '/Notes<\/h2>.*?<p>(.*?)<\/p>/s', $content, $m ) ) {
                    $fields['notes'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }
                break;

            case 'policy':
                $fields['effective'] = get_post_meta( $post_id, 'ptk_policy_effective_date', true );
                $fields['reviewed']  = get_post_meta( $post_id, 'ptk_policy_last_reviewed', true );

                if ( preg_match( '/What This Means.*?<\/h2>.*?<p[^>]*>(.*?)<\/p>/s', $content, $m ) ) {
                    $fields['summary'] = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
                }
                if ( preg_match( '/Official Policy Text<\/h2>(.*?)(?=<!-- wp:separator|$)/s', $content, $m ) ) {
                    preg_match_all( '/<p>(.*?)<\/p>/s', $m[1], $paras );
                    if ( ! empty( $paras[1] ) ) {
                        $fields['full_text'] = implode( "\n\n", array_map( function( $v ) {
                            return html_entity_decode( strip_tags( $v ), ENT_QUOTES, 'UTF-8' );
                        }, $paras[1] ) );
                    }
                }
                break;
        }

        // Extract Helpful Links section (common to all types).
        $fields['links'] = array();
        if ( preg_match( '/Helpful Links<\/h2>.*?<ul[^>]*>(.*?)<\/ul>/s', $content, $m ) ) {
            preg_match_all( '/<a\s+href="([^"]*)"[^>]*>(.*?)<\/a>/s', $m[1], $link_matches );
            if ( ! empty( $link_matches[1] ) ) {
                foreach ( $link_matches[1] as $idx => $url ) {
                    $fields['links'][] = array(
                        'text' => html_entity_decode( strip_tags( $link_matches[2][ $idx ] ), ENT_QUOTES, 'UTF-8' ),
                        'url'  => $url,
                    );
                }
            }
        }

        return $fields;
    }

    /**
     * Render the wizard page.
     */
    public static function render_wizard() {
        // Ensure all default categories exist (critical for subsites).
        self::ensure_default_categories();

        // Determine if we are in edit mode.
        $edit_id   = isset( $_GET['ptk_edit_id'] ) ? absint( $_GET['ptk_edit_id'] ) : 0;
        $edit_data = array();
        $edit_fields = array();
        if ( $edit_id ) {
            $edit_data = self::get_edit_data( $edit_id );
            $edit_fields = isset( $edit_data['fields'] ) ? $edit_data['fields'] : array();
        }
        $is_edit = ! empty( $edit_data );

        // Check for success redirect.
        if ( isset( $_GET['ptk_created'] ) ) {
            $post_id = absint( $_GET['ptk_created'] );
            $is_update = isset( $_GET['ptk_updated'] ) && '1' === $_GET['ptk_updated'];
            $edit_link = get_edit_post_link( $post_id, 'raw' );
            $view_link = get_permalink( $post_id );
            ?>
            <div class="wrap ptk-wizard-wrap">
                <div class="ptk-wizard-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <h2><?php echo $is_update ? 'Entry Updated Successfully!' : 'Entry Created Successfully!'; ?></h2>
                    <p>Your knowledge entry has been <?php echo $is_update ? 'updated' : 'published and is now searchable'; ?>.</p>
                    <div class="ptk-wizard-success-actions">
                        <a href="<?php echo esc_url( $view_link ); ?>" class="button button-primary" target="_blank">View Entry</a>
                        <a href="<?php echo esc_url( $edit_link ); ?>" class="button">Edit in WordPress</a>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-content-wizard' ) ); ?>" class="button">Create Another</a>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $categories = get_terms( array(
            'taxonomy'   => 'knowledge_category',
            'hide_empty' => false,
        ) );

        ?>
        <div class="wrap ptk-wizard-wrap">
            <h1 class="ptk-wizard-header">
                <span class="dashicons dashicons-welcome-learn-more"></span>
                <?php echo $is_edit ? 'Edit Knowledge Entry' : 'Create a Knowledge Entry'; ?>
            </h1>
            <p class="ptk-wizard-intro"><?php echo $is_edit ? 'Update the fields below and save your changes.' : 'Fill in the fields below and we\'ll format everything for you. No editing required!'; ?></p>

            <form method="post" action="" id="ptk-wizard-form" enctype="multipart/form-data">
                <?php wp_nonce_field( 'ptk_wizard_submit', 'ptk_wizard_nonce' ); ?>
                <?php if ( $is_edit ) : ?>
                    <input type="hidden" name="ptk_edit_id" value="<?php echo esc_attr( $edit_id ); ?>">
                <?php endif; ?>

                <!-- Step 1: Choose Category -->
                <div class="ptk-wizard-section ptk-wizard-step" id="ptk-step-category">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">1</span>
                        What type of entry are you creating?
                    </h2>
                    <div class="ptk-category-cards">
                        <?php foreach ( $categories as $cat ) :
                            $icon = self::get_category_icon( $cat->slug );
                            $desc = self::get_category_description( $cat->slug );
                        ?>
                        <label class="ptk-category-card" data-category="<?php echo esc_attr( $cat->slug ); ?>">
                            <input type="radio" name="ptk_category" value="<?php echo esc_attr( $cat->slug ); ?>" required>
                            <span class="ptk-card-icon dashicons <?php echo esc_attr( $icon ); ?>"></span>
                            <span class="ptk-card-name"><?php echo esc_html( $cat->name ); ?></span>
                            <span class="ptk-card-desc"><?php echo esc_html( $desc ); ?></span>
                        </label>
                        <?php endforeach; ?>

                        <!-- Custom / Traditional Editor option -->
                        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=pta_knowledge&ptk_classic=1' ) ); ?>" class="ptk-category-card ptk-card-custom">
                            <span class="ptk-card-icon dashicons dashicons-edit-large"></span>
                            <span class="ptk-card-name">Custom Entry</span>
                            <span class="ptk-card-desc">Use the full WordPress editor for freeform content</span>
                        </a>
                    </div>
                </div>

                <!-- Step 2: Basic Info (always shown after category) -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden" id="ptk-step-basics">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">2</span>
                        Basic Information
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-title" class="ptk-field-label">Title <span class="ptk-required">*</span></label>
                        <input type="text" id="ptk-title" name="ptk_title" class="ptk-field-input" required
                               placeholder="e.g., How to Set Up for a Bake Sale"
                               value="<?php echo isset( $_GET['ptk_prefill_title'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['ptk_prefill_title'] ) ) ) : ''; ?>">
                        <p class="ptk-field-hint">Write a clear, searchable title. Think: what would someone type to find this?</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-excerpt" class="ptk-field-label">Short Summary</label>
                        <textarea id="ptk-excerpt" name="ptk_excerpt" class="ptk-field-textarea" rows="2"
                                  placeholder="A 1-2 sentence summary that appears in search results."></textarea>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-tags" class="ptk-field-label">Tags</label>
                        <input type="text" id="ptk-tags" name="ptk_tags" class="ptk-field-input"
                               placeholder="e.g., bake sale, fundraiser, spring (comma-separated)">
                        <p class="ptk-field-hint">Add keywords people might search for, separated by commas.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Featured Image</label>
                        <div class="ptk-image-upload" id="ptk-featured-image-wrap">
                            <input type="hidden" name="ptk_featured_image" id="ptk-featured-image-id" value="">
                            <div class="ptk-image-preview" id="ptk-featured-image-preview"></div>
                            <button type="button" class="button ptk-upload-btn" data-target="ptk-featured-image">
                                <span class="dashicons dashicons-format-image"></span> Choose Image
                            </button>
                            <button type="button" class="button ptk-remove-image ptk-hidden" data-target="ptk-featured-image">Remove</button>
                        </div>
                    </div>
                </div>

                <!-- Category-Specific Forms -->

                <!-- HOW-TO GUIDE -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-how-to-guide">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Build Your How-To Guide
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-howto-intro" class="ptk-field-label">Introduction</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-howto-intro" name="ptk_howto_intro" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Briefly describe what this guide covers and why it's useful."></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-row">
                        <div class="ptk-field-group ptk-field-half">
                            <label for="ptk-howto-difficulty" class="ptk-field-label">Difficulty</label>
                            <select id="ptk-howto-difficulty" name="ptk_difficulty" class="ptk-field-select">
                                <option value="">— Select —</option>
                                <option value="Easy">Easy</option>
                                <option value="Medium">Medium</option>
                                <option value="Hard">Hard</option>
                            </select>
                        </div>
                        <div class="ptk-field-group ptk-field-half">
                            <label for="ptk-howto-time" class="ptk-field-label">Time Estimate</label>
                            <input type="text" id="ptk-howto-time" name="ptk_time_estimate" class="ptk-field-input"
                                   placeholder="e.g., 30 minutes">
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">What You'll Need</label>
                        <div class="ptk-textarea-wrap">
                            <textarea name="ptk_howto_materials" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="List materials or prerequisites, one per line."></textarea>
                        </div>
                        <p class="ptk-field-hint">One item per line. Leave blank if not applicable.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Steps <span class="ptk-required">*</span></label>
                        <p class="ptk-field-hint">Add each step of your guide. You can optionally add an image or link to any step.</p>
                        <div class="ptk-repeater" id="ptk-howto-steps" data-min="1">
                            <!-- Steps added by JS -->
                        </div>
                        <button type="button" class="button ptk-add-step" data-repeater="ptk-howto-steps">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Step
                        </button>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Tips &amp; Notes</label>
                        <div class="ptk-textarea-wrap">
                            <textarea name="ptk_howto_tips" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Any helpful tips, common mistakes to avoid, or additional notes."></textarea>
                        </div>
                    </div>
                </div>

                <!-- EVENT PLAYBOOK -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-event-playbook">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Build Your Event Playbook
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-event-overview" class="ptk-field-label">Event Overview</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-event-overview" name="ptk_event_overview" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Describe the event: what it is, who it's for, and the goal."></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-row">
                        <div class="ptk-field-group ptk-field-third">
                            <label for="ptk-event-date" class="ptk-field-label">Event Date</label>
                            <input type="date" id="ptk-event-date" name="ptk_event_date" class="ptk-field-input">
                        </div>
                        <div class="ptk-field-group ptk-field-third">
                            <label for="ptk-event-location" class="ptk-field-label">Location</label>
                            <input type="text" id="ptk-event-location" name="ptk_location" class="ptk-field-input"
                                   placeholder="e.g., School gym">
                        </div>
                        <div class="ptk-field-group ptk-field-third">
                            <label for="ptk-event-budget" class="ptk-field-label">Budget</label>
                            <input type="text" id="ptk-event-budget" name="ptk_budget" class="ptk-field-input"
                                   placeholder="e.g., $500">
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Timeline</label>
                        <p class="ptk-field-hint">Add timeline milestones leading up to and during the event.</p>
                        <div class="ptk-repeater" id="ptk-event-timeline" data-min="1">
                            <!-- Items added by JS -->
                        </div>
                        <button type="button" class="button ptk-add-timeline" data-repeater="ptk-event-timeline">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Timeline Item
                        </button>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Supplies Needed</label>
                        <div class="ptk-textarea-wrap">
                            <textarea name="ptk_event_supplies" class="ptk-field-textarea ptk-linkable" rows="4"
                                      placeholder="List supplies needed, one per line. Include quantities if possible.&#10;e.g., 50 paper plates&#10;3 folding tables&#10;1 cash box"></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Key Contacts &amp; Roles</label>
                        <div class="ptk-textarea-wrap">
                            <textarea name="ptk_event_contacts" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="List key people and their roles, one per line.&#10;e.g., Jane Smith — Event Chair&#10;John Doe — Setup Lead"></textarea>
                        </div>
                    </div>
                </div>

                <!-- FAQ -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-faq">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Write Your FAQ Entry
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-faq-short-answer" class="ptk-field-label">Quick Answer <span class="ptk-required">*</span></label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-faq-short-answer" name="ptk_faq_short_answer" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Write a concise answer that someone could copy and paste."></textarea>
                        </div>
                        <p class="ptk-field-hint">This is the main answer people will see. Keep it clear and copy-friendly.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-faq-details" class="ptk-field-label">Detailed Explanation</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-faq-details" name="ptk_faq_details" class="ptk-field-textarea ptk-linkable" rows="5"
                                      placeholder="Optional: provide more context, background info, or links."></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-faq-reviewed" class="ptk-field-label">Last Reviewed</label>
                        <input type="date" id="ptk-faq-reviewed" name="ptk_last_reviewed" class="ptk-field-input"
                               value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
                    </div>
                </div>

                <!-- RESOURCE -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-resource">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Add Your Resource
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-resource-desc" class="ptk-field-label">Description <span class="ptk-required">*</span></label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-resource-desc" name="ptk_resource_description" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="What is this resource and why is it useful?"></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-row">
                        <div class="ptk-field-group ptk-field-half">
                            <label for="ptk-resource-url" class="ptk-field-label">Resource URL</label>
                            <input type="url" id="ptk-resource-url" name="ptk_resource_url" class="ptk-field-input"
                                   placeholder="https://...">
                            <p class="ptk-field-hint">Link to the resource if it's hosted online.</p>
                        </div>
                        <div class="ptk-field-group ptk-field-half">
                            <label for="ptk-resource-type" class="ptk-field-label">File Type</label>
                            <select id="ptk-resource-type" name="ptk_file_type" class="ptk-field-select">
                                <option value="">— Select —</option>
                                <option value="PDF">PDF</option>
                                <option value="Video">Video</option>
                                <option value="Template">Template</option>
                                <option value="Form">Form</option>
                                <option value="Image">Image</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Upload File</label>
                        <div class="ptk-image-upload" id="ptk-resource-file-wrap">
                            <input type="hidden" name="ptk_resource_file_id" id="ptk-resource-file-id" value="">
                            <div class="ptk-file-preview" id="ptk-resource-file-preview"></div>
                            <button type="button" class="button ptk-upload-file-btn" data-target="ptk-resource-file">
                                <span class="dashicons dashicons-upload"></span> Upload File
                            </button>
                            <button type="button" class="button ptk-remove-file ptk-hidden" data-target="ptk-resource-file">Remove</button>
                        </div>
                        <p class="ptk-field-hint">Or upload a file directly (PDF, image, document, etc.).</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-resource-howto" class="ptk-field-label">How to Use</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-resource-howto" name="ptk_resource_howto" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Instructions for using this resource, one step per line."></textarea>
                        </div>
                    </div>
                </div>

                <!-- GLOSSARY TERM -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-glossary">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Define This Term
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-glossary-definition" class="ptk-field-label">Plain-English Definition <span class="ptk-required">*</span></label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-glossary-definition" name="ptk_glossary_definition" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Explain this term simply — as if talking to someone who just joined the PTA and has never heard of it."></textarea>
                        </div>
                        <p class="ptk-field-hint">This shows as a tooltip when people hover over this term in other entries.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-glossary-details" class="ptk-field-label">More Details</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-glossary-details" name="ptk_glossary_details" class="ptk-field-textarea ptk-linkable" rows="4"
                                      placeholder="Optional: add more context, examples, or links for people who want to learn more."></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-glossary-example" class="ptk-field-label">Example</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-glossary-example" name="ptk_glossary_example" class="ptk-field-textarea ptk-linkable" rows="2"
                                      placeholder='Optional: e.g., "When you hear someone say POSSE, they mean: put it on the PTA website first, then share it on social media."'></textarea>
                        </div>
                    </div>
                </div>

                <!-- CHECKLIST -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-checklist">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Build Your Checklist
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-checklist-intro" class="ptk-field-label">Introduction</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-checklist-intro" name="ptk_checklist_intro" class="ptk-field-textarea ptk-linkable" rows="2"
                                      placeholder="Briefly describe what this checklist is for and when to use it."></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Checklist Items <span class="ptk-required">*</span></label>
                        <p class="ptk-field-hint">Add each item that needs to be completed. You can reorder them with the arrows.</p>
                        <div class="ptk-repeater" id="ptk-checklist-items" data-min="1">
                            <!-- Items added by JS -->
                        </div>
                        <button type="button" class="button ptk-add-checklist-item" data-repeater="ptk-checklist-items">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Item
                        </button>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-checklist-notes" class="ptk-field-label">Notes</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-checklist-notes" name="ptk_checklist_notes" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="Any additional notes, reminders, or important deadlines."></textarea>
                        </div>
                    </div>
                </div>

                <!-- POLICY / RULES -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden ptk-category-form" id="ptk-form-policy">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">3</span>
                        Document This Policy or Rule
                    </h2>

                    <div class="ptk-field-group">
                        <label for="ptk-policy-summary" class="ptk-field-label">Summary <span class="ptk-required">*</span></label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-policy-summary" name="ptk_policy_summary" class="ptk-field-textarea ptk-linkable" rows="3"
                                      placeholder="In plain English, what does this policy or rule say? What do people need to know?"></textarea>
                        </div>
                        <p class="ptk-field-hint">Write this for someone who has never read a bylaws document.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-policy-full-text" class="ptk-field-label">Full Policy Text</label>
                        <div class="ptk-textarea-wrap">
                            <textarea id="ptk-policy-full-text" name="ptk_policy_full_text" class="ptk-field-textarea ptk-linkable" rows="6"
                                      placeholder="Optional: paste the official policy language here for reference."></textarea>
                        </div>
                    </div>

                    <div class="ptk-field-row">
                        <div class="ptk-field-group ptk-field-half">
                            <label for="ptk-policy-effective" class="ptk-field-label">Effective Date</label>
                            <input type="date" id="ptk-policy-effective" name="ptk_policy_effective_date" class="ptk-field-input">
                        </div>
                        <div class="ptk-field-group ptk-field-half">
                            <label for="ptk-policy-reviewed" class="ptk-field-label">Last Reviewed</label>
                            <input type="date" id="ptk-policy-reviewed" name="ptk_policy_last_reviewed" class="ptk-field-input"
                                   value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
                        </div>
                    </div>
                </div>

                <!-- LINKS & RESOURCES (common to all types, shown when category selected) -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden" id="ptk-step-links">
                    <h2 class="ptk-wizard-section-title">
                        <span class="ptk-step-number">4</span>
                        Links &amp; Resources
                    </h2>
                    <p class="ptk-field-hint" style="margin-bottom:16px;">Add any helpful links related to this entry. These will appear at the bottom.</p>

                    <div class="ptk-repeater" id="ptk-links-repeater" data-min="0">
                        <!-- Link items added by JS -->
                    </div>
                    <button type="button" class="button ptk-add-link-item" data-repeater="ptk-links-repeater">
                        <span class="dashicons dashicons-plus-alt2"></span> Add Link
                    </button>
                </div>

                <!-- Submit -->
                <div class="ptk-wizard-section ptk-wizard-step ptk-hidden" id="ptk-step-submit">
                    <div class="ptk-wizard-submit-area">
                        <div class="ptk-submit-options">
                            <label class="ptk-field-label">Publish as:</label>
                            <label class="ptk-radio-label">
                                <input type="radio" name="ptk_status" value="publish" checked> Published (visible immediately)
                            </label>
                            <label class="ptk-radio-label">
                                <input type="radio" name="ptk_status" value="draft"> Draft (save for later)
                            </label>
                        </div>
                        <button type="submit" class="button button-primary button-hero" id="ptk-wizard-submit-btn">
                            <span class="dashicons dashicons-saved"></span> <?php echo $is_edit ? 'Update Entry' : 'Create Entry'; ?>
                        </button>
                        <p class="ptk-submit-note">You can always edit the entry later in WordPress if needed.</p>
                    </div>
                </div>

            </form>
        </div>

        <!-- Link Popup Modal -->
        <div id="ptk-link-popup" class="ptk-link-popup ptk-hidden">
            <div class="ptk-link-popup-inner">
                <div class="ptk-link-popup-header">
                    <strong>Insert Link</strong>
                    <button type="button" class="ptk-link-popup-close">&times;</button>
                </div>
                <div class="ptk-link-popup-body">
                    <label class="ptk-field-label">Link Text</label>
                    <input type="text" id="ptk-link-popup-text" class="ptk-field-input" placeholder="e.g., Go to Givebacks">
                    <label class="ptk-field-label" style="margin-top:10px;">URL</label>
                    <input type="text" id="ptk-link-popup-url" class="ptk-field-input" placeholder="https://...">
                </div>
                <div class="ptk-link-popup-footer">
                    <button type="button" class="button button-primary" id="ptk-link-popup-insert">Insert</button>
                    <button type="button" class="button" id="ptk-link-popup-cancel">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle form submission: create or update the post with auto-generated block content.
     */
    public static function handle_submission() {
        if ( ! isset( $_POST['ptk_wizard_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['ptk_wizard_nonce'], 'ptk_wizard_submit' ) ) {
            wp_die( 'Security check failed. Please try again.' );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'You do not have permission to create entries.' );
        }

        $edit_id  = absint( $_POST['ptk_edit_id'] ?? 0 );
        $category = sanitize_text_field( wp_unslash( $_POST['ptk_category'] ?? '' ) );
        $title    = sanitize_text_field( wp_unslash( $_POST['ptk_title'] ?? '' ) );
        $excerpt  = sanitize_textarea_field( wp_unslash( $_POST['ptk_excerpt'] ?? '' ) );
        $tags     = sanitize_text_field( wp_unslash( $_POST['ptk_tags'] ?? '' ) );
        $status   = in_array( $_POST['ptk_status'] ?? 'draft', array( 'publish', 'draft' ), true ) ? $_POST['ptk_status'] : 'draft';

        if ( empty( $title ) || empty( $category ) ) {
            wp_die( 'Title and category are required.' );
        }

        // For glossary terms, auto-fill the excerpt with the definition
        // so it powers the tooltip system.
        if ( 'glossary' === $category && empty( $excerpt ) ) {
            $excerpt = sanitize_textarea_field( wp_unslash( $_POST['ptk_glossary_definition'] ?? '' ) );
        }

        // Category-specific server-side validation.
        if ( 'faq' === $category && empty( trim( sanitize_textarea_field( wp_unslash( $_POST['ptk_faq_short_answer'] ?? '' ) ) ) ) ) {
            wp_die( 'Please provide a Quick Answer for your FAQ entry.' );
        }
        if ( 'resource' === $category && empty( trim( sanitize_textarea_field( wp_unslash( $_POST['ptk_resource_description'] ?? '' ) ) ) ) ) {
            wp_die( 'Please provide a Description for your Resource.' );
        }
        if ( 'glossary' === $category && empty( trim( sanitize_textarea_field( wp_unslash( $_POST['ptk_glossary_definition'] ?? '' ) ) ) ) ) {
            wp_die( 'Please provide a Definition for your Glossary Term.' );
        }
        if ( 'policy' === $category && empty( trim( sanitize_textarea_field( wp_unslash( $_POST['ptk_policy_summary'] ?? '' ) ) ) ) ) {
            wp_die( 'Please provide a Summary for your Policy entry.' );
        }

        // Generate block content based on category.
        $content = self::generate_content( $category );

        // Create or update the post.
        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $status,
            'post_type'    => 'pta_knowledge',
        );

        $is_update = false;
        if ( $edit_id ) {
            // Verify the post exists and user can edit it.
            $existing = get_post( $edit_id );
            if ( ! $existing || 'pta_knowledge' !== $existing->post_type ) {
                wp_die( 'Invalid entry to update.' );
            }
            if ( ! current_user_can( 'edit_post', $edit_id ) ) {
                wp_die( 'You do not have permission to edit this entry.' );
            }

            $post_data['ID'] = $edit_id;
            $post_id = wp_update_post( $post_data, true );
            $is_update = true;
        } else {
            $post_id = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $post_id ) ) {
            wp_die( 'Error saving entry: ' . esc_html( $post_id->get_error_message() ) );
        }

        // Set category.
        $term = get_term_by( 'slug', $category, 'knowledge_category' );
        if ( $term ) {
            wp_set_post_terms( $post_id, array( $term->term_id ), 'knowledge_category' );
        }

        // Set tags.
        if ( ! empty( $tags ) ) {
            $tag_array = array_map( 'trim', explode( ',', $tags ) );
            wp_set_post_tags( $post_id, $tag_array );
        } else {
            wp_set_post_tags( $post_id, array() );
        }

        // Set featured image.
        $featured_id = absint( $_POST['ptk_featured_image'] ?? 0 );
        if ( $featured_id ) {
            set_post_thumbnail( $post_id, $featured_id );
        } elseif ( $edit_id ) {
            delete_post_thumbnail( $post_id );
        }

        // Save category-specific meta fields.
        self::save_meta_fields( $post_id, $category );

        // Redirect to success page.
        $redirect_args = array(
            'post_type'   => 'pta_knowledge',
            'page'        => 'ptk-content-wizard',
            'ptk_created' => $post_id,
        );
        if ( $is_update ) {
            $redirect_args['ptk_updated'] = '1';
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Convert markdown-style [text](url) links in a string to HTML anchor tags.
     */
    private static function convert_inline_links( $text ) {
        // Match [link text](url) pattern.
        return preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
            $text
        );
    }

    /**
     * Escape HTML but preserve converted inline links.
     * First escapes, then converts markdown links to HTML.
     */
    private static function esc_with_links( $text ) {
        // First convert inline links in the raw text.
        // We need to handle this carefully: escape everything except links.
        // Strategy: replace links with placeholders, escape, restore.
        $placeholders = array();
        $counter = 0;
        $processed = preg_replace_callback( '/\[([^\]]+)\]\(([^)]+)\)/', function( $matches ) use ( &$placeholders, &$counter ) {
            $key = '%%PTKLINK' . $counter . '%%';
            $placeholders[ $key ] = '<a href="' . esc_url( $matches[2] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $matches[1] ) . '</a>';
            $counter++;
            return $key;
        }, $text );

        // Escape the rest.
        $escaped = esc_html( $processed );

        // Restore link placeholders.
        foreach ( $placeholders as $key => $html ) {
            $escaped = str_replace( esc_html( $key ), $html, $escaped );
        }

        return $escaped;
    }

    /**
     * Generate Gutenberg block content from form data based on category.
     */
    private static function generate_content( $category ) {
        $blocks = array();

        switch ( $category ) {
            case 'how-to-guide':
                $blocks = self::generate_howto_content();
                break;
            case 'event-playbook':
                $blocks = self::generate_event_content();
                break;
            case 'faq':
                $blocks = self::generate_faq_content();
                break;
            case 'resource':
                $blocks = self::generate_resource_content();
                break;
            case 'glossary':
                $blocks = self::generate_glossary_content();
                break;
            case 'checklist':
                $blocks = self::generate_checklist_content();
                break;
            case 'policy':
                $blocks = self::generate_policy_content();
                break;
            default:
                return '';
        }

        // Append Helpful Links section (common to all types).
        $links_blocks = self::generate_links_section();
        if ( ! empty( $links_blocks ) ) {
            $blocks = array_merge( $blocks, $links_blocks );
        }

        return implode( "\n\n", $blocks );
    }

    /**
     * Generate the common "Helpful Links" section from the links repeater.
     */
    private static function generate_links_section() {
        $blocks = array();

        $link_texts = isset( $_POST['ptk_link_text'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ptk_link_text'] ) ) : array();
        $link_urls  = isset( $_POST['ptk_link_url'] ) ? array_map( 'esc_url_raw', wp_unslash( $_POST['ptk_link_url'] ) ) : array();

        $has_links = false;
        foreach ( $link_texts as $i => $text ) {
            $url = isset( $link_urls[ $i ] ) ? $link_urls[ $i ] : '';
            if ( ! empty( trim( $text ) ) && ! empty( trim( $url ) ) ) {
                $has_links = true;
                break;
            }
        }

        if ( $has_links ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Helpful Links</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            foreach ( $link_texts as $i => $text ) {
                $url = isset( $link_urls[ $i ] ) ? $link_urls[ $i ] : '';
                if ( ! empty( trim( $text ) ) && ! empty( trim( $url ) ) ) {
                    $blocks[] = '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a></li>';
                }
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        return $blocks;
    }

    /**
     * Generate How-To Guide content.
     */
    private static function generate_howto_content() {
        $blocks = array();

        // Introduction.
        $intro = sanitize_textarea_field( wp_unslash( $_POST['ptk_howto_intro'] ?? '' ) );
        if ( ! empty( $intro ) ) {
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . self::esc_with_links( $intro ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        // What You'll Need.
        $materials = sanitize_textarea_field( wp_unslash( $_POST['ptk_howto_materials'] ?? '' ) );
        if ( ! empty( $materials ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">What You\'ll Need</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            $items = array_filter( array_map( 'trim', explode( "\n", $materials ) ) );
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            foreach ( $items as $item ) {
                $blocks[] = '<li>' . self::esc_with_links( $item ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        // Steps.
        $step_texts      = isset( $_POST['ptk_step_text'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['ptk_step_text'] ) ) : array();
        $step_images     = isset( $_POST['ptk_step_image'] ) ? array_map( 'absint', $_POST['ptk_step_image'] ) : array();
        $step_link_texts = isset( $_POST['ptk_step_link_text'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ptk_step_link_text'] ) ) : array();
        $step_link_urls  = isset( $_POST['ptk_step_link_url'] ) ? array_map( 'esc_url_raw', wp_unslash( $_POST['ptk_step_link_url'] ) ) : array();

        if ( ! empty( $step_texts ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Steps</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            foreach ( $step_texts as $i => $text ) {
                if ( empty( trim( $text ) ) ) {
                    continue;
                }
                $step_num = $i + 1;

                $blocks[] = '<!-- wp:heading {"level":3} -->';
                $blocks[] = '<h3 class="wp-block-heading">Step ' . $step_num . '</h3>';
                $blocks[] = '<!-- /wp:heading -->';

                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . self::esc_with_links( $text ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';

                // Step link button.
                $sl_text = isset( $step_link_texts[ $i ] ) ? trim( $step_link_texts[ $i ] ) : '';
                $sl_url  = isset( $step_link_urls[ $i ] ) ? trim( $step_link_urls[ $i ] ) : '';
                if ( ! empty( $sl_text ) && ! empty( $sl_url ) ) {
                    $blocks[] = '<!-- wp:paragraph -->';
                    $blocks[] = '<p><a href="' . esc_url( $sl_url ) . '" class="ptk-step-link-btn" target="_blank" rel="noopener noreferrer">' . esc_html( $sl_text ) . ' &rarr;</a></p>';
                    $blocks[] = '<!-- /wp:paragraph -->';
                }

                // Step image.
                $img_id = isset( $step_images[ $i ] ) ? $step_images[ $i ] : 0;
                if ( $img_id ) {
                    $img_url = wp_get_attachment_url( $img_id );
                    $img_alt = get_post_meta( $img_id, '_wp_attachment_image_alt', true );
                    if ( $img_url ) {
                        $blocks[] = '<!-- wp:image {"id":' . $img_id . ',"sizeSlug":"large"} -->';
                        $blocks[] = '<figure class="wp-block-image size-large"><img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $img_alt ) . '" class="wp-image-' . $img_id . '"/></figure>';
                        $blocks[] = '<!-- /wp:image -->';
                    }
                }
            }
        }

        // Tips.
        $tips = sanitize_textarea_field( wp_unslash( $_POST['ptk_howto_tips'] ?? '' ) );
        if ( ! empty( $tips ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Tips &amp; Notes</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            $tip_items = array_filter( array_map( 'trim', explode( "\n", $tips ) ) );
            if ( count( $tip_items ) > 1 ) {
                $blocks[] = '<!-- wp:list -->';
                $blocks[] = '<ul class="wp-block-list">';
                foreach ( $tip_items as $tip ) {
                    $blocks[] = '<li>' . self::esc_with_links( $tip ) . '</li>';
                }
                $blocks[] = '</ul>';
                $blocks[] = '<!-- /wp:list -->';
            } else {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . self::esc_with_links( $tips ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        return $blocks;
    }

    /**
     * Generate Event Playbook content.
     */
    private static function generate_event_content() {
        $blocks = array();

        // Overview.
        $overview = sanitize_textarea_field( wp_unslash( $_POST['ptk_event_overview'] ?? '' ) );
        if ( ! empty( $overview ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Overview</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . self::esc_with_links( $overview ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        // Event details summary.
        $date     = sanitize_text_field( wp_unslash( $_POST['ptk_event_date'] ?? '' ) );
        $location = sanitize_text_field( wp_unslash( $_POST['ptk_location'] ?? '' ) );
        $budget   = sanitize_text_field( wp_unslash( $_POST['ptk_budget'] ?? '' ) );

        if ( $date || $location || $budget ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Event Details</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            if ( $date ) {
                $formatted_date = date_i18n( 'F j, Y', strtotime( $date ) );
                $blocks[] = '<li><strong>Date:</strong> ' . esc_html( $formatted_date ) . '</li>';
            }
            if ( $location ) {
                $blocks[] = '<li><strong>Location:</strong> ' . esc_html( $location ) . '</li>';
            }
            if ( $budget ) {
                $blocks[] = '<li><strong>Budget:</strong> ' . esc_html( $budget ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        // Timeline.
        $timeline_whens = isset( $_POST['ptk_timeline_when'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ptk_timeline_when'] ) ) : array();
        $timeline_whats = isset( $_POST['ptk_timeline_what'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ptk_timeline_what'] ) ) : array();

        $has_timeline = false;
        foreach ( $timeline_whens as $i => $when ) {
            if ( ! empty( trim( $when ) ) || ! empty( trim( $timeline_whats[ $i ] ?? '' ) ) ) {
                $has_timeline = true;
                break;
            }
        }

        if ( $has_timeline ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Timeline</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            foreach ( $timeline_whens as $i => $when ) {
                $what = $timeline_whats[ $i ] ?? '';
                if ( empty( trim( $when ) ) && empty( trim( $what ) ) ) {
                    continue;
                }
                $blocks[] = '<li><strong>' . esc_html( $when ) . ':</strong> ' . esc_html( $what ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        // Supplies.
        $supplies = sanitize_textarea_field( wp_unslash( $_POST['ptk_event_supplies'] ?? '' ) );
        if ( ! empty( $supplies ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Supplies Needed</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $items = array_filter( array_map( 'trim', explode( "\n", $supplies ) ) );
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            foreach ( $items as $item ) {
                $blocks[] = '<li>' . self::esc_with_links( $item ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        // Contacts.
        $contacts = sanitize_textarea_field( wp_unslash( $_POST['ptk_event_contacts'] ?? '' ) );
        if ( ! empty( $contacts ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Key Contacts</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $items = array_filter( array_map( 'trim', explode( "\n", $contacts ) ) );
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            foreach ( $items as $item ) {
                $blocks[] = '<li>' . self::esc_with_links( $item ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        return $blocks;
    }

    /**
     * Generate FAQ content.
     */
    private static function generate_faq_content() {
        $blocks = array();

        $short_answer = sanitize_textarea_field( wp_unslash( $_POST['ptk_faq_short_answer'] ?? '' ) );
        $details      = sanitize_textarea_field( wp_unslash( $_POST['ptk_faq_details'] ?? '' ) );

        if ( ! empty( $short_answer ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Quick Answer</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->';
            $blocks[] = '<p style="font-weight:600">' . self::esc_with_links( $short_answer ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        if ( ! empty( $details ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Details</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            $paragraphs = array_filter( array_map( 'trim', explode( "\n\n", $details ) ) );
            if ( empty( $paragraphs ) ) {
                $paragraphs = array_filter( array_map( 'trim', explode( "\n", $details ) ) );
            }
            foreach ( $paragraphs as $para ) {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . self::esc_with_links( $para ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        return $blocks;
    }

    /**
     * Generate Resource content.
     */
    private static function generate_resource_content() {
        $blocks = array();

        $description = sanitize_textarea_field( wp_unslash( $_POST['ptk_resource_description'] ?? '' ) );
        $howto       = sanitize_textarea_field( wp_unslash( $_POST['ptk_resource_howto'] ?? '' ) );
        $url         = esc_url_raw( wp_unslash( $_POST['ptk_resource_url'] ?? '' ) );
        $file_id     = absint( $_POST['ptk_resource_file_id'] ?? 0 );

        if ( ! empty( $description ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Description</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . self::esc_with_links( $description ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        // Download/link section.
        if ( $file_id || ! empty( $url ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Download / Access</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            if ( $file_id ) {
                $file_url  = wp_get_attachment_url( $file_id );
                $file_name = get_the_title( $file_id );
                if ( $file_url ) {
                    $blocks[] = '<!-- wp:file {"id":' . $file_id . '} -->';
                    $blocks[] = '<div class="wp-block-file"><a href="' . esc_url( $file_url ) . '">' . esc_html( $file_name ) . '</a><a href="' . esc_url( $file_url ) . '" class="wp-block-file__button wp-element-button" download>Download</a></div>';
                    $blocks[] = '<!-- /wp:file -->';
                }
            }

            if ( ! empty( $url ) ) {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Access Resource Online &rarr;</a></p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        if ( ! empty( $howto ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">How to Use</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            $steps = array_filter( array_map( 'trim', explode( "\n", $howto ) ) );
            if ( count( $steps ) > 1 ) {
                $blocks[] = '<!-- wp:list {"ordered":true} -->';
                $blocks[] = '<ol class="wp-block-list">';
                foreach ( $steps as $step ) {
                    $blocks[] = '<li>' . self::esc_with_links( $step ) . '</li>';
                }
                $blocks[] = '</ol>';
                $blocks[] = '<!-- /wp:list -->';
            } else {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . self::esc_with_links( $howto ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        return $blocks;
    }

    /**
     * Generate Glossary Term content.
     */
    private static function generate_glossary_content() {
        $blocks = array();

        $definition = sanitize_textarea_field( wp_unslash( $_POST['ptk_glossary_definition'] ?? '' ) );
        $details    = sanitize_textarea_field( wp_unslash( $_POST['ptk_glossary_details'] ?? '' ) );
        $example    = sanitize_textarea_field( wp_unslash( $_POST['ptk_glossary_example'] ?? '' ) );

        if ( ! empty( $definition ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">What It Means</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->';
            $blocks[] = '<p style="font-weight:600">' . self::esc_with_links( $definition ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        if ( ! empty( $details ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">More Details</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            $paragraphs = array_filter( array_map( 'trim', explode( "\n\n", $details ) ) );
            if ( empty( $paragraphs ) ) {
                $paragraphs = array_filter( array_map( 'trim', explode( "\n", $details ) ) );
            }
            foreach ( $paragraphs as $para ) {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . self::esc_with_links( $para ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        if ( ! empty( $example ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Example</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p><em>' . self::esc_with_links( $example ) . '</em></p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        return $blocks;
    }

    /**
     * Generate Checklist content.
     */
    private static function generate_checklist_content() {
        $blocks = array();

        $intro = sanitize_textarea_field( wp_unslash( $_POST['ptk_checklist_intro'] ?? '' ) );
        if ( ! empty( $intro ) ) {
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . self::esc_with_links( $intro ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        $items = isset( $_POST['ptk_checklist_item'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['ptk_checklist_item'] ) ) : array();
        $filtered = array_filter( array_map( 'trim', $items ) );

        if ( ! empty( $filtered ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Checklist</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:list -->';
            $blocks[] = '<ul class="wp-block-list">';
            foreach ( $filtered as $item ) {
                $blocks[] = '<li>&#9744; ' . self::esc_with_links( $item ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        $notes = sanitize_textarea_field( wp_unslash( $_POST['ptk_checklist_notes'] ?? '' ) );
        if ( ! empty( $notes ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Notes</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . self::esc_with_links( $notes ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        return $blocks;
    }

    /**
     * Generate Policy / Rules content.
     */
    private static function generate_policy_content() {
        $blocks = array();

        $summary   = sanitize_textarea_field( wp_unslash( $_POST['ptk_policy_summary'] ?? '' ) );
        $full_text = sanitize_textarea_field( wp_unslash( $_POST['ptk_policy_full_text'] ?? '' ) );

        if ( ! empty( $summary ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">What This Means (In Plain English)</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->';
            $blocks[] = '<p style="font-weight:600">' . self::esc_with_links( $summary ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        if ( ! empty( $full_text ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Official Policy Text</h2>';
            $blocks[] = '<!-- /wp:heading -->';

            $paragraphs = array_filter( array_map( 'trim', explode( "\n\n", $full_text ) ) );
            if ( empty( $paragraphs ) ) {
                $paragraphs = array_filter( array_map( 'trim', explode( "\n", $full_text ) ) );
            }
            foreach ( $paragraphs as $para ) {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . self::esc_with_links( $para ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        $effective = sanitize_text_field( wp_unslash( $_POST['ptk_policy_effective_date'] ?? '' ) );
        $reviewed  = sanitize_text_field( wp_unslash( $_POST['ptk_policy_last_reviewed'] ?? '' ) );
        if ( $effective || $reviewed ) {
            $blocks[] = '<!-- wp:separator -->';
            $blocks[] = '<hr class="wp-block-separator"/>';
            $blocks[] = '<!-- /wp:separator -->';
            $blocks[] = '<!-- wp:paragraph {"fontSize":"small"} -->';
            $meta_parts = array();
            if ( $effective ) {
                $meta_parts[] = '<strong>Effective:</strong> ' . esc_html( date_i18n( 'F j, Y', strtotime( $effective ) ) );
            }
            if ( $reviewed ) {
                $meta_parts[] = '<strong>Last Reviewed:</strong> ' . esc_html( date_i18n( 'F j, Y', strtotime( $reviewed ) ) );
            }
            $blocks[] = '<p class="has-small-font-size">' . implode( ' &nbsp;|&nbsp; ', $meta_parts ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        return $blocks;
    }

    /**
     * Save category-specific meta fields from wizard form data.
     */
    private static function save_meta_fields( $post_id, $category ) {
        $meta_map = array(
            'how-to-guide'   => array( 'ptk_difficulty', 'ptk_time_estimate' ),
            'event-playbook' => array( 'ptk_event_date', 'ptk_location', 'ptk_budget' ),
            'faq'            => array( 'ptk_last_reviewed' ),
            'resource'       => array( 'ptk_resource_url', 'ptk_file_type' ),
            'glossary'       => array(),
            'checklist'      => array(),
            'policy'         => array( 'ptk_policy_effective_date', 'ptk_policy_last_reviewed' ),
        );

        if ( ! isset( $meta_map[ $category ] ) ) {
            return;
        }

        foreach ( $meta_map[ $category ] as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
                update_post_meta( $post_id, $key, $value );
            }
        }
    }

    /**
     * Get a dashicon for each category.
     */
    private static function get_category_icon( $slug ) {
        $icons = array(
            'how-to-guide'   => 'dashicons-editor-ol',
            'event-playbook' => 'dashicons-calendar-alt',
            'faq'            => 'dashicons-format-chat',
            'resource'       => 'dashicons-media-default',
            'glossary'       => 'dashicons-book-alt',
            'checklist'      => 'dashicons-yes-alt',
            'policy'         => 'dashicons-shield',
        );
        return $icons[ $slug ] ?? 'dashicons-admin-page';
    }

    /**
     * Get a short description for each category card.
     */
    private static function get_category_description( $slug ) {
        $descs = array(
            'how-to-guide'   => 'Step-by-step instructions with optional images',
            'event-playbook' => 'Event plans with timelines, supplies, and contacts',
            'faq'            => 'Questions with quick, copy-friendly answers',
            'resource'       => 'Files, videos, templates, and links',
            'glossary'       => 'Plain-English definition of a PTA term or tool',
            'checklist'      => 'A to-do list for transitions, setup, or audits',
            'policy'         => 'Bylaws, rules, or guidelines in plain language',
        );
        return $descs[ $slug ] ?? 'General knowledge entry';
    }
}
