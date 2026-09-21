<?php
/**
 * "Words you've explained" -- a glossary-only home for the pta_knowledge
 * entries that carry the `glossary` category, alphabetical, so it reads
 * the way the public glossary page reads.
 *
 * Today those entries only ever show up mixed into "What you've written"
 * (PTK_Written_List). This is a second door onto exactly the same entries,
 * filtered to one category -- it doesn't own or duplicate any data.
 *
 * Registered only when PTK_Hub_Look::on() -- see add_page() -- the same
 * gate PTK_Asked_For_List::add_page() uses.
 *
 * Search-as-you-type reuses PTK_Written_List's own script (written-list.js)
 * as-is: the markup below uses the same ids and data-ptk-* attributes that
 * script already looks for (#ptk-written-list, #ptk-written-search,
 * .ptk-entry-card[data-ptk-search]), so nothing here invents a second
 * mechanism. Without JavaScript the search box still works, as a plain GET
 * form posting `s` back to this same page.
 *
 * Split the same way as PTK_Written_List: pure helpers live in
 * PTK_Words_Copy; this class is WordPress-facing layout only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Words_List {

    /** This screen's own page slug -- a real admin page, not a list-table view. */
    const PAGE_SLUG = 'ptk-words';

    /** The category every glossary entry carries (see PTK_Glossary_Page). */
    const CATEGORY_SLUG = 'glossary';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /* ------------------------------------------------------------------
     * WordPress-facing.
     * ----------------------------------------------------------------*/

    /** Canonical admin URL of this screen. Same edit.php submenu shape as the others. */
    public static function url() {
        return admin_url( 'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG );
    }

    /**
     * Register this screen -- only while the new look is on. With it off,
     * this never runs: no menu entry, no page, nothing registered.
     */
    public static function add_page() {
        if ( ! class_exists( 'PTK_Hub_Look' ) || ! PTK_Hub_Look::on() ) {
            return;
        }
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            "Words you've explained",
            "Words you've explained",
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /**
     * Reuse PTK_Written_List's own script rather than copying it -- the
     * markup this screen renders uses the same ids and data-ptk-* attributes
     * it already looks for.
     */
    public static function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
            return;
        }
        wp_enqueue_script(
            'ptk-written-list',
            PTK_PLUGIN_URL . 'assets/js/written-list.js',
            array(),
            PTK_VERSION,
            true
        );
    }

    /** Shape one WP_Post (a glossary entry) into the flat array the card renderer needs. */
    private static function shape_term( $post ) {
        $id = $post->ID;

        $short = trim( (string) $post->post_excerpt );
        $long  = wp_strip_all_tags( (string) $post->post_content );
        $definition = PTK_Words_Copy::definition( $short, $long );

        $letter = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( get_the_title( $post ), 0, 1 ) ) : strtoupper( substr( get_the_title( $post ), 0, 1 ) );
        if ( '' === $letter || is_numeric( $letter ) ) {
            $letter = '#';
        }

        return array(
            'id'              => $id,
            'word'            => get_the_title( $post ),
            'definition'      => $definition,
            'is_draft'        => ( 'draft' === $post->post_status ),
            'can_edit'        => current_user_can( 'edit_post', $id ),
            'edit_url'        => class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_edit_id', $id, PTK_Content_Wizard::url() ) : get_edit_post_link( $id, '' ),
            'glossary_url'    => function_exists( 'ptk_glossary_url' ) ? ptk_glossary_url() . '#glossary-' . rawurlencode( $letter ) : '',
            'search_haystack' => function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( get_the_title( $post ) . ' ' . $definition ) ) : strtolower( trim( get_the_title( $post ) . ' ' . $definition ) ),
        );
    }

    /**
     * Every glossary entry the current user may see, alphabetical --
     * that's how the public glossary reads, and this screen agrees with it.
     *
     * @param string $search Sanitized search text (may be '').
     * @return array Shaped terms (see shape_term()).
     */
    public static function get_terms( $search = '' ) {
        $args = array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => array( 'publish', 'draft' ),
            'perm'           => 'readable',
            'posts_per_page' => 300,
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'knowledge_category',
                    'field'    => 'slug',
                    'terms'    => self::CATEGORY_SLUG,
                ),
            ),
        );
        if ( '' !== $search ) {
            $args['s'] = $search;
        }
        $query = new WP_Query( $args );
        $terms = array();
        foreach ( $query->posts as $post ) {
            $terms[] = self::shape_term( $post );
        }
        return $terms;
    }

    /** Render the screen. */
    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( "You don't have permission to see this.", 'Not allowed', array( 'response' => 403 ) );
        }

        $search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $all_terms = self::get_terms( '' );
        $terms     = self::get_terms( $search );

        $add_url = class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_for', 'word', PTK_Content_Wizard::url() ) : '';

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( PTK_Words_Copy::title(), PTK_Words_Copy::lead() );

        if ( '' !== $add_url ) {
            echo '<p>' . PTK_Hub_UI::primary_button( PTK_Words_Copy::add_button_label(), $add_url ) . '</p>';
        }

        if ( empty( $all_terms ) ) {
            $empty = PTK_Words_Copy::empty_state();
            echo '<div class="ptk-empty">';
            echo '<p class="ptk-card-title">' . esc_html( $empty['title'] ) . '</p>';
            echo '<p class="ptk-help">' . esc_html( $empty['text'] ) . '</p>';
            if ( '' !== $add_url ) {
                echo '<p>' . PTK_Hub_UI::primary_button( PTK_Words_Copy::add_button_label(), $add_url ) . '</p>';
            }
            echo '</div>';
        } else {
            echo '<form method="get" class="ptk-written-search-form" id="ptk-written-search-form">';
            echo '<input type="hidden" name="post_type" value="pta_knowledge">';
            echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
            echo '<input type="search" name="s" id="ptk-written-search" class="ptk-written-search" placeholder="' . esc_attr( PTK_Words_Copy::search_placeholder() ) . '" value="' . esc_attr( $search ) . '">';
            echo '</form>';

            echo '<div class="ptk-written-list" id="ptk-written-list">';
            if ( empty( $terms ) ) {
                echo '<div class="ptk-empty" id="ptk-written-empty">';
                echo '<p class="ptk-card-title">' . esc_html( 'Nothing matched "' . $search . '".' ) . '</p>';
                echo '<p class="ptk-help"><a href="' . esc_url( $add_url ) . '">Try a different word, or explain that one now &#8594;</a></p>';
                echo '</div>';
            } else {
                foreach ( $terms as $term ) {
                    echo self::render_card( $term );
                }
            }
            // Hidden until JS's own live search (written-list.js) hides every
            // card in place -- the server-rendered empty state above only
            // covers the initial page load.
            echo '<div class="ptk-empty" id="ptk-written-no-match" hidden>';
            echo '<p class="ptk-card-title" id="ptk-written-no-match-title">Nothing matched.</p>';
            echo '<p class="ptk-help"><a href="' . esc_url( $add_url ) . '">Try a different word, or explain that one now &#8594;</a></p>';
            echo '</div>';
            echo '</div>';
        }

        echo PTK_Hub_UI::quiet_links( array(
            array(
                'label' => PTK_Words_Copy::glossary_link_label(),
                'url'   => function_exists( 'ptk_glossary_url' ) ? ptk_glossary_url() : '',
            ),
        ) );

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    /** One card's markup for a shaped term (see shape_term()). */
    private static function render_card( array $term ) {
        $out  = '<article class="ptk-entry-card" data-ptk-key="' . esc_attr( $term['id'] ) . '" data-ptk-search="' . esc_attr( $term['search_haystack'] ) . '">';
        $out .= '<h2 class="ptk-entry-question">' . esc_html( $term['word'] ) . '</h2>';

        $definition = '' !== $term['definition'] ? $term['definition'] : PTK_Words_Copy::no_definition_note();
        $out .= '<p class="ptk-entry-answer">' . esc_html( $definition ) . '</p>';

        if ( $term['is_draft'] ) {
            $out .= '<p class="ptk-entry-meta">' . esc_html( PTK_Words_Copy::not_sent_note() ) . '</p>';
        }

        $out .= '<div class="ptk-entry-actions">';
        if ( $term['can_edit'] ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $term['edit_url'] ) . '">' . esc_html( PTK_Words_Copy::change_button_label() ) . '</a>';
        }
        if ( '' !== $term['glossary_url'] && ! $term['is_draft'] ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $term['glossary_url'] ) . '" target="_blank" rel="noopener">' . esc_html( PTK_Words_Copy::view_button_label() ) . '</a>';
        }
        $out .= '</div>';

        $out .= '</article>';
        return $out;
    }
}
