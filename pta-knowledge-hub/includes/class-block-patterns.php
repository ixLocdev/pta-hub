<?php
/**
 * Registers Gutenberg block patterns for each knowledge category.
 *
 * Provides structured starting points so volunteers create consistent content:
 * - How-To Guide: materials + numbered steps + tips
 * - Event Playbook: overview + timeline + supplies/budget + contacts
 * - FAQ: quick answer + detailed explanation
 * - Resource: description + how to use + related links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Block_Patterns {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_pattern_category' ) );
        add_action( 'init', array( __CLASS__, 'register_patterns' ) );
    }

    /**
     * Register a pattern category so all PTA patterns are grouped together.
     */
    public static function register_pattern_category() {
        register_block_pattern_category( 'pta-knowledge', array(
            'label' => __( 'PTA Knowledge Hub', 'pta-knowledge-hub' ),
        ) );
    }

    /**
     * Register all four category-specific block patterns.
     */
    public static function register_patterns() {
        // How-To Guide
        register_block_pattern( 'pta-knowledge/how-to-guide', array(
            'title'       => __( 'How-To Guide', 'pta-knowledge-hub' ),
            'description' => __( 'Step-by-step guide with materials list, numbered steps, and tips.', 'pta-knowledge-hub' ),
            'categories'  => array( 'pta-knowledge' ),
            'postTypes'   => array( 'pta_knowledge' ),
            'content'     => self::howto_pattern(),
        ) );

        // Event Playbook
        register_block_pattern( 'pta-knowledge/event-playbook', array(
            'title'       => __( 'Event Playbook', 'pta-knowledge-hub' ),
            'description' => __( 'Event plan with overview, timeline, supplies, budget, and contacts.', 'pta-knowledge-hub' ),
            'categories'  => array( 'pta-knowledge' ),
            'postTypes'   => array( 'pta_knowledge' ),
            'content'     => self::event_pattern(),
        ) );

        // FAQ
        register_block_pattern( 'pta-knowledge/faq', array(
            'title'       => __( 'FAQ Entry', 'pta-knowledge-hub' ),
            'description' => __( 'FAQ with a quick answer and detailed explanation.', 'pta-knowledge-hub' ),
            'categories'  => array( 'pta-knowledge' ),
            'postTypes'   => array( 'pta_knowledge' ),
            'content'     => self::faq_pattern(),
        ) );

        // Resource
        register_block_pattern( 'pta-knowledge/resource', array(
            'title'       => __( 'Resource', 'pta-knowledge-hub' ),
            'description' => __( 'Resource entry with description, usage instructions, and related links.', 'pta-knowledge-hub' ),
            'categories'  => array( 'pta-knowledge' ),
            'postTypes'   => array( 'pta_knowledge' ),
            'content'     => self::resource_pattern(),
        ) );
    }

    /**
     * How-To Guide pattern content.
     */
    private static function howto_pattern() {
        return '<!-- wp:heading -->
<h2>What You\'ll Need</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li>Item or supply needed</li>
<li>Another item</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Steps</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol>
<li>First step — describe what to do</li>
<li>Second step — add details</li>
<li>Third step — continue as needed</li>
</ol>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Tips &amp; Notes</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Add any helpful tips, common mistakes to avoid, or additional notes here.</p>
<!-- /wp:paragraph -->';
    }

    /**
     * Event Playbook pattern content.
     */
    private static function event_pattern() {
        return '<!-- wp:heading -->
<h2>Overview</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Briefly describe what this event is, who it\'s for, and the goal.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Timeline</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li><strong>4 weeks before:</strong> Start planning, book venue</li>
<li><strong>2 weeks before:</strong> Send invitations, recruit volunteers</li>
<li><strong>1 week before:</strong> Confirm supplies, finalize schedule</li>
<li><strong>Day of:</strong> Setup, run event, cleanup</li>
<li><strong>After:</strong> Thank volunteers, report results</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Supplies &amp; Budget</h2>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Item</th><th>Quantity</th><th>Estimated Cost</th></tr></thead><tbody><tr><td>Example item</td><td>10</td><td>$25.00</td></tr><tr><td>Another item</td><td>5</td><td>$15.00</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:heading -->
<h2>Key Contacts</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li><strong>Event Lead:</strong> Name — email or phone</li>
<li><strong>Volunteers:</strong> Name — role</li>
</ul>
<!-- /wp:list -->';
    }

    /**
     * FAQ pattern content.
     */
    private static function faq_pattern() {
        return '<!-- wp:heading -->
<h2>Quick Answer</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><strong>Write a concise 1–2 sentence answer here.</strong> This is what people see first and what gets copied when they click "Copy Answer."</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Details</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Provide a more complete explanation here. Include any relevant context, links, or additional information that helps answer the question thoroughly.</p>
<!-- /wp:paragraph -->';
    }

    /**
     * Resource pattern content.
     */
    private static function resource_pattern() {
        return '<!-- wp:heading -->
<h2>Description</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Describe what this resource is and why it\'s useful.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>How to Use</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol>
<li>Download or access the resource</li>
<li>Follow these steps to use it</li>
<li>Customize as needed for your school</li>
</ol>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Related Links</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li><a href="#">Link to related resource or website</a></li>
</ul>
<!-- /wp:list -->';
    }
}
