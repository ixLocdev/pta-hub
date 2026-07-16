<?php
/**
 * School color palette + Owner column + Ours/From-Council/All filters.
 *
 * Each school (and the Council) gets a distinct, Council-editable color so the
 * entry lists are scannable at a glance. Colors are stored NETWORK-WIDE as the
 * site option `ptk_site_colors` ({ blog_id: hex }) so every subsite's Owner
 * column reflects the Council-set palette. A deterministic default palette means
 * a fresh network already looks distinct with zero configuration.
 *
 * Display-only. The read-only lock lives in PTK_Content_Lock; the sync engine in
 * PTK_Multisite.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Site_Colors {

    /**
     * Approved palette. Council slate is first; the remaining ten are assigned
     * to school sites in blog_id order (cycling if a network exceeds ten).
     */
    const DEFAULT_PALETTE = array(
        '#475569', // Council slate
        '#2563eb',
        '#dc2626',
        '#ea580c',
        '#d97706',
        '#16a34a',
        '#0d9488',
        '#0891b2',
        '#7c3aed',
        '#db2777',
        '#4338ca',
    );

    const OPTION = 'ptk_site_colors';

    /** @var string Settings-page hook suffix, captured on registration. */
    private static $settings_hook = '';

    public static function init() {
        // Council-only settings screen (core is_main_site: true on single-site).
        if ( is_main_site() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
        }

        // Owner column — register LATE (99) so it APPENDS after
        // PTK_Admin_Helpers::custom_columns() rebuilds the whitelist at the
        // default priority (which would otherwise drop anything added earlier).
        add_filter( 'manage_pta_knowledge_posts_columns', array( __CLASS__, 'add_owner_column' ), 99 );
        add_action( 'manage_pta_knowledge_posts_custom_column', array( __CLASS__, 'render_owner_column' ), 10, 2 );

        // Ours | From Council | All filters.
        add_filter( 'views_edit-pta_knowledge', array( __CLASS__, 'owner_views' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_owner' ) );

        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css' ) );
    }

    /* ------------------------------------------------------------------
     * Palette / colors
     * ----------------------------------------------------------------*/

    /**
     * Compute the default blog_id => hex map (before any stored overrides).
     *
     * @return array<int,string>
     */
    public static function default_color_map() {
        $palette = self::DEFAULT_PALETTE;

        if ( ! is_multisite() ) {
            return array( (int) get_current_blog_id() => $palette[0] );
        }

        $main_id = (int) get_main_site_id();
        $map     = array( $main_id => $palette[0] );

        $sites = get_sites( array(
            'number'       => 500,
            'archived'     => 0,
            'deleted'      => 0,
            'site__not_in' => array( $main_id ),
            'orderby'      => 'id',
            'order'        => 'ASC',
        ) );

        $extras = count( $palette ) - 1; // available school colors
        $i      = 0;
        foreach ( $sites as $site ) {
            $blog_id         = (int) ( is_object( $site ) ? $site->blog_id : $site );
            $map[ $blog_id ] = $palette[ 1 + ( $i % $extras ) ];
            $i++;
        }

        return $map;
    }

    /**
     * All site colors: stored network option merged over computed defaults.
     *
     * @return array<int,string>
     */
    public static function get_colors() {
        $stored = get_site_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $clean = array();
        foreach ( $stored as $blog_id => $hex ) {
            if ( self::is_hex( $hex ) ) {
                $clean[ (int) $blog_id ] = strtolower( $hex );
            }
        }
        return $clean + self::default_color_map();
    }

    /**
     * Resolve one site's color (stored, else deterministic default).
     *
     * @return string Hex color.
     */
    public static function color_for( $blog_id ) {
        $blog_id = (int) $blog_id;
        $colors  = self::get_colors();
        if ( isset( $colors[ $blog_id ] ) ) {
            return $colors[ $blog_id ];
        }
        // A site not in the current default map (e.g. queried mid-provision):
        // fall back to the Council slate rather than an empty string.
        return self::DEFAULT_PALETTE[0];
    }

    private static function is_hex( $value ) {
        return is_string( $value ) && (bool) preg_match( '/^#[0-9a-fA-F]{6}$/', $value );
    }

    /**
     * A site's display name, safely.
     *
     * @return string Unescaped name (callers escape).
     */
    public static function site_name( $blog_id ) {
        $blog_id = (int) $blog_id;
        if ( is_multisite() ) {
            $details = get_blog_details( $blog_id );
            if ( $details && $details->blogname ) {
                return $details->blogname;
            }
            return 'Site #' . $blog_id;
        }
        return get_bloginfo( 'name' );
    }

    /* ------------------------------------------------------------------
     * Settings screen (Council)
     * ----------------------------------------------------------------*/

    public static function add_settings_page() {
        self::$settings_hook = add_submenu_page(
            'edit.php?post_type=pta_knowledge',
            'School Colors',
            'School Colors',
            'manage_options',
            'ptk-school-colors',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) || ! is_main_site() ) {
            wp_die( esc_html__( 'You do not have permission to manage school colors.', 'pta-knowledge-hub' ) );
        }

        $saved = false;
        if ( isset( $_POST['ptk_school_colors_submit'] ) ) {
            check_admin_referer( 'ptk_school_colors' );
            if ( current_user_can( 'manage_options' ) ) {
                $submitted = isset( $_POST['ptk_site_color'] ) && is_array( $_POST['ptk_site_color'] )
                    ? wp_unslash( $_POST['ptk_site_color'] )
                    : array();
                $to_store = array();
                foreach ( $submitted as $blog_id => $hex ) {
                    if ( self::is_hex( $hex ) ) {
                        $to_store[ (int) $blog_id ] = strtolower( $hex );
                    }
                }
                update_site_option( self::OPTION, $to_store );
                $saved = true;
            }
        }

        $colors = self::get_colors();

        // Every site, main first.
        if ( is_multisite() ) {
            $sites = get_sites( array(
                'number'   => 500,
                'archived' => 0,
                'deleted'  => 0,
                'orderby'  => 'id',
                'order'    => 'ASC',
            ) );
            $main_id  = (int) get_main_site_id();
            $blog_ids = array();
            foreach ( $sites as $site ) {
                $blog_ids[] = (int) ( is_object( $site ) ? $site->blog_id : $site );
            }
            // Main site first.
            $blog_ids = array_values( array_unique( array_merge( array( $main_id ), $blog_ids ) ) );
        } else {
            $blog_ids = array( (int) get_current_blog_id() );
        }
        ?>
        <div class="wrap ptk-school-colors">
            <h1><?php esc_html_e( 'School Colors', 'pta-knowledge-hub' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Assign each school a distinct color. The colored dot appears next to every entry in the Owner column across all school sites, so you can tell at a glance who owns each entry.', 'pta-knowledge-hub' ); ?>
            </p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'School colors saved.', 'pta-knowledge-hub' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'ptk_school_colors' ); ?>
                <table class="widefat striped ptk-colors-table" style="max-width:560px;margin-top:12px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'School', 'pta-knowledge-hub' ); ?></th>
                            <th><?php esc_html_e( 'Preview', 'pta-knowledge-hub' ); ?></th>
                            <th><?php esc_html_e( 'Color', 'pta-knowledge-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $blog_ids as $blog_id ) :
                            $hex     = isset( $colors[ $blog_id ] ) ? $colors[ $blog_id ] : self::DEFAULT_PALETTE[0];
                            $name    = self::site_name( $blog_id );
                            $is_main = is_multisite() ? ( $blog_id === (int) get_main_site_id() ) : true;
                        ?>
                        <tr>
                            <td>
                                <?php if ( $is_main ) : ?>
                                    <strong><?php echo esc_html( $name ); ?></strong>
                                    <span class="ptk-council-tag"><?php esc_html_e( 'PTA Council', 'pta-knowledge-hub' ); ?></span>
                                <?php else : ?>
                                    <?php echo esc_html( $name ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ptk-color-dot" style="background:<?php echo esc_attr( $hex ); ?>;"></span>
                            </td>
                            <td>
                                <input type="color"
                                    name="ptk_site_color[<?php echo esc_attr( $blog_id ); ?>]"
                                    value="<?php echo esc_attr( $hex ); ?>" />
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="submit" name="ptk_school_colors_submit" class="button button-primary">
                        <?php esc_html_e( 'Save Colors', 'pta-knowledge-hub' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Owner column
     * ----------------------------------------------------------------*/

    /**
     * APPEND an Owner column (does not replace the array).
     */
    public static function add_owner_column( $columns ) {
        $columns['ptk_owner'] = esc_html__( 'Owner', 'pta-knowledge-hub' );
        return $columns;
    }

    public static function render_owner_column( $column, $post_id ) {
        if ( 'ptk_owner' !== $column ) {
            return;
        }

        if ( class_exists( 'PTK_Multisite' ) && PTK_Multisite::is_network_copy( $post_id ) ) {
            $council_id = is_multisite() ? (int) get_main_site_id() : (int) get_current_blog_id();
            $color      = self::color_for( $council_id );
            echo '<span class="ptk-owner-badge">';
            echo '<span class="ptk-color-dot" style="background:' . esc_attr( $color ) . ';"></span>';
            echo '<span class="ptk-owner-lock" aria-hidden="true">&#128274;</span> ';
            echo '<strong>' . esc_html__( 'PTA Council', 'pta-knowledge-hub' ) . '</strong>';
            echo '</span>';
            return;
        }

        $blog_id = (int) get_current_blog_id();
        $color   = self::color_for( $blog_id );
        $name    = self::site_name( $blog_id );
        echo '<span class="ptk-owner-badge">';
        echo '<span class="ptk-color-dot" style="background:' . esc_attr( $color ) . ';"></span>';
        echo '<strong>' . esc_html( $name ) . '</strong>';
        echo '</span>';
    }

    /* ------------------------------------------------------------------
     * Ours | From Council | All filters
     * ----------------------------------------------------------------*/

    public static function owner_views( $views ) {
        $base = admin_url( 'edit.php?post_type=pta_knowledge' );

        $current = isset( $_GET['ptk_owner'] ) ? sanitize_key( wp_unslash( $_GET['ptk_owner'] ) ) : '';

        $counts = self::owner_counts();

        $links = array(
            'ours' => array(
                'label' => esc_html__( 'Ours', 'pta-knowledge-hub' ),
                'url'   => add_query_arg( 'ptk_owner', 'ours', $base ),
                'count' => $counts['ours'],
            ),
            'council' => array(
                'label' => esc_html__( 'From Council', 'pta-knowledge-hub' ),
                'url'   => add_query_arg( 'ptk_owner', 'council', $base ),
                'count' => $counts['council'],
            ),
            'all' => array(
                'label' => esc_html__( 'All', 'pta-knowledge-hub' ),
                'url'   => $base,
                'count' => $counts['all'],
            ),
        );

        $out = array();
        foreach ( $links as $key => $link ) {
            $is_current = ( 'all' === $key ) ? ( '' === $current ) : ( $current === $key );
            $out[ 'ptk_owner_' . $key ] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url( $link['url'] ),
                $is_current ? ' class="current" aria-current="page"' : '',
                $link['label'],
                (int) $link['count']
            );
        }

        // Prepend our owner views ahead of the default status views.
        return $out + $views;
    }

    /**
     * Counts for the owner views.
     *
     * @return array{ours:int,council:int,all:int}
     */
    private static function owner_counts() {
        $all = new WP_Query( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );

        $council = new WP_Query( array(
            'post_type'      => 'pta_knowledge',
            'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => array(
                array(
                    'key'     => 'ptk_network_source',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );

        $all_count     = (int) $all->found_posts;
        $council_count = (int) $council->found_posts;

        return array(
            'ours'    => max( 0, $all_count - $council_count ),
            'council' => $council_count,
            'all'     => $all_count,
        );
    }

    /**
     * Translate the ptk_owner query var into a meta_query on the copy meta.
     */
    public static function filter_by_owner( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( 'pta_knowledge' !== $query->get( 'post_type' ) ) {
            return;
        }
        $owner = isset( $_GET['ptk_owner'] ) ? sanitize_key( wp_unslash( $_GET['ptk_owner'] ) ) : '';
        if ( 'ours' !== $owner && 'council' !== $owner ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        if ( 'ours' === $owner ) {
            $meta_query[] = array(
                'key'     => 'ptk_network_source',
                'compare' => 'NOT EXISTS',
            );
        } else {
            $meta_query[] = array(
                'key'     => 'ptk_network_source',
                'compare' => 'EXISTS',
            );
        }
        $query->set( 'meta_query', $meta_query );
    }

    /* ------------------------------------------------------------------
     * Assets
     * ----------------------------------------------------------------*/

    public static function enqueue_css( $hook ) {
        $on_list     = ( 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'pta_knowledge' === $_GET['post_type'] );
        $on_settings = ( self::$settings_hook && $hook === self::$settings_hook );

        if ( ! $on_list && ! $on_settings ) {
            return;
        }

        wp_enqueue_style(
            'ptk-site-colors',
            PTK_PLUGIN_URL . 'assets/css/site-colors.css',
            array(),
            PTK_VERSION
        );
    }
}

/**
 * Global helper: resolve a site's palette color (stored or default).
 *
 * @param int $blog_id Blog ID.
 * @return string Hex color.
 */
if ( ! function_exists( 'ptk_site_color' ) ) {
    function ptk_site_color( $blog_id ) {
        return PTK_Site_Colors::color_for( $blog_id );
    }
}
