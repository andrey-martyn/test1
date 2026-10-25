<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__.'/common.php';
require_once __DIR__.'/CompetitorIdentity.php';
require_once __DIR__.'/CompetitorParser.php';

/** The search index is separate from price observations and confirmations. */
function competitors_discovery_schema(\PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS competitor_scan_pages(
        id INTEGER PRIMARY KEY,source_id TEXT NOT NULL,url TEXT NOT NULL,kind TEXT NOT NULL,depth INTEGER NOT NULL DEFAULT 0,
        next_check_at TEXT NOT NULL,checked_at TEXT,state TEXT NOT NULL DEFAULT 'pending',error_code TEXT,errors INTEGER NOT NULL DEFAULT 0,
        UNIQUE(source_id,url));
        CREATE INDEX IF NOT EXISTS competitor_scan_due ON competitor_scan_pages(next_check_at,id);
        CREATE TABLE IF NOT EXISTS competitor_search_index(id INTEGER PRIMARY KEY,source_id TEXT NOT NULL,url TEXT NOT NULL,title TEXT NOT NULL,
        title_source TEXT NOT NULL,seen_at TEXT NOT NULL,UNIQUE(source_id,url));
        CREATE INDEX IF NOT EXISTS competitor_search_source ON competitor_search_index(source_id,id);
        CREATE INDEX IF NOT EXISTS competitor_target_group ON competitor_targets(source_id,kind,enabled,match_group);
        CREATE TABLE IF NOT EXISTS competitor_suggestions(own_id INTEGER NOT NULL,candidate_id INTEGER NOT NULL,score INTEGER NOT NULL,
        PRIMARY KEY(own_id,candidate_id));
        CREATE TABLE IF NOT EXISTS competitor_discovery_settings(id INTEGER PRIMARY KEY CHECK(id=1),enabled INTEGER NOT NULL DEFAULT 0);");
    $db->exec('CREATE TABLE IF NOT EXISTS competitor_page_evidence(source_id TEXT NOT NULL,url TEXT NOT NULL,captured_at TEXT NOT NULL,error_code TEXT,payload TEXT NOT NULL,PRIMARY KEY(source_id,url))');
    $columns=array_column($db->query('PRAGMA table_info(competitor_search_index)')->fetchAll(),'name');
    if(!in_array('details',$columns,true))$db->exec('ALTER TABLE competitor_search_index ADD COLUMN details TEXT');
    $db->exec('CREATE TABLE IF NOT EXISTS competitor_price_lists(source_id TEXT NOT NULL,url TEXT NOT NULL,label TEXT NOT NULL,found_at TEXT NOT NULL,PRIMARY KEY(source_id,url))');
    $targetColumns=array_column($db->query('PRAGMA table_info(competitor_targets)')->fetchAll(),'name');
    if(!in_array('match_method',$targetColumns,true)){
        $db->exec("ALTER TABLE competitor_targets ADD COLUMN match_method TEXT NOT NULL DEFAULT 'unreviewed'");
        // The old false flag meant pending, including pages merely queued by
        // the user. An edited timestamp is not evidence of a human rejection.
        $db->exec("UPDATE competitor_targets SET match_method='manual' WHERE match_confirmed=1");
    }
    if(!in_array('match_evidence',$targetColumns,true))$db->exec('ALTER TABLE competitor_targets ADD COLUMN match_evidence TEXT');
}

function competitors_scan_enqueue(\PDO $db,string $source,string $url,string $kind,int $depth=0): bool
{
    if ($source==='lavka' || !in_array($kind,['sitemap','catalog','product'],true) || $depth>6) return false;
    try {$url=competitors_validate_url($url,$source);}catch(\InvalidArgumentException $ignored){return false;}
    $count=$db->prepare('SELECT count(*) FROM competitor_scan_pages WHERE source_id=?');$count->execute([$source]);
    if ((int)$count->fetchColumn()>=4000)return false;
    $stmt=$db->prepare('INSERT OR IGNORE INTO competitor_scan_pages(source_id,url,kind,depth,next_check_at) VALUES(?,?,?,?,?)');
    $stmt->execute([$source,$url,$kind,$depth,utc_now()]);return $stmt->rowCount()>0;
}

