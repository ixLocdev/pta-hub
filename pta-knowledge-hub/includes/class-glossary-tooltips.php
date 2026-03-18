<?php
/**
 * Glossary Tooltips — auto-link glossary terms in knowledge base content.
 *
 * Scans post content for terms that match Glossary entries and wraps
 * them in a tooltip so readers can hover to see the plain-English
 * definition without leaving the page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Glossary_Tooltips {

    /** @var array|null Cached glossary terms. */
    private static $terms = null;

    public static function init() {
        add_filter( 'the_content', array( __CLASS__, 'add_tooltips' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
    }

    /**
     * Enqueue tooltip CSS on knowledge base pages.
     */
    public static function enqueue_styles() {
        if ( ! is_singular( 'pta_knowledge' ) && ! self::is_knowledge_base_page() ) {
            return;
        }

        wp_enqueue_style(
            'ptk-glossary-tooltips',
            PTK_PLUGIN_URL . 'assets/css/glossary-tooltips.css',
            array(),
            PTK_VERSION
        );
    }

    /**
     * Check if we're on the knowledge base search page.
     */
    private static function is_knowledge_base_page() {
        global $post;
        return $post && has_shortcode( $post->post_content, 'pta_search' );
    }

    /**
     * Scan content and wrap glossary terms with tooltip markup.
     */
    public static function add_tooltips( $content ) {
        // Only on knowledge base content (single entries or search page).
        if ( ! is_singular( 'pta_knowledge' ) && ! self::is_knowledge_base_page() ) {
            return $content;
        }

        $terms = self::get_glossary_terms();
        if ( empty( $terms ) ) {
            return $content;
        }

        // Don't add tooltips inside a glossary entry to itself.
        if ( is_singular( 'pta_knowledge' ) ) {
            global $post;
            $current_cats = wp_get_post_terms( $post->ID, 'knowledge_category', array( 'fields' => 'slugs' ) );
            if ( ! is_wp_error( $current_cats ) && in_array( 'glossary', $current_cats, true ) ) {
                // Remove this post's own title from the tooltip list.
                $own_title = strtolower( $post->post_title );
                $terms = array_filter( $terms, function( $term ) use ( $own_title ) {
                    return strtolower( $term['title'] ) !== $own_title;
                });
            }
        }

        if ( empty( $terms ) ) {
            return $content;
        }

        // Sort by title length descending so longer terms match first
        // (e.g., "Google Workspace" before "Google").
        usort( $terms, function( $a, $b ) {
            return strlen( $b['title'] ) - strlen( $a['title'] );
        });

        // Track which terms we've already linked to avoid duplicates.
        $linked = array();

        foreach ( $terms as $term ) {
            if ( isset( $linked[ strtolower( $term['title'] ) ] ) ) {
                continue;
            }

            $pattern = '/\b(' . preg_quote( $term['title'], '/' ) . ')\b/i';

            // Only replace the FIRST occurrence, and only in text nodes
            // (not inside HTML tags or existing tooltips).
            $new_content = self::replace_first_in_text( $content, $pattern, $term );

            if ( $new_content !== $content ) {
                $content = $new_content;
                $linked[ strtolower( $term['title'] ) ] = true;
            }
        }

        return $content;
    }

    /**
     * Replace only the first occurrence of a term, and only in visible
     * text (not inside HTML tags, attributes, or existing tooltips).
     */
    private static function replace_first_in_text( $html, $pattern, $term ) {
        // Split content by HTML tags to avoid replacing inside tags.
        $parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        $replaced = false;
        $inside_tooltip = false;

        foreach ( $parts as &$part ) {
            // Skip HTML tags.
            if ( isset( $part[0] ) && $part[0] === '<' ) {
                // Track if we're inside an existing tooltip.
                if ( strpos( $part, 'ptk-glossary-term' ) !== false ) {
                    $inside_tooltip = true;
                }
                if ( $inside_tooltip && strpos( $part, '</span' ) !== false ) {
                    $inside_tooltip = false;
                }
                continue;
            }

            if ( $replaced || $inside_tooltip ) {
                continue;
            }

            // Try to replace in this text node.
            $definition = esc_attr( wp_strip_all_tags( $term['definition'] ) );
            $link       = esc_url( $term['url'] );
            $replacement = '<span class="ptk-glossary-term" data-definition="' . $definition . '">'
                         . '<a href="' . $link . '" class="ptk-glossary-link">$1</a>'
                         . '<span class="ptk-glossary-tooltip">' . esc_html( wp_strip_all_tags( $term['definition'] ) ) . '</span>'
                         . '</span>';

            $new_part = preg_replace( $pattern, $replacement, $part, 1, $count );
            if ( $count > 0 ) {
                $part = $new_part;
                $replaced = true;
            }
        }
        unset( $part );

        return implode( '', $parts );
    }

    /**
     * Get all glossary terms (title + excerpt as definition + permalink).
     * Cached per request.
     */
    private static function get_glossary_terms() {
        if ( self::$terms !== null ) {
            return self::$terms;
        }

        // Try transient cache first (cleared when glossary posts are saved).
        $cached = get_transient( 'ptk_glossary_terms' );
        if ( false !== $cached ) {
            self::$terms = $cached;
            return self::$terms;
        }

        $glossary_term = get_term_by( 'slug', 'glossary', 'knowledge_category' );
        if ( ! $glossary_term ) {
            self::$terms = array();
            return self::$terms;
        }

        $posts = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'knowledge_category',
                    'field'    => 'term_id',
                    'terms'    => $glossary_term->term_id,
                ),
            ),
        ) );

        self::$terms = array();
        foreach ( $posts as $p ) {
            $definition = $p->post_excerpt;
            if ( empty( $definition ) ) {
                // Fall back to first paragraph of content.
                $stripped = wp_strip_all_tags( $p->post_content );
                $definition = wp_trim_words( $stripped, 30, '…' );
            }
            if ( empty( $definition ) ) {
                continue;
            }

            self::$terms[] = array(
                'title'      => $p->post_title,
                'definition' => $definition,
                'url'        => get_permalink( $p->ID ),
            );
        }

        // Cache for 1 hour.
        set_transient( 'ptk_glossary_terms', self::$terms, HOUR_IN_SECONDS );

        return self::$terms;
    }

    /**
     * Invalidate the glossary cache when a glossary post is saved.
     * Called from save_post hook (registered below).
     */
    public static function invalidate_cache( $post_id ) {
        if ( 'pta_knowledge' !== get_post_type( $post_id ) ) {
            return;
        }

        $terms = wp_get_post_terms( $post_id, 'knowledge_category', array( 'fields' => 'slugs' ) );
        if ( ! is_wp_error( $terms ) && in_array( 'glossary', $terms, true ) ) {
            delete_transient( 'ptk_glossary_terms' );
        }
    }
}

// Invalidate glossary cache when glossary posts are saved/deleted.
add_action( 'save_post_pta_knowledge', array( 'PTK_Glossary_Tooltips', 'invalidate_cache' ) );
add_action( 'delete_post', array( 'PTK_Glossary_Tooltips', 'invalidate_cache' ) );
