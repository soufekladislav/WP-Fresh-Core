<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Fresh_Core_Export {
    const CRON_HOOK = 'fresh_core_refresh_static_export';
    const NEAR_HOOK = 'fresh_core_refresh_static_export_near';

    public static function init(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'scheduled_refresh' ) );
        add_action( self::NEAR_HOOK, array( __CLASS__, 'scheduled_refresh' ) );
        add_action( 'save_post', array( __CLASS__, 'mark_dirty' ), 99, 3 );
        add_action( 'deleted_post', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'created_term', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'edited_term', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'delete_term', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'wp_update_nav_menu', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'activated_plugin', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'deactivated_plugin', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'switch_theme', array( __CLASS__, 'mark_dirty_simple' ), 99 );
        add_action( 'updated_option', array( __CLASS__, 'mark_dirty_option' ), 99, 3 );
        add_action( 'added_option', array( __CLASS__, 'mark_dirty_option_added' ), 99, 2 );

        // WooCommerce orders are excluded from the generic content export, but the
        // sanitized finance feed must stay fresh for Eda and other Fresh workers.
        add_action( 'woocommerce_new_order', array( __CLASS__, 'mark_order_dirty' ), 99, 1 );
        add_action( 'woocommerce_update_order', array( __CLASS__, 'mark_order_dirty' ), 99, 1 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'mark_order_dirty' ), 99, 4 );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'mark_order_dirty' ), 99, 1 );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'mark_order_dirty' ), 99, 2 );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, 'fresh_core_15min', self::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) { wp_unschedule_event( $ts, self::CRON_HOOK ); }
        $near = wp_next_scheduled( self::NEAR_HOOK );
        if ( $near ) { wp_unschedule_event( $near, self::NEAR_HOOK ); }
    }

    public static function cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['fresh_core_15min'] ) ) {
            $schedules['fresh_core_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Fresh Core every 15 minutes' );
        }
        return $schedules;
    }

    // Scheduled full snapshots are opt-in. Existing manual refresh and REST endpoints remain available.
    public static function scheduled_refresh(): array {
        // Recovery heartbeat even when automatic full snapshots are disabled.
        $job = Fresh_Core_Batch_Export::status();
        if ( in_array( $job['status'] ?? '', array( 'queued', 'running', 'waiting_for_scheduler' ), true ) ) {
            return Fresh_Core_Batch_Export::recover();
        }
        if ( 'yes' !== get_option( 'fresh_core_auto_full_export', 'no' ) ) { return array( 'ok' => true, 'skipped' => 'on_demand_only' ); }
        if ( ! get_option( 'fresh_core_export_dirty', 0 ) ) { return array( 'ok' => true, 'skipped' => 'unchanged' ); }
        return Fresh_Core_Batch_Export::start();
    }

    public static function enabled(): bool {
        return 'yes' === get_option( 'fresh_core_static_enabled', 'yes' );
    }

    public static function token(): string {
        $token = (string) get_option( 'fresh_core_static_token', '' );
        if ( strlen( $token ) < 40 ) {
            $token = wp_generate_password( 56, false, false );
            update_option( 'fresh_core_static_token', $token, false );
        }
        return $token;
    }

    public static function regenerate_token(): string {
        self::delete_export_tree();
        $token = wp_generate_password( 56, false, false );
        update_option( 'fresh_core_static_token', $token, false );
        return $token;
    }

    public static function mark_dirty( $post_id = 0, $post = null, $update = null ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'revision', 'shop_order', 'shop_order_refund', 'shop_subscription' ), true ) ) { return; }
        self::schedule_near_refresh();
    }

    public static function mark_dirty_simple(): void {
        self::schedule_near_refresh();
    }


    public static function mark_order_dirty( ...$args ): void {
        self::schedule_near_refresh();
    }

    public static function mark_dirty_option( string $option, $old_value = null, $value = null ): void {
        if ( 0 === strpos( $option, 'fresh_core_' ) ) { return; }
        if ( 0 === strpos( $option, 'elementor_' ) || 0 === strpos( $option, 'elementor_pro_' ) ) { self::schedule_near_refresh(); }
    }

    public static function mark_dirty_option_added( string $option, $value = null ): void {
        self::mark_dirty_option( $option, null, $value );
    }

    private static function schedule_near_refresh(): void {
        if ( ! self::enabled() ) { return; }
        update_option( 'fresh_core_export_dirty', time(), false );
        if ( 'yes' === get_option( 'fresh_core_auto_full_export', 'no' ) && ! wp_next_scheduled( self::NEAR_HOOK ) ) {
            wp_schedule_single_event( time() + 90, self::NEAR_HOOK );
        }
    }

    public static function base_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'fresh-core-connector/' . self::token();
    }

    public static function base_url(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['baseurl'] ) . 'fresh-core-connector/' . rawurlencode( self::token() );
    }

    public static function manifest_url(): string {
        return trailingslashit( self::base_url() ) . 'manifest.json';
    }

    private static function ensure_dir(): bool {
        $dir = self::base_dir();
        if ( ! wp_mkdir_p( $dir ) ) { return false; }
        // Prevent directory listing on Apache; harmless on other servers.
        if ( ! file_exists( $dir . '/.htaccess' ) ) {
            @file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\nHeader set X-Robots-Tag \"noindex, nofollow\"\nHeader set Cache-Control \"no-store, max-age=0\"\n</IfModule>\n" );
        }
        if ( ! file_exists( $dir . '/index.html' ) ) { @file_put_contents( $dir . '/index.html', '' ); }
        return true;
    }

    private static function write_json( string $relative, $data ): ?array {
        $relative = ltrim( str_replace( '..', '', $relative ), '/' );
        $path = trailingslashit( self::base_dir() ) . $relative;
        $subdir = dirname( $path );
        if ( ! wp_mkdir_p( $subdir ) ) { return null; }
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        if ( false === $json ) { return null; }
        $tmp = $path . '.tmp-' . wp_generate_password( 8, false, false );
        if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) { return null; }
        @rename( $tmp, $path );
        return array(
            'file' => $relative,
            'url' => trailingslashit( self::base_url() ) . str_replace( '%2F', '/', rawurlencode( $relative ) ),
            'bytes' => strlen( $json ),
            'sha256' => hash( 'sha256', $json ),
        );
    }

    private static function safe_post_types(): array {
        $blocked = array(
            'revision','attachment','nav_menu_item','custom_css','customize_changeset','user_request','oembed_cache',
            'shop_order','shop_order_refund','shop_subscription','shop_coupon','wc_order_status','wc_user_membership',
        );
        $types = get_post_types( array(), 'objects' );
        $out = array();
        foreach ( $types as $name => $obj ) {
            if ( in_array( $name, $blocked, true ) ) { continue; }
            if ( $obj->public || in_array( $name, array( 'elementor_library', 'wp_template', 'wp_template_part', 'product' ), true ) ) {
                $out[] = $name;
            }
        }
        sort( $out );
        return array_values( array_unique( $out ) );
    }

    public static function db_schema(): array {
        $out = array();
        foreach ( Fresh_Core_Data::db_tables() as $table ) {
            $schema = Fresh_Core_Data::db_table( (string) $table['name'] );
            if ( is_wp_error( $schema ) ) { continue; }
            $schema['rows_approx'] = $table['rows_approx'] ?? null;
            $schema['data_length'] = $table['data_length'] ?? null;
            $schema['index_length'] = $table['index_length'] ?? null;
            $out[] = $schema;
        }
        return $out;
    }

    private static function taxonomy_pages( string $taxonomy, int $per_page = 1000 ): array {
        $count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
        if ( is_wp_error( $count ) ) { $count = 0; }
        $pages = max( 1, (int) ceil( (int) $count / $per_page ) );
        $files = array();
        for ( $page = 1; $page <= $pages; $page++ ) {
            $items = Fresh_Core_Data::terms( $taxonomy, $page, $per_page );
            $file = 'taxonomies/' . sanitize_file_name( $taxonomy ) . '-' . $page . '.json';
            $meta = self::write_json( $file, array(
                'generated_at_gmt' => gmdate( 'c' ), 'taxonomy' => $taxonomy, 'page' => $page, 'pages' => $pages,
                'total' => (int) $count, 'items' => $items,
            ) );
            if ( $meta ) { $files[] = $meta; }
        }
        return $files;
    }

    private static function content_pages( string $post_type, int $per_page = 100 ): array {
        $first = Fresh_Core_Data::content( $post_type, 1, $per_page, 'any', true, '' );
        $pages = max( 1, (int) ( $first['pages'] ?? 1 ) );
        $files = array();
        for ( $page = 1; $page <= $pages; $page++ ) {
            $data = 1 === $page ? $first : Fresh_Core_Data::content( $post_type, $page, $per_page, 'any', true, '' );
            $file = 'content/' . sanitize_file_name( $post_type ) . '-' . $page . '.json';
            $meta = self::write_json( $file, array(
                'generated_at_gmt' => gmdate( 'c' ), 'post_type' => $post_type, 'page' => $page,
                'pages' => (int) ( $data['pages'] ?? $pages ), 'total' => (int) ( $data['total'] ?? 0 ),
                'items' => $data['items'] ?? array(),
            ) );
            if ( $meta ) { $files[] = $meta; }
        }
        return $files;
    }

    private static function elementor_export_files(): array {
        $files = array();
        $overview = Fresh_Core_Data::elementor_overview();
        $index = Fresh_Core_Data::elementor_documents_index();
        $site_settings = Fresh_Core_Data::elementor_site_settings();

        foreach ( array(
            'elementor/overview.json' => $overview,
            'elementor/index.json' => array( 'generated_at_gmt' => gmdate( 'c' ), 'items' => $index ),
            'elementor/site-settings.json' => array( 'generated_at_gmt' => gmdate( 'c' ), 'data' => $site_settings ),
            'elementor/pro-resources.json' => array( 'generated_at_gmt' => gmdate( 'c' ), 'data' => Fresh_Core_Data::elementor_pro_resources() ),
        ) as $name => $payload ) {
            $meta = self::write_json( $name, $payload );
            if ( $meta ) { $files[] = $meta; }
        }

        foreach ( Fresh_Core_Data::elementor_document_ids() as $id ) {
            $doc = Fresh_Core_Data::elementor_document( (int) $id );
            if ( ! $doc ) { continue; }
            $meta = self::write_json( 'elementor/documents/' . (int) $id . '.json', array(
                'generated_at_gmt' => gmdate( 'c' ),
                'document' => $doc,
            ) );
            if ( $meta ) { $files[] = $meta; }
        }
        return $files;
    }

    public static function diagnostics(): array {
        $tax_diagnostics = array();
        foreach ( array( 'category', 'product_cat' ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0 ) );
            if ( is_wp_error( $terms ) ) { continue; }
            $by_name = array();
            $empty = array();
            foreach ( $terms as $term ) {
                $normalized = function_exists( 'remove_accents' ) ? remove_accents( mb_strtolower( trim( $term->name ) ) ) : mb_strtolower( trim( $term->name ) );
                $by_name[ $normalized ][] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => (int) $term->parent, 'count' => (int) $term->count );
                if ( 0 === (int) $term->count ) { $empty[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => (int) $term->parent ); }
            }
            $duplicates = array_values( array_filter( $by_name, static fn( $group ) => count( $group ) > 1 ) );
            $tax_diagnostics[ $taxonomy ] = array(
                'total' => count( $terms ),
                'empty_count' => count( $empty ),
                'empty_terms' => array_slice( $empty, 0, 500 ),
                'duplicate_name_groups' => array_slice( $duplicates, 0, 500 ),
            );
        }

        return array(
            'generated_at_gmt' => gmdate( 'c' ),
            'taxonomies' => $tax_diagnostics,
            'fresh_modules' => Fresh_Core_Data::module_summaries(),
            'privacy' => array(
                'users' => 'schema only; user rows are never exported',
                'orders' => 'order/customer rows are never exported',
                'secrets' => 'options/meta values are not generically exported; only explicit safe fields are included',
            ),
        );
    }

    public static function refresh(): array {
        // Public legacy API now queues a bounded snapshot, never blocks the admin request.
        return Fresh_Core_Batch_Export::start();
    }

    private static function refresh_locked(): array {

        @set_time_limit( 120 );
        $started = microtime( true );
        $dirty_at_start = get_option( 'fresh_core_export_dirty', 0 );
        $files = array();

        $base_sets = array(
            'structure.json' => array(
                'generated_at_gmt' => gmdate( 'c' ),
                'manifest' => Fresh_Core_Data::manifest(),
                'post_types' => Fresh_Core_Data::post_types(),
                'taxonomies' => Fresh_Core_Data::taxonomies(),
                'menus' => Fresh_Core_Data::menus(),
            ),
            'database-schema.json' => array(
                'generated_at_gmt' => gmdate( 'c' ),
                'schema_only' => true,
                'tables' => self::db_schema(),
            ),
            'elementor.json' => array(
                'generated_at_gmt' => gmdate( 'c' ),
                'overview' => Fresh_Core_Data::elementor_overview(),
                'templates' => Fresh_Core_Data::elementor_templates(),
                'site_settings' => Fresh_Core_Data::elementor_site_settings(),
            ),
            'fresh-modules.json' => array(
                'generated_at_gmt' => gmdate( 'c' ),
                'modules' => Fresh_Core_Data::module_summaries(),
            ),
            'diagnostics.json' => self::diagnostics(),
            'seo/index.json' => Fresh_Core_SEO::index(),
            'modules/registry.json' => Fresh_Core_Modules::summary(),
        );
        foreach ( $base_sets as $name => $data ) {
            $meta = self::write_json( $name, $data );
            if ( $meta ) { $files[] = $meta; }
        }

        $files = array_merge( $files, self::elementor_export_files() );


        $orders_meta = self::write_json( 'commerce/orders.json', Fresh_Core_Content_Bridge::orders_feed_data( 1, 1000 ) );
        if ( $orders_meta ) { $files[] = $orders_meta; }

        foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy => $obj ) {
            if ( in_array( $taxonomy, array( 'nav_menu', 'link_category', 'post_format' ), true ) ) { continue; }
            if ( ! $obj->public && ! in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) { continue; }
            $files = array_merge( $files, self::taxonomy_pages( $taxonomy ) );
        }

        foreach ( self::safe_post_types() as $post_type ) {
            $files = array_merge( $files, self::content_pages( $post_type ) );
            $meta = self::write_json( 'meta-keys/' . sanitize_file_name( $post_type ) . '.json', array(
                'generated_at_gmt' => gmdate( 'c' ), 'post_type' => $post_type, 'items' => Fresh_Core_Data::meta_keys( $post_type ),
            ) );
            if ( $meta ) { $files[] = $meta; }
        }

        $manifest = array(
            'fresh_core_version' => FRESH_CORE_VERSION,
            'generated_at_gmt' => gmdate( 'c' ),
            'generation_seconds' => round( microtime( true ) - $started, 3 ),
            'read_only' => true,
            'capabilities' => array(
                'elementor_full_document_export' => true,
                'elementor_pro_theme_conditions' => true,
                'elementor_site_kit' => true,
                'elementor_widget_inventory' => true,
                'elementor_dynamic_tags_and_globals' => true,
                'seo_bridge' => true,
                'seo_provider' => Fresh_Core_SEO::provider(),
                'seo_provider_mode' => Fresh_Core_SEO::provider_mode(),
                'seo_detected_providers' => Fresh_Core_SEO::detected_providers(),
                'module_contract_version' => 1,
                'module_registry' => true,
                'event_log' => true,
                'wp_seo_dashboard' => true,
                'wp_seo_editor' => true,
                'seo_write_enabled' => Fresh_Core_Auth::write_enabled(),
                'seo_write_requires_separate_key' => true,
                'image_alt_read' => true,
                'image_alt_write' => Fresh_Core_Auth::write_enabled(),
                'image_url_resolve' => true,
                'content_bridge' => true,
                'content_export' => true,
                'content_preview_url' => true,
                'content_status' => true,
                'content_write_enabled' => Fresh_Core_Auth::write_enabled(),
                'content_write_requires_separate_key' => true,
                'analytics_summary' => true,
                'page_roles' => true,
                'commerce_orders_read' => true,
                'commerce_orders_sanitized' => true,
                'commerce_orders_customer_pii' => false,
                'accounting_bridge' => true,
                'accounting_enabled' => Fresh_Core_Accounting::enabled(),
                'accounting_requires_separate_key' => true,
                'central_updates' => true,
            ),
            'privacy' => array(
                'user_rows_exposed' => false,
                'order_customer_rows_exposed' => false,
                'sanitized_order_financial_rows_exposed' => true,
                'accounting_customer_data_in_static_export' => false,
                'accounting_customer_data_requires_private_api' => true,
                'generic_option_values_exposed' => false,
                'generic_meta_values_exposed' => false,
            ),
            'site' => array( 'url' => home_url( '/' ), 'name' => get_bloginfo( 'name' ) ),
            'commerce' => array(
                'orders_feed_url' => trailingslashit( self::base_url() ) . 'commerce/orders.json',
                'orders_rest_endpoint' => rest_url( 'fresh-core/v1/commerce/orders' ),
                'orders_feed_auth' => 'manifest-token-path',
                'orders_rest_auth' => 'Fresh Core READ key',
                'orders_customer_pii' => false,
                'accounting_orders_endpoint' => rest_url( 'fresh-core/v1/accounting/orders' ),
                'accounting_order_detail_endpoint' => rest_url( 'fresh-core/v1/accounting/orders/{id}' ),
                'accounting_auth_header' => 'X-Fresh-Core-Accounting-Key',
                'accounting_enabled' => Fresh_Core_Accounting::enabled(),
            ),
            'updates' => array(
                'product_url' => Fresh_Core_Updater::product_url(),
                'manifest_url' => Fresh_Core_Updater::manifest_url(),
                'auto_updates_enabled' => Fresh_Core_Updater::auto_enabled(),
            ),
            'files' => $files,
        );
        $manifest_meta = self::write_json( 'manifest.json', $manifest );
        update_option( 'fresh_core_export_last_run', gmdate( 'c' ), false );
        update_option( 'fresh_core_export_last_result', array(
            'ok' => (bool) $manifest_meta,
            'files' => count( $files ) + ( $manifest_meta ? 1 : 0 ),
            'seconds' => $manifest['generation_seconds'],
        ), false );
        if ( $manifest_meta && get_option( 'fresh_core_export_dirty', 0 ) === $dirty_at_start ) { delete_option( 'fresh_core_export_dirty' ); }

        return array( 'ok' => (bool) $manifest_meta, 'manifest_url' => self::manifest_url(), 'files' => count( $files ), 'seconds' => $manifest['generation_seconds'] );
    }

    private static function delete_export_tree(): void {
        $upload = wp_upload_dir();
        $root = trailingslashit( $upload['basedir'] ) . 'fresh-core-connector';
        if ( ! is_dir( $root ) ) { return; }
        self::rrmdir( $root );
    }

    private static function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) { return; }
        $items = scandir( $dir );
        if ( ! is_array( $items ) ) { return; }
        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) { continue; }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) { self::rrmdir( $path ); } else { @unlink( $path ); }
        }
        @rmdir( $dir );
    }
}
