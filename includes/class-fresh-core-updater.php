<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Central Fresh Core distribution/update client.
 *
 * Human product page and machine-readable update manifest are separate on
 * purpose. The URLs are configurable from Tools > Fresh Core and via filters.
 */
final class Fresh_Core_Updater {
    const CACHE_KEY = 'fresh_core_update_manifest_cache';
    const DEFAULT_MANIFEST_URL = 'https://www.freshcrackers.cz/wp-content/uploads/fresh-crackers/fresh-core/update.json';
    const DEFAULT_PRODUCT_URL = 'https://www.freshcrackers.cz/produkt/fresh-core/';

    public static function init(): void {
        // Migrate only the previous unmodified default; respect custom server settings.
        $previous = 'https://www.freshcrackers.cz/wp-content/uploads/fresh-core/update.json';
        if ( $previous === get_option( 'fresh_core_update_manifest_url' ) ) {
            update_option( 'fresh_core_update_manifest_url', self::DEFAULT_MANIFEST_URL, false );
            self::clear_cache();
        }
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        // WordPress 5.8+ custom Update URI uses this provider-specific hook.
        // Without it, WP may ignore third-party releases for this plugin.
        add_filter( 'update_plugins_www.freshcrackers.cz', array( __CLASS__, 'provide_update_uri' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
        add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
        add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_package_hash' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_after_upgrade' ), 10, 2 );
    }

    public static function manifest_url(): string {
        $url = trim( (string) get_option( 'fresh_core_update_manifest_url', self::DEFAULT_MANIFEST_URL ) );
        if ( '' === $url ) { $url = self::DEFAULT_MANIFEST_URL; }
        return (string) apply_filters( 'fresh_core_update_manifest_url', $url );
    }

    public static function product_url(): string {
        $url = trim( (string) get_option( 'fresh_core_product_url', self::DEFAULT_PRODUCT_URL ) );
        if ( '' === $url ) { $url = self::DEFAULT_PRODUCT_URL; }
        return (string) apply_filters( 'fresh_core_product_url', $url );
    }

    public static function auto_enabled(): bool {
        return 'yes' === get_option( 'fresh_core_auto_updates', 'no' );
    }

    public static function clear_cache(): void {
        delete_site_transient( self::CACHE_KEY );
    }

