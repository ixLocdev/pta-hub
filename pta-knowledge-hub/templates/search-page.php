<?php
/**
 * Template: the Hub families see -- the search page, rendered by [pta_search].
 *
 * Variables available:
 *   $suggested  -- array of suggested search terms
 *   $categories -- array of WP_Term objects for knowledge_category
 *
 * The words on this page are family-facing: no "entry", "post", "category",
 * "FAQ" or "playbook". PTK_Shortcode::category_label() turns a category's
 * stored slug into what a family should read; a category we don't know keeps
 * its own name.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$ptk_total_published = (int) wp_count_posts( 'pta_knowledge' )->publish;
?>
<div class="ptk-search-wrap" id="ptk-search-app">

<?php if ( 0 === $ptk_total_published ) : ?>
    <!-- Nothing written yet -->
    <div class="ptk-hero">
        <h2 class="ptk-hero-title">What do you need to know?</h2>
        <p class="ptk-hero-subtitle">Everything the PTA has written down, in one place.</p>
    </div>
    <div class="ptk-empty-install">
        <svg class="ptk-empty-install-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 17l9 4 9-4"/><path d="M3 12l9 4 9-4"/>
        </svg>
        <h3 class="ptk-empty-install-title">Nothing here yet</h3>
        <p class="ptk-empty-install-text">Your PTA is still writing this. Check back soon &mdash; answers, how-to guides and forms all end up here.</p>
        <?php if ( current_user_can( 'edit_posts' ) ) : ?>
            <a class="ptk-empty-install-cta" href="<?php echo esc_url( PTK_Content_Wizard::url() ); ?>">Write the first one</a>
        <?php endif; ?>
    </div>
<?php else : ?>

    <!-- The question, and the search box -->
    <div class="ptk-hero">
        <h2 class="ptk-hero-title">What do you need to know?</h2>
        <p class="ptk-hero-subtitle">Everything the PTA has written down &mdash; sign-ups, events, volunteering, where the money goes. Type a few words, or browse below.</p>
        <div class="ptk-search-box">
            <svg class="ptk-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                type="text"
                id="ptk-search-input"
                class="ptk-search-input"
                placeholder="Try &ldquo;spirit week&rdquo; or &ldquo;how do I volunteer&rdquo;"
                autocomplete="off"
                aria-label="Search everything the PTA has written down"
            />
            <button id="ptk-search-clear" class="ptk-search-clear" aria-label="Clear what you typed" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Or pick a kind of thing -->
        <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
        <p class="ptk-suggested-label" style="margin-top:22px;">Or pick a kind of thing:</p>
        <div class="ptk-category-filters" id="ptk-category-filters" role="group" aria-label="Pick a kind of thing">
            <button class="ptk-filter-btn ptk-filter-active" data-category="all" aria-pressed="true">Everything</button>
            <?php foreach ( $categories as $cat ) : ?>
                <button class="ptk-filter-btn" data-category="<?php echo esc_attr( $cat->slug ); ?>" aria-pressed="false">
                    <?php echo esc_html( PTK_Shortcode::category_label( $cat->slug, $cat->name ) ); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Things families ask about (shown when no query) -->
    <div class="ptk-suggested" id="ptk-suggested">
        <p class="ptk-suggested-label">Things families ask about</p>
        <div class="ptk-suggested-tags">
            <?php foreach ( $suggested as $term ) : ?>
                <button class="ptk-suggested-tag" data-query="<?php echo esc_attr( $term ); ?>">
                    <?php echo esc_html( $term ); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Just added -->
    <?php
    $recent_posts = get_posts( array(
        'post_type'      => 'pta_knowledge',
        'post_status'    => 'publish',
        'posts_per_page' => 4,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    ?>
    <?php if ( ! empty( $recent_posts ) ) : ?>
    <div class="ptk-recent-section" id="ptk-recent-section">
        <p class="ptk-recent-label">Just added</p>
        <div class="ptk-recent-grid">
            <?php
            foreach ( $recent_posts as $rp ) :
                $rp_slugs = wp_get_post_terms( $rp->ID, 'knowledge_category', array( 'fields' => 'slugs' ) );
                $rp_names = wp_get_post_terms( $rp->ID, 'knowledge_category', array( 'fields' => 'names' ) );
                $rp_slug  = ( ! is_wp_error( $rp_slugs ) && ! empty( $rp_slugs ) ) ? $rp_slugs[0] : '';
                $rp_name  = ( ! is_wp_error( $rp_names ) && ! empty( $rp_names ) ) ? $rp_names[0] : '';
                $rp_label = $rp_slug ? PTK_Shortcode::category_label( $rp_slug, $rp_name ) : '';
                $rp_excerpt = $rp->post_excerpt ? $rp->post_excerpt : wp_trim_words( wp_strip_all_tags( $rp->post_content ), 15 );
            ?>
                <a class="ptk-card" href="<?php echo esc_url( get_permalink( $rp->ID ) ); ?>">
                    <?php if ( $rp_label ) : ?>
                        <div class="ptk-card-badge-wrap"><span class="ptk-card-badge"><?php echo esc_html( $rp_label ); ?></span></div>
                    <?php endif; ?>
                    <div class="ptk-card-body">
                        <h3 class="ptk-card-title"><?php echo esc_html( $rp->post_title ); ?></h3>
                        <p class="ptk-card-excerpt"><?php echo esc_html( $rp_excerpt ); ?></p>
                        <span class="ptk-card-link"><?php echo esc_html( PTK_Shortcode::card_link_text( $rp_slug ) ); ?> &rarr;</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Waiting -->
    <div class="ptk-loading" id="ptk-loading" style="display:none;" role="status" aria-live="polite">
        <div class="ptk-spinner" aria-hidden="true"></div>
        <p>Looking&hellip;</p>
    </div>

    <!-- Nothing found -->
    <div class="ptk-empty" id="ptk-empty" style="display:none;" aria-live="polite">
        <svg class="ptk-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <line x1="8" y1="11" x2="14" y2="11"/>
        </svg>
        <p class="ptk-empty-title">We haven&rsquo;t written that one yet</p>
        <p class="ptk-empty-text" id="ptk-empty-text">Nothing here matches what you typed. Try fewer words, or a different one.</p>
        <p class="ptk-hint" id="ptk-hint" style="display:none;"></p>
        <p class="ptk-did-you-mean" id="ptk-did-you-mean" style="display:none;"></p>
        <div class="ptk-ask" id="ptk-ask">
            <button type="button" class="ptk-action" id="ptk-ask-btn">Ask us to write this</button>
        </div>
        <p class="ptk-ask-done" id="ptk-ask-done" style="display:none;" role="status"></p>
    </div>

    <!-- What we found -->
    <div class="ptk-results" id="ptk-results" style="display:none;" aria-live="polite">

        <!-- The best one -->
        <div class="ptk-best-answer" id="ptk-best-answer" style="display:none;"></div>

        <!-- How many others -->
        <p class="ptk-result-count" id="ptk-result-count" role="status"></p>

        <!-- The rest, grouped -->
        <div class="ptk-groups" id="ptk-groups"></div>
    </div>

    <!-- Search itself broke -->
    <div class="ptk-error" id="ptk-error" style="display:none;" aria-live="assertive">
        <svg class="ptk-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p class="ptk-empty-title">Search isn&rsquo;t working right now</p>
        <p class="ptk-empty-text">This is usually a brief connection hiccup &mdash; it&rsquo;s nothing you did.</p>
        <button type="button" class="ptk-retry-btn" id="ptk-retry">Try again</button>
        <p class="ptk-empty-text ptk-error-fallback">Still stuck? Refresh the page, or scroll up to browse what was just added.</p>
    </div>

<?php endif; ?>
</div>
