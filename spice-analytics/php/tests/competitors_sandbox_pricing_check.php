<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/CompetitorSandboxPricing.php';

function sp_expect(bool $value,string $message):void { if (!$value) throw new RuntimeException($message); }
function sp_error(string $code,callable $operation):void {
    try { $operation(); }
    catch (\Lavka\SandboxPricingError $error) { sp_expect($error->errorCode===$code,'Expected '.$code.', got '.$error->errorCode); return; }
    throw new RuntimeException('Expected '.$code);
}
function sp_remove(string $directory):void {
    foreach (scandir($directory)?:[] as $name) if ($name!=='.' && $name!=='..') {
        $path=$directory.'/'.$name;
        if (is_dir($path) && !is_link($path)) sp_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function sp_rows(array $ids=[990]):array {
    $rows=['setting'=>[
        ['store_id'=>'0','key'=>'config_currency','value'=>'UAH','serialized'=>'0'],
        ['store_id'=>'0','key'=>'config_language','value'=>'uk-ua','serialized'=>'0'],
        ['store_id'=>'0','key'=>'config_customer_group_id','value'=>'1','serialized'=>'0'],
        ['store_id'=>'0','key'=>'config_tax','value'=>'0','serialized'=>'0']],
        'currency'=>[['code'=>'UAH','value'=>'1.00000000','decimal_place'=>'2','status'=>'1']],
        'language'=>[['language_id'=>'3','code'=>'uk-ua','status'=>'1']],
        'product'=>[],'product_description'=>[],'product_to_store'=>[],'product_option'=>[],'option'=>[],
        'product_option_value'=>[],'option_value_description'=>[],'product_discount'=>[],'product_special'=>[]];
    foreach ($ids as $offset=>$id) {
        $group=100+$offset;
        $rows['product'][]=['product_id'=>$id,'model'=>'SYNTHETIC-'.$id,'price'=>'0.0000','quantity'=>'12','subtract'=>'1','status'=>'1','date_available'=>'2020-01-01','tax_class_id'=>'0','minimum'=>'1'];
        $rows['product_description'][]=['product_id'=>$id,'language_id'=>'3','name'=>'Синтетична пряність '.$id];
        $rows['product_to_store'][]=['product_id'=>$id,'store_id'=>'0'];
        $rows['product_option'][]=['product_option_id'=>$group,'product_id'=>$id,'option_id'=>$group,'required'=>'1'];
        $rows['option'][]=['option_id'=>$group,'type'=>'select'];
        foreach ([20=>'10',50=>'20',200=>'72',500=>'150',1000=>'270'] as $grams=>$price) {
            $value=$id*10+count($rows['product_option_value'])+1;
            $rows['product_option_value'][]=['product_option_value_id'=>$value,'product_option_id'=>$group,'product_id'=>$id,'option_id'=>$group,'option_value_id'=>$value,'quantity'=>'10','subtract'=>'1','price'=>$price,'price_prefix'=>'+'];
            $rows['option_value_description'][]=['option_value_id'=>$value,'language_id'=>'3','name'=>$grams.' г'];
        }
    }
    return $rows;
}

/** Real SQLite SELECT/UPDATE; only MySQL identity and snapshot syntax are adapted. */
class SandboxSqliteSource extends PDO {
    public $databaseName='lavkasp_test';
    public $userName='lavkasp_sandbox';
    public $writableTableType='BASE TABLE';
    public $writableEngine='InnoDB';
    public $triggerCount=0;
    public $externalFkCount=0;
    public $auditUser='lavkasp_test@localhost';
    public $auditAvailable=true;
    public $grants=['GRANT USAGE ON *.* TO `lavkasp_sandbox`@`localhost` IDENTIFIED BY PASSWORD \'PRIVATE_HASH_SENTINEL\'',
        'GRANT SELECT ON `lavkasp_test`.* TO `lavkasp_sandbox`@`localhost`',
        'GRANT UPDATE (`price`) ON `lavkasp_test`.`oc_product` TO `lavkasp_sandbox`@`localhost`',
        'GRANT UPDATE (`price`, `price_prefix`) ON `lavkasp_test`.`oc_product_option_value` TO `lavkasp_sandbox`@`localhost`'];
    public $updates=0;
    public $failAfterUpdate=0;
    public $afterUpdate;
    public function __construct(array $rows) {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $this->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
        $this->sqliteCreateFunction('NOW',function(){return date('Y-m-d H:i:s');});
        foreach (\Lavka\COMPETITOR_CATALOG_FIELDS as $table=>$fields) {
            $physical='oc_'.($table==='setting'?'analytics_catalog_settings':$table);
            parent::exec('CREATE TABLE `'.$physical.'` ('.implode(',',array_map(function($field){return '`'.$field.'` '.($field==='price'?'NUMERIC':'TEXT');},$fields)).')');
            foreach ($rows[$table]??[] as $row) {
                $values=array_map(function($field)use($row){return $this->quote((string)($row[$field]??''));},$fields);
                parent::exec('INSERT INTO `'.$physical.'` VALUES('.implode(',',$values).')');
            }
        }
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SandboxSqliteStatement::class,[$this]]);
    }
    #[\ReturnTypeWillChange]
    public function query($query,?int $fetchMode=null,...$args) {
        if ($query==='SELECT DATABASE()') return parent::query('SELECT '.$this->quote($this->databaseName));
        if ($query==='SELECT CURRENT_USER()') return parent::query('SELECT '.$this->quote($this->userName.'@localhost'));
        if ($query==='SHOW GRANTS') return parent::query(implode(' UNION ALL ',array_map(function($grant){return 'SELECT '.$this->quote($grant);},$this->grants)));
        if (stripos($query,'information_schema.TABLES')!==false) return parent::query("SELECT 'oc_product' AS TABLE_NAME,".$this->quote($this->writableTableType).' AS TABLE_TYPE,'.$this->quote($this->writableEngine)." AS ENGINE UNION ALL SELECT 'oc_product_option_value',".$this->quote($this->writableTableType).','.$this->quote($this->writableEngine));
        if (strpos($query,'FROM oc_analytics_sandbox_guard')!==false) {
            if (!$this->auditAvailable) throw new PDOException('PRIVATE_GUARD_ERROR');
            $columns=$this->quote($this->writableTableType).' AS table_type,'.$this->quote($this->writableEngine).' AS engine,'.(int)$this->triggerCount.' AS trigger_count,'.(int)$this->externalFkCount.' AS external_fk_count,'.$this->quote($this->auditUser).' AS audit_user';
            return parent::query("SELECT 'oc_product' AS table_name,".$columns." UNION ALL SELECT 'oc_product_option_value',".$this->quote($this->writableTableType).','.$this->quote($this->writableEngine).','.(int)$this->triggerCount.','.(int)$this->externalFkCount.','.$this->quote($this->auditUser));
        }
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);
    }
    #[\ReturnTypeWillChange]
    public function exec($statement) {
        if (in_array($statement,['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','ROLLBACK'],true)) return 0;
        throw new RuntimeException('Unexpected non-CAS statement');
    }
    public function mutate(string $statement):void { parent::exec($statement); }
    public function digest():string {
        $rows=[];
        foreach (['oc_product','oc_product_option_value'] as $table) $rows[$table]=parent::query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
        return hash('sha256',json_encode($rows));
    }
}
class SandboxSqliteStatement extends PDOStatement {
    private $source;
    protected function __construct(SandboxSqliteSource $source) { $this->source=$source; }
    #[\ReturnTypeWillChange]
    public function execute($parameters=null) {
        $write=strpos($this->queryString,'UPDATE `oc_')===0;
        if ($write && !preg_match('/^UPDATE `oc_(?:product|product_option_value)` SET `price`=\?(?:,`price_prefix`=\?)? WHERE /',$this->queryString)) throw new RuntimeException('Unexpected writable fields');
        $ok=$parameters===null?parent::execute():parent::execute($parameters);
        if ($write) {
            ++$this->source->updates;
            if ($this->source->afterUpdate) ($this->source->afterUpdate)($this->source);
            if ($this->source->updates===$this->source->failAfterUpdate) throw new PDOException('PRIVATE_DRIVER_SENTINEL');
        }
        return $ok;
    }
}

