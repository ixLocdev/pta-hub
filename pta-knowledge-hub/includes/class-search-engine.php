<?php
/**
 * Custom search engine with relevance scoring.
 *
 * Scores results by title, tags, excerpt, and content relevance.
 * Uses a multi-layer approach: exact match → word match → substring
 * match → stem/prefix match, so even partial queries find results.
 *
 * Performance features:
 * - Batch term loading (2 queries instead of N×3)
 * - Transient caching (1 hour, invalidated on post save/delete)
 * - Result limit (configurable via filter)
 * - Synonym file cached in static variable
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Search_Engine {

    /**
     * Scoring weights — tune these to adjust result ranking.
     */
    const SCORE_TITLE_EXACT    = 50;  // Full query found as substring in title.
    const SCORE_TITLE_WORD     = 20;  // Individual word matches title (word boundary).
    const SCORE_TITLE_SUBSTR   = 10;  // Individual word found as substring in title.
    const SCORE_TAG            = 15;  // Word matches a tag.
    const SCORE_EXCERPT        = 10;  // Word matches excerpt.
    const SCORE_EXCERPT_SUBSTR = 5;   // Word found as substring in excerpt.
    const SCORE_CONTENT        = 3;   // Word matches content (word boundary).
    const SCORE_CONTENT_SUBSTR = 1;   // Word found as substring in content.
    const SCORE_INTENT_BOOST   = 30;  // Category matches detected intent.
    const SCORE_STEM_TITLE     = 8;   // Stem/prefix matches title.
    const SCORE_STEM_CONTENT   = 1;   // Stem/prefix matches content.
    const SCORE_PHRASE_TITLE   = 25;  // Multi-word phrase found in sequence in title.
    const SCORE_PHRASE_CONTENT = 8;   // Multi-word phrase found in sequence in content.
    const SCORE_RECENCY_MAX    = 10;  // Max bonus for recently modified entries.
    const SCORE_POPULARITY_MAX = 15;  // Max bonus for frequently clicked entries.
    const SCORE_FUZZY_TITLE    = 12;  // Fuzzy/typo match against a title word.
    const SCORE_FUZZY_TAG      = 8;   // Fuzzy/typo match against a tag.

    /**
     * Cached synonyms data (loaded once per request).
     */
    private static $synonyms_cache = null;

    /**
     * Intent detection patterns.
     * Maps query keywords to knowledge_category slugs.
     */
    private static $intent_map = array(
        'how-to-guide'   => array( 'how to', 'how do', 'steps', 'instructions', 'procedure', 'process', 'setup', 'set up', 'guide' ),
        'event-playbook' => array( 'event', 'when is', 'planning', 'schedule', 'timeline', 'supplies', 'budget' ),
        'faq'            => array( 'faq', 'question', 'what is', 'why do', 'can i', 'do we', 'is there', 'explain' ),
        'resource'       => array( 'video', 'watch', 'template', 'flyer', 'form', 'download', 'image', 'photo' ),
        'glossary'       => array( 'what is', 'what does', 'define', 'definition', 'meaning', 'term', 'glossary', 'stands for', 'acronym' ),
        'checklist'      => array( 'checklist', 'to do', 'todo', 'transition', 'onboarding', 'new officer', 'setup list' ),
        'policy'         => array( 'policy', 'rule', 'rules', 'bylaw', 'bylaws', 'standing rule', 'guideline', 'governance' ),
    );

    /**
     * Minimal stopwords — only the most meaningless words.
     * Kept short so searches like "how to post" keep "how" and "post".
     */
    private static $stopwords = array(
        'a', 'an', 'the', 'it', 'i', 'me', 'my',
        'of', 'in', 'on', 'and', 'or', 'at',
        'be', 'am',
    );

    public static function init() {
        add_action( 'wp_ajax_pta_search', array( __CLASS__, 'handle_search' ) );
        add_action( 'wp_ajax_nopriv_pta_search', array( __CLASS__, 'handle_search' ) );

        // Autocomplete endpoint (lightweight — returns titles only).
        add_action( 'wp_ajax_pta_autocomplete', array( __CLASS__, 'handle_autocomplete' ) );
        add_action( 'wp_ajax_nopriv_pta_autocomplete', array( __CLASS__, 'handle_autocomplete' ) );

        // Click tracking for popularity-weighted ranking.
        add_action( 'wp_ajax_pta_track_click', array( __CLASS__, 'handle_track_click' ) );
        add_action( 'wp_ajax_nopriv_pta_track_click', array( __CLASS__, 'handle_track_click' ) );

        // Debug endpoint — remove after troubleshooting.
        add_action( 'wp_ajax_pta_debug_terms', array( __CLASS__, 'debug_terms' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_debug_terms' ) );

        // Invalidate search cache when knowledge posts change.
        add_action( 'save_post_pta_knowledge', array( __CLASS__, 'invalidate_cache' ) );
        add_action( 'delete_post', array( __CLASS__, 'invalidate_cache_on_delete' ) );
        // Also invalidate when taxonomy terms are changed on any post.
        add_action( 'set_object_terms', array( __CLASS__, 'invalidate_cache_on_term_change' ), 10, 4 );
    }

    /**
     * Hook into admin_init — if ?ptk_debug_terms=1 is in the URL, output debug info.
     * Access via: /wp-admin/?ptk_debug_terms=1
     * Remove after troubleshooting.
     */
    public static function maybe_debug_terms() {
        if ( isset( $_GET['ptk_debug_terms'] ) && '1' === $_GET['ptk_debug_terms'] ) {
            self::debug_terms();
        }
    }

    /**
     * Debug endpoint: shows all taxonomies and terms for every pta_knowledge post.
     * Remove after troubleshooting.
     */
    public static function debug_terms() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Admin only.' );
        }

        $posts = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
        ) );

        $debug = array();
        foreach ( $posts as $post ) {
            $kc = wp_get_post_terms( $post->ID, 'knowledge_category', array( 'fields' => 'all' ) );
            $cat = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'all' ) );
            $debug[] = array(
                'id'                  => $post->ID,
                'title'               => $post->post_title,
                'knowledge_category'  => is_wp_error( $kc ) ? 'ERROR: ' . $kc->get_error_message() : array_map( function( $t ) { return $t->slug . ' (' . $t->name . ')'; }, $kc ),
                'category'            => is_wp_error( $cat ) ? 'ERROR: ' . $cat->get_error_message() : array_map( function( $t ) { return $t->slug . ' (' . $t->name . ')'; }, $cat ),
            );
        }

        header( 'Content-Type: application/json' );
        echo json_encode( $debug, JSON_PRETTY_PRINT );
        exit;
    }

    /**
     * Clear all search transients when content changes.
     */
    public static function invalidate_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_ptk_search_%'
                OR option_name LIKE '_transient_timeout_ptk_search_%'"
        );
    }

    /**
     * Clear cache only when a pta_knowledge post is deleted.
     */
    public static function invalidate_cache_on_delete( $post_id ) {
        if ( 'pta_knowledge' === get_post_type( $post_id ) ) {
            self::invalidate_cache();
        }
    }

    /**
     * Clear cache when taxonomy terms change on a pta_knowledge post.
     */
    public static function invalidate_cache_on_term_change( $object_id, $terms, $tt_ids, $taxonomy ) {
        if ( in_array( $taxonomy, array( 'knowledge_category', 'post_tag' ), true ) ) {
            if ( 'pta_knowledge' === get_post_type( $object_id ) ) {
                self::invalidate_cache();
            }
        }
    }

    /**
     * Run a knowledge-base search and return formatted, ranked results.
     *
     * Public reusable API. Other classes (Wizard related-entries panel, etc.)
     * call this directly to get a flat list of formatted results without the
     * AJAX response shaping (best-answer detection, grouping, "did you mean"
     * suggestions) that handle_search() does on top.
     *
     * @param string $query Search string.
     * @param array  $args  {
     *     Optional. Defaults shown.
     *     @type int    $limit    Max results to return. Default 20.
     *     @type int[]  $exclude  Post IDs to skip.
     *     @type string $category Category slug filter, '' for all.
     * }
     * @return array List of formatted results (id, title, permalink, excerpt, category, catName, tags, score, ...).
     */
    public static function search( string $query, array $args = array() ): array {
        $scored = self::score_posts( $query, $args );
        $out    = array();
        foreach ( $scored as $entry ) {
            $out[] = self::format_result( $entry['post'], $entry['score'], $entry['terms'] );
        }
        return $out;
    }

    /**
     * Core scoring loop. Returns ranked entries with the original post object,
     * score, and pre-loaded terms — the shape the AJAX handler needs for
     * best-answer detection and grouping.
     *
     * @param string $query
     * @param array  $args  Same shape as search().
     * @return array<int, array{post: WP_Post, score: int, terms: array}>
     */
    private static function score_posts( string $query, array $args ): array {
        $args = array_merge( array(
            'limit'    => 20,
            'exclude'  => array(),
            'category' => '',
        ), $args );

        $query = trim( $query );
        if ( '' === $query ) {
            return array();
        }

        $tokens          = self::tokenize( $query );
        $raw_words       = self::tokenize_raw( $query );
        $expanded_tokens = self::expand_synonyms( $tokens, $query );
        $intent_slugs    = self::detect_intent( $query );

        if ( empty( $tokens ) && empty( $raw_words ) ) {
            return array();
        }

        $get_args = array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
        );
        if ( ! empty( $args['exclude'] ) ) {
            $get_args['post__not_in'] = array_map( 'intval', (array) $args['exclude'] );
        }
        if ( ! empty( $args['category'] ) ) {
            $get_args['tax_query'] = array(
                array(
                    'taxonomy' => 'knowledge_category',
                    'field'    => 'slug',
                    'terms'    => $args['category'],
                ),
            );
        }

        $posts = get_posts( $get_args );
        if ( empty( $posts ) ) {
            return array();
        }

        $post_ids       = wp_list_pluck( $posts, 'ID' );
        $terms_map      = self::batch_load_terms( $post_ids );
        $popularity_map = self::get_popularity_map( $post_ids );

        $scored = array();
        foreach ( $posts as $post ) {
            $post_terms = isset( $terms_map[ $post->ID ] ) ? $terms_map[ $post->ID ] : array(
                'categories' => array(),
                'cat_slugs'  => array(),
                'tag_names'  => array(),
            );
            $popularity = isset( $popularity_map[ $post->ID ] ) ? $popularity_map[ $post->ID ] : 0;
            $score = self::score_post( $post, $query, $expanded_tokens, $raw_words, $intent_slugs, $post_terms, $popularity );
            if ( $score > 0 ) {
                $scored[] = array(
                    'post'  => $post,
                    'score' => $score,
                    'terms' => $post_terms,
                );
            }
        }

        if ( class_exists( 'PTK_Role_Access' ) ) {
            $scored = PTK_Role_Access::filter_search_results( $scored );
        }

        usort( $scored, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        $limit  = max( 1, (int) $args['limit'] );
        return array_slice( $scored, 0, $limit );
    }

    /**
     * AJAX handler: searches pta_knowledge posts and returns the full response
     * shape (best-answer, groups, suggestions, did-you-mean) consumed by the
     * front-end search UI.
     */
    public static function handle_search() {
        check_ajax_referer( 'ptk_search_nonce', '_wpnonce' );

        // Block search for logged-out users when login is required.
        if ( ! ptk_check_access() ) {
            wp_send_json_error( array( 'message' => 'Login required.' ), 403 );
        }

        $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        if ( empty( trim( $query ) ) ) {
            wp_send_json_success( array(
                'results'    => array(),
                'bestAnswer' => null,
                'total'      => 0,
            ) );
        }

        // Check transient cache.
        $cache_key = 'ptk_search_' . md5( mb_strtolower( $query ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
        }

        // Handle queries that tokenize to nothing (single char or empty).
        $probe_tokens    = self::tokenize( $query );
        $probe_raw_words = self::tokenize_raw( $query );
        if ( empty( $probe_tokens ) && empty( $probe_raw_words ) ) {
            wp_send_json_success( array(
                'bestAnswer' => null,
                'groups'     => array(),
                'total'      => 0,
                'hint'       => 'Try more specific keywords like "bake sale" or "volunteer sign up".',
            ) );
        }

        // Run the core scoring loop. Score_posts() applies the limit internally,
        // so capture the unlimited count by temporarily lifting the limit and
        // recording total separately.
        $max_results = apply_filters( 'ptk_max_search_results', 50 );
        $scored      = self::score_posts( $query, array( 'limit' => $max_results ) );
        $total       = count( $scored );

        // Posts table is empty / nothing scored — return empty response with
        // the same shape the front-end expects, then fall through to "did you mean".
        // Note: we fetch the post list again only if needed for the suggestions fallback.
        $posts = array();
        if ( 0 === $total ) {
            $posts = get_posts( array(
                'post_type'      => 'pta_knowledge',
                'post_status'    => 'publish',
                'posts_per_page' => 500,
            ) );

            if ( empty( $posts ) ) {
                wp_send_json_success( array(
                    'bestAnswer' => null,
                    'groups'     => array(),
                    'total'      => 0,
                ) );
            }
        }

        // Detect "Best Answer".
        $best_answer    = null;
        $best_answer_id = null;
        if ( count( $scored ) >= 2 && $scored[0]['score'] >= $scored[1]['score'] * 2 ) {
            $best_answer    = self::format_result( $scored[0]['post'], $scored[0]['score'], $scored[0]['terms'], true );
            $best_answer_id = $scored[0]['post']->ID;
        } elseif ( count( $scored ) === 1 ) {
            $best_answer    = self::format_result( $scored[0]['post'], $scored[0]['score'], $scored[0]['terms'], true );
            $best_answer_id = $scored[0]['post']->ID;
        }

        // Group by category.
        $grouped = array();
        foreach ( $scored as $entry ) {
            // Skip the best answer from regular results.
            if ( $best_answer_id && $entry['post']->ID === $best_answer_id ) {
                continue;
            }

            $cat_slugs = $entry['terms']['cat_slugs'];
            $cat       = ! empty( $cat_slugs ) ? $cat_slugs[0] : 'uncategorized';

            if ( ! isset( $grouped[ $cat ] ) ) {
                $grouped[ $cat ] = array();
            }
            $grouped[ $cat ][] = self::format_result( $entry['post'], $entry['score'], $entry['terms'] );
        }

        $response = array(
            'bestAnswer' => $best_answer,
            'groups'     => $grouped,
            'total'      => $total,
        );

        // Add "Did you mean?" suggestion on zero results.
        if ( 0 === $total ) {
            $suggestion = self::did_you_mean( $query );
            if ( $suggestion ) {
                $response['didYouMean'] = $suggestion;
            }

            // Fallback: show all entries as browseable suggestions when nothing matched.
            if ( ! empty( $posts ) ) {
                $fallback_post_ids = wp_list_pluck( array_slice( $posts, 0, 10 ), 'ID' );
                $fallback_terms    = self::batch_load_terms( $fallback_post_ids );
                $fallback          = array();
                $shown             = 0;
                foreach ( $posts as $post ) {
                    if ( $shown >= 10 ) {
                        break;
                    }
                    $pt = isset( $fallback_terms[ $post->ID ] ) ? $fallback_terms[ $post->ID ] : array(
                        'categories' => array(),
                        'cat_slugs'  => array(),
                        'tag_names'  => array(),
                    );
                    $fallback[] = self::format_result( $post, 0, $pt );
                    $shown++;
                }
                $response['suggestions'] = $fallback;
            }
        }

        // Cache for 1 hour.
        set_transient( $cache_key, $response, HOUR_IN_SECONDS );

        wp_send_json_success( $response );
    }

    /**
     * Batch-load categories and tags for all posts in 2 queries.
     */
    private static function batch_load_terms( $post_ids ) {
        $map = array();
        foreach ( $post_ids as $id ) {
            $map[ (int) $id ] = array(
                'categories' => array(),
                'cat_slugs'  => array(),
                'tag_names'  => array(),
            );
        }

        // Batch category load — query per post to guarantee correct mapping.
        foreach ( $post_ids as $pid ) {
            $pid = (int) $pid;
            $terms = wp_get_post_terms( $pid, 'knowledge_category', array( 'fields' => 'all' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $term ) {
                    $map[ $pid ]['categories'][] = $term;
                    $map[ $pid ]['cat_slugs'][]  = $term->slug;
                }
            }
        }

        // Batch tag load.
        $tag_terms = wp_get_object_terms( $post_ids, 'post_tag' );
        if ( ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $term ) {
                $oid = $term->object_id;
                if ( isset( $map[ $oid ] ) ) {
                    $map[ $oid ]['tag_names'][] = $term->name;
                }
            }
        }

        return $map;
    }

    /**
     * Check if a token matches text using word boundaries.
     */
    private static function word_match( $token, $text ) {
        $pattern = '/\b' . preg_quote( $token, '/' ) . '\b/i';
        return (bool) preg_match( $pattern, $text );
    }

    /**
     * Check if a token appears anywhere in text (substring).
     */
    private static function substr_match( $token, $text ) {
        return mb_strpos( $text, $token ) !== false;
    }

    /**
     * Generate simple stems/prefixes of a word for fuzzy matching.
     * Returns the word minus its last 1-2 characters (if word is long enough).
     * e.g., "posting" → ["postin", "posti"], "bake" → ["bak"]
     */
    private static function get_stems( $word ) {
        $stems = array();
        $len   = mb_strlen( $word );
        if ( $len >= 4 ) {
            $stems[] = mb_substr( $word, 0, $len - 1 );
        }
        if ( $len >= 5 ) {
            $stems[] = mb_substr( $word, 0, $len - 2 );
        }
        // Also add the word + common suffixes for prefix matching.
        // e.g., "bake" checks if title contains words starting with "bake".
        return $stems;
    }

    /**
     * Check if any stem of the token matches a word in the text (prefix match).
     * "post" matches "poster", "posting", "posted".
     * "bake" matches "bakers", "baking".
     */
    private static function stem_match( $token, $text ) {
        // First: does the token appear as a prefix of any word?
        $pattern = '/\b' . preg_quote( $token, '/' ) . '/i';
        if ( preg_match( $pattern, $text ) ) {
            return true;
        }
        // Second: does a stem of the token match any word?
        $stems = self::get_stems( $token );
        foreach ( $stems as $stem ) {
            $pattern = '/\b' . preg_quote( $stem, '/' ) . '/i';
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Score a single post against the query.
     *
     * Multi-layer scoring:
     * 1. Exact full-query match in title (highest)
     * 2. Word-boundary matches per token in title/tags/excerpt/content
     * 3. Substring matches (catches partial words)
     * 4. Stem/prefix matches (catches "bake" → "baking")
     * 5. Intent boost (category matches detected intent)
     */
    private static function score_post( $post, $query, $expanded_tokens, $raw_words, $intent_slugs, $post_terms, $popularity = 0 ) {
        $lower_query = mb_strtolower( $query );
        $title       = mb_strtolower( $post->post_title );
        $excerpt     = mb_strtolower( $post->post_excerpt );

        // Strip block comment markers before stripping tags.
        $raw_content = preg_replace( '/<!--.*?-->/s', ' ', $post->post_content );
        $content     = mb_strtolower( wp_strip_all_tags( $raw_content ) );
        // Collapse whitespace for cleaner matching.
        $content     = preg_replace( '/\s+/', ' ', trim( $content ) );

        // Build tag string from pre-loaded terms.
        $tags = mb_strtolower( implode( ' ', $post_terms['tag_names'] ) );

        // Combine title + excerpt + content + tags for a "full text" field.
        $full_text = $title . ' ' . $excerpt . ' ' . $tags . ' ' . $content;

        $score = 0;

        // --- Layer 1: Exact full-query match in title ---
        if ( self::substr_match( $lower_query, $title ) ) {
            $score += self::SCORE_TITLE_EXACT;
        }

        // --- Layer 2: Per-token word-boundary matches ---
        foreach ( $expanded_tokens as $token ) {
            if ( self::word_match( $token, $title ) ) {
                $score += self::SCORE_TITLE_WORD;
            }
            if ( self::word_match( $token, $tags ) ) {
                $score += self::SCORE_TAG;
            }
            if ( self::word_match( $token, $excerpt ) ) {
                $score += self::SCORE_EXCERPT;
            }
            if ( self::word_match( $token, $content ) ) {
                $score += self::SCORE_CONTENT;
            }
        }

        // --- Layer 3: Substring matches (catches partial words) ---
        // Use raw_words (includes words that were stopwords) for broader matching.
        foreach ( $raw_words as $word ) {
            if ( mb_strlen( $word ) < 3 ) {
                continue; // Skip very short words for substring matching.
            }
            if ( self::substr_match( $word, $title ) && ! self::word_match( $word, $title ) ) {
                $score += self::SCORE_TITLE_SUBSTR;
            }
            if ( self::substr_match( $word, $excerpt ) && ! self::word_match( $word, $excerpt ) ) {
                $score += self::SCORE_EXCERPT_SUBSTR;
            }
            if ( self::substr_match( $word, $content ) && ! self::word_match( $word, $content ) ) {
                $score += self::SCORE_CONTENT_SUBSTR;
            }
        }

        // --- Layer 4: Stem/prefix matching ---
        // "post" matches "posting", "bake" matches "baking"
        if ( $score === 0 ) {
            foreach ( $raw_words as $word ) {
                if ( mb_strlen( $word ) < 3 ) {
                    continue;
                }
                if ( self::stem_match( $word, $title ) ) {
                    $score += self::SCORE_STEM_TITLE;
                }
                if ( self::stem_match( $word, $content ) ) {
                    $score += self::SCORE_STEM_CONTENT;
                }
            }
        }

        // --- Layer 5: Intent boost (using pre-loaded terms) ---
        if ( ! empty( $intent_slugs ) ) {
            foreach ( $intent_slugs as $intent_slug ) {
                if ( in_array( $intent_slug, $post_terms['cat_slugs'], true ) ) {
                    $score += self::SCORE_INTENT_BOOST;
                }
            }
        }

        // --- Layer 6: Phrase matching (multi-word queries matched in sequence) ---
        if ( count( $raw_words ) >= 2 ) {
            $phrase = implode( ' ', $raw_words );
            if ( self::substr_match( $phrase, $title ) ) {
                $score += self::SCORE_PHRASE_TITLE;
            }
            if ( self::substr_match( $phrase, $content ) ) {
                $score += self::SCORE_PHRASE_CONTENT;
            }
        }

        // --- Layer 7: Fuzzy/typo matching against title words and tags ---
        if ( $score === 0 && ! empty( $raw_words ) ) {
            $title_words = preg_split( '/\s+/', $title );
            $all_tags    = ! empty( $post_terms['tag_names'] ) ? $post_terms['tag_names'] : array();

            foreach ( $raw_words as $word ) {
                if ( mb_strlen( $word ) < 3 ) {
                    continue;
                }
                // Check against title words.
                foreach ( $title_words as $tw ) {
                    $tw = preg_replace( '/[^a-z0-9]/', '', $tw );
                    if ( mb_strlen( $tw ) < 3 ) {
                        continue;
                    }
                    $dist = levenshtein( $word, $tw );
                    $threshold = mb_strlen( $word ) <= 4 ? 1 : 2;
                    if ( $dist > 0 && $dist <= $threshold ) {
                        $score += self::SCORE_FUZZY_TITLE;
                        break; // One fuzzy match per query word is enough.
                    }
                }
                // Check against tags.
                foreach ( $all_tags as $tag ) {
                    $tag_lower = mb_strtolower( $tag );
                    $dist = levenshtein( $word, $tag_lower );
                    $threshold = mb_strlen( $word ) <= 4 ? 1 : 2;
                    if ( $dist > 0 && $dist <= $threshold ) {
                        $score += self::SCORE_FUZZY_TAG;
                        break;
                    }
                }
            }
        }

        // --- Layer 8: Recency boost (recently modified entries score higher) ---
        if ( $score > 0 ) {
            $modified   = strtotime( $post->post_modified_gmt );
            $now        = time();
            $days_ago   = max( 0, ( $now - $modified ) / DAY_IN_SECONDS );
            // Full bonus within 7 days, linear decay over 90 days, 0 after 90.
            if ( $days_ago <= 7 ) {
                $score += self::SCORE_RECENCY_MAX;
            } elseif ( $days_ago < 90 ) {
                $score += (int) round( self::SCORE_RECENCY_MAX * ( 90 - $days_ago ) / 83 );
            }
        }

        // --- Layer 9: Popularity boost (entries that get clicked more rank higher) ---
        if ( $score > 0 && $popularity > 0 ) {
            // Logarithmic scale: 1 click = ~0, 10 clicks = ~half max, 100+ clicks = max.
            $pop_score = min( self::SCORE_POPULARITY_MAX, (int) round( self::SCORE_POPULARITY_MAX * log10( $popularity + 1 ) / 2 ) );
            $score += $pop_score;
        }

        return $score;
    }

    /**
     * Format a post into a JSON-friendly result object.
     */
    private static function format_result( $post, $score, $post_terms, $is_best = false ) {
        $cat_slug = ! empty( $post_terms['cat_slugs'] ) ? $post_terms['cat_slugs'][0] : 'uncategorized';
        $cat_name = ! empty( $post_terms['categories'] ) ? $post_terms['categories'][0]->name : 'Uncategorized';

        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'medium' );

        return array(
            'id'        => $post->ID,
            'title'     => $post->post_title,
            'excerpt'   => $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
            'permalink' => get_permalink( $post->ID ),
            'thumbnail' => $thumbnail ? $thumbnail : null,
            'category'  => $cat_slug,
            'catName'   => $cat_name,
            'tags'      => $post_terms['tag_names'],
            'score'     => $score,
            'isBest'    => $is_best,
        );
    }

    /**
     * Tokenize a query string, removing only minimal stopwords.
     */
    private static function tokenize( $query ) {
        $words = preg_split( '/\s+/', mb_strtolower( trim( $query ) ) );
        return array_values( array_filter( $words, function( $w ) {
            return mb_strlen( $w ) > 1 && ! in_array( $w, self::$stopwords, true );
        } ) );
    }

    /**
     * Tokenize without removing any stopwords (for substring/stem matching).
     * Only removes single-character words.
     */
    private static function tokenize_raw( $query ) {
        $words = preg_split( '/\s+/', mb_strtolower( trim( $query ) ) );
        return array_values( array_filter( $words, function( $w ) {
            return mb_strlen( $w ) > 1;
        } ) );
    }

    /**
     * Load synonyms from file, cached in a static variable.
     */
    private static function load_synonyms() {
        if ( null !== self::$synonyms_cache ) {
            return self::$synonyms_cache;
        }

        $synonyms_file = PTK_PLUGIN_DIR . 'data/synonyms.json';
        if ( ! file_exists( $synonyms_file ) || ! is_readable( $synonyms_file ) ) {
            self::$synonyms_cache = array();
            return self::$synonyms_cache;
        }

        $raw = file_get_contents( $synonyms_file );
        if ( false === $raw ) {
            self::$synonyms_cache = array();
            return self::$synonyms_cache;
        }

        $decoded = json_decode( $raw, true );
        self::$synonyms_cache = is_array( $decoded ) ? $decoded : array();
        return self::$synonyms_cache;
    }

    /**
     * Expand tokens using the synonyms.json file.
     */
    private static function expand_synonyms( $tokens, $query ) {
        $synonyms = self::load_synonyms();
        if ( empty( $synonyms ) ) {
            return $tokens;
        }

        $lower_query = mb_strtolower( $query );
        $expanded    = array_flip( $tokens ); // Use keys for uniqueness.

        foreach ( $synonyms as $canonical => $aliases ) {
            $all_forms = array_merge( array( $canonical ), $aliases );
            $matched   = false;

            foreach ( $all_forms as $form ) {
                if ( strpos( $lower_query, $form ) !== false ) {
                    $matched = true;
                    break;
                }
                foreach ( $tokens as $token ) {
                    if ( strpos( $form, $token ) !== false || strpos( $token, $form ) !== false ) {
                        $matched = true;
                        break 2;
                    }
                }
            }

            if ( $matched ) {
                $expanded[ $canonical ] = true;
                foreach ( $aliases as $alias ) {
                    $expanded[ $alias ] = true;
                }
            }
        }

        return array_keys( $expanded );
    }

    /**
     * Detect user intent from query keywords.
     * Returns an array of knowledge_category slugs that should be boosted.
     */
    private static function detect_intent( $query ) {
        $lower = mb_strtolower( $query );
        $slugs = array();

        foreach ( self::$intent_map as $slug => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( strpos( $lower, $kw ) !== false ) {
                    $slugs[] = $slug;
                    break;
                }
            }
        }

        return $slugs;
    }

    /**
     * Get popular tags for suggested searches.
     */
    public static function get_suggested_searches( $count = 8 ) {
        $tags = get_terms( array(
            'taxonomy'   => 'post_tag',
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => $count,
            'hide_empty' => true,
            'object_ids' => get_posts( array(
                'post_type'      => 'pta_knowledge',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ) ),
        ) );

        if ( is_wp_error( $tags ) || empty( $tags ) ) {
            // Fallback suggested searches.
            return array( 'bake sale', 'volunteer', 'fundraiser', 'meeting', 'field trip', 'budget', 'safety', 'sign up' );
        }

        return wp_list_pluck( $tags, 'name' );
    }

    /**
     * Get all synonym keys for "Did you mean?" suggestions.
     */
    public static function get_synonym_keys() {
        $synonyms = self::load_synonyms();
        $keys     = array_keys( $synonyms );
        foreach ( $synonyms as $aliases ) {
            $keys = array_merge( $keys, $aliases );
        }
        return array_unique( $keys );
    }

    /**
     * Find the closest synonym key to the query using Levenshtein distance.
     * Returns a suggestion string or null if nothing is close enough.
     */
    private static function did_you_mean( $query ) {
        $lower_query = mb_strtolower( trim( $query ) );
        $keys        = self::get_synonym_keys();
        $best_match  = null;
        $best_dist   = PHP_INT_MAX;

        foreach ( $keys as $key ) {
            $dist = levenshtein( $lower_query, $key );
            // Only suggest if within 3 edits and the match is reasonably close.
            if ( $dist < $best_dist && $dist <= 3 && $dist < mb_strlen( $lower_query ) ) {
                $best_dist  = $dist;
                $best_match = $key;
            }
        }

        // Don't suggest the exact same thing.
        if ( $best_match && $best_match !== $lower_query ) {
            return $best_match;
        }

        return null;
    }

    /* ------------------------------------------------------------------
     * Autocomplete — lightweight endpoint returning matching titles
     * ----------------------------------------------------------------*/

    /**
     * AJAX handler: returns up to 6 matching titles for autocomplete dropdown.
     */
    public static function handle_autocomplete() {
        check_ajax_referer( 'ptk_search_nonce', '_wpnonce' );

        $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        if ( mb_strlen( trim( $query ) ) < 2 ) {
            wp_send_json_success( array() );
        }

        $lower_query = mb_strtolower( trim( $query ) );

        // Check transient cache.
        $cache_key = 'ptk_ac_' . md5( $lower_query );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
        }

        $posts = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
        ) );

        $matches = array();
        foreach ( $posts as $post ) {
            $title_lower = mb_strtolower( $post->post_title );
            $score = 0;

            // Exact substring in title.
            if ( mb_strpos( $title_lower, $lower_query ) !== false ) {
                $score = 100;
                // Bonus if it starts with the query.
                if ( mb_strpos( $title_lower, $lower_query ) === 0 ) {
                    $score = 200;
                }
            } else {
                // Word-level matching.
                $words = preg_split( '/\s+/', $lower_query );
                foreach ( $words as $w ) {
                    if ( mb_strlen( $w ) >= 2 && mb_strpos( $title_lower, $w ) !== false ) {
                        $score += 10;
                    }
                }
            }

            if ( $score > 0 ) {
                $cats     = wp_get_post_terms( $post->ID, 'knowledge_category', array( 'fields' => 'all' ) );
                $cat_slug = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? $cats[0]->slug : '';
                $cat_name = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? $cats[0]->name : '';

                $matches[] = array(
                    'id'        => $post->ID,
                    'title'     => $post->post_title,
                    'permalink' => get_permalink( $post->ID ),
                    'category'  => $cat_slug,
                    'catName'   => $cat_name,
                    'score'     => $score,
                );
            }
        }

        // Sort by score descending.
        usort( $matches, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        $results = array_slice( $matches, 0, 6 );

        // Cache for 30 minutes.
        set_transient( $cache_key, $results, 30 * MINUTE_IN_SECONDS );

        wp_send_json_success( $results );
    }

    /* ------------------------------------------------------------------
     * Click Tracking — for popularity-weighted ranking
     * ----------------------------------------------------------------*/

    /**
     * Create the click tracking table on plugin activation.
     */
    public static function create_click_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'ptk_click_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            query VARCHAR(255) NOT NULL DEFAULT '',
            clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_clicked_at (clicked_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Drop the click table on uninstall.
     */
    public static function drop_click_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptk_click_log';
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * AJAX handler: log a click on a search result.
     */
    public static function handle_track_click() {
        check_ajax_referer( 'ptk_search_nonce', '_wpnonce' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $query   = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( ! $post_id ) {
            wp_send_json_error();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ptk_click_log';

        $wpdb->insert(
            $table,
            array(
                'post_id'    => $post_id,
                'query'      => mb_substr( $query, 0, 255 ),
                'clicked_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s' )
        );

        wp_send_json_success();
    }

    /**
     * Get click counts per post (last 90 days) as a map: post_id => count.
     *
     * @param int[] $post_ids Array of post IDs to look up.
     * @return int[] Associative array of post_id => click_count.
     */
    private static function get_popularity_map( $post_ids ) {
        if ( empty( $post_ids ) ) {
            return array();
        }

        // Check transient cache.
        $cache_key = 'ptk_popularity_map';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ptk_click_log';

        // Check if table exists (graceful fallback if not yet created).
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ) );

        if ( ! $table_exists ) {
            return array();
        }

        $results = $wpdb->get_results(
            "SELECT post_id, COUNT(*) AS click_count
             FROM {$table}
             WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY post_id",
            OBJECT
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $map = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $map[ (int) $row->post_id ] = (int) $row->click_count;
            }
        }

        // Cache for 1 hour.
        set_transient( $cache_key, $map, HOUR_IN_SECONDS );

        return $map;
    }
}
