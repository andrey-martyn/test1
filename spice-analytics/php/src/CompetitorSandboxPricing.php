<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/Competitors.php';
require_once __DIR__ . '/CompetitorOwnCatalog.php';
require_once __DIR__ . '/SandboxEnvironment.php';

final class SandboxPricingError extends \RuntimeException
{
    public $errorCode;
    public function __construct(string $code) { $this->errorCode=$code; parent::__construct($code); }
}

const SANDBOX_PRICING_FACTORS = [20=>'0.5',50=>'1',200=>'3.6',500=>'7.5',1000=>'13.5'];

function sandbox_pricing_amount($value): string
{
    if (!is_string($value) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$value) || bccomp($value,'0',2)<=0) throw new SandboxPricingError('invalid_base_price');
    return bcadd($value,'0',2);
}

/** Each pack is rounded from the original base once; no chain of rounded prices. */
function sandbox_pricing_formula(string $base): array
{
    $base=sandbox_pricing_amount($base); $prices=[];
    foreach (SANDBOX_PRICING_FACTORS as $grams=>$factor) $prices[$grams]=bcadd(bcmul($base,$factor,6),'0.005',2);
    return $prices;
}

/** The marker and credentials are local-only. No arbitrary connection selectors. */
function sandbox_pricing_configuration(string $directory): array
{
    $real=realpath($directory);
    if (!$real || $real!==rtrim($directory,'/') || !is_dir($real)) throw new SandboxPricingError('sandbox_directory_invalid');
    $marker=$real.'/sandbox.json'; $sourceFile=$real.'/source.json';
    foreach ([$marker,$sourceFile] as $path) if (!is_file($path) || is_link($path) || filesize($path)>65536) throw new SandboxPricingError('sandbox_not_configured');
    try { $sandbox=decode(file_get_contents($marker)); $source=source_config($sourceFile); }
    catch (\Throwable $ignored) { throw new SandboxPricingError('sandbox_configuration_invalid'); }
    if (!is_array($sandbox) || ($sandbox['environment']??null)!=='sandbox' || ($sandbox['database']??null)!=='lavkasp_test'
        || ($sandbox['allowed_shop_database']??null)!=='lavkasp_test' || ($sandbox['source_user']??null)!=='lavkasp_sandbox'
        || ($sandbox['writes_enabled']??false)!==true || ($sandbox['test_store_verified']??false)!==true || ($sandbox['data_dir']??null)!==$real
        || ($sandbox['public_url']??null)!=='https://test.lavka-speciy.com/analytics') throw new SandboxPricingError('sandbox_marker_invalid');
    if (($source['database']??null)!=='lavkasp_test' || ($source['user']??null)!=='lavkasp_sandbox'
        || ($source['prefix']??'oc_')!=='oc_' || !in_array($source['host']??null,['127.0.0.1','localhost'],true)
        || (int)($source['port']??3306)!==3306 || !empty($source['unix_socket'])) throw new SandboxPricingError('sandbox_source_forbidden');
    $shop=$sandbox['shop_root']??'';
    if (!is_string($shop) || !($shopReal=realpath($shop)) || $shopReal!==rtrim($shop,'/') || basename($shopReal)!=='test'
        || $real===$shopReal || str_starts_with($real,$shopReal.'/')) throw new SandboxPricingError('sandbox_shop_invalid');
    $admin=$sandbox['admin_config']??null;
    if (!is_string($admin) || $admin==='' || $admin[0]!=='/') throw new SandboxPricingError('sandbox_shop_unverified');
    try {
        sandbox_validate_layout((string)($sandbox['previous_data_dir']??''),$real,$shopReal);
        sandbox_store_checks($shopReal,$admin);
    } catch (\Throwable $error) { throw new SandboxPricingError('sandbox_shop_unverified'); }
    return ['directory'=>$real,'source'=>$source,'sandbox'=>$sandbox];
}

