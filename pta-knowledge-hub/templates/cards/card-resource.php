<?php
/**
 * Card template: Resource
 * Orange accent, prominent thumbnail, video play overlay, "View Resource" link.
 *
 * Variables: $result (array with id, title, excerpt, permalink, thumbnail, tags)
 */
?>
<a href="<?php echo esc_url( $result['permalink'] ); ?>" class="ptk-card ptk-card-resource">
    <div class="ptk-card-thumb ptk-thumb-resource">
        <?php if ( $result['thumbnail'] ) : ?>
            <img src="<?php echo esc_url( $result['thumbnail'] ); ?>" alt="<?php echo esc_attr( $result['title'] ); ?>" loading="lazy" />
        <?php else : ?>
            <div class="ptk-thumb-placeholder">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <path d="m21 15-5-5L5 21"/>
                </svg>
            </div>
        <?php endif; ?>
    </div>
    <div class="ptk-card-body">
        <span class="ptk-card-badge ptk-badge-resource">Resource</span>
        <h3 class="ptk-card-title"><?php echo esc_html( $result['title'] ); ?></h3>
        <p class="ptk-card-excerpt"><?php echo esc_html( $result['excerpt'] ); ?></p>
        <?php if ( ! empty( $result['tags'] ) ) : ?>
            <div class="ptk-card-tags">
                <?php foreach ( array_slice( $result['tags'], 0, 3 ) as $tag ) : ?>
                    <span class="ptk-card-tag"><?php echo esc_html( $tag ); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <span class="ptk-card-link ptk-link-resource">View Resource &rarr;</span>
    </div>
</a>
