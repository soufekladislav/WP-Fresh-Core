<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Minimal SEO bridge for Fresh Core.
 *
 * Intelligence, auditing and planning live in Web Booster. This class only:
 * - reads normalized SEO metadata from the active provider,
 * - exposes a safe SEO index,
 * - applies a small allow-list of approved metadata changes,
 * - renders metadata itself only when no supported SEO plugin is active.
 */
final class Fresh_Core_SEO {
    const META_TITLE = '_fresh_core_seo_title';
    const META_DESCRIPTION = '_fresh_core_seo_description';
    const META_CANONICAL = '_fresh_core_seo_canonical';
    const META_NOINDEX = '_fresh_core_seo_noindex';
    const META_NOFOLLOW = '_fresh_core_seo_nofollow';

    public static function init(): void {
        add_action( 'fresh_core_seo_summary_batch', array( __CLASS__, 'process_local_summary_batch' ) );
        add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ), 99 );
        add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ), 99 );
        add_action( 'wp_head', array( __CLASS__, 'maybe_remove_core_canonical' ), 0 );
        add_action( 'wp_head', array( __CLASS__, 'render_native_meta' ), 1 );
    }

    public static function detected_providers(): array {
        $out = array( 'fresh_core' );
        if ( function_exists( 'aioseo' ) && class_exists( '\\AIOSEO\\Plugin\\Common\\Models\\Post' ) ) { $out[] = 'aioseo'; }
        if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) { $out[] = 'yoast'; }
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) { $out[] = 'rank_math'; }
        return array_values( array_unique( $out ) );
    }

    public static function provider_mode(): string {
        $mode = sanitize_key( (string) get_option( 'fresh_core_seo_provider_mode', 'auto' ) );
        return in_array( $mode, array( 'auto', 'fresh_core', 'aioseo', 'yoast', 'rank_math' ), true ) ? $mode : 'auto';
    }

    public static function provider(): string {
        $detected = self::detected_providers();
        $mode = self::provider_mode();
        if ( 'auto' !== $mode ) {
            return in_array( $mode, $detected, true ) ? $mode : 'fresh_core';
        }
        foreach ( array( 'aioseo', 'yoast', 'rank_math' ) as $candidate ) {
            if ( in_array( $candidate, $detected, true ) ) { return $candidate; }
        }
        return 'fresh_core';
    }

    public static function native_rendering_active(): bool {
        if ( 'fresh_core' !== self::provider() ) { return false; }
        $external = array_diff( self::detected_providers(), array( 'fresh_core' ) );
        return empty( $external );
    }

    public static function capabilities(): array {
        return array(
            'provider' => self::provider(),
            'provider_mode' => self::provider_mode(),
            'detected_providers' => self::detected_providers(),
            'read' => array(
                'title' => true, 'description' => true, 'canonical' => true, 'noindex' => true, 'nofollow' => true,
                'permalink' => true, 'word_count' => true, 'page_role' => true, 'images' => true,
                'image_alt' => true, 'image_url_resolve' => true,
            ),
            'write' => array(
                'enabled' => Fresh_Core_Auth::write_enabled(),
                'title' => true, 'description' => true, 'canonical' => true, 'noindex' => true, 'nofollow' => true,
                'image_alt' => true, 'schema' => false, 'redirects' => false, 'content' => false, 'batch' => true,
            ),
            'native_provider' => array(
                'metadata' => true, 'robots' => true, 'canonical' => true, 'wp_core_sitemap_compatible' => true, 'rendering_active' => self::native_rendering_active(),
                'social_metadata' => false, 'advanced_schema' => false,
                'safe_to_disable_full_seo_suite' => false,
                'note' => 'Fresh Core 0.5.1 can own core metadata. Before disabling a full SEO suite, verify schema/social/sitemap features used by the site.',
            ),
            'safety' => array(
                'separate_write_key' => true, 'allow_list_only' => true, 'approval_expected_upstream' => true,
            ),
        );
    }

    private static function allowed_post( WP_Post $post ): bool {
        $blocked = array(
            'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset',
            'user_request', 'oembed_cache', 'shop_order', 'shop_order_refund', 'shop_subscription',
        );
        if ( in_array( $post->post_type, $blocked, true ) ) { return false; }
        $obj = get_post_type_object( $post->post_type );
        return $obj && ( $obj->public || in_array( $post->post_type, array( 'product', 'elementor_library' ), true ) );
    }

    private static function bool_meta( int $post_id, string $key ): bool {
        return '1' === (string) get_post_meta( $post_id, $key, true );
    }

    private static function provider_values_from( int $post_id, string $provider ): array {
        $values = array( 'title' => '', 'description' => '', 'canonical' => '', 'noindex' => false, 'nofollow' => false );

        if ( 'aioseo' === $provider ) {
            try {
                if ( class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
                    $model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                    if ( $model ) {
                        $values['title'] = (string) ( $model->title ?? '' );
                        $values['description'] = (string) ( $model->description ?? '' );
                        $values['canonical'] = (string) ( $model->canonical_url ?? '' );
                        $values['noindex'] = ! empty( $model->robots_noindex );
                        $values['nofollow'] = ! empty( $model->robots_nofollow );
                    }
                } else {
                    global $wpdb;
                    $table = $wpdb->prefix . 'aioseo_posts';
                    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
                        $row = $wpdb->get_row( $wpdb->prepare( "SELECT title,description,canonical_url,robots_noindex,robots_nofollow FROM {$table} WHERE post_id=%d ORDER BY id DESC LIMIT 1", $post_id ), ARRAY_A );
                        if ( is_array( $row ) ) {
                            $values['title'] = (string) ( $row['title'] ?? '' );
                            $values['description'] = (string) ( $row['description'] ?? '' );
                            $values['canonical'] = (string) ( $row['canonical_url'] ?? '' );
                            $values['noindex'] = ! empty( $row['robots_noindex'] );
                            $values['nofollow'] = ! empty( $row['robots_nofollow'] );
                        }
                    }
                }
            } catch ( \Throwable $e ) {}
            return $values;
        }

        if ( 'yoast' === $provider ) {
            $values['title'] = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
            $values['description'] = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            $values['canonical'] = (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
            $values['noindex'] = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
            $values['nofollow'] = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
            return $values;
        }

        if ( 'rank_math' === $provider ) {
            $values['title'] = (string) get_post_meta( $post_id, 'rank_math_title', true );
            $values['description'] = (string) get_post_meta( $post_id, 'rank_math_description', true );
            $values['canonical'] = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
            $robots = get_post_meta( $post_id, 'rank_math_robots', true );
            $robots = is_array( $robots ) ? $robots : array();
            $values['noindex'] = in_array( 'noindex', $robots, true );
            $values['nofollow'] = in_array( 'nofollow', $robots, true );
            return $values;
        }

        $values['title'] = (string) get_post_meta( $post_id, self::META_TITLE, true );
        $values['description'] = (string) get_post_meta( $post_id, self::META_DESCRIPTION, true );
        $values['canonical'] = (string) get_post_meta( $post_id, self::META_CANONICAL, true );
        $values['noindex'] = self::bool_meta( $post_id, self::META_NOINDEX );
        $values['nofollow'] = self::bool_meta( $post_id, self::META_NOFOLLOW );
        return $values;
    }

    private static function provider_values( int $post_id ): array {
        return self::provider_values_from( $post_id, self::provider() );
    }

    public static function values( int $post_id ): array {
        return self::provider_values( $post_id );
    }

    public static function import_to_native( string $source = '' ): array {
        $source = sanitize_key( $source ?: self::provider() );
        if ( 'fresh_core' === $source || ! in_array( $source, self::detected_providers(), true ) ) {
            return array( 'ok' => false, 'source' => $source, 'imported' => 0, 'message' => 'Vyber aktivní externí SEO provider.' );
        }
        $types = get_post_types( array( 'public' => true ), 'names' );
        if ( post_type_exists( 'product' ) ) { $types[] = 'product'; }
        $q = new WP_Query( array(
            'post_type' => array_values( array_unique( array_diff( $types, array( 'attachment' ) ) ) ),
            'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC',
        ) );
        $imported = 0; $fields = 0;
        foreach ( $q->posts as $post_id ) {
            $v = self::provider_values_from( (int) $post_id, $source );
            if ( '' !== trim( (string) $v['title'] ) ) { update_post_meta( $post_id, self::META_TITLE, $v['title'] ); $fields++; }
            if ( '' !== trim( (string) $v['description'] ) ) { update_post_meta( $post_id, self::META_DESCRIPTION, $v['description'] ); $fields++; }
            if ( '' !== trim( (string) $v['canonical'] ) ) { update_post_meta( $post_id, self::META_CANONICAL, $v['canonical'] ); $fields++; }
            update_post_meta( $post_id, self::META_NOINDEX, ! empty( $v['noindex'] ) ? '1' : '0' );
            update_post_meta( $post_id, self::META_NOFOLLOW, ! empty( $v['nofollow'] ) ? '1' : '0' );
            $imported++;
        }
        update_option( 'fresh_core_seo_last_import', array( 'source' => $source, 'items' => $imported, 'fields' => $fields, 'time_gmt' => gmdate( 'c' ) ), false );
        Fresh_Core_Export::mark_dirty_simple();
        Fresh_Core_Modules::emit( 'seo.provider.imported', array( 'source' => $source, 'items' => $imported, 'fields' => $fields ), 'boban' );
        return array( 'ok' => true, 'source' => $source, 'imported' => $imported, 'fields' => $fields );
    }

    public static function local_summary(): array {
        // Never build the full site SEO index during dashboard rendering.
        $cached = get_option( 'fresh_core_seo_summary_cached', array() );
        if ( is_array( $cached ) && $cached ) { return $cached; }
        return array( 'items' => 0, 'missing_title' => 0, 'missing_description' => 0, 'noindex' => 0, 'images_missing_alt' => 0, 'provider' => self::provider(), 'mode' => self::provider_mode(), 'pending' => true );
    }

    // Background SEO summary processes at most 35 posts per cron execution. It never
    // builds the complete index in the wp-admin HTTP request.
    public static function schedule_local_summary(): bool {
        $state = get_option( 'fresh_core_seo_summary_job', array() );
        if ( is_array( $state ) && ! empty( $state['status'] ) && 'running' === $state['status'] ) { return false; }
        $types = get_post_types( array( 'public' => true ), 'names' );
        if ( post_type_exists( 'product' ) ) { $types[] = 'product'; }
        $types = array_values( array_unique( array_diff( $types, array( 'attachment' ) ) ) );
        $state = array( 'status' => 'running', 'offset' => 0, 'types' => $types,
            'started_at_gmt' => gmdate( 'c' ), 'updated_at_gmt' => gmdate( 'c' ),
            'summary' => array( 'items' => 0, 'missing_title' => 0, 'missing_description' => 0, 'noindex' => 0, 'images_missing_alt' => 0 ) );
        update_option( 'fresh_core_seo_summary_job', $state, false );
        wp_schedule_single_event( time() + 5, 'fresh_core_seo_summary_batch' );
        return true;
    }

    public static function calculate_local_summary(): array {
        // Legacy public method: now queues work rather than blocking requests.
        $started = self::schedule_local_summary();
        return array( 'ok' => true, 'queued' => $started, 'status' => $started ? 'scheduled' : 'already_running' );
    }

    public static function process_local_summary_batch(): void {
        $state = get_option( 'fresh_core_seo_summary_job', array() );
        if ( ! is_array( $state ) || ( $state['status'] ?? '' ) !== 'running' ) { return; }
        // A transient-style lock avoids overlapping cron workers; update_option is not atomic.
        if ( ! add_option( 'fresh_core_seo_batch_lock', time(), '', false ) ) { return; }
        try {
            $offset = (int) $state['offset'];
            $q = new WP_Query( array( 'post_type' => $state['types'],
                'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
                'posts_per_page' => 35, 'offset' => $offset,
                'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true,
                'suppress_filters' => false ) );
            $ids = $q->posts;
            foreach ( $ids as $id ) {
                $item = self::item( (int) $id );
                if ( ! $item ) { continue; }
                $state['summary']['items']++;
                $seo = $item['seo'] ?? array();
                if ( '' === trim( (string) ( $seo['title'] ?? '' ) ) ) { $state['summary']['missing_title']++; }
                if ( '' === trim( (string) ( $seo['description'] ?? '' ) ) ) { $state['summary']['missing_description']++; }
                if ( ! empty( $seo['noindex'] ) ) { $state['summary']['noindex']++; }
                foreach ( $item['images'] ?? array() as $image ) {
                    if ( '' === trim( (string) ( $image['alt'] ?? '' ) ) ) { $state['summary']['images_missing_alt']++; }
                }
            }
            $state['offset'] = $offset + count( $ids );
            $state['updated_at_gmt'] = gmdate( 'c' );
            if ( count( $ids ) < 35 ) {
                $state['summary']['provider'] = self::provider();
                $state['summary']['mode'] = self::provider_mode();
                $state['summary']['generated_at_gmt'] = gmdate( 'c' );
                update_option( 'fresh_core_seo_summary_cached', $state['summary'], false );
                $state['status'] = 'completed';
                $state['completed_at_gmt'] = gmdate( 'c' );
            }
            update_option( 'fresh_core_seo_summary_job', $state, false );
            if ( $state['status'] === 'running' ) { wp_schedule_single_event( time() + 2, 'fresh_core_seo_summary_batch' ); }
        } finally {
            delete_option( 'fresh_core_seo_batch_lock' );
        }
    }

    private static function visible_word_count( string $html ): int {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );
        if ( '' === $text ) { return 0; }
        $parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? count( $parts ) : 0;
    }

    private static function page_role( int $post_id ): string {
        if ( (int) get_option( 'page_on_front', 0 ) === $post_id ) { return 'front_page'; }
        if ( (int) get_option( 'page_for_posts', 0 ) === $post_id ) { return 'posts_page'; }
        if ( (int) get_option( 'wp_page_for_privacy_policy', 0 ) === $post_id ) { return 'privacy_policy'; }
        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $role ) {
                if ( (int) wc_get_page_id( $role ) === $post_id ) { return 'woocommerce_' . $role; }
            }
        }
        return '';
    }

    private static function image_row( int $attachment_id, string $context, bool $used = true ): array {
        if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) { return array(); }
        $post = get_post( $attachment_id );
        if ( ! $post instanceof WP_Post ) { return array(); }
        $file = get_attached_file( $attachment_id );
        return array(
            'id' => $attachment_id,
            'url' => (string) wp_get_attachment_url( $attachment_id ),
            'filename' => $file ? basename( (string) $file ) : '',
            'title' => get_the_title( $attachment_id ),
            'caption' => trim( wp_strip_all_tags( (string) $post->post_excerpt ) ),
            'description' => trim( wp_strip_all_tags( (string) $post->post_content ) ),
            'alt' => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
            'context' => $context,
            'used' => $used,
        );
    }

    private static function collect_elementor_image_ids( $value, array &$ids ): void {
        if ( is_array( $value ) ) {
            if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && ( isset( $value['url'] ) || isset( $value['source'] ) ) ) {
                $id = (int) $value['id'];
                if ( $id > 0 && wp_attachment_is_image( $id ) ) { $ids[ $id ] = 'elementor'; }
            }
            foreach ( $value as $child ) { self::collect_elementor_image_ids( $child, $ids ); }
        } elseif ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) { self::collect_elementor_image_ids( $decoded, $ids ); }
        }
    }

    private static function images_for_post( int $post_id, string $content ): array {
        $ids = array();
        $featured = (int) get_post_thumbnail_id( $post_id );
        if ( $featured > 0 ) { $ids[ $featured ] = 'featured'; }

        // WooCommerce stores product gallery images outside post_content.
        if ( 'product' === get_post_type( $post_id ) ) {
            $gallery = (string) get_post_meta( $post_id, '_product_image_gallery', true );
            foreach ( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) as $gallery_id ) {
                if ( $gallery_id > 0 && wp_attachment_is_image( $gallery_id ) ) { $ids[ $gallery_id ] = 'woocommerce_gallery'; }
            }
        }

        if ( preg_match_all( '/wp-image-(\d+)/i', $content, $m ) ) {
            foreach ( $m[1] as $id ) { $id = (int) $id; if ( $id > 0 ) { $ids[ $id ] = 'content'; } }
        }
        if ( preg_match_all( '/<img[^>]+src=[\"\']([^\"\']+)[\"\']/i', $content, $m ) ) {
            foreach ( $m[1] as $src ) {
                $id = (int) attachment_url_to_postid( html_entity_decode( (string) $src ) );
                if ( $id > 0 ) { $ids[ $id ] = 'content'; }
            }
        }

        $elementor = get_post_meta( $post_id, '_elementor_data', true );
        if ( $elementor ) { self::collect_elementor_image_ids( $elementor, $ids ); }

        $rows = array();
        foreach ( $ids as $id => $context ) {
            $row = self::image_row( (int) $id, (string) $context, true );
            if ( $row ) { $rows[] = $row; }
        }
        return $rows;
    }

    public static function image_item( int $attachment_id ): array {
        return self::image_row( $attachment_id, 'attachment', true );
    }

    public static function resolve_image_url( string $url ): array {
        $url = esc_url_raw( trim( $url ) );
        if ( '' === $url ) { return array(); }

        $candidates = array( $url );
        $clean = preg_replace( '/[?#].*$/', '', $url );
        if ( $clean && $clean !== $url ) { $candidates[] = $clean; }
        if ( $clean ) {
            // WordPress often renders a generated thumbnail URL while the media
            // library stores the original attachment URL.
            $unsized = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $clean );
            if ( $unsized && $unsized !== $clean ) { $candidates[] = $unsized; }
        }

        $attachment_id = 0;
        foreach ( array_unique( $candidates ) as $candidate ) {
            $attachment_id = (int) attachment_url_to_postid( $candidate );
            if ( $attachment_id > 0 ) { break; }
        }

        if ( $attachment_id <= 0 && $clean ) {
            $uploads = wp_upload_dir();
            $baseurl = isset( $uploads['baseurl'] ) ? rtrim( (string) $uploads['baseurl'], '/' ) : '';
            $relative = '';
            if ( $baseurl && 0 === strpos( $clean, $baseurl . '/' ) ) {
                $relative = ltrim( substr( $clean, strlen( $baseurl ) ), '/' );
            } else {
                // CDN/optimizer may change the host while keeping the uploads path.
                $url_path = (string) wp_parse_url( $clean, PHP_URL_PATH );
                $base_path = $baseurl ? rtrim( (string) wp_parse_url( $baseurl, PHP_URL_PATH ), '/' ) : '';
                if ( $url_path && $base_path ) {
                    $pos = strpos( $url_path, $base_path . '/' );
                    if ( false !== $pos ) {
                        $relative = ltrim( substr( $url_path, $pos + strlen( $base_path ) ), '/' );
                    }
                }
            }
            if ( $relative ) {
                $relative = rawurldecode( $relative );
                $relative = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $relative );
                global $wpdb;
                $attachment_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
                    $relative
                ) );
            }
        }

        if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) { return array(); }
        return array(
            'attachment_id' => $attachment_id,
            'item' => self::image_item( $attachment_id ),
        );
    }

    public static function apply_image_alt( int $attachment_id, string $alt, string $change_id = '' ) {
        if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
            return new WP_Error( 'fresh_core_image_missing', 'Image attachment is missing or invalid.', array( 'status' => 404 ) );
        }
        $alt = sanitize_text_field( $alt );
        $change_id = sanitize_text_field( $change_id );
        $existing = self::find_logged_change( $change_id, $attachment_id );
        $before = self::image_item( $attachment_id );
        if ( $existing ) {
            return array( 'ok' => true, 'duplicate' => true, 'change_id' => $change_id, 'before' => $before, 'after' => $before, 'original_time_gmt' => (string) ( $existing['time_gmt'] ?? '' ) );
        }
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        clean_post_cache( $attachment_id );
        Fresh_Core_Export::mark_dirty_simple();
        $after = self::image_item( $attachment_id );
        self::log_change( array(
            'change_id' => $change_id, 'post_id' => $attachment_id, 'kind' => 'image_alt',
            'fields' => array( 'alt' ), 'time_gmt' => gmdate( 'c' ),
        ) );
        return array( 'ok' => true, 'change_id' => $change_id, 'before' => $before, 'after' => $after );
    }

    public static function item( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || ! self::allowed_post( $post ) ) { return array(); }

        $seo = self::provider_values( $post_id );
        $excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
        if ( '' === $excerpt ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 35, '' );
        }

        return array(
            'id' => (int) $post_id,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'url' => get_permalink( $post_id ),
            'slug' => $post->post_name,
            'wp_title' => get_the_title( $post_id ),
            'modified_gmt' => $post->post_modified_gmt,
            'word_count' => self::visible_word_count( (string) $post->post_content ),
            'excerpt' => $excerpt,
            'page_role' => self::page_role( $post_id ),
            'images' => self::images_for_post( $post_id, (string) $post->post_content ),
            'seo' => array(
                'provider' => self::provider(),
                'title' => trim( (string) $seo['title'] ),
                'description' => trim( (string) $seo['description'] ),
                'canonical' => trim( (string) $seo['canonical'] ),
                'noindex' => (bool) $seo['noindex'],
                'nofollow' => (bool) $seo['nofollow'],
                'fallback_title' => get_the_title( $post_id ),
                'fallback_description' => $excerpt,
            ),
        );
    }

    public static function index(): array {
        $types = get_post_types( array( 'public' => true ), 'names' );
        if ( post_type_exists( 'product' ) ) { $types[] = 'product'; }
        $types = array_values( array_unique( array_diff( $types, array( 'attachment' ) ) ) );

        $q = new WP_Query( array(
            'post_type' => $types,
            'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ) );

        $items = array();
        foreach ( $q->posts as $post_id ) {
            $row = self::item( (int) $post_id );
            if ( $row ) { $items[] = $row; }
        }

        return array(
            'generated_at_gmt' => gmdate( 'c' ),
            'provider' => self::provider(),
            'capabilities' => self::capabilities(),
            'items' => $items,
        );
    }

    private static function sanitize_changes( array $changes ): array {
        $out = array();
        if ( array_key_exists( 'title', $changes ) ) {
            $out['title'] = sanitize_text_field( (string) $changes['title'] );
        }
        if ( array_key_exists( 'description', $changes ) ) {
            $out['description'] = sanitize_textarea_field( (string) $changes['description'] );
        }
        if ( array_key_exists( 'canonical', $changes ) ) {
            $out['canonical'] = esc_url_raw( (string) $changes['canonical'] );
        }
        if ( array_key_exists( 'noindex', $changes ) ) {
            $out['noindex'] = (bool) $changes['noindex'];
        }
        if ( array_key_exists( 'nofollow', $changes ) ) {
            $out['nofollow'] = (bool) $changes['nofollow'];
        }
        return $out;
    }

    private static function write_provider( int $post_id, array $changes ): void {
        $provider = self::provider();

        if ( 'aioseo' === $provider ) {
            $model = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
            if ( array_key_exists( 'title', $changes ) ) { $model->title = $changes['title'] ?: null; }
            if ( array_key_exists( 'description', $changes ) ) { $model->description = $changes['description'] ?: null; }
            if ( array_key_exists( 'canonical', $changes ) ) { $model->canonical_url = $changes['canonical'] ?: null; }
            if ( array_key_exists( 'noindex', $changes ) || array_key_exists( 'nofollow', $changes ) ) {
                $model->robots_default = false;
                if ( array_key_exists( 'noindex', $changes ) ) { $model->robots_noindex = (bool) $changes['noindex']; }
                if ( array_key_exists( 'nofollow', $changes ) ) { $model->robots_nofollow = (bool) $changes['nofollow']; }
            }
            $model->save();
            return;
        }

        if ( 'yoast' === $provider ) {
            if ( array_key_exists( 'title', $changes ) ) { update_post_meta( $post_id, '_yoast_wpseo_title', $changes['title'] ); }
            if ( array_key_exists( 'description', $changes ) ) { update_post_meta( $post_id, '_yoast_wpseo_metadesc', $changes['description'] ); }
            if ( array_key_exists( 'canonical', $changes ) ) { update_post_meta( $post_id, '_yoast_wpseo_canonical', $changes['canonical'] ); }
            if ( array_key_exists( 'noindex', $changes ) ) {
                $changes['noindex'] ? update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' ) : delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
            }
            if ( array_key_exists( 'nofollow', $changes ) ) {
                $changes['nofollow'] ? update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' ) : delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow' );
            }
            return;
        }

        if ( 'rank_math' === $provider ) {
            if ( array_key_exists( 'title', $changes ) ) { update_post_meta( $post_id, 'rank_math_title', $changes['title'] ); }
            if ( array_key_exists( 'description', $changes ) ) { update_post_meta( $post_id, 'rank_math_description', $changes['description'] ); }
            if ( array_key_exists( 'canonical', $changes ) ) { update_post_meta( $post_id, 'rank_math_canonical_url', $changes['canonical'] ); }
            if ( array_key_exists( 'noindex', $changes ) || array_key_exists( 'nofollow', $changes ) ) {
                $current = get_post_meta( $post_id, 'rank_math_robots', true );
                $current = is_array( $current ) ? $current : array();
                $was_noindex = in_array( 'noindex', $current, true );
                $was_nofollow = in_array( 'nofollow', $current, true );
                $current = array_values( array_diff( $current, array( 'index', 'noindex', 'follow', 'nofollow' ) ) );
                $noindex = array_key_exists( 'noindex', $changes ) ? (bool) $changes['noindex'] : $was_noindex;
                $nofollow = array_key_exists( 'nofollow', $changes ) ? (bool) $changes['nofollow'] : $was_nofollow;
                $current[] = $noindex ? 'noindex' : 'index';
                $current[] = $nofollow ? 'nofollow' : 'follow';
                update_post_meta( $post_id, 'rank_math_robots', array_values( array_unique( $current ) ) );
            }
            return;
        }

        if ( array_key_exists( 'title', $changes ) ) { update_post_meta( $post_id, self::META_TITLE, $changes['title'] ); }
        if ( array_key_exists( 'description', $changes ) ) { update_post_meta( $post_id, self::META_DESCRIPTION, $changes['description'] ); }
        if ( array_key_exists( 'canonical', $changes ) ) { update_post_meta( $post_id, self::META_CANONICAL, $changes['canonical'] ); }
        if ( array_key_exists( 'noindex', $changes ) ) { update_post_meta( $post_id, self::META_NOINDEX, $changes['noindex'] ? '1' : '0' ); }
        if ( array_key_exists( 'nofollow', $changes ) ) { update_post_meta( $post_id, self::META_NOFOLLOW, $changes['nofollow'] ? '1' : '0' ); }
    }

    private static function log_change( array $entry ): void {
        $log = get_option( 'fresh_core_change_log', array() );
        $log = is_array( $log ) ? $log : array();
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, 1000 );
        update_option( 'fresh_core_change_log', $log, false );
    }

    private static function find_logged_change( string $change_id, int $post_id ): ?array {
        if ( '' === $change_id ) { return null; }
        $log = get_option( 'fresh_core_change_log', array() );
        $log = is_array( $log ) ? $log : array();
        foreach ( $log as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            if ( (string) ( $entry['change_id'] ?? '' ) === $change_id && (int) ( $entry['post_id'] ?? 0 ) === $post_id ) {
                return $entry;
            }
        }
        return null;
    }

    public static function apply( int $post_id, array $changes, string $change_id = '' ) {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || ! self::allowed_post( $post ) ) {
            return new WP_Error( 'fresh_core_seo_post', 'Post is missing or not allowed for SEO updates.', array( 'status' => 404 ) );
        }

        $changes = self::sanitize_changes( $changes );
        if ( ! $changes ) {
            return new WP_Error( 'fresh_core_seo_changes', 'No supported SEO changes were supplied.', array( 'status' => 400 ) );
        }

        $change_id = sanitize_text_field( $change_id );
        $existing = self::find_logged_change( $change_id, $post_id );
        if ( $existing ) {
            $current = self::item( $post_id );
            return array(
                'ok' => true,
                'duplicate' => true,
                'change_id' => $change_id,
                'provider' => self::provider(),
                'before' => $current,
                'after' => $current,
                'original_time_gmt' => (string) ( $existing['time_gmt'] ?? '' ),
            );
        }

        $before = self::item( $post_id );
        try {
            self::write_provider( $post_id, $changes );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'fresh_core_seo_provider', $e->getMessage(), array( 'status' => 500 ) );
        }

        clean_post_cache( $post_id );
        Fresh_Core_Export::mark_dirty_simple();
        $after = self::item( $post_id );

        self::log_change( array(
            'change_id' => $change_id,
            'post_id' => $post_id,
            'provider' => self::provider(),
            'fields' => array_keys( $changes ),
            'time_gmt' => gmdate( 'c' ),
        ) );
        Fresh_Core_Modules::emit( 'seo.updated', array( 'post_id' => $post_id, 'provider' => self::provider(), 'change_id' => $change_id ), 'boban' );

        return array(
            'ok' => true,
            'change_id' => $change_id,
            'provider' => self::provider(),
            'before' => $before,
            'after' => $after,
        );
    }

    public static function filter_document_title( string $title ): string {
        if ( ! self::native_rendering_active() || ! is_singular() ) { return $title; }
        $post_id = get_queried_object_id();
        $custom = trim( (string) get_post_meta( $post_id, self::META_TITLE, true ) );
        return '' !== $custom ? $custom : $title;
    }

    public static function filter_robots( array $robots ): array {
        if ( ! self::native_rendering_active() || ! is_singular() ) { return $robots; }
        $post_id = get_queried_object_id();
        if ( self::bool_meta( $post_id, self::META_NOINDEX ) ) { $robots['noindex'] = true; }
        if ( self::bool_meta( $post_id, self::META_NOFOLLOW ) ) { $robots['nofollow'] = true; }
        return $robots;
    }

    public static function maybe_remove_core_canonical(): void {
        if ( ! self::native_rendering_active() || ! is_singular() ) { return; }
        $post_id = get_queried_object_id();
        $canonical = trim( (string) get_post_meta( $post_id, self::META_CANONICAL, true ) );
        if ( '' !== $canonical ) {
            remove_action( 'wp_head', 'rel_canonical' );
        }
    }

    public static function render_native_meta(): void {
        if ( ! self::native_rendering_active() || ! is_singular() ) { return; }
        $post_id = get_queried_object_id();
        $description = trim( (string) get_post_meta( $post_id, self::META_DESCRIPTION, true ) );
        $canonical = trim( (string) get_post_meta( $post_id, self::META_CANONICAL, true ) );

        if ( '' !== $description ) {
            echo "\n<meta name=\"description\" content=\"" . esc_attr( $description ) . "\">\n";
        }
        if ( '' !== $canonical ) {
            echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
        }
    }
}