function sandbox_pricing_status(string $directory): array
{
    $result=['environment'=>'sandbox','database'=>'lavkasp_test','enabled'=>false,'can_write'=>false,'reason'=>'sandbox_not_configured',
        'message'=>'Изменение цен доступно только в отдельной песочнице lavkasp_test.','formula'=>SANDBOX_PRICING_FACTORS,
        'purchase_cost_available'=>false,'atomicity'=>'compare_and_swap_with_compensation'];
    try { sandbox_pricing_configuration($directory); $result['enabled']=true; $result['can_write']=true; $result['reason']=null;
        $result['message']='Песочница lavkasp_test. Можно проверить план и применить цены к тестовому магазину.';
    } catch (SandboxPricingError $error) { $result['reason']=$error->errorCode; }
    catch (\Throwable $ignored) { $result['reason']='sandbox_configuration_invalid'; }
    $result['recent_plans']=[];
    if ($result['enabled']) {
        $path=$directory.'/sandbox-prices.sqlite3';
        if (is_file($path) && !is_link($path)) {
            try {
                $journal=new \SQLite3($path,SQLITE3_OPEN_READONLY); $journal->enableExceptions(true);
                try {
                    $rows=$journal->query('SELECT id,plan_hash,state,created_at,payload FROM sandbox_price_plans ORDER BY created_at DESC,rowid DESC LIMIT 5');
                    while ($row=$rows->fetchArray(SQLITE3_ASSOC)) {
                        $payload=decode($row['payload']);
                        $result['recent_plans'][]=['plan_id'=>$row['id'],'plan_hash'=>$row['plan_hash'],'state'=>$row['state'],'created_at'=>$row['created_at'],
                            'product_count'=>count($payload['rows']??[]),'rollback_available'=>in_array($row['state'],['applied','applying','rolling_back','partial'],true)];
                    }
                } finally { $journal->close(); }
            } catch (\Throwable $ignored) { $result['journal_warning']='sandbox_journal_unavailable'; }
        }
    }
    return $result;
}

function sandbox_pricing_validate_identity(\PDO $source): void
{
    if ($source->query('SELECT DATABASE()')->fetchColumn()!=='lavkasp_test') throw new SandboxPricingError('sandbox_database_forbidden');
    $identity=(string)$source->query('SELECT CURRENT_USER()')->fetchColumn();
    if (explode('@',$identity,2)[0]!=='lavkasp_sandbox') throw new SandboxPricingError('sandbox_user_forbidden');
    $grants=$source->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_NUM);
    if (!$grants) throw new SandboxPricingError('sandbox_grants_unverified');
    try {
        sandbox_source_grants(array_map(function($row){return (string)($row[0]??'');},$grants));
        sandbox_source_tables($source);
    }
    catch (SandboxEnvironmentError $error) { throw new SandboxPricingError($error->errorCode); }
}

