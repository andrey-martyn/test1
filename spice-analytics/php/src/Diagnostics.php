<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__.'/Analytics.php';
require_once __DIR__.'/CompetitorEvidence.php';
require_once __DIR__.'/CompetitorDiscovery.php';

/** Numeric aggregates only: never copy arbitrary report, CRM or source payloads. */
function diagnostic_numbers($value, int $depth=0)
{
    if(is_int($value)||is_float($value)||is_bool($value)||$value===null)return $value;
    if(!is_array($value)&&!is_object($value))return null;
    if($depth>9)return null;
    $out=[];$count=0;
    foreach((array)$value as $key=>$item){
        if(++$count>1500)break;
        if(preg_match('/(?:ids?|names?|emails?|phones?|notes?|texts?|messages?|coupons?|owners?|variants|source|label|title|url|city|zone|country|reason|payload|summary|activity|weeks)$/i',(string)$key))continue;
        if(!preg_match('/^[a-zA-Z0-9_.-]{1,50}$/D',(string)$key))continue;
        $clean=diagnostic_numbers($item,$depth+1);if($clean!==null||$item===null)$out[$key]=$clean;
    }
    return $out;
}
function diagnostic_enum($value): string
{
    return is_string($value)&&preg_match('/^[a-zA-Z0-9_.:-]{1,70}$/D',$value)?$value:'';
}
function diagnostic_public_url(string $url): string
{
    try{$url=competitors_http_url($url)['url'];}catch(\Throwable $ignored){return '';}
    $p=parse_url($url);$query=[];parse_str($p['query']??'',$query);$allowed=[];
    if(isset($query['product_id'])&&preg_match('/^\d{1,12}$/D',(string)$query['product_id']))$allowed['product_id']=$query['product_id'];
    if(($query['route']??'')==='product/product')$allowed['route']='product/product';
    return $p['scheme'].'://'.$p['host'].($p['path']??'/').($allowed?'?'.http_build_query($allowed):'');
}
function diagnostic_public_fields(array $row, int $depth=0): array
{
    if($depth>5)return [];
    $numeric=['id','target_id','own_id','candidate_id','score','price','old_price','grams','grams_override','min_quantity','enabled','match_confirmed','queued','count','checked','succeeded','failed','deferred','saved','skipped','suggestions','queued_candidates','prices_loaded','limit','budget_seconds','status','http_status','bytes','errors','seconds','peak_mb','links','pages'];
    $enums=['source_id','kind','state','error_code','method','currency','unit','availability','packaging','packaging_override','packaging_source','runner_revision','php'];
    $text=['title','label','pack_label','match_group','condition'];$out=[];
    foreach($numeric as $key)if(isset($row[$key])&&(is_numeric($row[$key])||is_bool($row[$key])))$out[$key]=is_bool($row[$key])?$row[$key]:0+$row[$key];
    foreach($enums as $key)if(isset($row[$key]))$out[$key]=diagnostic_enum($row[$key]);
    foreach($text as $key)if(is_string($row[$key]??null))$out[$key]=\lavka_competitor_diagnostic_text($row[$key],180);
    foreach(['url','href'] as $key)if(is_string($row[$key]??null))$out[$key]=diagnostic_public_url($row[$key]);
    foreach(['last_success','next_check_at','checked_at','started_at','finished_at','observed_at','occurred_at'] as $key)if(is_string($row[$key]??null)&&preg_match('/^[0-9TZ:+. -]{10,35}$/D',$row[$key]))$out[$key]=$row[$key];
    foreach(['observation','last_attempt','offers','discovery','matching','evidence','before','after'] as $key){
        if(!is_array($row[$key]??null))continue;
        $value=$row[$key];$out[$key]=array_is_list($value)?array_map(function($r)use($depth){return is_array($r)?diagnostic_public_fields($r,$depth+1):null;},array_slice($value,0,12)):diagnostic_public_fields($value,$depth+1);
    }
    if(is_array($row['errors']??null))$out['errors']=array_values(array_intersect($row['errors'],['evidence_storage_failed','evidence_structure_failed','evidence_size_limit']));
    return $out;
}
/** Saved public terms, stripped contacts and bounded source excerpts. */
function diagnostic_terms(array $targets): array
{
    require_once __DIR__.'/CompetitorTerms.php';
    $pages=array_values(array_filter($targets,function($target){return $target['enabled']&&$target['kind']==='terms'&&in_array($target['source_id'],['lavka','deluxe','spicery','banka','specii'],true);}));
    $matrix=competitors_terms_comparison($targets);$out=['active_pages'=>count($pages),'pages'=>[],'rows'=>[]];
    foreach(array_slice($pages,0,20) as $target){$row=diagnostic_public_fields($target);unset($row['observation']);
        $text=$target['observation']['text']??'';$lines=[];
        if(is_string($text))foreach(array_slice(preg_split('/\r?\n/',mb_substr($text,0,8000)),0,80) as $line)$lines[]=\lavka_competitor_diagnostic_text($line,300);
        $row['text_excerpt']=mb_substr(implode("\n",$lines),0,6000);$row['facts_count']=is_string($text)?count(competitors_terms_extract($text)):0;$out['pages'][]=$row;
    }
    foreach($matrix['rows'] as $row){$clean=['id'=>$row['id'],'label'=>$row['label'],'cells'=>[]];
        foreach($row['cells'] as $source=>$cell){$value=$cell['value'];$c=['value'=>is_string($value)?\lavka_competitor_diagnostic_text($value,360):$value,'display'=>\lavka_competitor_diagnostic_text($cell['display'],360),'state'=>$cell['state'],'page_count'=>$cell['page_count']??0,'evidence'=>[]];
            foreach(array_slice($cell['evidence'],0,4) as $e)$c['evidence'][]=['target_id'=>$e['target_id'],'url'=>diagnostic_public_url($e['url']),'checked_at'=>$e['checked_at'],'state'=>$e['state'],'quote'=>\lavka_competitor_diagnostic_text($e['quote'],600)];
            $clean['cells'][$source]=$c;
        }$out['rows'][]=$clean;
    }
    return $out;
}

