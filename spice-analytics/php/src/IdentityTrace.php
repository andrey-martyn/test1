<?php

declare(strict_types=1);
namespace Lavka;

/** Reconstruct current edges using exactly the keys used by Analytics::identity. */
function identity_trace_graph(array $orders,array $links): array
{
    $graph=[];$index=[];
    $connect=function(string $a,string $b,array $reason)use(&$graph){
        if($a===$b)return;
        $key=encode($reason);$graph[$a][$b][$key]=$reason;$graph[$b][$a][$key]=$reason;
    };
    foreach($orders as $o){
        $id=(string)$o['order_id'];$graph[$id]=$graph[$id]??[];$keys=[];
        if(($o['customer_id']??'0')!=='0'&&($o['customer_id']??'')!=='')$keys['account:'.$o['customer_id']]=['kind'=>'account','label'=>'Один аккаунт магазина','account_id'=>(string)$o['customer_id']];
        $email=email_key($o['email']);$phone=phone_key($o['telephone']);
        if($email&&$phone)$keys['contact:'.encode([$email,$phone])]=['kind'=>'contact','label'=>'Совпали email и телефон','email'=>$email,'phone'=>$phone];
        foreach($keys as $key=>$reason){
            if(isset($index[$key]))$connect($id,$index[$key],$reason);else $index[$key]=$id;
        }
    }
    foreach($links as $link)if(isset($orders[$link['a']],$orders[$link['b']]))$connect((string)$link['a'],(string)$link['b'],['kind'=>'manual','label'=>'Объединено вручную','created_at'=>$link['created_at']??'']);
    return $graph;
}
function identity_trace_node(array $o,array $statusIds,string $currency): array
{
    return ['id'=>(string)$o['order_id'],'name'=>trim($o['firstname'].' '.$o['lastname'])?:'Гость','email'=>$o['email'],'phone'=>$o['telephone'],'date'=>substr($o['date_added'],0,10),
        'surname'=>identity_order_surname($o),'name_fields_adjusted'=>normalize(identity_order_surname($o))!==normalize($o['lastname']),
        'outside_sales_scope'=>!in_array($o['order_status_id'],$statusIds,true)||$o['currency_code']!==$currency];
}
/** A shortest valid path, not an invented chronological audit of old decisions. */
function identity_trace_path(array $graph,array $orders,string $start,string $end,array $statusIds,string $currency): array
{
    if(!isset($orders[$start],$orders[$end]))return ['available'=>false,'message'=>'Исходный заказ недоступен в выбранной истории'];
    $previous=[$start=>null];$queue=[$start];$cursor=0;
    while($cursor<count($queue)&&!array_key_exists($end,$previous)){
        $current=$queue[$cursor++];
        foreach($graph[$current]??[] as $other=>$reasons){
            $other=(string)$other;if(array_key_exists($other,$previous))continue;
            $previous[$other]=['from'=>$current,'reasons'=>array_values($reasons)];$queue[]=$other;
            if($other===$end)break;
        }
    }
    if(!array_key_exists($end,$previous))return ['available'=>false,'message'=>'Связь между этими заказами не найдена. Обновите список'];
    $ids=[$end];$edges=[];$current=$end;
    while($current!==$start){$edge=$previous[$current];$edges[]=['from'=>$edge['from'],'to'=>$current,'reasons'=>$edge['reasons']];$current=$edge['from'];$ids[]=$current;}
    return ['available'=>true,'nodes'=>array_map(function($id)use($orders,$statusIds,$currency){return identity_trace_node($orders[$id],$statusIds,$currency);},array_reverse($ids)),
        'edges'=>array_reverse($edges),'manual_edges'=>count(array_filter($edges,function($edge){foreach($edge['reasons'] as $reason)if($reason['kind']==='manual')return true;return false;}))];
}
function identity_trace(\PDO $db,string $directory,string $left,string $right,string $asof,string $currency,string $revision): array
{
    foreach([$left,$right] as $id)if(!preg_match('/^[0-9]{1,30}$/D',$id))fail(422,'Неверный номер карточки');
    if($left===$right||!valid_date($asof)||!preg_match('/^[A-Z]{3}$/D',$currency))fail(422,'Неверные параметры цепочки');
    $db->beginTransaction();
    try{
        if($revision!==identity_report_revision($db,$directory))fail(409,'История изменилась. Обновите список совпадений');
        $store=new Analytics($db,$directory);
        if(!in_array($currency,$store->metadata()['currencies'],true))fail(422,'Неизвестная валюта');
        $report=$store->report(0,$asof,$currency,'all',false);$pair=null;$key=identity_pair_key([$left,$right]);
        foreach(array_merge($report['duplicate_pairs'],$report['dismissed_duplicate_pairs']) as $candidate)if(identity_pair_key($candidate['ids'])===$key&&$candidate['matches']){$pair=$candidate;break;}
        if(!$pair)fail(409,'Эта пара больше не предлагается для сравнения. Обновите список');
        $members=array_column($report['customers'],null,'id');$orders=[];
        foreach($store->orders as $id=>$o)if(substr($o['date_added'],0,10)<=$asof)$orders[(string)$id]=$o;
        $links=$db->query('SELECT a,b,created_at FROM manual_links ORDER BY a,b')->fetchAll();$graph=identity_trace_graph($orders,$links);
        $current=[];foreach(['left','right'] as $side){$ids=$members[$pair[$side]['id']]['order_ids'];$current[$side]=(string)end($ids);}
        $groups=[];
        foreach($pair['matches'] as $match){
            $sources=[];
            foreach(['left','right'] as $side)$sources[$side]=!empty($match[$side.'_current'])?$current[$side]:(string)($match[$side.'_sources'][0]['order_id']??'');
            $group=$sources['left'].':'.$sources['right'];
            if(!isset($groups[$group]))$groups[$group]=['sources'=>$sources,'matches'=>[],'historical'=>$sources['left']!==$current['left']||$sources['right']!==$current['right']];
            $groups[$group]['matches'][]=array_intersect_key($match,array_flip(['kind','label','value']));
        }
        $groups=array_values($groups);
        $rank=function($group){$ranks=['email'=>0,'phone'=>1,'name'=>2,'surname'=>3];return min(array_map(function($m)use($ranks){return $ranks[$m['kind']];},$group['matches']));};
        usort($groups,function($a,$b)use($rank){return (int)$b['historical']<=>(int)$a['historical']?:$rank($a)<=>$rank($b)?:strcmp(encode($a['sources']),encode($b['sources']));});
        $paths=[];$variants=[];
        foreach(array_slice($groups,0,8) as $group){
            $variant=['matches'=>$group['matches']];
            foreach(['left','right'] as $side){
                $source=$group['sources'][$side];$pathKey=$side.':'.$current[$side].':'.$source;
                if(!isset($paths[$pathKey]))$paths[$pathKey]=identity_trace_path($graph,$orders,$current[$side],$source,$store->settings['status_ids'],$currency);
                $variant[$side.'_path']=$pathKey;
            }
            $variants[]=$variant;
        }
        $result=['pair'=>['left'=>array_intersect_key($pair['left'],array_flip(['id','name'])),'right'=>array_intersect_key($pair['right'],array_flip(['id','name']))],'variants'=>$variants,'variant_total'=>count($groups),'paths'=>$paths,'revision'=>$report['identity_revision'],
            'review_state'=>!empty($pair['review_id'])?'different_customers':'pending','basis'=>'current_saved_links','read_only'=>true,'existing_links_changed'=>false,
            'note'=>'Показан кратчайший путь по действующим связям. Для старых ручных объединений сохранены пары и даты, но не исходная причина решения. Связи внутри двух карточек показаны отдельно.'];
        if(identity_report_revision($db,$directory)!==$revision)fail(409,'История изменилась. Обновите список совпадений');
        $db->commit();return $result;
    }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
