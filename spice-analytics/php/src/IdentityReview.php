<?php

declare(strict_types=1);
namespace Lavka;

/** Review decisions live outside analytics.sqlite3: no order, link or CRM writes. */
function identity_review_read(string $directory): array
{
    $file=$directory.'/identity-reviews.json';
    if(!file_exists($file))return ['version'=>1,'pairs'=>[]];
    if(!is_file($file)||is_link($file))throw new \RuntimeException('Журнал проверки клиентов недоступен');
    $state=decode(file_get_contents($file));
    if(($state['version']??null)!==1||!is_array($state['pairs']??null))throw new \RuntimeException('Не удалось прочитать журнал проверки клиентов');
    foreach($state['pairs'] as $record){
        if(!is_array($record)||!is_string($record['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$record['id'])||!is_string($record['created_at']??null))throw new \RuntimeException('Повреждён журнал проверки клиентов');
        foreach(['left','right'] as $side){
            if(!is_array($record[$side]??null)||!array_is_list($record[$side])||!$record[$side])throw new \RuntimeException('Повреждён журнал проверки клиентов');
            foreach($record[$side] as $anchor)if(!is_string($anchor)||!preg_match('/^[0-9]{1,30}$/D',$anchor))throw new \RuntimeException('Повреждён журнал проверки клиентов');
        }
    }
    return $state;
}
function identity_review_revision(string $directory): string
{
    $file=$directory.'/identity-reviews.json';clearstatcache(true,$file);
    return is_file($file)?hash_file('sha256',$file):'none';
}
function identity_report_revision(\PDO $db,string $directory): string
{
    return revision($db).':'.identity_review_revision($directory);
}
function identity_pair_key(array $ids): string
{
    sort($ids,SORT_NUMERIC);return implode(':',$ids);
}
/** Follow saved order anchors through later imports and explicit manual merges. */
function identity_review_projection(array $customers,DSU $dsu,array $state,array $candidates): array
{
    $members=[];$byPair=[];$blocked=[];$dismissed=[];
    foreach($customers as $c)$members[$c['id']]=$c;
    foreach($candidates as $pair)$byPair[$pair['id']]=$pair;
    foreach($state['pairs'] as $record){
        if(!empty($record['restored_at']))continue;
        $roots=[];
        foreach(['left','right'] as $side){
            $roots[$side]=[];
            foreach($record[$side] as $anchor)if(isset($dsu->parents[$anchor])){
                $root=$dsu->find($anchor);if(isset($members[$root]))$roots[$side][$root]=true;
            }
        }
        foreach(array_keys($roots['left']) as $left)foreach(array_keys($roots['right']) as $right){
            $ids=[(string)$left,(string)$right];$key=identity_pair_key($ids);
            if($left!==$right)$blocked[$key]=true;
            if(isset($dismissed[$key])){
                $dismissed[$key]['review_ids'][]=$record['id'];
                $dismissed[$key]['reviewed_at']=max($dismissed[$key]['reviewed_at'],$record['created_at']);
                continue;
            }
            $row=$byPair[$key]??['id'=>$key,'ids'=>$ids,'left'=>identity_pair_member($members[$left]),'right'=>identity_pair_member($members[$right]),'matches'=>[],'kinds'=>[],'strength'=>'surname','priority'=>0];
            $row['id']=$record['id'].':'.$key;$row['review_id']=$record['id'];$row['review_ids']=[$record['id']];$row['reviewed_at']=$record['created_at'];$row['same_history']=$left===$right;
            $dismissed[$key]=$row;
        }
    }
    return ['blocked'=>$blocked,'dismissed'=>array_values($dismissed)];
}
function identity_review_pairs(\PDO $db,string $directory,array $pairs,string $asof,string $currency): array
{
    if(!array_is_list($pairs)||count($pairs)<1||count($pairs)>100||!valid_date($asof)||!preg_match('/^[A-Z]{3}$/D',$currency))fail(422,'Выберите от 1 до 100 пар и корректную дату');
    $keys=[];
    foreach($pairs as $pair){
        if(!is_array($pair)||!array_is_list($pair)||count($pair)!==2)fail(422,'Нужна пара из двух карточек');
        foreach($pair as $id)if(!is_string($id)||!preg_match('/^[0-9]{1,30}$/D',$id))fail(422,'Неверный идентификатор карточки');
        if($pair[0]===$pair[1])fail(422,'Выберите разные карточки');
        $key=identity_pair_key($pair);if(isset($keys[$key]))fail(422,'Одна пара выбрана несколько раз');$keys[$key]=$pair;
    }
    return identity_review_write($directory,function($state)use($db,$directory,$keys,$asof,$currency){
        $store=new Analytics($db,$directory);
        if(!in_array($currency,$store->metadata()['currencies'],true))fail(422,'Неизвестная валюта');
        $report=$store->report(0,$asof,$currency,'all',false);$available=array_fill_keys(array_column($report['duplicate_pairs'],'id'),true);
        foreach($keys as $key=>$_)if(!isset($available[$key]))fail(409,'Пары изменились. Обновите список перед проверкой.');
        $cards=[];foreach(identity_customer_cards($store,$asof) as $c)$cards[$c['id']]=$c;
        foreach($keys as $pair)$state['pairs'][]=['id'=>bin2hex(random_bytes(16)),'left'=>$cards[$pair[0]]['order_ids'],'right'=>$cards[$pair[1]]['order_ids'],'created_at'=>utc_now(),'restored_at'=>null];
        return [$state,['ok'=>true,'dismissed'=>count($keys),'shop_writes'=>false,'existing_links_changed'=>false]];
    });
}
function identity_review_restore(string $directory,array $ids): array
{
    if(!array_is_list($ids)||count($ids)<1||count($ids)>10000||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(422,'Неверный список отметок для отмены');
    foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))fail(422,'Неверный номер отметки');
    return identity_review_write($directory,function($state)use($ids){
        $active=[];foreach($state['pairs'] as $index=>$record)if(empty($record['restored_at']))$active[$record['id']]=$index;
        foreach($ids as $id)if(!isset($active[$id]))fail(409,'Отметка уже отменена. Обновите список.');
        foreach($ids as $id)$state['pairs'][$active[$id]]['restored_at']=utc_now();
        return [$state,['ok'=>true,'restored'=>count($ids),'shop_writes'=>false,'existing_links_changed'=>false]];
    });
}
function identity_review_write(string $directory,callable $change): array
{
    private_dir($directory);$path=$directory.'/identity-reviews.lock';
    if(is_link($path))throw new \RuntimeException('Журнал проверки клиентов недоступен');
    $lock=fopen($path,'c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Не удалось сохранить отметку');
    chmod($path,0600);
    try{
        [$state,$result]=$change(identity_review_read($directory));
        atomic_write($directory.'/identity-reviews.json',encode($state));return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
