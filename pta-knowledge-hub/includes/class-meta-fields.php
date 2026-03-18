<?php
/**
 * Category-specific meta fields for PTA Knowledge entries.
 *
 * Registers custom post meta with REST API support so they appear
 * in the Gutenberg sidebar. Fields are rendered on the single template
 * and optionally in search result cards.
 *
 * Fields per category:
 * - How-To Guide: difficulty, time_estimate
 * - Event Playbook: event_date, location, budget
 * - FAQ: last_reviewed
 * - Resource: resource_url, file_type
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Meta_Fields {

    /**
     * All meta field definitions grouped by category.
     */
    private static $fields = array(
        'how-to-guide' => array(
            'ptk_difficulty'    => array(
                'label'   => 'Difficulty',
                'type'    => 'string',
                'default' => '',
            ),
            'ptk_time_estimate' => array(
                'label'   => 'Time Estimate',
                'type'    => 'string',
                'default' => '',
            ),
        ),
        'event-playbook' => array(
            'ptk_event_date' => array(
                'label'   => 'Event Date',
                'type'    => 'string',
                'default' => '',
            ),
            'ptk_location' => array(
                'label'   => 'Location',
                'type'    => 'string',
                'default' => '',
            ),
            'ptk_budget' => array(
                'label'   => 'Budget',
                'type'    => 'string',
                'default' => '',
            ),
        ),
        'faq' => array(
            'ptk_last_reviewed' => array(
                'label'   => 'Last Reviewed',
                'type'    => 'string',
                'default' => '',
            ),
        ),
        'resource' => array(
            'ptk_resource_url' => array(
                'label'   => 'Resource URL',
                'type'    => 'string',
                'default' => '',
            ),
            'ptk_file_type' => array(
                'label'   => 'File Type',
                'type'    => 'string',
                'default' => '',
            ),
        ),
    );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_meta' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_pta_knowledge', array( __CLASS__, 'save_meta' ), 10, 2 );
    }

    /**
     * Register all meta fields with REST API support.
     */
    public static function register_meta() {
        foreach ( self::$fields as $cat_fields ) {
            foreach ( $cat_fields as $key => $def ) {
                register_post_meta( 'pta_knowledge', $key, array(
                    'type'              => $def['type'],
                    'single'            => true,
                    'default'           => $def['default'],
                    'show_in_rest'      => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback'     => function() {
                        return current_user_can( 'edit_posts' );
                    },
                ) );
            }
        }
    }

    /**
     * Add the category-specific meta box in the classic editor sidebar.
     */
    public static function add_meta_box() {
        add_meta_box(
            'ptk_category_fields',
            'Entry Details',
            array( __CLASS__, 'render_meta_box' ),
            'pta_knowledge',
            'side',
            'default'
        );
    }

    /**
     * Render the meta box with fields relevant to the post's category.
     */
    public static function render_meta_box( $post ) {
        wp_nonce_field( 'ptk_meta_fields', 'ptk_meta_nonce' );

        $cat_slug = self::get_post_category_slug( $post->ID );
        $all_fields = self::get_fields_for_category( $cat_slug );

        if ( empty( $all_fields ) ) {
            echo '<p class="description">Select a category to see relevant fields.</p>';
            return;
        }

        echo '<div class="ptk-meta-fields">';

        foreach ( $all_fields as $key => $def ) {
            $value = get_post_meta( $post->ID, $key, true );
            $id = esc_attr( $key );
            $label = esc_html( $def['label'] );

            echo '<p>';
            echo '<label for="' . $id . '"><strong>' . $label . '</strong></label><br>';

            if ( 'ptk_difficulty' === $key ) {
                echo '<select name="' . $id . '" id="' . $id . '" style="width:100%">';
                $options = array( '' => '— Select —', 'Easy' => 'Easy', 'Medium' => 'Medium', 'Hard' => 'Hard' );
                foreach ( $options as $opt_val => $opt_label ) {
                    $selected = selected( $value, $opt_val, false );
                    echo '<option value="' . esc_attr( $opt_val ) . '"' . $selected . '>' . esc_html( $opt_label ) . '</option>';
                }
                echo '</select>';
            } elseif ( 'ptk_file_type' === $key ) {
                echo '<select name="' . $id . '" id="' . $id . '" style="width:100%">';
                $options = array( '' => '— Select —', 'PDF' => 'PDF', 'Video' => 'Video', 'Template' => 'Template', 'Form' => 'Form', 'Image' => 'Image', 'Other' => 'Other' );
                foreach ( $options as $opt_val => $opt_label ) {
                    $selected = selected( $value, $opt_val, false );
                    echo '<option value="' . esc_attr( $opt_val ) . '"' . $selected . '>' . esc_html( $opt_label ) . '</option>';
                }
                echo '</select>';
            } elseif ( strpos( $key, '_date' ) !== false || strpos( $key, '_reviewed' ) !== false ) {
                echo '<input type="date" name="' . $id . '" id="' . $id . '" value="' . esc_attr( $value ) . '" style="width:100%">';
            } elseif ( strpos( $key, '_url' ) !== false ) {
                echo '<input type="url" name="' . $id . '" id="' . $id . '" value="' . esc_attr( $value ) . '" placeholder="https://" style="width:100%">';
            } else {
                echo '<input type="text" name="' . $id . '" id="' . $id . '" value="' . esc_attr( $value ) . '" style="width:100%">';
            }

            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Save meta fields on post save.
     */
    public static function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ptk_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ptk_meta_nonce'], 'ptk_meta_fields' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Save all known meta keys if present in POST data.
        foreach ( self::$fields as $cat_fields ) {
            foreach ( $cat_fields as $key => $def ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
                    update_post_meta( $post_id, $key, $value );
                }
            }
        }
    }

    /**
     * Get the primary category slug for a post.
     */
    public static function get_post_category_slug( $post_id ) {
        $terms = wp_get_post_terms( $post_id, 'knowledge_category', array( 'fields' => 'slugs' ) );
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            return $terms[0];
        }
        return '';
    }

    /**
     * Get the fields applicable to a given category slug.
     * Returns all fields if no specific category matches.
     */
    public static function get_fields_for_category( $cat_slug ) {
        if ( isset( self::$fields[ $cat_slug ] ) ) {
            return self::$fields[ $cat_slug ];
        }

        // If no category set, show all fields so nothing is hidden.
        $all = array();
        foreach ( self::$fields as $cat_fields ) {
            $all = array_merge( $all, $cat_fields );
        }
        return $all;
    }

    /**
     * Get field definitions (used by single template to render meta).
     */
    public static function get_all_fields() {
        return self::$fields;
    }
}
