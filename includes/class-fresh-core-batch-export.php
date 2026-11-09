<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Bounded, resumable static snapshot producer. No full catalog in one PHP request. */
final class Fresh_Core_Batch_Export {
    const HOOK = 'fresh_core_export_process_batch';
    const OPT = 'fresh_core_export_batch_job';
    const LOCK = 'fresh_core_export_batch_worker_lock';
    const CHUNK = 25;
    public static function init(): void { add_action( self::HOOK, array( __CLASS__, 'process' ) ); }
    private static function now(): string { return gmdate( 'c' ); }
    public static function recover(): array {
        return array( 'ok' => self::enqueue(), 'recovery' => true );
    }
    public static function status(): array { $s = get_option( self::OPT, array() ); return is_array( $s ) ? $s : array(); }
    private static function enqueue(): bool {
        if ( wp_next_scheduled( self::HOOK ) ) { return true; }
        return (bool) wp_schedule_single_event( time() + 3, self::HOOK );
    }
    private static function types(): array {
        $blocked = array( 'revision','attachment','nav_menu_item','custom_css','customize_changeset','user_request','oembed_cache','shop_order','shop_order_refund','shop_subscription','shop_coupon','wc_order_status','wc_user_membership' );
        $out = array();
        foreach ( get_post_types( array(), 'objects' ) as $name => $obj ) {
            if ( ! in_array( $name, $blocked, true ) && ( $obj->public || in_array( $name, array( 'elementor_library','wp_template','wp_template_part','product' ), true ) ) ) { $out[] = $name; }
        }
        sort( $out ); return $out;
    }
    private static function count_posts( array $types ): int {
        if ( ! $types ) { return 0; }
        global $wpdb;
        $t = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $args = array_merge( $types, array( 'publish','draft','pending','future','private' ) );
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($t) AND post_status IN (%s,%s,%s,%s,%s)";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
    }
    private static function plan(): array {
        $steps = array();
        foreach ( array( 'structure','schema','elementor-main','modules','diagnostics','plugins-registry','orders','seo-open' ) as $name ) { $steps[] = array( 'kind' => $name ); }
        $types = array_values( array_unique( array_merge( array_keys( get_post_types( array( 'public' => true ) ) ), post_type_exists( 'product' ) ? array( 'product' ) : array() ) ) );
        $types = array_values( array_diff( $types, array( 'attachment' ) ) );
        $count = self::count_posts( $types );
        for ( $i = 0; $i < max( 1, (int) ceil( $count / self::CHUNK ) ); $i++ ) { $steps[] = array( 'kind'=>'seo-page', 'offset'=> $i * self::CHUNK, 'types'=>$types ); }
        $steps[] = array( 'kind'=>'seo-close' );
        foreach ( Fresh_Core_Data::elementor_document_ids() as $id ) { $steps[] = array( 'kind'=>'elementor-doc', 'id'=>(int)$id ); }
        foreach ( get_taxonomies( array(), 'objects' ) as $name=>$obj ) {
            if ( in_array( $name, array('nav_menu','link_category','post_format'), true ) || ( ! $obj->public && ! in_array($name,array('product_cat','product_tag'),true) ) ) { continue; }
            $n = wp_count_terms( array( 'taxonomy'=>$name, 'hide_empty'=>false ) );
            if ( is_wp_error( $n ) ) { continue; }
            $pages = max( 1, (int) ceil( (int) $n / 100 ) );
            for ( $p=1;$p<=$pages;$p++ ) { $steps[] = array('kind'=>'tax','name'=>$name,'page'=>$p,'pages'=>$pages,'total'=>(int)$n); }
        }
        foreach ( self::types() as $type ) {
            $n = self::count_posts( array($type) );
            $pages = max( 1, (int) ceil( $n / 25 ) );
            for ( $p=1;$p<=$pages;$p++ ) { $steps[] = array('kind'=>'content','name'=>$type,'page'=>$p,'pages'=>$pages); }
            $steps[] = array('kind'=>'meta-keys','name'=>$type);
        }
        $steps[] = array('kind'=>'publish');
        return $steps;
    }
    public static function start(): array {
        if ( ! Fresh_Core_Export::enabled() ) { return array('ok'=>false,'message'=>'Statický konektor je vypnutý.'); }
        $old = self::status();
        if ( in_array( $old['status'] ?? '', array('queued','running'), true ) ) {
            self::enqueue(); return array('ok'=>true,'status'=>'already_running','completed'=>(int)($old['completed']??0),'total'=>(int)($old['total']??0));
        }
        $id = gmdate('YmdHis') . '-' . strtolower( wp_generate_password(6,false,false) );
        $steps = self::plan();
        $base = Fresh_Core_Export::base_dir();
        if ( ! wp_mkdir_p( $base.'/snapshots/'.$id ) ) { return array('ok'=>false,'message'=>'Nelze vytvořit složku snapshotu.'); }
        $state = array('id'=>$id,'status'=>'queued','started_at_gmt'=>self::now(),'updated_at_gmt'=>self::now(),'completed'=>0,'total'=>count($steps),'steps'=>$steps,'files'=>array(),'errors'=>array(),'dirty_start'=>get_option('fresh_core_export_dirty',0),'elapsed_ms'=>0);
        update_option(self::OPT,$state,false);
        self::public_status($state);
        $queued = self::enqueue();
        if ( ! $queued ) { $state['status']='waiting_for_scheduler'; $state['errors'][]='WP-Cron refused to schedule; run wp eval Fresh_Core_Batch_Export::process() manually.'; update_option(self::OPT,$state,false); }
        return array('ok'=>$queued,'status'=>$state['status'],'completed'=>0,'total'=>count($steps),'job_id'=>$id);
    }
    private static function write( array &$s, string $path, $data ): void {
        $rel = 'snapshots/'.$s['id'].'/'.$path;
        $dest = trailingslashit( Fresh_Core_Export::base_dir() ).$rel;
        if ( ! wp_mkdir_p(dirname($dest)) ) { throw new RuntimeException('Directory creation failed: '.$path); }
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        if ( false === $json ) { throw new RuntimeException('JSON failed: '.$path); }
        $tmp = $dest.'.tmp';
        if ( false === file_put_contents($tmp,$json,LOCK_EX) || ! rename($tmp,$dest) ) { throw new RuntimeException('Write failed: '.$path); }
        $s['files'][] = array('file'=>$path,'url'=>trailingslashit(Fresh_Core_Export::base_url()).$rel,'bytes'=>strlen($json),'sha256'=>hash('sha256',$json),'part_number'=>(int)$s['completed']+1,'total_parts'=>(int)$s['total'],'is_last'=>false);
    }
    private static function seo_append( array &$s, array $rows, bool $last=false ): void {
        $path = Fresh_Core_Export::base_dir().'/snapshots/'.$s['id'].'/seo/index.json';
        if ( ! wp_mkdir_p(dirname($path)) ) { throw new RuntimeException('SEO folder not writable'); }
        $h = fopen($path,'ab'); if (!$h) { throw new RuntimeException('Cannot append SEO index'); }
        if ( ftell($h)===0 ) { fwrite($h, '{"generated_at_gmt":'.wp_json_encode(self::now()).',"provider":'.wp_json_encode(Fresh_Core_SEO::provider()).',"capabilities":'.wp_json_encode(Fresh_Core_SEO::capabilities()).',"items":['); }
        foreach ($rows as $item) {
            $encoded = wp_json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (false === $encoded) { fclose($h); throw new RuntimeException('SEO item encoding failed'); }
            if ( ! empty($s['seo_items']) ) { fwrite($h,','); }
            fwrite($h,$encoded); $s['seo_items'] = (int)($s['seo_items']??0)+1;
        }
        if ($last) { fwrite($h,']}'); }
        fclose($h);
        if ($last) { $rel='snapshots/'.$s['id'].'/seo/index.json'; $s['files'][]=array('file'=>'seo/index.json','url'=>trailingslashit(Fresh_Core_Export::base_url()).$rel,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'part_number'=>(int)$s['completed']+1,'total_parts'=>(int)$s['total'],'is_last'=>false); }
    }
    private static function public_status(array $s): void {
        $data = array('job_id'=>$s['id']??'', 'status'=>$s['status']??'unknown',
            'part_number'=>(int)($s['completed']??0), 'total_parts'=>(int)($s['total']??0),
            'has_more'=>in_array($s['status']??'',array('queued','running','waiting_for_scheduler'),true),
            'is_last'=>($s['status']??'')==='completed',
            'updated_at_gmt'=>$s['updated_at_gmt']??'',
            'error_count'=>count($s['errors']??array()));
        $dir=Fresh_Core_Export::base_dir();
        if(!wp_mkdir_p($dir)) return;
        $tmp=$dir.'/batch-status.json.tmp';
        $encoded=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(false!==$encoded && false!==@file_put_contents($tmp,$encoded,LOCK_EX)) @rename($tmp,$dir.'/batch-status.json');
    }
    private static function execute(array &$s,array $step): void {
        $k=$step['kind']; $stamp=array('generated_at_gmt'=>self::now());
        if ($k==='structure') { self::write($s,'structure.json',$stamp+array('manifest'=>Fresh_Core_Data::manifest(),'post_types'=>Fresh_Core_Data::post_types(),'taxonomies'=>Fresh_Core_Data::taxonomies(),'menus'=>Fresh_Core_Data::menus())); }
        elseif ($k==='schema') { self::write($s,'database-schema.json',$stamp+array('schema_only'=>true,'tables'=>Fresh_Core_Export::db_schema())); }
        elseif ($k==='elementor-main') {
            self::write($s,'elementor.json',$stamp+array('overview'=>Fresh_Core_Data::elementor_overview(),'templates'=>Fresh_Core_Data::elementor_templates(),'site_settings'=>Fresh_Core_Data::elementor_site_settings()));
            self::write($s,'elementor/overview.json',Fresh_Core_Data::elementor_overview());
            self::write($s,'elementor/index.json',$stamp+array('items'=>Fresh_Core_Data::elementor_documents_index()));
            self::write($s,'elementor/site-settings.json',$stamp+array('data'=>Fresh_Core_Data::elementor_site_settings()));
            self::write($s,'elementor/pro-resources.json',$stamp+array('data'=>Fresh_Core_Data::elementor_pro_resources()));
        }
        elseif ($k==='modules') { self::write($s,'fresh-modules.json',$stamp+array('modules'=>Fresh_Core_Data::module_summaries())); self::write($s,'modules/registry.json',Fresh_Core_Modules::summary()); }
        elseif ($k==='diagnostics') { self::write($s,'diagnostics.json',Fresh_Core_Export::diagnostics()); }
        elseif ($k==='plugins-registry') { self::write($s,'plugins/registry.json',$stamp+array('schema'=>'fresh-console/1.0','items'=>Fresh_Core_Console::manifest_registry())); }
        elseif ($k==='orders') { self::write($s,'commerce/orders.json',Fresh_Core_Content_Bridge::orders_feed_data(1,1000)); }
        elseif ($k==='seo-open') { $s['seo_items']=0; }
        elseif ($k==='seo-page') {
            $q=new WP_Query(array('post_type'=>$step['types'],'post_status'=>array('publish','draft','pending','future','private'),'posts_per_page'=>self::CHUNK,'offset'=>$step['offset'],'orderby'=>'ID','order'=>'ASC','fields'=>'ids','no_found_rows'=>true));
            $items=array();foreach($q->posts as $id) { $item=Fresh_Core_SEO::item((int)$id);if($item) $items[]=$item; }
            self::seo_append($s,$items);
        }
        elseif ($k==='seo-close') { self::seo_append($s,array(),true); }
        elseif ($k==='elementor-doc') { $doc=Fresh_Core_Data::elementor_document((int)$step['id']); if($doc) self::write($s,'elementor/documents/'.(int)$step['id'].'.json',$stamp+array('document'=>$doc)); }
        elseif ($k==='tax') { $n=$step['name']; $p=$step['page']; self::write($s,'taxonomies/'.sanitize_file_name($n).'-'.$p.'.json',$stamp+array('taxonomy'=>$n,'page'=>$p,'pages'=>$step['pages'],'total'=>$step['total'],'items'=>Fresh_Core_Data::terms($n,$p,100))); }
        elseif ($k==='content') { $n=$step['name']; $p=$step['page'];$data=Fresh_Core_Data::content($n,$p,25,'any',true,'');self::write($s,'content/'.sanitize_file_name($n).'-'.$p.'.json',$stamp+array('post_type'=>$n,'page'=>$p,'pages'=>$step['pages'],'total'=>(int)($data['total']??0),'items'=>$data['items']??array())); }
        elseif ($k==='meta-keys') { $n=$step['name'];self::write($s,'meta-keys/'.sanitize_file_name($n).'.json',$stamp+array('post_type'=>$n,'items'=>Fresh_Core_Data::meta_keys($n))); }
        elseif ($k==='publish') { self::publish($s); }
    }
    private static function publish(array &$s): void {
        $old = json_decode((string)@file_get_contents(Fresh_Core_Export::base_dir().'/manifest.json'),true);
        $old = is_array($old)?$old:array();
        $manifest=$old;
        $manifest['fresh_core_version']=FRESH_CORE_VERSION;
        $manifest['generated_at_gmt']=self::now();
        $manifest['generation_seconds']=round($s['elapsed_ms']/1000,3);
        $manifest['files']=$s['files'];
        $manifest['snapshot_id']=$s['id'];
        $manifest['batch_status_url']=trailingslashit(Fresh_Core_Export::base_url()).'batch-status.json';
        $manifest['batch_delivery']=array('mode'=>'snapshot','total_parts'=>$s['total'],'completed_parts'=>$s['total'],'is_last'=>true,'status'=>'complete');
        $manifest['capabilities']=array_merge(is_array($old['capabilities']??null)?$old['capabilities']:array(),array('seo_bridge'=>true,'content_export'=>true,'central_updates'=>true,'batch_export'=>true));
        $manifest['site']=array('url'=>home_url('/'),'name'=>get_bloginfo('name'));
        // Reject missing/truncated outputs rather than advertising partial data.
        $dir=Fresh_Core_Export::base_dir();
        foreach ($s['files'] as $f) {
            $relative='snapshots/'.$s['id'].'/'.$f['file'];
            $path=$dir.'/'.$relative;
            if (!is_file($path) || filesize($path)!==(int)$f['bytes'] || hash_file('sha256',$path)!==$f['sha256']) {
                throw new RuntimeException('Snapshot validation failed: '.$f['file']);
            }
        }
        $temp=$dir.'/manifest.json.tmp-'.$s['id'];
        $json=wp_json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        if(false===$json || false===file_put_contents($temp,$json,LOCK_EX) || !rename($temp,$dir.'/manifest.json')) { throw new RuntimeException('Manifest publishing failed'); }
        update_option('fresh_core_export_last_run',$manifest['generated_at_gmt'],false);
        update_option('fresh_core_export_last_result',array('ok'=>true,'files'=>count($s['files'])+1,'seconds'=>$manifest['generation_seconds'],'job_id'=>$s['id']),false);
        if (get_option('fresh_core_export_dirty',0)===$s['dirty_start']) { delete_option('fresh_core_export_dirty'); }
    }
    public static function process(): void {
        $s=self::status(); if (!in_array($s['status']??'',array('queued','running','waiting_for_scheduler'),true)) return;
        $last=(int)get_option(self::LOCK,0);
        if ($last && time()-$last>300) delete_option(self::LOCK);
        if (!add_option(self::LOCK,time(),'',false)) return;
        try {
            $s=self::status();$idx=(int)$s['completed'];
            if(!isset($s['steps'][$idx])) return;
            $s['status']='running';$s['updated_at_gmt']=self::now();update_option(self::OPT,$s,false);
            $t=microtime(true);
            try { self::execute($s,$s['steps'][$idx]);$s['completed']=$idx+1;$s['elapsed_ms']+=(int)round((microtime(true)-$t)*1000);$s['status']=$s['completed']>=$s['total']?'completed':'running'; }
            catch(Throwable $e) { $s['status']='failed';$s['errors'][]=array('part'=>$idx+1,'message'=>substr($e->getMessage(),0,200),'at_gmt'=>self::now()); }
            $s['updated_at_gmt']=self::now();if($s['status']==='completed')$s['completed_at_gmt']=self::now();
            update_option(self::OPT,$s,false);
            self::public_status($s);
            if ($s['status']==='running' && !self::enqueue()) { $s['status']='waiting_for_scheduler';$s['errors'][]='Cron scheduling failed';update_option(self::OPT,$s,false);self::public_status($s); }
        } finally { delete_option(self::LOCK); }
    }
}
