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
     *
     * public.css first -- the shared tokens and fonts. glossary-page.css
     * declares no color of its own.
     */
    public static function enqueue_assets() {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'pta_glossary' ) ) {
            wp_enqueue_style(
                'ptk-public',
                PTK_PLUGIN_URL . 'assets/css/public.css',
                array(),
                PTK_VERSION
            );
            wp_enqueue_style(
                'ptk-glossary-page',
                PTK_PLUGIN_URL . 'assets/css/glossary-page.css',
                array( 'ptk-public' ),
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
            if ( ! preg_match( '/^[A-Z]$/', $letter ) ) {
                $letter = '#';
            }
            $grouped[ $letter ][] = $term;
        }
        // Letters in order, and the words that start with a number or a
        // symbol last -- where the # sits in the A-Z strip.
        uksort( $grouped, function( $a, $b ) {
            if ( '#' === $a || '#' === $b ) {
                return ( '#' === $a ) - ( '#' === $b );
            }
            return strcmp( $a, $b );
        });

        $active_letters = array_keys( $grouped );
        $count          = count( $terms );

        ob_start();
        ?>
        <div class="ptk-glossary-wrap" id="ptk-glossary"
             data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'ptk_submit_suggestion' ) ); ?>">

            <?php echo self::render_hero(); ?>

            <div class="ptk-glossary-search-box">
                <svg class="ptk-glossary-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <label class="screen-reader-text" for="ptk-glossary-search">Look up a word</label>
                <input type="search" class="ptk-glossary-search" id="ptk-glossary-search"
                       placeholder="Look up a word" autocomplete="off">
            </div>

            <nav class="ptk-glossary-az" aria-label="Jump to a letter">
                <?php echo self::render_letters( $active_letters ); ?>
            </nav>

            <p class="ptk-glossary-count" id="ptk-glossary-count" role="status"><?php echo esc_html( self::count_words( $count ) ); ?></p>

            <div class="ptk-glossary-list" id="ptk-glossary-list">
                <?php foreach ( $grouped as $letter => $letter_terms ) : ?>
                    <section class="ptk-glossary-group" id="glossary-<?php echo esc_attr( $letter ); ?>">
                        <h3 class="ptk-glossary-letter"><?php echo esc_html( $letter ); ?></h3>
                        <dl class="ptk-glossary-entries">
                            <?php foreach ( $letter_terms as $term ) {
                                echo self::render_term( $term );
                            } ?>
                        </dl>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="ptk-glossary-none" id="ptk-glossary-none" hidden>
                <h3 class="ptk-glossary-none-title">We haven't explained that one yet</h3>
                <p class="ptk-glossary-none-text">Ask, and we'll add it so the next person doesn't have to wonder.</p>
                <div id="ptk-glossary-ask">
                    <button type="button" class="ptk-action" id="ptk-glossary-ask-btn">Ask us to explain it</button>
                </div>
                <p class="ptk-glossary-ask-done" id="ptk-glossary-ask-done" role="status" hidden></p>
            </div>

            <p class="ptk-glossary-footer">
                <a href="<?php echo esc_url( ptk_hub_url() ); ?>" class="ptk-quiet">Back to the Hub</a>
            </p>
        </div>

        <script>
        (function () {
            var wrap = document.getElementById('ptk-glossary');
            var search = document.getElementById('ptk-glossary-search');
            var list = document.getElementById('ptk-glossary-list');
            if (!wrap || !search || !list) return;

            var count = document.getElementById('ptk-glossary-count');
            var none = document.getElementById('ptk-glossary-none');
            var az = wrap.querySelector('.ptk-glossary-az');
            var askBtn = document.getElementById('ptk-glossary-ask-btn');
            var askWrap = document.getElementById('ptk-glossary-ask');
            var askDone = document.getElementById('ptk-glossary-ask-done');
            var entries = list.querySelectorAll('.ptk-glossary-entry');
            var groups = list.querySelectorAll('.ptk-glossary-group');
            var total = entries.length;

            function words(n) {
                return n + (n === 1 ? ' word' : ' words');
            }

            search.addEventListener('input', function () {
                var query = this.value.toLowerCase().trim();
                var visible = 0;

                entries.forEach(function (entry) {
                    var text = entry.getAttribute('data-term') + ' ' + entry.textContent.toLowerCase();
                    var matches = !query || text.indexOf(query) !== -1;
                    entry.hidden = !matches;
                    if (matches) visible++;
                });

                groups.forEach(function (group) {
                    group.hidden = !group.querySelector('.ptk-glossary-entry:not([hidden])');
                });

                if (az) az.hidden = !!query;
                count.textContent = query
                    ? (visible ? words(visible) + ' with “' + this.value.trim() + '”' : '')
                    : words(total);
                none.hidden = visible !== 0;

                // A new search gets a fresh offer.
                if (askWrap) askWrap.hidden = false;
                if (askDone) askDone.hidden = true;
                if (askBtn) {
                    askBtn.disabled = false;
                    askBtn.textContent = 'Ask us to explain it';
                }
            });

            if (askBtn) {
                askBtn.addEventListener('click', function () {
                    var asked = search.value.trim();
                    if (!asked) return;

                    askBtn.disabled = true;
                    askBtn.textContent = 'Sending…';

                    var body = new URLSearchParams();
                    body.append('action', 'ptk_submit_suggestion');
                    body.append('_wpnonce', wrap.getAttribute('data-nonce') || '');
                    body.append('suggestion_title', asked);
                    body.append('suggestion_body', 'Someone looked this word up in the glossary and found nothing.');

                    fetch(wrap.getAttribute('data-ajax'), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            askWrap.hidden = true;
                            askDone.textContent = 'Thanks — we’ll add ' + asked + ' to the list.';
                            askDone.className = 'ptk-glossary-ask-done';
                        } else {
                            failed(res && res.data && res.data.message);
                        }
                        askDone.hidden = false;
                    })
                    .catch(function () {
                        failed(null);
                        askDone.hidden = false;
                    });
                });
            }

            function failed(message) {
                askBtn.disabled = false;
                askBtn.textContent = 'Ask us to explain it';
                askDone.textContent = message || 'That didn’t send. Please try again in a moment.';
                askDone.className = 'ptk-glossary-ask-done ptk-glossary-ask-failed';
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * The question at the top. Shared by the full page and the empty one.
     */
    private static function render_hero() {
        return '<div class="ptk-glossary-hero">'
            . '<h2 class="ptk-glossary-title">What does that mean?</h2>'
            . '<p class="ptk-glossary-subtitle">' . PTK_Hub_UI::no_widow( 'The words, short names and tools you\'ll hear around the PTA, explained in plain English.' ) . '</p>'
            . '</div>';
    }

    /**
     * The A-Z strip. A letter with nothing under it is shown but not a link.
     */
    private static function render_letters( $active_letters ) {
        $html = '';
        foreach ( array_merge( range( 'A', 'Z' ), array( '#' ) ) as $letter ) {
            if ( in_array( $letter, $active_letters, true ) ) {
                $html .= '<a href="#glossary-' . esc_attr( $letter ) . '" class="ptk-az-letter ptk-az-active">' . esc_html( $letter ) . '</a>';
            } elseif ( '#' !== $letter ) {
                $html .= '<span class="ptk-az-letter ptk-az-disabled" aria-hidden="true">' . esc_html( $letter ) . '</span>';
            }
        }
        return $html;
    }

    /**
     * One word and what it means.
     */
    private static function render_term( $term ) {
        return '<div class="ptk-glossary-entry" data-term="' . esc_attr( strtolower( $term['title'] ) ) . '">'
            . '<dt><a href="' . esc_url( $term['url'] ) . '" class="ptk-glossary-entry-title">' . esc_html( $term['title'] ) . '</a></dt>'
            . '<dd class="ptk-glossary-entry-definition">' . esc_html( $term['definition'] ) . '</dd>'
            . '</div>';
    }

    private static function count_words( $n ) {
        return $n . ( 1 === $n ? ' word' : ' words' );
    }

    /**
     * Render an empty state when no glossary terms exist.
     */
    private static function render_empty() {
        return '<div class="ptk-glossary-wrap">'
            . self::render_hero()
            . '<div class="ptk-glossary-none">'
            . '<h3 class="ptk-glossary-none-title">Nothing explained here yet</h3>'
            . '<p class="ptk-glossary-none-text">' . PTK_Hub_UI::no_widow( 'The PTA hasn\'t written up any words yet. The Hub may already have what you\'re looking for.' ) . '</p>'
            . '<a href="' . esc_url( ptk_hub_url() ) . '" class="ptk-action">Search the Hub</a>'
            . '</div>'
            . '</div>';
    }

    /**
     * Get all published glossary terms with definitions.
     */
    private static function get_glossary_terms() {
        // Reuse the same transient cache as the tooltip system.
        // The tooltips fill this same cache without sorting it, so sort on
        // the way out, not only on a cache miss.
        $cached = get_transient( 'ptk_glossary_terms' );
        if ( false !== $cached ) {
            return self::sorted( (array) $cached );
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

        // Cache for 1 hour (same as tooltip system).
        set_transient( 'ptk_glossary_terms', $terms, HOUR_IN_SECONDS );

        return self::sorted( $terms );
    }

    /**
     * Alphabetical by title, ignoring case.
     */
    private static function sorted( $terms ) {
        usort( $terms, function( $a, $b ) {
            return strcasecmp( $a['title'], $b['title'] );
        });
        return $terms;
    }
}