function competitors_discovery_start(\PDO $db): array
{
    $db->exec('INSERT OR REPLACE INTO competitor_discovery_settings VALUES(1,1)');
    foreach(competitors_sources() as $source) if($source['id']!=='lavka') {
        competitors_scan_enqueue($db,$source['id'],$source['base_url'].'/sitemap.xml','sitemap');
        competitors_scan_enqueue($db,$source['id'],$source['base_url'].'/','catalog');
    }
    foreach($db->query("SELECT source_id,url FROM competitor_targets WHERE enabled=1 AND kind='catalog' AND source_id<>'lavka'") as $target)
        competitors_scan_enqueue($db,$target['source_id'],$target['url'],'catalog');
    $db->exec("UPDATE competitor_scan_pages SET next_check_at='".utc_now()."' WHERE state='error'");
    return ['ok'=>true,'message'=>'Поиск поставлен в очередь фонового обработчика'];
}

/** Current public catalogue only: fixed SELECTs, no order/contact fields or DDL. */
function competitors_catalog_expand(string $directory,string $configFile,?callable $reader=null): array
{
    set_error_handler(function(){return true;});
    try{return competitors_catalog_expand_inner($directory,$configFile,$reader);}finally{restore_error_handler();}
}
function competitors_catalog_expand_inner(string $directory,string $configFile,?callable $reader=null): array
{
    if (PHP_VERSION_ID<80200)throw new SyncError('catalog_requires_php82_cli');
    require_once __DIR__.'/Competitors.php';require_once __DIR__.'/Sync.php';require_once __DIR__.'/SandboxRuntime.php';
    if(is_link($directory.'/competitor-full-catalog.json'))throw new SyncError('catalog_source_config');
    $config=source_config($configFile);sandbox_assert_source($directory,$config,$configFile);
    if($reader)$rows=$reader($config);
    else {
        $source=source_connect($config);$prefix=$config['prefix']??'oc_';
        if(!preg_match('/^[A-Za-z0-9_]{0,32}$/D',$prefix))throw new SyncError('catalog_source_config');
        $query='SELECT p.product_id,d.name,d.language_id FROM `'.$prefix.'product` p JOIN `'.$prefix.'product_to_store` s ON s.product_id=p.product_id AND s.store_id=0 JOIN `'.$prefix.'product_description` d ON d.product_id=p.product_id WHERE p.status=1 ORDER BY p.product_id,d.language_id LIMIT 15001';
        $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {$first=$source->query($query)->fetchAll(\PDO::FETCH_ASSOC);$rows=$source->query($query)->fetchAll(\PDO::FETCH_ASSOC);
            if(count($rows)>15000 || !hash_equals(hash('sha256',encode($first)),hash('sha256',encode($rows))))throw new SyncError('catalog_source_changed');
        }finally{$source->exec('ROLLBACK');}
        $source=null;
    }
    $products=[];
    foreach($rows as $row){$id=(string)($row['product_id']??'');if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new SyncError('catalog_read_failed');
        if(!isset($products[$id]))$products[$id]=mb_substr(trim((string)$row['name']),0,160);
    }
    if(count($products)>4000)throw new SyncError('catalog_product_limit');
    $db=competitors_db($directory);$existing=[];
    foreach($db->query("SELECT id,url FROM competitor_targets WHERE source_id='lavka' AND kind='product'") as $row){$query=[];parse_str(parse_url($row['url'],PHP_URL_QUERY)??'',$query);if(isset($query['product_id']))$existing[(string)$query['product_id']]=true;}
    $added=0;$now=utc_now();$host=file_exists($directory.'/sandbox.json')?'https://test.lavka-speciy.com':'https://lavka-speciy.com';
    $db->beginTransaction();
    try {
        $total=(int)$db->query('SELECT count(*) FROM competitor_targets WHERE enabled=1')->fetchColumn();
        $new=array_diff_key($products,$existing);if($total+count($new)>COMPETITOR_MAX_PRODUCTS)throw new SyncError('catalog_product_limit');
        $stmt=$db->prepare("INSERT INTO competitor_targets(source_id,kind,url,label,pack_label,match_group,match_confirmed,created_at,updated_at,next_check_at) VALUES('lavka','product',?,?,? ,?,1,?,?,?)");
        foreach($new as $id=>$label){$url=competitors_validate_url($host.'/index.php?route=product/product&product_id='.$id,'lavka',true);$stmt->execute([$url,$label?:'Товар '.$id,'50 г, 500 г','lavka-'.$id,$now,$now,$now]);$added++;}
        $db->commit();
    }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    return ['products'=>count($products),'added'=>$added,'shop_writes'=>false];
}

