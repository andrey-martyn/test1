<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
require_once dirname(__DIR__).'/src/Dashboard.php';
function ir_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ir_fixture():array{
    $rows=array_fill_keys(array_keys(\Lavka\TABLES),[]);
    $people=[
        ['1','11','Анна','Северная','north@example.invalid','+380(50)765-43-21','2025-01-01'],
        ['2','22','Олена','Соколова','south@example.invalid','+380677654321','2026-09-01'],
        ['3','33','Марія','Інша','third@example.invalid','+380637654322','2026-06-01'],
        ['4','44','Дмитро','Окремий','fourth@example.invalid','+380637654323','2026-05-01'],
        ['5','55','Ірина','Спільна','five@example.invalid','+380637654324','2026-04-01'],
        ['6','66','Олег','Спільна','six@example.invalid','+380637654325','2026-04-02'],
        ['7','77','Ольга','Друга','seven@example.invalid','+380637654326','2026-03-01'],
        ['8','88','Анна','Северная','old@example.invalid','0507654321','2024-01-01'],
        ['9','99','Наталія','Окрема','nine@example.invalid','+380637654327','2026-02-01'],
        ['10','33','Марія','Інша','third@example.invalid','+380637654322','2026-07-01'],
        ['11','111','Гость','Покупатель','info@example.invalid','','2026-01-01'],
        ['12','112','Гость','Покупатель','info@example.invalid','','2026-01-02'],
    ];
    foreach($people as $v){
        [$id,$account,$first,$last,$email,$phone,$date]=$v;
        $row=array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'],'');
        $rows['oc_order'][$id]=array_merge($row,['order_id'=>$id,'customer_id'=>$account,'firstname'=>$first,'lastname'=>$last,'email'=>$email,'telephone'=>$phone,'date_added'=>$date.' 12:00:00','date_modified'=>$date.' 12:00:00','total'=>'100','currency_code'=>'UAH','currency_value'=>'1','order_status_id'=>'2','shipping_city'=>'Київ']);
        $rows['oc_order_product'][$id]=['order_product_id'=>$id,'order_id'=>$id,'product_id'=>'1','name'=>'Синтетическая специя','model'=>'test','quantity'=>'1','price'=>'100','total'=>'100'];
    }
    $rows['oc_order_status']['2:1']=['order_status_id'=>'2','language_id'=>'1','name'=>'В обработке'];return $rows;
}
if(defined('LAVKA_IDENTITY_REVIEW_FIXTURE_ONLY'))return;
function ir_snapshot(PDO $db):string{
    $out=[];foreach(['raw_records','imports','settings','manual_links','customer_actions','crm_events','ga4_records','app_revision','sync_status'] as $table)$out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
    return hash('sha256',\Lavka\encode($out));
}
function ir_report(PDO $db,string $dir):array{return (new \Lavka\Analytics($db,$dir))->report(0,'2026-10-27','UAH','all',false);}
$dir=sys_get_temp_dir().'/identity-review-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try{
    $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);$rows=ir_fixture();\Lavka\apply_snapshot($db,$rows,'test');
    foreach([['2','8'],['3','4'],['3','7'],['3','9']] as $p)$db->prepare('INSERT INTO manual_links VALUES(?,?,?)')->execute([$p[0],$p[1],'2026-10-20']);
    $db->exec("INSERT INTO customer_actions VALUES('3','{\"stage\":\"do_not_contact\",\"note\":\"existing-private-note\"}')");\Lavka\bump($db);
    $before=ir_snapshot($db);$bytes=hash_file('sha256',$dir.'/analytics.sqlite3');$links=$db->query('SELECT * FROM manual_links ORDER BY a,b')->fetchAll();
    $report=ir_report($db,$dir);$pairs=array_column($report['duplicate_pairs'],null,'id');
    ir_check(isset($pairs['1:2'],$pairs['5:6']),'Historical contacts and exact surnames remain reviewable');
    ir_check(!isset($pairs['1:3'],$pairs['2:3'],$pairs['11:12']),'Unrelated people, role mailboxes and generic names never form evidence');
    $pair=$pairs['1:2'];$matches=array_column($pair['matches'],null,'kind');
    ir_check($pair['right']['name']==='Олена Соколова'&&$pair['historical']&&$matches['phone']['left_current']&&!$matches['phone']['right_current'],'Latest display names are distinct from real historical evidence');
    ir_check($matches['phone']['right_sources'][0]['order_id']==='8'&&$matches['phone']['right_sources'][0]['date']==='2024-01-01','Historical evidence identifies its actual order');
    foreach($report['duplicate_pairs'] as $p){
        $all=array_column($report['customers'],null,'id');$left=\Lavka\identity_contact_keys($all[$p['ids'][0]]);$right=\Lavka\identity_contact_keys($all[$p['ids'][1]]);
        foreach($p['matches'] as $m)ir_check(isset($left[$m['kind']][$m['value']],$right[$m['kind']][$m['value']]),'Every claimed match exists on BOTH cards');
    }
    ir_check($pairs['5:6']['strength']==='surname'&&$pair['priority']>$pairs['5:6']['priority'],'A surname is weak evidence, never a contact-level match');
    $dash=\Lavka\cached_dashboard($db,$dir,'2026-10-27','UAH');$revision=\Lavka\identity_review_revision($dir);
    $result=\Lavka\identity_review_pairs($db,$dir,[['2','1'],['6','5']],'2026-10-27','UAH');
    ir_check($result['dismissed']===2&&!$result['existing_links_changed'],'Unordered batch decisions accepted');
    ir_check(ir_snapshot($db)===$before&&hash_file('sha256',$dir.'/analytics.sqlite3')===$bytes,'Review does not change a SINGLE BYTE of the existing database');
    $after=ir_report($db,$dir);ir_check(count($after['duplicate_pairs'])===0&&count($after['dismissed_duplicate_pairs'])===2,'Decisions survive a fresh report');
    ir_check($after['metrics']===$report['metrics']&&\Lavka\encode($after['customers'])===\Lavka\encode($report['customers']),'All merged customer histories, metrics and CRM flags are preserved');
    ir_check(((array)$after['crm']['customers'])['1']['duplicates']['count']===0,'Dismissed pair is not requested again by CRM');
    ir_check(\Lavka\cached_dashboard($db,$dir,'2026-10-27','UAH')['workflows']['duplicate_pairs']===$dash['workflows']['duplicate_pairs']-2,'Dashboard caches follow the separate review revision');
    ir_check(\Lavka\cached_report($db,$dir,0,'2026-10-27','UAH')['duplicate_pairs']===[],'Ordinary report caches also respect decisions');
    $json=file_get_contents($dir.'/identity-reviews.json');ir_check(strpos($json,'@')===false&&strpos($json,'Северная')===false,'Decision storage contains only order anchors and dates, no contact copies');
    $saved=\Lavka\identity_review_revision($dir);
    foreach([[['1','3']],[['1','2']],[['1','1']],[['1','2'],['2','1']]] as $bad){
        try{\Lavka\identity_review_pairs($db,$dir,$bad,'2026-10-27','UAH');throw new RuntimeException('Invalid review accepted');}catch(\Lavka\HttpError $e){ir_check(in_array($e->status,[409,422],true),'Invalid and stale pairs rejected atomically');}
        ir_check(\Lavka\identity_review_revision($dir)===$saved&&ir_snapshot($db)===$before,'Failed batches preserve decisions and all existing records');
    }
    try{\Lavka\merge_identity_pairs($db,$dir,[['1','2']],'2026-10-27','UAH');throw new RuntimeException('Dismissed stale merge accepted');}catch(\Lavka\HttpError $e){ir_check($e->status===409,'Stale merge must not join a dismissed pair');}
    $decision=$after['dismissed_duplicate_pairs'][0]['review_id'];\Lavka\identity_review_restore($dir,[$decision]);
    ir_check(count(ir_report($db,$dir)['duplicate_pairs'])===1&&ir_snapshot($db)===$before,'Restore changes only the separate decision journal');
    \Lavka\identity_review_pairs($db,$dir,[['1','2']],'2026-10-27','UAH');
    // A late historical import changes the representative ID, not the reviewed pair.
    $late=$rows['oc_order']['1'];$late['order_id']='0';$late['date_added']='2023-01-01 12:00:00';$late['date_modified']=$late['date_added'];$rows['oc_order']['0']=$late;$line=$rows['oc_order_product']['1'];$line['order_product_id']='0';$line['order_id']='0';$rows['oc_order_product']['0']=$line;
    \Lavka\apply_snapshot($db,$rows,'test');$fresh=ir_report($db,$dir);
    ir_check(!in_array('0:2',array_column($fresh['duplicate_pairs'],'id'),true)&&count($fresh['dismissed_duplicate_pairs'])===2,'Decisions survive imports and changed representative IDs');
    ir_check($db->query('SELECT * FROM manual_links ORDER BY a,b')->fetchAll()===$links,'All previously completed manual links survive import and review');
    $reviews=\Lavka\identity_review_read($dir);$active=array_values(array_filter($reviews['pairs'],function($p){return empty($p['restored_at']);}));
    $snapshot=ir_snapshot($db);\Lavka\identity_review_restore($dir,array_column($active,'id'));ir_check(count(ir_report($db,$dir)['duplicate_pairs'])===2&&ir_snapshot($db)===$snapshot,'All decisions can be undone without redoing any old merges');
    $journal=file_get_contents($dir.'/identity-reviews.json');
    file_put_contents($dir.'/identity-reviews.json','{broken');
    try{ir_report($db,$dir);throw new RuntimeException('Corrupt review journal was silently ignored');}catch(JsonException $e){}
    file_put_contents($dir.'/identity-reviews.json',$journal);
    ir_check(ir_snapshot($db)===$snapshot,'A corrupt journal cannot modify or repair existing records');
    $backupRoot=$dir.'-backup';
    $command=implode(' ',array_map('escapeshellarg',[PHP_BINARY,dirname(__DIR__).'/bin/backup.php','--data',$dir,'--destination',$backupRoot]));
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    ir_check(proc_close($process)===0,'Private backup failed: '.$stderr);
    $backups=glob($backupRoot.'/*');ir_check(count($backups)===1,'One complete backup');
    ir_check(file_get_contents($backups[0].'/identity-reviews.json')===$journal,'Backup includes all separate review decisions');
    $copy=new SQLite3($backups[0].'/analytics.sqlite3',SQLITE3_OPEN_READONLY);
    ir_check((int)$copy->querySingle('SELECT count(*) FROM manual_links')===count($links),'Backup includes all previously completed manual links');$copy->close();
    foreach(glob($backups[0].'/*') as $file)unlink($file);rmdir($backups[0]);rmdir($backupRoot);
    echo \Lavka\encode(['historical_evidence'=>'verified_on_both_sides','generic_noise'=>'filtered','review_and_restore'=>'passed','existing_database_bytes'=>'unchanged','existing_manual_links'=>'preserved','imports_and_cache_revision'=>'passed','php'=>PHP_VERSION])."\n";
}finally{
    $db=null;foreach(glob($dir.'/cache/*') as $p)if(is_file($p))unlink($p);foreach(glob($dir.'/cache/.*') as $p)if(is_file($p))unlink($p);if(is_dir($dir.'/cache'))rmdir($dir.'/cache');foreach(glob($dir.'/*') as $p)if(is_file($p))unlink($p);rmdir($dir);
}
