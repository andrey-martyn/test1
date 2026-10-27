<?php

declare(strict_types=1);
namespace Lavka;

/** Validate the entire selection before writing one edge for each selected row. */
function merge_identity_pairs(\PDO $db, string $directory, array $pairs, string $asof, string $currency): array
{
    if (!array_is_list($pairs) || count($pairs) < 1 || count($pairs) > 100 || !valid_date($asof) || !preg_match('/^[A-Z]{3}$/D', $currency)) fail(422, 'Выберите от 1 до 100 пар и корректную дату');
    $normalized = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) fail(422, 'Каждая строка должна содержать ровно два контакта');
        foreach ($pair as $id) if (!is_string($id) || !preg_match('/^[0-9]{1,30}$/D', $id)) fail(422, 'Неверный идентификатор контакта');
        if ($pair[0] === $pair[1]) fail(422, 'Выберите разные контакты');
        sort($pair, SORT_NUMERIC);
        $key = implode(':', $pair);
        if (isset($normalized[$key])) fail(422, 'Одна пара выбрана несколько раз');
        $normalized[$key] = $pair;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $store = new Analytics($db, $directory);
        if (!in_array($currency, $store->metadata()['currencies'], true)) fail(422, 'Неизвестная валюта');
        $report = $store->report(0, $asof, $currency, 'all', false);
        $available = array_fill_keys(array_column($report['duplicate_pairs'], 'id'), true);
        foreach ($normalized as $key => $_) if (!isset($available[$key])) fail(409, 'Совпадения изменились. Обновите таблицу и выберите пары заново.');
        $nodes = [];
        foreach ($normalized as $pair) foreach ($pair as $id) $nodes[$id] = true;
        $dsu = new DSU(array_map('strval', array_keys($nodes)));
        $stmt = $db->prepare('INSERT OR IGNORE INTO manual_links VALUES(?,?,?)');
        foreach ($normalized as $pair) {
            $stmt->execute([$pair[0], $pair[1], utc_now()]);
            $dsu->union($pair[0], $pair[1]);
        }
        $components = [];
        foreach ($nodes as $id => $_) $components[$dsu->find((string) $id)] = true;
        bump($db);
        $db->exec('COMMIT');
        return ['ok' => true, 'pairs_merged' => count($normalized), 'contacts' => count($nodes), 'groups' => count($components)];
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}


/** All years and currencies, using the cabinet's included order statuses. */
function identity_customer_cards(Analytics $store,string $asof): array
{
    if(!valid_date($asof))fail(422,'Неверная дата');
    $before=array_values(array_filter($store->orders,function($o)use($asof){return substr($o['date_added'],0,10)<=$asof;}));
    $dsu=$store->identity($before);$groups=[];
    foreach($before as $o)if(in_array($o['order_status_id'],$store->settings['status_ids'],true))$groups[$dsu->find($o['order_id'])][]=$o;
    $cards=[];
    foreach($groups as $id=>$orders){
        usort($orders,function($a,$b){return strcmp($a['date_added'],$b['date_added'])?:(int)$a['order_id']<=>(int)$b['order_id'];});
        $latest=$orders[count($orders)-1];$fields=identity_contact_fields($orders);$amounts=[];
        foreach($orders as $o){$cc=$o['currency_code'];$amounts[$cc]=plus($amounts[$cc]??'0',order_value($o));}
        foreach($amounts as &$amount)$amount=money($amount);unset($amount);ksort($amounts);
        $cards[]=$fields+['id'=>(string)$id,'order_ids'=>array_map('strval',array_column($orders,'order_id')),'name'=>trim($latest['firstname'].' '.$latest['lastname'])?:'Гость','email'=>$latest['email'],'phone'=>$latest['telephone'],
            'emails'=>array_values(array_unique(array_filter(array_column($orders,'email')))),'phones'=>array_values(array_unique(array_filter(array_column($orders,'telephone')))),
            'city'=>$latest['shipping_city']?:$latest['payment_city'],'lifetime_count'=>count($orders),'last_order'=>substr($latest['date_added'],0,10),'amounts'=>$amounts];
    }
    return $cards;
}

function identity_customer_search(Analytics $store,string $asof,string $query='',int $page=1): array
{
    if(mb_strlen($query)>200 || $page<1 || $page>100000)fail(422,'Неверные параметры поиска');
    $cards=identity_customer_cards($store,$asof);$q=normalize($query);$digits=preg_replace('/\D/','',$query);
    if($q!=='')$cards=array_values(array_filter($cards,function($c)use($q,$digits){
        $text=normalize(implode(' ',array_merge([$c['id'],$c['name'],$c['city']],$c['names'],$c['surnames'],$c['emails'],$c['phones'])));
        if(mb_strpos($text,$q)!==false)return true;
        return strlen($digits)>=3 && preg_match('/^[+() .\-0-9]+$/D',$q) && strpos(implode(' ',array_map(function($v){return preg_replace('/\D/','',$v);},$c['phones'])),$digits)!==false;
    }));
    usort($cards,function($a,$b){return strcmp(normalize($a['name']),normalize($b['name']))?:(int)$a['id']<=>(int)$b['id'];});
    return ['customers'=>array_slice($cards,($page-1)*30,30),'total'=>count($cards),'page'=>$page,'page_size'=>30,'as_of'=>$asof,'scope'=>'all_years_currencies_included_statuses'];
}

/** An explicit selection can link unrelated cards; no matching signal is required. */
function merge_identity_customers(\PDO $db,string $directory,array $ids,string $asof): array
{
    if(!array_is_list($ids)||count($ids)<2||count($ids)>20||!valid_date($asof))fail(422,'Выберите от 2 до 20 разных карточек');
    foreach($ids as $id)if(!is_string($id)||!preg_match('/^[0-9]{1,30}$/D',$id))fail(422,'Неверный идентификатор карточки');
    if(count(array_unique($ids))!==count($ids))fail(422,'Карточка выбрана несколько раз');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $store=new Analytics($db,$directory);$cards=identity_customer_cards($store,$asof);$known=array_fill_keys(array_column($cards,'id'),true);
        foreach($ids as $id)if(!isset($known[$id]))fail(409,'Карточки изменились или не входят в выбранные статусы. Обновите список.');
        $stmt=$db->prepare('INSERT OR IGNORE INTO manual_links VALUES(?,?,?)');
        foreach(array_slice($ids,1) as $id){$pair=[$ids[0],$id];sort($pair,SORT_NUMERIC);$stmt->execute([$pair[0],$pair[1],utc_now()]);}
        bump($db);$db->exec('COMMIT');
        return ['ok'=>true,'customer_id'=>(string)min(array_map('intval',$ids)),'merged_ids'=>$ids,'cards_merged'=>count($ids),'shop_writes'=>false];
    }catch(\Throwable $error){$db->exec('ROLLBACK');throw $error;}
}
