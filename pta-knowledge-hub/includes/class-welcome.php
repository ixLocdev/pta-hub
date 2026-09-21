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
        if ( class_exists( 'PTK_Hub_Look' ) && PTK_Hub_Look::on() ) {
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

    /**
     * The six things a volunteer might want to do, in order. Pure: the
     * caller passes a capability map and the real destinations, so tests
     * need no WordPress. Each item: key, title, meta, url, soft (bool).
     *
     * @param array $caps  e.g. array( 'edit_posts' => bool, 'manage_options' => bool, 'council' => bool )
     * @param array $urls  key => url, supplied by render_new_home(); missing keys give ''.
     * @return array[]
     */
    public static function intentions( array $caps, array $urls = array() ) {
        $edit_posts     = ! empty( $caps['edit_posts'] );
        $manage_options = ! empty( $caps['manage_options'] );

        $catalog = array(
            'newsletter' => array(
                'title'  => "Tell families what's happening",
                'meta'   => "Write this week's newsletter — five short steps, with a preview.",
                'needs'  => $edit_posts,
                'soft'   => true,
            ),
            'answer'     => array(
                'title'  => 'Answer a question families keep asking',
                'meta'   => 'Write it down once, and it lives on the Hub for everyone.',
                'needs'  => $edit_posts,
                'soft'   => false,
            ),
            'vendor'     => array(
                'title'  => "Recommend someone we've used",
                'meta'   => 'A DJ, a caterer, a photographer — add them for other PTAs.',
                'needs'  => $edit_posts,
                'soft'   => false,
            ),
            'word'       => array(
                'title'  => 'Explain a word or phrase',
                'meta'   => 'ASE, GiveBacks, room parent — in plain English.',
                'needs'  => $edit_posts,
                'soft'   => false,
            ),
            'fix'        => array(
                'title'  => "Fix something that's wrong",
                'meta'   => 'Find what this site has written and change it.',
                'needs'  => $edit_posts,
                'soft'   => false,
            ),
        );

        $intentions = array();
        foreach ( $catalog as $key => $item ) {
            if ( ! $item['needs'] ) {
                continue;
            }
            $intentions[] = array(
                'key'   => $key,
                'title' => $item['title'],
                'meta'  => $item['meta'],
                'url'   => isset( $urls[ $key ] ) ? (string) $urls[ $key ] : '',
                'soft'  => $item['soft'],
            );
        }

        // The "not sure" route needs at least two real choices to be worth offering.
        if ( count( $intentions ) >= 2 ) {
            $intentions[] = array(
                'key'   => 'unsure',
                'title' => "I'm not sure where to start",
                'meta'  => "Say what you need to do and I'll take you to the right place.",
                'url'   => '',
                'soft'  => false,
            );
        }

        return $intentions;
    }

    /** Plain-language cues for the "not sure" picker: intention key => sentence. */
    public static function cues() {
        return array(
            'newsletter' => 'We have a PTA meeting next Thursday',
            'answer'     => 'Parents keep emailing about pickup',
            'vendor'     => 'The DJ from the spring dance was great',
            'word'       => 'Someone asked what "Title I" means',
            'fix'        => "There's a typo on the website",
        );
    }

    /**
     * Pure: "2 vendor reviews to approve" / "1 vendor review to approve".
     * The nudge labels are plural nouns; a count of one gets the singular.
     */
    public static function waiting_text( $label, $count ) {
        $count    = (int) $count;
        $singular = array(
            'Vendor reviews to approve'      => 'vendor review to approve',
            'Topic suggestions from members' => 'topic suggestion from members',
            'Entries due for a review'       => 'entry due for a review',
        );
        if ( 1 === $count && isset( $singular[ $label ] ) ) {
            return '1 ' . $singular[ $label ];
        }
        $label = strtolower( substr( $label, 0, 1 ) ) . substr( $label, 1 );
        return number_format( $count ) . ' ' . $label;
    }

    /**
     * The inside of the "I'm not sure where to start" card: a box to say
     * what you need in your own words, whatever that turned out to mean,
     * and the five example sentences underneath it.
     *
     * The form is a plain GET back to this screen -- no JavaScript, no
     * AJAX -- so it works everywhere, the back button behaves, and the
     * result can be linked to. PTK_Hub_Router does the thinking and never
     * sends anyone anywhere on its own: this screen always shows what it
     * thinks and lets the volunteer click.
     *
     * @param array  $intentions From intentions(), including 'unsure'.
     * @param array  $urls       Intention key => plain destination url.
     * @param string $typed      What the volunteer wrote, already sanitized.
     * @return string Trusted markup for PTK_Hub_UI::card()'s body.
     */
    private static function render_router_body( array $intentions, array $urls, $typed ) {
        // Only the intentions really on this volunteer's screen can be
        // offered -- the router never invents a destination.
        $available = array();
        $titles    = array();
        $metas     = array();
        foreach ( $intentions as $item ) {
            if ( 'unsure' === $item['key'] || '' === $item['url'] ) {
                continue;
            }
            $available[]           = $item['key'];
            $titles[ $item['key'] ] = $item['title'];
            $metas[ $item['key'] ]  = $item['meta'];
        }

        $out  = '<form class="ptk-router" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        $out .= '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        $out .= '<label class="ptk-router-label" for="ptk-start">What are you trying to get done?</label>';
        $out .= '<div class="ptk-router-row">';
        $out .= '<input type="text" class="ptk-router-input" id="ptk-start" name="ptk_start"'
            . ' autocomplete="off" spellcheck="false"'
            . ( '' !== $typed ? ' autofocus' : '' )
            . ' value="' . esc_attr( $typed ) . '"'
            . ' placeholder="I need parents to volunteer for the book fair">';
        $out .= '<button type="submit" class="ptk-router-go">Show me where</button>';
        $out .= '</div>';
        $out .= '</form>';

        $state = '';
        if ( '' !== $typed && ! empty( $available ) ) {
            $route = PTK_Hub_Router::route( $typed, $available );
            $state = $route['state'];

            $out .= '<p class="ptk-router-said">' . PTK_Hub_UI::no_widow( PTK_Hub_Router::heading( $route['state'] ) ) . '</p>';

            $shown = 0;
            foreach ( $route['matches'] as $match ) {
                $key = $match['key'];
                $to  = PTK_Hub_Router::destination( $key, $typed, $urls );
                if ( '' === $to || ! isset( $titles[ $key ] ) ) {
                    continue;
                }
                $class = 'ptk-router-match' . ( 0 === $shown ? ' ptk-router-match--first' : '' );
                $out  .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $to ) . '">'
                    . '<span class="ptk-router-match-title">' . esc_html( $titles[ $key ] ) . '</span>'
                    . '<span class="ptk-router-match-meta">' . esc_html( $metas[ $key ] ) . '</span>'
                    . '</a>';
                $shown++;
            }
        }

        // The example sentences, always. After an answer they are the way
        // back out; before one, they show what this box is for.
        $cues  = self::cues();
        $list  = '';
        foreach ( $cues as $cue_key => $cue_sentence ) {
            if ( ! isset( $urls[ $cue_key ] ) || '' === $urls[ $cue_key ] || ! isset( $titles[ $cue_key ] ) ) {
                continue;
            }
            $list .= '<a class="ptk-cue" href="' . esc_url( $urls[ $cue_key ] ) . '">'
                . '<span class="ptk-cue-text">&#8220;' . esc_html( $cue_sentence ) . '&#8221;</span>'
                . esc_html( $titles[ $cue_key ] )
                . '</a>';
        }

        if ( '' !== $list ) {
            // When nothing matched, the heading above already introduced
            // this list -- a second line over it would say it twice.
            if ( 'none' !== $state ) {
                $label = ( '' !== $typed ) ? 'Or start from one of these:' : 'Or pick the sentence that sounds like you:';
                $out  .= '<p class="ptk-router-said ptk-router-said--quiet">' . PTK_Hub_UI::no_widow( $label ) . '</p>';
            }
            $out .= $list;
        }

        return $out;
    }

    /**
     * The new home screen: six intentions instead of a card grid, rendered
     * only when the PTA Hub look is on (see render_page()).
     */
    private static function render_new_home() {
        $caps = array(
            'edit_posts'     => current_user_can( 'edit_posts' ),
            'manage_options' => current_user_can( 'manage_options' ),
            'council'        => self::is_council_admin(),
        );

        $vendor_url = '';
        if ( $caps['council'] ) {
            $vendor_url = admin_url( 'edit.php?post_type=ptk_vendor' );
        }
        // No helper/option holds the public Vendor Directory page's id or
        // url, so non-council volunteers get no vendor intention rather
        // than a guessed link (dropped below when the url is empty).

        $urls = array(
            'newsletter' => class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::url() : '',
            // ?ptk_for=question / ?ptk_for=word pick the question-first
            // screen's headline and starting type (plan Task 2) -- only
            // read by the wizard when the new look is on; with it off the
            // wizard ignores ptk_for entirely, so this is a harmless extra
            // query arg on the old screen.
            'answer'     => class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_for', 'question', PTK_Content_Wizard::url() ) : '',
            'word'       => class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_for', 'word', PTK_Content_Wizard::url() ) : '',
            'vendor'     => $vendor_url,
            'fix'        => class_exists( 'PTK_Written_List' ) ? PTK_Written_List::url() : admin_url( 'edit.php?post_type=pta_knowledge' ),
        );

        $intentions = self::intentions( $caps, $urls );

        // What the volunteer typed into "I'm not sure where to start", if
        // anything. The form is a plain GET back to this same screen, so
        // the router works with JavaScript off and the result is a real,
        // shareable url.
        $typed = isset( $_GET['ptk_start'] ) ? sanitize_text_field( wp_unslash( $_GET['ptk_start'] ) ) : '';

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( 'What would you like to do?', "Nothing goes out to families until you say so." );

        echo '<div class="ptk-cards">';
        foreach ( $intentions as $intention ) {
            // Drop any intention (other than "unsure") whose url is empty —
            // there is nowhere real to send the volunteer.
            if ( 'unsure' !== $intention['key'] && '' === $intention['url'] ) {
                continue;
            }

            if ( 'unsure' === $intention['key'] ) {
                echo PTK_Hub_UI::card( array(
                    'title' => $intention['title'],
                    'meta'  => $intention['meta'],
                    'body'  => self::render_router_body( $intentions, $urls, $typed ),
                    'key'   => $intention['key'],
                    // Open on its own only when they have already asked
                    // something -- otherwise the answer would be hidden
                    // behind the fold they just came out of.
                    'open'  => ( '' !== $typed ),
                ) );
                continue;
            }

            echo PTK_Hub_UI::card( array(
                'title' => $intention['title'],
                'meta'  => $intention['meta'],
                'url'   => $intention['url'],
                'soft'  => $intention['soft'],
                'key'   => $intention['key'],
            ) );
        }
        echo '</div>';

        $nudges = self::get_nudges();
        if ( ! empty( $nudges ) ) {
            $items = array();
            foreach ( $nudges as $n ) {
                $items[] = array(
                    'text' => self::waiting_text( $n['label'], $n['count'] ),
                    'url'  => $n['url'],
                );
            }
            echo PTK_Hub_UI::waiting_row( $items );
        }

        $quiet = array();
        if ( current_user_can( 'manage_options' ) && class_exists( 'PTK_Share_Settings' ) ) {
            $quiet[] = array(
                'label' => 'Set up the basics (once)',
                'url'   => PTK_Share_Settings::page_url(),
            );
        }
        $quiet[] = array(
            'label' => class_exists( 'PTK_Simple_Mode' ) ? PTK_Simple_Mode::toggle_link_label() : 'Show all of WordPress',
            'url'   => class_exists( 'PTK_Simple_Mode' ) ? PTK_Simple_Mode::toggle_url() : admin_url(),
        );
        if ( ! empty( $quiet ) ) {
            echo PTK_Hub_UI::quiet_links( $quiet );
        }

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    public static function render_page() {
        if ( class_exists( 'PTK_Hub_Look' ) && PTK_Hub_Look::on() ) {
            self::render_new_home();
            return;
        }

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
