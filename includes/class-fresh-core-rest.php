<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Fresh_Core_REST {
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache' ), 10, 3 );
    }

    public static function no_cache( $response, $server, $request ) {
        if ( 0 === strpos( $request->get_route(), '/fresh-core/v1/' ) && $response instanceof WP_REST_Response ) {
            $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
            $response->header( 'X-Robots-Tag', 'noindex, nofollow' );
        }
        return $response;
    }

    private static function args(): array {
        return array(
            'fresh_core_key' => array( 'type' => 'string', 'required' => false ),
        );
    }

    public static function routes(): void {
        $p = array( 'permission_callback' => array( 'Fresh_Core_Auth', 'permission' ) );

        register_rest_route( 'fresh-core/v1', '/ping', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( array( 'ok' => true, 'version' => FRESH_CORE_VERSION, 'time_gmt' => gmdate('c') ) ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/manifest', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::manifest() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/snapshot', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::snapshot() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/post-types', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::post_types() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/taxonomies', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::taxonomies() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/terms', array_merge( $p, array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'terms' ), 'args' => array_merge( self::args(), array(
                'taxonomy' => array( 'type' => 'string', 'required' => true ),
                'page' => array( 'type' => 'integer', 'default' => 1 ),
                'per_page' => array( 'type' => 'integer', 'default' => 500 ),
                'parent' => array( 'type' => 'integer', 'required' => false ),
            ) ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/content', array_merge( $p, array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'content' ), 'args' => array_merge( self::args(), array(
                'post_type' => array( 'type' => 'string', 'required' => true ),
                'page' => array( 'type' => 'integer', 'default' => 1 ),
                'per_page' => array( 'type' => 'integer', 'default' => 50 ),
                'status' => array( 'type' => 'string', 'default' => 'any' ),
                'include_content' => array( 'type' => 'boolean', 'default' => false ),
                'search' => array( 'type' => 'string', 'default' => '' ),
            ) ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/meta-keys', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn( WP_REST_Request $r ) => self::ok( Fresh_Core_Data::meta_keys( sanitize_key( (string) $r['post_type'] ) ) ),
            'args' => array_merge( self::args(), array( 'post_type' => array( 'type' => 'string', 'required' => true ) ) ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/db/tables', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::db_tables() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/db/table', array_merge( $p, array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'db_table' ), 'args' => array_merge( self::args(), array( 'name' => array( 'type' => 'string', 'required' => true ) ) ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/menus', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::menus() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/elementor/templates', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::elementor_templates() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/elementor/index', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::elementor_documents_index() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/elementor/site-settings', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::elementor_site_settings() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/elementor/pro-resources', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::elementor_pro_resources() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/elementor/document', array_merge( $p, array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'elementor_document' ), 'args' => array_merge( self::args(), array(
                'id' => array( 'type' => 'integer', 'required' => true ),
            ) ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/fresh/modules', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Data::module_summaries() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/modules/registry', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_Modules::summary() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/seo/capabilities', array_merge( $p, array(
            'methods' => 'GET', 'callback' => fn() => self::ok( Fresh_Core_SEO::capabilities() ), 'args' => self::args(),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/seo/item', array_merge( $p, array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'seo_item' ), 'args' => array_merge( self::args(), array(
                'id' => array( 'type' => 'integer', 'required' => true ),
            ) ),
        ) ) );

        $write = array( 'permission_callback' => array( 'Fresh_Core_Auth', 'permission_write_or_error' ) );
        register_rest_route( 'fresh-core/v1', '/write/ping', array_merge( $write, array(
            'methods' => 'GET',
            'callback' => fn() => self::ok( array(
                'ok' => true,
                'version' => FRESH_CORE_VERSION,
                'provider' => Fresh_Core_SEO::provider(),
                'write_enabled' => Fresh_Core_Auth::write_enabled(),
                'time_gmt' => gmdate( 'c' ),
            ) ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/seo/apply', array_merge( $write, array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'seo_apply' ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/seo/apply-batch', array_merge( $write, array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'seo_apply_batch' ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/media/resolve', array_merge( $write, array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'media_resolve' ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/media/apply-alt', array_merge( $write, array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'media_apply_alt' ),
        ) ) );
    }

    private static function ok( $data ): WP_REST_Response {
        return new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
    }

    public static function terms( WP_REST_Request $r ) {
        $taxonomy = sanitize_key( (string) $r['taxonomy'] );
        if ( ! taxonomy_exists( $taxonomy ) ) { return new WP_Error( 'fresh_core_taxonomy', 'Unknown taxonomy.', array( 'status' => 404 ) ); }
        $parent = null !== $r->get_param( 'parent' ) ? (int) $r['parent'] : -1;
        return self::ok( Fresh_Core_Data::terms( $taxonomy, max(1,(int)$r['page']), (int)$r['per_page'], $parent ) );
    }

    public static function content( WP_REST_Request $r ) {
        $post_type = sanitize_key( (string) $r['post_type'] );
        if ( ! post_type_exists( $post_type ) ) { return new WP_Error( 'fresh_core_post_type', 'Unknown post type.', array( 'status' => 404 ) ); }
        if ( in_array( $post_type, array( 'shop_order', 'shop_order_refund', 'shop_subscription' ), true ) ) {
            return new WP_Error( 'fresh_core_private_type', 'Order/customer post types are intentionally not exposed.', array( 'status' => 403 ) );
        }
        return self::ok( Fresh_Core_Data::content(
            $post_type, max(1,(int)$r['page']), (int)$r['per_page'], sanitize_key((string)$r['status']), (bool)$r['include_content'], sanitize_text_field((string)$r['search'])
        ) );
    }

    public static function elementor_document( WP_REST_Request $r ) {
        $id = (int) $r['id'];
        $data = Fresh_Core_Data::elementor_document( $id );
        if ( ! $data ) { return new WP_Error( 'fresh_core_elementor_document', 'Elementor document not found or intentionally blocked.', array( 'status' => 404 ) ); }
        return self::ok( $data );
    }

    public static function seo_item( WP_REST_Request $r ) {
        $id = (int) $r['id'];
        $data = Fresh_Core_SEO::item( $id );
        if ( ! $data ) {
            return new WP_Error( 'fresh_core_seo_item', 'SEO item not found or intentionally blocked.', array( 'status' => 404 ) );
        }
        return self::ok( $data );
    }

    public static function seo_apply( WP_REST_Request $r ) {
        $body = $r->get_json_params();
        $body = is_array( $body ) ? $body : array();
        $post_id = isset( $body['post_id'] ) ? (int) $body['post_id'] : 0;
        $changes = isset( $body['changes'] ) && is_array( $body['changes'] ) ? $body['changes'] : array();
        $change_id = isset( $body['change_id'] ) ? sanitize_text_field( (string) $body['change_id'] ) : '';

        if ( $post_id <= 0 ) {
            return new WP_Error( 'fresh_core_seo_post_id', 'post_id is required.', array( 'status' => 400 ) );
        }

        $result = Fresh_Core_SEO::apply( $post_id, $changes, $change_id );
        if ( is_wp_error( $result ) ) { return $result; }
        return self::ok( $result );
    }


    public static function media_resolve( WP_REST_Request $r ) {
        $body = $r->get_json_params();
        $body = is_array( $body ) ? $body : array();
        $image_url = isset( $body['image_url'] ) ? esc_url_raw( (string) $body['image_url'] ) : '';
        if ( '' === $image_url ) {
            return new WP_Error( 'fresh_core_image_url', 'image_url is required.', array( 'status' => 400 ) );
        }
        $result = Fresh_Core_SEO::resolve_image_url( $image_url );
        if ( ! $result ) {
            return new WP_Error( 'fresh_core_image_unresolved', 'Image URL could not be mapped to a WordPress attachment.', array( 'status' => 404 ) );
        }
        return self::ok( $result );
    }


    public static function media_apply_alt( WP_REST_Request $r ) {
        $body = $r->get_json_params();
        $body = is_array( $body ) ? $body : array();
        $attachment_id = isset( $body['attachment_id'] ) ? (int) $body['attachment_id'] : 0;
        $alt = array_key_exists( 'alt', $body ) ? (string) $body['alt'] : '';
        $change_id = isset( $body['change_id'] ) ? sanitize_text_field( (string) $body['change_id'] ) : '';
        if ( $attachment_id <= 0 ) {
            return new WP_Error( 'fresh_core_image_id', 'attachment_id is required.', array( 'status' => 400 ) );
        }
        $result = Fresh_Core_SEO::apply_image_alt( $attachment_id, $alt, $change_id );
        if ( is_wp_error( $result ) ) { return $result; }
        return self::ok( $result );
    }


    public static function seo_apply_batch( WP_REST_Request $r ) {
        $body = $r->get_json_params();
        $body = is_array( $body ) ? $body : array();
        $items = isset( $body['items'] ) && is_array( $body['items'] ) ? array_values( $body['items'] ) : array();
        if ( ! $items ) {
            return new WP_Error( 'fresh_core_seo_batch_empty', 'items is required.', array( 'status' => 400 ) );
        }
        if ( count( $items ) > 50 ) {
            return new WP_Error( 'fresh_core_seo_batch_limit', 'Maximum batch size is 50.', array( 'status' => 400 ) );
        }

        $results = array();
        $applied = 0;
        $failed = 0;
        foreach ( $items as $idx => $item ) {
            $item = is_array( $item ) ? $item : array();
            $post_id = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
            $changes = isset( $item['changes'] ) && is_array( $item['changes'] ) ? $item['changes'] : array();
            $change_id = isset( $item['change_id'] ) ? sanitize_text_field( (string) $item['change_id'] ) : 'batch-' . ( $idx + 1 );
            if ( $post_id <= 0 ) {
                $results[] = array( 'ok' => false, 'post_id' => $post_id, 'change_id' => $change_id, 'error' => 'post_id is required.' );
                $failed++;
                continue;
            }
            $result = Fresh_Core_SEO::apply( $post_id, $changes, $change_id );
            if ( is_wp_error( $result ) ) {
                $results[] = array( 'ok' => false, 'post_id' => $post_id, 'change_id' => $change_id, 'error' => $result->get_error_message() );
                $failed++;
                continue;
            }
            $results[] = array( 'ok' => true, 'post_id' => $post_id, 'change_id' => $change_id, 'data' => $result );
            $applied++;
        }
        return self::ok( array( 'ok' => 0 === $failed, 'applied' => $applied, 'failed' => $failed, 'results' => $results ) );
    }

    public static function db_table( WP_REST_Request $r ) {
        $data = Fresh_Core_Data::db_table( (string) $r['name'] );
        if ( is_wp_error( $data ) ) { return $data; }
        return self::ok( $data );
    }
}
