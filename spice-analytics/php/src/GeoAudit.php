<?php

declare(strict_types=1);
namespace Lavka;

function geo_quality(Geo $geo, array $orders): array
{
    $q = ['orders'=>count($orders),'public_ip'=>0,'address_without_region'=>0,'ip_resolved'=>0,
        'delivery_without_city'=>0,'delivery_without_location'=>0,'pickup_odesa'=>0,'payment_fallback'=>0,
        'region_from_city'=>0,'renamed_orders'=>0,'needs_review'=>0,'unknown_orders'=>[],
        'modes'=>[],'methods'=>[],'unmatched_names'=>[]];
    $methods = [];$unmatched = [];
    foreach ($orders as $o) {
        $address = $geo->address($o);$mixed = $geo->resolve($o);$ip = $geo->lookup($o['ip'] ?? '');
        if ($geo->publicIp($o['ip'] ?? '')) ++$q['public_ip'];
        if ($ip && ($ip['city'] !== '' || $ip['zone'] !== '')) ++$q['ip_resolved'];
        if ($address['zone'] === '') ++$q['address_without_region'];
        if ($address['source_kind'] === 'pickup') ++$q['pickup_odesa'];
        if ($address['source_kind'] === 'payment') ++$q['payment_fallback'];
        if ($address['region_inferred']) ++$q['region_from_city'];
        if (geo_city_text($o['shipping_city'] ?? '') === '') {
            ++$q['delivery_without_city'];
            if (geo_text($o['shipping_zone'] ?? '') === '') ++$q['delivery_without_location'];
            $label = geo_text($o['shipping_method'] ?? '') ?: 'Способ доставки не указан';$key = normalize($label);
            $methods[$key] = $methods[$key] ?? ['method'=>$label,'count'=>0,'pickup_odesa'=>0,'payment'=>0,'ip'=>0,'unknown_city'=>0];
            ++$methods[$key]['count'];
            if ($address['source_kind'] === 'pickup') ++$methods[$key]['pickup_odesa'];
            if ($address['source_kind'] === 'payment') ++$methods[$key]['payment'];
            if ($mixed['source_kind'] === 'ip') ++$methods[$key]['ip'];
            if (!geo_city_text($mixed['city'])) ++$methods[$key]['unknown_city'];
        }
        if ($address['original_city'] !== '' && normalize($address['original_city']) !== normalize($address['city'])) ++$q['renamed_orders'];
        foreach (['address','mixed','ip'] as $mode) {
            $loc = $mode === 'mixed' ? $mixed : $geo->resolve($o,$mode);
            $q['modes'][$mode] = $q['modes'][$mode] ?? ['city_missing'=>0,'region_missing'=>0,'sources'=>[]];
            if (!geo_city_text($loc['city'])) ++$q['modes'][$mode]['city_missing'];
            if (!$loc['zone']) ++$q['modes'][$mode]['region_missing'];
            $kind = $loc['source_kind'];$q['modes'][$mode]['sources'][$kind] = ($q['modes'][$mode]['sources'][$kind] ?? 0)+1;
        }
        $unknownName = $mixed['country_code'] === 'UA' && geo_city_text($mixed['city']) !== '' && !$mixed['city_matched'];
        if ($unknownName) {
            $key = encode([$mixed['city'],$mixed['zone']]);
            $unmatched[$key] = $unmatched[$key] ?? ['city'=>$mixed['city'],'zone'=>$mixed['zone'],'count'=>0];++$unmatched[$key]['count'];
        }
        // An unlisted village with a declared region is usable delivery data.
        // Keep its spelling in diagnostics without making it an address error.
        if (!geo_city_text($mixed['city']) || !$mixed['zone'] || $mixed['city_conflict']) {
            ++$q['needs_review'];
            $reason = $mixed['city_conflict'] ? 'Город не соответствует указанной области' : (!geo_city_text($mixed['city']) ? 'Город не удалось определить' : (!$mixed['zone'] ? 'Область не определена' : 'Название отсутствует в справочнике; сохранено как в заказе'));
            $q['unknown_orders'][] = ['id'=>(string)$o['order_id'],'date'=>substr($o['date_added'],0,10),
                'shipping_method'=>$o['shipping_method'] ?? '', 'city'=>$mixed['city'],'zone'=>$mixed['zone'],
                'source'=>$mixed['source'],'reason'=>$reason];
        }
    }
    usort($q['unknown_orders'],function($a,$b){return strcmp($b['date'],$a['date']) ?: (int)$b['id']<=>(int)$a['id'];});
    $q['unknown_orders'] = array_slice($q['unknown_orders'],0,100);
    usort($methods,function($a,$b){return $b['count']<=>$a['count'];});$q['methods']=array_slice(array_values($methods),0,30);
    usort($unmatched,function($a,$b){return $b['count']<=>$a['count'];});$q['unmatched_names']=array_slice(array_values($unmatched),0,50);
    return $q;
}
