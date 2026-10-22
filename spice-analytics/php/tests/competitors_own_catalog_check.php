<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/CompetitorOwnCatalog.php';
function own_expect(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); }
function own_throws(string $code,callable $function):void {
    try { $function(); } catch (\Lavka\SyncError $error) { own_expect($error->getMessage()===$code,'Expected '.$code.', got '.$error->getMessage()); return; }
    throw new RuntimeException('Expected '.$code);
}
function own_fixture():array {
    return ['setting'=>[
        ['store_id'=>0,'key'=>'config_currency','value'=>'UAH','serialized'=>0],
        ['store_id'=>0,'key'=>'config_language','value'=>'uk-ua','serialized'=>0],
        ['store_id'=>0,'key'=>'config_customer_group_id','value'=>'1','serialized'=>0],
        ['store_id'=>0,'key'=>'config_tax','value'=>'0','serialized'=>0],
        ['store_id'=>0,'key'=>'config_mail_password','value'=>'PRIVATE_SETTING_SHOULD_NOT_APPEAR','serialized'=>0]],
        'currency'=>[['code'=>'UAH','value'=>'1.00000000','decimal_place'=>'2','status'=>'1']],
        'language'=>[['language_id'=>'3','code'=>'uk-ua','status'=>'1']],
        'product'=>[['product_id'=>'1214','model'=>'TRI','price'=>'0.0000','quantity'=>'12','subtract'=>'1','status'=>'1','date_available'=>'2020-01-01','tax_class_id'=>'0','minimum'=>'1']],
        'product_description'=>[['product_id'=>'1214','language_id'=>'3','name'=>'Синтетична пряність']],
        'product_to_store'=>[['product_id'=>'1214','store_id'=>'0']],
        'product_option'=>[['product_option_id'=>'99','product_id'=>'1214','option_id'=>'7','required'=>'1']],
        'option'=>[['option_id'=>'7','type'=>'select']],
        'product_option_value'=>[
            ['product_option_value_id'=>'701','product_option_id'=>'99','product_id'=>'1214','option_id'=>'7','option_value_id'=>'11','quantity'=>'8','subtract'=>'1','price'=>'125.0000','price_prefix'=>'+'],
            ['product_option_value_id'=>'702','product_option_id'=>'99','product_id'=>'1214','option_id'=>'7','option_value_id'=>'12','quantity'=>'0','subtract'=>'1','price'=>'400.0000','price_prefix'=>'+']],
        'option_value_description'=>[['option_value_id'=>'11','language_id'=>'3','name'=>'50 г'],['option_value_id'=>'12','language_id'=>'3','name'=>'200 г']],
        'product_discount'=>[],'product_special'=>[],'as_of_date'=>'2026-10-22'];
}
/** Real SQL fixtures, query_only SQLite, with only MySQL transaction syntax adapted. */
class OwnSqliteReadOnlySource extends PDO {
    public $mutateBetweenReads=false;
    private $productReads=0;
    public function __construct(array $rows) {
        parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->sqliteCreateFunction('NOW',function(){return date('Y-m-d H:i:s');});
        foreach (\Lavka\COMPETITOR_CATALOG_FIELDS as $table=>$fields) {
            $physical='oc_'.($table==='setting'?'analytics_catalog_settings':$table);
            parent::exec('CREATE TABLE `'.$physical.'` ('.implode(',',array_map(function($field){return '`'.$field.'` TEXT';},$fields)).')');
            foreach ($rows[$table]??[] as $row) {
                if ($table==='setting' && !in_array($row['key'],\Lavka\COMPETITOR_CATALOG_SETTING_KEYS,true)) continue;
                $values=array_map(function($field)use($row){return $this->quote((string)($row[$field]??''));},$fields);
                parent::exec('INSERT INTO `'.$physical.'` VALUES('.implode(',',$values).')');
            }
        }
        parent::exec('PRAGMA query_only=ON');
    }
    #[\ReturnTypeWillChange]
    public function exec($statement) {
        if (in_array($statement,['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','ROLLBACK'],true)) return 0;
        throw new RuntimeException('Non-read SQL rejected');
    }
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]) {
        if (strpos($query,'FROM `oc_product`')!==false && ++$this->productReads===2 && $this->mutateBetweenReads) {
            // A concurrent shop edit between reads, not a production write path.
            parent::exec('PRAGMA query_only=OFF'); parent::exec("UPDATE oc_product SET price='1'"); parent::exec('PRAGMA query_only=ON');
        }
        return parent::prepare($query,$options);
    }
}
$watch=[1214=>['target_id'=>1,'product_id'=>1214,'source_product_id'=>1214,'label'=>'Synthetic spice','pack_label'=>'50 г / 200 г','url'=>'https://lavka-speciy.com/index.php?product_id=1214&route=product%2Fproduct']];
$time='2026-10-22T10:00:00Z'; $rows=own_fixture();
$built=\Lavka\competitors_own_catalog_build($rows,$watch,$time);
own_expect($built['state']==='ok' && $built['offer_count']===2,'Two exact option packs');
$offers=$built['products'][0]['offers'];
own_expect($offers[0]['price']==='125.00' && $offers[0]['grams']===50.0,'Zero base plus selected option is actual pack price');
own_expect($offers[0]['availability']==='in_stock' && $offers[1]['availability']==='out_of_stock','Option stock distinguishes availability');
own_expect($offers[0]['provenance']['product_option_value_id']===701 && $offers[0]['provenance']['base_price']==='0.0000','Exact source identifiers and pricing evidence retained');
own_expect($offers[0]['provenance']['raw_price']==='125.0000' && $offers[0]['provenance']['currency_decimal_places']===2
    && $built['currency_decimal_places']===2 && $built['products'][0]['currency_decimal_places']===2,'Precise stored amount and storefront precision remain distinct evidence');
