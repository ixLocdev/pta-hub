<?php
/**
 * Template: Search page rendered by [pta_search] shortcode.
 *
 * Variables available:
 *   $suggested  — array of suggested search terms
 *   $categories — array of WP_Term objects for knowledge_category
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<?php
$ptk_total_published = (int) wp_count_posts( 'pta_knowledge' )->publish;
?>
<div class="ptk-search-wrap" id="ptk-search-app">

<?php if ( 0 === $ptk_total_published ) : ?>
    <!-- Fresh-install empty state -->
    <div class="ptk-hero">
        <h2 class="ptk-hero-title">PTA Hub</h2>
        <p class="ptk-hero-subtitle">Quick answers to your PTA questions.</p>
    </div>
    <div class="ptk-empty-install">
        <svg class="ptk-empty-install-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 17l9 4 9-4"/><path d="M3 12l9 4 9-4"/>
        </svg>
        <h3 class="ptk-empty-install-title">Coming soon</h3>
        <p class="ptk-empty-install-text">This PTA Hub is being set up. Check back soon for searchable guides, FAQs, and resources.</p>
        <?php if ( current_user_can( 'edit_posts' ) ) : ?>
            <a class="ptk-empty-install-cta" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-content-wizard' ) ); ?>">+ Add your first entry</a>
        <?php endif; ?>
    </div>
<?php else : ?>

    <!-- Hero Search Bar -->
    <div class="ptk-hero">
        <h2 class="ptk-hero-title">PTA Hub</h2>
        <p class="ptk-hero-subtitle">Quick answers to your PTA questions &mdash; search or browse below.</p>
        <div class="ptk-search-box">
            <svg class="ptk-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                type="text"
                id="ptk-search-input"
                class="ptk-search-input"
                placeholder="Search the PTA Hub..."
                autocomplete="off"
                aria-label="Search the PTA Hub"
            />
            <button id="ptk-search-clear" class="ptk-search-clear" aria-label="Clear search" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Category Filter Buttons -->
    <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
    <div class="ptk-category-filters" id="ptk-category-filters" role="group" aria-label="Filter by category">
        <button class="ptk-filter-btn ptk-filter-active" data-category="all" aria-pressed="true">All</button>
        <?php foreach ( $categories as $cat ) : ?>
            <button class="ptk-filter-btn" data-category="<?php echo esc_attr( $cat->slug ); ?>" aria-pressed="false">
                <?php echo esc_html( $cat->name ); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recently Added (always shows latest 4 entries) -->
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
        <p class="ptk-recent-label">Recently Added</p>
        <div class="ptk-recent-grid">
            <?php foreach ( $recent_posts as $rp ) :
                $rp_cats = wp_get_post_terms( $rp->ID, 'knowledge_category', array( 'fields' => 'names' ) );
                $rp_cat  = ( ! is_wp_error( $rp_cats ) && ! empty( $rp_cats ) ) ? $rp_cats[0] : '';
                $rp_cats_slugs = wp_get_post_terms( $rp->ID, 'knowledge_category', array( 'fields' => 'slugs' ) );
                $rp_cat_slug   = ! empty( $rp_cats_slugs ) ? $rp_cats_slugs[0] : '';
                $cat_css_map = array(
                    'how-to-guide' => 'howto', 'event-playbook' => 'event', 'faq' => 'faq',
                    'resource' => 'resource', 'glossary' => 'glossary', 'checklist' => 'checklist', 'policy' => 'policy',
                );
                $cat_class = isset( $cat_css_map[ $rp_cat_slug ] ) ? 'ptk-card-' . $cat_css_map[ $rp_cat_slug ] : '';
            ?>
                <div class="ptk-card <?php echo esc_attr( $cat_class ); ?>">
                    <div class="ptk-card-body">
                        <a href="<?php echo esc_url( get_permalink( $rp->ID ) ); ?>" class="ptk-card-title-link">
                            <h3 class="ptk-card-title"><?php echo esc_html( $rp->post_title ); ?></h3>
                        </a>
                        <p class="ptk-card-excerpt"><?php echo esc_html( $rp->post_excerpt ? $rp->post_excerpt : wp_trim_words( wp_strip_all_tags( $rp->post_content ), 15 ) ); ?></p>
                        <a href="<?php echo esc_url( get_permalink( $rp->ID ) ); ?>" class="ptk-card-link" aria-hidden="true" tabindex="-1">View &rarr;</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Suggested Searches (shown when no query) -->
    <div class="ptk-suggested" id="ptk-suggested">
        <p class="ptk-suggested-label">Popular searches:</p>
        <div class="ptk-suggested-tags">
            <?php foreach ( $suggested as $term ) : ?>
                <button class="ptk-suggested-tag" data-query="<?php echo esc_attr( $term ); ?>">
                    <?php echo esc_html( $term ); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="ptk-loading" id="ptk-loading" style="display:none;" role="status" aria-live="polite">
        <div class="ptk-spinner" aria-hidden="true"></div>
        <p>Searching...</p>
    </div>

    <!-- Results Container -->
    <div class="ptk-results" id="ptk-results" style="display:none;" aria-live="polite">

        <!-- Best Answer Slot -->
        <div class="ptk-best-answer" id="ptk-best-answer" style="display:none;"></div>

        <!-- Grouped Results -->
        <div class="ptk-groups" id="ptk-groups"></div>

        <!-- Result Count -->
        <p class="ptk-result-count" id="ptk-result-count" role="status"></p>
    </div>

    <!-- Empty State -->
    <div class="ptk-empty" id="ptk-empty" style="display:none;" aria-live="polite">
        <svg class="ptk-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <line x1="8" y1="11" x2="14" y2="11"/>
        </svg>
        <p class="ptk-empty-title">No results found</p>
        <p class="ptk-empty-text">Try different keywords, check your spelling, or browse by category above.</p>
        <p class="ptk-hint" id="ptk-hint" style="display:none;"></p>
        <p class="ptk-did-you-mean" id="ptk-did-you-mean" style="display:none;"></p>
    </div>

    <!-- Error State (network/server errors) -->
    <div class="ptk-error" id="ptk-error" style="display:none;" aria-live="assertive">
        <svg class="ptk-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p class="ptk-empty-title">Search unavailable</p>
        <p class="ptk-empty-text">Please try again in a moment.</p>
    </div>

<?php endif; ?>
</div>
