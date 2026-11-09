<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared module/event contract for Fresh virtual employees.
 *
 * Fresh Core remains infrastructure. Boban, Linda and future employees register
 * capabilities through this layer instead of adding one-off connectors.
 */
final class Fresh_Core_Modules {
    const EVENT_OPTION = 'fresh_core_event_log';

    public static function init(): void {
        add_action( 'save_post', array( __CLASS__, 'capture_post_saved' ), 30, 3 );
        add_action( 'added_post_meta', array( __CLASS__, 'capture_media_alt' ), 30, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'capture_media_alt' ), 30, 4 );
    }

    public static function registry(): array {
        $modules = array(
            'core' => array(
                'id' => 'core',
                'name' => 'Fresh Core',
                'version' => FRESH_CORE_VERSION,
                'status' => 'active',
                'capabilities' => array( 'manifest', 'content.read', 'content.write', 'content.preview', 'content.status', 'analytics.summary', 'media.read', 'events.read', 'safe_write' ),
            ),
            'boban' => array(
                'id' => 'boban',
                'name' => 'Boban',
                'status' => 'ready',
                'capabilities' => array( 'seo.read', 'seo.write', 'media.alt.write', 'seo.provider', 'seo.dashboard' ),
                'external_app' => 'https://webbooster.freshcrackers.cz',
            ),
        );
        /**
         * Other Fresh products (for example Linda) register here later:
         * add_filter( 'fresh_core_modules', fn( $modules ) => ... );
         */
        $filtered = apply_filters( 'fresh_core_modules', $modules );
        return is_array( $filtered ) ? $filtered : $modules;
    }

    public static function contract(): array {
        return array(
            'version' => 1,
            'fields' => array( 'id', 'name', 'status', 'capabilities' ),
            'events' => array(
                'content.saved', 'content.upserted', 'media.alt.updated', 'seo.updated', 'module.custom',
            ),
            'principle' => 'modules share Fresh Core data/events/permissions; specialist logic stays in the specialist app',
        );
    }

    public static function summary(): array {
        return array(
            'generated_at_gmt' => gmdate( 'c' ),
            'contract' => self::contract(),
            'modules' => array_values( self::registry() ),
            'recent_events' => self::events( 100 ),
        );
    }

    public static function emit( string $type, array $payload = array(), string $module = 'core' ): void {
        $events = get_option( self::EVENT_OPTION, array() );
        $events = is_array( $events ) ? $events : array();
        array_unshift( $events, array(
            'id' => wp_generate_uuid4(),
            'type' => sanitize_key( str_replace( '.', '_', $type ) ),
            'event' => sanitize_text_field( $type ),
            'module' => sanitize_key( $module ),
            'payload' => self::sanitize_payload( $payload ),
            'time_gmt' => gmdate( 'c' ),
        ) );
        update_option( self::EVENT_OPTION, array_slice( $events, 0, 300 ), false );
    }

    private static function sanitize_payload( array $payload ): array {
        $allowed = array();
        foreach ( $payload as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( is_scalar( $value ) || null === $value ) {
                $allowed[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
            }
        }
        return $allowed;
    }

    public static function events( int $limit = 100 ): array {
        $events = get_option( self::EVENT_OPTION, array() );
        $events = is_array( $events ) ? $events : array();
        return array_slice( $events, 0, max( 1, min( 300, $limit ) ) );
    }

    public static function capture_post_saved( int $post_id, WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
        $obj = get_post_type_object( $post->post_type );
        if ( ! $obj || ( ! $obj->public && 'product' !== $post->post_type ) ) { return; }
        self::emit( 'content.saved', array(
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'update' => $update ? 1 : 0,
            'modified_gmt' => $post->post_modified_gmt,
        ) );
    }

    public static function capture_media_alt( $meta_id, $post_id, $meta_key, $meta_value ): void {
        if ( '_wp_attachment_image_alt' !== $meta_key || ! wp_attachment_is_image( (int) $post_id ) ) { return; }
        self::emit( 'media.alt.updated', array( 'attachment_id' => (int) $post_id ) );
    }
}