own_expect(strpos(\Lavka\encode($built),'PRIVATE_SETTING')===false,'Only fixed setting keys can enter projection');
$rounding=$rows; $rounding['product_option_value'][0]['price']='125.0050';
$rounded=\Lavka\competitors_own_catalog_build($rounding,$watch,$time);
own_expect($rounded['products'][0]['offers'][0]['price']==='125.01','Currency display rounds half up instead of truncating fractional kopecks');
$whole=own_fixture(); $whole['currency'][0]['decimal_place']='0';
$whole['product'][0]['price']='100.4900'; $whole['product_option_value'][0]['price']='25.4900';
$wholeBuilt=\Lavka\competitors_own_catalog_build($whole,$watch,$time); $wholeOffer=$wholeBuilt['products'][0]['offers'][0];
own_expect($wholeOffer['price']==='126' && $wholeOffer['provenance']['raw_price']==='125.9800'
    && $wholeBuilt['currency_decimal_places']===0 && $wholeBuilt['products'][0]['currency_decimal_places']===0
    && $wholeOffer['provenance']['currency_decimal_places']===0,'Whole-hryvnia display rounds the raw sum once while retaining precise stored prices');
own_expect($wholeOffer['provenance']['base_price']==='100.4900' && $wholeOffer['provenance']['option_adjustment']==='25.4900','Display rounding never changes base or option values');
$whole['product'][0]['price']='100.0000'; $whole['product_option_value'][0]['price']='25.4999';
own_expect(\Lavka\competitors_own_catalog_build($whole,$watch,$time)['products'][0]['offers'][0]['price']==='125','Whole-hryvnia display does not pre-round to kopecks');
$whole['product_option_value'][0]['price']='25.5000';
own_expect(\Lavka\competitors_own_catalog_build($whole,$watch,$time)['products'][0]['offers'][0]['price']==='126','Whole-hryvnia exact half rounds upward');
$whole['product'][0]['price']='0.0000'; $whole['product_option_value'][0]['price']='0.4900';
$zeroDisplay=\Lavka\competitors_own_catalog_build($whole,$watch,$time);
own_expect(count($zeroDisplay['products'][0]['offers'])===1 && $zeroDisplay['products'][0]['offers'][0]['grams']===200.0
    && in_array('nonpositive_price',$zeroDisplay['products'][0]['warnings'],true),'Positive raw values displayed as zero do not become comparable zero prices');
