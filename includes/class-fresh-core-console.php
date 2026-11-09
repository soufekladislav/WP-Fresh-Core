<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Central UI registry only: never loads or executes other Fresh plugins. */
final class Fresh_Core_Console {
    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
        add_action( 'admin_menu', array( __CLASS__, 'group_menus' ), 9999 );
        add_action( 'wp_ajax_fresh_core_save_console_order', array( __CLASS__, 'save_order' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_fonts' ) );
        add_action( 'admin_post_fresh_core_check_all_updates', array( __CLASS__, 'check_all_updates' ) );
        add_action( 'admin_notices', array( __CLASS__, 'context_navigation' ), 5 );
        add_filter( 'plugin_action_links_' . plugin_basename( FRESH_CORE_FILE ), array( __CLASS__, 'core_action_links' ) );
    }

    public static function core_action_links( array $links ): array {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=fresh-crackers-core' ) ) . '">Nastavení</a>' );
        $links[] = '<a href="' . esc_url( Fresh_Core_Updater::product_url() ) . '" target="_blank" rel="noopener noreferrer">Dokumentace</a>';
        $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=fresh-crackers' ) ) . '">Nástěnka</a>';
        return $links;
    }

    public static function check_all_updates(): void {
        if ( ! current_user_can( 'update_plugins' ) ) { wp_die( 'Nedostatečná oprávnění.', 403 ); }
        check_admin_referer( 'fresh_core_check_all_updates' );
        Fresh_Core_Updater::clear_cache();
        delete_site_transient( 'update_plugins' );
        if ( ! function_exists( 'wp_update_plugins' ) ) { require_once ABSPATH . 'wp-includes/update.php'; }
        wp_update_plugins();
        $manifest = Fresh_Core_Updater::fetch_manifest( true );
        $notice = is_wp_error( $manifest ) ? $manifest->get_error_message() : 'Kontrola dokončena. Nalezené aktualizace jsou zobrazeny v přehledu.';
        set_transient( 'fresh_core_update_check_' . get_current_user_id(), $notice, 120 );
        wp_safe_redirect( admin_url( 'admin.php?page=fresh-crackers' ) );
        exit;
    }

    /** Navigation above original plugin admin pages; keep native forms, tabs and capabilities intact. */
    public static function context_navigation(): void {
        if ( ! current_user_can( 'manage_options' ) || empty( $_GET['page'] ) ) { return; }
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        if ( strpos( $page, 'fresh-crackers' ) === 0 || $page === 'fresh-core' ) { return; }
        foreach ( self::modules() as $module ) {
            $matched = false;
            foreach ( $module['pages'] as $panel ) {
                $part = wp_parse_url( $panel['url'], PHP_URL_QUERY );
                if ( $part ) { parse_str( $part, $query ); if ( isset( $query['page'] ) && $query['page'] === $page ) { $matched = true; break; } }
            }
            if ( ! $matched ) { continue; }
            echo '<div class="fc-context-header" style="background:#fff;border:1px solid #dce6d5;border-left:5px solid #9ec415;border-radius:12px;margin:16px 20px 16px 0;padding:18px 22px;box-shadow:0 3px 14px rgba(0,0,0,.04)">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap"><div style="display:flex;align-items:center;gap:14px"><span class="dashicons ' . esc_attr( $module['icon'] ) . '" style="font-size:26px;width:34px;height:34px;color:#81a712"></span><div><strong style="font-size:20px;display:block">' . esc_html( $module['name'] ) . '</strong><span style="color:#65746a">' . esc_html( $module['description'] ) . '</span></div></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=fresh-crackers' ) ) . '">← Zpět na nástěnku</a></div>';
            if ( count( $module['pages'] ) > 1 ) {
                echo '<nav style="display:flex;gap:8px;flex-wrap:wrap;margin-top:15px" aria-label="Panely modulu">';
                foreach ( $module['pages'] as $panel ) { echo '<a class="button" href="' . esc_url( $panel['url'] ) . '">' . esc_html( $panel['title'] ) . '</a>'; }
                echo '</nav>';
            }
            echo '</div>';
            break;
        }
    }

    private static function fresh_tools(): array {
        return array(
            array('AI Engine', 'Centrální AI služby, modely a konektory.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/09/fresh-ai-engine.jpg', 'https://aiengine.freshcrackers.cz/'),
            array('Ema', 'Fresh Hub – správa práce a společných agend.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/09/fresh-hub-1024x768.png', 'https://hub.freshcrackers.cz/login'),
            array('Linda', 'Sociální sítě, obsah a marketingové workflow.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/09/ai-social-media-1024x768.png', 'https://socialmedia.freshcrackers.cz/?page=login'),
            array('Boban', 'SEO, webová analytika a optimalizace výkonu.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/08/boban-1024x768.png', 'https://webbooster.freshcrackers.cz/'),
            array('Viki', 'E-commerce a obchodní analytika.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/09/viki-1024x768.png', 'https://e-commerce.freshcrackers.cz/admin/'),
            array('Karel', 'AI vývoj a podpora programování.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/09/ai-developer-1024x768.png', 'https://ai-coder.freshcrackers.cz/'),
            array('Eda', 'Finance, doklady a účetní workflow.', 'https://www.freshcrackers.cz/wp-content/uploads/2026/09/eda-finance-1024x768.jpg', 'https://finance.freshcrackers.cz/'),
        );
    }

    public static function enqueue_fonts( $hook ): void {
        if ( strpos( (string) $hook, 'fresh-crackers' ) === false ) { return; }
        wp_enqueue_style( 'fresh-console-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
    }

    public static function register_menu(): void {
        add_menu_page( 'Fresh Crackers', 'Fresh Crackers', 'manage_options', 'fresh-crackers', array( __CLASS__, 'render' ), 'dashicons-screenoptions', 3 );
        add_submenu_page( 'fresh-crackers', 'Nástěnka', 'Nástěnka', 'manage_options', 'fresh-crackers', array( __CLASS__, 'render' ) );
        add_submenu_page( 'fresh-crackers', 'Fresh Core', 'Fresh Core', 'manage_options', 'fresh-crackers-core', array( __CLASS__, 'render_core' ) );
        add_submenu_page( null, 'Fresh aplikace', 'Fresh aplikace', 'manage_options', 'fresh-crackers-module', array( __CLASS__, 'render_module' ) );
    }

    private static function definitions(): array {
        return array(
            'fresh-core' => array( 'name' => 'Fresh Core', 'description' => 'Konektory, monitoring, SEO a API', 'icon' => 'dashicons-admin-network' ),
            'fresh-affiliate' => array( 'name' => 'Fresh Affiliate', 'description' => 'Partneři a provize', 'icon' => 'dashicons-groups' ),
            'fresh-event' => array( 'name' => 'Fresh Event', 'description' => 'Události a registrace', 'icon' => 'dashicons-calendar-alt' ),
            'fresh-sales' => array( 'name' => 'Fresh Sales', 'description' => 'Obchod a kampaně', 'icon' => 'dashicons-chart-line' ),
            'fresh-security' => array( 'name' => 'Fresh Security', 'description' => 'Bezpečnost a skenování', 'icon' => 'dashicons-shield' ),
            'fresh-media' => array( 'name' => 'Fresh Media', 'description' => 'Optimalizace médií', 'icon' => 'dashicons-format-gallery' ),
            'fresh-edu' => array( 'name' => 'Fresh EDU', 'description' => 'Kurzy a vzdělávání', 'icon' => 'dashicons-welcome-learn-more' ),
        );
    }

    /** A future-proof optional extension of the standard WordPress plugin header:
     * Fresh Console: yes
     * Fresh Admin Slug: some-plugin-page
     * Fresh Icon: dashicons-chart-bar
     * Fresh Description: Description
     * Fresh Tabs: Overview|admin.php?page=foo;Settings|admin.php?page=foo-settings
     * URLs are local wp-admin links only. Third-party modules may also register via the filter.
     */
    private static function metadata( string $file ): array {
        $path = WP_PLUGIN_DIR . '/' . $file;
        if ( ! is_file( $path ) ) { return array(); }
        $fields = array( 'console' => 'Fresh Console', 'admin_slug' => 'Fresh Admin Slug', 'icon' => 'Fresh Icon', 'description' => 'Fresh Description', 'tabs' => 'Fresh Tabs' );
        return get_file_data( $path, $fields, 'plugin' );
    }

    private static function clean_admin_url( string $target ): string {
        $target = trim( $target );
        if ( '' === $target || preg_match( '~^(?:https?:)?//~i', $target ) || strpos( $target, '..' ) !== false || preg_match( '/[\r\n]/', $target ) ) { return ''; }
        $target = ltrim( $target, '/' );
        if ( preg_match( '~^(?:admin|edit|tools|options-general|upload|users|post-new|plugins|index|woocommerce|profile|themes)\.php(?:\?.*)?$~', $target ) || preg_match( '~^edit\.php\?.+$~', $target ) ) { return admin_url( $target ); }
        if ( strpos( $target, 'admin.php?' ) === 0 ) { return admin_url( $target ); }
        if ( strpos( $target, '?' ) !== false || strpos( $target, '.php' ) !== false ) { return ''; }
        return admin_url( 'admin.php?page=' . rawurlencode( $target ) );
    }

    /** Identify actual WordPress admin callbacks owned by the plugin rather than guessing slugs. */
    private static function callback_belongs_to( string $hook, string $file ): bool {
        global $wp_filter;
        if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) { return false; }
        $base = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . dirname( $file ) . '/' );
        foreach ( (array) $wp_filter[ $hook ]->callbacks as $callbacks ) {
            foreach ( (array) $callbacks as $entry ) {
                if ( empty( $entry['function'] ) ) { continue; }
                try {
                    $callback = $entry['function'];
                    if ( is_array( $callback ) && count( $callback ) === 2 ) {
                        $reflect = new ReflectionMethod( $callback[0], $callback[1] );
                    } elseif ( $callback instanceof Closure || is_string( $callback ) ) {
                        $reflect = new ReflectionFunction( $callback );
                    } elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
                        $reflect = new ReflectionMethod( $callback, '__invoke' );
                    } else { continue; }
                    $path = $reflect->getFileName();
                    if ( $path && 0 === strpos( wp_normalize_path( $path ), $base ) ) { return true; }
                } catch ( ReflectionException $e ) { continue; }
            }
        }
        return false;
    }

    private static function menu_url( string $page, string $parent ): string {
        if ( preg_match( '~^(?:[a-z0-9_-]+\.php)(?:\?.*)?$~i', $page ) ) { return admin_url( $page ); }
        if ( '' !== $parent && preg_match( '~^[a-z0-9_-]+\.php(?:\?.*)?$~i', $parent ) ) {
            return admin_url( add_query_arg( 'page', $page, $parent ) );
        }
        return admin_url( 'admin.php?page=' . rawurlencode( $page ) );
    }

    private static function admin_pages( string $file, string $name, string $admin_slug, string $tabs_spec ): array {
        global $menu, $submenu;
        $pages = array();
        if ( $admin_slug !== '' ) {
            $url = self::clean_admin_url( $admin_slug );
            if ( $url ) { $pages[ $url ] = array( 'title' => 'Přehled', 'url' => $url ); }
        }
        foreach ( explode( ';', $tabs_spec ) as $spec ) {
            $parts = explode( '|', $spec, 2 );
            if ( count( $parts ) !== 2 ) { continue; }
            $url = self::clean_admin_url( $parts[1] );
            if ( $url ) { $pages[ $url ] = array( 'title' => sanitize_text_field( $parts[0] ), 'url' => $url ); }
        }
        // admin_menu runs after all plugins have registered their WordPress pages.
        $candidates = array();
        foreach ( (array) $menu as $item ) {
            if ( ! isset( $item[2] ) ) { continue; }
            $candidates[] = array( (string) $item[2], '', (string) $item[0] );
        }
        foreach ( (array) $submenu as $parent => $children ) {
            foreach ( (array) $children as $item ) {
                if ( ! isset( $item[2] ) ) { continue; }
                $candidates[] = array( (string) $item[2], (string) $parent, (string) $item[0] );
            }
        }
        foreach ( $candidates as $entry ) {
            list( $page, $parent, $label ) = $entry;
            if ( $page === '' || strpos( $page, 'fresh-crackers' ) !== false ) { continue; }
            if ( ! function_exists( 'get_plugin_page_hookname' ) ) { continue; }
            $hook = get_plugin_page_hookname( $page, $parent );
            // Only routes with an actual callback from this plugin; no guessed URLs.
            if ( ! self::callback_belongs_to( $hook, $file ) ) { continue; }
            $url = self::menu_url( $page, $parent );
            $label = trim( wp_strip_all_tags( $label ) );
            $pages[ $url ] = array( 'title' => sanitize_text_field( $label ?: $name ), 'url' => $url );
        }
        return array_values( $pages );
    }

    /** Publicly safe registry for AI manifests: never includes credentials or private settings. */
    public static function manifest_registry(): array {
        $result = array();
        foreach ( self::modules() as $module ) {
            $result[] = array( 'name' => $module['name'], 'plugin' => $module['id'],
                'version' => $module['version'], 'active' => ! empty( $module['active'] ),
                'description' => $module['description'] );
        }
        return $result;
    }

    public static function modules(): array {
        if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $installed = get_plugins();
        $active = (array) get_option( 'active_plugins', array() );
        $out = array();
        foreach ( $installed as $file => $info ) {
            $is_active = in_array( $file, $active, true ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file ) );
            $slug = dirname( $file );
            $title = (string) ( $info['Name'] ?? $slug );
            $headers = self::metadata( $file );
            $defs = self::definitions();
            $key = null;
            foreach ( $defs as $id => $def ) {
                if ( 0 === strpos( $slug, $id ) || strcasecmp( $title, $def['name'] ) === 0 ) { $key = $id; break; }
            }
            if ( null === $key && 0 !== stripos( $title, 'Fresh ' ) && strtolower( trim( $headers['console'] ?? '' ) ) !== 'yes' ) { continue; }
            if ( strtolower( trim( $headers['console'] ?? '' ) ) === 'no' ) { continue; }
            $def = $key !== null ? $defs[ $key ] : array( 'name' => $title, 'description' => 'Nainstalovaný Fresh modul', 'icon' => 'dashicons-admin-plugins' );
            $description = ! empty( $headers['description'] ) ? sanitize_text_field( $headers['description'] ) : $def['description'];
            $icon = ! empty( $headers['icon'] ) && preg_match( '/^dashicons-[a-z0-9-]+$/', $headers['icon'] ) ? $headers['icon'] : $def['icon'];
            $pages = self::admin_pages( $file, $title, (string) ( $headers['admin_slug'] ?? '' ), (string) ( $headers['tabs'] ?? '' ) );
            if ( $slug === 'fresh-core' ) { array_unshift( $pages, array( 'title' => 'Fresh Core', 'url' => admin_url( 'admin.php?page=fresh-crackers-core' ) ) ); }
            $docs = ! empty( $info['PluginURI'] ) ? esc_url_raw( (string) $info['PluginURI'] ) : '';
            $out[ $file ] = array( 'id' => $file, 'name' => $title, 'description' => $description, 'docs' => $docs,
                'icon' => $icon, 'version' => (string) ( $info['Version'] ?? '' ), 'active' => $is_active, 'pages' => $is_active ? $pages : array() );
        }
        $out = apply_filters( 'fresh_core_console_modules', $out );
        if ( ! is_array( $out ) ) { $out = array(); }
        $order = get_user_meta( get_current_user_id(), 'fresh_core_console_order', true );
        $order = is_array( $order ) ? $order : array();
        uksort( $out, static function ( $a, $b ) use ( $order ) {
            $ia = array_search( $a, $order, true ); $ib = array_search( $b, $order, true );
            $ia = false === $ia ? 100000 : $ia; $ib = false === $ib ? 100000 : $ib;
            return $ia === $ib ? strcmp( $a, $b ) : ( $ia <=> $ib );
        } );
        return $out;
    }

    public static function group_menus(): void {
        // Hide only the legacy Fresh Core Tools link. Other plugins retain their
        // original menus until they explicitly register a tested console adapter.
        remove_submenu_page( 'tools.php', 'fresh-core' );
    }

    public static function save_order(): void {
        check_ajax_referer( 'fresh_console_order', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Forbidden' ), 403 ); }
        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ids'] ) ) : array();
        $allowed = array_keys( self::modules() );
        $ids = array_values( array_unique( array_intersect( $ids, $allowed ) ) );
        update_user_meta( get_current_user_id(), 'fresh_core_console_order', $ids );
        wp_send_json_success( array( 'saved' => count( $ids ) ) );
    }

    public static function render_core(): void { Fresh_Core_Admin::page(); }

    public static function render_module(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Nedostatečná oprávnění.' ) ); }
        $id = isset( $_GET['module'] ) ? sanitize_text_field( wp_unslash( $_GET['module'] ) ) : '';
        $modules = self::modules();
        if ( ! isset( $modules[ $id ] ) ) { echo '<div class="wrap"><h1>Modul nenalezen</h1></div>'; return; }
        $module = $modules[ $id ];
        echo '<div class="wrap fresh-console"><h1>' . esc_html( $module['name'] ) . '</h1><p>' . esc_html( $module['description'] ) . ' · v' . esc_html( $module['version'] ) . '</p>';
        echo '<p>Jednotlivé panely otevírají původní administrační obrazovky, aby zůstaly funkční jejich formuláře, skripty a oprávnění.</p>';
        if ( empty( $module['pages'] ) ) {
            echo '<div class="notice notice-info inline"><p>Plugin nemá ověřenou administrační stránku. Doplň do jeho hlavičky <code>Fresh Admin Slug:</code> nebo <code>Fresh Tabs:</code>. Dosavadní menu pluginu zůstává dostupné.</p></div>';
        } else {
            echo '<nav class="nav-tab-wrapper" aria-label="Panely aplikace">';
            foreach ( $module['pages'] as $page ) {
                echo '<a class="nav-tab" href="' . esc_url( $page['url'] ) . '">' . esc_html( $page['title'] ) . '</a>';
            }
            echo '</nav>';
        }
        echo '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Verze a aktualizace</a>';
        if ( ! empty( $module['docs'] ) ) { echo ' · <a href="' . esc_url( $module['docs'] ) . '" target="_blank" rel="noopener noreferrer">Dokumentace / web pluginu</a>'; }
        echo ' · <a href="' . esc_url( admin_url( 'admin.php?page=fresh-crackers' ) ) . '">← Zpět na nástěnku Fresh Crackers</a></p></div>';
    }


    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $modules = self::modules();
        $metric = Fresh_Core_Telemetry::summary( 24 );
        $export = (string) get_option( 'fresh_core_export_last_run', '' );
        $seo = get_option( 'fresh_core_seo_summary_cached', array() );
        $job = get_option( 'fresh_core_seo_summary_job', array() );
        $update = (string) get_option( 'fresh_core_update_last_check', '' );
        $nonce = wp_create_nonce( 'fresh_console_order' );
        $plugin_updates = get_site_transient( 'update_plugins' );
        $available_updates = is_object( $plugin_updates ) && isset( $plugin_updates->response ) ? (array) $plugin_updates->response : array();
        ?>
        <div class="wrap fresh-console">
            <style>
            .fresh-console{max-width:1260px;font-family:Poppins,Arial,sans-serif}.fresh-console h1{font-size:28px;font-weight:750;margin:0}
            .fresh-console .fc-hero{background:#fff;border:1px solid #dfe5e6;border-radius:16px;padding:23px 26px;margin:20px 0}
            .fresh-console .fc-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
            .fresh-console .fc-logo{display:inline-flex;align-items:center;justify-content:center;background:#9EC415;border-radius:12px;color:#fff;font-size:23px;font-weight:bold;width:46px;height:46px}
            .fresh-console .fc-title{display:flex;align-items:center;gap:13px}.fresh-console .fc-muted{color:#607074}
            .fresh-console .fc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:13px;margin-top:22px}
            .fresh-console .fc-stat,.fresh-console .fc-card{background:#fff;border:1px solid #dfe5e6;border-radius:12px;padding:18px}
            .fresh-console .fc-stat strong{display:block;font-size:21px;margin:7px 0}
            .fresh-console .fc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
            .fresh-console .fc-card{display:grid;grid-template-columns:42px minmax(0,1fr);grid-template-areas:"icon info" "actions actions";align-items:start;gap:12px;min-height:126px;padding:20px;border-radius:15px;box-shadow:0 3px 14px rgba(30,45,15,.035)}
            .fresh-console .fc-card>.dashicons{grid-area:icon;color:#799e08;font-size:30px;width:42px;height:42px;background:#f2f8e5;border-radius:12px;display:flex;align-items:center;justify-content:center}
            .fresh-console .fc-card .fc-info{grid-area:info;min-width:0;overflow-wrap:anywhere}.fresh-console .fc-card strong{display:block;font-size:15px;line-height:1.5}
            .fresh-console .fc-card small{display:block;color:#607074;margin-top:6px;line-height:1.6}.fresh-console .fc-card .fc-version{display:inline-block;background:#f3f7ec;color:#54730a;border-radius:20px;font-weight:600;padding:4px 9px;margin-top:9px;font-size:11px}.fresh-console .fc-card .fc-controls{grid-area:actions;display:flex;gap:8px;align-items:center;margin-top:6px}.fresh-console .fc-card .fc-controls .fc-open{flex:1;text-align:center;border-color:#9ec415;color:#46600a;border-radius:8px;padding:2px 10px;min-width:0}.fresh-console .fc-card .fc-controls .button{border-radius:8px}.fresh-console .fc-card .fc-controls .button:hover{border-color:#e40058;color:#e40058}
            .fresh-console .fc-card[draggable="true"]{cursor:grab}.fresh-console .fc-card.fc-drag{opacity:.45}
            .fresh-console .fc-tool{background:#fff;border:1px solid #dfe5e6;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
            .fresh-console .fc-tool img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block;background:#f1f3ef}
            .fresh-console .fc-tool-body{padding:16px;display:flex;flex:1;flex-direction:column;align-items:start}
            .fresh-console .fc-tool-body strong{font-size:17px}.fresh-console .fc-tool-body p{color:#607074;line-height:1.5;flex:1}
            .fresh-console .fc-controls-compact{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
            .fresh-console .fc-controls-compact .fc-check-link{font-size:11px;color:#526548;margin-right:auto;text-decoration:underline}
            .fresh-console .fc-controls-compact .fc-up,.fresh-console .fc-controls-compact .fc-down{min-width:25px;padding:0 5px}
            .fresh-console .fc-section{margin:25px 0 12px;font-size:18px;font-weight:700}
            .fresh-console .fc-pill{padding:6px 10px;border-radius:20px;background:#edf6d9;color:#40600b;font-weight:600}
            .fresh-console .fc-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}
            @media(max-width:600px){.fresh-console .fc-hero{padding:16px}.fresh-console h1{font-size:23px}}

            .fresh-console .button-primary{background:#9EC415;border-color:#9EC415;color:#000}
            .fresh-console{--fc-primary:#9EC415;--fc-link:#E40058;--fc-secondary:#F3F3F3;color:#000}
            .fresh-console a:not(.button){color:#E40058}
            .fresh-console .fc-card{min-width:0;grid-template-rows:auto minmax(76px,1fr);align-content:start}
            .fresh-console .fc-card .fc-controls{display:grid;grid-template-columns:minmax(0,1fr) auto auto;grid-template-areas:"open up down" "check check check";align-items:center;gap:9px;width:100%;min-width:0}
            .fresh-console .fc-card .fc-controls .fc-open{grid-area:open;display:block;box-sizing:border-box;width:100%;white-space:normal;height:auto;line-height:1.4;padding:10px 12px;background:#9EC415;color:#000;border-color:#9EC415;font-weight:600}
            .fresh-console .fc-card .fc-controls .fc-check-link{grid-area:check;display:block;margin:3px 0 0;font-size:11px;color:#E40058;overflow-wrap:anywhere}
            .fresh-console .fc-card .fc-controls .fc-up{grid-area:up}
            .fresh-console .fc-card .fc-controls .fc-down{grid-area:down}
            .fresh-console .fc-card .fc-controls .fc-unavailable{grid-area:open;font-size:12px;color:#777;line-height:1.6}
            .fresh-console .fc-card .fc-controls .fc-update{grid-column:1/-1;grid-row:3;background:#9EC415;border-color:#9EC415;color:#000;font-weight:600;text-align:center}
            .fresh-console .fc-hero{border-top:4px solid #9EC415}
            @media(max-width:680px){.fresh-console .fc-grid{grid-template-columns:1fr}}
            </style>
            <div class="fc-hero">
                <div class="fc-head"><div class="fc-title"><img class="fc-logo" src="https://www.freshcrackers.cz/wp-content/uploads/2023/10/Fresh-Crackers-Logo.png" alt="Fresh Crackers" style="object-fit:contain;background:#fff"><div><h1>Fresh Crackers</h1><span class="fc-muted">Jedno místo pro aplikace a správu konektoru</span></div></div><span class="fc-pill">Fresh Core <?php echo esc_html( FRESH_CORE_VERSION ); ?></span></div>
                <div class="fc-stats">
                    <div class="fc-stat"><span class="fc-muted">Aktivních Fresh modulů</span><strong><?php echo count(array_filter($modules, static function($m){return !empty($m['active']);})); ?></strong></div>
                    <div class="fc-stat"><span class="fc-muted">Vzorků za 24 h</span><strong><?php echo (int) ( $metric['count'] ?? 0 ); ?></strong><small>Výběrový monitoring</small></div>
                    <div class="fc-stat"><span class="fc-muted">Paměťová špička PHP</span><strong><?php echo esc_html( (string) ( $metric['peak_mb'] ?? 0 ) ); ?> MB</strong></div>
                    <div class="fc-stat"><span class="fc-muted">Poslední statický export</span><strong style="font-size:15px"><?php echo esc_html( Fresh_Core_Admin::local_time( $export ) ); ?></strong><small><?php echo 'yes' === get_option( 'fresh_core_auto_full_export', 'no' ) ? 'Automatický režim' : 'Na vyžádání'; ?></small></div>
                </div>
            </div>
            <h2 class="fc-section">Moje aplikace</h2>
            <p class="fc-muted">Přetažením karet změníš jejich pořadí. Řazení se uloží pouze pro tvůj účet. Původní administrační stránky ostatních pluginů zůstávají dostupné.</p>
            <div class="fc-grid" id="fresh-console-sort">
                <?php foreach ( $modules as $module ) : ?>
                    <div class="fc-card" draggable="true" data-id="<?php echo esc_attr( $module['id'] ); ?>">
                        <span class="dashicons <?php echo esc_attr( $module['icon'] ); ?>"></span>
                        <div class="fc-info"><strong><?php echo esc_html( $module['name'] ); ?></strong><small><?php echo esc_html( $module['description'] ); ?></small><span class="fc-version">v<?php echo esc_html( $module['version'] ); ?></span>
                        <?php if ( isset( $available_updates[ $module['id'] ] ) ) : ?><small style="color:#a44c00;font-weight:700">Dostupná aktualizace: <?php echo esc_html( (string) $available_updates[ $module['id'] ]->new_version ); ?></small><?php endif; ?></div>
                        <div class="fc-controls fc-controls-compact">
                            <?php if ( ! empty( $module['active'] ) && ! empty( $module['pages'] ) ) : ?>
                                <a class="button button-primary fc-open" href="<?php echo esc_url( $module['pages'][0]['url'] ); ?>">Do pluginu</a>
                            <?php elseif ( empty( $module['active'] ) ) : ?>
                                <span class="fc-unavailable">Neaktivní plugin</span>
                            <?php else : ?>
                                <span class="fc-unavailable">Administrace není zaregistrovaná</span>
                            <?php endif; ?>
                            <?php if ( isset( $available_updates[ $module['id'] ] ) && current_user_can('update_plugins') ) : ?>
                                <a class="button fc-update" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $module['id'] ) ), 'upgrade-plugin_' . $module['id'] ) ); ?>">Aktualizovat</a>
                            <?php endif; ?>
                            <a class="fc-check-link" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=fresh_core_check_all_updates'), 'fresh_core_check_all_updates' ) ); ?>">Zkontrolovat aktualizaci</a>
                            <button class="button fc-up" type="button" title="Posunout nahoru" aria-label="Posunout nahoru">↑</button>
                            <button class="button fc-down" type="button" title="Posunout dolů" aria-label="Posunout dolů">↓</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div><p id="fresh-console-sort-result" role="status" class="fc-muted"></p>
            <h2 class="fc-section">Nástroje Fresh Crackers</h2>
            <p class="fc-muted">Otevři aplikaci v nové záložce; přihlášení a oprávnění si spravuje každá aplikace samostatně.</p>
            <div class="fc-grid fc-tools">
              <?php foreach ( self::fresh_tools() as $tool ) : ?>
                <article class="fc-tool">
                  <img src="<?php echo esc_url( $tool[2] ); ?>" alt="" loading="lazy">
                  <div class="fc-tool-body"><strong><?php echo esc_html( $tool[0] ); ?></strong><p><?php echo esc_html( $tool[1] ); ?></p>
                  <a class="button" href="<?php echo esc_url( $tool[3] ); ?>" target="_blank" rel="noopener noreferrer">Otevřít aplikaci ↗</a></div>
                </article>
              <?php endforeach; ?>
            </div>
            <h2 class="fc-section">Provoz a aktualizace</h2>
            <div class="fc-hero">
                <p><strong>SEO souhrn:</strong> <?php echo esc_html( (string) ( $seo['items'] ?? 0 ) ); ?> položek · <?php echo esc_html( Fresh_Core_Admin::local_time( (string) ( $seo['generated_at_gmt'] ?? '' ) ) ); ?> (starší záznam může být bez časové značky)</p>
                <p><strong>SEO přepočet:</strong> <?php echo esc_html( (string) ( $job['status'] ?? 'nespuštěn' ) ); ?><?php if ( isset( $job['offset'] ) ) : ?> · prošlo <?php echo (int) $job['offset']; ?> záznamů<?php endif; ?></p>
                <p><strong>Poslední kontrola aktualizací:</strong> <?php echo esc_html( Fresh_Core_Admin::local_time( $update ) ); ?></p>
                <?php $check_notice = get_transient( 'fresh_core_update_check_' . get_current_user_id() ); if ( $check_notice ) : ?>
                    <p role="status"><?php echo esc_html( $check_notice ); ?></p>
                    <?php delete_transient( 'fresh_core_update_check_' . get_current_user_id() ); ?>
                <?php endif; ?>
                <?php $updates = get_site_transient( 'update_plugins' ); $updates_count = is_object( $updates ) && isset( $updates->response ) ? count( (array) $updates->response ) : 0; ?>
                <p><strong>Dostupné aktualizace:</strong> <?php echo (int) $updates_count; ?> · <strong>Fresh Core:</strong> <?php echo esc_html( (string) get_option( 'fresh_core_update_latest_version', 'nezjištěno' ) ); ?></p>
                <?php if ( current_user_can( 'update_plugins' ) ) : ?>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin:12px 0">
                    <input type="hidden" name="action" value="fresh_core_check_all_updates">
                    <?php wp_nonce_field( 'fresh_core_check_all_updates' ); ?>
                    <button class="button button-primary" type="submit">Zkontrolovat aktualizace všech pluginů nyní</button>
                </form>
                <?php endif; ?>
                <div class="fc-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=fresh-crackers-core' ) ); ?>">Nastavení Fresh Core a akce</a><a class="button" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">Aktualizace WordPressu</a></div>
            </div>
        </div>
        <script>
        (function(){
            const wrap=document.getElementById('fresh-console-sort');if(!wrap)return;
            let dragged=null;const status=document.getElementById('fresh-console-sort-result');
            function save(){const ids=[...wrap.children].map(x=>x.dataset.id),data=new URLSearchParams();data.set('action','fresh_core_save_console_order');data.set('nonce',<?php echo wp_json_encode( $nonce ); ?>);ids.forEach(x=>data.append('ids[]',x));fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data}).then(r=>r.json()).then(r=>{status.textContent=r.success?'Pořadí uloženo.':'Pořadí se nepodařilo uložit.'}).catch(()=>{status.textContent='Chyba ukládání pořadí.'});}
            wrap.addEventListener('dragstart',e=>{dragged=e.target.closest('.fc-card');if(dragged)dragged.classList.add('fc-drag')});
            wrap.addEventListener('dragover',e=>{if(!dragged)return;e.preventDefault();const el=e.target.closest('.fc-card');if(el&&el!==dragged){const box=el.getBoundingClientRect();wrap.insertBefore(dragged,e.clientY<box.top+box.height/2?el:el.nextSibling)}});
            wrap.addEventListener('dragend',()=>{if(dragged){dragged.classList.remove('fc-drag');dragged=null;save()}});
            wrap.addEventListener('click',e=>{const btn=e.target.closest('button');if(!btn)return;const row=btn.closest('.fc-card');if(!row)return;if(btn.classList.contains('fc-up')&&row.previousElementSibling){wrap.insertBefore(row,row.previousElementSibling);save()}if(btn.classList.contains('fc-down')&&row.nextElementSibling){wrap.insertBefore(row.nextElementSibling,row);save()}});
        })();
        </script>
        <?php
    }
}