function sp_fixture(string $directory,array $ids=[990]):array {
    \Lavka\private_dir($directory); \Lavka\private_dir($directory.'/analytics-private');
    $data=$directory.'/analytics-private-test'; $shop=$directory.'/test';
    \Lavka\private_dir($data); \Lavka\private_dir($shop.'/admin'); \Lavka\private_dir($shop.'/storage');
    $config="<?php\ndefine('DB_DATABASE','lavkasp_test');\ndefine('DB_PREFIX','oc_');\ndefine('DB_HOSTNAME','127.0.0.1');\ndefine('DB_PORT','3306');\ndefine('HTTP_SERVER','https://test.lavka-speciy.com/');\ndefine('HTTPS_SERVER','https://test.lavka-speciy.com/');\n";
    $config.='define(\'DIR_STORAGE\','.var_export($shop.'/storage/',true).");\n";
    file_put_contents($shop.'/config.php',$config);
    file_put_contents($shop.'/admin/config.php',$config."define('HTTP_CATALOG','https://test.lavka-speciy.com/');\ndefine('HTTPS_CATALOG','https://test.lavka-speciy.com/');\n");
    \Lavka\atomic_write($data.'/sandbox.json',\Lavka\encode(['environment'=>'sandbox','database'=>'lavkasp_test','allowed_shop_database'=>'lavkasp_test','source_user'=>'lavkasp_sandbox',
        'writes_enabled'=>true,'test_store_verified'=>true,'data_dir'=>$data,'previous_data_dir'=>$directory.'/analytics-private','shop_root'=>$shop,'admin_config'=>$shop.'/admin/config.php','public_url'=>'https://test.lavka-speciy.com/analytics']));
    \Lavka\atomic_write($data.'/source.json',\Lavka\encode(['host'=>'127.0.0.1','port'=>3306,'database'=>'lavkasp_test','user'=>'lavkasp_sandbox','password'=>'PRIVATE_PASSWORD_SENTINEL','prefix'=>'oc_']));
    $db=\Lavka\competitors_db($data); $db->exec('UPDATE competitor_targets SET enabled=0');
    $targets=[];
    foreach ($ids as $id) {
        $url=\Lavka\competitors_validate_url('https://lavka-speciy.com/index.php?route=product/product&product_id='.$id,'lavka');
        $stmt=$db->prepare('SELECT id FROM competitor_targets WHERE source_id=\'lavka\' AND kind=\'product\' AND url=?'); $stmt->execute([$url]);
        $target=$stmt->fetchColumn();
        if (!$target) $target=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>$url,'label'=>'Synthetic spice'])['id'];
        else \Lavka\competitors_save_target($db,['enabled'=>true],(int)$target);
        $targets[$id]=(int)$target;
    }
    return [$data,$shop,$targets];
}

