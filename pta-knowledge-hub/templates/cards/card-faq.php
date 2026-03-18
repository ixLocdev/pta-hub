<?php
/**
 * Card template: FAQ
 * Purple accent, question as title, answer preview, "Copy Answer" button.
 *
 * Variables: $result (array with id, title, excerpt, permalink, thumbnail, tags)
 */
?>
<div class="ptk-card ptk-card-faq">
    <div class="ptk-card-body">
        <div class="ptk-card-header">
            <span class="ptk-card-badge ptk-badge-faq">FAQ</span>
            <button class="ptk-copy-btn" data-copy-text="<?php echo esc_attr( $result['excerpt'] ); ?>" aria-label="Copy answer">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
                <span class="ptk-copy-label">Copy</span>
            </button>
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
        <a href="<?php echo esc_url( $result['permalink'] ); ?>" class="ptk-card-link ptk-link-faq" aria-hidden="true" tabindex="-1">Full Answer &rarr;</a>
    </div>
</div>
