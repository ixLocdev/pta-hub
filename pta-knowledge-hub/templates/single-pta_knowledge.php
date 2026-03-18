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

// Check role-based access.
if ( class_exists( 'PTK_Role_Access' ) ) {
    $post_id_check = get_the_ID();
    if ( $post_id_check && ! PTK_Role_Access::can_user_view( $post_id_check ) ) {
        ?>
        <div class="ptk-single-wrap">
            <div class="ptk-role-restricted">
                <div class="ptk-role-restricted-icon">&#128274;</div>
                <h2>Restricted Content</h2>
                <p>This knowledge base entry is only available to certain roles. Contact your PTA administrator if you believe you should have access.</p>
            </div>
        </div>
        <?php
        get_footer();
        return;
    }
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

    <!-- Network Sync Banner (multisite) -->
    <?php if ( class_exists( 'PTK_Multisite' ) && PTK_Multisite::is_network_copy( get_the_ID() ) ) : ?>
        <div class="ptk-network-banner">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Shared by the PTA Council
        </div>
    <?php endif; ?>

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

    <!-- Action Bar -->
    <div class="ptk-single-actions">
        <?php if ( 'faq' === $cat_slug && get_the_excerpt() ) : ?>
            <button class="ptk-single-faq-copy" data-copy-text="<?php echo esc_attr( get_the_excerpt() ); ?>" aria-label="Copy answer to clipboard">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
                Copy Answer
            </button>
        <?php endif; ?>
        <button class="ptk-print-btn" onclick="window.print();" aria-label="Print this entry">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>
            </svg>
            Print This
        </button>
    </div>

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

    <!-- Feedback: Was This Helpful? -->
    <?php if ( class_exists( 'PTK_Feedback' ) ) :
        $ptk_fb_counts = PTK_Feedback::get_feedback_counts( get_the_ID() );
        $ptk_fb_voted  = PTK_Feedback::has_user_voted( get_the_ID() );
    ?>
        <div class="ptk-feedback" id="ptk-feedback" data-post-id="<?php echo esc_attr( get_the_ID() ); ?>">
            <?php if ( $ptk_fb_voted ) : ?>
                <p class="ptk-feedback-thanks">Thanks for your feedback!</p>
                <p class="ptk-feedback-counts">
                    <?php echo esc_html( $ptk_fb_counts['helpful'] ); ?> found this helpful &middot;
                    <?php echo esc_html( $ptk_fb_counts['not_helpful'] ); ?> did not
                </p>
            <?php else : ?>
                <p class="ptk-feedback-question">Was this entry helpful?</p>
                <div class="ptk-feedback-buttons">
                    <button class="ptk-feedback-btn" data-helpful="1" data-post-id="<?php echo esc_attr( get_the_ID() ); ?>" aria-label="Yes, this was helpful">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/><path d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3"/></svg>
                        Yes
                    </button>
                    <button class="ptk-feedback-btn" data-helpful="0" data-post-id="<?php echo esc_attr( get_the_ID() ); ?>" aria-label="No, this was not helpful">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/><path d="M17 2h3a2 2 0 012 2v7a2 2 0 01-2 2h-3"/></svg>
                        No
                    </button>
                </div>
            <?php endif; ?>
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
