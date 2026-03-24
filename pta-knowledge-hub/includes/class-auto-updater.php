<?php
/**
 * Self-hosted auto-updater for PTA Knowledge Hub.
 *
 * Checks a remote JSON manifest for the latest version and hooks into
 * WordPress's native plugin update system. Admins see "Update Available"
 * in the Plugins list and can update with one click.
 *
 * Remote manifest URL: https://ixcreations.com/PTAC Updates/update-info.json
 * Remote zip URL:      https://ixcreations.com/PTAC Updates/pta-knowledge-hub.zip
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Auto_Updater {

    /** @var string URL of the JSON manifest file. */
    const MANIFEST_URL = 'https://ixcreations.com/PTAC%20Updates/update-info.json';

    /** @var string Plugin basename (e.g. pta-knowledge-hub/pta-knowledge-hub.php). */
    private static $plugin_basename;

    /**
     * Register hooks.
     */
    public static function init() {
        self::$plugin_basename = plugin_basename( PTK_PLUGIN_DIR . 'pta-knowledge-hub.php' );

        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( __CLASS__, 'after_install' ), 10, 3 );
    }

    /**
     * Check the remote manifest for a newer version.
     *
     * @param object $transient The update_plugins transient.
     * @return object Modified transient with our update info if available.
     */
    public static function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = self::get_remote_manifest();
        if ( ! $remote ) {
            return $transient;
        }

        $current_version = PTK_VERSION;

        if ( version_compare( $remote->version, $current_version, '>' ) ) {
            $transient->response[ self::$plugin_basename ] = (object) array(
                'slug'        => 'pta-knowledge-hub',
                'plugin'      => self::$plugin_basename,
                'new_version' => $remote->version,
                'url'         => isset( $remote->homepage ) ? $remote->homepage : '',
                'package'     => $remote->download_url,
                'tested'      => isset( $remote->tested ) ? $remote->tested : '',
                'requires'    => isset( $remote->requires ) ? $remote->requires : '',
            );
        } else {
            // No update — make sure we're not stuck in the response array.
            unset( $transient->response[ self::$plugin_basename ] );
            $transient->no_update[ self::$plugin_basename ] = (object) array(
                'slug'        => 'pta-knowledge-hub',
                'plugin'      => self::$plugin_basename,
                'new_version' => $current_version,
                'url'         => '',
                'package'     => '',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" modal in the Plugins page.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || 'pta-knowledge-hub' !== $args->slug ) {
            return $result;
        }

        $remote = self::get_remote_manifest();
        if ( ! $remote ) {
            return $result;
        }

        $info = new stdClass();
        $info->name          = 'PTA Knowledge Hub';
        $info->slug          = 'pta-knowledge-hub';
        $info->version       = $remote->version;
        $info->author        = '<a href="https://ixcreations.com">Lucas Deichl</a>';
        $info->homepage      = isset( $remote->homepage ) ? $remote->homepage : 'https://ixcreations.com';
        $info->download_link = $remote->download_url;
        $info->tested        = isset( $remote->tested ) ? $remote->tested : '';
        $info->requires      = isset( $remote->requires ) ? $remote->requires : '6.0';
        $info->requires_php  = isset( $remote->requires_php ) ? $remote->requires_php : '7.4';
        $info->last_updated  = isset( $remote->last_updated ) ? $remote->last_updated : '';

        if ( isset( $remote->sections ) ) {
            $info->sections = (array) $remote->sections;
        } else {
            $info->sections = array(
                'description' => 'A searchable knowledge base for your PTA. Volunteers add content through WordPress, parents and members find answers instantly.',
                'changelog'   => isset( $remote->changelog ) ? $remote->changelog : 'See release notes.',
            );
        }

        return $info;
    }

    /**
     * After install, make sure the plugin folder name stays correct.
     *
     * WordPress sometimes renames the extracted folder. This ensures
     * it stays as "pta-knowledge-hub".
     *
     * @param bool  $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public static function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || self::$plugin_basename !== $hook_extra['plugin'] ) {
            return $result;
        }

        global $wp_filesystem;

        $proper_destination = WP_PLUGIN_DIR . '/pta-knowledge-hub';
        $installed_to       = $result['destination'];

        // If the extracted folder name doesn't match, rename it.
        if ( $installed_to !== $proper_destination ) {
            $wp_filesystem->move( $installed_to, $proper_destination );
            $result['destination']    = $proper_destination;
            $result['destination_name'] = 'pta-knowledge-hub';
        }

        // Re-activate plugin after update.
        if ( is_multisite() && is_plugin_active_for_network( self::$plugin_basename ) ) {
            activate_plugin( self::$plugin_basename, '', true );
        } elseif ( is_plugin_active( self::$plugin_basename ) ) {
            activate_plugin( self::$plugin_basename );
        }

        return $result;
    }

    /**
     * Fetch and cache the remote manifest JSON.
     *
     * @return object|false Decoded manifest or false on failure.
     */
    private static function get_remote_manifest() {
        $cache_key = 'ptk_update_manifest';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( self::MANIFEST_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure for 30 minutes so we don't hammer the server.
            set_transient( $cache_key, false, 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        if ( ! $data || ! isset( $data->version ) || ! isset( $data->download_url ) ) {
            set_transient( $cache_key, false, 30 * MINUTE_IN_SECONDS );
            return false;
        }

        // Cache for 6 hours.
        set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

        return $data;
    }
}