function competitors_index_link(\PDO $db,string $source,string $url,string $title,string $titleSource): bool
{
    try {$url=competitors_validate_url($url,$source);}catch(\InvalidArgumentException $ignored){return false;}
    if($source==='lavka')return false;
    $title=mb_substr(trim(strip_tags(html_entity_decode($title,ENT_QUOTES,'UTF-8'))),0,300);if($title==='')return false;
    $stmt=$db->prepare('SELECT count(*) FROM competitor_search_index WHERE source_id=?');$stmt->execute([$source]);if((int)$stmt->fetchColumn()>=20000)return false;
    $stmt=$db->prepare('INSERT OR IGNORE INTO competitor_search_index(source_id,url,title,title_source,seen_at) VALUES(?,?,?,?,?)');$stmt->execute([$source,$url,$title,$titleSource,utc_now()]);
    // A real product title supersedes a name inferred from a URL, never vice versa.
    if($titleSource==='catalog')$db->prepare("UPDATE competitor_search_index SET title=?,title_source='catalog',seen_at=? WHERE source_id=? AND url=? AND title_source<>'page'")->execute([$title,utc_now(),$source,$url]);
    if($titleSource==='page'){$db->prepare("UPDATE competitor_search_index SET title=?,title_source='page',seen_at=? WHERE source_id=? AND url=?")->execute([$title,utc_now(),$source,$url]);}
    return true;
}

