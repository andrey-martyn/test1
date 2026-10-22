<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__ . '/Sync.php';
require_once __DIR__ . '/CompetitorParser.php';

/** Fixed catalogue fields only; no customer, order or arbitrary setting fields. */
const COMPETITOR_CATALOG_FIELDS = [
    'product' => ['product_id','model','price','quantity','subtract','status','date_available','tax_class_id','minimum'],
    'product_to_store' => ['product_id','store_id'],
    'product_description' => ['product_id','language_id','name'],
    'product_option' => ['product_option_id','product_id','option_id','required'],
    'product_option_value' => ['product_option_value_id','product_option_id','product_id','option_id','option_value_id','quantity','subtract','price','price_prefix'],
    'option' => ['option_id','type'],
    'option_value_description' => ['option_value_id','language_id','name'],
    'product_discount' => ['product_id','customer_group_id','quantity','priority','price','date_start','date_end'],
    'product_special' => ['product_id','customer_group_id','priority','price','date_start','date_end'],
    'setting' => ['store_id','key','value','serialized'],
    'currency' => ['code','value','decimal_place','status'],
    'language' => ['language_id','code','status'],
];
const COMPETITOR_CATALOG_SETTING_KEYS = ['config_currency','config_language','config_customer_group_id','config_tax'];

function competitors_own_catalog_requirements(): array
{
    return ['state'=>'requirements','access'=>'SELECT','tables'=>COMPETITOR_CATALOG_FIELDS,
        'settings_view'=>'oc_analytics_catalog_settings','direct_setting_table_access'=>false,
        'setting_keys'=>COMPETITOR_CATALOG_SETTING_KEYS,'shop_writes'=>false,
        'scope'=>'enabled_lavka_product_targets','pricing'=>'standard_ocstore3_guest_single_pack'];
}

/** Read existing watched product IDs; never initialize or migrate a monitoring DB. */
function competitors_own_catalog_watch(string $directory): array
{
    $path=$directory.'/competitors.sqlite3';
    if (!is_file($path) || is_link($path)) return [];
    $db=new \SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true);
    try {
        $result=$db->query("SELECT id,url,label,pack_label FROM competitor_targets WHERE enabled=1 AND source_id='lavka' AND kind='product' ORDER BY id LIMIT 350");
        $watch=[];
        while ($row=$result->fetchArray(SQLITE3_ASSOC)) {
            $parts=parse_url($row['url']); $query=[];
            $hosts=['lavka-speciy.com','www.lavka-speciy.com'];
            if (file_exists($directory.'/sandbox.json')) $hosts[]='test.lavka-speciy.com';
            if (!is_array($parts) || !in_array(strtolower($parts['host']??''),$hosts,true)) continue;
            parse_str($parts['query']??'',$query);
            $id=$query['product_id']??null;
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',$id)) continue;
            $watch[(int)$id]=['target_id'=>(int)$row['id'],'product_id'=>(int)$id,'source_product_id'=>(int)$id,
                'label'=>mb_substr((string)$row['label'],0,300),'pack_label'=>mb_substr((string)$row['pack_label'],0,300),'url'=>(string)$row['url']];
        }
        return $watch;
    } finally { $db->close(); }
}

function competitors_own_catalog_select(\PDO $source,string $prefix,string $table,string $where='',array $arguments=[],?float $deadline=null): array
{
    if ($deadline!==null && microtime(true)>$deadline) throw new SyncError('catalog_time_budget');
    $fields=COMPETITOR_CATALOG_FIELDS[$table];
    $columns=implode(',',array_map(function($field){return '`'.$field.'`';},$fields));
    $physical=$table==='setting'?'analytics_catalog_settings':$table;
    $query='SELECT '.$columns.' FROM `'.$prefix.$physical.'`'.$where.' ORDER BY '.$columns;
    $stmt=$source->prepare($query); $stmt->execute($arguments); $rows=[];
    while ($row=$stmt->fetch(\PDO::FETCH_ASSOC)) {
        if (count($rows)>=30000) throw new SyncError('catalog_record_limit');
        $rows[]=$row;
    }
    $stmt->closeCursor(); return $rows;
}

