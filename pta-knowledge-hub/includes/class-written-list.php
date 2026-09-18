<?php
/**
 * "What you've written" -- the Hub's own replacement for WordPress's plain
 * entries list, spec docs/superpowers/specs/2026-09-18-what-youve-written-design.md.
 *
 * A second door only: WordPress's own edit.php list is untouched and still
 * reachable. This page exists only when PTK_Hub_Look::on() -- see add_page()
 * -- and is registered as its own admin screen ('ptk-written'), never a
 * restyled list table.
 *
 * The methods below are split the same way as PTK_Entry_Type and
 * PTK_Simple_Mode: pure, WordPress-free helpers first (safe to unit test
 * with plain php -- see tests/test-written-list.php), then the
 * WordPress-facing methods that call them.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Written_List {

    /** This screen's own page slug -- a real admin page, not a list-table view. */
    const PAGE_SLUG = 'ptk-written';

    /** admin_post action / nonce action for removing an entry (WordPress's own trash). */
    const TRASH_ACTION = 'ptk_written_trash';

    /** admin_post action / nonce action for undoing that (WordPress's own untrash). */
    const UNTRASH_ACTION = 'ptk_written_untrash';

    /** The quiet filters, in display order. */
    const FILTERS = array( 'all', 'ours', 'council', 'draft' );

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_post_' . self::TRASH_ACTION, array( __CLASS__, 'handle_trash' ) );
        add_action( 'admin_post_' . self::UNTRASH_ACTION, array( __CLASS__, 'handle_untrash' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /* ------------------------------------------------------------------
     * Pure helpers -- no WordPress calls, no state, no side effects.
     * ----------------------------------------------------------------*/

    /**
     * Pure: trim plain text at a word boundary, never mid-word. $text is
     * assumed already stripped of tags and collapsed to single spaces by
     * the caller (WordPress-facing shape_entry() does that with
     * wp_strip_all_tags() before calling this).
     *
     * @param string $text
     * @param int    $max_chars
     * @return string
     */
    public static function trim_answer( $text, $max_chars = 140 ) {
        $text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
        if ( '' === $text ) {
            return '';
        }
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $max_chars : strlen( $text ) <= $max_chars ) {
            return $text;
        }
        $cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_chars ) : substr( $text, 0, $max_chars );
        $at  = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
        if ( false !== $at && $at > 0 ) {
            $cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $at ) : substr( $cut, 0, $at );
        }
        return rtrim( $cut, " ,.;:-\xE2\x80\x94" ) . "\xE2\x80\xA6"; // trailing … (U+2026)
    }

    /**
     * Pure: the quiet meta line -- "Filed as a how-to guide · updated Sep
     * 14", plus " · From the Council" when the entry isn't this school's
     * own. The NOT SENT YET stamp is rendered separately (see render()) so
     * a card never carries more than one stamp.
     *
     * @param string $type_label     e.g. "how-to guide", from PTK_Entry_Type::names().
     * @param string $modified_label e.g. "Sep 14", already formatted by the caller.
     * @param bool   $is_council
     * @return string
     */
    public static function meta_line( $type_label, $modified_label, $is_council ) {
        $type_label     = trim( (string) $type_label );
        $modified_label = trim( (string) $modified_label );
        if ( '' === $type_label ) {
            $type_label = 'entry';
        }
        $line = 'Filed as a ' . $type_label;
        if ( '' !== $modified_label ) {
            $line .= " \xC2\xB7 updated " . $modified_label;
        }
        if ( $is_council ) {
            $line .= " \xC2\xB7 From the Council";
        }
        return $line;
    }

    /**
     * Pure: which quiet filters make sense on this site. "From the
     * Council" only ever applies where Council-shared copies can exist at
     * all -- a multisite subsite. Order matches the spec: Everything ·
     * Ours · From the Council · Not sent yet.
     *
     * @param bool $council_possible Whatever tells the caller Council entries can exist here.
     * @return string[] a subset of self::FILTERS, in order.
     */
    public static function available_filters( $council_possible ) {
        $filters = array( 'all', 'ours' );
        if ( $council_possible ) {
            $filters[] = 'council';
        }
        $filters[] = 'draft';
        return $filters;
    }

    /** Pure: the label for a filter key. Unknown keys fall back to "Everything". */
    public static function filter_label( $key ) {
        $labels = array(
            'all'     => 'Everything',
            'ours'    => 'Ours',
            'council' => 'From the Council',
            'draft'   => 'Not sent yet',
        );
        return isset( $labels[ $key ] ) ? $labels[ $key ] : $labels['all'];
    }

    /**
     * Pure: does this entry belong under the given quiet filter?
     *
     * @param array  $entry array( 'is_draft' => bool, 'is_council' => bool ).
     * @param string $filter one of self::FILTERS; anything else behaves like 'all'.
     */
    public static function matches_filter( array $entry, $filter ) {
        $is_draft   = ! empty( $entry['is_draft'] );
        $is_council = ! empty( $entry['is_council'] );
        switch ( $filter ) {
            case 'ours':
                return ! $is_council;
            case 'council':
                return $is_council;
            case 'draft':
                return $is_draft;
            default:
                return true;
        }
    }

    /**
     * Pure: build the lowercase haystack a search box matches against, from
     * the entry's own words -- the question, the answer, and whatever else
     * people might search for (category name).
     *
     * @param string $question
     * @param string $answer
     * @param string $extra e.g. the category label.
     * @return string
     */
    public static function search_haystack( $question, $answer, $extra = '' ) {
        $joined = trim( (string) $question ) . ' ' . trim( (string) $answer ) . ' ' . trim( (string) $extra );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $joined ) ) : strtolower( trim( $joined ) );
    }

    /**
     * Pure: does a search box query match a pre-lowercased haystack? An
     * empty query always matches -- "no word typed yet" is not a filter.
     *
     * @param string $haystack_lower Already lowercased (search_haystack()).
     * @param string $query          Raw, as typed; case-folded here.
     */
    public static function matches_search( $haystack_lower, $query ) {
        $query = trim( (string) $query );
        if ( '' === $query ) {
            return true;
        }
        $query = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
        return false !== strpos( (string) $haystack_lower, $query );
    }

    /**
     * Pure: can this person change this entry's details? Today this is
     * exactly what WordPress's own edit_post capability says (map_meta_cap,
     * via PTK_Content_Lock, already denies it for a Council-shared copy on
     * a school site) -- kept as its own named helper, tested on its own, so
     * the card's "Change the details" vs. "The Council wrote this" branch
     * is never decided ad hoc at the render call site.
     *
     * @param bool $user_can_edit_post current_user_can( 'edit_post', $id ).
     */
    public static function can_change( $user_can_edit_post ) {
        return (bool) $user_can_edit_post;
    }

    /**
     * Pure: the empty-state copy. Two different messages -- nothing
     * written yet at all, vs. a search/filter that matched nothing.
     *
     * @param bool   $has_any_entries Any entries at all, before search/filter.
     * @param string $search          The search query in effect, if any.
     * @return array array( 'title' => string, 'link_text' => string ).
     */
    public static function empty_state_copy( $has_any_entries, $search ) {
        $search = trim( (string) $search );
        if ( ! $has_any_entries ) {
            return array(
                'title'     => 'Nothing here yet.',
                'link_text' => "Answer a question families keep asking \xE2\x86\x92",
            );
        }
        if ( '' !== $search ) {
            return array(
                'title'     => "Nothing matched \xE2\x80\x9C" . $search . "\xE2\x80\x9D.",
                'link_text' => "Try a different word, or answer that question now \xE2\x86\x92",
            );
        }
        return array(
            'title'     => 'Nothing matched.',
            'link_text' => "Try a different word, or answer that question now \xE2\x86\x92",
        );
    }

    /* ------------------------------------------------------------------
     * WordPress-facing.
     * ----------------------------------------------------------------*/

    /** Canonical admin URL of this screen. Same edit.php submenu shape as the wizard. */
    public static function url() {
        return admin_url( 'edit.php?post_type=pta_knowledge&page=' . self::PAGE_SLUG );
    }

    /**
     * Register this screen -- only while the new look is on. With it off,
     * this never runs: no menu entry, no page, nothing registered.
     */
    public static function add_page() {
        if ( ! class_exists( 'PTK_Hub_Look' ) || ! PTK_Hub_Look::on() ) {
            return;
        }
        add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            "What you've written",
            "What you've written",
            'edit_posts',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
            return;
        }
        wp_enqueue_script(
            'ptk-written-list',
            PTK_PLUGIN_URL . 'assets/js/written-list.js',
            array(),
            PTK_VERSION,
            true
        );
    }

    /**
     * Shape one WP_Post into the flat array the card renderer and the pure
     * helpers above need. Reads live WordPress state; nothing here is pure.
     */
    private static function shape_entry( $post ) {
        $id = $post->ID;

        $slug       = '';
        $terms      = wp_get_post_terms( $id, 'knowledge_category', array( 'fields' => 'slugs' ) );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            $slug = (string) $terms[0];
        }
        $names      = class_exists( 'PTK_Entry_Type' ) ? PTK_Entry_Type::names() : array();
        $type_label = isset( $names[ $slug ] ) ? $names[ $slug ] : 'entry';

        $is_council = class_exists( 'PTK_Multisite' ) && PTK_Multisite::is_network_copy( $id );
        $is_draft   = 'draft' === $post->post_status;
        $can_edit   = current_user_can( 'edit_post', $id );
        $can_delete = current_user_can( 'delete_post', $id );

        $answer = '' !== trim( (string) $post->post_excerpt )
            ? $post->post_excerpt
            : wp_strip_all_tags( $post->post_content );

        $modified_label = date_i18n( 'M j', strtotime( $post->post_modified ) );

        $permalink = $is_draft ? get_preview_post_link( $id ) : get_permalink( $id );

        return array(
            'id'              => $id,
            'question'        => get_the_title( $post ),
            'answer_trimmed'  => self::trim_answer( wp_strip_all_tags( $answer ) ),
            'type_label'      => $type_label,
            'modified_label'  => $modified_label,
            'is_council'      => $is_council,
            'is_draft'        => $is_draft,
            'can_edit'        => $can_edit,
            'can_delete'      => $can_delete,
            'permalink'       => $permalink,
            'edit_url'        => class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_edit_id', $id, PTK_Content_Wizard::url() ) : get_edit_post_link( $id, '' ),
            'search_haystack' => self::search_haystack( get_the_title( $post ), wp_strip_all_tags( $answer ), $type_label ),
        );
    }

    /**
     * Query every pta_knowledge entry the current user may see -- 'perm' =>
     * 'readable' is WordPress's own capability filter for non-published
     * statuses, and 's' goes straight into WP_Query, which escapes it
     * itself; nothing here builds SQL by hand.
     *
     * @param string $search Sanitized search text (may be '').
     * @return array Shaped entries (see shape_entry()).
     */
    public static function get_entries( $search = '' ) {
        $args = array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => array( 'publish', 'draft' ),
            'perm'           => 'readable',
            'posts_per_page' => 300,
            'no_found_rows'  => true,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );
        if ( '' !== $search ) {
            $args['s'] = $search;
        }
        $query   = new WP_Query( $args );
        $entries = array();
        foreach ( $query->posts as $post ) {
            $entries[] = self::shape_entry( $post );
        }
        return $entries;
    }

    /** Render the screen. */
    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( "You don't have permission to see this.", 'Not allowed', array( 'response' => 403 ) );
        }

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $filter = isset( $_GET['ptk_filter'] ) ? sanitize_key( wp_unslash( $_GET['ptk_filter'] ) ) : 'all';
        if ( ! in_array( $filter, self::FILTERS, true ) ) {
            $filter = 'all';
        }

        $all_entries = self::get_entries( '' );
        $council_possible = is_multisite();
        foreach ( $all_entries as $e ) {
            if ( $e['is_council'] ) {
                $council_possible = true;
            }
        }
        $filters = self::available_filters( $council_possible );

        $entries = self::get_entries( $search );
        $entries = array_values( array_filter( $entries, function ( $e ) use ( $filter ) {
            return self::matches_filter( $e, $filter );
        } ) );

        echo '<div class="wrap">';
        echo PTK_Hub_UI::page_open( "What you've written" );

        self::render_removed_notice();

        echo '<form method="get" class="ptk-written-search-form" id="ptk-written-search-form">';
        echo '<input type="hidden" name="post_type" value="pta_knowledge">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<input type="search" name="s" id="ptk-written-search" class="ptk-written-search" placeholder="Type a word you remember." value="' . esc_attr( $search ) . '">';
        echo '</form>';

        echo '<div class="ptk-written-filters" id="ptk-written-filters" role="group" aria-label="Filter what you\'ve written">';
        foreach ( $filters as $key ) {
            $active = ( $key === $filter );
            $url    = add_query_arg( array(
                'post_type'  => 'pta_knowledge',
                'page'       => self::PAGE_SLUG,
                'ptk_filter' => $key,
                's'          => $search,
            ), admin_url( 'edit.php' ) );
            printf(
                '<a href="%1$s" class="ptk-written-filter%2$s" data-ptk-filter="%3$s"%4$s>%5$s</a>',
                esc_url( $url ),
                $active ? ' ptk-written-filter--active' : '',
                esc_attr( $key ),
                $active ? ' aria-current="true"' : '',
                esc_html( self::filter_label( $key ) )
            );
        }
        echo '</div>';

        echo '<div class="ptk-written-list" id="ptk-written-list">';
        if ( empty( $entries ) ) {
            $copy = self::empty_state_copy( ! empty( $all_entries ), $search );
            echo '<div class="ptk-empty" id="ptk-written-empty">';
            echo '<p class="ptk-card-title">' . esc_html( $copy['title'] ) . '</p>';
            echo '<p class="ptk-help"><a href="' . esc_url( class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_for', 'question', PTK_Content_Wizard::url() ) : admin_url() ) . '">' . esc_html( $copy['link_text'] ) . '</a></p>';
            echo '</div>';
        } else {
            foreach ( $entries as $entry ) {
                echo self::render_card( $entry );
            }
            // Hidden until JS's own live search/filter (assets/js/written-list.js)
            // hides every card in place -- the server-rendered empty state above
            // only covers the initial page load.
            echo '<div class="ptk-empty" id="ptk-written-no-match" hidden>';
            echo '<p class="ptk-card-title" id="ptk-written-no-match-title">Nothing matched.</p>';
            echo '<p class="ptk-help"><a href="' . esc_url( class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_for', 'question', PTK_Content_Wizard::url() ) : admin_url() ) . '">Try a different word, or answer that question now &#8594;</a></p>';
            echo '</div>';
        }
        echo '</div>';

        echo PTK_Hub_UI::next_steps( array(
            array( 'label' => 'Answer another question', 'url' => class_exists( 'PTK_Content_Wizard' ) ? add_query_arg( 'ptk_for', 'question', PTK_Content_Wizard::url() ) : '' ),
            array( 'label' => 'Tell families about it', 'url' => class_exists( 'PTK_Newsletter_Builder' ) ? PTK_Newsletter_Builder::url() : '' ),
            array( 'label' => "I'm done", 'url' => class_exists( 'PTK_Welcome' ) ? admin_url( 'edit.php?post_type=pta_knowledge&page=ptk-welcome' ) : admin_url() ),
        ) );

        echo PTK_Hub_UI::page_close();
        echo '</div>';
    }

    /** One card's markup for a shaped entry (see shape_entry()). */
    private static function render_card( array $entry ) {
        $out  = '<article class="ptk-entry-card" data-ptk-key="' . esc_attr( $entry['id'] ) . '" data-ptk-search="' . esc_attr( $entry['search_haystack'] ) . '" data-ptk-council="' . ( $entry['is_council'] ? '1' : '0' ) . '" data-ptk-draft="' . ( $entry['is_draft'] ? '1' : '0' ) . '">';
        $out .= '<h2 class="ptk-entry-question">' . esc_html( $entry['question'] ) . '</h2>';
        if ( '' !== $entry['answer_trimmed'] ) {
            $out .= '<p class="ptk-entry-answer">' . esc_html( $entry['answer_trimmed'] ) . '</p>';
        }

        $meta = self::meta_line( $entry['type_label'], $entry['modified_label'], $entry['is_council'] );
        $out .= '<p class="ptk-entry-meta">' . esc_html( $meta );
        if ( $entry['is_draft'] ) {
            $out .= ' ' . PTK_Hub_UI::stamp( 'NOT SENT YET', 'warning' );
        }
        $out .= '</p>';

        $out .= '<div class="ptk-entry-actions">';
        if ( self::can_change( $entry['can_edit'] ) ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $entry['edit_url'] ) . '">Change the details</a>';
        } else {
            $out .= '<span class="ptk-entry-locked">The Council wrote this &mdash; ask them to change it.</span>';
        }
        if ( $entry['permalink'] ) {
            $out .= '<a class="ptk-btn" href="' . esc_url( $entry['permalink'] ) . '" target="_blank" rel="noopener">See it on the Hub</a>';
        }
        $out .= '</div>';

        if ( $entry['can_delete'] ) {
            $nonce = wp_create_nonce( self::TRASH_ACTION );
            $out  .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ptk-entry-remove-row" data-ptk-remove-form>';
            $out  .= '<input type="hidden" name="action" value="' . esc_attr( self::TRASH_ACTION ) . '">';
            $out  .= '<input type="hidden" name="id" value="' . esc_attr( $entry['id'] ) . '">';
            $out  .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
            $out  .= '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
            $out  .= '<button type="submit" class="ptk-entry-remove-btn ptk-quiet-links">Remove it</button>';
            $out  .= '<span class="ptk-entry-remove-confirm" hidden>Remove this? Families won&rsquo;t see it any more. <button type="submit" class="ptk-entry-remove-yes">Yes, remove it</button> <button type="button" class="ptk-entry-remove-cancel">Cancel</button></span>';
            $out  .= '</form>';
        }

        $out .= '</article>';
        return $out;
    }

    /** The "Removed. Undo" banner, when we just came back from a trash action. */
    private static function render_removed_notice() {
        if ( empty( $_GET['ptk_removed'] ) ) {
            return;
        }
        $id = absint( $_GET['ptk_removed'] );
        if ( ! $id || 'pta_knowledge' !== get_post_type( $id ) || 'trash' !== get_post_status( $id ) ) {
            return;
        }
        if ( ! current_user_can( 'delete_post', $id ) ) {
            return;
        }
        $undo_url = wp_nonce_url(
            add_query_arg( array(
                'action'      => self::UNTRASH_ACTION,
                'id'          => $id,
                'redirect_to' => rawurlencode( self::current_url( true ) ),
            ), admin_url( 'admin-post.php' ) ),
            self::UNTRASH_ACTION
        );
        echo '<div class="ptk-written-notice"><p>Removed. <a href="' . esc_url( $undo_url ) . '">Undo</a></p></div>';
    }

    /** This screen's own current URL (query args minus ptk_removed), for "come back here" links. */
    private static function current_url( $drop_removed = false ) {
        $args = array(
            'post_type' => 'pta_knowledge',
            'page'      => self::PAGE_SLUG,
        );
        if ( isset( $_GET['s'] ) ) {
            $args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }
        if ( isset( $_GET['ptk_filter'] ) ) {
            $args['ptk_filter'] = sanitize_key( wp_unslash( $_GET['ptk_filter'] ) );
        }
        if ( ! $drop_removed && isset( $_GET['ptk_removed'] ) ) {
            $args['ptk_removed'] = absint( $_GET['ptk_removed'] );
        }
        return add_query_arg( $args, admin_url( 'edit.php' ) );
    }

    /** admin_post_ptk_written_trash: remove one entry (WordPress's own trash). */
    public static function handle_trash() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( 'You must be signed in to do that.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::TRASH_ACTION );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id || 'pta_knowledge' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
            wp_die( "You don't have permission to remove that.", 'Not allowed', array( 'response' => 403 ) );
        }

        wp_trash_post( $id );

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : self::url();
        $redirect = add_query_arg( 'ptk_removed', $id, remove_query_arg( 'ptk_removed', $redirect ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    /** admin_post_ptk_written_untrash: real Undo. */
    public static function handle_untrash() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( 'You must be signed in to do that.', 'Not allowed', array( 'response' => 403 ) );
        }
        check_admin_referer( self::UNTRASH_ACTION );

        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id || 'pta_knowledge' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
            wp_die( "You don't have permission to restore that.", 'Not allowed', array( 'response' => 403 ) );
        }

        wp_untrash_post( $id );

        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : self::url();
        wp_safe_redirect( $redirect );
        exit;
    }
}