function competitors_scan_parse(\PDO $db,array $task,string $body,string $url): array
{
    $db->beginTransaction();try{$result=competitors_scan_parse_inner($db,$task,$body,$url);$db->commit();return $result;}catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function competitors_scan_parse_inner(\PDO $db,array $task,string $body,string $url): array
{
    require_once __DIR__.'/CompetitorParser.php';
    if($task['kind']==='sitemap' && substr($body,0,2)==="\x1f\x8b"){$body=@gzdecode($body,2097152);if($body===false)throw new CompetitorParseError('sitemap_invalid');}
    if(strlen($body)>2097152)throw new CompetitorParseError('response_too_large');
    $found=0;$pages=0;
    if($task['kind']==='product'){
        list($doc,$xp)=competitor_dom($body);$scope=competitor_main_node($xp);
        $title=competitor_first_text($xp,['.//h1'],$scope);
        if($title==='')throw new CompetitorParseError('product_title_unavailable');
        $observation=null;try{$observation=competitors_parse(['source_id'=>$task['source_id'],'kind'=>'product','grams_override'=>null],$body,$url);}catch(CompetitorParseError $ignored){}
        competitors_index_link($db,$task['source_id'],$url,$title,'page');
        $db->prepare('UPDATE competitor_search_index SET details=? WHERE source_id=? AND url=?')->execute([encode(['read_at'=>utc_now(),'observation'=>$observation]),$task['source_id'],$url]);
        return ['links'=>1,'pages'=>0];
    }
    if($task['kind']==='sitemap'){
        if(stripos($body,'<!DOCTYPE')!==false || stripos($body,'<!ENTITY')!==false)throw new CompetitorParseError('sitemap_invalid');
        $previous=libxml_use_internal_errors(true);$dom=new \DOMDocument();
        try{if(!$dom->loadXML($body,LIBXML_NONET))throw new CompetitorParseError('sitemap_invalid');}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $root=$dom->documentElement->localName;
        if(!in_array($root,['sitemapindex','urlset'],true))throw new CompetitorParseError('sitemap_invalid');
        $xp=new \DOMXPath($dom);$nodes=$xp->query('/*/*[local-name()="sitemap" or local-name()="url"]/*[local-name()="loc"]');
        foreach($nodes as $i=>$node){if($i>=10000)break;$link=trim($node->textContent);
            if($root==='sitemapindex'){$pages+=(int)competitors_scan_enqueue($db,$task['source_id'],$link,'sitemap',(int)$task['depth']+1);continue;}
            $path=parse_url($link,PHP_URL_PATH)?:'';
            if(preg_match('~\.(?:jpg|png|webp|pdf|xml|gz)$~i',$path)||preg_match('~/(?:blog|news|articles|about|faq|reviews|otzyvy|contact)(?:/|$)~i',$path))continue;
            $title=rawurldecode(pathinfo(rtrim($path,'/'),PATHINFO_FILENAME));$title=str_replace(['-','_'],' ',$title);$titleSource='url';
            if($node->parentNode instanceof \DOMElement){$titles=$node->parentNode->getElementsByTagNameNS('*','title');if($titles->length){$title=$titles->item(0)->textContent;$titleSource='sitemap';}}
            $found+=(int)competitors_index_link($db,$task['source_id'],$link,$title,$titleSource);
        }
    }else {
        $observation=['links'=>[]];try{$observation=competitors_parse(['source_id'=>$task['source_id'],'kind'=>'catalog','grams_override'=>null],$body,$url);}catch(CompetitorParseError $ignored){}
        foreach($observation['links'] as $link){$found+=(int)competitors_index_link($db,$task['source_id'],$link['url'],$link['title'],'catalog');
            if(!competitors_identity_jar($link['title'],$link['url']))competitors_scan_enqueue($db,$task['source_id'],$link['url'],'product',(int)$task['depth']+1);
        }
        $previous=libxml_use_internal_errors(true);$dom=new \DOMDocument();
        try{list($dom,$xp)=competitor_dom($body);
            foreach($xp->query('//a[@href] | //link[@rel="next" and @href]') as $i=>$node){if($i>5000)break;
                $href=competitor_absolute_url($node->getAttribute('href'),$url);if(!$href)continue;
                $text=mb_strtolower(trim($node->textContent));$path=parse_url($href,PHP_URL_PATH)?:'';
                $params=[];parse_str(parse_url($href,PHP_URL_QUERY)??'',$params);
                $category=($params['route']??'')==='product/category'||preg_match('~/(?:catalog|category|katalog|spetsii|spetsiyi|pryanosti|spices)(?:/|$)~iu',$path);
                $menu=false;for($parent=$node;$parent instanceof \DOMElement;$parent=$parent->parentNode){if(strtolower($parent->tagName)==='nav'||preg_match('/(?:^|[ _-])(?:menu|navbar|navigation|category-list|sidebar)(?:$|[ _-])/i',$parent->getAttribute('class'))){$menu=true;break;}}
                if(preg_match('/(?:прайс|прай[сc]|price[ -]?list|прайслист)/iu',$text)&&preg_match('~\.(?:csv|xlsx?|xml|pdf)$~i',$path)){
                    try{$safe=competitors_validate_url($href,$task['source_id']);$db->prepare('INSERT OR REPLACE INTO competitor_price_lists VALUES(?,?,?,?)')->execute([$task['source_id'],$safe,mb_substr($node->textContent,0,160),utc_now()]);}catch(\InvalidArgumentException $ignored){}continue;
                }
                if($menu&&!preg_match('~\.(?:html?|jpg|png|pdf|zip|xlsx?)$~i',$path))$category=true;
                $pagination=$node->getAttribute('rel')==='next'||preg_match('~(?:[?&](?:page|PAGEN_1)=\d{1,3}(?:&|$)|/page/\d{1,3}/?$)~',$href);
                if(in_array($href,array_column($observation['links'],'url'),true))continue;
                if(($category&&(!preg_match('~\.(?:html?|jpg|png|pdf)$~i',$path)||$menu)&&!preg_match('~/(?:reviews|otzyvy|blog)/~i',$path))||$pagination)
                    $pages+=(int)competitors_scan_enqueue($db,$task['source_id'],$href,'catalog',(int)$task['depth']+1);
            }
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    return ['links'=>$found,'pages'=>$pages];
}

/** Name similarity only proposes links; it never establishes product identity. */
function competitors_name_tokens(string $name): array
{
    $s=mb_strtolower(html_entity_decode(competitors_identity_name($name),ENT_QUOTES,'UTF-8'));
    $s=preg_replace('/\d+(?:[.,]\d+)?\s*(?:кг|kg|гр|г|g|мл|ml)\b/iu',' ',$s);
    $s=strtr($s,['ё'=>'е','і'=>'и','ї'=>'и','є'=>'е','ґ'=>'г']);
    $s=strtr($s,['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sh','ы'=>'i','э'=>'e','ю'=>'yu','я'=>'ya','ь'=>'','ъ'=>'']);
    $s=strtr($s,['koritsya'=>'koritsa','korica'=>'koritsa','tseilons'=>'ceylons','tsejlons'=>'ceylons','chorn'=>'chern','tsil'=>'tsel','chasnik'=>'chesnok','kmin'=>'tmin','kurkum'=>'kurkum','pryanosti'=>'','spetsii'=>'','spetsiyi'=>'','spices'=>'']);
    preg_match_all('/[a-z]{3,}/',$s,$matches);$tokens=[];
    foreach($matches[0] as $token){if(in_array($token,['kupit','kupiti','kupyty','kupiti','kupit','tsena','cena','price','product','catalog','content','html','gramm','gram','premium','quality','paket','upakovka','natural','online','shop','ru','ua','doypack','doy','pack','zip','kraft','rossip','rossyp','rozsip','fasovka','fasovki','ves','weight','vag','rozvazhniy','nasipom'],true))continue;
        foreach(['turmeric'=>'kurkum','clove'=>'clove','nutmeg'=>'muskat','ginger'=>'imbir','caraway'=>'tmin','cumin'=>'zira','kumin'=>'zira','rosemary'=>'rozmarin','basil'=>'bazilik','sesame'=>'kunzhut','vanilla'=>'vanil','garlic'=>'chesnok','dill'=>'ukrop','krip'=>'ukrop','fennel'=>'fenhel','cocoa'=>'kakao','cacao'=>'kakao','coconut'=>'kokos','kokos'=>'kokos','sugar'=>'sahar','semya'=>'seed','semen'=>'seed','simin'=>'seed','nasin'=>'seed','seed'=>'seed','zelen'=>'leaf','list'=>'leaf','leaf'=>'leaf','sushen'=>'dried','sushon'=>'dried','sush'=>'dried','drobl'=>'crushed','podrib'=>'crushed','crush'=>'crushed','granul'=>'granulated','hlop'=>'flakes','plastiv'=>'flakes','molot'=>'ground','melen'=>'ground','ground'=>'ground','tsel'=>'whole','whole'=>'whole','ceylon'=>'ceylon','kassi'=>'cassia','cassia'=>'cassia','kurkum'=>'kurkum','gvozdik'=>'clove','koriandr'=>'coriander','coriander'=>'coriander','cinnamon'=>'koritsa','paprik'=>'paprika','kardamon'=>'cardamom','cardamom'=>'cardamom','chern'=>'black','black'=>'black','bel'=>'white','bil'=>'white','white'=>'white','krasn'=>'red','red'=>'red','kopchen'=>'smoked','kopchen'=>'smoked','smoked'=>'smoked'] as $prefix=>$canonical)if(strpos($token,$prefix)===0){$token=$canonical;break;}
        $token=preg_replace('/(?:skaya|skii|skiy|skyi|skaya|naya|nii|nyi|aya|oyu|ogo|aya|yaya|yi|iy|ya)$/','',$token);
        if(strlen($token)>=3)$tokens[$token]=true;
    }
    return array_keys($tokens);
}

function competitors_name_score(array $a,array $b): int
{
    foreach([['ground','whole','crushed','granulated','flakes'],['seed','leaf'],['ceylon','cassia'],['black','white','red']] as $group){$x=array_values(array_intersect($a,$group));$y=array_values(array_intersect($b,$group));if($x&&$y&&!array_intersect($x,$y))return 0;}
    $weak=['ground','whole','crushed','granulated','flakes','seed','leaf','dried','black','white','red','smoked'];$core=array_intersect(array_diff($a,$weak),array_diff($b,$weak));if(!$core)return 0;
    $common=count(array_intersect($a,$b));return (int)round(100*(.6*$common/max(1,count($a))+.4*$common/max(1,count($b))));
}

function competitors_discovery_match(\PDO $db,bool $autoWatch=false): array
{
    $index=$db->query('SELECT id,source_id,url,title,title_source,seen_at FROM competitor_search_index ORDER BY id LIMIT 80000')->fetchAll();$byToken=[];$tokens=[];$profiles=[];
    foreach($index as $i=>$row){$profiles[$i]=competitors_identity_profile($row['title'],$row['url']);$tokens[$i]=$profiles[$i]['tokens'];foreach($tokens[$i] as $token)$byToken[$token][]=$i;}
    $own=$db->query("SELECT * FROM competitor_targets WHERE enabled=1 AND kind='product' AND source_id='lavka' AND match_group<>'' ORDER BY id")->fetchAll();
    $suggestions=[];$auto=[];$used=[];
    // Excluded packaging remains in the audit history, outside active comparisons.
    if($autoWatch)foreach($db->query("SELECT * FROM competitor_targets WHERE enabled=1 AND kind='product' AND source_id<>'lavka'") as $old){
        $observation=$old['observation']?decode($old['observation']):null;$offers=$observation['offers']??[];
        $jar=competitors_identity_jar($observation['title']??$old['label'],$old['url'])||($offers&&!array_filter($offers,function($o){return ($o['packaging']??'unknown')!=='jar';}));
        if($jar)$db->prepare('UPDATE competitor_targets SET enabled=0,match_confirmed=0,updated_at=? WHERE id=?')->execute([utc_now(),$old['id']]);
    }
    foreach($own as $product){$ownProfile=competitors_identity_profile($product['label']);$a=$ownProfile['tokens'];$pool=[];foreach(array_diff($a,['ground','whole','crushed','granulated','flakes','seed','leaf','dried','black','white','red','smoked']) as $token)foreach($byToken[$token]??[] as $i)$pool[$i]=true;$rank=[];
        foreach(array_keys($pool) as $i){$identity=competitors_identity_compare($product['label'],$index[$i]['title'],$index[$i]['url'],$ownProfile,$profiles[$i]);$score=$identity['score'];if($score<45)continue;$source=$index[$i]['source_id'];$rank[$source][]=['row'=>$index[$i],'score'=>$score,'exact'=>$identity['exact']];}
        foreach($rank as $source=>$items){usort($items,function($x,$y){return $y['score']<=>$x['score']?:(int)(bool)competitor_weight($x['row']['title'])<=>(int)(bool)competitor_weight($y['row']['title'])?:$x['row']['id']<=>$y['row']['id'];});
            foreach(array_slice($items,0,3) as $item){$suggestions[]=[$product['id'],$item['row']['id'],$item['score']];if($autoWatch)competitors_scan_enqueue($db,$source,$item['row']['url'],'product',1);}
            if($items[0]['score']>=60 && (!isset($items[1])||$items[0]['score']-$items[1]['score']>=10||($items[0]['exact']&&$items[1]['exact']))){
                $best=$items[0];$key=$best['row']['id'];$auto[$key][]=['own'=>$product,'candidate'=>$best['row'],'score'=>$best['score']];
            }
        }
    }
    $db->beginTransaction();try{$db->exec('DELETE FROM competitor_suggestions');$insert=$db->prepare('INSERT INTO competitor_suggestions VALUES(?,?,?)');foreach($suggestions as $row)$insert->execute($row);$db->commit();}catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $added=0;
    if($autoWatch)foreach($auto as $items){usort($items,function($a,$b){return $b['score']<=>$a['score'];});if(isset($items[1])&&$items[0]['score']-$items[1]['score']<10)continue;$item=$items[0];
        $exists=$db->prepare("SELECT 1 FROM competitor_targets WHERE source_id=? AND kind='product' AND url=?");$exists->execute([$item['candidate']['source_id'],$item['candidate']['url']]);if($exists->fetchColumn())continue;
        if($added>=40)break;
        try{$saved=competitors_save_target($db,['source_id'=>$item['candidate']['source_id'],'kind'=>'product','url'=>$item['candidate']['url'],'label'=>$item['candidate']['title'],'match_group'=>$item['own']['match_group'],'match_confirmed'=>false]);$added++;
            $lookup=$db->prepare('SELECT details FROM competitor_search_index WHERE id=?');$lookup->execute([$item['candidate']['id']]);$cached=$lookup->fetchColumn();$details=$cached?decode($cached):null;
            if(!empty($details['observation'])&&strtotime($details['read_at']??'')>=time()-172800)competitors_record_attempt($db,(int)$saved['id'],$details['observation'],null,$details['read_at'],200);
        }catch(CompetitorConflict $ignored){}
    }
    return ['suggestions'=>count($suggestions),'queued_candidates'=>$added];
}

function competitors_discovery_review(\PDO $db,array $body): array
{
    $db->beginTransaction();try{$db->exec('UPDATE competitor_discovery_settings SET enabled=enabled WHERE id=1');$result=competitors_discovery_review_locked($db,$body);$db->commit();return $result;}catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function competitors_discovery_review_locked(\PDO $db,array $body): array
{
    if(array_diff(array_keys($body),['pairs','confirm'])||!is_array($body['pairs']??null)||!array_is_list($body['pairs'])||count($body['pairs'])<1||count($body['pairs'])>100||!is_bool($body['confirm']??null))throw new \InvalidArgumentException('Выберите от 1 до 100 сопоставлений');
    $prepared=[];$urls=[];
    foreach($body['pairs'] as $pair){if(!is_array($pair)||count($pair)!==2||!array_is_list($pair)||!is_int($pair[0])||!is_int($pair[1]))throw new \InvalidArgumentException('Неверное сопоставление');
        $stmt=$db->prepare("SELECT o.id AS own_id,o.match_group,i.* FROM competitor_suggestions s JOIN competitor_targets o ON o.id=s.own_id JOIN competitor_search_index i ON i.id=s.candidate_id WHERE s.own_id=? AND s.candidate_id=? AND o.enabled=1 AND o.source_id='lavka'");$stmt->execute($pair);$row=$stmt->fetch();if(!$row)throw new CompetitorConflict('Список изменился. Обновите найденные товары');
        if(isset($urls[$row['url']])&&$urls[$row['url']]!==$row['match_group'])throw new CompetitorConflict('Одна ссылка выбрана для разных наших товаров');$urls[$row['url']]=$row['match_group'];
        $stmt=$db->prepare("SELECT * FROM competitor_targets WHERE source_id=? AND kind='product' AND url=?");$stmt->execute([$row['source_id'],$row['url']]);$old=$stmt->fetch();
        if($old && (!$old['enabled']||$old['match_group']!==$row['match_group']))throw new CompetitorConflict('Ссылка уже настроена для другого товара или отключена. Проверьте её отдельно');
        // Confirmation is explicit, after an actual successfully read title.
        if($body['confirm']&&(!$old||!$old['last_success']||!$old['last_attempt']||decode($old['last_attempt'])['state']!=='ok'))throw new CompetitorConflict('Сначала дождитесь успешной загрузки выбранных карточек');
        $prepared[]=[$row,$old];
    }
    // Validate all rows before changing any. Each save still enforces capacity.
    $active=(int)$db->query('SELECT count(*) FROM competitor_targets WHERE enabled=1')->fetchColumn();
    $new=count(array_filter($prepared,function($row){return !$row[1];}));if($active+$new>COMPETITOR_MAX_PRODUCTS)throw new CompetitorConflict('Лимит страниц наблюдения');
    $count=0;
    foreach($prepared as list($row,$old)){
        $fields=['source_id'=>$row['source_id'],'kind'=>'product','url'=>$row['url'],'label'=>$old?$old['label']:$row['title'],'match_group'=>$row['match_group'],'match_confirmed'=>$body['confirm']||($old&&(bool)$old['match_confirmed'])];
        if(!$body['confirm'])unset($fields['match_confirmed']);
        $saved=competitors_save_target($db,$fields,$old?(int)$old['id']:null);if(!$body['confirm'])competitors_queue($db,(int)$saved['id']);$count++;
    }
    return ['ok'=>true,'processed'=>$count,'confirmed'=>$body['confirm'],'shop_writes'=>false];
}

function competitors_discovery_results(\PDO $db,string $search='',string $source='all',int $page=1,int $ownId=0,string $status='all'): array
{
    if(!in_array($status,['all','review','loaded','confirmed'],true))throw new \InvalidArgumentException('Неверный статус сопоставления');
    if($source!=='all')competitors_source($source);$search=mb_substr(trim($search),0,100);$page=max(1,min(10000,$page));
    $where=" WHERE o.enabled=1 AND (?='all' OR i.source_id=?) AND (o.label LIKE ? ESCAPE '\\' OR i.title LIKE ? ESCAPE '\\')";
    $pattern='%'.strtr($search,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';$where.=' AND (CAST(? AS INTEGER)=0 OR o.id=?)';$args=[$source,$source,$pattern,$pattern,$ownId,$ownId];
    $from=" FROM competitor_suggestions s JOIN competitor_targets o ON o.id=s.own_id JOIN competitor_search_index i ON i.id=s.candidate_id LEFT JOIN competitor_targets t ON t.source_id=i.source_id AND t.kind='product' AND t.url=i.url";
    if($status!=='all')$where.=' AND t.enabled=1 AND t.last_success IS NOT NULL AND t.error_count=0';
    if($status==='review')$where.=' AND t.match_confirmed=0 AND t.match_group=o.match_group';
    if($status==='confirmed')$where.=' AND t.match_confirmed=1 AND t.match_group=o.match_group';
    $stmt=$db->prepare('SELECT count(*)'.$from.$where);$stmt->execute($args);$total=(int)$stmt->fetchColumn();
    $stmt=$db->prepare('SELECT o.id AS own_id,o.label AS own_title,o.match_group,i.id AS candidate_id,i.source_id,i.url,i.title,i.title_source,s.score,t.id AS target_id,t.match_confirmed,t.match_method,t.match_group AS target_group,t.enabled AS target_enabled,t.last_success,t.last_attempt,t.observation'.$from.$where.' ORDER BY o.label,i.source_id,s.score DESC,i.id LIMIT 50 OFFSET '.(($page-1)*50));$stmt->execute($args);$rows=$stmt->fetchAll();
    foreach($rows as &$row){foreach(['own_id','candidate_id','score','target_id'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];$row['match_confirmed']=(bool)$row['match_confirmed'];$row['target_enabled']=$row['target_enabled']===null?null:(bool)$row['target_enabled'];$observation=$row['observation']?decode($row['observation']):null;$row['read_title']=$observation['title']??null;$row['identity']=competitors_identity_compare($row['own_title'],$row['read_title']?:$row['title'],$row['url']);$row['score']=$row['identity']['score'];$row['offers']=$observation['offers']??[];unset($row['observation']);$row['last_attempt']=$row['last_attempt']?decode($row['last_attempt']):null;}
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>50,'status'=>$status];
}

/** An observed interval, not a claim about the host's cron configuration. */
function competitors_run_cadence(array $runs): array
{
    $times=[];foreach(array_slice($runs,0,10) as $run){$stamp=$run['started_at']??null;
        if(is_string($stamp)&&preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$stamp))$times[]=strtotime($stamp);}
    $times=array_values(array_unique($times));rsort($times);$gaps=[];
    for($i=1;$i<count($times);++$i){$gap=$times[$i-1]-$times[$i];if($gap>0&&$gap<=86400)$gaps[]=$gap;}
    return ['interval_seconds'=>count($gaps)>=2?(int)round(median($gaps)):null,'interval_samples'=>count($gaps)];
}

function competitors_monitoring_status(\PDO $db,string $directory,array $targets,array $runs,array $catalog): array
{
    $now=time();$latest=$runs[0]??null;$last=$latest['finished_at']??$latest['started_at']??null;
    $cadence=competitors_run_cadence($runs);
    $result=['observed_interval_seconds'=>$cadence['interval_seconds'],'interval_samples'=>$cadence['interval_samples'],'last_run'=>$last,'runner_state'=>!$last?'never':($now-strtotime($last)>1200?'overdue':'recent'),'last_batch'=>$latest,
        'interval_hours'=>24,'recommended_cron_minutes'=>1,'own_products'=>0,'own_prices'=>count(array_filter(($catalog['state']??'')==='ok'?($catalog['products']??[]):[],function($p){return !empty($p['offers']);})),
        'full_catalog'=>is_file($directory.'/competitor-full-catalog.json'),'remote_products'=>0,'pending'=>0,'due'=>0,'failed'=>0,'unconfirmed'=>0,'unconfirmed_loaded'=>0,'automatic_confirmed'=>0,'fresh'=>0,'sources'=>[],
        'price_lists'=>$db->query('SELECT * FROM competitor_price_lists ORDER BY source_id,label LIMIT 100')->fetchAll(),'discovery_enabled'=>(bool)$db->query('SELECT enabled FROM competitor_discovery_settings WHERE id=1')->fetchColumn()];
    foreach(competitors_sources() as $source)if($source['id']!=='lavka')$result['sources'][$source['id']]=['tracked'=>0,'pending'=>0,'failed'=>0,'fresh'=>0,'indexed'=>0,'scan_pending'=>0,'scan_errors'=>0,'last_scan'=>null,'scan_error'=>null];
    foreach($targets as $target){if(!$target['enabled']||$target['kind']!=='product')continue;if($target['source_id']==='lavka'){$result['own_products']++;continue;}
        $result['remote_products']++;$source=&$result['sources'][$target['source_id']];$source['tracked']++;
        if(!$target['last_attempt']){$result['pending']++;$source['pending']++;}
        if($target['last_attempt']&&$target['last_attempt']['state']==='error'){$result['failed']++;$source['failed']++;}
        if(!$target['match_confirmed'])$result['unconfirmed']++;
        if($target['last_success']&&$now-strtotime($target['last_success'])<=172800&&($target['last_attempt']['state']??'')==='ok'){$result['fresh']++;$source['fresh']++;if(!$target['match_confirmed'])$result['unconfirmed_loaded']++;elseif(($target['match_method']??'')==='auto')$result['automatic_confirmed']++;}
        if(strtotime($target['next_check_at'])<=$now)$result['due']++;
        unset($source);
    }
    foreach($db->query('SELECT source_id,count(*) AS n FROM competitor_search_index GROUP BY source_id') as $row)if(isset($result['sources'][$row['source_id']]))$result['sources'][$row['source_id']]['indexed']=(int)$row['n'];
    foreach($db->query("SELECT source_id,sum(CASE WHEN next_check_at<='".utc_now()."' THEN 1 ELSE 0 END) AS pending,sum(CASE WHEN state='error' THEN 1 ELSE 0 END) AS errors,max(checked_at) AS last_scan FROM competitor_scan_pages GROUP BY source_id") as $row)if(isset($result['sources'][$row['source_id']])){$result['sources'][$row['source_id']]['scan_pending']=(int)$row['pending'];$result['sources'][$row['source_id']]['scan_errors']=(int)$row['errors'];$result['sources'][$row['source_id']]['last_scan']=$row['last_scan'];}
    foreach($result['sources'] as $id=>&$source){$stmt=$db->prepare("SELECT error_code FROM competitor_scan_pages WHERE source_id=? AND state='error' ORDER BY checked_at DESC LIMIT 1");$stmt->execute([$id]);$source['scan_error']=$stmt->fetchColumn()?:null;}unset($source);
    return $result;
}