/** Fixed public-page support file, never a general private-file download. */
function diagnostic_saved_pages(string $directory): array
{
    $file=$directory.'/public-page-diagnostics.json';
    if(!is_file($file)||is_link($file)||filesize($file)>131072)return ['available'=>false];
    try{$input=decode(file_get_contents($file));}catch(\Throwable $ignored){return ['available'=>false,'reason'=>'invalid_saved_report'];}
    if(($input['schema']??'')!=='lavka-public-pages-v1'||!is_array($input['report']??null))return ['available'=>false,'reason'=>'invalid_saved_report'];
    $keys=array_fill_keys(explode(' ','schema collected_at collector_revision report state reason read_only selected checked deferred seconds targets id source kind label url http status final_url bytes parser method error_code offers_count links_count offers document title h1 canonical og_url scope tag class itemprop has_h1 product_meta name value price_nodes text content data-price data-price-value data-base-price data-weight data-grams data-option-value-id data-product-option-value-id data-price-prefix data-prefix price-prefix purchase_options control selected catalog_anchors href ancestors depth context availability_nodes recognized price old_price grams min_quantity availability packaging currency unit condition document_error truncated sku_options structured_offers current_label data-treevalue data-price-id data-price-new'),true);
    $walk=function($value,int $depth=0,string $field='')use(&$walk,$keys){
        if($depth>10)return null;
        if(is_array($value)){$out=[];foreach(array_slice($value,0,40,true) as $key=>$part){if(!is_int($key)&&!isset($keys[$key]))continue;$out[$key]=$walk($part,$depth+1,(string)$key);}return $out;}
        if(is_string($value)){
            if($field==='collected_at' && competitors_terms_timestamp($value)!==null)return $value;
            if($field==='collector_revision' && preg_match('/^[a-f0-9]{16}$/D',$value))return $value;
            if($field==='id' && ($publicId=\lavka_competitor_diagnostic_id($value))!==null)return $publicId;
            return \lavka_competitor_diagnostic_text($value,700);
        }
        return is_numeric($value)||is_bool($value)||$value===null?$value:null;
    };
    return ['available'=>true]+$walk($input);
}

