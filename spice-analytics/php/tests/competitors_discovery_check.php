<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorHttp.php';
require_once dirname(__DIR__).'/src/CompetitorOwnCatalog.php';
function find_expect($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function find_remove(string $dir):void{foreach(scandir($dir) as $name){if($name==='.'||$name==='..')continue;$p=$dir.'/'.$name;if(is_dir($p)&&!is_link($p))find_remove($p);else unlink($p);}rmdir($dir);}
$root=sys_get_temp_dir().'/lavka-discovery-'.bin2hex(random_bytes(6));\Lavka\private_dir($root);
try {
    $db=\Lavka\competitors_db($root);$db->exec('UPDATE competitor_targets SET enabled=0');
    $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999999','label'=>'Куркума молотая','pack_label'=>'50 г, 500 г','match_group'=>'test-kurkuma','match_confirmed'=>true]);
    $other=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999998','label'=>'Корица цейлонская целая','pack_label'=>'500 г','match_group'=>'test-cinnamon','match_confirmed'=>true]);
    \Lavka\competitors_discovery_start($db);$task=['source_id'=>'deluxe','kind'=>'sitemap','depth'=>0];
    $maps=\Lavka\competitors_scan_parse($db,$task,'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://deluxe.com.ua/products.xml</loc></sitemap><sitemap><loc>https://evil.invalid/map.xml</loc></sitemap></sitemapindex>','https://deluxe.com.ua/sitemap.xml');
    find_expect($maps['pages']===1,'Only same-source sitemap children enter crawl queue');
    $links=\Lavka\competitors_scan_parse($db,$task,'<urlset><url><loc>https://deluxe.com.ua/kurkuma-molotaya.html</loc></url><url><loc>https://deluxe.com.ua/koritsa-kassia-tselaya.html</loc></url><url><loc>https://deluxe.com.ua/account/private</loc></url><url><loc>https://deluxe.com.ua/blog/kurkuma-polza</loc></url><url><loc>https://evil.invalid/kurkuma</loc></url></urlset>','https://deluxe.com.ua/products.xml');
    find_expect($links['links']===2,'Sitemaps exclude account, off-source and blog URLs');
    $compressed=\Lavka\competitors_scan_parse($db,$task,gzencode('<urlset><url><loc>https://deluxe.com.ua/kurkuma-molotaya.html</loc></url></urlset>'),'https://deluxe.com.ua/maps.xml.gz');find_expect($compressed['links']===1,'Bounded gzip sitemaps are supported');
    $before=(int)$db->query('SELECT count(*) FROM competitor_search_index')->fetchColumn();
    try{\Lavka\competitors_scan_parse($db,$task,'<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><urlset><url><loc>&a;</loc></url></urlset>','https://deluxe.com.ua/map.xml');throw new RuntimeException('DTD accepted');}catch(\Lavka\CompetitorParseError $expected){}
    find_expect((int)$db->query('SELECT count(*) FROM competitor_search_index')->fetchColumn()===$before,'Malformed XML does not change search index');
    $html='<html><body><div class="product-thumb"><a href="/kurkuma-molotaya.html">Куркума молотая</a><span class="price">50 грн</span></div><a rel="next" href="/catalog/spetsii/?page=2">Далее</a></body></html>';
    $scan=\Lavka\competitors_scan_parse($db,['source_id'=>'deluxe','kind'=>'catalog','depth'=>1],$html,'https://deluxe.com.ua/catalog/spetsii/');
    $open=\Lavka\competitors_scan_parse($db,['source_id'=>'spicery','kind'=>'catalog','depth'=>0],'<nav><a href="/index.php?route=product%2Fcategory&amp;path=42">Специи</a></nav>','https://spiceryshop.com.ua/');find_expect($open['pages']===1,'OpenCart category routes are traversed even without product cards on the homepage');
    find_expect($scan['pages']===1,'Catalogue pagination enters bounded queue');
    \Lavka\competitors_index_link($db,'banka','https://banka-speciy.in.ua/ua/kurkuma','Куркума мелена','page');
    $match=\Lavka\competitors_discovery_match($db,true);find_expect($match['suggestions']>=2&&$match['queued_candidates']===2,'Bilingual titles and URL names create proposed links');
    $found=\Lavka\competitors_discovery_results($db,'','all',1,(int)$own['id']);find_expect(\Lavka\competitors_discovery_results($db)['total']>=2,'Unfiltered search includes all own products');find_expect(count($found['rows'])===2,'Recommendations are pageable and filter by originating product');
    find_expect(!array_filter($found['rows'],function($r){return $r['match_confirmed'];}),'Auto-watch never confirms identity');
    $saved=$db->query("SELECT * FROM competitor_targets WHERE enabled=1 AND source_id<>'lavka' AND kind='product'")->fetchAll();find_expect(count($saved)===2,'Proposed pages are queued once');
    \Lavka\competitors_discovery_match($db,true);find_expect((int)$db->query("SELECT count(*) FROM competitor_targets WHERE enabled=1 AND source_id<>'lavka' AND kind='product'")->fetchColumn()===2,'Repeated matching is idempotent');
    find_expect(!\Lavka\competitors_discovery_results($db,'','all',1,(int)$other['id'])['rows'],'Cassia is not proposed for Ceylon cinnamon');
    $pairs=array_map(function($r){return [$r['own_id'],$r['candidate_id']];},$found['rows']);
    try{\Lavka\competitors_discovery_review($db,['pairs'=>$pairs,'confirm'=>true]);throw new RuntimeException('Unread confirmation accepted');}catch(\Lavka\CompetitorConflict $expected){}
    find_expect(!(int)$db->query("SELECT sum(match_confirmed) FROM competitor_targets WHERE source_id<>'lavka'")->fetchColumn(),'Failed batch leaves all confirmation bits unchanged');
    foreach($saved as $row){$observation=['kind'=>'product','title'=>'Куркума молотая','offers'=>[['key'=>'500','label'=>'500 г','price'=>'120','currency'=>'UAH','grams'=>500,'availability'=>'in_stock','unit'=>'pack','min_quantity'=>1,'condition'=>'','packaging'=>'doypack']],'text'=>'','links'=>[],'warnings'=>[],'method'=>'synthetic'];\Lavka\competitors_record_attempt($db,(int)$row['id'],$observation,null,\Lavka\utc_now(),200);}
    find_expect(\Lavka\competitors_discovery_results($db,'','all',1,(int)$own['id'],'review')['total']===2,'Review filter includes only read unconfirmed matching groups');
    $review=\Lavka\competitors_discovery_review($db,['pairs'=>$pairs,'confirm'=>true]);find_expect($review['processed']===2,'Explicit batch confirms read product pairs');
    find_expect(\Lavka\competitors_discovery_results($db,'','all',1,(int)$own['id'],'review')['total']===0&&\Lavka\competitors_discovery_results($db,'','all',1,(int)$own['id'],'confirmed')['total']===2,'Review filter and confirmed filter change after explicit batch');
    $snapshot=$db->query('SELECT id,match_confirmed FROM competitor_targets ORDER BY id')->fetchAll();
    try{\Lavka\competitors_discovery_review($db,['pairs'=>[$pairs[0],[9999999,9999999]],'confirm'=>true]);throw new RuntimeException('Forged candidate accepted');}catch(\Lavka\CompetitorConflict $expected){}
    find_expect($db->query('SELECT id,match_confirmed FROM competitor_targets ORDER BY id')->fetchAll()===$snapshot,'Invalid batch is atomic');
    $overview=\Lavka\competitors_overview($db,$root);find_expect($overview['monitoring']['runner_state']==='never'&&$overview['monitoring']['fresh']===2,'Health separates loaded pages from absent cron');
    find_expect($overview['monitoring']['own_products']===2&&$overview['monitoring']['unconfirmed']===0,'Health uses actual enabled products and confirmations');
    $bulk=$root.'/bulk';\Lavka\private_dir($bulk);$bulkDb=\Lavka\competitors_db($bulk);$bulkDb->exec('UPDATE competitor_targets SET enabled=0');
    if(PHP_VERSION_ID>=80200){
        $config=['host'=>'127.0.0.1','database'=>'synthetic','user'=>'synthetic','password'=>'synthetic-test-only','prefix'=>'oc_'];file_put_contents($bulk.'/source.json',\Lavka\encode($config));
        $reader=function(){return array_map(function($id){return ['product_id'=>(string)$id,'name'=>'Специя '.$id,'language_id'=>'1'];},range(50000,50399));};
        $expanded=\Lavka\competitors_catalog_expand($bulk,$bulk.'/source.json',$reader);find_expect($expanded['added']===400,'Complete 400-product inventory is registered');
        $again=\Lavka\competitors_catalog_expand($bulk,$bulk.'/source.json',$reader);find_expect($again['added']===0,'Catalogue expansion is idempotent');
        find_expect(count(\Lavka\competitors_own_catalog_watch($bulk))===400,'Catalogue snapshot selection no longer truncates at 350');
        $ids=array_column($bulkDb->query("SELECT id FROM competitor_targets WHERE enabled=1 AND source_id='lavka'")->fetchAll(),'id');
        find_expect(!\Lavka\competitors_due($bulkDb,50,array_map('intval',$ids)),'All 400 own prices stay out of HTTP queue');
    }
    if(PHP_VERSION_ID>=80200){
        require_once dirname(__DIR__).'/bin/competitors.php';
        $runDir=$root.'/runner';\Lavka\private_dir($runDir);$runDb=\Lavka\competitors_db($runDir);$runDb->exec('UPDATE competitor_targets SET enabled=0');
        \Lavka\competitors_save_target($runDb,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=888888','label'=>'Куркума молотая','pack_label'=>'500 г','match_group'=>'runner-kurkuma','match_confirmed'=>true]);
        \Lavka\competitors_discovery_start($runDb);$tick=(float)time()+10;$calls=[];
        $transport=function($url)use(&$calls){$calls[]=$url;if(strpos($url,'robots.txt')!==false)return ['status'=>200,'headers'=>['content-type'=>'text/plain'],'body'=>"User-agent: *\nAllow: /\nSitemap: https://deluxe.com.ua/map-products.xml\n"];
            if(strpos($url,'sitemap.xml')!==false)return ['status'=>200,'headers'=>['content-type'=>'application/xml'],'body'=>'<urlset><url><loc>https://deluxe.com.ua/kurkuma-molotaya.html</loc></url></urlset>'];
            return ['status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>'<h1>Куркума молотая 500 г</h1><script type="application/ld+json">{"@type":"Product","name":"Куркума молотая 500 г","offers":{"@type":"Offer","price":"90","priceCurrency":"UAH","availability":"https://schema.org/InStock"}}</script>'];};
        $result=lavka_competitors_run(['data'=>$runDir,'limit'=>30,'seconds'=>55],$transport,function()use(&$tick){return $tick;},function($seconds)use(&$tick){$tick+=$seconds;});
        find_expect($result['discovery']['links']===1&&$result['matching']['queued_candidates']===1&&$result['succeeded']>=1,'Background scan flows into candidate matching and price loading');
        find_expect(!(int)$runDb->query("SELECT match_confirmed FROM competitor_targets WHERE source_id='deluxe' AND kind='product'")->fetchColumn(),'Runner never confirms a guessed product');
        find_expect((int)$runDb->query("SELECT count(*) FROM competitor_scan_pages WHERE url='https://deluxe.com.ua/map-products.xml'")->fetchColumn()===1,'Robots sitemap declarations enter the bounded queue');
    }
    echo \Lavka\encode(['sitemap_catalog_pagination_and_private_path_guards'=>'passed','bilingual_candidate_index_and_false_confirmation'=>'passed','atomic_human_batch_review'=>'passed','full_400_catalog'=>'passed','health_and_idempotence'=>'passed','php'=>PHP_VERSION,'external_network'=>false])."\n";
}finally{find_remove($root);}
