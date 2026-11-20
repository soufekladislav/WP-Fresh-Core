<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Ema contact bridge: existing Accounting READ auth, no new credentials. */
final class Fresh_Core_CRM {
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }
    public static function routes(): void {
        register_rest_route( 'fresh-core/v1', '/accounting/contacts', array(
            'methods' => 'GET',
            'permission_callback' => array( 'Fresh_Core_Accounting', 'permission_or_error' ),
            'callback' => array( __CLASS__, 'contacts' ),
            'args' => array(
                'kind' => array( 'type' => 'string', 'default' => 'users' ),
                'after_id' => array( 'type' => 'integer', 'default' => 0 ),
                'limit' => array( 'type' => 'integer', 'default' => 50 ),
            ),
        ) );
    }
    private static function page( array $items, string $kind, int $limit, int $cursor, bool $available = true ): WP_REST_Response {
        $more = count( $items ) > $limit;
        $items = array_slice( $items, 0, $limit );
        $next = $items ? (int) $items[ count( $items ) - 1 ]['source_id'] : $cursor;
        $response = new WP_REST_Response( array(
            'ok' => true, 'available' => $available, 'site' => home_url( '/' ),
            'kind' => $kind, 'items' => $items, 'has_more' => $more, 'next_after_id' => $next,
        ), 200 );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        return $response;
    }
    public static function contacts( WP_REST_Request $request ) {
        $kind = (string) $request->get_param( 'kind' );
        $after = (int) $request->get_param( 'after_id' );
        $limit = (int) $request->get_param( 'limit' );
        if ( ! in_array( $kind, array( 'users', 'elementor_submissions' ), true )
             || $after < 0 || $limit < 1 || $limit > 50 ) {
            return new WP_Error( 'fresh_core_crm_args', 'Invalid contact source or pagination.', array( 'status' => 400 ) );
        }
        return 'users' === $kind ? self::users( $after, $limit ) : self::submissions( $after, $limit );
    }
    private static function users( int $after, int $limit ): WP_REST_Response {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, user_email, display_name, user_registered FROM {$wpdb->users}
             WHERE ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit + 1 ) );
        $items = array();
        foreach ( $rows as $row ) {
            $id = (int) $row->ID;
            $user = get_userdata( $id );
            $email = sanitize_email( (string) $row->user_email );
            // Do not present internal team/admin accounts as customer leads.
            if ( ! is_email( $email ) || ( $user && ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) ) ) ) {
                $items[] = array( 'source_id' => $id, 'skip' => true );
                continue;
            }
            $name = trim( (string) get_user_meta( $id, 'first_name', true ) . ' ' . (string) get_user_meta( $id, 'last_name', true ) );
            if ( '' === $name ) { $name = (string) $row->display_name; }
            $items[] = array(
                'source_id' => $id, 'source_kind' => 'wp_user', 'email' => $email,
                'name' => sanitize_text_field( $name ), 'phone' => '', 'company' => '',
                'form_name' => '', 'source_page' => '', 'occurred_at' => (string) $row->user_registered,
            );
        }
        return self::page( $items, 'users', $limit, $after );
    }
    private static function contact_fields( array $rows ): array {
        $aliases = array(
            'email' => 'email', 'e_mail' => 'email',
            'name' => 'name', 'full_name' => 'name', 'jmeno' => 'name',
            'first_name' => 'first_name', 'last_name' => 'last_name',
            'phone' => 'phone', 'telefon' => 'phone', 'tel' => 'phone',
            'company' => 'company', 'firma' => 'company', 'spolecnost' => 'company',
        );
        $found = array();
        foreach ( $rows as $row ) {
            $key = strtolower( str_replace( '-', '_', trim( (string) $row->key ) ) );
            if ( ! isset( $aliases[ $key ] ) ) { continue; }
            $val = trim( wp_strip_all_tags( (string) $row->value ) );
            if ( '' !== $val && strlen( $val ) <= 300 ) {
                $found[ $aliases[ $key ] ] = sanitize_text_field( $val );
            }
        }
        $email = sanitize_email( (string) ( $found['email'] ?? '' ) );
        if ( ! is_email( $email ) ) { return array(); }
        $name = (string) ( $found['name'] ?? '' );
        if ( '' === $name ) {
            $name = trim( (string) ( $found['first_name'] ?? '' ) . ' ' . (string) ( $found['last_name'] ?? '' ) );
        }
        return array(
            'email' => $email, 'name' => substr( $name, 0, 190 ),
            'phone' => substr( (string) ( $found['phone'] ?? '' ), 0, 60 ),
            'company' => substr( (string) ( $found['company'] ?? '' ), 0, 190 ),
        );
    }
    private static function submissions( int $after, int $limit ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'e_submissions';
        $values_table = $wpdb->prefix . 'e_submissions_values';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ||
             $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $values_table ) ) !== $values_table ) {
            return self::page( array(), 'elementor_submissions', $limit, $after, false );
        }
        $tick = chr( 96 ); // SQL identifier quoting for known WP-owned table names
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, post_id, form_name, created_at FROM {$tick}{$table}{$tick}
             WHERE type = 'submission' AND status <> 'trash'
               AND id > %d ORDER BY id ASC LIMIT %d", $after, $limit + 1 ) );
        if ( ! $rows ) { return self::page( array(), 'elementor_submissions', $limit, $after ); }
        $ids = array_map( static fn($row) => (int) $row->id, $rows );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // Read only whitelisted contact fields even from the database: never
        // fetch message bodies, file URLs or unrelated answers into memory.
        $keys = array( 'email', 'e_mail', 'e-mail', 'name', 'full_name', 'jmeno',
            'first_name', 'last_name', 'phone', 'telefon', 'tel', 'company', 'firma', 'spolecnost' );
        $key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $values = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT submission_id, {$tick}key{$tick}, {$tick}value{$tick} FROM {$tick}{$values_table}{$tick}
             WHERE submission_id IN ({$placeholders}) AND {$tick}key{$tick} IN ({$key_placeholders})",
            ...array_merge( $ids, $keys ) ) );
        $by_id = array();
        foreach ( $values as $v ) { $by_id[ (int) $v->submission_id ][] = $v; }
        $items = array();
        foreach ( $rows as $row ) {
            $id = (int) $row->id;
            $contact = self::contact_fields( $by_id[ $id ] ?? array() );
            if ( ! $contact ) {
                $items[] = array( 'source_id' => $id, 'skip' => true );
                continue;
            }
            $items[] = array_merge( array(
                'source_id' => $id, 'source_kind' => 'elementor_submission',
                'form_name' => sanitize_text_field( (string) $row->form_name ),
                'source_page' => get_permalink( (int) $row->post_id ) ?: '',
                'occurred_at' => (string) $row->created_at,
            ), $contact );
        }
        return self::page( $items, 'elementor_submissions', $limit, $after );
    }
}
