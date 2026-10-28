<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
function diff_expect(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function diff_seed(string $directory): array
{
    $db=\Lavka\competitors_db($directory);
    $target=\Lavka\competitors_save_target($db,['source_id'=>'deluxe','kind'=>'terms','url'=>'https://deluxe.com.ua/synthetic-diff','label'=>'Доставка и минимальный заказ']);
    $texts=['Минимальный заказ 100 грн. Доставка 60 грн. Самовывоз доступен.',
        'Минимальный заказ 200 грн. Доставка 60 грн. Самовывоз доступен.',
        'Минимальный заказ 200 грн. Доставка 90 грн. Самовывоз доступен.'];
    $dates=['2026-10-25T10:00:00Z','2026-10-26T10:00:00Z','2026-10-27T10:00:00Z','2026-10-28T10:00:00Z'];
    foreach($dates as $i=>$date){
        $observation=['kind'=>'terms','title'=>'Условия доставки','text'=>$texts[min($i,2)],'offers'=>[],'links'=>[],'warnings'=>[],'method'=>'synthetic'];
        \Lavka\competitors_record_attempt($db,$target['id'],$observation,null,$date,200);
    }
    \Lavka\competitors_record_attempt($db,$target['id'],null,'blocked','2026-10-28T11:00:00Z',403);
    $changes=$db->query('SELECT id FROM competitor_changes WHERE target_id='.(int)$target['id'].' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    return [$db,$target,$changes,$texts];
}
if(!defined('LAVKA_DIFF_FIXTURE_ONLY')){
    $directory=sys_get_temp_dir().'/lavka-diff-'.bin2hex(random_bytes(6));mkdir($directory,0700);
    try{
        [$db,$target,$changes,$texts]=diff_seed($directory);
        $before=$db->query('SELECT total_changes()')->fetchColumn();
        $history=\Lavka\competitors_history($db,$target['id'],(int)$changes[0]);
        diff_expect($history['selected_change']['before']['text']===$texts[0]&&$history['selected_change']['after']['text']===$texts[1],'The selected event uses its exact saved pair, not the latest page');
        diff_expect(count($history['observations'])===4&&count($changes)===2,'Repeated successful reads are unchanged; failed reads never replace snapshots');
        diff_expect($history['observations'][0]['observation']['text']===$texts[2],'Current snapshot remains current');
        diff_expect(!isset(\Lavka\competitors_history($db,$target['id'])['selected_change']),'Normal history remains compatible');
        $otherId=(int)$db->query('SELECT id FROM competitor_targets WHERE id<>'.(int)$target['id'].' LIMIT 1')->fetchColumn();
        foreach([[$otherId,(int)$changes[0]],[$target['id'],99999999]] as $args){
            try{\Lavka\competitors_history($db,$args[0],$args[1]);throw new RuntimeException('An event cannot be borrowed from a different target');}
            catch(\Lavka\CompetitorNotFound $expected){}
        }
        diff_expect($before===$db->query('SELECT total_changes()')->fetchColumn(),'Viewing differences writes no data');
        // Exact event payloads remain available after its snapshots leave the 180-row window.
        $db->beginTransaction();
        $insert=$db->prepare('INSERT INTO competitor_observations(target_id,checked_at,payload,fingerprint) SELECT target_id,checked_at,payload,fingerprint FROM competitor_observations WHERE target_id=? ORDER BY id DESC LIMIT 1');
        for($i=0;$i<185;$i++)$insert->execute([$target['id']]);
        $db->commit();$before=$db->query('SELECT total_changes()')->fetchColumn();
        $limited=\Lavka\competitors_history($db,$target['id'],(int)$changes[0]);
        diff_expect(count($limited['observations'])===180&&$limited['observations'][179]['observation']['text']===$texts[2],'Old snapshots have left the history window');
        diff_expect($limited['selected_change']['before']['text']===$texts[0]&&$limited['selected_change']['after']['text']===$texts[1],'Historical event remains exact beyond the snapshot limit');
        diff_expect($before===$db->query('SELECT total_changes()')->fetchColumn(),'Older event reads also write no data');
        echo \Lavka\encode(['php'=>PHP_VERSION,'exact_historical_change'=>'passed','unchanged_and_failed_reads'=>'passed','target_isolation'=>'passed','database_writes'=>0]),"\n";
    }finally{$db=null;foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}
}
