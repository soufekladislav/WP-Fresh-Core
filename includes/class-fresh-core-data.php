<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Fresh_Core_Data {
    private static function scalarize( $value ) {
        if ( is_null( $value ) || is_scalar( $value ) ) { return $value; }
        if ( is_array( $value ) ) {
            $out = array();
            $i = 0;
            foreach ( $value as $k => $v ) {
                if ( $i++ > 100 ) { $out['_truncated'] = true; break; }
                $out[ is_int( $k ) ? $k : sanitize_key( (string) $k ) ] = self::scalarize( $v );
            }
            return $out;
        }
        return null;
    }

    public static function manifest(): array {
        global $wpdb, $wp_version;
        $theme = wp_get_theme();
        $plugins = array();
        if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        foreach ( get_plugins() as $file => $data ) {
            if ( ! is_plugin_active( $file ) ) { continue; }
            $plugins[] = array(
                'file' => $file,
                'name' => (string) ( $data['Name'] ?? $file ),
                'version' => (string) ( $data['Version'] ?? '' ),
            );
        }
        usort( $plugins, fn($a,$b) => strcasecmp( $a['name'], $b['name'] ) );

        return array(
            'connector_version' => FRESH_CORE_VERSION,
            'site' => array(
                'name' => get_bloginfo( 'name' ),
                'url' => home_url( '/' ),
                'language' => get_bloginfo( 'language' ),
                'timezone' => wp_timezone_string(),
                'front_page_id' => (int) get_option( 'page_on_front', 0 ),
                'posts_page_id' => (int) get_option( 'page_for_posts', 0 ),
            ),
            'runtime' => array(
                'wordpress' => $wp_version,
                'php' => PHP_VERSION,
                'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
                'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
                'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
                'db_prefix' => $wpdb->prefix,
            ),
            'theme' => array(
                'name' => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'stylesheet' => $theme->get_stylesheet(),
                'template' => $theme->get_template(),
            ),
            'active_plugins' => $plugins,
            'routes' => array(
                'snapshot' => '/wp-json/fresh-core/v1/snapshot',
                'post_types' => '/wp-json/fresh-core/v1/post-types',
                'taxonomies' => '/wp-json/fresh-core/v1/taxonomies',
                'terms' => '/wp-json/fresh-core/v1/terms?taxonomy=product_cat',
                'content' => '/wp-json/fresh-core/v1/content?post_type=product',
                'meta_keys' => '/wp-json/fresh-core/v1/meta-keys?post_type=product',
                'db_tables' => '/wp-json/fresh-core/v1/db/tables',
                'db_table' => '/wp-json/fresh-core/v1/db/table?name=' . rawurlencode( $wpdb->posts ),
                'elementor' => '/wp-json/fresh-core/v1/elementor/templates',
                'elementor_index' => '/wp-json/fresh-core/v1/elementor/index',
                'elementor_document' => '/wp-json/fresh-core/v1/elementor/document?id=123',
                'elementor_site_settings' => '/wp-json/fresh-core/v1/elementor/site-settings',
                'elementor_pro_resources' => '/wp-json/fresh-core/v1/elementor/pro-resources',
                'modules' => '/wp-json/fresh-core/v1/fresh/modules',
                'seo_capabilities' => '/wp-json/fresh-core/v1/seo/capabilities',
                'seo_item' => '/wp-json/fresh-core/v1/seo/item?id=123',
                'write_ping' => '/wp-json/fresh-core/v1/write/ping',
                'seo_apply' => '/wp-json/fresh-core/v1/seo/apply',
                'media_apply_alt' => '/wp-json/fresh-core/v1/media/apply-alt',
                'content_export' => '/wp-json/fresh-core/v1/content/export',
                'commerce_catalog' => '/wp-json/fresh-core/v1/commerce/catalog',
                'commerce_product' => '/wp-json/fresh-core/v1/commerce/product/<id>',
                'content_preview_url' => '/wp-json/fresh-core/v1/content/preview-url',
                'content_status' => '/wp-json/fresh-core/v1/content/status?url=https%3A%2F%2Fexample.com%2F',
                'content_upsert' => '/wp-json/fresh-core/v1/write/content/upsert',
                'analytics_summary' => '/wp-json/fresh-core/v1/analytics/summary',
            ),
        );
    }

    public static function post_types(): array {
        $objects = get_post_types( array(), 'objects' );
        $out = array();
        foreach ( $objects as $name => $obj ) {
            if ( in_array( $name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user_request', 'oembed_cache' ), true ) ) { continue; }
            $counts = wp_count_posts( $name );
            $count_map = array();
            if ( $counts ) {
                foreach ( get_object_vars( $counts ) as $status => $count ) {
                    if ( (int) $count > 0 ) { $count_map[ $status ] = (int) $count; }
                }
            }
            $out[] = array(
                'name' => $name,
                'label' => $obj->labels->name ?? $name,
                'public' => (bool) $obj->public,
                'hierarchical' => (bool) $obj->hierarchical,
                'show_in_rest' => (bool) $obj->show_in_rest,
                'taxonomies' => get_object_taxonomies( $name ),
                'supports' => get_all_post_type_supports( $name ),
                'counts' => $count_map,
            );
        }
        usort( $out, fn($a,$b) => strcasecmp( $a['name'], $b['name'] ) );
        return $out;
    }

    public static function taxonomies(): array {
        $objects = get_taxonomies( array(), 'objects' );
        $out = array();
        foreach ( $objects as $name => $obj ) {
            if ( in_array( $name, array( 'nav_menu', 'link_category', 'post_format' ), true ) ) { continue; }
            $count = wp_count_terms( array( 'taxonomy' => $name, 'hide_empty' => false ) );
            if ( is_wp_error( $count ) ) { $count = 0; }
            $out[] = array(
                'name' => $name,
                'label' => $obj->labels->name ?? $name,
                'public' => (bool) $obj->public,
                'hierarchical' => (bool) $obj->hierarchical,
                'show_in_rest' => (bool) $obj->show_in_rest,
                'object_type' => array_values( (array) $obj->object_type ),
                'term_count' => (int) $count,
            );
        }
        usort( $out, fn($a,$b) => strcasecmp( $a['name'], $b['name'] ) );
        return $out;
    }

    public static function terms( string $taxonomy, int $page = 1, int $per_page = 500, int $parent = -1 ): array {
        if ( ! taxonomy_exists( $taxonomy ) ) { return array(); }
        $per_page = max( 1, min( 1000, $per_page ) );
        $args = array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $per_page,
            'offset' => max( 0, ( $page - 1 ) * $per_page ),
            'orderby' => 'term_id',
            'order' => 'ASC',
        );
        if ( $parent >= 0 ) { $args['parent'] = $parent; }
        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) { return array(); }
        return array_map( static function( $term ) {
            return array(
                'id' => (int) $term->term_id,
                'parent' => (int) $term->parent,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => (int) $term->count,
                'description' => wp_strip_all_tags( (string) $term->description ),
            );
        }, $terms );
    }

    public static function content( string $post_type, int $page = 1, int $per_page = 50, string $status = 'any', bool $include_content = false, string $search = '' ): array {
        if ( ! post_type_exists( $post_type ) ) { return array( 'items' => array(), 'total' => 0, 'pages' => 0 ); }
        $per_page = max( 1, min( 100, $per_page ) );
        $args = array(
            'post_type' => $post_type,
            'post_status' => $status ?: 'any',
            'posts_per_page' => $per_page,
            'paged' => max( 1, $page ),
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        );
        if ( '' !== $search ) { $args['s'] = $search; }
        $q = new WP_Query( $args );
        $items = array();
        foreach ( $q->posts as $post ) {
            $tax = array();
            foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
                $terms = wp_get_object_terms( $post->ID, $taxonomy );
                if ( is_wp_error( $terms ) || ! $terms ) { continue; }
                $tax[ $taxonomy ] = array_map( static function( $t ) {
                    return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => (int) $t->parent );
                }, $terms );
            }
            $row = array(
                'id' => (int) $post->ID,
                'post_type' => $post_type,
                'title' => get_the_title( $post ),
                'slug' => $post->post_name,
                'url' => get_permalink( $post->ID ),
                'status' => $post->post_status,
                'date' => $post->post_date_gmt,
                'modified' => $post->post_modified_gmt,
                'parent' => (int) $post->post_parent,
                'menu_order' => (int) $post->menu_order,
                'excerpt' => wp_strip_all_tags( (string) $post->post_excerpt ),
                'content_length' => strlen( (string) $post->post_content ),
                'taxonomies' => $tax,
            );
            if ( $include_content ) { $row['content'] = (string) $post->post_content; }
            if ( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $post->ID );
                if ( $product ) {
                    $attrs = array();
                    foreach ( $product->get_attributes() as $attr ) {
                        $attrs[] = array(
                            'name' => $attr->get_name(),
                            'taxonomy' => $attr->is_taxonomy(),
                            'visible' => $attr->get_visible(),
                            'variation' => $attr->get_variation(),
                            'options' => array_values( $attr->get_options() ),
                        );
                    }
                    $row['product'] = array(
                        'type' => $product->get_type(),
                        'sku' => $product->get_sku(),
                        'price' => $product->get_price(),
                        'regular_price' => $product->get_regular_price(),
                        'sale_price' => $product->get_sale_price(),
                        'stock_status' => $product->get_stock_status(),
                        'stock_quantity' => $product->get_stock_quantity(),
                        'virtual' => $product->is_virtual(),
                        'featured' => $product->is_featured(),
                        'attributes' => $attrs,
                        'fresh_event' => self::event_meta( $post->ID ),
                    );
                }
            }
            $items[] = $row;
        }
        return array( 'items' => $items, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages );
    }

    private static function event_meta( int $post_id ): ?array {
        if ( 'yes' !== get_post_meta( $post_id, '_fresh_event_enabled', true ) ) { return null; }
        $keys = array(
            '_fresh_event_start', '_fresh_event_end', '_fresh_event_deadline', '_fresh_event_location',
            '_fresh_event_capacity', '_fresh_event_distance', '_fresh_event_elevation', '_fresh_event_difficulty',
            '_fresh_event_organizer_post_id', '_fresh_event_organizer_id'
        );
        $out = array( 'enabled' => true );
        foreach ( $keys as $key ) {
            $v = get_post_meta( $post_id, $key, true );
            if ( '' !== $v && null !== $v ) { $out[ ltrim( $key, '_' ) ] = self::scalarize( $v ); }
        }
        return $out;
    }

    public static function meta_keys( string $post_type ): array {
        global $wpdb;
        if ( ! post_type_exists( $post_type ) ) { return array(); }
        $sql = "SELECT pm.meta_key, COUNT(*) AS occurrences
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s
                GROUP BY pm.meta_key ORDER BY occurrences DESC, pm.meta_key ASC LIMIT 1000";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_type, '_wp_%' ), ARRAY_A );
        return array_map( static function( $r ) {
            return array( 'meta_key' => $r['meta_key'], 'occurrences' => (int) $r['occurrences'] );
        }, $rows ?: array() );
    }

    public static function db_tables(): array {
        global $wpdb;
        $like = $wpdb->esc_like( $wpdb->prefix ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
        $out = array();
        foreach ( $rows ?: array() as $r ) {
            $out[] = array(
                'name' => $r['Name'],
                'engine' => $r['Engine'] ?? '',
                'rows_approx' => isset( $r['Rows'] ) ? (int) $r['Rows'] : null,
                'collation' => $r['Collation'] ?? '',
                'data_length' => isset( $r['Data_length'] ) ? (int) $r['Data_length'] : null,
                'index_length' => isset( $r['Index_length'] ) ? (int) $r['Index_length'] : null,
            );
        }
        return $out;
    }

    public static function db_table( string $name ) {
        global $wpdb;
        if ( ! self::safe_table_name( $name ) ) {
            return new WP_Error( 'fresh_core_bad_table', 'Table is outside the WordPress prefix or has an invalid name.', array( 'status' => 400 ) );
        }
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
        if ( $exists !== $name ) {
            return new WP_Error( 'fresh_core_missing_table', 'Table not found.', array( 'status' => 404 ) );
        }
        $columns = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $name ) . '`', ARRAY_A );
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $name ) . '`', ARRAY_A );
        $safe_cols = array();
        foreach ( $columns ?: array() as $c ) {
            $safe_cols[] = array(
                'field' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'], 'key' => $c['Key'], 'extra' => $c['Extra'],
            );
        }
        $safe_idx = array();
        foreach ( $indexes ?: array() as $i ) {
            $safe_idx[] = array(
                'name' => $i['Key_name'], 'column' => $i['Column_name'], 'sequence' => (int) $i['Seq_in_index'], 'unique' => 0 === (int) $i['Non_unique'],
            );
        }
        return array( 'name' => $name, 'columns' => $safe_cols, 'indexes' => $safe_idx );
    }

    private static function safe_table_name( string $name ): bool {
        global $wpdb;
        return 0 === strpos( $name, $wpdb->prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
    }

    public static function menus(): array {
        $out = array();
        foreach ( wp_get_nav_menus() as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id ) ?: array();
            $out[] = array(
                'id' => (int) $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'items' => array_map( static function( $item ) {
                    return array(
                        'id' => (int) $item->ID,
                        'parent' => (int) $item->menu_item_parent,
                        'title' => $item->title,
                        'url' => $item->url,
                        'type' => $item->type,
                        'object' => $item->object,
                        'object_id' => (int) $item->object_id,
                        'order' => (int) $item->menu_order,
                    );
                }, $items ),
            );
        }
        return $out;
    }

    /**
     * Elementor stores the editable document tree as JSON in _elementor_data.
     * We intentionally export this structure for development/diagnostics, while
     * redacting credentials that may be embedded in third-party widget settings.
     */
    private static function decode_elementor_value( $value ) {
        if ( is_string( $value ) ) {
            $trim = trim( $value );
            if ( '' !== $trim && ( '[' === substr( $trim, 0, 1 ) || '{' === substr( $trim, 0, 1 ) ) ) {
                $decoded = json_decode( $trim, true );
                if ( JSON_ERROR_NONE === json_last_error() ) { return $decoded; }
            }
        }
        return $value;
    }

    private static function elementor_sensitive_key( string $key ): bool {
        $key = strtolower( $key );
        $allow = array( 'accessibility', 'token_name', 'form_id', 'query_id', 'menu_id', 'template_id', 'post_id' );
        if ( in_array( $key, $allow, true ) ) { return false; }
        return (bool) preg_match( '/(?:password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|auth(?:orization)?[_-]?token|client[_-]?secret|webhook[_-]?(?:url|key|secret)|private[_-]?key)/i', $key );
    }

    private static function sanitize_elementor_value( $value, string $key = '' ) {
        if ( '' !== $key && self::elementor_sensitive_key( $key ) ) { return '[REDACTED_BY_FRESH_CORE]'; }
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $child_key = is_string( $k ) ? $k : '';
                $out[ $k ] = self::sanitize_elementor_value( $v, $child_key );
            }
            return $out;
        }
        if ( is_object( $value ) ) { return self::sanitize_elementor_value( get_object_vars( $value ), $key ); }
        return $value;
    }

    private static function elementor_data( int $post_id ): array {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        $decoded = self::decode_elementor_value( $raw );
        if ( ! is_array( $decoded ) ) { return array(); }
        return self::sanitize_elementor_value( $decoded );
    }

    private static function elementor_page_settings( int $post_id ): array {
        $settings = self::decode_elementor_value( get_post_meta( $post_id, '_elementor_page_settings', true ) );
        if ( ! is_array( $settings ) ) { $settings = array(); }
        return self::sanitize_elementor_value( $settings );
    }

    private static function elementor_walk( array $elements, array &$stats, string $path = '' ): void {
        foreach ( $elements as $index => $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $id = isset( $element['id'] ) ? (string) $element['id'] : '';
            $el_type = isset( $element['elType'] ) ? (string) $element['elType'] : 'unknown';
            $widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
            $here = $path . '/' . $index . ':' . ( $widget_type ?: $el_type ) . ( $id ? '#' . $id : '' );

            $stats['element_count']++;
            $stats['element_types'][ $el_type ] = ( $stats['element_types'][ $el_type ] ?? 0 ) + 1;
            if ( $widget_type ) {
                $stats['widget_count']++;
                $stats['widgets'][ $widget_type ] = ( $stats['widgets'][ $widget_type ] ?? 0 ) + 1;
            }

            $settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
            self::elementor_collect_special_settings( $settings, $stats, $here );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                self::elementor_walk( $element['elements'], $stats, $here );
            }
        }
    }

    private static function elementor_collect_special_settings( array $settings, array &$stats, string $path ): void {
        foreach ( $settings as $key => $value ) {
            $key_s = (string) $key;
            if ( '__dynamic__' === $key_s && is_array( $value ) ) {
                foreach ( $value as $control => $dynamic_value ) {
                    $stats['dynamic_tags'][] = array( 'path' => $path, 'control' => (string) $control, 'value' => $dynamic_value );
                }
            }
            if ( '__globals__' === $key_s && is_array( $value ) ) {
                foreach ( $value as $control => $global_value ) {
                    $stats['global_references'][] = array( 'path' => $path, 'control' => (string) $control, 'value' => $global_value );
                }
            }
            if ( preg_match( '/(?:^|_)(?:template|loop_template|skin_template|template_id)(?:$|_)/i', $key_s ) ) {
                $stats['template_references'][] = array( 'path' => $path, 'setting' => $key_s, 'value' => $value );
            }
            if ( 'query_id' === $key_s && '' !== (string) $value ) {
                $stats['query_ids'][] = array( 'path' => $path, 'value' => (string) $value );
            }
            if ( preg_match( '/(?:^|_)(?:menu_id|nav_menu)(?:$|_)/i', $key_s ) && ( is_scalar( $value ) || null === $value ) ) {
                $stats['menu_references'][] = array( 'path' => $path, 'setting' => $key_s, 'value' => $value );
            }
            if ( is_array( $value ) && '__dynamic__' !== $key_s && '__globals__' !== $key_s ) {
                self::elementor_collect_special_settings( $value, $stats, $path . '/' . $key_s );
            }
        }
    }

    private static function elementor_stats( array $data ): array {
        $stats = array(
            'element_count' => 0,
            'widget_count' => 0,
            'element_types' => array(),
            'widgets' => array(),
            'dynamic_tags' => array(),
            'global_references' => array(),
            'template_references' => array(),
            'query_ids' => array(),
            'menu_references' => array(),
        );
        self::elementor_walk( $data, $stats );
        ksort( $stats['element_types'] );
        ksort( $stats['widgets'] );
        return $stats;
    }

    public static function elementor_document_ids(): array {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        global $wpdb;
        $blocked = array( 'revision', 'attachment', 'shop_order', 'shop_order_refund', 'shop_subscription', 'user_request' );
        $placeholders = implode( ',', array_fill( 0, count( $blocked ), '%s' ) );
        $sql = "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
                WHERE p.post_type NOT IN ($placeholders)
                  AND p.post_status NOT IN ('trash','auto-draft','inherit')
                ORDER BY p.ID ASC";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $prepared = $wpdb->prepare( $sql, $blocked );
        $ids = $wpdb->get_col( $prepared );
        $cache = array_values( array_map( 'intval', $ids ?: array() ) );
        return $cache;
    }

    public static function elementor_document( int $post_id ): array {
        static $cache = array();
        if ( isset( $cache[ $post_id ] ) ) { return $cache[ $post_id ]; }
        $post = get_post( $post_id );
        if ( ! $post ) { return array(); }
        if ( in_array( $post->post_type, array( 'revision', 'attachment', 'shop_order', 'shop_order_refund', 'shop_subscription', 'user_request' ), true ) ) { return array(); }

        $data = self::elementor_data( $post_id );
        $page_settings = self::elementor_page_settings( $post_id );
        $conditions = self::sanitize_elementor_value( get_post_meta( $post_id, '_elementor_conditions', true ) );
        $stats = self::elementor_stats( $data );

        $document_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );
        if ( '' === $document_type && class_exists( '\\Elementor\\Plugin' ) ) {
            try {
                $plugin = \Elementor\Plugin::$instance;
                if ( $plugin && isset( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
                    $document = $plugin->documents->get( $post_id, false );
                    if ( $document && method_exists( $document, 'get_name' ) ) { $document_type = (string) $document->get_name(); }
                }
            } catch ( \Throwable $e ) {
                // Meta fallback above is enough; never break export because of an Elementor API change.
            }
        }

        $result = array(
            'id' => (int) $post_id,
            'title' => get_the_title( $post ),
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'parent' => (int) $post->post_parent,
            'permalink' => get_permalink( $post_id ),
            'modified_gmt' => $post->post_modified_gmt,
            'wp_page_template' => (string) get_post_meta( $post_id, '_wp_page_template', true ),
            'elementor' => array(
                'document_type' => $document_type,
                'edit_mode' => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
                'elementor_version' => (string) get_post_meta( $post_id, '_elementor_version', true ),
                'elementor_pro_version' => (string) get_post_meta( $post_id, '_elementor_pro_version', true ),
                'page_settings' => $page_settings,
                'conditions' => $conditions,
                'controls_usage' => self::sanitize_elementor_value( get_post_meta( $post_id, '_elementor_controls_usage', true ) ),
                'page_assets' => self::sanitize_elementor_value( get_post_meta( $post_id, '_elementor_page_assets', true ) ),
                'content' => $data,
                'summary' => $stats,
            ),
        );
        $cache[ $post_id ] = $result;
        return $result;
    }

    public static function elementor_documents_index(): array {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        $items = array();
        foreach ( self::elementor_document_ids() as $id ) {
            $doc = self::elementor_document( $id );
            if ( ! $doc ) { continue; }
            $summary = $doc['elementor']['summary'] ?? array();
            $items[] = array(
                'id' => $doc['id'],
                'title' => $doc['title'],
                'post_type' => $doc['post_type'],
                'status' => $doc['status'],
                'slug' => $doc['slug'],
                'permalink' => $doc['permalink'],
                'modified_gmt' => $doc['modified_gmt'],
                'document_type' => $doc['elementor']['document_type'] ?? '',
                'edit_mode' => $doc['elementor']['edit_mode'] ?? '',
                'wp_page_template' => $doc['wp_page_template'],
                'conditions' => $doc['elementor']['conditions'] ?? array(),
                'element_count' => (int) ( $summary['element_count'] ?? 0 ),
                'widget_count' => (int) ( $summary['widget_count'] ?? 0 ),
                'widgets' => $summary['widgets'] ?? array(),
                'template_references' => $summary['template_references'] ?? array(),
                'query_ids' => $summary['query_ids'] ?? array(),
            );
        }
        $cache = $items;
        return $cache;
    }

    public static function elementor_templates(): array {
        $out = array();
        foreach ( self::elementor_documents_index() as $doc ) {
            if ( 'elementor_library' !== $doc['post_type'] ) { continue; }
            $out[] = $doc;
        }
        return $out;
    }

    public static function elementor_registered_widgets(): array {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        $out = array();
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return $out; }
        try {
            $plugin = \Elementor\Plugin::$instance;
            if ( ! $plugin || ! isset( $plugin->widgets_manager ) || ! method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) { return $out; }
            foreach ( $plugin->widgets_manager->get_widget_types() as $name => $widget ) {
                $row = array( 'name' => (string) $name );
                if ( is_object( $widget ) ) {
                    if ( method_exists( $widget, 'get_title' ) ) { $row['title'] = (string) $widget->get_title(); }
                    if ( method_exists( $widget, 'get_categories' ) ) { $row['categories'] = array_values( (array) $widget->get_categories() ); }
                    $row['class'] = get_class( $widget );
                }
                $out[] = $row;
            }
        } catch ( \Throwable $e ) {}
        usort( $out, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
        $cache = $out;
        return $cache;
    }

    private static function sanitize_code_like_string( string $value ): string {
        $value = preg_replace( '/(?i)(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|password|passwd|secret)(\\s*[=:]\\s*)(["\\\'][^"\\\']+["\\\'])/', '$1$2"[REDACTED_BY_FRESH_CORE]"', $value );
        $value = preg_replace( '/(?i)(authorization\\s*[:=]\\s*["\\\']?bearer\\s+)[A-Za-z0-9._~+\\/=-]+/', '$1[REDACTED_BY_FRESH_CORE]', $value );
        return (string) $value;
    }

    public static function elementor_pro_resources(): array {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        $resources = array();
        $post_types = get_post_types( array(), 'objects' );
        foreach ( $post_types as $name => $obj ) {
            if ( 'elementor_library' === $name ) { continue; }
            if ( ! preg_match( '/^(?:elementor_|e-)/', $name ) ) { continue; }
            if ( preg_match( '/(?:submission|entry|log|action)/i', $name ) ) { continue; }

            $posts = get_posts( array(
                'post_type' => $name,
                'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                'posts_per_page' => 500,
                'orderby' => 'ID',
                'order' => 'ASC',
            ) );
            if ( ! $posts ) { continue; }

            $items = array();
            foreach ( $posts as $post ) {
                $all_meta = get_post_meta( $post->ID );
                $meta = array();
                foreach ( $all_meta as $key => $values ) {
                    if ( 0 !== strpos( (string) $key, '_elementor_' ) ) { continue; }
                    $value = get_post_meta( $post->ID, $key, true );
                    $value = self::decode_elementor_value( $value );
                    if ( is_string( $value ) && false !== stripos( (string) $key, 'code' ) ) {
                        $value = self::sanitize_code_like_string( $value );
                    }
                    $meta[ $key ] = self::sanitize_elementor_value( $value, (string) $key );
                }
                $items[] = array(
                    'id' => (int) $post->ID,
                    'title' => get_the_title( $post ),
                    'post_type' => $name,
                    'status' => $post->post_status,
                    'slug' => $post->post_name,
                    'modified_gmt' => $post->post_modified_gmt,
                    'meta' => $meta,
                );
            }
            $resources[ $name ] = array(
                'label' => $obj->labels->name ?? $name,
                'count' => count( $items ),
                'items' => $items,
            );
        }
        $cache = $resources;
        return $cache;
    }

    public static function elementor_site_settings(): array {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        $active_kit = (int) get_option( 'elementor_active_kit', 0 );
        $kit = array();
        if ( $active_kit > 0 ) {
            $post = get_post( $active_kit );
            $kit = array(
                'id' => $active_kit,
                'title' => $post ? get_the_title( $post ) : '',
                'status' => $post ? $post->post_status : '',
                'page_settings' => self::elementor_page_settings( $active_kit ),
                'content' => self::elementor_data( $active_kit ),
            );
        }

        $safe_options = array(
            'elementor_cpt_support', 'elementor_disable_color_schemes', 'elementor_disable_typography_schemes',
            'elementor_container_width', 'elementor_space_between_widgets', 'elementor_stretched_section_container',
            'elementor_page_title_selector', 'elementor_viewport_lg', 'elementor_viewport_md', 'elementor_default_generic_fonts',
            'elementor_load_fa4_shim', 'elementor_font_display', 'elementor_css_print_method', 'elementor_editor_break_lines',
        );
        $options = array();
        foreach ( $safe_options as $name ) {
            $value = get_option( $name, null );
            if ( null !== $value ) { $options[ $name ] = self::sanitize_elementor_value( $value, $name ); }
        }

        global $wpdb;
        $experiment_rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'elementor_experiment-%' ORDER BY option_name", ARRAY_A );
        $experiments = array();
        foreach ( $experiment_rows ?: array() as $row ) { $experiments[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] ); }

        $cache = array(
            'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
            'elementor_pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
            'active_kit_id' => $active_kit,
            'active_kit' => $kit,
            'safe_options' => $options,
            'experiments' => self::sanitize_elementor_value( $experiments ),
            'registered_widgets' => self::elementor_registered_widgets(),
        );
        return $cache;
    }

    public static function elementor_overview(): array {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }
        $index = self::elementor_documents_index();
        $by_type = array();
        $by_post_type = array();
        foreach ( $index as $doc ) {
            $type = $doc['document_type'] ?: 'unknown';
            $by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
            $by_post_type[ $doc['post_type'] ] = ( $by_post_type[ $doc['post_type'] ] ?? 0 ) + 1;
        }
        ksort( $by_type );
        ksort( $by_post_type );
        $cache = array(
            'available' => defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ),
            'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
            'elementor_pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
            'document_count' => count( $index ),
            'documents_by_type' => $by_type,
            'documents_by_post_type' => $by_post_type,
            'front_page_id' => (int) get_option( 'page_on_front', 0 ),
            'posts_page_id' => (int) get_option( 'page_for_posts', 0 ),
            'pro_resource_types' => array_map( static function( $resource ) { return (int) ( $resource['count'] ?? 0 ); }, self::elementor_pro_resources() ),
        );
        return $cache;
    }

    public static function module_summaries(): array {
        global $wpdb;
        $tables = array_column( self::db_tables(), 'name' );
        $summary = array(
            'affiliate' => array( 'detected' => false, 'tables' => array() ),
            'event' => array( 'detected' => false, 'tables' => array() ),
            'live' => array( 'detected' => false, 'tables' => array() ),
        );
        foreach ( $tables as $table ) {
            if ( 0 === strpos( $table, $wpdb->prefix . 'fresh_affiliate_' ) ) {
                $summary['affiliate']['detected'] = true;
                $summary['affiliate']['tables'][] = self::table_count( $table );
            } elseif ( 0 === strpos( $table, $wpdb->prefix . 'fresh_event_' ) ) {
                $summary['event']['detected'] = true;
                $summary['event']['tables'][] = self::table_count( $table );
            } elseif ( 0 === strpos( $table, $wpdb->prefix . 'alf_' ) ) {
                $summary['live']['detected'] = true;
                $summary['live']['tables'][] = self::table_count( $table );
            }
        }

        $summary['affiliate']['version'] = defined( 'FRESH_AFFILIATE_VERSION' ) ? FRESH_AFFILIATE_VERSION : null;
        $summary['event']['version'] = defined( 'FRESH_EVENT_VERSION' ) ? FRESH_EVENT_VERSION : null;
        $summary['live']['version'] = defined( 'ALF_VERSION' ) ? ALF_VERSION : null;

        $summary['affiliate']['settings'] = self::safe_option_subset( 'fresh_affiliate_settings', array(
            'commission_type','commission_rate','shop_commission_rate','event_commission_rate','live_commission_rate','cookie_days','attribution','auto_register_customer','auto_approve'
        ) );
        $summary['live']['settings'] = self::safe_option_subset( 'alf_settings', array( 'app_name','event_category','live_product_id','trial_days','access_days' ) );

        if ( post_type_exists( 'product' ) ) {
            $event_ids = get_posts( array(
                'post_type' => 'product', 'post_status' => array( 'publish','draft','pending','private' ), 'posts_per_page' => -1,
                'fields' => 'ids', 'meta_key' => '_fresh_event_enabled', 'meta_value' => 'yes',
            ) );
            $summary['event']['event_products'] = count( $event_ids );
        }

        return $summary;
    }

    private static function table_count( string $table ): array {
        global $wpdb;
        if ( ! self::safe_table_name( $table ) ) { return array( 'name' => $table, 'rows' => null ); }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );
        return array( 'name' => $table, 'rows' => null === $count ? null : (int) $count );
    }

    private static function safe_option_subset( string $option, array $keys ): array {
        $value = get_option( $option, array() );
        if ( ! is_array( $value ) ) { return array(); }
        $out = array();
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $value ) ) { $out[ $key ] = self::scalarize( $value[ $key ] ); }
        }
        return $out;
    }

    public static function category_snapshot(): array {
        $wanted = array( 'category', 'product_cat', 'product_tag' );
        $out = array();
        foreach ( $wanted as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
            $count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
            if ( is_wp_error( $count ) ) { $count = 0; }
            $pages = max( 1, (int) ceil( (int) $count / 1000 ) );
            $all = array();
            for ( $p = 1; $p <= $pages && $p <= 10; $p++ ) {
                $all = array_merge( $all, self::terms( $taxonomy, $p, 1000 ) );
            }
            $out[ $taxonomy ] = array( 'count' => (int) $count, 'terms' => $all, 'truncated' => (int) $count > count( $all ) );
        }
        return $out;
    }

    public static function snapshot(): array {
        return array(
            'generated_at_gmt' => gmdate( 'c' ),
            'privacy' => array(
                'user_rows_exposed' => false,
                'order_customer_data_exposed' => false,
                'secrets_exposed' => false,
                'database_schema_only' => true,
            ),
            'manifest' => self::manifest(),
            'post_types' => self::post_types(),
            'taxonomies' => self::taxonomies(),
            'categories' => self::category_snapshot(),
            'menus' => self::menus(),
            'elementor_overview' => self::elementor_overview(),
            'elementor_templates' => self::elementor_templates(),
            'fresh_modules' => self::module_summaries(),
            'database_tables' => self::db_tables(),
        );
    }
}
