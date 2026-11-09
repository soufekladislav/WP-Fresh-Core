<?php
/**
 * Plugin Name: Fresh Core
 * Description: Shared integration layer for Fresh WordPress projects and virtual employees, with sanitized read connectors, modular events and an optional allow-listed write bridge for SEO and content.
 * Version: 0.7.6
 * Author: Fresh Crackers
 * Author URI: https://www.freshcrackers.cz/
 * Plugin URI: https://www.freshcrackers.cz/produkt/fresh-core/
 * Update URI: https://www.freshcrackers.cz/produkt/fresh-core/
 * Requires at least: 6.2
 * Fresh Console: yes
 * Fresh Admin Slug: fresh-crackers-core
 * Fresh Icon: dashicons-admin-network
 * Fresh Description: Manifest, konektory a správa Fresh pluginů
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FRESH_CORE_VERSION', '0.7.6' );
define( 'FRESH_CORE_FILE', __FILE__ );
define( 'FRESH_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once FRESH_CORE_DIR . 'includes/class-fresh-core-telemetry.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-auth.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-accounting.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-eda-events.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-updater.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-data.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-modules.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-seo.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-content-bridge.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-rest.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-export.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-batch-export.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-admin.php';
require_once FRESH_CORE_DIR . 'includes/class-fresh-core-console.php';

register_activation_hook( __FILE__, function () {
    Fresh_Core_Telemetry::install();
    if ( ! get_option( 'fresh_core_access_key' ) ) {
        update_option( 'fresh_core_access_key', wp_generate_password( 64, false, false ), false );
    }
    if ( false === get_option( 'fresh_core_connector_enabled', false ) ) {
        add_option( 'fresh_core_connector_enabled', 'no', '', false );
    }
    if ( false === get_option( 'fresh_core_static_enabled', false ) ) { add_option( 'fresh_core_static_enabled', 'yes', '', false ); }
    if ( false === get_option( 'fresh_core_write_enabled', false ) ) { add_option( 'fresh_core_write_enabled', 'no', '', false ); }
    if ( false === get_option( 'fresh_core_accounting_enabled', false ) ) { add_option( 'fresh_core_accounting_enabled', 'no', '', false ); }
    if ( false === get_option( 'fresh_core_auto_updates', false ) ) { add_option( 'fresh_core_auto_updates', 'no', '', false ); }
    if ( false === get_option( 'fresh_core_update_manifest_url', false ) ) { add_option( 'fresh_core_update_manifest_url', Fresh_Core_Updater::DEFAULT_MANIFEST_URL, '', false ); }
    if ( false === get_option( 'fresh_core_product_url', false ) ) { add_option( 'fresh_core_product_url', Fresh_Core_Updater::DEFAULT_PRODUCT_URL, '', false ); }
    if ( false === get_option( 'fresh_core_seo_provider_mode', false ) ) { add_option( 'fresh_core_seo_provider_mode', 'auto', '', false ); }
    Fresh_Core_Auth::get_write_key();
    Fresh_Core_Accounting::get_key();
    Fresh_Core_Accounting::get_invoice_write_key();
    Fresh_Core_Export::token();
    update_option( 'fresh_core_version', FRESH_CORE_VERSION, false );
    wp_schedule_single_event( time() + 30, Fresh_Core_Export::CRON_HOOK );
} );

add_filter( 'rest_authentication_errors', array( 'Fresh_Core_Auth', 'allow_key_authenticated_rest' ), 9999 );

add_action( 'plugins_loaded', function () {
    Fresh_Core_Telemetry::init();
    Fresh_Core_Modules::init();
    Fresh_Core_SEO::init();
    Fresh_Core_Content_Bridge::init();
    Fresh_Core_Accounting::init();
    Fresh_Core_Eda_Events::init();
    Fresh_Core_REST::init();
    Fresh_Core_Export::init();
    Fresh_Core_Batch_Export::init();
    Fresh_Core_Updater::init();
    if ( is_admin() ) {
        Fresh_Core_Admin::init();
        Fresh_Core_Console::init();
    }
} );

register_deactivation_hook( __FILE__, array( 'Fresh_Core_Export', 'deactivate' ) );
