<?php
/**
 * Reading-time estimate and auto-generated Table of Contents for
 * single pta_knowledge entries.
 *
 * The TOC is built once at save time (regex-based, looking for H2s)
 * and cached in post meta `ptk_toc_html`. On the front-end a
 * `the_content` filter at priority 20 (after wpautop) injects matching
 * `id="<slug>"` attributes into each <h2> tag and prepends the cached
 * TOC. We deliberately do not mutate post_content.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Single_Enhancements {

    public static function init() {
        add_action( 'save_post_pta_knowledge', array( __CLASS__, 'regenerate_toc' ), 20, 1 );
        add_filter( 'the_content', array( __CLASS__, 'inject_toc_and_ids' ), 20 );
    }

    /**
     * Estimated reading time in minutes (rounded up, never below 1).
     */
    public static function reading_time( $post_id ) {
        $words = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
        return (int) max( 1, ceil( $words / 225 ) );
    }

    /**
     * Build / refresh the cached TOC HTML for a post.
     */
    public static function regenerate_toc( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $content = get_post_field( 'post_content', $post_id );

        if ( ! preg_match_all( '/<h2\b[^>]*>(.*?)<\/h2>/is', $content, $matches ) ) {
            delete_post_meta( $post_id, 'ptk_toc_html' );
            return;
        }

        $headings = $matches[1];
        if ( count( $headings ) < 3 ) {
            delete_post_meta( $post_id, 'ptk_toc_html' );
            return;
        }

        $items = '';
        $seen  = array();
        foreach ( $headings as $raw ) {
            $text = trim( wp_strip_all_tags( $raw ) );
            if ( '' === $text ) {
                continue;
            }
            $slug = sanitize_title( $text );
            if ( '' === $slug ) {
                continue;
            }
            // Disambiguate duplicates.
            $base = $slug;
            $i    = 2;
            while ( isset( $seen[ $slug ] ) ) {
                $slug = $base . '-' . $i;
                $i++;
            }
            $seen[ $slug ] = true;

            $items .= '<li><a href="#' . esc_attr( $slug ) . '">' . esc_html( $text ) . '</a></li>';
        }

        if ( '' === $items ) {
            delete_post_meta( $post_id, 'ptk_toc_html' );
            return;
        }

        $toc  = '<nav class="ptk-toc" aria-label="Table of contents">';
        $toc .= '<details open><summary>On this page</summary>';
        $toc .= '<ol>' . $items . '</ol>';
        $toc .= '</details></nav>';

        update_post_meta( $post_id, 'ptk_toc_html', $toc );
    }

    /**
     * Inject H2 ids into the rendered content and prepend the cached TOC.
     *
     * Runs at priority 20 so wpautop and the block renderer have already
     * processed the content. Operates on the filtered string only — never
     * touches post_content.
     */
    public static function inject_toc_and_ids( $content ) {
        if ( ! is_singular( 'pta_knowledge' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return $content;
        }

        $toc = get_post_meta( $post_id, 'ptk_toc_html', true );
        if ( empty( $toc ) ) {
            return $content;
        }

        // Inject ids into <h2> tags. We track slugs to mirror what regenerate_toc()
        // produced; same disambiguation logic ensures the anchors line up.
        $seen    = array();
        $content = preg_replace_callback(
            '/<h2\b([^>]*)>(.*?)<\/h2>/is',
            function ( $m ) use ( &$seen ) {
                $attrs = $m[1];
                $inner = $m[2];

                // If an id already exists, leave the tag alone.
                if ( preg_match( '/\bid\s*=/i', $attrs ) ) {
                    return $m[0];
                }

                $text = trim( wp_strip_all_tags( $inner ) );
                if ( '' === $text ) {
                    return $m[0];
                }

                $slug = sanitize_title( $text );
                if ( '' === $slug ) {
                    return $m[0];
                }

                $base = $slug;
                $i    = 2;
                while ( isset( $seen[ $slug ] ) ) {
                    $slug = $base . '-' . $i;
                    $i++;
                }
                $seen[ $slug ] = true;

                return '<h2' . $attrs . ' id="' . esc_attr( $slug ) . '">' . $inner . '</h2>';
            },
            $content
        );

        return $toc . $content;
    }
}
