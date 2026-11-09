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
        $response = wp_remote_get( $url, array(
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
        if ( ! empty( $data['sha256'] ) ) { $data['sha256'] = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $data['sha256'] ) ); }
        delete_option( 'fresh_core_update_last_error' );
        update_option( 'fresh_core_update_latest_version', $data['version'], false );
        set_site_transient( self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS );
        return $data;
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
            $transient->no_update[ $basename ] = self::update_object( $data );
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
        if ( plugin_basename( FRESH_CORE_FILE ) !== $plugin ) { return $reply; }
        $data = self::fetch_manifest( false );
        if ( is_wp_error( $data ) || ! is_array( $data ) || empty( $data['sha256'] ) ) { return $reply; }
        if ( empty( $data['download_url'] ) || untrailingslashit( (string) $data['download_url'] ) !== untrailingslashit( $package ) ) { return $reply; }
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