/** Narrow PHP 7.3-compatible connector; the existing CLI source connector stays unchanged. */
function sandbox_pricing_connect(array $configuration, ?\PDO $testSource=null): \PDO
{
    if ($testSource) { sandbox_pricing_validate_identity($testSource); return $testSource; }
    $config=$configuration['source'];
    set_error_handler(function(){return true;});
    try {
        $source=new \PDO('mysql:host=127.0.0.1;port=3306;dbname=lavkasp_test;charset=utf8mb4',$config['user'],$config['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_STRINGIFY_FETCHES=>true,\PDO::ATTR_TIMEOUT=>10]);
        sandbox_pricing_validate_identity($source); return $source;
    } catch (SandboxPricingError $error) { throw $error; }
    catch (\Throwable $ignored) { throw new SandboxPricingError('sandbox_connection_failed'); }
    finally { restore_error_handler(); }
}

function sandbox_pricing_journal(string $directory): \PDO
{
    $path=$directory.'/sandbox-prices.sqlite3';
    if (is_link($path)) throw new SandboxPricingError('sandbox_journal_invalid');
    $journal=database($path);
    $journal->exec('CREATE TABLE IF NOT EXISTS sandbox_price_plans(id TEXT PRIMARY KEY,plan_hash TEXT NOT NULL,state TEXT NOT NULL,created_at TEXT NOT NULL,expires_at TEXT NOT NULL,payload TEXT NOT NULL,result TEXT);
        CREATE TABLE IF NOT EXISTS sandbox_price_changes(id INTEGER PRIMARY KEY,plan_id TEXT NOT NULL,product_id INTEGER NOT NULL,table_name TEXT NOT NULL,row_id INTEGER NOT NULL,before_payload TEXT NOT NULL,after_payload TEXT NOT NULL,identity_payload TEXT NOT NULL,state TEXT NOT NULL);');
    $columns=$journal->query('PRAGMA table_info(sandbox_price_changes)')->fetchAll();
    if (!in_array('identity_payload',array_column($columns,'name'),true)) $journal->exec("ALTER TABLE sandbox_price_changes ADD COLUMN identity_payload TEXT NOT NULL DEFAULT '{}'");
    chmod($path,0600); return $journal;
}

function sandbox_pricing_watch(string $directory,array $selections): array
{
    if (!$selections || count($selections)>20) throw new SandboxPricingError('sandbox_selection_limit');
    $available=competitors_own_catalog_watch($directory); $byTarget=[];
    foreach ($available as $product) $byTarget[$product['target_id']]=$product;
    $watch=[]; $bases=[];
    foreach ($selections as $selection) {
        if (!is_array($selection) || array_diff(array_keys($selection),['target_id','base_50']) || !is_int($selection['target_id']??null)
            || !isset($byTarget[$selection['target_id']])) throw new SandboxPricingError('sandbox_target_invalid');
        $product=$byTarget[$selection['target_id']]; $id=$product['product_id'];
        if (isset($watch[$id])) throw new SandboxPricingError('sandbox_duplicate_product');
        $watch[$id]=$product; $bases[$id]=sandbox_pricing_amount($selection['base_50']??null);
    }
    return [$watch,$bases];
}

/** Only this product's option metadata belongs in its review fingerprint. */
function sandbox_pricing_guard(array $ownRows,array $catalogRows,array $changes=[]): string
{
    foreach ($changes as $change) {
        $key=$change['table']==='product'?'product_id':'product_option_value_id';
        foreach ($ownRows[$change['table']] as &$row) {
            if ((int)$row[$key]===$change['row_id']) foreach ($change['after'] as $field=>$value) $row[$field]=$value;
        }
        unset($row);
    }
    foreach (['product','product_option_value','product_special','product_discount'] as $table) {
        foreach ($ownRows[$table] as &$row) if (isset($row['price'])) $row['price']=bcadd((string)$row['price'],'0',4);
        unset($row);
    }
    $optionIds=[]; $valueIds=[];
    foreach ($ownRows['product_option'] as $option) $optionIds[(int)$option['option_id']]=true;
    foreach ($ownRows['product_option_value'] as $value) $valueIds[(int)$value['option_value_id']]=true;
    $guard=['own'=>$ownRows,'setting'=>$catalogRows['setting'],'currency'=>$catalogRows['currency'],'language'=>$catalogRows['language'],
        'option'=>array_values(array_filter($catalogRows['option'],function($row)use($optionIds){return isset($optionIds[(int)$row['option_id']]);})),
        'option_value_description'=>array_values(array_filter($catalogRows['option_value_description'],function($row)use($valueIds){return isset($valueIds[(int)$row['option_value_id']]);}))];
    return hash('sha256',encode($guard));
}

function sandbox_pricing_structure(array $catalogRows,int $id): string
{
    $structure=[]; $optionIds=[]; $valueIds=[];
    foreach (['product','product_description','product_to_store','product_option','product_option_value'] as $table) {
        $structure[$table]=[];
        foreach ($catalogRows[$table]??[] as $row) {
            if ((int)$row['product_id']!==$id) continue;
            if ($table==='product') $row=['product_id'=>(int)$row['product_id'],'model'=>(string)$row['model']];
            elseif ($table==='product_option_value') {
                $valueIds[(int)$row['option_value_id']]=true;
                foreach (['price','price_prefix','quantity','subtract'] as $field) unset($row[$field]);
            } elseif ($table==='product_option') $optionIds[(int)$row['option_id']]=true;
            $structure[$table][]=$row;
        }
    }
    $structure['option']=array_values(array_filter($catalogRows['option']??[],function($row)use($optionIds){return isset($optionIds[(int)$row['option_id']]);}));
    $structure['option_value_description']=array_values(array_filter($catalogRows['option_value_description']??[],function($row)use($valueIds){return isset($valueIds[(int)$row['option_value_id']]);}));
    return hash('sha256',encode($structure));
}

function sandbox_pricing_product_plan(array $catalogRows,array $product,string $base): array
{
    $id=$product['product_id']; $ownRows=[];
    foreach (['product','product_option','product_option_value','product_special','product_discount','product_description','product_to_store'] as $table) {
        $ownRows[$table]=array_values(array_filter($catalogRows[$table]??[],function($row)use($id){return (int)$row['product_id']===$id;}));
    }
    if ($product['warnings'] || !$product['offers']) throw new SandboxPricingError('sandbox_product_unsupported');
    if (count($ownRows['product'])!==1 || count($ownRows['product_option'])!==1) throw new SandboxPricingError('sandbox_options_unsupported');
    foreach ($product['offers'] as $offer) if (($offer['provenance']['base_price_kind']??'')!=='base') throw new SandboxPricingError('sandbox_active_retail_price');
    $decimals=$product['currency_decimal_places']??null;
    if (!is_int($decimals) || !in_array($decimals,[0,2],true)) throw new SandboxPricingError('sandbox_currency_unsupported');
    $prices=sandbox_pricing_formula($base); $basePrice=$prices[50]; $seen=[]; $packs=[]; $changes=[]; $beforeBase=null; $beforeDisplayBase=null;
    foreach ($product['offers'] as $offer) {
        $grams=$offer['grams']; $key=(int)$grams;
        if ($grams===null || (float)$grams!==(float)$key || !isset($prices[$key]) || isset($seen[$key]) || $offer['unit']!=='pack') throw new SandboxPricingError('sandbox_pack_unsupported');
        $seen[$key]=true;
        // The review keeps the stored numeric sum separate from the storefront's
        // currency rounding. A whole-hryvnia display must never replace raw SQL prices.
        $rawBefore=$offer['provenance']['raw_price']??null;
        if (!is_string($rawBefore) || !preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D',$rawBefore)) throw new SandboxPricingError('sandbox_product_unsupported');
        $rawBefore=bcadd($rawBefore,'0',4);
        $displayAfter=competitors_own_catalog_round($prices[$key],$decimals);
        if (bccomp($displayAfter,'0',$decimals)<=0) throw new SandboxPricingError('sandbox_nonpositive_display_price');
        if ($key===50) { $beforeBase=$rawBefore; $beforeDisplayBase=$offer['price']; }
        $valueId=(int)$offer['provenance']['product_option_value_id']; $before=null;
        foreach ($ownRows['product_option_value'] as $value) if ((int)$value['product_option_value_id']===$valueId) $before=$value;
        if (!$before || $valueId<1) throw new SandboxPricingError('sandbox_option_missing');
        $delta=bcsub($prices[$key],$basePrice,4); $prefix=bccomp($delta,'0',4)<0?'-':'+';
        $afterPrice=bccomp($delta,'0',4)<0?bcsub('0',$delta,4):$delta;
        $changes[]=['table'=>'product_option_value','row_id'=>$valueId,'product_id'=>$id,
            'before'=>['price'=>bcadd((string)$before['price'],'0',4),'price_prefix'=>(string)$before['price_prefix']],
            'after'=>['price'=>$afterPrice,'price_prefix'=>$prefix],
            'identity'=>['product_option_id'=>(string)$before['product_option_id'],'option_id'=>(string)$before['option_id'],'option_value_id'=>(string)$before['option_value_id']]];
        $packs[]=['grams'=>$key,'before'=>$rawBefore,'after'=>$prices[$key],
            'display_before'=>$offer['price'],'display_after'=>$displayAfter];
    }
    if (!isset($seen[50]) || count($seen)!==count($ownRows['product_option_value'])) throw new SandboxPricingError('sandbox_pack_unsupported');
    $productBefore=$ownRows['product'][0];
    array_unshift($changes,['table'=>'product','row_id'=>$id,'product_id'=>$id,'before'=>['price'=>bcadd((string)$productBefore['price'],'0',4)],'after'=>['price'=>bcadd($basePrice,'0',4)],'identity'=>['model'=>(string)$productBefore['model']]]);
    usort($packs,function($a,$b){return $a['grams']<=>$b['grams'];});
    return ['target_id'=>$product['target_id'],'product_id'=>$id,'label'=>$product['label'],'base_50'=>$basePrice,'before_base_50'=>$beforeBase,
        'currency_decimal_places'=>$decimals,'display_base_50'=>competitors_own_catalog_round($basePrice,$decimals),'display_before_base_50'=>$beforeDisplayBase,
        'url'=>'https://test.lavka-speciy.com/index.php?route=product%2Fproduct&product_id='.$id,'packs'=>$packs,'changes'=>$changes,
        'guard'=>sandbox_pricing_guard($ownRows,$catalogRows),'after_guard'=>sandbox_pricing_guard($ownRows,$catalogRows,$changes),'structure_guard'=>sandbox_pricing_structure($catalogRows,$id)];
}

function sandbox_pricing_plan(string $directory,array $selections,?\PDO $testSource=null): array
{
    $configuration=sandbox_pricing_configuration($directory); list($watch,$bases)=sandbox_pricing_watch($directory,$selections);
    $source=sandbox_pricing_connect($configuration,$testSource); $catalogRows=competitors_own_catalog_fetch($source,'oc_',$watch);
    $catalog=competitors_own_catalog_build($catalogRows,$watch,utc_now()); $rows=[]; $skipped=[];
    foreach ($catalog['products'] as $product) {
        try { $rows[]=sandbox_pricing_product_plan($catalogRows,$product,$bases[$product['product_id']]); }
        catch (SandboxPricingError $error) { $skipped[]=['target_id'=>$product['target_id'],'product_id'=>$product['product_id'],'reason'=>$error->errorCode]; }
    }
    if (!$rows) return ['state'=>'empty','database'=>'lavkasp_test','rows'=>[],'skipped'=>$skipped];
    $id=bin2hex(random_bytes(16)); $hash=bin2hex(random_bytes(32)); $created=utc_now(); $expires=gmdate('Y-m-d\TH:i:s\Z',time()+1800);
    $payload=['database'=>'lavkasp_test','rows'=>$rows,'skipped'=>$skipped,'selections'=>$selections];
    $journal=sandbox_pricing_journal($directory);
    $journal->beginTransaction();
    try {
        $journal->prepare('INSERT INTO sandbox_price_plans VALUES(?,?,?,?,?,?,NULL)')->execute([$id,$hash,'planned',$created,$expires,encode($payload)]);
        $insert=$journal->prepare('INSERT INTO sandbox_price_changes(plan_id,product_id,table_name,row_id,before_payload,after_payload,identity_payload,state) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($rows as $row) foreach ($row['changes'] as $change) $insert->execute([$id,$row['product_id'],$change['table'],$change['row_id'],encode($change['before']),encode($change['after']),encode($change['identity']),'pending']);
        $journal->commit();
    } catch (\Throwable $error) { if ($journal->inTransaction()) $journal->rollBack(); throw new SandboxPricingError('sandbox_journal_failed'); }
    foreach ($rows as &$row) unset($row['changes'],$row['guard'],$row['after_guard'],$row['structure_guard']); unset($row);
    return ['state'=>'planned','environment'=>'sandbox','database'=>'lavkasp_test','plan_id'=>$id,'plan_hash'=>$hash,'created_at'=>$created,'expires_at'=>$expires,
        'rows'=>$rows,'skipped'=>$skipped,'atomicity'=>'compare_and_swap_with_compensation','purchase_cost_available'=>false];
}

function sandbox_pricing_load_plan(\PDO $journal,string $id,string $hash): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new SandboxPricingError('sandbox_plan_invalid');
    $stmt=$journal->prepare('SELECT * FROM sandbox_price_plans WHERE id=?'); $stmt->execute([$id]); $plan=$stmt->fetch();
    if (!$plan || !hash_equals($plan['plan_hash'],$hash)) throw new SandboxPricingError('sandbox_plan_invalid');
    $plan['payload']=decode($plan['payload']); return $plan;
}

/** Fixed two tables and three writable fields; never interpolate request identifiers. */
function sandbox_pricing_cas(\PDO $source,array $change,bool $reverse=false): bool
{
    $table=$change['table_name']??$change['table']??'';
    if (!in_array($table,['product','product_option_value'],true)) throw new SandboxPricingError('sandbox_change_invalid');
    $before=$change['before']??decode($change['before_payload']); $after=$change['after']??decode($change['after_payload']);
    if ($reverse) { $swap=$before; $before=$after; $after=$swap; }
    $fields=$table==='product'?['price']:['price','price_prefix'];
    if (array_keys($before)!==$fields || array_keys($after)!==$fields) throw new SandboxPricingError('sandbox_change_invalid');
    $identity=sandbox_pricing_change_identity($change,$table);
    $id=(int)$change['row_id']; $product=(int)$change['product_id'];
    $key=$table==='product'?'product_id':'product_option_value_id';
    $set=[]; $where=['`'.$key.'`=?','`product_id`=?']; $args=[];
    foreach ($fields as $field) { $set[]='`'.$field.'`=?'; $args[]=$after[$field]; }
    $args[]=$id; $args[]=$product;
    foreach ($fields as $field) { $where[]='`'.$field.'`=?'; $args[]=$before[$field]; }
    foreach ($identity as $field=>$value) { $where[]='`'.$field.'`=?'; $args[]=$value; }
    if ($before===$after) return sandbox_pricing_row_matches($source,$change,$after);
    $stmt=$source->prepare('UPDATE `oc_'.$table.'` SET '.implode(',',$set).' WHERE '.implode(' AND ',$where));
    $stmt->execute($args); return $stmt->rowCount()===1;
}

function sandbox_pricing_change_identity(array $change,string $table): array
{
    $identity=$change['identity']??decode($change['identity_payload']??'{}');
    if (!is_array($identity) || array_keys($identity)!==($table==='product'?['model']:['product_option_id','option_id','option_value_id'])) throw new SandboxPricingError('sandbox_change_invalid');
    foreach ($identity as $field=>$value) if (!is_string($value) || strlen($value)>1000 || strpos($value,"\x00")!==false) throw new SandboxPricingError('sandbox_change_invalid');
    return $identity;
}

function sandbox_pricing_row_matches(\PDO $source,array $change,array $values): bool
{
    $table=$change['table_name']??$change['table'];
    if (!in_array($table,['product','product_option_value'],true)) throw new SandboxPricingError('sandbox_change_invalid');
    $key=$table==='product'?'product_id':'product_option_value_id'; $fields=array_keys($values);
    if ($fields!==($table==='product'?['price']:['price','price_prefix'])) throw new SandboxPricingError('sandbox_change_invalid');
    $identity=sandbox_pricing_change_identity($change,$table);
    $where=''; $args=[(int)$change['row_id'],(int)$change['product_id']];
    foreach ($identity as $field=>$value) { $where.=' AND `'.$field.'`=?'; $args[]=$value; }
    $stmt=$source->prepare('SELECT '.implode(',',array_map(function($field){return '`'.$field.'`';},$fields)).' FROM `oc_'.$table.'` WHERE `'.$key.'`=? AND `product_id`=?'.$where);
    $stmt->execute($args); $row=$stmt->fetch();
    if (!$row) return false;
    foreach ($values as $field=>$value) {
        if ($field==='price') { if (bccomp((string)$row[$field],(string)$value,4)!==0) return false; }
        elseif ((string)$row[$field]!==$value) return false;
    }
    return true;
}

function sandbox_pricing_refresh_catalog(string $directory,\PDO $source): string
{
    try {
        $watch=competitors_own_catalog_watch($directory);
        $snapshot=competitors_own_catalog_build(competitors_own_catalog_fetch($source,'oc_',$watch),$watch,utc_now());
        foreach ($snapshot['products'] as &$product) $product['url']='https://test.lavka-speciy.com/index.php?route=product%2Fproduct&product_id='.$product['product_id']; unset($product);
        $snapshot['environment']='sandbox'; $snapshot['database']='lavkasp_test'; $snapshot['source_database']='lavkasp_test';
        atomic_write($directory.'/own-catalog.json',encode($snapshot));
        atomic_write($directory.'/own-catalog-status.json',encode(['state'=>'ok','checked_at'=>$snapshot['checked_at'],'last_success'=>$snapshot['last_success'],'error_code'=>null]));
        return 'ok';
    } catch (\Throwable $ignored) {
        atomic_write($directory.'/own-catalog-status.json',encode(['state'=>'error','checked_at'=>'2000-01-01T00:00:00Z','error_code'=>'catalog_refresh_required']));
        return 'pending';
    }
}

function sandbox_pricing_rollback_blocks(\PDO $source,array $rows): array
{
    $watch=[];
    foreach ($rows as $row) $watch[$row['product_id']]=['product_id'=>$row['product_id']];
    try {
        $current=competitors_own_catalog_fetch($source,'oc_',$watch); $blocked=[];
        foreach ($rows as $row) if (!is_string($row['structure_guard']??null) || !hash_equals($row['structure_guard'],sandbox_pricing_structure($current,(int)$row['product_id']))) $blocked[]=(int)$row['product_id'];
        return $blocked;
    } catch (\Throwable $ignored) { return array_map('intval',array_keys($watch)); }
}

function sandbox_pricing_compensate(\PDO $source,\PDO $journal,string $planId,array $blockedProducts=[]): array
{
    $stmt=$journal->prepare("SELECT * FROM sandbox_price_changes WHERE plan_id=? AND state IN ('applying','applied','rollback_conflict') ORDER BY id DESC"); $stmt->execute([$planId]);
    $restored=0; $conflicts=0;
    foreach ($stmt->fetchAll() as $change) {
        $before=decode($change['before_payload']); $after=decode($change['after_payload']);
        try {
            if (in_array((int)$change['product_id'],$blockedProducts,true)) $ok=false;
            elseif (sandbox_pricing_row_matches($source,$change,$before)) $ok=true;
            elseif (sandbox_pricing_row_matches($source,$change,$after)) $ok=sandbox_pricing_cas($source,$change,true);
            else $ok=false;
        } catch (\Throwable $ignored) { $ok=false; }
        $journal->prepare('UPDATE sandbox_price_changes SET state=? WHERE id=?')->execute([$ok?'rolled_back':'rollback_conflict',$change['id']]);
        if ($ok) $restored++; else $conflicts++;
    }
    return ['restored_changes'=>$restored,'conflicting_changes'=>$conflicts];
}

function sandbox_pricing_with_locks(string $directory,callable $operation): array
{
    umask(0077); $locks=[];
    try {
        foreach (['.sandbox-pricing.lock','.own-catalog.lock'] as $name) {
            if (is_link($directory.'/'.$name)) throw new SandboxPricingError('sandbox_lock_invalid');
            $lock=fopen($directory.'/'.$name,'c');
            if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if (is_resource($lock)) fclose($lock); throw new SandboxPricingError('sandbox_busy'); }
            chmod($directory.'/'.$name,0600); $locks[]=$lock;
        }
        return $operation();
    } finally { foreach (array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); } }
}

function sandbox_pricing_apply(string $directory,string $planId,string $planHash,?\PDO $testSource=null): array
{
    $configuration=sandbox_pricing_configuration($directory);
    return sandbox_pricing_with_locks($directory,function()use($directory,$planId,$planHash,$testSource,$configuration):array {
        $source=sandbox_pricing_connect($configuration,$testSource); $journal=sandbox_pricing_journal($directory); $plan=sandbox_pricing_load_plan($journal,$planId,$planHash);
        if ($plan['state']==='applied') return decode($plan['result']);
        if ($plan['state']!=='planned') throw new SandboxPricingError('sandbox_plan_not_pending');
        if (strtotime($plan['expires_at'])<time()) throw new SandboxPricingError('sandbox_plan_expired');
        $payload=$plan['payload']; $selected=[];
        foreach ($payload['rows'] as $row) $selected[]=['target_id'=>$row['target_id'],'base_50'=>$row['base_50']];
        list($watch,$bases)=sandbox_pricing_watch($directory,$selected);
        $catalogRows=competitors_own_catalog_fetch($source,'oc_',$watch); $catalog=competitors_own_catalog_build($catalogRows,$watch,utc_now()); $fresh=[];
        foreach ($catalog['products'] as $product) $fresh[$product['product_id']]=sandbox_pricing_product_plan($catalogRows,$product,$bases[$product['product_id']]);
        foreach ($payload['rows'] as $row) if (!isset($fresh[$row['product_id']]) || !hash_equals($row['guard'],$fresh[$row['product_id']]['guard'])) throw new SandboxPricingError('sandbox_plan_stale');
        $journal->prepare("UPDATE sandbox_price_plans SET state='applying' WHERE id=?")->execute([$planId]);
        $stmt=$journal->prepare('SELECT * FROM sandbox_price_changes WHERE plan_id=? ORDER BY id'); $stmt->execute([$planId]); $changes=$stmt->fetchAll();
        $failed=false; $changed=0; $deadline=microtime(true)+30;
        foreach ($changes as $change) {
            if (microtime(true)>$deadline) { $failed=true; break; }
            $before=decode($change['before_payload']); $after=decode($change['after_payload']);
            if ($before===$after) { $journal->prepare("UPDATE sandbox_price_changes SET state='unchanged' WHERE id=?")->execute([$change['id']]); continue; }
            $journal->prepare("UPDATE sandbox_price_changes SET state='applying' WHERE id=?")->execute([$change['id']]);
            try { $ok=sandbox_pricing_cas($source,$change); } catch (\Throwable $ignored) { $ok=false; }
            if (!$ok) { $failed=true; break; }
            $journal->prepare("UPDATE sandbox_price_changes SET state='applied' WHERE id=?")->execute([$change['id']]); $changed++;
        }
        // Legacy tables may be MyISAM: review again after the CAS writes to
        // detect a concurrent structural/stock/promotion edit, then compensate.
        if (!$failed) {
            try {
                $afterRows=competitors_own_catalog_fetch($source,'oc_',$watch);
                $afterCatalog=competitors_own_catalog_build($afterRows,$watch,utc_now()); $afterGuards=[];
                foreach ($afterCatalog['products'] as $product) {
                    $check=sandbox_pricing_product_plan($afterRows,$product,$bases[$product['product_id']]);
                    $afterGuards[$product['product_id']]=$check['guard'];
                }
                foreach ($payload['rows'] as $row) if (!isset($afterGuards[$row['product_id']]) || !hash_equals($row['after_guard'],$afterGuards[$row['product_id']])) $failed=true;
            } catch (\Throwable $ignored) { $failed=true; }
        }
        $result=['state'=>'applied','database'=>'lavkasp_test','plan_id'=>$planId,'applied_products'=>count($payload['rows']),'failed_products'=>0,
            'changed_records'=>$changed,'atomicity'=>'compare_and_swap_with_compensation','rollback_available'=>true];
        if ($failed) {
            $compensation=sandbox_pricing_compensate($source,$journal,$planId,sandbox_pricing_rollback_blocks($source,$payload['rows']));
            $result=array_merge($result,$compensation,['state'=>$compensation['conflicting_changes']?'partial':'rolled_back','applied_products'=>0,'failed_products'=>count($payload['rows']),'reason'=>'sandbox_write_conflict']);
        }
        $result['rollback_available']=in_array($result['state'],['applied','partial'],true);
        $result['rows']=[];
        foreach ($payload['rows'] as $row) $result['rows'][]=['product_id'=>$row['product_id'],'state'=>$result['state']];
        $result['own_catalog_refresh']=sandbox_pricing_refresh_catalog($directory,$source);
        $journal->prepare('UPDATE sandbox_price_plans SET state=?,result=? WHERE id=?')->execute([$result['state'],encode($result),$planId]);
        return $result;
    });
}

function sandbox_pricing_rollback(string $directory,string $planId,string $planHash,?\PDO $testSource=null): array
{
    $configuration=sandbox_pricing_configuration($directory);
    return sandbox_pricing_with_locks($directory,function()use($directory,$planId,$planHash,$testSource,$configuration):array {
        $source=sandbox_pricing_connect($configuration,$testSource); $journal=sandbox_pricing_journal($directory); $plan=sandbox_pricing_load_plan($journal,$planId,$planHash);
        if ($plan['state']==='rolled_back') return decode($plan['result']);
        if (!in_array($plan['state'],['applied','applying','partial','rolling_back'],true)) throw new SandboxPricingError('sandbox_rollback_unavailable');
        $journal->prepare("UPDATE sandbox_price_plans SET state='rolling_back' WHERE id=?")->execute([$planId]);
        $result=array_merge(['state'=>'rolled_back','database'=>'lavkasp_test','plan_id'=>$planId,'atomicity'=>'compare_and_swap_with_compensation'],sandbox_pricing_compensate($source,$journal,$planId,sandbox_pricing_rollback_blocks($source,$plan['payload']['rows'])));
        if ($result['conflicting_changes']) $result['state']='partial';
        $result['rows']=[];
        foreach ($plan['payload']['rows'] as $row) $result['rows'][]=['product_id'=>$row['product_id'],'state'=>$result['state']];
        $result['rollback_available']=$result['state']==='partial'; $result['own_catalog_refresh']=sandbox_pricing_refresh_catalog($directory,$source);
        $journal->prepare('UPDATE sandbox_price_plans SET state=?,result=? WHERE id=?')->execute([$result['state'],encode($result),$planId]);
        return $result;
    });
}
