<?php
/**
 * Public "Suggest a topic" form + admin queue.
 *
 * Adds:
 * - A `ptk_suggestion` private CPT (admin-only), one row per submission.
 * - Shortcode [ptk_suggest_form] that renders the front-end form.
 * - AJAX endpoint `ptk_submit_suggestion` (logged-in or anonymous).
 * - "Convert to Draft" row action that creates a pta_knowledge draft.
 * - Spam controls: nonce, honeypot, IP-hash rate limit (3/hour).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Suggestions {

    const POST_TYPE   = 'ptk_suggestion';
    const RATE_LIMIT  = 3;          // submissions per IP per hour
    const RATE_WINDOW = HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );

        add_shortcode( 'ptk_suggest_form', array( __CLASS__, 'render_form' ) );

        add_action( 'wp_ajax_ptk_submit_suggestion',        array( __CLASS__, 'handle_submit' ) );
        add_action( 'wp_ajax_nopriv_ptk_submit_suggestion', array( __CLASS__, 'handle_submit' ) );

        add_action( 'admin_action_ptk_convert_suggestion', array( __CLASS__, 'handle_convert' ) );

        // Row actions on the Suggestions list table.
        add_filter( 'post_row_actions', array( __CLASS__, 'register_row_actions' ), 10, 2 );

        // Show suggester info in the admin list.
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',         array( __CLASS__, 'admin_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
    }

    /* -------------------------------------------------------------- */
    /*  CPT                                                           */
    /* -------------------------------------------------------------- */

    public static function register_post_type() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'               => 'Suggestions',
                'singular_name'      => 'Suggestion',
                'menu_name'          => 'Suggestions',
                'add_new_item'       => 'Add Suggestion',
                'edit_item'          => 'Review Suggestion',
                'view_item'          => 'View Suggestion',
                'all_items'          => 'Suggestions',
                'search_items'       => 'Search Suggestions',
                'not_found'          => 'No suggestions yet.',
                'not_found_in_trash' => 'No suggestions in trash.',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=pta_knowledge',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'supports'            => array( 'title', 'editor' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
        ) );
    }

    /* -------------------------------------------------------------- */
    /*  Front-end form                                                */
    /* -------------------------------------------------------------- */

    public static function render_form( $atts ) {
        $atts = shortcode_atts( array(
            'title'  => 'Suggest a topic',
            'intro'  => 'Can\'t find what you need? Tell us what you\'d like added to the PTA Hub.',
        ), $atts );

        ob_start();
        ?>
        <div class="ptk-suggest-form-wrap" id="ptk-suggest">
            <h2 class="ptk-suggest-form-title"><?php echo esc_html( $atts['title'] ); ?></h2>
            <p class="ptk-suggest-form-intro"><?php echo esc_html( $atts['intro'] ); ?></p>

            <form id="ptk-suggest-form" class="ptk-suggest-form">
                <p>
                    <label for="ptk-suggest-title">What's the topic?</label>
                    <input type="text" id="ptk-suggest-title" name="suggestion_title" required minlength="5" maxlength="200" placeholder="e.g., How to set up the membership store" />
                    <span class="ptk-suggest-count" data-target="ptk-suggest-title" data-max="200">0 / 200</span>
                </p>
                <p>
                    <label for="ptk-suggest-body">Anything else you want to add? <span class="ptk-optional">(optional)</span></label>
                    <textarea id="ptk-suggest-body" name="suggestion_body" rows="4" maxlength="2000" placeholder="Why is this useful, where have you looked, who would benefit..."></textarea>
                    <span class="ptk-suggest-count" data-target="ptk-suggest-body" data-max="2000">0 / 2000</span>
                </p>
                <p>
                    <label for="ptk-suggest-name">Your name <span class="ptk-optional">(optional)</span></label>
                    <input type="text" id="ptk-suggest-name" name="suggestion_name" maxlength="100" />
                </p>
                <p>
                    <label for="ptk-suggest-email">Your email <span class="ptk-optional">(optional, only if you'd like a follow-up)</span></label>
                    <input type="email" id="ptk-suggest-email" name="suggestion_email" maxlength="200" />
                </p>
                <p style="position:absolute;left:-9999px;" aria-hidden="true">
                    <label for="ptk-suggest-website">Website</label>
                    <input type="text" id="ptk-suggest-website" name="suggestion_website" tabindex="-1" autocomplete="off" />
                </p>
                <?php wp_nonce_field( 'ptk_submit_suggestion', '_ptk_suggest_nonce' ); ?>
                <p>
                    <button type="submit" class="ptk-suggest-submit">Send suggestion</button>
                </p>
                <div class="ptk-suggest-status" role="status" aria-live="polite"></div>
                <div class="ptk-suggest-thanks" hidden>Thanks! We'll review your suggestion soon.</div>
            </form>
        </div>

        <script>
        (function(){
            var form = document.getElementById('ptk-suggest-form');
            if (!form) return;
            var status = form.querySelector('.ptk-suggest-status');
            var thanks = form.querySelector('.ptk-suggest-thanks');
            var submit = form.querySelector('.ptk-suggest-submit');
            var fields = form.querySelectorAll('p:not(.ptk-suggest-thanks)');

            function showThanks() {
                fields.forEach(function(p){ p.hidden = true; });
                if (status) { status.hidden = true; }
                if (thanks) { thanks.hidden = false; }
            }
            function showError(msg) {
                status.textContent = msg;
                status.className = 'ptk-suggest-status ptk-suggest-error';
                submit.disabled = false;
                submit.textContent = 'Send suggestion';
            }

            // Live character counters.
            form.querySelectorAll('.ptk-suggest-count').forEach(function(span){
                var input = document.getElementById(span.dataset.target);
                var max = parseInt(span.dataset.max, 10);
                if (!input) return;
                function tick(){
                    var len = input.value.length;
                    span.textContent = len + ' / ' + max;
                    span.classList.toggle('ptk-suggest-count-warn', len > max - 20);
                }
                input.addEventListener('input', tick);
                tick();
            });

            form.addEventListener('submit', function(e){
                e.preventDefault();
                var titleEl = form.querySelector('[name="suggestion_title"]');
                if (titleEl && titleEl.value.trim().length < 5) {
                    showError('Please add a few more words to the topic — at least 5 characters.');
                    return;
                }
                status.textContent = '';
                submit.disabled = true;
                submit.textContent = 'Sending...';

                var data = new FormData();
                data.append('action', 'ptk_submit_suggestion');
                data.append('_wpnonce', form.querySelector('[name="_ptk_suggest_nonce"]').value);
                data.append('suggestion_title', form.querySelector('[name="suggestion_title"]').value);
                data.append('suggestion_body', form.querySelector('[name="suggestion_body"]').value);
                data.append('suggestion_name', form.querySelector('[name="suggestion_name"]').value);
                data.append('suggestion_email', form.querySelector('[name="suggestion_email"]').value);
                data.append('suggestion_website', form.querySelector('[name="suggestion_website"]').value);

                fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    if (json && json.success) {
                        showThanks();
                    } else {
                        var msg = (json && json.data && json.data.message) ? json.data.message : 'Something went wrong. Please try again later.';
                        showError(msg);
                    }
                })
                .catch(function(){
                    showError('Network error. Please try again.');
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* -------------------------------------------------------------- */
    /*  AJAX submit                                                   */
    /* -------------------------------------------------------------- */

    public static function handle_submit() {
        check_ajax_referer( 'ptk_submit_suggestion', '_wpnonce' );

        // Honeypot — bots fill the hidden "website" field.
        if ( ! empty( $_POST['suggestion_website'] ) ) {
            // Pretend success so the bot moves on.
            wp_send_json_success( array( 'message' => 'Thanks!' ) );
        }

        $title = isset( $_POST['suggestion_title'] ) ? sanitize_text_field( wp_unslash( $_POST['suggestion_title'] ) ) : '';
        $body  = isset( $_POST['suggestion_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['suggestion_body'] ) ) : '';
        $name  = isset( $_POST['suggestion_name'] ) ? sanitize_text_field( wp_unslash( $_POST['suggestion_name'] ) ) : '';
        $email = isset( $_POST['suggestion_email'] ) ? sanitize_email( wp_unslash( $_POST['suggestion_email'] ) ) : '';

        if ( '' === trim( $title ) ) {
            wp_send_json_error( array( 'message' => 'Please describe the topic.' ) );
        }
        if ( mb_strlen( $title ) > 200 ) {
            wp_send_json_error( array( 'message' => 'Title is too long.' ) );
        }
        // Reject titles that look like spam URLs.
        if ( preg_match( '#https?://#i', $title ) ) {
            wp_send_json_error( array( 'message' => 'Please describe the topic in plain text (no URLs in the title).' ) );
        }

        // Rate limit by IP hash.
        $ip_hash      = self::ip_hash();
        $transient_id = 'ptk_suggest_rate_' . $ip_hash;
        $count        = (int) get_transient( $transient_id );
        if ( $count >= self::RATE_LIMIT ) {
            wp_send_json_error( array( 'message' => 'You\'ve sent a few suggestions recently — please try again in an hour.' ) );
        }
        set_transient( $transient_id, $count + 1, self::RATE_WINDOW );

        $suggestion_id = wp_insert_post( array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish', // private CPT — "publish" just means visible in admin
            'post_title'   => $title,
            'post_content' => $body,
        ), true );

        if ( is_wp_error( $suggestion_id ) ) {
            wp_send_json_error( array( 'message' => 'Could not save the suggestion.' ) );
        }

        if ( $name ) {
            update_post_meta( $suggestion_id, 'ptk_suggester_name', $name );
        }
        if ( $email ) {
            update_post_meta( $suggestion_id, 'ptk_suggester_email', $email );
        }
        update_post_meta( $suggestion_id, 'ptk_suggester_ip_hash', $ip_hash );

        wp_send_json_success( array( 'message' => 'Suggestion received.' ) );
    }

    private static function ip_hash(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return substr( hash( 'sha256', $ip . wp_salt() ), 0, 32 );
    }

    /* -------------------------------------------------------------- */
    /*  Admin: row actions + convert                                  */
    /* -------------------------------------------------------------- */

    public static function register_row_actions( $actions, $post ) {
        if ( ! isset( $post->post_type ) || self::POST_TYPE !== $post->post_type ) {
            return $actions;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url( 'admin.php?action=ptk_convert_suggestion&id=' . $post->ID ),
            'ptk_convert_suggestion_' . $post->ID
        );
        // Visually distinct "Convert to Draft" (blue, bold) so editors can tell
        // it apart from the standard Trash row action at a glance.
        $actions = array_merge(
            array( 'ptk_convert' => '<a href="' . esc_url( $url ) . '" style="color:#2563eb;font-weight:600;">Convert to Draft</a>' ),
            $actions
        );
        return $actions;
    }

    public static function handle_convert() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ptk_convert_suggestion_' . $id );

        $suggestion = get_post( $id );
        if ( ! $suggestion || self::POST_TYPE !== $suggestion->post_type ) {
            wp_die( 'Suggestion not found.', '', array( 'response' => 404 ) );
        }

        $draft_id = wp_insert_post( array(
            'post_type'    => 'pta_knowledge',
            'post_status'  => 'draft',
            'post_title'   => $suggestion->post_title,
            'post_content' => $suggestion->post_content,
        ), true );

        if ( is_wp_error( $draft_id ) ) {
            wp_die( 'Could not create draft.', '', array( 'response' => 500 ) );
        }

        update_post_meta( $draft_id, 'ptk_from_suggestion', $id );
        update_post_meta( $id, 'ptk_converted_to', $draft_id );

        // Trash the suggestion now that it has a home as a draft.
        wp_trash_post( $id );

        wp_safe_redirect( get_edit_post_link( $draft_id, 'raw' ) );
        exit;
    }

    /* -------------------------------------------------------------- */
    /*  Admin columns                                                 */
    /* -------------------------------------------------------------- */

    public static function admin_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['ptk_suggester'] = 'Suggester';
            }
        }
        if ( ! isset( $new['ptk_suggester'] ) ) {
            $new['ptk_suggester'] = 'Suggester';
        }
        return $new;
    }

    public static function render_admin_column( $column, $post_id ) {
        if ( 'ptk_suggester' !== $column ) {
            return;
        }
        $name  = get_post_meta( $post_id, 'ptk_suggester_name', true );
        $email = get_post_meta( $post_id, 'ptk_suggester_email', true );
        if ( $name || $email ) {
            echo esc_html( $name ? $name : 'Anonymous' );
            if ( $email ) {
                echo '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
            }
        } else {
            echo '<em style="color:#9ca3af;">Anonymous</em>';
        }
    }
}
