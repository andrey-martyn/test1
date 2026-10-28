<?php
// In-memory adapter exercises the real service without shop credentials or network.
define('DB_PREFIX','oc_');
require __DIR__.'/../../store/system/library/lavka_product_tags.php';
class TagFixtureRegistry {
    public $data=[];
    public function get($key) { return isset($this->data[$key])?$this->data[$key]:null; }
    public function has($key) { return isset($this->data[$key]); }
    public function set($key,$value) { $this->data[$key]=$value; }
}
class TagFixtureConfig {
    public $values=['config_store_id'=>0,'config_language'=>'ru-ru','config_complete_status'=>[5],'config_review_status'=>1];
    public function get($key) { return isset($this->values[$key])?$this->values[$key]:null; }
}
class TagFixtureCache {
    public $values=[]; public $deleted=[];
    public function get($key) { return isset($this->values[$key])?$this->values[$key]:false; }
    public function set($key,$value) { $this->values[$key]=$value; }
    public function delete($key) { $this->deleted[]=$key; }
}
class TagFixtureDb {
    public $native=['sticker_new'=>1,'sticker_new_date'=>30,'mpn_as_sticker'=>1,'upc_as_sticker'=>1,'ean_as_sticker'=>1,'jan_as_sticker'=>1,'isbn_as_sticker'=>1,
        'sticker_upc_background_color'=>'bc6464','sticker_upc_text_color'=>'e0bcbc',
        'sticker_ean_background_color'=>'230606','sticker_ean_text_color'=>'ceb7b7',
        'sticker_jan_background_color'=>'000fff','sticker_jan_text_color'=>'000000',
        'sticker_isbn_background_color'=>'fff100','sticker_isbn_text_color'=>'000000',
        'sticker_mpn_background_color'=>'fff100','sticker_mpn_text_color'=>'000000'];
    public $definitions=[]; public $flags=[]; public $queries=[]; public $products=[]; public $salesRows=[['product_id'=>816,'total'=>11]]; public $affected=0; private $snapshot;
    public $reviewRows=[['product_id'=>816,'total'=>12,'rating'=>4.8],['product_id'=>999,'total'=>7,'rating'=>5]];
    public function __construct() {
        $this->products[816]=['product_id'=>816,'date_available'=>date('Y-m-d',time()-86400),'date_modified'=>'2001-01-01','mpn'=>'Популярно','upc'=>'1111','ean'=>'2222','jan'=>'3333','isbn'=>'4444','price'=>'45.0000','quantity'=>767];
    }
    public function escape($value) { return addslashes($value); }
    public function countAffected() { return $this->affected; }
    private function sets($sql) {
        preg_match_all("/`?(label_ru|label_uk|background|foreground|code)`?='((?:\\\\.|[^'])*)'|`?(enabled|sort_order|days|min_sales|revision|product_id|store_id)`?=(\d+)/u",$sql,$matches,PREG_SET_ORDER);
        $out=[];
        foreach ($matches as $m) { if ($m[1]!=='') $out[$m[1]]=stripslashes($m[2]); else $out[$m[3]]=(int)$m[4]; }
        return $out;
    }
    public function query($sql) {
        $this->queries[]=$sql; $this->affected=0; $rows=[];
        if (strpos($sql,'CREATE TABLE')===0) {}
        elseif ($sql==='START TRANSACTION') $this->snapshot=[$this->definitions,$this->flags];
        elseif ($sql==='ROLLBACK') { list($this->definitions,$this->flags)=$this->snapshot; }
        elseif ($sql==='COMMIT') {}
        elseif (strpos($sql,'SELECT `data`')===0) $rows=[['data'=>json_encode($this->native)]];
        elseif (strpos($sql,'SELECT * FROM `oc_lavka_tag_definition`')===0) $rows=array_values($this->definitions);
        elseif (strpos($sql,'SELECT `product_id`,`code`')===0) $rows=array_values($this->flags);
        elseif (strpos($sql,'SELECT op.product_id')===0) $rows=$this->salesRows;
        elseif (strpos($sql,'SELECT r.product_id')===0) $rows=$this->reviewRows;
        elseif (strpos($sql,'SELECT p.*')===0) $rows=[$this->products[816]];
        elseif (strpos($sql,'SELECT * FROM `oc_product`')===0) {
            preg_match('/product_id=(\d+)/',$sql,$m); if (isset($this->products[(int)$m[1]])) $rows=[$this->products[(int)$m[1]]];
        } elseif (strpos($sql,'INSERT IGNORE INTO `oc_lavka_tag_definition`')===0) {
            $row=$this->sets($sql); if (!isset($this->definitions[$row['code']])) { $this->definitions[$row['code']]=$row; $this->affected=1; }
        } elseif (strpos($sql,'UPDATE `oc_lavka_tag_definition`')===0) {
            list($set,$where)=explode(' WHERE ',$sql); $row=$this->sets($set); $condition=$this->sets($where); $code=$condition['code'];
            if ($this->definitions[$code]['revision']===$condition['revision']) { $row['revision']=$condition['revision']+1; $this->definitions[$code]=array_merge($this->definitions[$code],$row); $this->affected=1; }
        } elseif (strpos($sql,'INSERT IGNORE INTO `oc_lavka_product_tag`')===0) {
            $row=$this->sets($sql); $key=$row['product_id'].':'.$row['code'];
            if (!isset($this->flags[$key])) { $this->flags[$key]=$row; $this->affected=1; }
        } elseif (strpos($sql,'UPDATE `oc_lavka_product_tag`')===0) {
            list($set,$where)=explode(' WHERE ',$sql); $row=$this->sets($set); $condition=$this->sets($where); $key=$condition['product_id'].':'.$condition['code'];
            if (isset($this->flags[$key]) && $this->flags[$key]['revision']===$condition['revision']) { $this->flags[$key]['enabled']=$row['enabled']; $this->flags[$key]['revision']++; $this->affected=1; }
        } else throw new RuntimeException('Unsupported fixture SQL: '.$sql);
        return (object)['rows'=>$rows,'row'=>$rows?$rows[0]:[],'num_rows'=>count($rows)];
    }
}
function fixture() {
    $registry=new TagFixtureRegistry(); $db=new TagFixtureDb();
    $registry->data=['db'=>$db,'config'=>new TagFixtureConfig(),'cache'=>new TagFixtureCache(),'session'=>(object)['data'=>['language'=>'uk-ua']]];
    $engine=new LavkaProductTags($registry); $engine->initialize();
    return [$engine,$db,$registry];
}
function verify($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function rejects($call,$message) { try { $call(); } catch (Exception $e) { return $e->getMessage(); } throw new RuntimeException('Expected rejection: '.$message); }

if (defined('TAG_FIXTURE_BOOTSTRAP')) return;
$scenario=$argv[1];
list($engine,$db,$registry)=fixture();
$product=$db->products[816];
if ($scenario==='compose') {
    foreach (['fresh','brand','exclusive'] as $code) $engine->writeFlag(816,$code,1,0);
    $tags=$engine->stickers($product,[['name'=>'special','text'=>'Акция &amp; скидка','value'=>'5','text_after'=>'%','length'=>10]]);
    verify(array_column($tags,'name')===['lavka-new','lavka-hit','lavka-fresh','lavka-brand','lavka-exclusive','special','upc','ean','jan','isbn'],'All assigned labels and native code stickers must be retained');
    verify($tags[1]['text']==='Топ продажів','Session language must select UA label');
    verify($tags[6]['background']==='#bc6464' && $tags[6]['color']==='#e0bcbc','Saved native colors must be exact');
    verify($tags[5]['text']==='Акция & скидка','Native entities must be decoded once before output escaping');
    $registry->get('session')->data['language']='ru-ru';
    verify($engine->stickers($product)[1]['text']==='Топ продаж','RU label must switch with language');
    rejects(function()use($engine){$engine->writeFlag(816,'hit',0,0);},'Top is automatic');
    verify($db->products[816]===$product,'No product field may be changed');
    $count=count($db->queries); $engine->stickers($product); $engine->stickers($product);
    verify(count($db->queries)===$count,'Repeated products must share one batched flag/settings lookup');
} elseif ($scenario==='dates') {
    $now=strtotime('2026-10-28 12:00:00');
    $product['date_available']='2026-10-27'; $product['date_modified']='2030-01-01';
    verify($engine->state($product,$now)['new']['enabled'],'Available product inside period is new');
    $product['date_available']='2026-01-01';
    verify(!$engine->state($product,$now)['new']['enabled'],'Editing product must not restart new period');
    $product['date_available']='2026-11-01'; verify(!$engine->state($product,$now)['new']['enabled'],'Future product must not be new yet');
    $product['date_available']='invalid'; verify(!$engine->state($product,$now)['new']['enabled'],'Missing/invalid date must be inactive');
} elseif ($scenario==='conflicts') {
    $engine->writeFlag(816,'fresh',0,0); $engine->writeFlag(816,'brand',1,0);
    verify(rejects(function()use($engine){$engine->writeFlag(816,'fresh',1,0);},'duplicate insert')==='TAG_CONFLICT','Concurrent initial choice must not overwrite');
    $state=$engine->writeFlag(816,'fresh',1,1);
    verify($state['brand']['enabled'] && $state['fresh']['revision']===2,'Unrelated flag must survive partial write');
    verify(rejects(function()use($engine){$engine->writeFlag(816,'fresh',0,1);},'stale revision')==='TAG_CONFLICT','Stale update must conflict');
    rejects(function()use($engine){$engine->writeFlag(816,'new',1,0);},'automatic type');
    rejects(function()use($engine){$engine->writeFlag(999,'fresh',1,0);},'unknown product');
    rejects(function()use($engine){$engine->writeFlag(816,'fresh',2,0);},'invalid bool');
    verify($db->products[816]===$product,'Price, dates, codes, stock must remain byte-equivalent');
} elseif ($scenario==='settings') {
    $before=$engine->definitions(); $rows=$before; $rows['fresh']['background']='#abc'; $rows['fresh']['label_ru']='Мелем свежим';
    $after=$engine->saveDefinitions($rows); verify($after['fresh']['background']==='#aabbcc','HEX shorthand normalization');
    $rows=$after; $rows['new']['label_ru']='Changed first'; $rows['exclusive']['revision']=1;
    verify(rejects(function()use($engine,$rows){$engine->saveDefinitions($rows);},'stale settings')==='TAG_CONFLICT','Settings conflict');
    verify($db->definitions===$after,'All five settings changes must roll back together');
    foreach (['background'=>'red;display:none','label_ru'=>'<script>','days'=>'4000'] as $field=>$bad) {
        $rows=$after; $rows['new'][$field]=$bad; $count=count($db->queries);
        rejects(function()use($engine,$rows){$engine->saveDefinitions($rows);},'invalid settings');
        verify(count($db->queries)===$count,'Invalid settings must fail before any transaction');
    }
    $db->definitions['new']['label_ru']='Preserve';
    $engine->initialize(); verify($db->definitions['new']['label_ru']==='Preserve','Repeat init must not reset existing settings');
} elseif ($scenario==='bestseller') {
    $db->salesRows=[['product_id'=>816,'total'=>10]];
    $db->flags['816:hit']=['product_id'=>816,'code'=>'hit','enabled'=>1,'revision'=>1];
    verify(!$engine->state($product)['hit']['enabled'],'10 sold must not qualify, even with old MPN and manual Hit');
    $db->salesRows=[['product_id'=>816,'total'=>11]];
    $registry->get('cache')->values['lavka.tags.sales.0']['expires']=0;
    $engine=new LavkaProductTags($registry);
    verify($engine->state($product)['hit']['enabled'],'11 sold must qualify without any top-N ranking');
    $db->flags['816:hit']['enabled']=0;
    verify($engine->state($product)['hit']['enabled'],'Old manual false must not suppress automatic Top');
    $rows=$engine->definitions(); $rows['hit']['min_sales']=12; $engine->saveDefinitions($rows);
    verify(!$engine->state($product)['hit']['enabled'],'Configured threshold applies to cached sales immediately');
    $before=count($db->queries);$again=new LavkaProductTags($registry);$again->state($product);
    verify(!array_filter(array_slice($db->queries,$before),function($q){return strpos($q,'SELECT op.product_id')===0;}),'Fresh cache avoids duplicate aggregate query');
    $registry->get('config')->values['config_complete_status']=[];
    $engine=new LavkaProductTags($registry); $before=count($db->queries);$state=$engine->state($product);
    verify(!$state['hit']['enabled'] && !$state['hit']['configured'],'No completed statuses means no automatic Top');
    verify(!array_filter(array_slice($db->queries,$before),function($q){return strpos($q,'SELECT op.product_id')===0;}),'No broad fallback to all order statuses');
    rejects(function()use($engine){$engine->writeFlag(816,'hit',1,0);},'automatic Top cannot be manually assigned');
    $rows=$engine->definitions();$rows['hit']['min_sales']=0;
    rejects(function()use($engine,$rows){$engine->saveDefinitions($rows);},'zero threshold');
} elseif ($scenario==='sql') {
    $engine->writeFlag(816,'brand',0,0); $engine->writeFlag(816,'fresh',1,0); $engine->writeFlag(816,'brand',1,1);
    $rows=$engine->definitions(); $rows['brand']['background']='#123456'; $engine->saveDefinitions($rows);
}
echo json_encode(['ok'=>true,'scenario'=>$scenario,'queries'=>$db->queries]);