/** Queries are read-only and selected product IDs are bound, never interpolated. */
function competitors_own_catalog_fetch(\PDO $source,string $prefix,array $watch): array
{
    if (!preg_match('/^[A-Za-z0-9_]{0,32}$/D',$prefix)) throw new SyncError('catalog_source_config');
    $ids=array_keys($watch); if (!$ids) return [];
    $in=' IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $deadline=microtime(true)+30;
    // LIMIT 0 first makes missing grants/schema a complete, all-or-nothing failure.
    foreach (COMPETITOR_CATALOG_FIELDS as $table=>$fields) {
        if (microtime(true)>$deadline) throw new SyncError('catalog_time_budget');
        $physical=$table==='setting'?'analytics_catalog_settings':$table;
        $statement=$source->query('SELECT '.implode(',',array_map(function($field){return '`'.$field.'`';},$fields)).' FROM `'.$prefix.$physical.'` LIMIT 0');
        $statement->closeCursor();
    }
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $read=function()use($source,$prefix,$ids,$in,$deadline):array {
        $rows=[];
        foreach (['product','product_to_store','product_description','product_option','product_option_value'] as $table) {
            $rows[$table]=competitors_own_catalog_select($source,$prefix,$table,' WHERE `product_id`'.$in,$ids,$deadline);
        }
        foreach (['product_discount','product_special'] as $table) {
            $rows[$table]=competitors_own_catalog_select($source,$prefix,$table,' WHERE `product_id`'.$in." AND (`date_start`='0000-00-00' OR `date_start`<NOW()) AND (`date_end`='0000-00-00' OR `date_end`>NOW())",$ids,$deadline);
        }
        $optionIds=[]; $valueIds=[];
        foreach ($rows['product_option'] as $option) $optionIds[(int)$option['option_id']]=true;
        foreach ($rows['product_option_value'] as $option) $valueIds[(int)$option['option_value_id']]=true;
        foreach (['option'=>$optionIds,'option_value_description'=>$valueIds] as $table=>$selected) {
            $key=$table==='option'?'option_id':'option_value_id';
            $rows[$table]=$selected?competitors_own_catalog_select($source,$prefix,$table,' WHERE `'.$key.'` IN ('.implode(',',array_fill(0,count($selected),'?')).')',array_keys($selected),$deadline):[];
        }
        $rows['setting']=competitors_own_catalog_select($source,$prefix,'setting',' WHERE `store_id`=0 AND `key` IN (?,?,?,?)',COMPETITOR_CATALOG_SETTING_KEYS,$deadline);
        $rows['currency']=competitors_own_catalog_select($source,$prefix,'currency'," WHERE `code`='UAH'",[],$deadline);
        $rows['language']=competitors_own_catalog_select($source,$prefix,'language','',[],$deadline);
        // Server time/date is used for date_available consistently with the active-price predicates.
        $rows['as_of_date']=(string)$source->query('SELECT CURRENT_DATE')->fetchColumn();
        return $rows;
        };
        $first=$read(); $second=$read();
        // InnoDB shares one read-only snapshot. Legacy MyISAM tables are checked
        // by two sorted reads; differing results never replace the prior copy.
        if (!hash_equals(hash('sha256',encode($first)),hash('sha256',encode($second)))) throw new SyncError('catalog_source_changed');
        return $second;
    } finally { $source->exec('ROLLBACK'); }
}

function competitors_own_catalog_amount($value): ?string
{
    if (!is_scalar($value) || !preg_match('/^-?\d{1,9}(?:\.\d{1,6})?$/D',(string)$value)) return null;
    return bcadd((string)$value,'0',4);
}

/** Match ocStore currency display without changing stored catalogue amounts. */
function competitors_own_catalog_round(string $value,int $decimals): string
{
    if (!in_array($decimals,[0,2],true)) throw new SyncError('catalog_currency_unsupported');
    $half=$decimals===0?'0.5':'0.005';
    return bccomp($value,'0',12)<0?bcsub($value,$half,$decimals):bcadd($value,$half,$decimals);
}
function competitors_own_catalog_pack(string $name): ?array
{
    $name=competitor_text($name);
    // Whole option value must identify one pack; strength, volume and multi-packs stay unknown.
    if (preg_match('/^(\d{1,6}(?:[.,]\d{1,3})?)\s*(?:кг|kg|гр\.?|г|g)$/iuD',$name)) {
        $grams=competitor_weight($name);
        return $grams===null?null:['grams'=>$grams,'label'=>$name];
    }
    if (preg_match('/^1\s*(?:шт\.?|pcs?\.?)$/iuD',$name)) return ['grams'=>null,'label'=>$name];
    return null;
}

