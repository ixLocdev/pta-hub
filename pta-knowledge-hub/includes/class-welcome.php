<?php
/**
 * "Start Here" welcome screen for PTA Hub volunteers.
 *
 * A friendly, role-aware admin home base: opens when you click the PTA Hub
 * menu (reordered to land here), plus a Dashboard-home widget nudge. Reads
 * existing counts (vendor approvals, topic suggestions, entries due for
 * review) — no new storage. See docs/superpowers/specs/2026-07-15-welcome-page-design.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Welcome {

    const PAGE_SLUG   = 'ptk-welcome';
    const MENU_PARENT = 'edit.php?post_type=pta_knowledge';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        // Late priority so the reorder runs AFTER core adds "All Entries" and
        // after the other PTA Hub submenus register — otherwise it races them.
        add_action( 'admin_menu', array( __CLASS__, 'reorder_menu' ), 999 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
    }

    /** Register the Start Here submenu page. */
    public static function add_page() {
        add_submenu_page(
            self::MENU_PARENT,
            'Start Here',
            'Start Here',
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Move Start Here to the top of the PTA Hub submenu so clicking the
     * top-level "PTA Hub" opens it (WP links a top-level menu to its first
     * submenu item).
     */
    public static function reorder_menu() {
        global $submenu;
        if ( empty( $submenu[ self::MENU_PARENT ] ) ) {
            return;
        }
        $items = $submenu[ self::MENU_PARENT ];

        // Start Here first, then the three newsletter pages together, in the
        // order a volunteer uses them. WordPress lists submenus in the order
        // they were registered, which scattered them among Vendors and
        // Suggestions. $item[2] is the menu slug.
        $wanted = array(
            self::PAGE_SLUG,
            'edit.php?post_type=pta_newsletter',
            'ptk-newsletter-builder',
            'ptk-share-settings',
        );
        $front = array();
        foreach ( $wanted as $slug ) {
            foreach ( $items as $i => $item ) {
                if ( isset( $item[2] ) && $slug === $item[2] ) {
                    $front[] = $item;
                    unset( $items[ $i ] );
                    break;
                }
            }
        }
        if ( $front ) {
            $submenu[ self::MENU_PARENT ] = array_values( array_merge( $front, $items ) );
        }
    }

    /** Enqueue the screen CSS only on the Start Here page. */
    public static function enqueue( $hook ) {
        if ( 'pta_knowledge_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'ptk-welcome',
            PTK_PLUGIN_URL . 'assets/css/welcome.css',
            array(),
            PTK_VERSION
        );
    }

    /** Council-only pieces need the moderator cap AND the main site. */
    private static function is_council_admin() {
        return current_user_can( 'edit_others_posts' )
            && ( ! is_multisite() || is_main_site() ); // core is_main_site() — true on single-site
    }

    /**
     * "Waiting for you" items the viewer can act on, with count > 0.
     * @return array[] each: label, count, url, color ('urgent'|'soon')
     */
    private static function get_nudges() {
        $out = array();

        if ( self::is_council_admin() && class_exists( 'PTK_Vendor_Moderation' ) ) {
            $n = PTK_Vendor_Moderation::pending_count();
            if ( $n > 0 ) {
                $out[] = array(
                    'label' => 'Vendor reviews to approve',
                    'count' => $n,
                    'url'   => admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-vendor-approvals' ),
                    'color' => 'urgent',
                );
            }
        }

        if ( current_user_can( 'edit_posts' ) && class_exists( 'PTK_Suggestions' ) ) {
            $counts = wp_count_posts( PTK_Suggestions::POST_TYPE );
            $n      = $counts ? (int) $counts->publish : 0;
            if ( $n > 0 ) {
                $out[] = array(
                    'label' => 'Topic suggestions from members',
                    'count' => $n,
                    'url'   => admin_url( 'edit.php?post_type=' . PTK_Suggestions::POST_TYPE ),
                    'color' => 'soon',
                );
            }
        }

        if ( current_user_can( 'edit_posts' ) && class_exists( 'PTK_Review_Reminders' ) ) {
            $n = PTK_Review_Reminders::overdue_count();
            if ( $n > 0 ) {
                $out[] = array(
                    'label' => 'Entries due for a review',
                    'count' => $n,
                    'url'   => admin_url( 'edit.php?post_type=pta_knowledge&orderby=ptk_last_reviewed&order=asc' ),
                    'color' => 'soon',
                );
            }
        }

        return $out;
    }

    /**
     * Action cards the viewer can use.
     * @return array[] each: icon, title, desc, button, url, primary(bool), new_tab(bool)
     */
    private static function get_cards() {
        $cards = array();

        // 4.3.0: the newsletter is the most-repeated weekly task, so it
        // takes the first, primary-styled slot — "Add or update an entry"
        // (below) steps back to secondary. Two blue "primary" buttons on
        // one page is no primary action at all (spec Decision 8).
        if ( current_user_can( 'edit_posts' ) && class_exists( 'PTK_Newsletter_Builder' ) ) {
            $last_line  = '';
            $last_label = '';
            $edit_url   = '';
            $last_id    = PTK_Newsletter_Builder::most_recent_newsletter_id();
            if ( $last_id ) {
                $last_issue = get_post_meta( $last_id, 'ptk_nl_issue', true );
                $last_date  = (string) get_post_meta( $last_id, 'ptk_nl_date', true );
                if ( $last_issue && class_exists( 'PTK_Share_Text' ) ) {
                    $edit_url  = add_query_arg( 'ptk_nl_edit_id', $last_id, PTK_Newsletter_Builder::url() );
                    $date_str  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $last_date ) ? date_i18n( 'M j', strtotime( $last_date ) ) : '';
                    $last_label = 'No. ' . PTK_Share_Text::issue_label( $last_issue );
                    $last_line  = $last_label . ( '' !== $date_str ? ' · ' . $date_str : '' ) . '.';
                }
            }
            $cards[] = array(
                'icon'    => '📰',
                'title'   => "Write this week's newsletter",
                'desc'    => 'Five short steps, with a live preview as you go.' . ( $last_line ? ' Last issue: ' . $last_line : '' ),
                'button'  => 'Start the newsletter',
                'url'     => PTK_Newsletter_Builder::url(),
                'primary' => true,
                'new_tab' => false,
                // A second, quieter button to pick up the latest issue again
                // (fix a typo after sending, finish a draft).
                'second_button' => $edit_url ? 'Edit ' . $last_label : '',
                'second_url'    => $edit_url,
            );
        }

        if ( current_user_can( 'edit_posts' ) && class_exists( 'PTK_Content_Wizard' ) ) {
            $cards[] = array(
                'icon'    => '➕',
                'title'   => 'Add or update an entry',
                'desc'    => 'Answer a common question or write a how-to. Fill in a few boxes and we format it for you.',
                'button'  => 'Start the guided form',
                'url'     => PTK_Content_Wizard::url(),
                'primary' => false,
                'new_tab' => false,
            );
        }

        if ( self::is_council_admin() ) {
            $cards[] = array(
                'icon'    => '🏪',
                'title'   => 'Manage the Vendor Directory',
                'desc'    => 'Add vendors and approve the reviews members write, shared across every school.',
                'button'  => 'Open vendors',
                'url'     => admin_url( 'edit.php?post_type=ptk_vendor' ),
                'primary' => false,
                'new_tab' => false,
            );
        }

        // Everyone: see the live Hub + glossary (front-end, new tab).
        $cards[] = array(
            'icon'    => '🔎',
            'title'   => 'See the live Hub',
            'desc'    => 'View the site the way parents and members see it — search, answers, and the vendor directory.',
            'button'  => 'Open the Hub',
            'url'     => ptk_hub_url(),
            'primary' => false,
            'new_tab' => true,
        );
        $cards[] = array(
            'icon'    => '📖',
            'title'   => 'Browse the glossary',
            'desc'    => 'Plain-English definitions of PTA terms, tools, and acronyms — handy when something\'s unfamiliar.',
            'button'  => 'Open glossary',
            'url'     => ptk_glossary_url(),
            'primary' => false,
            'new_tab' => true,
        );

        return $cards;
    }

    public static function render_page() {
        $nudges = self::get_nudges();
        $cards  = self::get_cards();
        ?>
        <div class="wrap ptk-welcome">
            <h1 class="ptk-welcome-title">👋 Welcome to the PTA Hub</h1>
            <p class="ptk-welcome-intro">Everything your PTA knows, in one place — plus a few easy things you can do here. No tech skills needed.</p>

            <?php if ( ! empty( $nudges ) ) : ?>
            <div class="ptk-welcome-section-label">Waiting for you</div>
            <div class="ptk-welcome-nudges">
                <?php foreach ( $nudges as $n ) : ?>
                    <a class="ptk-welcome-nudge" href="<?php echo esc_url( $n['url'] ); ?>">
                        <span class="ptk-welcome-nudge-count ptk-welcome-nudge-<?php echo esc_attr( $n['color'] ); ?>"><?php echo esc_html( number_format_i18n( $n['count'] ) ); ?></span>
                        <span class="ptk-welcome-nudge-label"><?php echo esc_html( $n['label'] ); ?> →</span>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="ptk-welcome-note">These only appear when something's actually waiting — a quiet week shows nothing here.</p>
            <?php endif; ?>

            <div class="ptk-welcome-section-label">What would you like to do?</div>
            <div class="ptk-welcome-cards">
                <?php foreach ( $cards as $c ) : ?>
                    <div class="ptk-welcome-card">
                        <div class="ptk-welcome-card-icon"><?php echo esc_html( $c['icon'] ); ?></div>
                        <div class="ptk-welcome-card-title"><?php echo esc_html( $c['title'] ); ?></div>
                        <?php /* wp_kses_post, not esc_html: the newsletter card's "Last issue: … · Edit"
                                line carries a real link. Every other card's desc is a plain hard-coded
                                string with nothing to escape, so this is a no-op for them. */ ?>
                        <div class="ptk-welcome-card-desc"><?php echo wp_kses_post( $c['desc'] ); ?></div>
                        <a class="ptk-welcome-btn <?php echo $c['primary'] ? 'ptk-welcome-btn-primary' : 'ptk-welcome-btn-secondary'; ?>"
                           href="<?php echo esc_url( $c['url'] ); ?>"<?php echo $c['new_tab'] ? ' target="_blank" rel="noopener"' : ''; ?>>
                            <?php echo esc_html( $c['button'] ); ?>
                        </a>
                        <?php if ( ! empty( $c['second_button'] ) && ! empty( $c['second_url'] ) ) : ?>
                            <a class="ptk-welcome-btn ptk-welcome-btn-secondary" href="<?php echo esc_url( $c['second_url'] ); ?>">
                                <?php echo esc_html( $c['second_button'] ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="ptk-welcome-footer">New to all this? Everything here is safe to click around — you can't break anything, and nothing goes public until it's ready. 💛</p>
        </div>
        <?php
    }

    public static function add_dashboard_widget() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'ptk_welcome_widget',
            'PTA Hub — Start Here',
            array( __CLASS__, 'render_dashboard_widget' )
        );
    }

    public static function render_dashboard_widget() {
        $nudges = self::get_nudges();
        $url    = admin_url( 'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG );
        echo '<p style="font-size:13px;color:#50575e;margin:0 0 12px;">Your PTA\'s answers, how-tos, and vendor directory — all in one place.</p>';
        if ( ! empty( $nudges ) ) {
            // Surface the single most urgent item (urgent before soon).
            usort( $nudges, function( $a, $b ) {
                $rank = array( 'urgent' => 0, 'soon' => 1 );
                return $rank[ $a['color'] ] <=> $rank[ $b['color'] ];
            } );
            $top = $nudges[0];
            echo '<p style="font-size:13px;margin:0 0 12px;"><strong>' . esc_html( number_format_i18n( $top['count'] ) ) . '</strong> '
                . esc_html( strtolower( $top['label'] ) ) . ' — <a href="' . esc_url( $top['url'] ) . '">take a look →</a></p>';
        }
        echo '<a class="button button-primary" href="' . esc_url( $url ) . '">Open Start Here</a>';
    }
}
