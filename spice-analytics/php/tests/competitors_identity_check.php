<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorDiscovery.php';
function identity_verify($ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
function identity_cleanup(string $dir): void{foreach(scandir($dir) as $name){if($name==='.'||$name==='..')continue;$file=$dir.'/'.$name;if(is_dir($file)&&!is_link($file))identity_cleanup($file);else unlink($file);}rmdir($dir);}
$directory=sys_get_temp_dir().'/lavka-product-identity-'.bin2hex(random_bytes(6));\Lavka\private_dir($directory);
try{
    foreach([
        ['Укроп семена','Кріп насіння',true],['Укроп молотый','Укроп молотый 100 г',true],
        ['Укроп семена','Укроп семена молотые',false],['Укроп молотый','Укроп сушёный',false],
        ['Укроп семена','Укроп зелень сушеная',false],['Укроп семена','Кориандр семена',false],
        ['Перец черный молотый','Перець чорний цілий',false],
    ] as $case){$result=\Lavka\competitors_identity_compare($case[0],$case[1]);identity_verify($result['exact']===$case[2],'Product, language and form: '.$case[1]);}
    identity_verify(\Lavka\competitors_identity_compare('Укроп семена','Укроп семена в банке')['score']===0,'Jar title rejected');
    identity_verify(\Lavka\competitors_identity_compare('Укроп семена','Укроп семена','https://banka-speciy.in.ua/ukrop-semena-v-banke')['score']===0,'Jar URL rejected');
    identity_verify(!\Lavka\competitors_identity_jar('Укроп семена — Банка Спецій','https://banka-speciy.in.ua/ukrop-semena'),'Shop name is not jar evidence');
    identity_verify(!\Lavka\competitors_identity_jar('Укроп семена, пластиковый zip-пакет'),'Plastic pouches are not plastic jars');
    $db=\Lavka\competitors_db($directory);$db->exec('UPDATE competitor_targets SET enabled=0');
    $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999991','label'=>'Укроп семена','match_group'=>'identity-dill','match_confirmed'=>true]);
    $jar=\Lavka\competitors_save_target($db,['source_id'=>'banka','kind'=>'product','url'=>'https://banka-speciy.in.ua/ukrop-semena-v-banke','label'=>'Укроп семена в банке','match_group'=>'identity-dill','match_confirmed'=>false]);
    foreach(['ukrop-semena-v-banke'=>'Укроп семена в банке','ukrop-semena-molotye'=>'Укроп семена молотые','ukrop-semena'=>'Укроп семена','ukrop-semena-400-g'=>'Укроп семена 400 г'] as $path=>$title)\Lavka\competitors_index_link($db,'banka','https://banka-speciy.in.ua/'.$path,$title,'catalog');
    $match=\Lavka\competitors_discovery_match($db,true);$found=\Lavka\competitors_discovery_results($db,'','all',1,(int)$own['id']);
    identity_verify($match['queued_candidates']===1&&$found['total']===2,'Bag/base candidate preferred; jar and ground seeds excluded');
    identity_verify(!$db->query('SELECT enabled FROM competitor_targets WHERE id='.(int)$jar['id'])->fetchColumn(),'Excluded jar archived without deleting history');
    identity_verify($db->query("SELECT url FROM competitor_targets WHERE enabled=1 AND source_id='banka'")->fetchColumn()==='https://banka-speciy.in.ua/ukrop-semena','Canonical bulk page wins a same-name weight tie');
    $html='<main><section class="product" data-product-id="17"><h1>Укроп семена</h1><meta itemprop="priceCurrency" content="UAH"><meta itemprop="availability" content="https://schema.org/InStock"><input type="radio" class="product-option-value" name="option[9]" value="201" data-price="20.50" data-description="100 г" data-name="Zip-lock пакет 100 г" data-min-quantity="1" data-special="0"></section><aside>Банка, 1 грн</aside></main>';
    $observation=\Lavka\competitors_parse(['kind'=>'product'],$html,'https://banka-speciy.in.ua/ukrop-semena');
    identity_verify($observation['offers'][0]['price']==='20.50'&&$observation['offers'][0]['packaging']==='zip','Numeric absolute price needs explicit currency; zero special is not a sale');
    \Lavka\competitors_scan_parse($db,['source_id'=>'banka','kind'=>'product','depth'=>1],$html,'https://banka-speciy.in.ua/ukrop-semena');
    $index=$db->query("SELECT * FROM competitor_search_index WHERE url='https://banka-speciy.in.ua/ukrop-semena'")->fetch();
    identity_verify($index['title_source']==='page'&&\Lavka\decode($index['details'])['observation']['offers'][0]['price']==='20.50','Product scan preserves actual title and price evidence');
    $feed='<main><a href="/files/price.csv">Прайс-лист CSV</a><a href="https://evil.invalid/price.csv">Прайс-лист</a></main>';
    \Lavka\competitors_scan_parse($db,['source_id'=>'banka','kind'=>'catalog','depth'=>0],$feed,'https://banka-speciy.in.ua/');
    identity_verify((int)$db->query('SELECT count(*) FROM competitor_price_lists')->fetchColumn()===1,'Price lists discovered only on the same public source');
    if(PHP_VERSION_ID>=80200){
        require_once dirname(__DIR__).'/bin/competitors.php';
        $db->exec('INSERT OR REPLACE INTO competitor_discovery_settings VALUES(1,1)');
        for($i=0;$i<8;$i++)\Lavka\competitors_scan_enqueue($db,'banka','https://banka-speciy.in.ua/ukrop-semena-page-'.$i,'product',1);
        $db->exec("UPDATE competitor_targets SET next_check_at='2099-01-01T00:00:00Z' WHERE source_id='lavka'");
        $calls=[];$tick=(float)time()+10;
        $transport=function($url)use(&$calls,$html){$calls[]=$url;return strpos($url,'robots.txt')!==false?['status'=>200,'headers'=>['content-type'=>'text/plain'],'body'=>"User-agent: *\nAllow: /\n"]:['status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>$html];};
        $run=lavka_competitors_run(['data'=>$directory,'limit'=>30,'seconds'=>55],$transport,function()use(&$tick){return $tick;},function($seconds)use(&$tick){$tick+=$seconds;});
        identity_verify($run['discovery']['checked']>1&&$run['discovery']['checked']<=8,'Product indexing uses a bounded packet, not one page per cron');
        identity_verify(count(array_filter($calls,function($url){return $url==='https://banka-speciy.in.ua/ukrop-semena';}))===1,'Existing watched product reuses the scan response without a second download');
    }
    echo \Lavka\encode(['product_form_and_jar_rejection'=>'passed','bulk_canonical_and_history'=>'passed','actual_page_index_and_numeric_prices'=>'passed','price_list_discovery'=>'passed','php'=>PHP_VERSION]),PHP_EOL;
}finally{identity_cleanup($directory);}