/** Pure standard-ocStore projection. Unsupported price rules produce no invented offer. */
function competitors_own_catalog_build(array $rows,array $watch,string $checkedAt): array
{
    $settings=[];
    foreach ($rows['setting']??[] as $setting) {
        if ((int)$setting['store_id']!==0 || !in_array($setting['key'],COMPETITOR_CATALOG_SETTING_KEYS,true)) continue;
        if ((int)$setting['serialized']!==0 || isset($settings[$setting['key']])) throw new SyncError('catalog_settings_unsupported');
        $settings[$setting['key']]=(string)$setting['value'];
    }
    foreach (COMPETITOR_CATALOG_SETTING_KEYS as $key) if (!array_key_exists($key,$settings)) throw new SyncError('catalog_settings_missing');
    if ($settings['config_currency']!=='UAH' || !ctype_digit($settings['config_customer_group_id']) || (int)$settings['config_customer_group_id']<1
        || !in_array($settings['config_tax'],['0','1'],true)) throw new SyncError('catalog_settings_unsupported');
    $currencies=array_values(array_filter($rows['currency']??[],function($currency){return ($currency['code']??null)==='UAH';}));
    if (count($currencies)!==1) throw new SyncError('catalog_currency_unsupported');
    $currency=$currencies[0]; $precision=(string)($currency['decimal_place']??'');
    if ((string)($currency['status']??'')!=='1' || !in_array($precision,['0','2'],true)
        || !preg_match('/^\d{1,9}(?:\.\d{1,12})?$/D',(string)($currency['value']??''))
        || bccomp((string)$currency['value'],'1',12)!==0) throw new SyncError('catalog_currency_unsupported');
    $currencyDecimals=(int)$precision;
    $languageId=0;
    foreach ($rows['language']??[] as $language) if ($language['code']===$settings['config_language'] && (int)$language['status']===1) $languageId=(int)$language['language_id'];
    if (!$languageId) throw new SyncError('catalog_language_unknown');
    $group=(int)$settings['config_customer_group_id']; $byId=[];
    foreach (['product','product_description','product_to_store','product_option','product_option_value','product_special','product_discount'] as $table) {
        foreach ($rows[$table]??[] as $row) $byId[$table][(int)$row['product_id']][]=$row;
    }
    $types=[]; foreach ($rows['option']??[] as $option) $types[(int)$option['option_id']]=(string)$option['type'];
    $names=[]; foreach ($rows['option_value_description']??[] as $description) if ((int)$description['language_id']===$languageId) $names[(int)$description['option_value_id']]=(string)$description['name'];
    $products=[]; $offerCount=0;
    foreach ($watch as $id=>$target) {
        $entry=$target+['offers'=>[],'warnings'=>[],'source'=>'mysql_catalog','checked_at'=>$checkedAt,'method'=>'standard_ocstore3_catalog','currency_decimal_places'=>$currencyDecimals];
        $product=$byId['product'][$id][0]??null;
        if (!$product) { $entry['warnings'][]='product_missing'; $products[]=$entry; continue; }
        $entry['model']=mb_substr((string)$product['model'],0,100);
        $descriptionName='';
        foreach ($byId['product_description'][$id]??[] as $description) if ((int)$description['language_id']===$languageId) {
            $descriptionName=competitor_text((string)$description['name']); $entry['label']=mb_substr($descriptionName,0,300);
        }
        if ($descriptionName==='') $entry['warnings'][]='description_missing';
        $visible=false; foreach ($byId['product_to_store'][$id]??[] as $store) if ((int)$store['store_id']===0) $visible=true;
        if (!$visible || (int)$product['status']!==1 || (string)$product['date_available']>($rows['as_of_date']??date('Y-m-d'))) $entry['warnings'][]='product_not_public';
        if ((int)$product['minimum']>1) $entry['warnings'][]='minimum_quantity';
        if ($settings['config_tax']==='1' && (int)$product['tax_class_id']>0) $entry['warnings'][]='tax_unresolved';
        $base=competitors_own_catalog_amount($product['price']);
        if ($base===null || bccomp($base,'0',4)<0) $entry['warnings'][]='base_price_unknown';
        $priceKind='base';
        foreach (['product_discount','product_special'] as $table) {
            $candidates=array_values(array_filter($byId[$table][$id]??[],function($price)use($group,$table){return (int)$price['customer_group_id']===$group && ($table!=='product_discount' || (int)$price['quantity']===1);}));
            usort($candidates,function($a,$b){$priority=(int)$a['priority']<=>(int)$b['priority']; return $priority?:bccomp((string)$a['price'],(string)$b['price'],4);});
            if ($candidates) { $candidate=competitors_own_catalog_amount($candidates[0]['price']); if ($candidate===null || bccomp($candidate,'0',4)<0) $entry['warnings'][]='base_price_unknown'; else { $base=$candidate; $priceKind=$table==='product_special'?'special':'discount_quantity_1'; } }
        }
        $options=$byId['product_option'][$id]??[]; $optionValues=$byId['product_option_value'][$id]??[];
        $packGroups=[]; $values=[];
        foreach ($options as $option) {
            $optionId=(int)$option['product_option_id']; $type=$types[(int)$option['option_id']]??'';
            $packValues=[];
            foreach ($optionValues as $value) if ((int)$value['product_option_id']===$optionId) {
                $pack=competitors_own_catalog_pack($names[(int)$value['option_value_id']]??'');
                if ($pack!==null) $packValues[]=[$value,$pack];
            }
            if ($packValues && in_array($type,['select','radio','image'],true)) { $packGroups[]=$optionId; $values=array_merge($values,$packValues); }
            elseif ((int)$option['required']===1 || $packValues) $entry['warnings'][]='option_combination_unsupported';
        }
        if (!$options) {
            $grams=competitor_weight($descriptionName);
            $fixedPack=$grams===null?null:['grams'=>$grams,'label'=>(string)$grams.' г'];
            if ($fixedPack!==null) {
                $packGroups=[0];
                $values=[[['product_option_id'=>0,'product_option_value_id'=>0,'option_value_id'=>0,'price'=>'0','price_prefix'=>'+','subtract'=>0,'quantity'=>0],$fixedPack]];
            }
        }
        if (count($packGroups)!==1) $entry['warnings'][]=count($packGroups)>1?'option_combination_unsupported':'pack_unknown';
        if (!$entry['warnings']) foreach ($values as $pair) {
            list($value,$pack)=$pair; $delta=competitors_own_catalog_amount($value['price']);
            if ($delta===null || !in_array($value['price_prefix'],['+','-'],true)) { $entry['warnings'][]='option_price_unsupported'; $entry['offers']=[]; break; }
            $rawPrice=$value['price_prefix']==='+'?bcadd($base,$delta,4):bcsub($base,$delta,4);
            if (bccomp($rawPrice,'0',4)<=0) { $entry['warnings'][]='nonpositive_price'; continue; }
            // Round the raw sum once, as Currency::format does. Rounding the
            // base and the option separately can change the storefront price.
            $price=competitors_own_catalog_round($rawPrice,$currencyDecimals);
            if (bccomp($price,'0',$currencyDecimals)<=0) { $entry['warnings'][]='nonpositive_price'; continue; }
            $stock=(int)$product['quantity']>0 && ((int)$value['subtract']!==1 || (int)$value['quantity']>0);
            $entry['offers'][]=['label'=>$pack['label'],'grams'=>$pack['grams'],'price'=>$price,'currency'=>'UAH','unit'=>'pack',
                'availability'=>$stock?'in_stock':'out_of_stock','min_quantity'=>1,'condition'=>'','checked_at'=>$checkedAt,
                'source'=>'mysql_catalog','provenance'=>['product_id'=>(int)$id,'product_option_id'=>(int)$value['product_option_id'],
                    'product_option_value_id'=>(int)$value['product_option_value_id'],'option_value_id'=>(int)$value['option_value_id'],
                    'base_price'=>$base,'option_adjustment'=>$delta,'price_prefix'=>$value['price_prefix'],'base_price_kind'=>$priceKind,
                    'raw_price'=>$rawPrice,'currency_decimal_places'=>$currencyDecimals]];
        }
        $offerCount+=count($entry['offers']); $entry['warnings']=array_values(array_unique($entry['warnings'])); $products[]=$entry;
    }
    return ['state'=>'ok','source'=>'mysql_catalog','checked_at'=>$checkedAt,'last_success'=>$checkedAt,'products'=>$products,
        'product_count'=>count($products),'offer_count'=>$offerCount,'pricing_model'=>'standard_ocstore3_guest_single_pack','shop_writes'=>false,
        'currency_decimal_places'=>$currencyDecimals];
}