$whole['product_option_value'][0]['price']='0.4900'; $whole['product_option_value'][0]['price_prefix']='-';
own_expect(count(\Lavka\competitors_own_catalog_build($whole,$watch,$time)['products'][0]['offers'])===1,'Negative raw prices are rejected before display rounding');
$rows['product'][0]['price']='100'; $rows['product_option_value'][0]['price']='25'; $rows['product_option_value'][0]['price_prefix']='-';
$built=\Lavka\competitors_own_catalog_build($rows,$watch,$time); own_expect($built['products'][0]['offers'][0]['price']==='75.00','Subtractive option uses base price');
$rows['product_discount']=[['product_id'=>1214,'customer_group_id'=>1,'quantity'=>1,'priority'=>1,'price'=>'90'],['product_id'=>1214,'customer_group_id'=>1,'quantity'=>5,'priority'=>0,'price'=>'10']];
$rows['product_special']=[['product_id'=>1214,'customer_group_id'=>2,'priority'=>0,'price'=>'1'],['product_id'=>1214,'customer_group_id'=>1,'priority'=>3,'price'=>'85'],['product_id'=>1214,'customer_group_id'=>1,'priority'=>2,'price'=>'80']];
$built=\Lavka\competitors_own_catalog_build($rows,$watch,$time);
own_expect($built['products'][0]['offers'][0]['price']==='55.00' && $built['products'][0]['offers'][0]['provenance']['base_price_kind']==='special','Active guest special priority overrides base and quantity-1 discount, wholesale and other groups ignored');
$bad=$rows; $bad['setting'][3]['value']='1'; $bad['product'][0]['tax_class_id']='9';
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time); own_expect(!$built['offer_count'] && in_array('tax_unresolved',$built['products'][0]['warnings'],true),'Unresolved tax never becomes a comparable price');
$bad=$rows; $bad['product_option'][]=['product_option_id'=>100,'product_id'=>1214,'option_id'=>8,'required'=>1]; $bad['option'][]=['option_id'=>8,'type'=>'text'];
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time); own_expect(!$built['offer_count'] && in_array('option_combination_unsupported',$built['products'][0]['warnings'],true),'Other required choices cannot be ignored');
$bad=$rows; $bad['product_option_value'][0]['price_prefix']='=';
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time); own_expect(!$built['offer_count'],'Nonstandard price modifiers are excluded');
$bad=$rows; $bad['product'][0]['minimum']='3';
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time); own_expect(!$built['offer_count'],'Minimum quantity is not ordinary single-pack retail');
$bad=$rows; $bad['product_to_store'][0]['store_id']='4';
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time); own_expect(!$built['offer_count'],'Other store membership excluded');
$bad=$rows; $bad['product_description']=[];
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time);
own_expect(!$built['offer_count'] && in_array('description_missing',$built['products'][0]['warnings'],true),'Product without a description in the active language is not public');
$bad['product_description']=[['product_id'=>1214,'language_id'=>9,'name'=>'Other language']];
$built=\Lavka\competitors_own_catalog_build($bad,$watch,$time);
own_expect(!$built['offer_count'] && in_array('description_missing',$built['products'][0]['warnings'],true),'Description in another language cannot satisfy storefront visibility');
$bad=$rows; $bad['currency'][0]['value']='40'; own_throws('catalog_currency_unsupported',function()use($bad,$watch,$time){\Lavka\competitors_own_catalog_build($bad,$watch,$time);});
foreach ([['value','0'],['value','1.00000001'],['status','0'],['status','01'],['decimal_place','1'],['decimal_place','3'],['decimal_place','2bad'],['decimal_place','0.0'],['decimal_place',null]] as $invalidCurrency) {
    $bad=own_fixture(); $bad['currency'][0][$invalidCurrency[0]]=$invalidCurrency[1];
    own_throws('catalog_currency_unsupported',function()use($bad,$watch,$time){\Lavka\competitors_own_catalog_build($bad,$watch,$time);});
}
$bad=own_fixture(); $bad['currency']=[];
own_throws('catalog_currency_unsupported',function()use($bad,$watch,$time){\Lavka\competitors_own_catalog_build($bad,$watch,$time);});
$bad=own_fixture(); $bad['currency'][]=$bad['currency'][0];
own_throws('catalog_currency_unsupported',function()use($bad,$watch,$time){\Lavka\competitors_own_catalog_build($bad,$watch,$time);});
$fixed=own_fixture(); $fixed['product_option']=[]; $fixed['product_option_value']=[]; $fixed['product'][0]['price']='75';
$fixed['product_description'][0]['name']='Синтетична пряність, 50 г';
$built=\Lavka\competitors_own_catalog_build($fixed,[1214=>array_merge($watch[1214],['pack_label'=>'50 г'])],$time);
own_expect($built['products'][0]['offers'][0]['price']==='75.00' && $built['products'][0]['offers'][0]['grams']===50.0,'Fixed product pack with no options');
$fixed['product_description'][0]['name']='Синтетична пряність';
$built=\Lavka\competitors_own_catalog_build($fixed,[1214=>array_merge($watch[1214],['pack_label'=>'50 г'])],$time);
own_expect(!$built['offer_count'] && in_array('pack_unknown',$built['products'][0]['warnings'],true),'Historical/freeform target pack label is not fresh catalogue pack evidence');
own_expect(\Lavka\competitors_own_catalog_pack('600 мг, 100 капсул')===null && \Lavka\competitors_own_catalog_pack('400 мл')===null,'Strength and volume are not grams');
$sqlSource=new OwnSqliteReadOnlySource(own_fixture());
$sqlRows=\Lavka\competitors_own_catalog_fetch($sqlSource,'oc_',$watch);
$sqlBuilt=\Lavka\competitors_own_catalog_build($sqlRows,$watch,$time);
own_expect($sqlBuilt['offer_count']===2 && $sqlBuilt['products'][0]['offers'][0]['price']==='125.00','Production SELECTs round-trip through query-only SQL fixture');
$wholeRows=own_fixture(); $wholeRows['currency'][0]['decimal_place']='0';
$wholeRows['product'][0]['price']='100.4900'; $wholeRows['product_option_value'][0]['price']='25.4900';
$wholeSqlSource=new OwnSqliteReadOnlySource($wholeRows);
$wholeSqlBuilt=\Lavka\competitors_own_catalog_build(\Lavka\competitors_own_catalog_fetch($wholeSqlSource,'oc_',$watch),$watch,$time);
own_expect($wholeSqlBuilt['products'][0]['offers'][0]['price']==='126' && $wholeSqlBuilt['products'][0]['offers'][0]['provenance']['raw_price']==='125.9800','Read-only SQL projection supports the actual zero-decimal UAH metadata');
own_expect(count($sqlRows['setting'])===4,'Settings view exposes only four selected configuration rows');
$changingSource=new OwnSqliteReadOnlySource(own_fixture()); $changingSource->mutateBetweenReads=true;
own_throws('catalog_source_changed',function()use($changingSource,$watch){\Lavka\competitors_own_catalog_fetch($changingSource,'oc_',$watch);});
$root=sys_get_temp_dir().'/lavka-own-catalog-'.bin2hex(random_bytes(6)); \Lavka\private_dir($root);
try {
    $notConfigured=\Lavka\competitors_own_catalog_sync_due($root);
    own_expect($notConfigured['state']==='not_configured' && !is_file($root.'/.own-catalog.lock'),'No background connection or lock before manual opt-in');
    foreach ([
        ['state'=>'ok','products'=>'invalid','last_success'=>\Lavka\utc_now()],
        ['state'=>'ok','products'=>[],'last_success'=>['invalid']],
        ['state'=>'ok','products'=>[],'last_success'=>'2026-02-30T00:00:00Z'],
        ['state'=>'ok','products'=>['invalid'],'last_success'=>\Lavka\utc_now()],
        ['state'=>'error','products'=>[],'last_success'=>\Lavka\utc_now()],
        ['state'=>'ok','products'=>[],'last_success'=>\Lavka\utc_now(),'currency_decimal_places'=>'0'],
        ['state'=>'ok','products'=>[],'last_success'=>\Lavka\utc_now(),'currency_decimal_places'=>null],
    ] as $invalidSnapshot) {
        file_put_contents($root.'/own-catalog.json',\Lavka\encode($invalidSnapshot));
        $beforeInvalid=file_get_contents($root.'/own-catalog.json');
        $invalid=\Lavka\competitors_own_catalog_sync_due($root);
        own_expect($invalid['state']==='error' && $invalid['products']===[] && $invalid['last_success']===null,'Invalid snapshot top-level shape is safe');
        own_expect(!is_file($root.'/.own-catalog.lock') && file_get_contents($root.'/own-catalog.json')===$beforeInvalid,'Malformed cache never enables source access or rewrites the file');
    }
    $validTime=\Lavka\utc_now();
    file_put_contents($root.'/own-catalog.json',\Lavka\encode(['state'=>'ok','products'=>[],'last_success'=>$validTime]));
    file_put_contents($root.'/own-catalog-status.json',\Lavka\encode(['state'=>'error','checked_at'=>$validTime,'error_code'=>'catalog_read_failed','products'=>'injected','last_success'=>'injected']));
    $safeStatus=\Lavka\competitors_own_catalog_read($root);
    own_expect($safeStatus['state']==='error' && $safeStatus['products']===[] && $safeStatus['last_success']===$validTime,'Status whitelist cannot replace products or successful timestamp');
    own_expect($safeStatus['currency_decimal_places']===2,'Legacy snapshots retain their existing two-decimal display interpretation');
    file_put_contents($root.'/own-catalog.json',\Lavka\encode(['state'=>'ok','products'=>$wholeSqlBuilt['products'],'last_success'=>$validTime,'currency_decimal_places'=>0]));
    file_put_contents($root.'/own-catalog-status.json',\Lavka\encode(['state'=>'ok','checked_at'=>$validTime,'currency_decimal_places'=>2]));
    $wholeRead=\Lavka\competitors_own_catalog_read($root);
    own_expect($wholeRead['currency_decimal_places']===0 && $wholeRead['products'][0]['offers'][0]['provenance']['raw_price']==='125.9800','Snapshot precision survives the reader and cannot be replaced by status metadata');
    file_put_contents($root.'/own-catalog-status.json',\Lavka\encode(['state'=>'ok','checked_at'=>['invalid']]));
    $invalidStatus=\Lavka\competitors_own_catalog_sync_due($root);
    own_expect($invalidStatus['state']==='error' && $invalidStatus['error_code']==='catalog_snapshot_invalid' && $invalidStatus['last_success']===$validTime
        && !is_file($root.'/.own-catalog.lock'),'Invalid status preserves valid cache but cannot activate background source access');
    unlink($root.'/own-catalog.json'); unlink($root.'/own-catalog-status.json');
    $requirements=\Lavka\competitors_own_catalog_requirements(); own_expect(count($requirements['tables'])===12 && !$requirements['shop_writes'],'Exact read-only catalogue requirements');
    if (PHP_VERSION_ID>=80200) {
        $db=new SQLite3($root.'/competitors.sqlite3');
        $db->exec('CREATE TABLE competitor_targets(id INTEGER,source_id TEXT,kind TEXT,url TEXT,label TEXT,pack_label TEXT,enabled INTEGER)');
        $db->exec("INSERT INTO competitor_targets VALUES(1,'lavka','product','https://lavka-speciy.com/index.php?product_id=1214','Synthetic','50 г / 200 г',1),(2,'lavka','product','https://lavka-speciy.com/index.php?product_id=812','Disabled','50 г',0),(3,'deluxe','product','https://deluxe.com.ua/x','Other','',1)"); $db->close();
        $config=['host'=>'127.0.0.1','database'=>'synthetic','user'=>'readonly','password'=>'PRIVATE_SOURCE_SENTINEL','prefix'=>'oc_'];
        file_put_contents($root.'/source.json',\Lavka\encode($config)); chmod($root.'/source.json',0600);
        $dbBefore=hash_file('sha256',$root.'/competitors.sqlite3'); $configBefore=hash_file('sha256',$root.'/source.json'); $calls=0;
        ob_start();
        $result=\Lavka\competitors_own_catalog_sync($root,$root.'/source.json',function($config,$selected)use(&$calls){++$calls; own_expect(array_keys($selected)===[1214],'Only selected enabled own product IDs'); trigger_error('PRIVATE_RAW_DRIVER_WARNING',E_USER_WARNING); return own_fixture();});
        own_expect(ob_get_clean()==='','Raw connection/driver warnings are suppressed');
        own_expect($result['state']==='ok' && $calls===1 && is_file($root.'/own-catalog.json'),'Successful manual sync saves separate private snapshot');
        own_expect((fileperms($root.'/own-catalog.json')&0777)===0600,'Snapshot private permissions');
        $snapshotBefore=file_get_contents($root.'/own-catalog.json');
        $result=\Lavka\competitors_own_catalog_sync($root,$root.'/source.json',function(){throw new RuntimeException('PRIVATE_RAW_DATABASE_ERROR');});
        own_expect($result['state']==='error' && $result['last_success']!==null && $result['offer_count']===2,'Failure keeps last successful prices with ineligible error state');
        own_expect(file_get_contents($root.'/own-catalog.json')===$snapshotBefore,'Failure does not replace successful snapshot');
        own_expect(strpos(\Lavka\encode($result),'PRIVATE_')===false,'Neither raw errors nor credentials appear');
        own_expect(hash_file('sha256',$root.'/competitors.sqlite3')===$dbBefore && hash_file('sha256',$root.'/source.json')===$configBefore,'Monitoring DB and source config remain untouched');
        $due=\Lavka\competitors_own_catalog_sync_due($root); own_expect($due['state']==='error' && $due['checked_at']===$result['checked_at'],'Daily backoff returns cached error without reconnect');
    } else own_expect(\Lavka\competitors_own_catalog_sync($root,$root.'/source.json')['error_code']==='requires_php82_cli','Old PHP refuses sync while shared reader/projection load');
    echo \Lavka\encode(['own_catalog_projection'=>'passed','exact_pack_options_specials_stock'=>'passed','read_only_optin_failure_preservation'=>'passed','php'=>PHP_VERSION,'network_requests'=>0])."\n";
} finally {
    foreach (scandir($root) as $name) if ($name!=='.' && $name!=='..') unlink($root.'/'.$name);
    rmdir($root);
}
