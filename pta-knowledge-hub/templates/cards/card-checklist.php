<?php
/**
 * Card template: Checklist
 * Green accent, checklist title, summary, item count hint.
 *
 * Variables: $result (array with id, title, excerpt, permalink, thumbnail, tags)
 */
?>
<div class="ptk-card ptk-card-checklist">
    <div class="ptk-card-body">
        <div class="ptk-card-header">
            <span class="ptk-card-badge ptk-badge-checklist">Checklist</span>
        </div>
        <a href="<?php echo esc_url( $result['permalink'] ); ?>" class="ptk-card-title-link">
            <h3 class="ptk-card-title"><?php echo esc_html( $result['title'] ); ?></h3>
        </a>
        <p class="ptk-card-excerpt"><?php echo esc_html( $result['excerpt'] ); ?></p>
        <?php if ( ! empty( $result['tags'] ) ) : ?>
            <div class="ptk-card-tags">
                <?php foreach ( array_slice( $result['tags'], 0, 3 ) as $tag ) : ?>
                    <span class="ptk-card-tag"><?php echo esc_html( $tag ); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <a href="<?php echo esc_url( $result['permalink'] ); ?>" class="ptk-card-link ptk-link-checklist" aria-hidden="true" tabindex="-1">View Checklist &rarr;</a>
    </div>
</div>