    public static function fetch_manifest( bool $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) { return $cached; }
        }

        $url = esc_url_raw( self::manifest_url() );
        if ( ! $url ) { return new WP_Error( 'fresh_core_update_url_missing', 'Fresh Core update manifest URL is empty.' ); }
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || 'https' !== ( $parsed['scheme'] ?? '' ) ||
             empty( $parsed['host'] ) || ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ||
             ! empty( $parsed['query'] ) || ! empty( $parsed['fragment'] ) ||
             basename( (string) ( $parsed['path'] ?? '' ) ) !== 'update.json' ) {
            return new WP_Error( 'fresh_core_update_url_invalid', 'Update manifest must be an absolute HTTPS update.json URL without credentials or query parameters.' );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout' => 8,
            'redirection' => 3,
            'headers' => array( 'Accept' => 'application/json' ),
            'user-agent' => 'Fresh-Core/' . FRESH_CORE_VERSION . '; ' . home_url( '/' ),
        ) );
        update_option( 'fresh_core_update_last_check', gmdate( 'c' ), false );
        if ( is_wp_error( $response ) ) {
            update_option( 'fresh_core_update_last_error', $response->get_error_message(), false );
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $error = new WP_Error( 'fresh_core_update_http', 'Update server returned HTTP ' . $code . '.' );
            update_option( 'fresh_core_update_last_error', $error->get_error_message(), false );
            return $error;
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['version'] ) ) {
            $error = new WP_Error( 'fresh_core_update_invalid_json', 'Update manifest is missing a version.' );
            update_option( 'fresh_core_update_last_error', $error->get_error_message(), false );
            return $error;
        }
        $data['version'] = sanitize_text_field( (string) $data['version'] );
        if ( ! empty( $data['download_url'] ) ) { $data['download_url'] = esc_url_raw( (string) $data['download_url'] ); }
        if ( ! empty( $data['product_url'] ) ) { $data['product_url'] = esc_url_raw( (string) $data['product_url'] ); }
        $data['sha256'] = strtolower( trim( (string) ( $data['sha256'] ?? '' ) ) );
        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:\.\d+)?$/', $data['version'] ) ) {
            $error = new WP_Error( 'fresh_core_update_invalid_version', 'Update manifest version is not valid.' );
            update_option( 'fresh_core_update_last_error', $error->get_error_message(), false );
            return $error;
        }
        $package = self::validate_package( $data, $url );
        if ( is_wp_error( $package ) ) {
            update_option( 'fresh_core_update_last_error', $package->get_error_message(), false );
            return $package;
        }
        $data['download_url'] = $package;
        delete_option( 'fresh_core_update_last_error' );
        update_option( 'fresh_core_update_latest_version', $data['version'], false );
        set_site_transient( self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    public static function provide_update_uri( $update, array $plugin_data, string $plugin_file, $locales ) {
        if ( plugin_basename( FRESH_CORE_FILE ) !== $plugin_file ) { return $update; }
        $data = self::fetch_manifest( false );
        if ( is_wp_error( $data ) || ! is_array( $data ) ||
             version_compare( (string) $data['version'], FRESH_CORE_VERSION, '<=' ) ) { return false; }
        return array(
            'slug' => 'fresh-core',
            'version' => (string) $data['version'],
            'package' => (string) $data['download_url'],
            'url' => ! empty( $data['product_url'] ) ? (string) $data['product_url'] : self::product_url(),
            'requires' => (string) ( $data['requires'] ?? '6.2' ),
            'requires_php' => (string) ( $data['requires_php'] ?? '7.4' ),
        );
    }

    /** No cross-host or cross-directory downloads, and a mandatory checksum. */
    private static function validate_package( array $data, string $manifest_url ) {
        $uri = wp_parse_url( $manifest_url );
        $package_url = esc_url_raw( (string) ( $data['download_url'] ?? '' ) );
        $download = wp_parse_url( $package_url );
        if ( ! is_array( $uri ) || ! is_array( $download ) ||
            ( $uri['scheme'] ?? '' ) !== 'https' || ( $download['scheme'] ?? '' ) !== 'https' ||
            strtolower( (string) ( $uri['host'] ?? '' ) ) !== strtolower( (string) ( $download['host'] ?? '' ) ) ||
            (int) ( $uri['port'] ?? 443 ) !== (int) ( $download['port'] ?? 443 ) ||
            ! empty( $download['query'] ) || ! empty( $download['fragment'] ) ||
            (string) ( $download['path'] ?? '' ) !== trailingslashit( dirname( (string) ( $uri['path'] ?? '' ) ) ) . 'fresh-core.zip' ||
            ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $data['sha256'] ?? '' ) )
        ) {
            return new WP_Error( 'fresh_core_update_invalid_package', 'The update package must be fresh-core.zip in the manifest directory, on the same HTTPS host, with a valid SHA-256 digest.' );
        }
        return $package_url;
    }

    private static function update_object( array $data ): stdClass {
        $obj = new stdClass();
        $obj->slug = 'fresh-core';
        $obj->plugin = plugin_basename( FRESH_CORE_FILE );
        $obj->new_version = (string) $data['version'];
        $obj->url = ! empty( $data['product_url'] ) ? (string) $data['product_url'] : self::product_url();
        $obj->package = ! empty( $data['download_url'] ) ? (string) $data['download_url'] : '';
        if ( ! empty( $data['tested'] ) ) { $obj->tested = (string) $data['tested']; }
        if ( ! empty( $data['requires'] ) ) { $obj->requires = (string) $data['requires']; }
        if ( ! empty( $data['requires_php'] ) ) { $obj->requires_php = (string) $data['requires_php']; }
        return $obj;
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) { return $transient; }
        $data = self::fetch_manifest( false );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) { return $transient; }
        $basename = plugin_basename( FRESH_CORE_FILE );
        if ( version_compare( (string) $data['version'], FRESH_CORE_VERSION, '>' ) && ! empty( $data['download_url'] ) ) {
            $transient->response[ $basename ] = self::update_object( $data );
        } else {
            $current = $data;
            if ( version_compare( $current['version'], FRESH_CORE_VERSION, '<' ) ) { $current['version'] = FRESH_CORE_VERSION; }
            $transient->no_update[ $basename ] = self::update_object( $current );
            unset( $transient->response[ $basename ] );
        }
        return $transient;
    }

    public static function plugin_info( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || 'fresh-core' !== ( $args->slug ?? '' ) ) { return $result; }
        $data = self::fetch_manifest( false );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) { return $result; }
        $info = new stdClass();
        $info->name = 'Fresh Core';
        $info->slug = 'fresh-core';
        $info->version = (string) $data['version'];
        $info->author = '<a href="https://www.freshcrackers.cz/">Fresh Crackers</a>';
        $info->homepage = ! empty( $data['product_url'] ) ? (string) $data['product_url'] : self::product_url();
        $info->download_link = ! empty( $data['download_url'] ) ? (string) $data['download_url'] : '';
        $info->requires = (string) ( $data['requires'] ?? '6.2' );
        $info->requires_php = (string) ( $data['requires_php'] ?? '7.4' );
        $info->tested = (string) ( $data['tested'] ?? '' );
        $description = (string) ( $data['description'] ?? 'Shared integration layer for Fresh WordPress projects and Fresh workers.' );
        $changelog = (string) ( $data['changelog'] ?? '' );
        $info->sections = array( 'description' => wp_kses_post( $description ), 'changelog' => wp_kses_post( nl2br( $changelog ) ) );
        return $info;
    }

    public static function row_meta( array $links, string $file ): array {
        if ( plugin_basename( FRESH_CORE_FILE ) !== $file ) { return $links; }
        $links[] = '<a href="' . esc_url( self::product_url() ) . '" target="_blank" rel="noopener">Fresh Core</a>';
        return $links;
    }

    public static function auto_update( $update, $item ) {
        if ( ! self::auto_enabled() || ! is_object( $item ) ) { return $update; }
        $plugin = (string) ( $item->plugin ?? '' );
        $slug = (string) ( $item->slug ?? '' );
        if ( plugin_basename( FRESH_CORE_FILE ) === $plugin || 'fresh-core' === $slug ) { return true; }
        return $update;
    }

    public static function verify_package_hash( $reply, string $package, $upgrader, array $hook_extra ) {
        if ( false !== $reply ) { return $reply; }
        $plugin = (string) ( $hook_extra['plugin'] ?? '' );
        $plugins = (array) ( $hook_extra['plugins'] ?? array() );
        $basename = plugin_basename( FRESH_CORE_FILE );
        // Also cover bulk/automatic upgrades that do not set hook_extra['plugin'].
        $data = self::fetch_manifest( false );
        $matches_package = is_array( $data ) &&
            ! empty( $data['download_url'] ) && $package === (string) $data['download_url'];
        $is_core = $basename === $plugin || in_array( $basename, $plugins, true ) || $matches_package;
        if ( ! $is_core ) { return $reply; }
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            return new WP_Error( 'fresh_core_update_manifest_unavailable', 'Cannot verify Fresh Core update: manifest is unavailable.' );
        }
        if ( ! $matches_package ) {
            return new WP_Error( 'fresh_core_update_package_mismatch', 'Fresh Core package URL does not match the release manifest.' );
        }
        if ( ! function_exists( 'download_url' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $tmp = download_url( $package, 30 );
        if ( is_wp_error( $tmp ) ) { return $tmp; }
        $actual = strtolower( (string) hash_file( 'sha256', $tmp ) );
        $expected = strtolower( (string) $data['sha256'] );
        if ( ! hash_equals( $expected, $actual ) ) {
            @unlink( $tmp );
            return new WP_Error( 'fresh_core_update_hash_mismatch', 'Fresh Core update package checksum does not match the central manifest.' );
        }
        return $tmp;
    }

    public static function clear_cache_after_upgrade( $upgrader, array $options ): void {
        if ( 'plugin' === ( $options['type'] ?? '' ) ) { self::clear_cache(); }
    }
}
