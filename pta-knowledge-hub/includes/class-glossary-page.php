<?php
/**
 * Glossary Page — front-end A–Z listing of all glossary terms.
 *
 * Renders a filterable alphabetical glossary via the [pta_glossary] shortcode.
 * Each term links to its full knowledge entry and shows the plain-English
 * definition inline.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Glossary_Page {

    public static function init() {
        add_shortcode( 'pta_glossary', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Enqueue glossary page styles only when the shortcode is present.
     */
    public static function enqueue_assets() {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'pta_glossary' ) ) {
            wp_enqueue_style(
                'ptk-glossary-page',
                PTK_PLUGIN_URL . 'assets/css/glossary-page.css',
                array(),
                PTK_VERSION
            );
        }
    }

    /**
     * Render the glossary shortcode.
     */
    public static function render( $atts ) {
        // Access check.
        if ( ! ptk_check_access( true ) ) {
            return '';
        }

        $terms = self::get_glossary_terms();

        if ( empty( $terms ) ) {
            return self::render_empty();
        }

        // Group terms by first letter.
        $grouped = array();
        foreach ( $terms as $term ) {
            $letter = strtoupper( mb_substr( $term['title'], 0, 1 ) );
            if ( is_numeric( $letter ) ) {
                $letter = '#';
            }
            $grouped[ $letter ][] = $term;
        }
        ksort( $grouped );

        // Build available letters for the A–Z nav.
        $all_letters = array_merge( array( '#' ), range( 'A', 'Z' ) );
        $active_letters = array_keys( $grouped );

        ob_start();
        ?>
        <div class="ptk-glossary-wrap">
            <div class="ptk-glossary-hero">
                <h1 class="ptk-glossary-title">Glossary</h1>
                <p class="ptk-glossary-subtitle">Plain-English definitions for PTA terms, tools, and acronyms.</p>
                <div class="ptk-glossary-search-wrap">
                    <input type="text" class="ptk-glossary-search" id="ptk-glossary-search"
                           placeholder="Search terms..." autocomplete="off">
                    <span class="ptk-glossary-search-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                </div>
            </div>

            <!-- A–Z Navigation -->
            <nav class="ptk-glossary-az" aria-label="Alphabetical navigation">
                <?php foreach ( $all_letters as $letter ) :
                    $is_active = in_array( $letter, $active_letters, true );
                ?>
                    <?php if ( $is_active ) : ?>
                        <a href="#glossary-<?php echo esc_attr( $letter ); ?>" class="ptk-az-letter ptk-az-active"><?php echo esc_html( $letter ); ?></a>
                    <?php else : ?>
                        <span class="ptk-az-letter ptk-az-disabled"><?php echo esc_html( $letter ); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <!-- Term count -->
            <p class="ptk-glossary-count" id="ptk-glossary-count"><?php echo count( $terms ); ?> terms</p>

            <!-- Term listings grouped by letter -->
            <div class="ptk-glossary-list" id="ptk-glossary-list">
                <?php foreach ( $grouped as $letter => $letter_terms ) : ?>
                    <div class="ptk-glossary-group" id="glossary-<?php echo esc_attr( $letter ); ?>">
                        <h2 class="ptk-glossary-letter"><?php echo esc_html( $letter ); ?></h2>
                        <?php foreach ( $letter_terms as $term ) : ?>
                            <div class="ptk-glossary-entry" data-term="<?php echo esc_attr( strtolower( $term['title'] ) ); ?>">
                                <div class="ptk-glossary-entry-header">
                                    <a href="<?php echo esc_url( $term['url'] ); ?>" class="ptk-glossary-entry-title">
                                        <?php echo esc_html( $term['title'] ); ?>
                                    </a>
                                </div>
                                <p class="ptk-glossary-entry-definition">
                                    <?php echo esc_html( $term['definition'] ); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <!-- No results message (hidden by default) -->
                <div class="ptk-glossary-no-results" id="ptk-glossary-no-results" style="display:none;">
                    <div class="ptk-glossary-empty-icon">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                    </div>
                    <p>No terms match your search.</p>
                </div>
            </div>

            <!-- Back to knowledge base link -->
            <div class="ptk-glossary-footer">
                <a href="<?php echo esc_url( home_url( '/knowledge-base' ) ); ?>" class="ptk-glossary-back-link">
                    &larr; Back to PTA Hub
                </a>
            </div>
        </div>

        <script>
        (function() {
            var search = document.getElementById('ptk-glossary-search');
            var list = document.getElementById('ptk-glossary-list');
            var count = document.getElementById('ptk-glossary-count');
            var noResults = document.getElementById('ptk-glossary-no-results');
            if (!search || !list) return;

            var entries = list.querySelectorAll('.ptk-glossary-entry');
            var groups = list.querySelectorAll('.ptk-glossary-group');

            search.addEventListener('input', function() {
                var query = this.value.toLowerCase().trim();
                var visible = 0;

                entries.forEach(function(entry) {
                    var term = entry.getAttribute('data-term') || '';
                    var def = (entry.querySelector('.ptk-glossary-entry-definition') || {}).textContent || '';
                    var matches = !query || term.indexOf(query) !== -1 || def.toLowerCase().indexOf(query) !== -1;
                    entry.style.display = matches ? '' : 'none';
                    if (matches) visible++;
                });

                // Show/hide letter groups that have no visible entries.
                groups.forEach(function(group) {
                    var visibleEntries = group.querySelectorAll('.ptk-glossary-entry:not([style*="display: none"])');
                    group.style.display = visibleEntries.length > 0 ? '' : 'none';
                });

                count.textContent = visible + ' term' + (visible !== 1 ? 's' : '') + (query ? ' matching "' + query + '"' : '');
                noResults.style.display = visible === 0 ? '' : 'none';
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render an empty state when no glossary terms exist.
     */
    private static function render_empty() {
        ob_start();
        ?>
        <div class="ptk-glossary-wrap">
            <div class="ptk-glossary-hero">
                <h1 class="ptk-glossary-title">Glossary</h1>
                <p class="ptk-glossary-subtitle">Plain-English definitions for PTA terms, tools, and acronyms.</p>
            </div>
            <div class="ptk-glossary-empty">
                <div class="ptk-glossary-empty-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </div>
                <h2>Glossary Coming Soon</h2>
                <p>We're still building this glossary. In the meantime, try the search bar on the PTA Hub for answers.</p>
                <a href="<?php echo esc_url( ptk_hub_url() ); ?>" class="ptk-glossary-back-link">&larr; Back to PTA Hub</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get all published glossary terms with definitions.
     */
    private static function get_glossary_terms() {
        // Reuse the same transient cache as the tooltip system.
        $cached = get_transient( 'ptk_glossary_terms' );
        if ( false !== $cached ) {
            return $cached;
        }

        $glossary_term = get_term_by( 'slug', 'glossary', 'knowledge_category' );
        if ( ! $glossary_term ) {
            return array();
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
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $terms = array();
        foreach ( $posts as $p ) {
            $definition = $p->post_excerpt;
            if ( empty( $definition ) ) {
                $stripped = wp_strip_all_tags( $p->post_content );
                $definition = wp_trim_words( $stripped, 30, '...' );
            }
            if ( empty( $definition ) ) {
                continue;
            }

            $terms[] = array(
                'title'      => $p->post_title,
                'definition' => $definition,
                'url'        => get_permalink( $p->ID ),
            );
        }

        // Sort alphabetically by title.
        usort( $terms, function( $a, $b ) {
            return strcasecmp( $a['title'], $b['title'] );
        });

        // Cache for 1 hour (same as tooltip system).
        set_transient( 'ptk_glossary_terms', $terms, HOUR_IN_SECONDS );

        return $terms;
    }
}