function diagnostic_competitors(string $directory): array
{
    if(is_link($directory.'/competitors.sqlite3'))return ['available'=>false,'reason'=>'invalid_database_path'];
    if(!is_file($directory.'/competitors.sqlite3'))return ['available'=>false,'reason'=>'not_initialized'];
    // Read-only connection: export does not initialize modules, queue work or fetch URLs.
    $db=new \PDO('sqlite:'.$directory.'/competitors.sqlite3',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::SQLITE_ATTR_OPEN_FLAGS=>\PDO::SQLITE_OPEN_READONLY]);
    $tables=array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(),'name');
    $result=['available'=>true,'counts'=>[],'targets'=>[],'suggestions'=>[],'evidence'=>[],'runs'=>[]];
    foreach(['competitor_targets','competitor_search_index','competitor_suggestions','competitor_scan_pages','competitor_changes','competitor_discoveries','competitor_page_evidence'] as $table)if(in_array($table,$tables,true))$result['counts'][$table]=(int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn();
    $rows=$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll();$allTargets=array_map(__NAMESPACE__.'\\competitors_target_row',$rows);$strata=[];$stats=[];$activeStats=[];$archivedStats=[];
    foreach($rows as $row){$target=competitors_target_row($row);$state=$target['last_attempt']['state']??'unloaded';$key=$target['source_id'].':'.$target['kind'].':'.$state;$stats[$key]=($stats[$key]??0)+1;if($target['enabled'])$activeStats[$key]=($activeStats[$key]??0)+1;else $archivedStats[$key]=($archivedStats[$key]??0)+1;
        if(($strata[$key]??0)<3&&count($result['targets'])<60){$strata[$key]=($strata[$key]??0)+1;$result['targets'][]=diagnostic_public_fields($target);}}
    $result['target_states']=$stats;$result['target_states_active']=$activeStats;$result['target_states_archived']=$archivedStats;
    if(in_array('competitor_suggestions',$tables,true)){
        $q=$db->query('SELECT s.own_id,s.candidate_id,s.score,o.label AS own_title,c.title,c.url,c.source_id FROM competitor_suggestions s JOIN competitor_targets o ON o.id=s.own_id JOIN competitor_search_index c ON c.id=s.candidate_id ORDER BY s.score DESC,s.own_id LIMIT 200');$seen=[];
        foreach($q as $row){$key=$row['source_id'].':'.((int)$row['score']>=90?'high':'review');if(($seen[$key]??0)>=5)continue;$seen[$key]=($seen[$key]??0)+1;$clean=diagnostic_public_fields($row);$clean['own_title']=\lavka_competitor_diagnostic_text($row['own_title'],180);$clean['identity']=competitors_identity_compare($row['own_title'],$row['title'],$row['url']);$result['suggestions'][]=$clean;}
    }
    if(in_array('competitor_page_evidence',$tables,true)){
        $q=$db->query('SELECT * FROM competitor_page_evidence ORDER BY (error_code IS NOT NULL) DESC,captured_at DESC LIMIT 100');$seen=[];
        foreach($q as $row){if(count($result['evidence'])>=20)break;$key=$row['source_id'].':'.($row['error_code']??'ok');if(($seen[$key]??0)>=4)continue;$seen[$key]=($seen[$key]??0)+1;
            $result['evidence'][]=['source_id'=>diagnostic_enum($row['source_id']),'url'=>diagnostic_public_url($row['url']),'captured_at'=>$row['captured_at'],'error_code'=>diagnostic_enum($row['error_code']??''),'page'=>decode($row['payload'])];}
    }
    $result['evidence_note']='Saved sanitized page structure from ordinary background checks; no HTTP requests during export. Empty until pages are checked after this update.';
    if(in_array('competitor_runs',$tables,true))foreach($db->query('SELECT payload FROM competitor_runs ORDER BY id DESC LIMIT 5') as $row)$result['runs'][]=diagnostic_public_fields(decode($row['payload']));
    $result['cadence']=competitors_run_cadence($result['runs']);
    require_once __DIR__.'/CompetitorOwnCatalog.php';
    $own=competitors_own_catalog_read($directory);
    $result['own_catalog']=diagnostic_public_fields($own)+diagnostic_numbers(array_intersect_key($own,array_flip(['product_count','offer_count','shop_writes'])));
    $result['own_catalog']['products']=array_map(function($product){return diagnostic_public_fields($product)+['product_id'=>(int)($product['product_id']??0)];},array_slice($own['products']??[],0,8));
    $result['terms']=diagnostic_terms($allTargets);
    $result['collected_pages']=diagnostic_saved_pages($directory);
    $result['scan_errors']=[];$result['changes']=[];
    if(in_array('competitor_scan_pages',$tables,true))foreach($db->query("SELECT source_id,url,kind,state,error_code,checked_at,errors FROM competitor_scan_pages WHERE state='error' ORDER BY checked_at DESC LIMIT 15") as $row)$result['scan_errors'][]=diagnostic_public_fields($row);
    if(in_array('competitor_changes',$tables,true))foreach($db->query('SELECT target_id,source_id,kind,occurred_at FROM competitor_changes ORDER BY id DESC LIMIT 10') as $row)$result['changes'][]=diagnostic_public_fields($row);
    return $result;
}
function diagnostic_client(array $input, callable $ref): array
{
    $result=['viewport'=>[],'scripts'=>[],'errors'=>[],'probes'=>[]];
    foreach(['width','height','dpr'] as $key)if(is_numeric($input['viewport'][$key]??null))$result['viewport'][$key]=max(0,min(20000,(float)$input['viewport'][$key]));
    $enums=['engine'=>['chromium','firefox','webkit','edge'],'view'=>['overview','overview-report','products','customers','actions','retention','segments','opportunities','seasonality','geography','ga4','manual','competitors','settings'],'gender'=>['all','male','female','unknown'],'sort'=>['amount','priority','last','overdue','name']];
    foreach($enums as $key=>$allowed)$result[$key]=in_array($input[$key]??null,$allowed,true)?$input[$key]:'';
    foreach(array_slice((array)($input['scripts']??[]),0,40) as $row)if(is_array($row)&&preg_match('/^[a-zA-Z0-9_-]+\.js$/D',(string)($row['name']??'')))$result['scripts'][]=['name'=>$row['name'],'version'=>preg_match('/^[a-f0-9]{16}$/D',(string)($row['version']??''))?$row['version']:''];
    // Error classifications/locations only: exception messages may contain customer data.
    foreach(array_slice((array)($input['errors']??[]),0,20) as $row)if(is_array($row))$result['errors'][]=['type'=>diagnostic_enum($row['type']??''),'category'=>diagnostic_enum($row['category']??''),'script'=>preg_match('/^[a-zA-Z0-9_-]+\.js$/D',(string)($row['script']??''))?$row['script']:'','line'=>max(0,min(100000,(int)($row['line']??0)))];
    foreach(array_slice((array)($input['probes']??[]),0,15) as $row){if(!is_array($row))continue;$clean=['name'=>diagnostic_enum($row['name']??''),'count'=>max(0,(int)($row['count']??0)),'passed'=>isset($row['passed'])?(bool)$row['passed']:null,'samples'=>[]];foreach(array_slice((array)($row['samples']??[]),0,5) as $sample){if(!is_array($sample))continue;$s=diagnostic_numbers(array_intersect_key($sample,array_flip(['amount','order_count','lifetime_count','priority','overdue_days','price','grams'])));if(isset($sample['customer_id'])&&is_scalar($sample['customer_id']))$s['customer_ref']=$ref('C',(string)$sample['customer_id']);$clean['samples'][]=$s;}$result['probes'][]=$clean;}
    return $result;
}
function diagnostic_build(Analytics $store,int $year,string $asof,string $currency,string $gender,bool $includeNames,array $client=[]): array
{
    $salt=random_bytes(32);$ref=function(string $type,string $id)use($salt): string{return $type.'-'.substr(hash_hmac('sha256',$id,$salt),0,12);};
    $full=$store->report(0,$asof,$currency);$r=$year===0&&$gender==='all'?$full:$store->report($year,$asof,$currency,$gender);
    $checks=[];$check=function(string $name,$actual,$expected)use(&$checks):void{$checks[]=['name'=>$name,'passed'=>is_numeric($actual)&&is_numeric($expected)?abs((float)$actual-(float)$expected)<0.02:$actual===$expected,'actual'=>$actual,'expected'=>$expected];};
    $check('history.orders',array_sum(array_column($full['annual'],'order_count')),$full['metrics']['order_count']);
    $check('history.amount',array_sum(array_column($full['annual'],'amount')),$full['metrics']['amount']);
    $check('gender.orders',array_sum(array_column($full['gender_breakdown'],'order_count')),$full['metrics']['order_count']);
    $check('gender.amount',array_sum(array_column($full['gender_breakdown'],'amount')),$full['metrics']['amount']);
    $check('customers.period_orders',array_sum(array_column($r['customers'],'order_count')),$r['metrics']['order_count']);
    $check('customers.period_amount',array_sum(array_column($r['customers'],'amount')),$r['metrics']['amount']);
    $check('products.quantity',array_sum(array_column($r['products'],'quantity')),$r['metrics']['quantity']);
    foreach($r['location_modes'] as $mode=>$rows){$check('geography.'.$mode.'.orders',array_sum(array_column($rows,'count')),$r['metrics']['order_count']);$check('geography.'.$mode.'.amount',array_sum(array_column($rows,'amount')),$r['metrics']['amount']);}
    foreach($full['loyalty']['gender']['rows'] as $row){$check('loyalty.'.$row['key'].'.cohort',$row['eligible']+$row['pending'],$row['customers']);$check('loyalty.'.$row['key'].'.bounds',$row['returned']<=$row['eligible']&&$row['regular_180']<=$row['eligible_180'],true);}
    foreach($full['regional']['modes']['address'] as $window=>$channels)foreach((array)$channels['all']['totals'] as $y=>$total){$sum=0;foreach($channels['all']['regions'] as $regions)$sum+=$regions[$y]['count']??0;$check('regions.'.$window.'.'.$y.'.orders',$sum,$total['count']);$check('regions.'.$window.'.'.$y.'.channels',(((array)$channels['delivery']['totals'])[$y]['count']??0)+(((array)$channels['pickup']['totals'])[$y]['count']??0),$total['count']);}
    $byId=array_fill_keys(array_column($r['customers'],'id'),true);$pairs=$r['duplicate_pairs'];$validPairs=true;foreach($pairs as $pair)if(count(array_unique($pair['ids']))!==2||array_diff($pair['ids'],array_keys($byId)))$validPairs=false;$check('duplicates.references',$validPairs,true);
    $out=['schema'=>'lavka-system-diagnostics','schema_version'=>1,'generated_at'=>utc_now(),'privacy'=>['names_included'=>$includeNames,'contacts_included'=>false,'identifiers'=>'random per-export references','raw_html_included'=>false],
        'scope'=>['year'=>$year,'as_of'=>$asof,'currency'=>$currency,'gender'=>$gender,'history_scope'=>'all eligible history in this currency as of selected date'],
        'runtime'=>['php'=>PHP_VERSION,'memory_limit'=>ini_get('memory_limit'),'source_fingerprint'=>hash_file('sha256',__DIR__.'/Analytics.php'),'worker_fingerprint'=>substr(hash_file('sha256',dirname(__DIR__).'/bin/competitors.php'),0,16),'evidence_fingerprint'=>substr(hash_file('sha256',__DIR__.'/CompetitorEvidence.php'),0,16),'gender_fingerprint'=>hash_file('sha256',__DIR__.'/Gender.php')],
        'checks'=>$checks,'selected'=>['metrics'=>$r['metrics'],'gender'=>array_map(function($row){return ['key'=>$row['key']]+diagnostic_numbers($row);},$r['gender_breakdown']),'loyalty'=>diagnostic_numbers($r['loyalty']),'ga4'=>diagnostic_numbers(array_intersect_key((array)$r['ga4'],array_flip(['available','daily_available','transactions_available','daily_rows','transaction_rows','totals','channels','trend']))),'crm'=>diagnostic_numbers($r['crm']['stats']),'marketing'=>diagnostic_numbers(array_intersect_key($r['marketing'],array_flip(['segments','cohorts','conversion','median_second_days','frequency','recency','rfm_matrix','first_amount','repeat_amount','repeat_amount_share','top20_customer_share','abc','hours','months'])))],
        'history'=>['annual'=>diagnostic_numbers($full['annual']),'totals'=>$full['history_totals'],'regions'=>diagnostic_numbers($full['regional'])],
        'statuses_scope'=>'Whole imported snapshot, all dates and currencies; included statuses apply to selected report.',
        'settings'=>array_intersect_key($store->settings,array_flip(['status_ids','interval_days','grace_days','minimum_orders','followup_min_orders','contact_cooldown_days'])),
        'statuses'=>[],'unknown_gender'=>['count'=>$r['gender_diagnostics']['customer_count'],'reasons'=>$r['gender_diagnostics']['reasons'],'samples'=>[]], 'samples'=>['customers'=>[],'products'=>[],'duplicate_pairs'=>[],'orders'=>[]],
        'coverage'=>['server'=>'Reconciliations on full saved data, with bounded samples of selected filters.','browser'=>'Pure filter/sort probes and errors captured since this page opened; not a full interaction test.','not_verified'=>['remote shop state since last sync','live competitor pages during export','all UI interactions','payment or delivery completion']]];
    $statusCounts=[];foreach($store->orders as $o){$key=$o['order_status_id'];$statusCounts[$key]=($statusCounts[$key]??0)+1;}
    foreach($statusCounts as $id=>$count)$out['statuses'][]=['id'=>(string)$id,'label'=>\lavka_competitor_diagnostic_text($store->statuses[$id]??'',70),'count'=>$count,'included'=>in_array((string)$id,$store->settings['status_ids'],true)];
    foreach($r['gender_diagnostics']['samples'] as $sample){$row=['customer_ref'=>$ref('C',$sample['customer_id']),'reason'=>$sample['reason'],'order_refs'=>array_map(function($id)use($ref){return $ref('O',$id);},$sample['order_ids'])];if($includeNames)$row['name_fields']=array_map(function($name){return \lavka_competitor_diagnostic_text($name,100);},$sample['names']);$out['unknown_gender']['samples'][]=$row;}
    $chosen=[];foreach(['amount','priority','overdue_days'] as $sort){$rows=$r['customers'];usort($rows,function($a,$b)use($sort){return ($b[$sort]??0)<=>($a[$sort]??0);});foreach(array_slice($rows,0,6) as $c)$chosen[$c['id']]=$c;}
    $orderIds=[];foreach($chosen as $c){$row=diagnostic_numbers(array_intersect_key($c,array_flip(['amount','lifetime_amount','average','order_count','lifetime_count','days_since','interval_days','overdue_days','priority','followup_eligible','account_count'])));$row+=['customer_ref'=>$ref('C',$c['id']),'gender'=>diagnostic_enum($c['gender_estimate']),'gender_reason'=>diagnostic_enum($c['gender_reason']),'reminder'=>diagnostic_enum($c['reminder']),'segment'=>diagnostic_enum($c['segment']??'')];$out['samples']['customers'][]=$row;foreach(array_slice($c['order_ids'],0,2) as $id)$orderIds[$id]=true;}
    foreach(array_slice($r['products'],0,16) as $p)$out['samples']['products'][]=['product_id'=>$p['product_id'],'title'=>\lavka_competitor_diagnostic_text($p['name'],180),'pack'=>diagnostic_public_fields($p['pack']),'quantity'=>$p['quantity'],'amount'=>$p['amount'],'grams'=>$p['grams'],'orders'=>$p['order_count'],'customers'=>$p['customers']];
    foreach(array_slice($pairs,0,15) as $pair)$out['samples']['duplicate_pairs'][]=['customer_refs'=>array_map(function($id)use($ref){return $ref('C',$id);},$pair['ids']),'kinds'=>array_values(array_filter($pair['kinds'],function($k){return in_array($k,['name','email','phone'],true);}))];
    foreach($r['gender_diagnostics']['samples'] as $sample)foreach(array_slice($sample['order_ids'],0,1) as $id)$orderIds[$id]=true;
    foreach(array_slice(array_keys($orderIds),0,40) as $id){$o=$store->orders[$id]??null;if(!$o)continue;$location=$store->geo->address($o);$row=['order_ref'=>$ref('O',(string)$id),'date'=>substr($o['date_added'],0,10),'amount'=>money(order_value($o)),'currency'=>$o['currency_code'],'status_id'=>$o['order_status_id'],'channel'=>regional_channel($store->geo,$o),'region'=>regional_key($location),'lines'=>[]];foreach(array_slice($store->lines[$id]??[],0,5) as $line)$row['lines'][]=['product_id'=>$line['product_id'],'title'=>\lavka_competitor_diagnostic_text($line['name'],120),'quantity'=>$line['quantity'],'price'=>money($line['price']),'total'=>money($line['total']),'pack'=>diagnostic_public_fields($line['pack'])];$out['samples']['orders'][]=$row;}
    try{$out['competitors']=diagnostic_competitors($store->directory);}catch(\Throwable $error){$out['competitors']=['available'=>false,'reason'=>'diagnostic_module_failed','location'=>basename($error->getFile()).':'.$error->getLine()];$check('competitors.export',false,true);}
    if(isset($out['competitors']['terms'])){$terms=$out['competitors']['terms'];$first=$terms['rows'][0]??null;if($first)$check('competitors.terms_sources_visible',array_sum(array_column($first['cells'],'page_count')),min(350,$terms['active_pages']));}
    $out['client']=diagnostic_client($client,$ref);
    foreach($out['client']['probes'] as $probe){$expected=null;if(in_array($probe['name'],['customers_amount','customers_priority','customers_overdue'],true))$expected=count(array_filter($r['customers'],function($c){return $c['order_count']>0;}));if($probe['name']==='customers_overdue_filter')$expected=count(array_filter($r['customers'],function($c){return $c['order_count']>0&&$c['reminder']==='overdue';}));if(strpos($probe['name'],'duplicates_')===0){$kind=substr($probe['name'],11);if(in_array($kind,['all','email','phone','name'],true))$expected=count(array_filter($pairs,function($p)use($kind){return $kind==='all'||in_array($kind,$p['kinds'],true);}));}if($expected!==null)$check('browser.'.$probe['name'].'.count',$probe['count'],$expected);}
    $out['checks']=$checks;
    $out['result']=['checks'=>count($checks),'failed'=>count(array_filter($checks,function($c){return !$c['passed'];})),'client_errors'=>count($out['client']['errors']),'client_failed'=>count(array_filter($out['client']['probes'],function($p){return $p['passed']===false;}))];
    return $out;
}

