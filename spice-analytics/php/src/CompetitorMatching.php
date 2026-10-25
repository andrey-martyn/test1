<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__.'/CompetitorDiscovery.php';

const COMPETITOR_MATCHING_RULE = 'read-product-v1';

/** Preserve meaningful numbers (agar strength, grade, cut size), excluding pack weights. */
function competitors_matching_signature(string $title,string $url=''): ?string
{
    $profile=competitors_identity_profile($title,$url);
    if($profile['jar']||!$profile['core'])return null;
    $forms=array_values(array_intersect($profile['tokens'],['ground','whole','crushed','granulated','flakes','seed','leaf','smoked','ceylon','cassia','black','white','red']));sort($forms);
    $text=mb_strtolower(competitors_identity_name($title));
    $text=preg_replace('/(?<![\pL\d])\d+(?:[.,]\d+)?\s*(?:кг|kg|гр\.?|г|g|мл|ml)(?!\pL)/iu',' ',$text);
    preg_match_all('/\d+(?:[.,]\d+)?/u',$text,$matches);
    $numbers=[];foreach($matches[0] as $n)$numbers[]=(string)(float)str_replace(',','.',$n);
    sort($numbers,SORT_NUMERIC);
    return encode([$profile['core'],$forms,$profile['percentages'],$numbers]);
}

function competitors_matching_plan(array $targets,array $catalog=[],?int $now=null): array
{
    $now=$now??time();$catalogNames=[];
    if(($catalog['state']??'')==='ok')foreach($catalog['products']??[] as $p)$catalogNames[(int)$p['target_id']]=$p['label'];
    $own=[];$signatures=[];
    foreach($targets as $t)if($t['enabled']&&$t['kind']==='product'&&$t['source_id']==='lavka'&&$t['match_group']!==''){
        $title=$catalogNames[$t['id']]??$t['label'];$key=competitors_matching_signature($title);
        $own[$t['match_group']]=['id'=>$t['id'],'title'=>$title,'key'=>$key];
        if($key!==null)$signatures[$key][$t['match_group']]=true;
    }
    $decisions=[];$counts=['checked'=>0,'automatic'=>0,'newly_confirmed'=>0,'revoked'=>0,'manual'=>0,'review'=>0,'reasons'=>[]];
    foreach($targets as $t){
        if(!$t['enabled']||$t['kind']!=='product'||$t['source_id']==='lavka')continue;
        if(($t['match_method']??'unreviewed')==='manual'){$counts['manual']++;continue;}
        $counts['checked']++;$reason='exact';$product=$own[$t['match_group']]??null;$title=$t['observation']['title']??'';
        if(!$product)$reason='no_own_product';
        elseif(!$t['last_success']||($t['last_attempt']['state']??'')!=='ok'||!$t['observation'])$reason='not_loaded';
        elseif($now-strtotime($t['last_success'])>172800||strtotime($t['last_success'])>$now+300)$reason='stale';
        elseif($title==='')$reason='no_read_title';
        elseif(competitors_identity_jar($title,$t['url']))$reason='jar';
        else{
            $key=competitors_matching_signature($title,$t['url']);
            if($key===null||$key!==$product['key'])$reason='different_product_or_specs';
            elseif(count($signatures[$key]??[])!==1)$reason='ambiguous_own_product';
            elseif(!($identity=competitors_identity_compare($product['title'],$title,$t['url']))['exact'])$reason='different_product_or_specs';
            elseif(!array_filter($t['observation']['offers']??[],function($o){return ($o['packaging']??'unknown')!=='jar'&&($o['unit']??'')==='pack'&&is_numeric($o['grams']??null)&&$o['grams']>0&&is_numeric($o['price']??null)&&$o['price']>0;}))$reason='no_pack_price';
        }
        $confirmed=$reason==='exact';
        if($confirmed){$counts['automatic']++;if(!$t['match_confirmed'])$counts['newly_confirmed']++;}
        else{$counts['review']++;$counts['reasons'][$reason]=($counts['reasons'][$reason]??0)+1;if($t['match_confirmed'])$counts['revoked']++;}
        $decisions[]=['id'=>$t['id'],'confirmed'=>$confirmed,'evidence'=>['rule'=>COMPETITOR_MATCHING_RULE,'reason'=>$reason,'own_id'=>$product['id']??null,'own_title'=>$product['title']??'','read_title'=>$title,'match_group'=>$t['match_group'],'checked_at'=>$t['last_success']]];
    }
    return ['summary'=>$counts,'decisions'=>$decisions,'rule'=>COMPETITOR_MATCHING_RULE];
}

/** Only local matching metadata changes. Prices, source records and shop stay untouched. */
function competitors_matching_reconcile(\PDO $db,array $catalog=[]): array
{
    $db->beginTransaction();
    try{
        $db->exec('UPDATE competitor_discovery_settings SET enabled=enabled WHERE id=1');
        $targets=array_map(__NAMESPACE__.'\\competitors_target_row',$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll());
        $plan=competitors_matching_plan($targets,$catalog);
        $save=$db->prepare("UPDATE competitor_targets SET match_confirmed=?,match_method='auto',match_evidence=? WHERE id=? AND match_method<>'manual'");
        foreach($plan['decisions'] as $d)$save->execute([(int)$d['confirmed'],encode($d['evidence']),$d['id']]);
        $db->commit();return $plan['summary']+['rule'=>COMPETITOR_MATCHING_RULE,'network_requests'=>0,'shop_writes'=>false];
    }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
