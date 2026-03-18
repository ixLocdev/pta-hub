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
<div class="ptk-search-wrap" id="ptk-search-app">

    <!-- Hero Search Bar -->
    <div class="ptk-hero">
        <h2 class="ptk-hero-title">PTA Knowledge Base</h2>
        <p class="ptk-hero-subtitle">Find answers, guides, and resources instantly.</p>
        <div class="ptk-search-box">
            <svg class="ptk-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                type="text"
                id="ptk-search-input"
                class="ptk-search-input"
                placeholder="Search the PTA knowledge base..."
                autocomplete="off"
                aria-label="Search the PTA knowledge base"
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
                <span class="ptk-filter-count"><?php echo esc_html( $cat->count ); ?></span>
            </button>
        <?php endforeach; ?>
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

</div>