$root=sys_get_temp_dir().'/lavka-sandbox-pricing-'.bin2hex(random_bytes(6));
\Lavka\private_dir($root);
try {
    $formula=\Lavka\sandbox_pricing_formula('120');
    sp_expect($formula===[20=>'60.00',50=>'120.00',200=>'432.00',500=>'900.00',1000=>'1620.00'],'Five-pack chain uses 13.5B for 1 kg');
    sp_expect(\Lavka\sandbox_pricing_formula('19')[500]==='142.50','Fractional hryvnia retained');
    foreach (['0','-1','0.001','10.005',12,'1e3'] as $invalid) sp_error('invalid_base_price',function()use($invalid){\Lavka\sandbox_pricing_amount($invalid);});
    list($data,$shop,$targets)=sp_fixture($root.'/main',[990,823]);
    $source=new SandboxSqliteSource(sp_rows([990,823])); $before=$source->digest();
    $status=\Lavka\sandbox_pricing_status($data);
    sp_expect($status['enabled'] && $status['can_write'] && !$status['purchase_cost_available'],'Only verified sandbox can enable price planning');
    sp_expect(!is_file($data.'/sandbox-prices.sqlite3'),'Status never creates a journal or connects to MySQL');
    $selections=[['target_id'=>$targets[990],'base_50'=>'25'],['target_id'=>$targets[823],'base_50'=>'30']];
    $plan=\Lavka\sandbox_pricing_plan($data,$selections,$source);
    sp_expect($plan['state']==='planned' && count($plan['rows'])===2 && $before===$source->digest() && !$source->updates,'Planning executes real SELECT but never changes shop prices');
    sp_expect($plan['rows'][0]['packs'][0]['after']==='12.50' && $plan['rows'][0]['packs'][4]['after']==='337.50','Every pack derives from explicit 50 g base');
    sp_expect(!isset($plan['rows'][0]['guard']) && !isset($plan['rows'][0]['changes']),'Internal fingerprints and SQL field lists stay private');
    $result=\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$source);
    sp_expect($result['state']==='applied' && $result['applied_products']===2 && $result['own_catalog_refresh']==='ok','Multi-product plan applies and refreshes saved own catalogue');
    $current=\Lavka\competitors_own_catalog_read($data);
    sp_expect($current['source_database']==='lavkasp_test' && $current['environment']==='sandbox','Refreshed catalogue is explicitly sandbox sourced');
    sp_expect(strpos($current['products'][0]['url'],'https://test.lavka-speciy.com/')===0,'Own price source opens the test store');
    $updates=$source->updates;
    sp_expect(\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$source)===$result && $source->updates===$updates,'Repeated apply is idempotent');
    $recent=\Lavka\sandbox_pricing_status($data)['recent_plans'];
    sp_expect($recent[0]['plan_id']===$plan['plan_id'] && $recent[0]['rollback_available'],'Interrupted/reloaded UI can recover the rollback plan');
    $rollback=\Lavka\sandbox_pricing_rollback($data,$plan['plan_id'],$plan['plan_hash'],$source);
    sp_expect($rollback['state']==='rolled_back' && !$rollback['conflicting_changes'] && $source->digest()===$before,'Rollback restores exact original price and option prefixes');
    sp_expect(\Lavka\sandbox_pricing_rollback($data,$plan['plan_id'],$plan['plan_hash'],$source)===$rollback,'Repeated rollback is idempotent');

    $plan=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'21']],$source);
    $source->mutate("UPDATE oc_product SET price=1 WHERE product_id='990'"); $updates=$source->updates;
    sp_error('sandbox_plan_stale',function()use($data,$plan,$source){\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$source);});
    sp_expect($source->updates===$updates,'Concurrent edits invalidate reviewed plan before writes');
    $source->mutate("UPDATE oc_product SET price=0 WHERE product_id='990'");
    $journal=\Lavka\sandbox_pricing_journal($data); $journal->prepare("UPDATE sandbox_price_plans SET expires_at='2000-01-01T00:00:00Z' WHERE id=?")->execute([$plan['plan_id']]);
    sp_error('sandbox_plan_expired',function()use($data,$plan,$source){\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$source);});
    sp_error('sandbox_plan_invalid',function()use($data,$plan,$source){\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],str_repeat('a',64),$source);});
    $lock=fopen($data.'/.own-catalog.lock','c'); flock($lock,LOCK_EX|LOCK_NB);
    sp_error('sandbox_busy',function()use($data,$plan,$source){\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$source);});
    flock($lock,LOCK_UN); fclose($lock);

    $failure=new SandboxSqliteSource(sp_rows()); $failureBefore=$failure->digest();
    $plan=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'22']],$failure);
    $failure->failAfterUpdate=2;
    $result=\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$failure);
    sp_expect($result['state']==='rolled_back' && $failure->digest()===$failureBefore,'A driver failure after a MyISAM-style write is journalled and compensated');
    sp_expect(strpos(\Lavka\encode($result),'PRIVATE_DRIVER_SENTINEL')===false,'Raw driver errors are not returned');

    $conflict=new SandboxSqliteSource(sp_rows());
    $plan=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'23']],$conflict);
    $result=\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$conflict);
    sp_expect($result['state']==='applied','Conflict fixture applied');
    $conflict->mutate("UPDATE oc_product SET price=99 WHERE product_id='990'");
    $rollback=\Lavka\sandbox_pricing_rollback($data,$plan['plan_id'],$plan['plan_hash'],$conflict);
    sp_expect($rollback['state']==='partial' && $rollback['conflicting_changes']===1,'Rollback reports partial state after an external edit');
    sp_expect((string)$conflict->query("SELECT price FROM oc_product WHERE product_id='990'")->fetchColumn()==='99','Rollback never overwrites an intervening external price');

    $skippedRows=sp_rows([990,823]);
    $skippedRows['product_special']=[['product_id'=>823,'customer_group_id'=>1,'priority'=>1,'price'=>'5','date_start'=>'0000-00-00','date_end'=>'0000-00-00']];
    $skippedSource=new SandboxSqliteSource($skippedRows);
    $plan=\Lavka\sandbox_pricing_plan($data,$selections,$skippedSource);
    sp_expect(count($plan['rows'])===1 && count($plan['skipped'])===1,'Unsupported product is skipped independently');
    $applied=\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$skippedSource);
    sp_expect($applied['state']==='applied','Metadata belonging to a skipped product cannot invalidate another reviewed product');

    $structural=new SandboxSqliteSource(sp_rows());
    $plan=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'24']],$structural);
    $structural->afterUpdate=function($source){if($source->updates===1)$source->mutate('UPDATE oc_product_option SET required=0');};
    $compensated=\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$structural);
    sp_expect($compensated['state']==='partial' && $compensated['rollback_available'] && $compensated['conflicting_changes']>0,'Concurrent option structure edits block compensation for the changed product');
    sp_expect((string)$structural->query('SELECT required FROM oc_product_option')->fetchColumn()==='0','Compensation preserves an intervening option structure change');

    foreach (['reassigned_value'=>"UPDATE oc_product_option_value SET option_value_id='999999' WHERE product_option_value_id=(SELECT MIN(product_option_value_id) FROM oc_product_option_value)",
        'renamed_pack'=>"UPDATE oc_option_value_description SET name='25 г' WHERE option_value_id=(SELECT MIN(option_value_id) FROM oc_option_value_description)"] as $case=>$mutation) {
        $rebound=new SandboxSqliteSource(sp_rows());
        $plan=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'26']],$rebound);
        $applied=\Lavka\sandbox_pricing_apply($data,$plan['plan_id'],$plan['plan_hash'],$rebound);
        sp_expect($applied['state']==='applied','Structure rollback fixture applies: '.$case);
        $rebound->mutate($mutation); $changedDigest=$rebound->digest(); $updates=$rebound->updates;
        $rollback=\Lavka\sandbox_pricing_rollback($data,$plan['plan_id'],$plan['plan_hash'],$rebound);
        sp_expect($rollback['state']==='partial' && $rollback['rollback_available'] && $rollback['conflicting_changes']>0,'Rollback reports changed pack identity: '.$case);
        sp_expect($rebound->updates===$updates && $rebound->digest()===$changedDigest,'Rollback leaves all prices and reassigned pack metadata untouched: '.$case);
        if ($case==='renamed_pack') sp_expect((string)$rebound->query('SELECT name FROM oc_option_value_description ORDER BY option_value_id LIMIT 1')->fetchColumn()==='25 г','Rollback preserves the edited pack label');
    }

    $interrupted=new SandboxSqliteSource(sp_rows()); $interruptedBefore=$interrupted->digest();
    $plan=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$interrupted);
    $journal=\Lavka\sandbox_pricing_journal($data);
    $journal->prepare("UPDATE sandbox_price_plans SET state='rolling_back' WHERE id=?")->execute([$plan['plan_id']]);
    $stmt=$journal->prepare('SELECT * FROM sandbox_price_changes WHERE plan_id=? ORDER BY id LIMIT 1'); $stmt->execute([$plan['plan_id']]); $change=$stmt->fetch(); $stmt->closeCursor();
    $journal->prepare("UPDATE sandbox_price_changes SET state='applying' WHERE id=?")->execute([$change['id']]);
    sp_expect(\Lavka\sandbox_pricing_cas($interrupted,$change),'Fixture emulates a process stopping after an SQL write');
    $recovered=\Lavka\sandbox_pricing_rollback($data,$plan['plan_id'],$plan['plan_hash'],$interrupted);
    sp_expect($recovered['state']==='rolled_back' && $interrupted->digest()===$interruptedBefore,'An interrupted rollback resumes from the durable per-record journal');

    foreach (['lavkasp_lavka','other_database'] as $database) {
        $forbidden=new SandboxSqliteSource(sp_rows()); $forbidden->databaseName=$database;
        sp_error('sandbox_database_forbidden',function()use($data,$targets,$forbidden){\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$forbidden);});
        sp_expect(!$forbidden->updates,'Production database identity blocks every write');
    }
    $forbidden=new SandboxSqliteSource(sp_rows()); $forbidden->grants[]='GRANT SELECT ON `lavkasp_lavka`.* TO `lavkasp_sandbox`@`localhost`';
    sp_error('sandbox_other_database_grant',function()use($data,$targets,$forbidden){\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$forbidden);});
    $forbidden->grants=['GRANT ALL PRIVILEGES ON `lavkasp_test`.* TO `lavkasp_sandbox`@`localhost`'];
    sp_error('sandbox_privileges_too_broad',function()use($data,$targets,$forbidden){\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$forbidden);});
    $forbidden=new SandboxSqliteSource(sp_rows()); $forbidden->userName='lavkasp_lavka';
    sp_error('sandbox_user_forbidden',function()use($data,$targets,$forbidden){\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$forbidden);});

    foreach ([
        ['writableTableType','VIEW','sandbox_price_tables_not_isolated'],
        ['writableEngine','FEDERATED','sandbox_price_tables_not_isolated'],
        ['triggerCount',1,'sandbox_schema_not_isolated'],
        ['externalFkCount',1,'sandbox_schema_not_isolated'],
        ['auditUser','lavkasp_sandbox@localhost','sandbox_schema_audit_required'],
        ['auditAvailable',false,'sandbox_schema_audit_required']
    ] as $case) {
        list($property,$value,$errorCode)=$case;
        $forbidden=new SandboxSqliteSource(sp_rows()); $forbidden->$property=$value; $beforeForbidden=$forbidden->digest();
        sp_error($errorCode,function()use($data,$targets,$forbidden){\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$forbidden);});
        sp_expect(!$forbidden->updates && $forbidden->digest()===$beforeForbidden,'Untrusted writable-table metadata blocks every SQL write: '.$property);
    }

    $original=file_get_contents($shop.'/admin/config.php');
    file_put_contents($shop.'/admin/config.php',str_replace("'lavkasp_test'","'lavkasp_lavka'",$original));
    sp_expect(!\Lavka\sandbox_pricing_status($data)['enabled'],'Admin config pointing at live database blocks sandbox');
    file_put_contents($shop.'/admin/config.php',$original);
    $original=file_get_contents($shop.'/config.php');
    file_put_contents($shop.'/config.php',"<?php // define('DB_DATABASE','lavkasp_test');\ndefine('DB_DATABASE','lavkasp_lavka');\n");
    sp_expect(!\Lavka\sandbox_pricing_status($data)['enabled'],'Commented test DB does not hide active production config');
    file_put_contents($shop.'/config.php',$original);

    $unsupported=sp_rows(); $unsupported['option_value_description'][0]['name']='1 шт.';
    $unsupportedSource=new SandboxSqliteSource($unsupported);
    $empty=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],$unsupportedSource);
    sp_expect($empty['state']==='empty' && $empty['skipped'][0]['reason']==='sandbox_pack_unsupported' && !$unsupportedSource->updates,'Mixed piece/gram variants never receive a partial price chain');
    $unsupported=sp_rows(); $unsupported['product_special']=[['product_id'=>990,'customer_group_id'=>1,'priority'=>1,'price'=>'5','date_start'=>'0000-00-00','date_end'=>'0000-00-00']];
    $empty=\Lavka\sandbox_pricing_plan($data,[['target_id'=>$targets[990],'base_50'=>'25']],new SandboxSqliteSource($unsupported));
    sp_expect($empty['state']==='empty' && $empty['skipped'][0]['reason']==='sandbox_active_retail_price','Active storefront promotion cannot silently override a proposed retail base');
    $json=\Lavka\encode(\Lavka\sandbox_pricing_status($data));
    foreach (['PRIVATE_PASSWORD','PRIVATE_HASH','PRIVATE_DRIVER','source.json'] as $secret) sp_expect(strpos($json,$secret)===false,'Status hides credentials and driver data');
    sp_expect((fileperms($data.'/sandbox-prices.sqlite3')&0777)===0600,'Private journal permissions');
    echo \Lavka\encode(['sandbox_formula_and_options'=>'passed','read_review_apply_rollback'=>'passed','cas_compensation_conflicts'=>'passed','production_and_credentials_isolation'=>'passed','real_network_requests'=>0,'php'=>PHP_VERSION])."\n";
} finally { sp_remove($root); }
