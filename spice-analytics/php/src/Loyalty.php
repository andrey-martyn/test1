<?php

declare(strict_types=1);
namespace Lavka;

/** A fixed observation window, not an assertion about a person's character. */
function loyalty_bucket(): array
{
    return ['customers'=>0,'eligible'=>0,'returned'=>0,'pending'=>0,'rate'=>null,
        'eligible_180'=>0,'regular_180'=>0,'regular_rate'=>null,'customer_ids'=>[],
        'returned_ids'=>[],'pending_ids'=>[],'regular_ids'=>[]];
}
function loyalty_add(array &$bucket,string $id,array $orders,string $asof): void
{
    ++$bucket['customers'];$bucket['customer_ids'][]=$id;
    $age=days(substr($orders[0]['date_added'],0,10),$asof);
    if($age>=90){
        ++$bucket['eligible'];
        if(isset($orders[1])&&days($orders[0]['date_added'],$orders[1]['date_added'])<=90){++$bucket['returned'];$bucket['returned_ids'][]=$id;}
    }else{++$bucket['pending'];$bucket['pending_ids'][]=$id;}
    if($age>=180){
        ++$bucket['eligible_180'];
        if(isset($orders[2])&&days($orders[0]['date_added'],$orders[2]['date_added'])<=180){++$bucket['regular_180'];$bucket['regular_ids'][]=$id;}
    }
}
function loyalty_finish(array $rows): array
{
    foreach($rows as &$row){
        $row['rate']=$row['eligible']?even_round($row['returned']/$row['eligible']*100,2):null;
        $row['regular_rate']=$row['eligible_180']?even_round($row['regular_180']/$row['eligible_180']*100,2):null;
        $row['small_sample']=$row['eligible']<30;
    }unset($row);return $rows;
}
/** Cohort = year of first eligible purchase. Each buyer has one entry region. */
function loyalty_report(Geo $geo,array $groups,string $asof,int $year,bool $byGender=false): array
{
    $rows=[];
    if($byGender)foreach(NAME_GENDER_LABELS as $key=>$label)$rows[$key]=array_merge(loyalty_bucket(),['key'=>$key,'label'=>$label]);
    else foreach(['all','delivery','pickup'] as $channel)$rows[$channel]=[];
    foreach($groups as $id=>$orders){
        usort($orders,function($a,$b){return strcmp($a['date_added'],$b['date_added'])?:(int)$a['order_id']<=>(int)$b['order_id'];});
        if($year&&calendar_year($orders[0]['date_added'])!==$year)continue;
        if($byGender){$key=customer_gender_estimate($orders);loyalty_add($rows[$key],(string)$id,$orders,$asof);continue;}
        $location=$geo->address($orders[0]);$key=regional_key($location);$channel=regional_channel($geo,$orders[0]);
        $label=$key==='abroad'?'Другие страны':($key==='unknown'?'Регион не указан':geo_regions()['rows'][$key]);
        foreach(['all',$channel] as $kind){
            if(!isset($rows[$kind][$key]))$rows[$kind][$key]=array_merge(loyalty_bucket(),['key'=>$key,'label'=>$label]);
            loyalty_add($rows[$kind][$key],(string)$id,$orders,$asof);
        }
    }
    if($byGender)$rows=loyalty_finish($rows);
    else foreach($rows as &$channel)$channel=loyalty_finish($channel);unset($channel);
    return ['cohort_year'=>$year,'as_of'=>$asof,'window'=>90,'regular_window'=>180,'rows'=>$rows];
}
