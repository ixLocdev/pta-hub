<?php
/**
 * Template: Single knowledge entry view.
 *
 * WordPress will automatically use this template for the
 * pta_knowledge post type if placed in the theme or loaded via
 * the template_include filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

// Check access — show login prompt if required and user is not logged in.
if ( ! ptk_check_access() ) {
    echo '<div style="max-width:600px;margin:60px auto;padding:0 20px;">';
    ptk_check_access( true );
    echo '</div>';
    get_footer();
    return;
}

// Map category slugs to CSS class suffixes.
$cat_css_map = array(
    'how-to-guide'   => 'howto',
    'event-playbook' => 'event',
    'faq'            => 'faq',
    'resource'       => 'resource',
    'glossary'       => 'glossary',
    'checklist'      => 'checklist',
    'policy'         => 'policy',
);

while ( have_posts() ) :
    the_post();

    $cats      = wp_get_post_terms( get_the_ID(), 'knowledge_category' );
    $cat_slug  = ! empty( $cats ) ? $cats[0]->slug : '';
    $cat_name  = ! empty( $cats ) ? $cats[0]->name : '';
    $cat_class = isset( $cat_css_map[ $cat_slug ] ) ? 'ptk-cat-' . $cat_css_map[ $cat_slug ] : '';

    $tags = wp_get_post_terms( get_the_ID(), 'post_tag', array( 'fields' => 'names' ) );

    // Find related posts (same category, excluding current).
    $related = array();
    if ( ! empty( $cats ) ) {
        $related = get_posts( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'post__not_in'   => array( get_the_ID() ),
            'tax_query'      => array(
                array(
                    'taxonomy' => 'knowledge_category',
                    'field'    => 'term_id',
                    'terms'    => $cats[0]->term_id,
                ),
            ),
        ) );
    }
?>

<div class="ptk-single-wrap">

    <a href="<?php echo esc_url( home_url( '/knowledge-base' ) ); ?>" class="ptk-back-link" onclick="if(history.length>1){history.back();return false;}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Search
    </a>

    <!-- Breadcrumb -->
    <nav class="ptk-single-nav" aria-label="Breadcrumb">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
        <span class="ptk-sep">/</span>
        <a href="<?php echo esc_url( home_url( '/knowledge-base' ) ); ?>">Knowledge Base</a>
        <?php if ( $cat_name ) : ?>
            <span class="ptk-sep">/</span>
            <span><?php echo esc_html( $cat_name ); ?></span>
        <?php endif; ?>
    </nav>

    <!-- Category Badge -->
    <?php if ( $cat_name ) : ?>
        <span class="ptk-single-cat-badge <?php echo esc_attr( $cat_class ); ?>">
            <?php echo esc_html( $cat_name ); ?>
        </span>
    <?php endif; ?>

    <!-- Title -->
    <h1 class="ptk-single-title"><?php the_title(); ?></h1>

    <!-- Meta -->
    <div class="ptk-single-meta">
        <span>Updated <?php echo get_the_modified_date(); ?></span>
        <?php if ( $cat_name ) : ?>
            <span>&middot;</span>
            <span><?php echo esc_html( $cat_name ); ?></span>
        <?php endif; ?>
    </div>

    <!-- FAQ Copy Button (only for FAQ category) -->
    <?php if ( 'faq' === $cat_slug && get_the_excerpt() ) : ?>
        <button class="ptk-single-faq-copy" data-copy-text="<?php echo esc_attr( get_the_excerpt() ); ?>" aria-label="Copy answer to clipboard">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
            </svg>
            Copy Answer
        </button>
    <?php endif; ?>

    <!-- Content -->
    <div class="ptk-single-content">
        <?php the_content(); ?>
    </div>

    <!-- Tags -->
    <?php if ( ! empty( $tags ) ) : ?>
        <div class="ptk-single-tags">
            <?php foreach ( $tags as $tag ) : ?>
                <span class="ptk-single-tag"><?php echo esc_html( $tag ); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Related Entries -->
    <?php if ( ! empty( $related ) ) : ?>
        <div class="ptk-related">
            <h2 class="ptk-related-title">Related Entries</h2>
            <div class="ptk-related-grid">
                <?php foreach ( $related as $rel ) : ?>
                    <a href="<?php echo esc_url( get_permalink( $rel->ID ) ); ?>" class="ptk-related-card">
                        <div class="ptk-related-card-title"><?php echo esc_html( $rel->post_title ); ?></div>
                        <div class="ptk-related-card-excerpt">
                            <?php echo esc_html( $rel->post_excerpt ? $rel->post_excerpt : wp_trim_words( wp_strip_all_tags( $rel->post_content ), 15 ) ); ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
endwhile;
get_footer();
