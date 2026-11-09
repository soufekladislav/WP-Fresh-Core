<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Accounting-only WooCommerce bridge for Eda.
 *
 * This is intentionally separate from the normal Fresh Core READ/WRITE keys so
 * Linda, Boban, Karel and other Fresh apps do not receive billing/customer data
 * they do not need.
 */
final class Fresh_Core_Accounting {
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'woocommerce_view_order', array( __CLASS__, 'customer_invoice_link' ), 22 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_invoice_link' ), 22 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_document_links' ), 25, 4 );
    }

    public static function enabled(): bool {
        return 'yes' === get_option( 'fresh_core_accounting_enabled', 'no' );
    }

    public static function get_key(): string {
        $key = (string) get_option( 'fresh_core_accounting_key', '' );
        if ( strlen( $key ) < 32 ) {
            $key = wp_generate_password( 64, false, false );
            update_option( 'fresh_core_accounting_key', $key, false );
        }
        return $key;
    }

    /** Dedicated write-only credential for publishing an Eda invoice link. */
    public static function get_invoice_write_key(): string {
        $key = (string) get_option( 'fresh_core_invoice_write_key', '' );
        if ( strlen( $key ) < 32 ) {
            $key = wp_generate_password( 64, false, false );
            update_option( 'fresh_core_invoice_write_key', $key, false );
        }
        return $key;
    }

    public static function authorize_invoice_write( WP_REST_Request $request ) {
        if ( ! self::enabled() ) {
            return new WP_Error( 'fresh_core_accounting_disabled', 'Accounting bridge disabled.', array( 'status' => 503 ) );
        }
        $key = trim( (string) $request->get_header( 'x-fresh-core-invoice-write-key' ) );
        if ( '' === $key || ! hash_equals( self::get_invoice_write_key(), $key ) ) {
            return new WP_Error( 'fresh_core_invoice_forbidden', 'Invalid invoice publishing key.', array( 'status' => 403 ) );
        }
        return true;
    }

    public static function regenerate_key(): string {
        $key = wp_generate_password( 64, false, false );
        update_option( 'fresh_core_accounting_key', $key, false );
        return $key;
    }

    public static function authorize( WP_REST_Request $request ) {
        if ( ! self::enabled() ) {
            return new WP_Error( 'fresh_core_accounting_disabled', 'Fresh Core accounting bridge is disabled.', array( 'status' => 503 ) );
        }
        $provided = trim( (string) $request->get_header( 'x-fresh-core-accounting-key' ) );
        if ( '' === $provided ) {
            $provided = trim( (string) $request->get_param( 'fresh_core_accounting_key' ) );
        }
        $expected = self::get_key();
        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error( 'fresh_core_accounting_forbidden', 'Invalid Fresh Core accounting key.', array( 'status' => 403 ) );
        }
        return true;
    }

    public static function permission_or_error( WP_REST_Request $request ) {
        return self::authorize( $request );
    }

    public static function routes(): void {
        $auth = array( 'permission_callback' => array( __CLASS__, 'permission_or_error' ) );

        register_rest_route( 'fresh-core/v1', '/accounting/ping', array_merge( $auth, array(
            'methods' => 'GET',
            'callback' => static function() {
                return new WP_REST_Response( array(
                    'ok' => true,
                    'version' => FRESH_CORE_VERSION,
                    'woocommerce' => class_exists( 'WooCommerce' ),
                    'time_gmt' => gmdate( 'c' ),
                ), 200 );
            },
        ) ) );

        register_rest_route( 'fresh-core/v1', '/accounting/orders', array_merge( $auth, array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'orders' ),
            'args' => array(
                'page' => array( 'type' => 'integer', 'default' => 1 ),
                'per_page' => array( 'type' => 'integer', 'default' => 50 ),
                'modified_after' => array( 'type' => 'string', 'required' => false ),
                'status' => array( 'type' => 'string', 'required' => false ),
                'include_billing' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ) ) );

        register_rest_route( 'fresh-core/v1', '/accounting/orders/(?P<id>\d+)', array_merge( $auth, array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'order' ),
        ) ) );
        register_rest_route( 'fresh-core/v1', '/accounting/orders/(?P<id>\d+)/invoice-link', array(
            'methods' => 'POST',
            'permission_callback' => array( __CLASS__, 'authorize_invoice_write' ),
            'callback' => array( __CLASS__, 'publish_invoice_link' ),
        ) );
        register_rest_route( 'fresh-core/v1', '/accounting/orders/(?P<id>\d+)/advance-link', array(
            'methods' => 'POST',
            'permission_callback' => array( __CLASS__, 'authorize_invoice_write' ),
            'callback' => array( __CLASS__, 'publish_advance_link' ),
        ) );
        register_rest_route( 'fresh-core/v1', '/accounting/orders/(?P<id>\d+)/advance-link/revoke', array(
            'methods' => 'POST',
            'permission_callback' => array( __CLASS__, 'authorize_invoice_write' ),
            'callback' => array( __CLASS__, 'revoke_advance_link' ),
        ) );
        register_rest_route( 'fresh-core/v1', '/accounting/orders/(?P<id>\d+)/invoice-link/revoke', array(
            'methods' => 'POST',
            'permission_callback' => array( __CLASS__, 'authorize_invoice_write' ),
            'callback' => array( __CLASS__, 'revoke_invoice_link' ),
        ) );
    }

    /** Eda publishes only metadata and a long random download URL, never PDF bytes. */
    public static function publish_invoice_link( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'fresh_core_wc_missing', 'WooCommerce unavailable.', array( 'status' => 503 ) );
        }
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order || $order instanceof WC_Order_Refund ) {
            return new WP_Error( 'fresh_core_order_missing', 'Order not found.', array( 'status' => 404 ) );
        }
        $number = trim( (string) $request->get_param( 'number' ) );
        $url = trim( (string) $request->get_param( 'url' ) );
        $total = (string) $request->get_param( 'total' );
        $currency = (string) $request->get_param( 'currency' );
        $issued_on = trim( (string) $request->get_param( 'issued_on' ) );
        if ( ! preg_match( '/^[A-Z0-9-]{6,100}$/', $number ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issued_on ) ) {
            return new WP_Error( 'fresh_core_invoice_bad_data', 'Invalid invoice number or date.', array( 'status' => 400 ) );
        }
        // Long cryptographically-random bearer URL on the trusted Eda host only.
        $parts = wp_parse_url( $url );
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ( ! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'finance.freshcrackers.cz'
             || ! preg_match( '#^/faktura/[A-Za-z0-9_-]{43,100}$#', $path ) || isset($parts['query']) || isset($parts['fragment']) ) {
            return new WP_Error( 'fresh_core_invoice_bad_url', 'Invalid Eda invoice URL.', array( 'status' => 400 ) );
        }
        $credit = self::affiliate_credit_accounting( $order );
        if ( ! $credit['verified'] || ( ! empty( $credit['present'] ) && $credit['status'] !== 'completed' ) ) {
            return new WP_Error( 'fresh_core_invoice_credit_unverified', 'Affiliate credit is not verified for invoicing.', array( 'status' => 409 ) );
        }
        $expected_total = (float) $order->get_total() + (float) $credit['amount'];
        if ( $currency !== $order->get_currency() || ! is_numeric( $total )
             || abs( (float) $total - $expected_total ) > 0.02 ) {
            return new WP_Error( 'fresh_core_invoice_mismatch', 'Invoice amount or currency does not match order.', array( 'status' => 409 ) );
        }
        $existing = (string) $order->get_meta( '_fresh_eda_invoice_number', true );
        if ( '' !== $existing && $existing !== $number ) {
            return new WP_Error( 'fresh_core_invoice_conflict', 'Order has a different invoice number.', array( 'status' => 409 ) );
        }
        $order->update_meta_data( '_fresh_eda_invoice_number', $number );
        $order->update_meta_data( '_fresh_eda_invoice_url', $url );
        $order->update_meta_data( '_fresh_eda_invoice_issued_on', $issued_on );
        $order->update_meta_data( '_fresh_eda_invoice_id', sanitize_text_field( (string) $request->get_param( 'invoice_id' ) ) );
        $order->save();
        if ( '' === $existing ) {
            $order->add_order_note( 'Eda: publikován odkaz na fakturu ' . $number );
        }
        self::send_document_notification( $order, 'invoice', $number, $url );
        return new WP_REST_Response( array( 'ok' => true, 'order_id' => $order->get_id(), 'invoice_number' => $number ), 200 );
    }

    /** Proforma/advance request: never store it as a tax invoice or mark payment as received. */
    public static function publish_advance_link( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'fresh_core_wc_missing', 'WooCommerce unavailable.', array( 'status' => 503 ) );
        }
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order || $order instanceof WC_Order_Refund ) {
            return new WP_Error( 'fresh_core_order_missing', 'Order not found.', array( 'status' => 404 ) );
        }
        $number = trim( (string) $request->get_param( 'number' ) );
        $url = trim( (string) $request->get_param( 'url' ) );
        $issued_on = trim( (string) $request->get_param( 'issued_on' ) );
        $currency = (string) $request->get_param( 'currency' );
        $total = (string) $request->get_param( 'total' );
        if ( ! preg_match( '/^[A-Z0-9-]{6,100}$/', $number ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issued_on ) ) {
            return new WP_Error( 'fresh_core_advance_bad_data', 'Invalid advance number or date.', array( 'status' => 400 ) );
        }
        $parts = wp_parse_url( $url );
        if ( ! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'finance.freshcrackers.cz'
             || ! preg_match( '#^/zaloha/[A-Za-z0-9_-]{43,100}$#', $parts['path'] ?? '' ) || isset($parts['query']) || isset($parts['fragment']) ) {
            return new WP_Error( 'fresh_core_advance_bad_url', 'Invalid Eda advance URL.', array( 'status' => 400 ) );
        }
        if ( $currency !== $order->get_currency() || ! is_numeric( $total ) || abs( (float) $total - (float) $order->get_total() ) > 0.02 ) {
            return new WP_Error( 'fresh_core_advance_mismatch', 'Advance amount or currency does not match order.', array( 'status' => 409 ) );
        }
        $existing = (string) $order->get_meta( '_fresh_eda_advance_number', true );
        if ( '' !== $existing && $existing !== $number ) {
            return new WP_Error( 'fresh_core_advance_conflict', 'A different advance already exists.', array( 'status' => 409 ) );
        }
        $order->update_meta_data( '_fresh_eda_advance_number', $number );
        $order->update_meta_data( '_fresh_eda_advance_url', $url );
        $order->update_meta_data( '_fresh_eda_advance_issued_on', $issued_on );
        $order->save();
        self::send_document_notification( $order, 'advance', $number, $url );
        if ( '' === $existing ) {
            $order->add_order_note( 'Eda: zveřejněna zálohová výzva ' . $number );
        }
        return new WP_REST_Response( array( 'ok' => true, 'order_id' => $order->get_id(), 'advance_number' => $number ), 200 );
    }

    public static function revoke_advance_link( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'fresh_core_wc_missing', 'WooCommerce unavailable.', array( 'status' => 503 ) );
        }
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order || $order instanceof WC_Order_Refund ) {
            return new WP_Error( 'fresh_core_order_missing', 'Order not found.', array( 'status' => 404 ) );
        }
        $number = trim( (string) $request->get_param( 'number' ) );
        if ( $number !== (string) $order->get_meta( '_fresh_eda_advance_number', true ) ) {
            return new WP_Error( 'fresh_core_advance_conflict', 'Advance number does not match.', array( 'status' => 409 ) );
        }
        $order->delete_meta_data( '_fresh_eda_advance_url' );
        $order->save();
        $order->add_order_note( 'Eda: odkaz na zálohovou výzvu ' . $number . ' byl zneplatněn.' );
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    public static function revoke_invoice_link( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'fresh_core_wc_missing', 'WooCommerce unavailable.', array( 'status' => 503 ) );
        }
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order || $order instanceof WC_Order_Refund ) {
            return new WP_Error( 'fresh_core_order_missing', 'Order not found.', array( 'status' => 404 ) );
        }
        $number = trim( (string) $request->get_param( 'number' ) );
        $current_number = (string) $order->get_meta( '_fresh_eda_invoice_number', true );
        if ( $number !== $current_number ) {
            return new WP_Error( 'fresh_core_invoice_conflict', 'Invoice number does not match.', array( 'status' => 409 ) );
        }
        $order->delete_meta_data( '_fresh_eda_invoice_url' );
        $order->save();
        $order->add_order_note( 'Eda: odkaz na fakturu ' . $number . ' byl zneplatněn.' );
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /** Woo customer order view; HPOS-compatible order meta. */

    /** Uses the WooCommerce-configured mail sender. Independent of Fresh Shop Flow.
     *  Publication can happen AFTER the ordinary order email, so we send an independent
     *  document notice once, and additionally include links in future WC emails.
     */
    private static function send_document_notification( $order, string $kind, string $number, string $url ): void {
        if ( ! $order instanceof WC_Order || ! function_exists('WC') ) { return; }
        $meta = $kind === 'advance' ? '_fresh_eda_advance_mail_sent' : '_fresh_eda_invoice_mail_sent';
        if ( (string) $order->get_meta($meta, true) === $number ) { return; }
        $email = sanitize_email( (string) $order->get_billing_email() );
        if ( ! is_email($email) ) { return; }
        $title = $kind === 'advance' ? 'Platební výzva k objednávce' : 'Faktura k objednávce';
        $subject = sprintf('%s %s – objednávka %s', $title, $number, $order->get_order_number());
        $message = '<p>Dobrý den,</p><p>' . esc_html($title) . ' je připravena:</p>' .
            '<p><a href="' . esc_url($url) . '">Stáhnout doklad ' . esc_html($number) . ' (PDF)</a></p>' .
            '<p>' . esc_html(get_bloginfo('name')) . '</p>';
        $mailer = WC()->mailer();
        // Dedicated document email because the default paid-order email may already be gone.
        try {
            $sent = $mailer->send($email, $subject, $mailer->wrap_message($subject, $message), array('Content-Type: text/html; charset=UTF-8'));
        } catch (Throwable $e) {
            $sent = false;
            error_log('Fresh Core Eda document email failed for order ' . $order->get_id());
        }
        if ( $sent ) {
            $order->update_meta_data($meta, $number);
            $order->save();
            $order->add_order_note('Eda: odesláno oznámení zákazníkovi – ' . $number);
        } else {
            $order->add_order_note('Eda: e-mail dokladu se nepodařilo odeslat, opakuj publikování odkazu.');
        }
    }

    public static function email_document_links( $order, $sent_to_admin, $plain_text, $email ): void {
        if ( $sent_to_admin || ! $order instanceof WC_Order ) { return; }
        foreach (array('advance' => 'Zálohová výzva', 'invoice' => 'Faktura') as $kind => $label) {
            $number = (string)$order->get_meta('_fresh_eda_' . $kind . '_number', true);
            $url = (string)$order->get_meta('_fresh_eda_' . $kind . '_url', true);
            if (!$number || !$url) { continue; }
            if ($plain_text) { echo "\n" . $label . ' ' . $number . ': ' . $url . "\n"; }
            else { echo '<p><strong>' . esc_html($label . ' ' . $number) . '</strong>: <a href="' . esc_url($url) . '">Stáhnout PDF</a></p>'; }
        }
    }

    public static function customer_invoice_link( $order_id ): void {
        if ( ! is_user_logged_in() ) { return; }
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) { return; }
        self::render_invoice_link( $order );
    }

    public static function admin_invoice_link( $order ): void {
        if ( ! current_user_can( 'edit_shop_orders' ) || ! $order instanceof WC_Order ) { return; }
        self::render_invoice_link( $order );
    }

    private static function render_invoice_link( $order ): void {
        $number = (string) $order->get_meta( '_fresh_eda_invoice_number', true );
        $url = (string) $order->get_meta( '_fresh_eda_invoice_url', true );
        $advance_number = (string) $order->get_meta( '_fresh_eda_advance_number', true );
        $advance_url = (string) $order->get_meta( '_fresh_eda_advance_url', true );
        if ( '' !== $advance_number && '' !== $advance_url ) {
            echo '<p class="fresh-eda-advance-link"><strong>Zálohová výzva ' . esc_html( $advance_number ) . ':</strong> '
               . '<a href="' . esc_url( $advance_url ) . '" rel="noreferrer noopener" target="_blank">Stáhnout PDF</a></p>';
        }
        if ( '' === $number || '' === $url ) { return; }
        echo '<p class="fresh-eda-invoice-link"><strong>Faktura ' . esc_html( $number ) . ':</strong> '
           . '<a href="' . esc_url( $url ) . '" rel="noreferrer noopener" target="_blank">Stáhnout PDF</a></p>';
    }

    private static function date_iso( $date ): ?string {
        if ( ! $date || ! is_object( $date ) || ! method_exists( $date, 'date' ) ) { return null; }
        return $date->date( 'c' );
    }

    private static function first_meta( $order, array $keys ): string {
        foreach ( $keys as $key ) {
            $value = trim( (string) $order->get_meta( $key, true ) );
            if ( '' !== $value ) { return $value; }
        }
        return '';
    }

    private static function billing( $order ): array {
        $ico_keys = apply_filters( 'fresh_core_accounting_ico_meta_keys', array(
            '_billing_ico', 'billing_ico', '_billing_ic', 'billing_ic', '_billing_company_id', 'billing_company_id', '_billing_company_number', 'billing_company_number', '_billing_ico_number', 'billing_ico_number', 'company_id', '_company_id'
        ), $order );
        $dic_keys = apply_filters( 'fresh_core_accounting_dic_meta_keys', array(
            '_billing_dic', 'billing_dic', '_billing_vat_id', 'billing_vat_id', '_billing_vat_number', 'billing_vat_number', '_billing_vat', 'billing_vat', '_billing_tax_id', 'billing_tax_id', 'vat_id', '_vat_id'
        ), $order );
        $ico = self::first_meta( $order, is_array( $ico_keys ) ? $ico_keys : array() );
        $dic = self::first_meta( $order, is_array( $dic_keys ) ? $dic_keys : array() );
        return array(
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name' => (string) $order->get_billing_last_name(),
            'company' => (string) $order->get_billing_company(),
            'address_1' => (string) $order->get_billing_address_1(),
            'address_2' => (string) $order->get_billing_address_2(),
            'city' => (string) $order->get_billing_city(),
            'state' => (string) $order->get_billing_state(),
            'postcode' => (string) $order->get_billing_postcode(),
            'country' => (string) $order->get_billing_country(),
            'email' => (string) $order->get_billing_email(),
            'phone' => (string) $order->get_billing_phone(),
            'ico' => $ico,
            'dic' => $dic,
        );
    }

    private static function shipping_address( $order ): array {
        return array(
            'first_name' => (string) $order->get_shipping_first_name(),
            'last_name' => (string) $order->get_shipping_last_name(),
            'company' => (string) $order->get_shipping_company(),
            'address_1' => (string) $order->get_shipping_address_1(),
            'address_2' => (string) $order->get_shipping_address_2(),
            'city' => (string) $order->get_shipping_city(),
            'state' => (string) $order->get_shipping_state(),
            'postcode' => (string) $order->get_shipping_postcode(),
            'country' => (string) $order->get_shipping_country(),
        );
    }

    private static function payment_reference( $order ): array {
        $keys = apply_filters( 'fresh_core_accounting_payment_reference_meta_keys', array(
            '_variable_symbol', 'variable_symbol', '_variabilni_symbol', 'variabilni_symbol',
            '_billing_vs', 'billing_vs', '_payment_reference', 'payment_reference'
        ), $order );
        $explicit = self::first_meta( $order, is_array( $keys ) ? $keys : array() );
        $number = (string) $order->get_order_number();
        return array(
            'explicit' => $explicit,
            'suggested_variable_symbol' => preg_match( '/^\d+$/', $number ) ? $number : '',
            'transaction_id' => (string) $order->get_transaction_id(),
        );
    }

    private static function line_items( $order ): array {
        $rows = array();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            $rows[] = array(
                'id' => (int) $item_id,
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'sku' => $product && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
                'name' => (string) $item->get_name(),
                'quantity' => (float) $item->get_quantity(),
                'tax_class' => (string) $item->get_tax_class(),
                'subtotal' => (string) $item->get_subtotal(),
                'subtotal_tax' => (string) $item->get_subtotal_tax(),
                'total' => (string) $item->get_total(),
                'total_tax' => (string) $item->get_total_tax(),
                'taxes' => $item->get_taxes(),
            );
        }
        return $rows;
    }

    private static function shipping_items( $order ): array {
        $rows = array();
        foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
            $rows[] = array(
                'id' => (int) $item_id,
                'name' => (string) $item->get_name(),
                'method_id' => method_exists( $item, 'get_method_id' ) ? (string) $item->get_method_id() : '',
                'instance_id' => method_exists( $item, 'get_instance_id' ) ? (int) $item->get_instance_id() : 0,
                'total' => (string) $item->get_total(),
                'total_tax' => (string) $item->get_total_tax(),
                'taxes' => $item->get_taxes(),
            );
        }
        return $rows;
    }

    private static function fee_items( $order ): array {
        $rows = array();
        foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
            $rows[] = array(
                'id' => (int) $item_id,
                'name' => (string) $item->get_name(),
                'tax_class' => (string) $item->get_tax_class(),
                'total' => (string) $item->get_total(),
                'total_tax' => (string) $item->get_total_tax(),
                'taxes' => $item->get_taxes(),
            );
        }
        return $rows;
    }

    private static function coupon_items( $order ): array {
        $rows = array();
        foreach ( $order->get_items( 'coupon' ) as $item_id => $item ) {
            $rows[] = array(
                'id' => (int) $item_id,
                'code' => (string) $item->get_code(),
                'discount' => (string) $item->get_discount(),
                'discount_tax' => (string) $item->get_discount_tax(),
            );
        }
        return $rows;
    }

    private static function tax_items( $order ): array {
        $rows = array();
        foreach ( $order->get_items( 'tax' ) as $item_id => $item ) {
            $rows[] = array(
                'id' => (int) $item_id,
                'rate_id' => method_exists( $item, 'get_rate_id' ) ? (int) $item->get_rate_id() : 0,
                'label' => method_exists( $item, 'get_label' ) ? (string) $item->get_label() : (string) $item->get_name(),
                'rate_percent' => method_exists( $item, 'get_rate_percent' ) ? (float) $item->get_rate_percent() : null,
                'tax_total' => method_exists( $item, 'get_tax_total' ) ? (string) $item->get_tax_total() : '',
                'shipping_tax_total' => method_exists( $item, 'get_shipping_tax_total' ) ? (string) $item->get_shipping_tax_total() : '',
            );
        }
        return $rows;
    }

    private static function refunds( $order ): array {
        $rows = array();
        if ( ! method_exists( $order, 'get_refunds' ) ) { return $rows; }
        foreach ( $order->get_refunds() as $refund ) {
            if ( ! is_object( $refund ) ) { continue; }
            $rows[] = array(
                'id' => method_exists( $refund, 'get_id' ) ? (int) $refund->get_id() : 0,
                'date_created' => method_exists( $refund, 'get_date_created' ) ? self::date_iso( $refund->get_date_created() ) : null,
                'amount' => method_exists( $refund, 'get_amount' ) ? (string) $refund->get_amount() : '',
                'reason' => method_exists( $refund, 'get_reason' ) ? (string) $refund->get_reason() : '',
                'refunded_by' => method_exists( $refund, 'get_refunded_by' ) ? (int) $refund->get_refunded_by() : 0,
            );
        }
        return $rows;
    }

    /** Independently reconcile Fresh Affiliate wallet transaction to coupon and order metadata. */
    private static function affiliate_credit_accounting( $order ): array {
        $coupons = self::coupon_items( $order );
        $credits = array();
        foreach ( $coupons as $coupon ) {
            if ( 0 === strpos( strtolower( (string) $coupon['code'] ), 'fresh-credit-' ) ) {
                $credits[] = $coupon;
            }
        }
        $amount = (float) $order->get_meta( '_fresh_affiliate_credit', true );
        $wallet_id = absint( $order->get_meta( '_fresh_affiliate_wallet_transaction_id', true ) );
        $affiliate_id = absint( $order->get_meta( '_fresh_affiliate_credit_affiliate_id', true ) );
        if ( ! $credits && $amount <= 0 && ! $wallet_id && ! $affiliate_id ) {
            return array( 'present' => false, 'verified' => true, 'amount' => '0.00', 'status' => 'none' );
        }
        $result = array( 'present' => true, 'verified' => false, 'amount' => '0.00',
                         'wallet_transaction_id' => $wallet_id, 'affiliate_id' => $affiliate_id,
                         'status' => 'unverified', 'reason' => 'credit_incomplete' );
        if ( count( $credits ) !== 1 || ! $wallet_id || ! $affiliate_id || $amount <= 0 ) {
            return $result;
        }
        $coupon = $credits[0];
        $coupon_amount = (float) $coupon['discount'] + (float) $coupon['discount_tax'];
        if ( $coupon_amount <= 0 || abs( $coupon_amount - $amount ) > 0.011 ) {
            $result['reason'] = 'coupon_amount_mismatch'; return $result;
        }
        if ( ! class_exists( 'Fresh_Affiliate_DB' ) || ! method_exists( 'Fresh_Affiliate_DB', 'table' ) ) {
            $result['reason'] = 'affiliate_unavailable'; return $result;
        }
        global $wpdb;
        $table = Fresh_Affiliate_DB::table( 'wallet_transactions' );
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            $result['reason'] = 'invalid_wallet_table'; return $result;
        }
        $tx = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, affiliate_id, order_id, type, amount, status FROM `{$table}` WHERE id = %d LIMIT 1", $wallet_id ) );
        if ( ! $tx || (int) $tx->order_id !== (int) $order->get_id()
             || (int) $tx->affiliate_id !== $affiliate_id || $tx->type !== 'order_discount'
             || ! in_array( $tx->status, array( 'reserved', 'completed' ), true )
             || abs( (float) $tx->amount - $amount ) > 0.011 ) {
            $result['reason'] = 'wallet_transaction_mismatch'; return $result;
        }
        if ( ! class_exists( 'Fresh_Affiliate_DB' ) || ! method_exists( 'Fresh_Affiliate_DB', 'get_affiliate' ) ) {
            $result['reason'] = 'affiliate_lookup_missing'; return $result;
        }
        $affiliate = Fresh_Affiliate_DB::get_affiliate( $affiliate_id );
        if ( ! $affiliate || strtolower( (string) $coupon['code'] ) !== 'fresh-credit-' . absint( $affiliate->user_id ) ) {
            $result['reason'] = 'affiliate_coupon_owner_mismatch'; return $result;
        }
        if ( $order->is_paid() && $tx->status !== 'completed' ) {
            $result['reason'] = 'wallet_not_completed'; return $result;
        }
        $result['verified'] = true;
        $result['amount'] = wc_format_decimal( $amount, wc_get_price_decimals() );
        $result['status'] = $tx->status;
        unset( $result['reason'] );
        return $result;
    }

    private static function payload( $order, bool $include_billing = true ): array {
        $payload = array(
            'id' => (int) $order->get_id(),
            'external_id' => (string) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'customer_id' => method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0,
            'status' => (string) $order->get_status(),
            'date_created' => self::date_iso( $order->get_date_created() ),
            'date_modified' => self::date_iso( $order->get_date_modified() ),
            'date_paid' => self::date_iso( $order->get_date_paid() ),
            'date_completed' => self::date_iso( $order->get_date_completed() ),
            'currency' => (string) $order->get_currency(),
            'prices_include_tax' => method_exists( $order, 'get_prices_include_tax' ) ? (bool) $order->get_prices_include_tax() : null,
            'subtotal' => method_exists( $order, 'get_subtotal' ) ? (string) $order->get_subtotal() : '',
            'discount_total' => (string) $order->get_discount_total(),
            'discount_tax' => method_exists( $order, 'get_discount_tax' ) ? (string) $order->get_discount_tax() : '',
            'shipping_total' => (string) $order->get_shipping_total(),
            'shipping_tax' => method_exists( $order, 'get_shipping_tax' ) ? (string) $order->get_shipping_tax() : '',
            'cart_tax' => method_exists( $order, 'get_cart_tax' ) ? (string) $order->get_cart_tax() : '',
            'total_tax' => (string) $order->get_total_tax(),
            'total' => (string) $order->get_total(),
            'payment_method' => (string) $order->get_payment_method(),
            'payment_method_title' => (string) $order->get_payment_method_title(),
            'transaction_id' => (string) $order->get_transaction_id(),
            'payment_reference' => self::payment_reference( $order ),
            'is_paid' => (bool) $order->is_paid(),
            'needs_payment' => (bool) $order->needs_payment(),
            'created_via' => (string) $order->get_created_via(),
            'customer_note' => (string) $order->get_customer_note(),
            'billing' => $include_billing ? self::billing( $order ) : null,
            'shipping_address' => $include_billing ? self::shipping_address( $order ) : null,
            'items' => self::line_items( $order ),
            'shipping' => self::shipping_items( $order ),
            'fees' => self::fee_items( $order ),
            'coupons' => self::coupon_items( $order ),
            'affiliate_credit_accounting' => self::affiliate_credit_accounting( $order ),
            'taxes' => self::tax_items( $order ),
            'refunds' => self::refunds( $order ),
        );
        return (array) apply_filters( 'fresh_core_accounting_order_payload', $payload, $order, $include_billing );
    }

    public static function order( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'fresh_core_woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 404 ) );
        }
        $id = (int) $request->get_param( 'id' );
        $order = wc_get_order( $id );
        if ( ! $order || $order instanceof WC_Order_Refund ) {
            return new WP_Error( 'fresh_core_order_not_found', 'Order not found.', array( 'status' => 404 ) );
        }
        return new WP_REST_Response( array( 'ok' => true, 'order' => self::payload( $order, true ) ), 200 );
    }

    public static function orders( WP_REST_Request $request ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new WP_REST_Response( array( 'ok' => true, 'available' => false, 'orders' => array() ), 200 );
        }
        $page = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 250, (int) $request->get_param( 'per_page' ) ) );
        $args = array(
            'type' => 'shop_order',
            'limit' => $per_page,
            'page' => $page,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        );
        $status = sanitize_text_field( (string) $request->get_param( 'status' ) );
        if ( '' !== $status ) { $args['status'] = $status; }
        $modified_after = sanitize_text_field( (string) $request->get_param( 'modified_after' ) );
        if ( '' !== $modified_after ) { $args['date_modified'] = '>=' . $modified_after; }

        $include_billing = rest_sanitize_boolean( $request->get_param( 'include_billing' ) );
        $result = wc_get_orders( $args );
        $objects = is_object( $result ) && isset( $result->orders ) ? $result->orders : ( is_array( $result ) ? $result : array() );
        $orders = array();
        foreach ( $objects as $order ) {
            if ( $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ) { $orders[] = self::payload( $order, $include_billing ); }
        }
        $total = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
        $pages = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : ( $total ? 1 : 0 );
        return new WP_REST_Response( array(
            'ok' => true,
            'available' => true,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $pages,
            'has_more' => $page < $pages,
            'include_billing' => $include_billing,
            'orders' => $orders,
        ), 200 );
    }
}
