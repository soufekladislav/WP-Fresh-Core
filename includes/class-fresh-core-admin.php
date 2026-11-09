<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Fresh_Core_Admin {
    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_fresh_core_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_fresh_core_console_toggle', array( __CLASS__, 'save_simple_toggle' ) );
        add_action( 'wp_ajax_fresh_core_reveal_secret', array( __CLASS__, 'reveal_secret' ) );
        add_action( 'admin_post_fresh_core_download_snapshot', array( __CLASS__, 'download_snapshot' ) );
        add_action( 'wp_ajax_fresh_core_export_status', array( __CLASS__, 'export_status' ) );
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_seo_box' ), 40, 2 );
    }

    public static function menu(): void {
        add_management_page( 'Fresh Core', 'Fresh Core', 'manage_options', 'fresh-core', array( __CLASS__, 'page' ) );
    }

    public static function save(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
        check_admin_referer( 'fresh_core_save' );
        $action = sanitize_key( $_POST['fresh_core_action'] ?? 'settings' );

        if ( 'regenerate' === $action ) {
            update_option( 'fresh_core_access_key', wp_generate_password( 64, false, false ), false );
            Fresh_Core_Auth::regenerate_write_key();
            Fresh_Core_Export::regenerate_token();
            self::store_result( 'regenerate', array( 'ok' => true, 'message' => 'Klíče obnoveny. Statický export nebyl spuštěn; po změně klíče obnovte manifest ručně.' ) );
        } elseif ( 'regenerate-accounting' === $action ) {
            Fresh_Core_Accounting::regenerate_key();
            update_option( 'fresh_core_admin_notice', array( 'accounting_key' => 'regenerated' ), false );
        } elseif ( 'check-update' === $action ) {
            Fresh_Core_Updater::clear_cache();
            $result = Fresh_Core_Updater::fetch_manifest( true );
            if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( true ); }
            delete_site_transient( 'update_plugins' );
            if ( function_exists( 'wp_update_plugins' ) ) { wp_update_plugins(); }
            update_option( 'fresh_core_admin_notice', is_wp_error( $result ) ? array( 'update_error' => $result->get_error_message() ) : array( 'latest_version' => (string) ( $result['version'] ?? '' ) ), false );
        } elseif ( 'seo-summary' === $action ) {
            self::store_result( 'seo-summary', Fresh_Core_SEO::calculate_local_summary() );
        } elseif ( 'refresh' === $action ) {
            self::store_result( 'refresh', Fresh_Core_Export::refresh() );
        } elseif ( 'import-seo' === $action ) {
            $source = sanitize_key( $_POST['seo_import_source'] ?? '' );
            $result = Fresh_Core_SEO::import_to_native( $source );
            update_option( 'fresh_core_admin_notice', $result, false );
            self::store_result( 'import-seo', array( 'ok' => ! empty( $result['ok'] ), 'message' => 'SEO import dokončen; statický export označte k obnovení dle potřeby.' ) );
            Fresh_Core_Export::mark_dirty_simple();
        } else {
            update_option( 'fresh_core_connector_enabled', isset( $_POST['connector_enabled'] ) ? 'yes' : 'no', false );
            update_option( 'fresh_core_static_enabled', isset( $_POST['static_enabled'] ) ? 'yes' : 'no', false );
            update_option( 'fresh_core_write_enabled', isset( $_POST['write_enabled'] ) ? 'yes' : 'no', false );
            update_option( 'fresh_core_accounting_enabled', isset( $_POST['accounting_enabled'] ) ? 'yes' : 'no', false );
            update_option( 'fresh_core_auto_updates', isset( $_POST['auto_updates'] ) ? 'yes' : 'no', false );
            $update_manifest_url = isset( $_POST['update_manifest_url'] ) ? esc_url_raw( wp_unslash( $_POST['update_manifest_url'] ) ) : Fresh_Core_Updater::DEFAULT_MANIFEST_URL;
            $product_url = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : Fresh_Core_Updater::DEFAULT_PRODUCT_URL;
            update_option( 'fresh_core_update_manifest_url', $update_manifest_url ?: Fresh_Core_Updater::DEFAULT_MANIFEST_URL, false );
            update_option( 'fresh_core_product_url', $product_url ?: Fresh_Core_Updater::DEFAULT_PRODUCT_URL, false );
            Fresh_Core_Updater::clear_cache();
            $mode = sanitize_key( $_POST['seo_provider_mode'] ?? 'auto' );
            if ( ! in_array( $mode, array( 'auto', 'fresh_core', 'aioseo', 'yoast', 'rank_math' ), true ) ) { $mode = 'auto'; }
            update_option( 'fresh_core_seo_provider_mode', $mode, false );
            Fresh_Core_Export::mark_dirty_simple();
        }
        wp_safe_redirect( admin_url( 'admin.php?page=fresh-crackers-core' ) );
        exit;
    }

    private static function store_result( string $action, array $result ): void {
        update_option( 'fresh_core_last_admin_action', array( 'action' => $action,
            'ok' => ! empty( $result['ok'] ), 'result' => $result,
            'finished_at_gmt' => gmdate( 'c' ) ), false );
    }

    public static function dashboard_widget(): void {
        if ( current_user_can( 'edit_posts' ) ) {
            wp_add_dashboard_widget( 'fresh_core_boban_seo', 'Boban – SEO přehled', array( __CLASS__, 'dashboard_widget_render' ) );
        }
    }

    public static function dashboard_widget_render(): void {
        $s = Fresh_Core_SEO::local_summary();
        $url = 'https://webbooster.freshcrackers.cz';
        ?>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:8px 0 14px">
            <div style="background:#f5f7f1;padding:10px;border-radius:8px"><small>SEO položky</small><br><strong style="font-size:20px"><?php echo (int) $s['items']; ?></strong></div>
            <div style="background:#f5f7f1;padding:10px;border-radius:8px"><small>Chybí title</small><br><strong style="font-size:20px"><?php echo (int) $s['missing_title']; ?></strong></div>
            <div style="background:#f5f7f1;padding:10px;border-radius:8px"><small>Chybí description</small><br><strong style="font-size:20px"><?php echo (int) $s['missing_description']; ?></strong></div>
            <div style="background:#f5f7f1;padding:10px;border-radius:8px"><small>Obrázky bez ALT</small><br><strong style="font-size:20px"><?php echo (int) $s['images_missing_alt']; ?></strong></div>
        </div>
        <?php if ( ! empty( $s['pending'] ) ) : ?><p><em>SEO souhrn nebyl dosud vypočten. Úplná analýza se nespouští při otevření administrace.</em></p><?php endif; ?>
        <p><strong>Provider:</strong> <?php echo esc_html( $s['provider'] ); ?>. Toto je pouze lokální WordPress pohled; priority, Search Console, GA4 a pracovní plán řídí Boban ve Web Boosteru.</p>
        <p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Otevřít Bobana ↗</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=fresh-crackers-core' ) ); ?>">Fresh Core</a></p>
        <?php
    }

    public static function meta_boxes(): void {
        foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type => $obj ) {
            if ( in_array( $type, array( 'attachment', 'revision', 'nav_menu_item', 'shop_order', 'shop_order_refund' ), true ) ) { continue; }
            if ( ! $obj->public && 'product' !== $type ) { continue; }
            add_meta_box( 'fresh-core-boban-seo', 'Boban / Fresh Core SEO', array( __CLASS__, 'seo_box' ), $type, 'normal', 'default' );
        }
    }

    public static function seo_box( WP_Post $post ): void {
        $item = Fresh_Core_SEO::item( (int) $post->ID );
        $seo = is_array( $item ) ? ( $item['seo'] ?? array() ) : array();
        wp_nonce_field( 'fresh_core_seo_box_' . $post->ID, 'fresh_core_seo_nonce' );
        ?>
        <p style="margin-top:0;color:#555">Rychlá editace normalizovaných SEO polí. Strategii, návrhy a měření dopadu řeší primárně Boban ve Web Boosteru.</p>
        <p><label><strong>SEO title</strong><br><input type="text" name="fresh_core_seo_title" value="<?php echo esc_attr( (string) ( $seo['title'] ?? '' ) ); ?>" style="width:100%"></label></p>
        <p><label><strong>Meta description</strong><br><textarea name="fresh_core_seo_description" rows="3" style="width:100%"><?php echo esc_textarea( (string) ( $seo['description'] ?? '' ) ); ?></textarea></label></p>
        <p><label><strong>Canonical</strong><br><input type="url" name="fresh_core_seo_canonical" value="<?php echo esc_attr( (string) ( $seo['canonical'] ?? '' ) ); ?>" style="width:100%"></label></p>
        <p><label><input type="checkbox" name="fresh_core_seo_noindex" value="1" <?php checked( ! empty( $seo['noindex'] ) ); ?>> noindex</label>&nbsp;&nbsp;<label><input type="checkbox" name="fresh_core_seo_nofollow" value="1" <?php checked( ! empty( $seo['nofollow'] ) ); ?>> nofollow</label></p>
        <p><small>Aktivní provider: <strong><?php echo esc_html( Fresh_Core_SEO::provider() ); ?></strong>. Změny zde se zapisují do stejného provideru jako Boban.</small></p>
        <?php
    }

    public static function save_seo_box( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['fresh_core_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fresh_core_seo_nonce'] ) ), 'fresh_core_seo_box_' . $post_id ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
        $changes = array(
            'title' => isset( $_POST['fresh_core_seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['fresh_core_seo_title'] ) ) : '',
            'description' => isset( $_POST['fresh_core_seo_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fresh_core_seo_description'] ) ) : '',
            'canonical' => isset( $_POST['fresh_core_seo_canonical'] ) ? esc_url_raw( wp_unslash( $_POST['fresh_core_seo_canonical'] ) ) : '',
            'noindex' => isset( $_POST['fresh_core_seo_noindex'] ),
            'nofollow' => isset( $_POST['fresh_core_seo_nofollow'] ),
        );
        Fresh_Core_SEO::apply( $post_id, $changes, 'wp-manual-' . wp_generate_uuid4() );
    }

    public static function local_time( string $raw ): string {
        if ( '' === $raw ) { return 'nezjištěno'; }
        try { return ( new DateTimeImmutable( $raw, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'Europe/Prague' ) )->format( 'd.m.Y H:i:s T' ); }
        catch ( Exception $e ) { return 'neplatný čas'; }
    }

    private static function button_form( string $action, string $label, string $class = 'secondary', string $confirm = '' ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px" <?php if ( $confirm ) : ?>onsubmit="return confirm('<?php echo esc_js( $confirm ); ?>');"<?php endif; ?>>
            <?php wp_nonce_field( 'fresh_core_save' ); ?>
            <input type="hidden" name="action" value="fresh_core_save">
            <input type="hidden" name="fresh_core_action" value="<?php echo esc_attr( $action ); ?>">
            <?php submit_button( $label, $class, 'submit', false ); ?>
        </form>
        <?php
    }

    public static function download_snapshot(): void {
        if(!current_user_can('manage_options')) { wp_die('Forbidden',403); }
        check_admin_referer('fresh_core_download_snapshot');
        if(!class_exists('ZipArchive')) { wp_die('PHP ZipArchive není dostupný.'); }
        $base = Fresh_Core_Export::base_dir();
        $manifest = json_decode((string)@file_get_contents($base.'/manifest.json'),true);
        $id = is_array($manifest) ? (string)($manifest['snapshot_id']??'') : '';
        if(!preg_match('/^[a-zA-Z0-9_-]+$/',$id)) { wp_die('Dokončený snapshot nebyl nalezen.'); }
        $dir = $base.'/snapshots/'.$id;
        $real = realpath($dir);
        if(!$real || !is_dir($real) || strpos($real,realpath($base.'/snapshots').DIRECTORY_SEPARATOR)!==0) { wp_die('Snapshot není dostupný.'); }
        $tmp = wp_tempnam('fresh-core-'.$id.'.zip');
        if(!$tmp) { wp_die('Nelze vytvořit dočasný ZIP.'); }
        $zip = new ZipArchive();
        if($zip->open($tmp,ZipArchive::OVERWRITE)!==true) { @unlink($tmp); wp_die('Nelze otevřít ZIP.'); }
        $size=0; $count=0;
        foreach((array)($manifest['files']??array()) as $file) {
            $rel = (string)($file['file']??'');
            if($rel==='' || strpos($rel,'..')!==false || substr($rel,0,1)==='/') continue;
            $path = realpath($real.'/'.$rel);
            if(!$path || strpos($path,$real.DIRECTORY_SEPARATOR)!==0 || !is_file($path)) continue;
            $size += filesize($path); $count++;
            if($size > 250*1024*1024 || $count > 10000) { $zip->close(); @unlink($tmp); wp_die('Export je příliš velký pro přímé stažení.'); }
            $zip->addFile($path,$rel);
        }
        $zip->addFromString('manifest.json',wp_json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="fresh-core-export-'.$id.'.zip"');
        header('Content-Length: '.filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp); @unlink($tmp); exit;
    }

    public static function save_simple_toggle(): void {
        if ( ! current_user_can('manage_options') ) { wp_die('Forbidden',403); }
        check_admin_referer('fresh_core_console_toggle');
        $allowed = array('fresh_core_connector_enabled','fresh_core_static_enabled','fresh_core_write_enabled','fresh_core_accounting_enabled');
        $key = sanitize_key( wp_unslash($_POST['key'] ?? '') );
        if (!in_array($key,$allowed,true)) { wp_die('Invalid option',400); }
        update_option($key, !empty($_POST['enabled']) ? 'yes' : 'no', false);
        wp_safe_redirect( admin_url('admin.php?page=fresh-crackers-core') ); exit;
    }

    public static function reveal_secret(): void {
        if ( !current_user_can('manage_options') ) { wp_send_json_error(array('message'=>'Forbidden'),403); }
        check_ajax_referer('fresh_core_reveal_secret','nonce');
        $type = sanitize_key( wp_unslash($_POST['type'] ?? '') );
        if ($type === 'write') { $value = Fresh_Core_Auth::get_write_key(); }
        elseif ($type === 'accounting') { $value = Fresh_Core_Accounting::get_key(); }
        elseif ($type === 'rest') { $value = Fresh_Core_Auth::get_key(); }
        elseif ($type === 'manifest') { $value = Fresh_Core_Export::manifest_url(); }
        else { wp_send_json_error(array('message'=>'Unknown credential'),400); }
        wp_send_json_success(array('value'=>$value));
    }

    public static function export_status(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array('message'=>'Forbidden'),403 ); }
        check_ajax_referer( 'fresh_core_export_status', 'nonce' );
        $s = Fresh_Core_Batch_Export::status();
        $done = (int) ( $s['completed'] ?? 0 );
        $total = (int) ( $s['total'] ?? 0 );
        $elapsed = (float) ( $s['elapsed_ms'] ?? 0 );
        $remaining = $done > 0 && $total > $done && $elapsed > 0 ? (int) ceil( ( $elapsed / $done ) * ( $total - $done ) / 1000 ) : null;
        wp_send_json_success( array(
            'status' => (string) ( $s['status'] ?? 'not_started' ),
            'completed' => $done, 'total' => $total,
            'percent' => $total ? round( 100 * $done / $total, 1 ) : 0,
            'updated_at' => (string) ( $s['updated_at_gmt'] ?? '' ),
            'estimated_remaining_seconds' => $remaining,
            'last_error' => array_slice( (array) ( $s['errors'] ?? array() ), -1 ),
            'published' => is_file( Fresh_Core_Export::base_dir() . '/manifest.json' ),
        ) );
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        if (isset($_GET['advanced']) && $_GET['advanced'] === '1') { self::legacy_page(); return; }
        $batch = Fresh_Core_Batch_Export::status();
        $status = (string)($batch['status'] ?? 'not_started');
        $completed = (int)($batch['completed'] ?? 0); $total = (int)($batch['total'] ?? 0);
        $progress = $total ? min(100,round(100*$completed/$total,1)) : 0;
        $last = (string)get_option('fresh_core_export_last_run','');
        $manifest = Fresh_Core_Export::manifest_url();
        $export_available = is_file( Fresh_Core_Export::base_dir() . '/manifest.json' );
        $toggles = array(
          'fresh_core_static_enabled'=>array('Manifest','Statický anonymizovaný export pro Fresh aplikace'),
          'fresh_core_connector_enabled'=>array('REST konektor','Povolení čtení přes původní REST API'),
          'fresh_core_write_enabled'=>array('Zápisy','Povolit schválené změny obsahu a SEO'),
          'fresh_core_accounting_enabled'=>array('Eda – účetnictví','Povolit oddělené účetní rozhraní'),
        );
        ?>
        <div class="wrap fc-simple" style="max-width:1180px;font-family:Poppins,Arial,sans-serif">
          <style>
          .fc-simple .fc-banner{background:linear-gradient(115deg,#090909,#283300);color:#fff;border-radius:18px;padding:27px 30px;margin:20px 0;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}
          .fc-simple .fc-banner h1{color:#fff;margin:0 0 6px;font-size:29px}.fc-simple .fc-banner p{color:#dbe8d0;margin:0}
          .fc-simple .fc-panel{background:#fff;border:1px solid #dfe5da;border-radius:14px;padding:22px;margin:17px 0;box-shadow:0 2px 12px #00000006}
          .fc-simple .fc-panel h2{margin:0 0 12px;font-size:20px}
          .fc-simple .fc-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0}
          .fc-simple .fc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
          .fc-simple .fc-progress{background:#e8ede3;border-radius:9px;overflow:hidden;height:10px;margin:13px 0}
          .fc-simple .fc-progress>div{height:100%;background:#9EC415}
          .fc-simple .fc-muted{color:#888888}
          .fc-simple .fc-key{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:7px 0 12px}
          .fc-simple .fc-key input{width:min(500px,100%);flex:1;min-width:140px}
          .fc-simple .fc-toggle{border-top:1px solid #e9eee5;padding:13px 0}
          @media(max-width:600px){.fc-simple .fc-banner{padding:19px}.fc-simple .fc-panel{padding:16px}}
          </style>
          <div class="fc-banner"><div style="display:flex;align-items:center;gap:18px"><img src="https://www.freshcrackers.cz/wp-content/uploads/2023/10/Fresh-Crackers-Logo.png" alt="Fresh Crackers" style="width:54px;height:54px;object-fit:contain"><div><h1>Fresh Core <small style="font-size:14px;color:#cfe5b9">v<?php echo esc_html(FRESH_CORE_VERSION); ?></small></h1><p>Manifest, konektory a bezpečná komunikace s Fresh aplikacemi</p></div></div><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=fresh-crackers')); ?>">← Nástěnka Fresh Crackers</a></div>
          <section class="fc-panel" id="fc-manifest-panel"><h2>Manifest a export</h2>
            <p class="fc-muted">Poslední úspěšný export: <strong><?php echo $last ? esc_html(self::local_time($last)) : 'Zatím není'; ?></strong></p>
            <div class="fc-row"><span>Aktuální úloha: <strong id="fc-export-status"><?php echo esc_html($status); ?></strong></span><strong id="fc-export-progress"><?php echo $completed . ' / ' . $total; ?> kroků (<?php echo esc_html($progress); ?> %)</strong></div>
            <div class="fc-progress" id="fc-export-bar" role="progressbar" aria-valuenow="<?php echo esc_attr($progress); ?>" aria-valuemin="0" aria-valuemax="100"><div style="width:<?php echo esc_attr($progress); ?>%"></div></div>
            <?php if(in_array($status,array('running','queued','waiting_for_scheduler'),true)): ?><p class="fc-muted">Export probíhá postupně. Přesný čas dokončení není garantován; stav se aktualizuje po jednotlivých dávkách.</p><?php endif; ?>
            <?php if($status === 'failed'): ?><p style="color:#a42929"><strong>Poslední úloha selhala:</strong> <?php echo esc_html(wp_json_encode(array_slice((array)($batch['errors']??array()),-1))); ?></p><?php endif; ?>
            <div class="fc-actions">
              <?php self::button_form('refresh','Vytvořit / obnovit export','primary'); ?>
              <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fresh-crackers-core')); ?>">Aktualizovat stav</a>
              <?php if($export_available): ?><a class="button" href="<?php echo esc_url($manifest); ?>" target="_blank" rel="noopener noreferrer">Otevřít manifest</a><?php endif; ?>
              <?php if($export_available): ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fresh_core_download_snapshot'),'fresh_core_download_snapshot')); ?>">Stáhnout export ZIP</a><?php endif; ?>
            </div>
            <p class="fc-muted">Poslední platný manifest zůstává dostupný i při chybě nové úlohy. Nový manifest se publikuje pouze po dokončení a validaci výstupu.</p><p class="fc-muted" id="fc-export-eta" aria-live="polite"></p>
          </section>
          <section class="fc-panel"><h2>Konektory a oprávnění</h2><p class="fc-muted">Eda, Boban, Linda, Karel a další aplikace používají samostatná oprávnění. Změna přepínače se uloží okamžitě.</p>
            <?php foreach($toggles as $key=>$details): ?>
              <div class="fc-row fc-toggle"><div><strong><?php echo esc_html($details[0]); ?></strong><div class="fc-muted"><?php echo esc_html($details[1]); ?></div></div>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('fresh_core_console_toggle'); ?><input type="hidden" name="action" value="fresh_core_console_toggle"><input type="hidden" name="key" value="<?php echo esc_attr($key); ?>">
                <label><input type="checkbox" name="enabled" value="1" <?php checked(get_option($key,'no'),'yes'); ?> onchange="this.form.submit()"> Povolit</label>
              </form></div>
            <?php endforeach; ?>
            <h3>Citlivé přístupové údaje</h3><p class="fc-muted">Klíče nejsou při zobrazení stránky vloženy do jejího HTML. Oprávněný správce je načte jednotlivě tlačítkem Zobrazit.</p>
            <?php foreach(array('manifest'=>'URL manifestu','rest'=>'REST klíč','write'=>'Write klíč','accounting'=>'Accounting klíč') as $type=>$label): ?>
              <label for="fc-secret-<?php echo esc_attr($type); ?>"><strong><?php echo esc_html($label); ?></strong></label>
              <div class="fc-key"><input type="password" id="fc-secret-<?php echo esc_attr($type); ?>" readonly value="" placeholder="Skryto" autocomplete="off"><button class="button fc-reveal" type="button" data-type="<?php echo esc_attr($type); ?>">Zobrazit</button><button class="button fc-copy" type="button" data-type="<?php echo esc_attr($type); ?>" disabled>Kopírovat</button></div>
            <?php endforeach; ?>
            <details><summary>Obnova přístupových klíčů</summary><p>Obnova zneplatní staré klíče. Propojené aplikace bude potřeba připojit znovu.</p><?php self::button_form('regenerate','Obnovit všechny konektorové klíče','secondary','Opravdu obnovit konektorové klíče?'); ?><?php self::button_form('regenerate-accounting','Obnovit Accounting klíč','secondary','Opravdu obnovit klíč pro Edu?'); ?></details>
          </section>
          <section class="fc-panel"><h2>Diagnostika a pokročilá nastavení</h2><p class="fc-muted">Technická nastavení, SEO import a servisní nástroje jsou dostupné zvlášť, aby nepřekážely při běžné práci.</p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fresh-crackers-core&advanced=1')); ?>">Otevřít pokročilá nastavení</a></section>
        </div>
        <script>(function(){
          const node=document.getElementById('fc-manifest-panel'), bar=document.getElementById('fc-export-bar');
          if(!node||!bar)return;
          const nonce=<?php echo wp_json_encode(wp_create_nonce('fresh_core_export_status')); ?>;
          async function poll(){
            try{
              const data=new URLSearchParams({action:'fresh_core_export_status',nonce:nonce});
              const response=await fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'});
              const result=await response.json();if(!result.success)return;
              const s=result.data,pct=Math.max(0,Math.min(100,Number(s.percent)||0));
              document.getElementById('fc-export-status').textContent=s.status;
              document.getElementById('fc-export-progress').textContent=s.completed+' / '+s.total+' kroků ('+pct+' %)';
              bar.setAttribute('aria-valuenow',String(pct));bar.firstElementChild.style.width=pct+'%';
              const msg=document.getElementById('fc-export-eta');
              if(s.status==='failed')msg.textContent='Export selhal. Příčina: '+(s.last_error?.[0]?.message||'neznámá')+'. Poslední dokončený manifest zůstává dostupný.';
              else if(['running','queued','waiting_for_scheduler'].includes(s.status))msg.textContent='Poslední krok: '+s.updated_at+(s.estimated_remaining_seconds!==null?' · Odhad '+Math.ceil(s.estimated_remaining_seconds/60)+' min (orientační)':' · Odhad bude dostupný po prvních dávkách');
              else msg.textContent=s.status==='completed'?'Export dokončen. Pro aktualizované odkazy otevři znovu tuto stránku.':'';
            }catch(e){ /* Stále funguje ruční obnovení stavu. */ }
          }
          poll();setInterval(()=>{if(!document.hidden)poll()},12000);
        })();</script>
        <script>(function(){const nonce=<?php echo wp_json_encode(wp_create_nonce('fresh_core_reveal_secret')); ?>;document.querySelectorAll('.fc-reveal').forEach(function(b){b.addEventListener('click',async function(){const type=b.dataset.type,input=document.getElementById('fc-secret-'+type);if(input.type==='text'){input.type='password';b.textContent='Zobrazit';return;}if(!input.value){const data=new URLSearchParams({action:'fresh_core_reveal_secret',nonce:nonce,type:type});b.disabled=true;try{const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data});const j=await r.json();if(!j.success)throw new Error('Access denied');input.value=j.data.value;input.parentNode.querySelector('.fc-copy').disabled=false;}catch(e){alert('Klíč se nepodařilo načíst.');}finally{b.disabled=false;}}if(input.value){input.type='text';b.textContent='Skrýt';}});});document.querySelectorAll('.fc-copy').forEach(function(b){b.addEventListener('click',function(){const input=document.getElementById('fc-secret-'+b.dataset.type);if(input.value&&navigator.clipboard){navigator.clipboard.writeText(input.value);}});});})();</script>
        <?php
    }

    public static function legacy_page(): void {

        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $enabled = Fresh_Core_Auth::enabled();
        $key = Fresh_Core_Auth::get_key();
        $base = rest_url( 'fresh-core/v1/' );
        $snapshot = add_query_arg( 'fresh_core_key', rawurlencode( $key ), $base . 'snapshot' );
        $static_enabled = Fresh_Core_Export::enabled();
        $static_url = Fresh_Core_Export::manifest_url();
        $last_run = (string) get_option( 'fresh_core_export_last_run', '' );
        $last_result = get_option( 'fresh_core_export_last_result', array() );
        $write_enabled = Fresh_Core_Auth::write_enabled();
        $write_key = Fresh_Core_Auth::get_write_key();
        $write_base = rest_url( 'fresh-core/v1' );
        $accounting_enabled = Fresh_Core_Accounting::enabled();
        $accounting_key = Fresh_Core_Accounting::get_key();
        $invoice_write_key = Fresh_Core_Accounting::get_invoice_write_key();
        $accounting_base = rest_url( 'fresh-core/v1/accounting/' );
        $update_manifest_url = Fresh_Core_Updater::manifest_url();
        $product_url = Fresh_Core_Updater::product_url();
        $auto_updates = Fresh_Core_Updater::auto_enabled();
        $latest_version = (string) get_option( 'fresh_core_update_latest_version', '' );
        $update_last_check = (string) get_option( 'fresh_core_update_last_check', '' );
        $update_last_error = (string) get_option( 'fresh_core_update_last_error', '' );
        ?>
        <div class="wrap">
            <h1>Fresh Core <small style="font-size:14px;color:#666">v<?php echo esc_html( FRESH_CORE_VERSION ); ?></small></h1>
            <?php $operation = get_option( 'fresh_core_last_admin_action', array() ); if ( is_array( $operation ) && $operation ) : ?>
            <div class="notice <?php echo ! empty( $operation['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p>
            <?php echo esc_html( (string) $operation['action'] ); ?>:
            <?php echo esc_html( wp_json_encode( $operation['result'] ?? array() ) ); ?>
            <?php echo esc_html( self::local_time( $operation['finished_at_gmt'] ?? '' ) ); ?>
            </p></div><?php endif; ?>
            <?php $batch = Fresh_Core_Batch_Export::status(); if ($batch) : ?>
            <div class="notice notice-info inline"><p><strong>Export manifestu:</strong>
            <?php echo esc_html( $batch['status'] ?? '?' ); ?> — dávka
            <strong><?php echo (int)($batch['completed']??0); ?>/<?php echo (int)($batch['total']??0); ?></strong>.
            Poslední zpracování: <?php echo esc_html(self::local_time($batch['updated_at_gmt']??'')); ?>.
            <?php if(!empty($batch['errors'])) echo esc_html(wp_json_encode(array_slice($batch['errors'],-1))); ?>
            <?php if(in_array($batch['status']??'',array('queued','running'),true)): ?> Obnovte stránku pro aktuální průběh. <?php endif; ?></p></div>
            <?php endif; ?>
            <?php $fc_metrics = Fresh_Core_Telemetry::summary( 24 ); ?>
            <div style="background:#fff;border:1px solid #dde4d6;border-left:4px solid #9EC415;padding:14px 18px;margin:15px 0;border-radius:8px;max-width:1050px">
                <h2 style="margin-top:0">Provoz a výkon – posledních 24 hodin</h2>
                <p>Vzorkované HTTP požadavky: <strong><?php echo (int) $fc_metrics['count']; ?></strong> · Pomalé/chybové: <strong><?php echo (int) $fc_metrics['slow_or_error']; ?></strong> · Nejvyšší naměřená paměť: <strong><?php echo esc_html( $fc_metrics['peak_mb'] ); ?> MB</strong>.</p>
                <p>Bezpečný vzorkovaný sběr; žádné celé URL, IP adresy, cookies, SQL dotazy ani osobní údaje. Podrobné reporty generovat pouze na vyžádání.</p>
                <p><code>wp eval 'print_r(Fresh_Core_Telemetry::summary(24));'</code></p>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field('fresh_core_save'); ?>
                    <input type="hidden" name="action" value="fresh_core_save"><input type="hidden" name="fresh_core_action" value="seo-summary">
                    <?php submit_button( 'Vypočítat SEO souhrn na vyžádání', 'secondary', 'submit', false ); ?>
                </form>
            </div>
            <p><strong>Společná integrační vrstva Fresh aplikací.</strong> Verze 0.6 zachovává beze změny stávající kontrakty pro Lindu, Bobana, Karla a další Fresh aplikace. Nově přidává oddělený účetní bridge pro Edu a centrální aktualizace z FreshCrackers. Statický konektor zůstává read-only a write bridge přijímá jen povolené operace.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'fresh_core_save' ); ?>
                <input type="hidden" name="action" value="fresh_core_save">
                <input type="hidden" name="fresh_core_action" value="settings">
                <table class="form-table">
                    <tr><th>Statický konektor</th><td><label><input type="checkbox" name="static_enabled" value="1" <?php checked( $static_enabled ); ?>> Povolit anonymizovaný statický JSON konektor (doporučeno)</label></td></tr>
                    <tr><th>Connector Manifest URL</th><td><input id="fresh-core-static-url" type="text" readonly value="<?php echo esc_attr( $static_url ); ?>" style="width:100%;max-width:1100px"><p class="description">Toto je hlavní URL, které mi pošli. Manifest obsahuje odkazy na oddělené soubory pro obsah, taxonomie, databázové schéma, Elementor a Fresh moduly. Náhodná cesta funguje jako revokovatelný bearer token.</p></td></tr>
                    <tr><th>Poslední export</th><td><?php echo $last_run ? esc_html( self::local_time( $last_run ) ) : 'zatím nevytvořen'; ?><?php if ( is_array( $last_result ) && $last_result ) : ?> — <?php echo esc_html( wp_json_encode( $last_result ) ); ?><?php endif; ?></td></tr>
                    <tr><th>REST konektor</th><td><label><input type="checkbox" name="connector_enabled" value="1" <?php checked( $enabled ); ?>> Ponechat původní REST přístup (volitelné)</label></td></tr>
                    <tr><th>REST snapshot</th><td><input type="text" readonly value="<?php echo esc_attr( $snapshot ); ?>" style="width:100%;max-width:1100px"><p class="description">Fallback. Pokud jej blokuje anti-bot ochrana, používej statický Connector Manifest URL výše.</p></td></tr>
                    <tr><th>Write bridge</th><td><label><input type="checkbox" name="write_enabled" value="1" <?php checked( $write_enabled ); ?>> Povolit schválené vzdálené změny přes samostatný write key</label><p class="description">Stejný neveřejný klíč chrání allow-list SEO operací i generický content bridge pro Lindu a další Fresh aplikace. Výchozí stav je vypnuto.</p></td></tr>
                    <tr><th>Write REST base URL</th><td><input type="text" readonly value="<?php echo esc_attr( $write_base ); ?>" style="width:100%;max-width:1100px"></td></tr>
                    <tr><th>Write key</th><td><input type="text" readonly value="<?php echo esc_attr( $write_key ); ?>" style="width:100%;max-width:1100px"><p class="description">Tento klíč nevkládej do veřejného manifestu. Připojené Fresh aplikace jej musí ukládat neveřejně a šifrovaně.</p></td></tr>
                    <tr><th>Accounting bridge</th><td><label><input type="checkbox" name="accounting_enabled" value="1" <?php checked( $accounting_enabled ); ?>> Povolit účetní/fakturační API pro Edu</label><p class="description">Samostatný klíč a samostatná oprávnění. Nemění READ/WRITE konektivitu Lindy, Bobana ani Karla.</p></td></tr>
                    <tr><th>Accounting REST base</th><td><input type="text" readonly value="<?php echo esc_attr( $accounting_base ); ?>" style="width:100%;max-width:1100px"><p class="description"><code>GET /accounting/orders</code> a <code>GET /accounting/orders/&lt;id&gt;</code> vrací plná fakturační data pouze s Accounting key.</p></td></tr>
                    <tr><th>Accounting key</th><td><input type="text" readonly value="<?php echo esc_attr( $accounting_key ); ?>" style="width:100%;max-width:1100px"><p class="description">Používá hlavičku <code>X-Fresh-Core-Accounting-Key</code>. Klíč není součástí statického manifestu.</p></td></tr>
                    <tr><th>Eda Invoice write key</th><td><input type="password" id="eda-invoice-write-key" readonly value="<?php echo esc_attr( $invoice_write_key ); ?>" style="width:100%;max-width:850px"><button type="button" class="button" onclick="var el=document.getElementById('eda-invoice-write-key');el.type=el.type==='password'?'text':'password'">Zobrazit / skrýt</button><p class="description">Oddělený klíč pouze pro přiřazení odkazu na fakturu k objednávce. Předej do Edy v části Fakturace. Nevkládej do veřejného manifestu.</p></td></tr>
                    <tr><th>SEO provider</th><td><select name="seo_provider_mode"><option value="auto" <?php selected( Fresh_Core_SEO::provider_mode(), 'auto' ); ?>>Auto – použít aktivní SEO plugin</option><option value="fresh_core" <?php selected( Fresh_Core_SEO::provider_mode(), 'fresh_core' ); ?>>Fresh Core native / shadow</option><?php foreach ( array( 'aioseo'=>'AIOSEO','yoast'=>'Yoast','rank_math'=>'Rank Math' ) as $pid=>$label ) : if ( ! in_array( $pid, Fresh_Core_SEO::detected_providers(), true ) ) { continue; } ?><option value="<?php echo esc_attr( $pid ); ?>" <?php selected( Fresh_Core_SEO::provider_mode(), $pid ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description">Aktivně používám: <strong><?php echo esc_html( Fresh_Core_SEO::provider() ); ?></strong>. V režimu Auto čtu i zapisuji do aktivního podporovaného pluginu. Fresh Core native lze zvolit po importu; dokud je aktivní jiný SEO plugin, běží bezpečně jako shadow storage a Fresh Core negeneruje duplicitní meta tagy.</p></td></tr>
                    <tr><th>Produkt / Fresh Core</th><td><input type="url" name="product_url" value="<?php echo esc_attr( $product_url ); ?>" style="width:100%;max-width:1100px"><p class="description">Veřejná stránka Fresh Core na FreshCrackers – popis produktu, registrace a dokumentace.</p></td></tr>
                    <tr><th>Update manifest URL</th><td><input type="url" name="update_manifest_url" value="<?php echo esc_attr( $update_manifest_url ); ?>" style="width:100%;max-width:1100px"><p class="description">Centrální strojově čitelný <code>update.json</code>. Výchozí cesta je <code>/wp-content/uploads/fresh-core/update.json</code>.</p></td></tr>
                    <tr><th>Automatické aktualizace</th><td><label><input type="checkbox" name="auto_updates" value="1" <?php checked( $auto_updates ); ?>> Automaticky instalovat novou Fresh Core verzi z centrálního feedu</label><p class="description">Výchozí stav je vypnuto. I bez toho se nová verze zobrazí v běžných aktualizacích WordPressu.</p></td></tr>
                </table>
                <?php submit_button( 'Uložit nastavení' ); ?>
            </form>

            <h2>Centrální aktualizace</h2>
            <p>Lokální verze: <strong><?php echo esc_html( FRESH_CORE_VERSION ); ?></strong> · Centrální verze: <strong><?php echo esc_html( $latest_version ?: 'zatím nezjištěna' ); ?></strong><?php if ( $update_last_check ) : ?> · Poslední kontrola: <?php echo esc_html( self::local_time( $update_last_check ) ); ?><?php endif; ?></p>
            <?php if ( $update_last_error ) : ?><div class="notice notice-warning inline"><p>Update server: <?php echo esc_html( $update_last_error ); ?></p></div><?php endif; ?>
            <p><?php self::button_form( 'check-update', 'Zkontrolovat aktualizaci', 'secondary' ); ?><?php self::button_form( 'regenerate-accounting', 'Vygenerovat nový Accounting key', 'secondary', 'Opravdu vygenerovat nový Accounting key? Eda bude muset dostat nový klíč.' ); ?></p>


            <?php $providers = array_values( array_diff( Fresh_Core_SEO::detected_providers(), array( 'fresh_core' ) ) ); $admin_notice = get_option( 'fresh_core_admin_notice', array() ); if ( $admin_notice ) { delete_option( 'fresh_core_admin_notice' ); } ?>
            <?php if ( is_array( $admin_notice ) && $admin_notice ) : ?>
                <?php if ( ! empty( $admin_notice['latest_version'] ) ) : ?>
                    <div class="notice notice-info inline"><p>Fresh Core update server hlásí verzi <strong><?php echo esc_html( (string) $admin_notice['latest_version'] ); ?></strong>. Lokálně používáš v<?php echo esc_html( FRESH_CORE_VERSION ); ?>.</p></div>
                <?php elseif ( ! empty( $admin_notice['update_error'] ) ) : ?>
                    <div class="notice notice-warning inline"><p>Kontrola aktualizace se nepodařila: <?php echo esc_html( (string) $admin_notice['update_error'] ); ?></p></div>
                <?php elseif ( ! empty( $admin_notice['accounting_key'] ) ) : ?>
                    <div class="notice notice-success inline"><p>Accounting key byl vygenerován znovu. Nezapomeň aktualizovat klíč v Edovi.</p></div>
                <?php else : ?>
                    <div class="notice notice-info inline"><p>Fresh Core: <?php echo esc_html( wp_json_encode( $admin_notice ) ); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( $providers ) : ?>
            <h2>Převzetí SEO dat do Fresh Core</h2>
            <p>Nejdřív data pouze zkopírujeme do nativních Fresh Core polí. Aktivní SEO plugin tím nevypínáme. Po kontrole výstupu můžeš provider přepnout na Fresh Core native.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px">
                <?php wp_nonce_field( 'fresh_core_save' ); ?><input type="hidden" name="action" value="fresh_core_save"><input type="hidden" name="fresh_core_action" value="import-seo">
                <select name="seo_import_source"><?php foreach ( $providers as $pid ) : ?><option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $pid ); ?></option><?php endforeach; ?></select>
                <?php submit_button( 'Zkopírovat SEO data do Fresh Core', 'secondary', 'submit', false ); ?>
            </form>
            <div class="notice notice-warning inline"><p><strong>SEO plugin zatím nevypínej automaticky.</strong> Fresh Core 0.5.1 pokrývá title, description, canonical, robots a ALT, ale pokročilé schema/social funkce mohou stále patřit původnímu pluginu.</p></div>
            <?php endif; ?>

            <h2>Universal Content Bridge</h2>
            <p>Content bridge je součást Fresh Core a slouží jako společné rozhraní pro Lindu a další Fresh aplikace. Není potřeba instalovat další WordPress konektor.</p>
            <table class="widefat striped" style="max-width:1100px;margin-bottom:16px">
                <tbody>
                    <tr><td><code>GET /content/export</code></td><td>Stránkovaný export publikovaných článků, stránek a produktů.</td></tr>
                    <tr><td><code>GET /commerce/catalog</code></td><td>WooCommerce katalog pro konverzní plánování (cena, sklad, kategorie, obrázky).</td></tr>
                    <tr><td><code>GET /commerce/product/&lt;id&gt;</code></td><td>Detail publikovaného WooCommerce produktu.</td></tr>
                    <tr><td><code>POST /content/preview-url</code></td><td>Náhled budoucí URL bez publikace obsahu.</td></tr>
                    <tr><td><code>GET /content/status</code></td><td>Ověření, zda URL odpovídá publikovanému WordPress obsahu.</td></tr>
                    <tr><td><code>GET /analytics/summary</code></td><td>Lokální dostupnost analytických pluginů a základní WooCommerce souhrn.</td></tr>
                    <tr><td><code>POST /write/content/upsert</code></td><td>Allow-list create/update pouze pro články a stránky; podporuje <code>change_id</code> a <code>external_id</code>.</td></tr>
                </tbody>
            </table>

            <p>
                <?php self::button_form( 'refresh', 'Obnovit data teď', 'primary' ); ?>
                <?php self::button_form( 'regenerate', 'Regenerovat konektor a klíče', 'secondary', 'Opravdu vygenerovat novou cestu a REST klíč? Staré odkazy přestanou fungovat.' ); ?>
            </p>

            <hr>
            <h2>Co je exportováno</h2>
            <ul style="list-style:disc;padding-left:22px">
                <li>post types a taxonomie včetně jejich vazeb a počtů,</li>
                <li>celé stromy rubrik, produktových kategorií, tagů a dalších veřejných taxonomií,</li>
                <li>články, stránky, produkty a další bezpečné veřejné/CMS typy obsahu po stránkách; u produktů ceny, SKU, sklad, atributy, kategorie a Fresh Event metadata,</li>
                <li>seznam meta klíčů podle post type bez generického čtení jejich hodnot,</li>
                <li>WordPress menu a kompletní Elementor/Elementor Pro dokumenty: kontejnery, sekce/sloupce, widgety, nested widgets, jejich settings, responzivní hodnoty, dynamické tagy, globální reference a custom CSS uložené v Page Settings,</li>
                <li>Elementor Theme Builder / Popup / Loop / další Library templates včetně Display Conditions, Query ID a odkazů na další template,</li>
                <li>aktivní Elementor Site Kit včetně Global Colors, Global Fonts, breakpointů a dalších Site Settings, plus seznam registrovaných widgetů Elementor/Pro/addonů,</li>
                <li>další Elementor/Pro resource post types (např. Custom Code, vlastní fonty/ikony, pokud je instalace registruje); form submissions a uživatelská data jsou vynechána,</li>
                <li>kompletní schéma všech WordPress tabulek: sloupce, datové typy, indexy a orientační velikosti,</li>
                <li>souhrny Fresh Affiliate, Fresh Event a Adrenaline Live,</li>
                <li>diagnostika duplicitních/prázdných rubrik a produktových kategorií,</li>
                <li>SEO index veřejného obsahu: permalink, WordPress ID, role stránky, provider, title/description/canonical/noindex/nofollow, orientační word count a použité obrázky včetně attachment ID a ALT.</li>
                <li>univerzální content bridge pro Fresh aplikace: stránkovaný export článků/stránek/produktů, URL preview/status, lokální analytický souhrn a allow-list create/update pro články a stránky.</li>
            </ul>
            <p><strong>Write bridge:</strong> samostatný klíč se nikdy neexportuje do statického manifestu a změny lze kompletně vypnout.</p>
            <p><strong>Co není ve veřejném/statickém exportu:</strong> řádky uživatelů, fakturační osobní údaje zákazníků, hesla, API klíče ani obecné hodnoty z wp_options/postmeta. Sanitizované finanční řádky objednávek zůstávají bez PII. Plná fakturační data jsou dostupná jen přes oddělený Accounting bridge s vlastním klíčem. Elementor metadata se exportují cíleně; hodnoty nastavení, jejichž název odpovídá heslu, tokenu, API klíči nebo webhook secretu, jsou nahrazené značkou REDACTED.</p>
            <p>Úplné automatické exporty jsou ve výchozím stavu vypnuté. Poslední úspěšný export a ruční obnovení jsou dostupné výše.</p>
        </div>
        <?php
    }
}