function competitors_own_catalog_utc($value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)) return false;
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
    return $date!==false && $date->format('Y-m-d\TH:i:s\Z')===$value;
}

function competitors_own_catalog_read(string $directory): array
{
    $result=['state'=>'not_configured','source'=>'mysql_catalog','products'=>[],'last_success'=>null,'checked_at'=>null,'error_code'=>null];
    $snapshotValid=false; $invalid=false;
    foreach (['own-catalog.json','own-catalog-status.json'] as $name) {
        $path=$directory.'/'.$name;
        if (!file_exists($path) && !is_link($path)) continue;
        if (!is_file($path) || is_link($path) || filesize($path)>4194304) { $invalid=true; continue; }
        try {
            $contents=@file_get_contents($path);
            if ($contents===false) throw new \UnexpectedValueException();
            $value=decode($contents);
            if (!is_array($value)) throw new \UnexpectedValueException();
            if ($name==='own-catalog.json') {
                if (($value['state']??null)!=='ok' || !is_array($value['products']??null)
                    || !competitors_own_catalog_utc($value['last_success']??null)) throw new \UnexpectedValueException();
                if (file_exists($directory.'/sandbox.json') && (($value['source_database']??null)!=='lavkasp_test' || ($value['environment']??null)!=='sandbox')) throw new \UnexpectedValueException();
                if (array_key_exists('currency_decimal_places',$value) && !in_array($value['currency_decimal_places'],[0,2],true)) throw new \UnexpectedValueException();
                foreach ($value['products'] as $product) if (!is_array($product)) throw new \UnexpectedValueException();
                $result['state']='ok'; $result['products']=$value['products']; $result['last_success']=$value['last_success'];
                $result['checked_at']=competitors_own_catalog_utc($value['checked_at']??null)?$value['checked_at']:$value['last_success'];
                $result['product_count']=count($value['products']); $result['offer_count']=0;
                foreach ($value['products'] as $product) if (is_array($product['offers']??null)) $result['offer_count']+=count($product['offers']);
                $result['pricing_model']='standard_ocstore3_guest_single_pack'; $result['shop_writes']=false; $snapshotValid=true;
                $result['source_database']=is_string($value['source_database']??null)?$value['source_database']:null;
                $result['environment']=($value['environment']??null)==='sandbox'?'sandbox':'read_only';
                $result['currency_decimal_places']=$value['currency_decimal_places']??2;
            } else {
                if (!in_array($value['state']??null,['ok','error'],true) || !competitors_own_catalog_utc($value['checked_at']??null)
                    || isset($value['error_code']) && (!is_string($value['error_code']) || !preg_match('/^catalog_[a-z0-9_]+$/D',$value['error_code']))) throw new \UnexpectedValueException();
                // Status cannot replace products, provenance or the successful timestamp.
                $result['checked_at']=$value['checked_at'];
                if ($value['state']==='error') { $result['state']='error'; $result['error_code']=$value['error_code']??'catalog_read_failed'; }
                elseif (!$snapshotValid) $invalid=true;
            }
        } catch (\Throwable $ignored) { $invalid=true; }
    }
    if ($invalid) {
        $result['state']='error'; $result['error_code']='catalog_snapshot_invalid';
        if (!$snapshotValid) { $result['products']=[]; $result['last_success']=null; }
    } elseif ($result['state']==='ok' && strtotime($result['last_success'])<time()-172800) {
        $result['state']='stale';
    }
    return $result;
}

