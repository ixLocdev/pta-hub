<?php
/**
 * Content Wizard — guided form for creating knowledge entries.
 *
 * Provides a simple fill-in-the-blanks interface so volunteers
 * can create properly formatted content without touching the
 * block editor. Each category gets its own form layout:
 *   - How-To Guide: title, intro, dynamic steps (text + optional image), tips
 *   - Event Playbook: title, overview, date/location/budget, timeline items, supplies, contacts
 *   - FAQ: question (title), short answer, detailed answer
 *   - Resource: title, description, file upload/URL, how-to-use instructions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Content_Wizard {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_wizard_page' ) );
        add_action( 'admin_menu', array( __CLASS__, 'remove_default_add_new' ), 99 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
        add_action( 'load-post-new.php', array( __CLASS__, 'redirect_add_new_to_wizard' ) );
    }

    /**
     * Remove the default "Add New Knowledge Entry" submenu and move
     * "Create Entry" to the second position (right after "All Entries").
     */
    public static function remove_default_add_new() {
        global $submenu;

        $parent = 'edit.php?post_type=pta_knowledge';

        // Remove the default "Add New" link.
        remove_submenu_page( $parent, 'post-new.php?post_type=pta_knowledge' );

        // Move "Create Entry" (the wizard) to position 1 (right after "All Entries" at 0).
        if ( isset( $submenu[ $parent ] ) ) {
            $wizard_key  = null;
            $wizard_item = null;

            foreach ( $submenu[ $parent ] as $key => $item ) {
                if ( isset( $item[2] ) && 'ptk-content-wizard' === $item[2] ) {
                    $wizard_key  = $key;
                    $wizard_item = $item;
                    break;
                }
            }

            if ( null !== $wizard_key ) {
                unset( $submenu[ $parent ][ $wizard_key ] );
                // Re-index: put "All Entries" first, then wizard, then the rest.
                $reordered = array();
                $inserted  = false;
                foreach ( $submenu[ $parent ] as $item ) {
                    $reordered[] = $item;
                    if ( ! $inserted ) {
                        $reordered[] = $wizard_item;
                        $inserted    = true;
                    }
                }
                if ( ! $inserted ) {
                    $reordered[] = $wizard_item;
                }
                $submenu[ $parent ] = $reordered;
            }
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
    }

    /**
     * Render the wizard page.
     */
    public static function render_wizard() {
        // Check for success redirect.
        if ( isset( $_GET['ptk_created'] ) ) {
            $post_id = absint( $_GET['ptk_created'] );
            $edit_link = get_edit_post_link( $post_id, 'raw' );
            $view_link = get_permalink( $post_id );
            ?>
            <div class="wrap ptk-wizard-wrap">
                <div class="ptk-wizard-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <h2>Entry Created Successfully!</h2>
                    <p>Your knowledge entry has been published and is now searchable.</p>
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
                Create a Knowledge Entry
            </h1>
            <p class="ptk-wizard-intro">Fill in the fields below and we'll format everything for you. No editing required!</p>

            <form method="post" action="" id="ptk-wizard-form" enctype="multipart/form-data">
                <?php wp_nonce_field( 'ptk_wizard_submit', 'ptk_wizard_nonce' ); ?>

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
                        <textarea id="ptk-howto-intro" name="ptk_howto_intro" class="ptk-field-textarea" rows="3"
                                  placeholder="Briefly describe what this guide covers and why it's useful."></textarea>
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
                        <textarea name="ptk_howto_materials" class="ptk-field-textarea" rows="3"
                                  placeholder="List materials or prerequisites, one per line."></textarea>
                        <p class="ptk-field-hint">One item per line. Leave blank if not applicable.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Steps <span class="ptk-required">*</span></label>
                        <p class="ptk-field-hint">Add each step of your guide. You can optionally add an image to any step.</p>
                        <div class="ptk-repeater" id="ptk-howto-steps" data-min="1">
                            <!-- Steps added by JS -->
                        </div>
                        <button type="button" class="button ptk-add-step" data-repeater="ptk-howto-steps">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Step
                        </button>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Tips &amp; Notes</label>
                        <textarea name="ptk_howto_tips" class="ptk-field-textarea" rows="3"
                                  placeholder="Any helpful tips, common mistakes to avoid, or additional notes."></textarea>
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
                        <textarea id="ptk-event-overview" name="ptk_event_overview" class="ptk-field-textarea" rows="3"
                                  placeholder="Describe the event: what it is, who it's for, and the goal."></textarea>
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
                        <textarea name="ptk_event_supplies" class="ptk-field-textarea" rows="4"
                                  placeholder="List supplies needed, one per line. Include quantities if possible.&#10;e.g., 50 paper plates&#10;3 folding tables&#10;1 cash box"></textarea>
                    </div>

                    <div class="ptk-field-group">
                        <label class="ptk-field-label">Key Contacts &amp; Roles</label>
                        <textarea name="ptk_event_contacts" class="ptk-field-textarea" rows="3"
                                  placeholder="List key people and their roles, one per line.&#10;e.g., Jane Smith — Event Chair&#10;John Doe — Setup Lead"></textarea>
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
                        <textarea id="ptk-faq-short-answer" name="ptk_faq_short_answer" class="ptk-field-textarea" rows="3"
                                  placeholder="Write a concise answer that someone could copy and paste."></textarea>
                        <p class="ptk-field-hint">This is the main answer people will see. Keep it clear and copy-friendly.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-faq-details" class="ptk-field-label">Detailed Explanation</label>
                        <textarea id="ptk-faq-details" name="ptk_faq_details" class="ptk-field-textarea" rows="5"
                                  placeholder="Optional: provide more context, background info, or links."></textarea>
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
                        <textarea id="ptk-resource-desc" name="ptk_resource_description" class="ptk-field-textarea" rows="3"
                                  placeholder="What is this resource and why is it useful?"></textarea>
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
                        <textarea id="ptk-resource-howto" name="ptk_resource_howto" class="ptk-field-textarea" rows="3"
                                  placeholder="Instructions for using this resource, one step per line."></textarea>
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
                        <textarea id="ptk-glossary-definition" name="ptk_glossary_definition" class="ptk-field-textarea" rows="3"
                                  placeholder="Explain this term simply — as if talking to someone who just joined the PTA and has never heard of it."></textarea>
                        <p class="ptk-field-hint">This shows as a tooltip when people hover over this term in other entries.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-glossary-details" class="ptk-field-label">More Details</label>
                        <textarea id="ptk-glossary-details" name="ptk_glossary_details" class="ptk-field-textarea" rows="4"
                                  placeholder="Optional: add more context, examples, or links for people who want to learn more."></textarea>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-glossary-example" class="ptk-field-label">Example</label>
                        <textarea id="ptk-glossary-example" name="ptk_glossary_example" class="ptk-field-textarea" rows="2"
                                  placeholder='Optional: e.g., "When you hear someone say POSSE, they mean: put it on the PTA website first, then share it on social media."'></textarea>
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
                        <textarea id="ptk-checklist-intro" name="ptk_checklist_intro" class="ptk-field-textarea" rows="2"
                                  placeholder="Briefly describe what this checklist is for and when to use it."></textarea>
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
                        <textarea id="ptk-checklist-notes" name="ptk_checklist_notes" class="ptk-field-textarea" rows="3"
                                  placeholder="Any additional notes, reminders, or important deadlines."></textarea>
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
                        <textarea id="ptk-policy-summary" name="ptk_policy_summary" class="ptk-field-textarea" rows="3"
                                  placeholder="In plain English, what does this policy or rule say? What do people need to know?"></textarea>
                        <p class="ptk-field-hint">Write this for someone who has never read a bylaws document.</p>
                    </div>

                    <div class="ptk-field-group">
                        <label for="ptk-policy-full-text" class="ptk-field-label">Full Policy Text</label>
                        <textarea id="ptk-policy-full-text" name="ptk_policy_full_text" class="ptk-field-textarea" rows="6"
                                  placeholder="Optional: paste the official policy language here for reference."></textarea>
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
                            <span class="dashicons dashicons-saved"></span> Create Entry
                        </button>
                        <p class="ptk-submit-note">You can always edit the entry later in WordPress if needed.</p>
                    </div>
                </div>

            </form>
        </div>
        <?php
    }

    /**
     * Handle form submission: create the post with auto-generated block content.
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

        // Create the post.
        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $status,
            'post_type'    => 'pta_knowledge',
        );

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            wp_die( 'Error creating entry: ' . esc_html( $post_id->get_error_message() ) );
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
        }

        // Set featured image.
        $featured_id = absint( $_POST['ptk_featured_image'] ?? 0 );
        if ( $featured_id ) {
            set_post_thumbnail( $post_id, $featured_id );
        }

        // Save category-specific meta fields.
        self::save_meta_fields( $post_id, $category );

        // Redirect to success page.
        wp_safe_redirect( add_query_arg(
            array(
                'post_type'   => 'pta_knowledge',
                'page'        => 'ptk-content-wizard',
                'ptk_created' => $post_id,
            ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /**
     * Generate Gutenberg block content from form data based on category.
     */
    private static function generate_content( $category ) {
        switch ( $category ) {
            case 'how-to-guide':
                return self::generate_howto_content();
            case 'event-playbook':
                return self::generate_event_content();
            case 'faq':
                return self::generate_faq_content();
            case 'resource':
                return self::generate_resource_content();
            case 'glossary':
                return self::generate_glossary_content();
            case 'checklist':
                return self::generate_checklist_content();
            case 'policy':
                return self::generate_policy_content();
            default:
                return '';
        }
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
            $blocks[] = '<p>' . esc_html( $intro ) . '</p>';
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
                $blocks[] = '<li>' . esc_html( $item ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        // Steps.
        $step_texts  = isset( $_POST['ptk_step_text'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['ptk_step_text'] ) ) : array();
        $step_images = isset( $_POST['ptk_step_image'] ) ? array_map( 'absint', $_POST['ptk_step_image'] ) : array();

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
                $blocks[] = '<p>' . esc_html( $text ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';

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
                    $blocks[] = '<li>' . esc_html( $tip ) . '</li>';
                }
                $blocks[] = '</ul>';
                $blocks[] = '<!-- /wp:list -->';
            } else {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . esc_html( $tips ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        return implode( "\n\n", $blocks );
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
            $blocks[] = '<p>' . esc_html( $overview ) . '</p>';
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
                $blocks[] = '<li>' . esc_html( $item ) . '</li>';
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
                $blocks[] = '<li>' . esc_html( $item ) . '</li>';
            }
            $blocks[] = '</ul>';
            $blocks[] = '<!-- /wp:list -->';
        }

        return implode( "\n\n", $blocks );
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
            $blocks[] = '<p style="font-weight:600">' . esc_html( $short_answer ) . '</p>';
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
                $blocks[] = '<p>' . esc_html( $para ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        return implode( "\n\n", $blocks );
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
            $blocks[] = '<p>' . esc_html( $description ) . '</p>';
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
                    $blocks[] = '<li>' . esc_html( $step ) . '</li>';
                }
                $blocks[] = '</ol>';
                $blocks[] = '<!-- /wp:list -->';
            } else {
                $blocks[] = '<!-- wp:paragraph -->';
                $blocks[] = '<p>' . esc_html( $howto ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        return implode( "\n\n", $blocks );
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
            $blocks[] = '<p style="font-weight:600">' . esc_html( $definition ) . '</p>';
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
                $blocks[] = '<p>' . esc_html( $para ) . '</p>';
                $blocks[] = '<!-- /wp:paragraph -->';
            }
        }

        if ( ! empty( $example ) ) {
            $blocks[] = '<!-- wp:heading -->';
            $blocks[] = '<h2 class="wp-block-heading">Example</h2>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p><em>' . esc_html( $example ) . '</em></p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        return implode( "\n\n", $blocks );
    }

    /**
     * Generate Checklist content.
     */
    private static function generate_checklist_content() {
        $blocks = array();

        $intro = sanitize_textarea_field( wp_unslash( $_POST['ptk_checklist_intro'] ?? '' ) );
        if ( ! empty( $intro ) ) {
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . esc_html( $intro ) . '</p>';
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
                $blocks[] = '<li>☐ ' . esc_html( $item ) . '</li>';
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
            $blocks[] = '<p>' . esc_html( $notes ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
        }

        return implode( "\n\n", $blocks );
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
            $blocks[] = '<p style="font-weight:600">' . esc_html( $summary ) . '</p>';
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
                $blocks[] = '<p>' . esc_html( $para ) . '</p>';
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

        return implode( "\n\n", $blocks );
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