/** Even a broken calculation can yield a small, non-sensitive diagnostic file. */
function diagnostic_fallback(\PDO $db,int $year,string $asof,string $currency,\Throwable $error,array $client): array
{
    $salt=random_bytes(32);$ref=function(string $type,string $id)use($salt):string{return $type.'-'.substr(hash_hmac('sha256',$id,$salt),0,12);};
    $counts=[];
    try{foreach($db->query('SELECT table_name,count(*) AS records FROM raw_records GROUP BY table_name') as $row)if(isset(SOURCE_FIELDS[$row['table_name']]))$counts[$row['table_name']]=(int)$row['records'];}catch(\Throwable $ignored){}
    $browser=diagnostic_client($client,$ref);
    return ['schema'=>'lavka-system-diagnostics','schema_version'=>1,'generated_at'=>utc_now(),'partial'=>true,'scope'=>['year'=>$year,'as_of'=>$asof,'currency'=>$currency],
        'privacy'=>['names_included'=>false,'contacts_included'=>false],'runtime'=>['php'=>PHP_VERSION,'source_fingerprint'=>hash_file('sha256',__DIR__.'/Analytics.php')],
        'checks'=>[['name'=>'report.calculation','passed'=>false]],'calculation_error'=>['type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()],
        'import_record_counts'=>$counts,'client'=>$browser,'coverage'=>['server'=>'Calculation failed; aggregate checks and report samples could not be produced. Raw error text is excluded.'],
        'result'=>['checks'=>1,'failed'=>1,'client_failed'=>count(array_filter($browser['probes'],function($p){return $p['passed']===false;})),'client_errors'=>count($browser['errors'])]];
}