/** Optional test reader returns fixed catalogue rows; no arbitrary SQL/URL arguments. */
function competitors_own_catalog_sync(string $directory,string $configFile,?callable $reader=null): array
{
    if (PHP_VERSION_ID<80200) return ['state'=>'error','error_code'=>'requires_php82_cli'];
    $directory=realpath($directory)?:'';
    if ($directory==='' || !is_dir($directory)) return ['state'=>'error','error_code'=>'private_directory_missing'];
    $manifest=$directory.'/deployment-php.json';
    if (is_file($manifest) && !is_link($manifest)) {
        try { $deployment=decode(file_get_contents($manifest)); }
        catch (\Throwable $ignored) { return ['state'=>'error','error_code'=>'deployment_manifest_invalid']; }
        foreach (['document_root','shop_root'] as $key) if (!empty($deployment[$key])) {
            $public=canonical_path($deployment[$key]);
            if ($directory===$public || str_starts_with($directory,$public.'/')) return ['state'=>'error','error_code'=>'private_directory_public'];
        }
    }
    umask(0077); $lock=fopen($directory.'/.own-catalog.lock','c');
    if (!$lock) return ['state'=>'error','error_code'=>'catalog_lock_failed'];
    try {
        if (!flock($lock,LOCK_EX|LOCK_NB)) return ['state'=>'skipped','error_code'=>'already_running'];
        $checkedAt=utc_now();
        try {
            $watch=competitors_own_catalog_watch($directory);
            if (!$watch) return ['state'=>'not_configured','error_code'=>'no_selected_products'];
            if (!is_file($configFile) || is_link($configFile)) throw new SyncError('catalog_source_missing');
            // PDO/TLS warnings can contain connection details. Only fixed safe
            // status codes are exposed; exceptions still enter the safe catch.
            set_error_handler(function(){return true;});
            try {
                $config=source_config($configFile);
                require_once __DIR__.'/SandboxRuntime.php';
                sandbox_assert_source($directory,$config,$configFile);
                if ($reader) $rows=$reader($config,$watch);
                else { $source=source_connect($config); $rows=competitors_own_catalog_fetch($source,$config['prefix']??'oc_',$watch); $source=null; }
            } finally { restore_error_handler(); }
            $snapshot=competitors_own_catalog_build($rows,$watch,$checkedAt);
            $snapshot['source_database']=$config['database'];
            $snapshot['environment']=file_exists($directory.'/sandbox.json')?'sandbox':'read_only';
            if ($snapshot['environment']==='sandbox') foreach ($snapshot['products'] as &$product) {
                $product['url']='https://test.lavka-speciy.com/index.php?route=product%2Fproduct&product_id='.$product['product_id'];
            }
            unset($product);
            atomic_write($directory.'/own-catalog.json',encode($snapshot));
            $status=['state'=>'ok','checked_at'=>$checkedAt,'last_success'=>$checkedAt,'error_code'=>null];
            atomic_write($directory.'/own-catalog-status.json',encode($status)); return $snapshot;
        } catch (\Throwable $error) {
            $code=$error instanceof SyncError && preg_match('/^catalog_[a-z0-9_]+$/D',$error->getMessage())?$error->getMessage():'catalog_read_failed';
            if ($error instanceof \PDOException && in_array((int)($error->errorInfo[1]??0),[1044,1142,1143,1227],true)) $code='catalog_select_required';
            $status=['state'=>'error','checked_at'=>$checkedAt,'error_code'=>$code];
            atomic_write($directory.'/own-catalog-status.json',encode($status)); return competitors_own_catalog_read($directory);
        }
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function competitors_own_catalog_sync_due(string $directory): array
{
    $state=competitors_own_catalog_read($directory);
    // The current delivery is a reviewable report format. A successful manual
    // import is the explicit opt-in before background source access is enabled.
    if (!is_file($directory.'/own-catalog.json') || is_link($directory.'/own-catalog.json') || empty($state['last_success'])
        || $state['error_code']==='catalog_snapshot_invalid') return $state;
    $last=is_string($state['checked_at'])?strtotime($state['checked_at']):false;
    if ($last!==false && $last>time()-86400) return $state;
    $updated=competitors_own_catalog_sync($directory,$directory.'/source.json');
    return ($updated['state']??null)==='skipped'?$state:$updated;
}
