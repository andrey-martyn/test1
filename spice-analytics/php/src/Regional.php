<?php

declare(strict_types=1);
namespace Lavka;

/** Fulfilment, not the buyer's residence. No address is inferred from a phone. */
function regional_channel(Geo $geo,array $order): string
{
    return $geo->pickupOdesa($order)||in_array(normalize($order['shipping_method']??''),['самовывоз','самовивіз','pickup','pick up'],true)?'pickup':'delivery';
}
function regional_key(array $location): string
{
    if(($location['country_code']??'')==='UA'&&isset(geo_regions()['rows'][$location['zone_code']??'']))return $location['zone_code'];
    return !empty($location['country_code'])&&$location['country_code']!=='UA'?'abroad':'unknown';
}
function regional_add(array &$rows,string $key,int $year,array $order,string $customer,bool $repeat): void
{
    if(!isset($rows[$key][$year]))$rows[$key][$year]=['count'=>0,'amount'=>'0','repeat_orders'=>0,'_customers'=>[]];
    $row=&$rows[$key][$year];++$row['count'];$row['amount']=plus($row['amount'],order_value($order));
    if($repeat)++$row['repeat_orders'];$row['_customers'][$customer]=true;unset($row);
}
function regional_finish(array $rows,array $firstYears): array
{
    foreach($rows as &$years){foreach($years as $year=>&$row){
        $row['customer_ids']=array_map('strval',array_keys($row['_customers']));$row['customer_count']=count($row['customer_ids']);
        $row['new_customers']=0;foreach($row['customer_ids'] as $id)if(($firstYears[$id]??null)===(int)$year)++$row['new_customers'];
        $row['returning_customers']=$row['customer_count']-$row['new_customers'];
        $row['average']=$row['count']?money(bcdiv($row['amount'],(string)$row['count'],12)):0;
        $row['amount']=money($row['amount']);unset($row['_customers']);
    }unset($row);}unset($years);
    return $rows;
}
/** All years to the selected as-of; filters on currency/status/name already applied. */
function regional_report(Analytics $store,array $orders,array $groups,DSU $dsu,string $asof,string $coverageStart): array
{
    $regions=[];foreach(geo_regions()['rows'] as $code=>$label)$regions[]=['id'=>$code,'label'=>$label,'country_code'=>'UA'];
    $regions[]=['id'=>'abroad','label'=>'Другие страны','country_code'=>''];$regions[]=['id'=>'unknown','label'=>'Регион не указан','country_code'=>''];
    $first=[];$firstYears=[];foreach($groups as $id=>$customerOrders){$first[$id]=$customerOrders[0]['order_id'];$firstYears[$id]=calendar_year($customerOrders[0]['date_added']);}
    $lastYear=(int)substr($asof,0,4);$firstYear=$coverageStart!==''?(int)substr($coverageStart,0,4):$lastYear;
    $result=['as_of'=>$asof,'cutoff'=>substr($asof,5),'coverage_start'=>$coverageStart,'years'=>range(min($firstYear,$lastYear),$lastYear),
        'regions'=>$regions,'channels'=>['all'=>'Все заказы','delivery'=>'Без самовывоза','pickup'=>'Самовывоз'],'modes'=>[]];
    foreach(['mixed','address','ip'] as $mode){
        $data=[];foreach(['full','same'] as $window)foreach(['all','delivery','pickup'] as $channel)$data[$window][$channel]=['regions'=>[],'totals'=>[]];
        foreach($orders as $order){
            $year=calendar_year($order['date_added']);$customer=$dsu->find($order['order_id']);$repeat=$first[$customer]!==$order['order_id'];
            $key=regional_key($store->geo->resolve($order,$mode));$kind=regional_channel($store->geo,$order);
            $windows=['full'];if(substr($order['date_added'],5,5)<=substr($asof,5))$windows[]='same';
            foreach($windows as $window)foreach(['all',$kind] as $channel){
                regional_add($data[$window][$channel]['regions'],$key,$year,$order,$customer,$repeat);
                regional_add($data[$window][$channel]['totals'],'store',$year,$order,$customer,$repeat);
            }
        }
        foreach($data as &$channels)foreach($channels as &$section){$section['regions']=regional_finish($section['regions'],$firstYears);$section['totals']=regional_finish($section['totals'],$firstYears)['store']??new \stdClass();}unset($section,$channels);
        $result['modes'][$mode]=$data;
    }
    return $result;
}
