<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Fresh_Core_Auth {
    public static function enabled(): bool {
        return 'yes' === get_option( 'fresh_core_connector_enabled', 'no' );
    }

    public static function get_key(): string {
        $key = (string) get_option( 'fresh_core_access_key', '' );
        if ( strlen( $key ) < 32 ) {
            $key = wp_generate_password( 64, false, false );
            update_option( 'fresh_core_access_key', $key, false );
        }
        return $key;
    }

    public static function authorize( WP_REST_Request $request ) {
        if ( ! self::enabled() ) {
            return new WP_Error( 'fresh_core_disabled', 'Fresh Core connector is disabled.', array( 'status' => 503 ) );
        }

        $provided = trim( (string) $request->get_header( 'x-fresh-core-key' ) );
        if ( '' === $provided ) {
            $provided = trim( (string) $request->get_param( 'fresh_core_key' ) );
        }

        $expected = self::get_key();
        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error( 'fresh_core_forbidden', 'Invalid Fresh Core access key.', array( 'status' => 403 ) );
        }

        return true;
    }

    public static function write_enabled(): bool {
        return 'yes' === get_option( 'fresh_core_write_enabled', 'no' );
    }

    public static function get_write_key(): string {
        $key = (string) get_option( 'fresh_core_write_key', '' );
        if ( strlen( $key ) < 32 ) {
            $key = wp_generate_password( 64, false, false );
            update_option( 'fresh_core_write_key', $key, false );
        }
        return $key;
    }

    public static function regenerate_write_key(): string {
        $key = wp_generate_password( 64, false, false );
        update_option( 'fresh_core_write_key', $key, false );
        return $key;
    }

    public static function authorize_write( WP_REST_Request $request ) {
        if ( ! self::write_enabled() ) {
            return new WP_Error( 'fresh_core_write_disabled', 'Fresh Core write bridge is disabled.', array( 'status' => 503 ) );
        }

        $provided = trim( (string) $request->get_header( 'x-fresh-core-write-key' ) );
        $expected = self::get_write_key();

        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error( 'fresh_core_write_forbidden', 'Invalid Fresh Core write key.', array( 'status' => 403 ) );
        }

        return true;
    }

    public static function permission_write( WP_REST_Request $request ): bool {
        return true === self::authorize_write( $request );
    }

    public static function permission_write_or_error( WP_REST_Request $request ) {
        return self::authorize_write( $request );
    }

    public static function permission( WP_REST_Request $request ): bool {
        return true === self::authorize( $request );
    }

    public static function permission_or_error( WP_REST_Request $request ) {
        return self::authorize( $request );
    }

    private static function raw_header( string $name ): string {
        $key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        return isset( $_SERVER[ $key ] ) ? trim( (string) wp_unslash( $_SERVER[ $key ] ) ) : '';
    }

    private static function is_fresh_core_request(): bool {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( false !== strpos( $uri, '/wp-json/fresh-core/v1/' ) ) { return true; }
        $route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
        return 0 === strpos( $route, '/fresh-core/v1/' );
    }

    /**
     * Some security plugins block every anonymous REST request before route
     * permission callbacks run. Fresh Core authenticates with its own scoped
     * keys, so a valid Fresh Core key may safely clear that generic blocker for
     * this namespace only. The route permission callback still performs the
     * definitive read/write authorization afterwards.
     */
    public static function allow_key_authenticated_rest( $result ) {
        if ( ! self::is_fresh_core_request() ) { return $result; }

        $read = self::raw_header( 'x-fresh-core-key' );
        if ( '' === $read && isset( $_GET['fresh_core_key'] ) ) {
            $read = trim( (string) wp_unslash( $_GET['fresh_core_key'] ) );
        }
        if ( self::enabled() && '' !== $read && hash_equals( self::get_key(), $read ) ) {
            return true;
        }

        $write = self::raw_header( 'x-fresh-core-write-key' );
        if ( self::write_enabled() && '' !== $write && hash_equals( self::get_write_key(), $write ) ) {
            return true;
        }

        // Eda uses a distinct accounting credential. A security plugin can
        // return REST 401 *before* the accounting permission callback runs.
        // Clear that generic block only for the accounting subnamespace and
        // only when the accounting bridge is enabled and its key matches.
        // Fresh_Core_Accounting::authorize() still checks the route permission.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $rest_route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
        $accounting_route = false !== strpos( $uri, '/wp-json/fresh-core/v1/accounting/' )
            || 0 === strpos( $rest_route, '/fresh-core/v1/accounting/' );
        if ( $accounting_route && class_exists( 'Fresh_Core_Accounting' ) && Fresh_Core_Accounting::enabled() ) {
            $accounting = self::raw_header( 'x-fresh-core-accounting-key' );
            if ( '' !== $accounting && hash_equals( Fresh_Core_Accounting::get_key(), $accounting ) ) {
                return true;
            }
        }
        if ( $accounting_route && class_exists( 'Fresh_Core_Accounting' ) && Fresh_Core_Accounting::enabled() ) {
            $invoice_write = self::raw_header( 'x-fresh-core-invoice-write-key' );
            if ( '' !== $invoice_write && hash_equals( Fresh_Core_Accounting::get_invoice_write_key(), $invoice_write ) ) {
                return true;
            }
        }
        return $result;
    }

}
