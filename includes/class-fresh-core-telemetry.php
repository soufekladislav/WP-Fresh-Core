<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Lightweight, bounded, privacy-preserving request telemetry. */
final class Fresh_Core_Telemetry {
    const SCHEMA_VERSION = '1';
    private static $started = 0.0;
    private static $previous_peak = 0;

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'fresh_core_metrics';
        $collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            observed_at datetime NOT NULL,
            area varchar(32) NOT NULL,
            duration_ms int unsigned NOT NULL,
            peak_mb decimal(9,2) NOT NULL,
            queries int unsigned NOT NULL,
            result varchar(16) NOT NULL,
            PRIMARY KEY  (id),
            KEY observed_at (observed_at),
            KEY area_time (area,observed_at)
        ) {$collate};" );
        update_option( 'fresh_core_metrics_schema', self::SCHEMA_VERSION, false );
    }

    public static function init(): void {
        self::$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
        self::$previous_peak = memory_get_peak_usage( true );
        add_action( 'shutdown', array( __CLASS__, 'capture' ), PHP_INT_MAX );
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
        add_action( 'fresh_core_metrics_cleanup', array( __CLASS__, 'cleanup' ) );

    }

    public static function maybe_upgrade(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( self::SCHEMA_VERSION !== (string) get_option( 'fresh_core_metrics_schema', '' ) ) { self::install(); }
        if ( ! wp_next_scheduled( 'fresh_core_metrics_cleanup' ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'fresh_core_metrics_cleanup' ); }
    }

    private static function area(): string {
        if ( defined( 'WP_CLI' ) && WP_CLI ) { return 'cli'; }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) { return 'cron'; }
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { return 'ajax'; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : (string) ( $_SERVER['REQUEST_URI'] ?? '' );
            return false !== strpos( $route, 'fresh-core/' ) ? 'fresh_core_api' : 'rest';
        }
        return is_admin() ? 'admin' : 'frontend';
    }

    public static function capture(): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! self::$started ) { return; }
        $elapsed = max( 0, (int) round( ( microtime( true ) - self::$started ) * 1000 ) );
        $peak = round( memory_get_peak_usage( true ) / 1048576, 2 );
        $err = error_get_last();
        $fatal = is_array( $err ) && in_array( (int) ( $err['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
        $sampled = wp_rand( 1, 50 ) === 1;
        if ( ! $fatal && $elapsed < 2000 && $peak < 256 && ! $sampled ) { return; }
        // Missing table (e.g. newly upgraded site before admin visit): silently skip.
        if ( self::SCHEMA_VERSION !== (string) get_option( 'fresh_core_metrics_schema', '' ) ) { return; }
        $wpdb->insert( $wpdb->prefix . 'fresh_core_metrics', array(
            'observed_at' => current_time( 'mysql', true ),
            'area' => self::area(),
            'duration_ms' => min( $elapsed, 4294967295 ),
            'peak_mb' => $peak,
            'queries' => max( 0, (int) ( $wpdb->num_queries ?? 0 ) ),
            'result' => $fatal ? 'fatal' : ( $elapsed >= 2000 || $peak >= 256 ? 'slow' : 'sample' ),
        ), array( '%s', '%s', '%d', '%f', '%d', '%s' ) );
    }

    public static function cleanup(): void {
        global $wpdb;
        if ( self::SCHEMA_VERSION !== (string) get_option( 'fresh_core_metrics_schema', '' ) ) { return; }
        $table = $wpdb->prefix . 'fresh_core_metrics';
        // Bounded deletion avoids holding large locks during housekeeping.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE observed_at < %s ORDER BY id LIMIT 500", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
    }

    public static function summary( int $hours = 24 ): array {
        global $wpdb;
        $hours = max( 1, min( 720, $hours ) );
        if ( self::SCHEMA_VERSION !== (string) get_option( 'fresh_core_metrics_schema', '' ) ) {
            return array( 'count' => 0, 'slow_or_error' => 0, 'peak_mb' => 0, 'by_area' => array() );
        }
        $table = $wpdb->prefix . 'fresh_core_metrics';
        $from = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT area, COUNT(*) AS samples, ROUND(AVG(duration_ms)) AS avg_ms, MAX(duration_ms) AS max_ms, MAX(peak_mb) AS peak_mb, SUM(result IN ('slow','fatal')) AS slow_or_error FROM {$table} WHERE observed_at >= %s GROUP BY area ORDER BY samples DESC", $from ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        return array(
            'count' => array_sum( array_map( static function( $r ) { return (int) $r['samples']; }, $rows ) ),
            'slow_or_error' => array_sum( array_map( static function( $r ) { return (int) $r['slow_or_error']; }, $rows ) ),
            'peak_mb' => $rows ? max( array_map( static function( $r ) { return (float) $r['peak_mb']; }, $rows ) ) : 0,
            'by_area' => $rows,
            'sampling' => 'approximately 1/50 requests, plus all slow (>=2s), high-memory (>=256MB) and fatal requests',
        );
    }
}
