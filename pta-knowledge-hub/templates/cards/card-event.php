<?php
/**
 * Card template: Event Playbook
 * Green accent, event-style layout, "View Playbook" link.
 *
 * Variables: $result (array with id, title, excerpt, permalink, thumbnail, tags)
 */
?>
<a href="<?php echo esc_url( $result['permalink'] ); ?>" class="ptk-card ptk-card-event">
    <?php if ( $result['thumbnail'] ) : ?>
        <div class="ptk-card-thumb">
            <img src="<?php echo esc_url( $result['thumbnail'] ); ?>" alt="<?php echo esc_attr( $result['title'] ); ?>" loading="lazy" />
        </div>
    <?php endif; ?>
    <div class="ptk-card-body">
        <span class="ptk-card-badge ptk-badge-event">Event Playbook</span>
        <h3 class="ptk-card-title"><?php echo esc_html( $result['title'] ); ?></h3>
        <p class="ptk-card-excerpt"><?php echo esc_html( $result['excerpt'] ); ?></p>
        <?php if ( ! empty( $result['tags'] ) ) : ?>
            <div class="ptk-card-tags">
                <?php foreach ( array_slice( $result['tags'], 0, 3 ) as $tag ) : ?>
                    <span class="ptk-card-tag"><?php echo esc_html( $tag ); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <span class="ptk-card-link ptk-link-event">View Playbook &rarr;</span>
    </div>
</a>
