<?php

declare(strict_types=1);
define('LAVKA_IDENTITY_REVIEW_FIXTURE_ONLY',true);
require_once __DIR__.'/identity_review_check.php';
function nt_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function nt_fixture():array{
    $rows=ir_fixture();$rows['oc_order']['7']['customer_id']='22';$rows['oc_order']['7']['currency_code']='USD';
    $rows['oc_order']['8']['firstname']='Северная';$rows['oc_order']['8']['lastname']='Анна';
    foreach([['21','Рябова','Юлия'],['22','Коваленко','Юлия'],['23','Юлия','Юлия'],['24','Юлия','Гончаренко'],['25','Юлия','Рябова'],['30','Будущая','Запись']] as $person){
        [$id,$first,$last]=$person;$o=$rows['oc_order']['1'];$o['order_id']=$id;$o['customer_id']=(string)(200+(int)$id);$o['firstname']=$first;$o['lastname']=$last;$o['email']='synthetic'.$id.'@example.invalid';$o['telephone']='+38097123'.str_pad($id,4,'0',STR_PAD_LEFT);$o['date_added']=($id==='30'?'2027-01-01':'2026-01-01').' 12:00:00';$o['date_modified']=$o['date_added'];$rows['oc_order'][$id]=$o;
        $line=$rows['oc_order_product']['1'];$line['order_product_id']=$id;$line['order_id']=$id;$rows['oc_order_product'][$id]=$line;
    }
    $extra=$rows['oc_order']['2'];$extra['order_id']='50';$extra['firstname']='Анна';$extra['lastname']='Северная';$extra['email']='alternate@example.invalid';$extra['telephone']='+380971235050';$extra['date_added']='2026-08-01 12:00:00';$extra['date_modified']=$extra['date_added'];$rows['oc_order']['50']=$extra;
    $line=$rows['oc_order_product']['1'];$line['order_product_id']='50';$line['order_id']='50';$rows['oc_order_product']['50']=$line;
    return $rows;
}
if(defined('LAVKA_NAME_TRACE_FIXTURE_ONLY'))return;
$dir=sys_get_temp_dir().'/identity-name-trace-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try{
    foreach(['Юлия','Юлія','Юля','Julia','Yuliia','Ирина','Андрей','Андрій','Саша','Олег','Мария','Анна-Марія','Юлия Юлия'] as $name)nt_check(\Lavka\identity_surname_key($name)==='','Given names are not surname evidence');
    foreach([
        ['Юлия','Рябова','Рябова'],['Рябова','Юлия','Рябова'],['Рябова Юлия','','Рябова'],['Юлия Рябова','','Рябова'],
        ['Юлия','Рябова Юлия','Рябова'],['Юлия Рябова','Юлия','Рябова'],['Юлия Юлия','',''],['Юлия','Юлия',''],['','Юлия',''],
        ['Юлия Владимировна','',''],['Юлия Владимировна Рябова','','Рябова'],['Рябова Юлия Владимировна','','Рябова'],['Lan','Nguyen','Nguyen'],['Юлия','Петрович','Петрович']
    ] as $case)nt_check(\Lavka\identity_order_surname(['firstname'=>$case[0],'lastname'=>$case[1]])===$case[2],'Swapped/combined name parsing: '.implode('|',$case));
    nt_check(\Lavka\identity_suggestion_name('Юлия Юлия')===''&&\Lavka\identity_suggestion_name('Юлия Владимировна')==='','A repeated first name or patronymic is not a full-name match');
    $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,nt_fixture(),'test');
    foreach([['7','8'],['3','4'],['3','9']] as $pair)$db->prepare('INSERT INTO manual_links VALUES(?,?,?)')->execute([$pair[0],$pair[1],'2026-10-20']);\Lavka\bump($db);
    $report=(new \Lavka\Analytics($db,$dir))->report(0,'2026-10-27','UAH','all',false);$pairs=array_column($report['duplicate_pairs'],null,'id');
    foreach($pairs as $pair)foreach($pair['matches'] as $match)nt_check($match['kind']!=='surname'||$match['value']!=='юлия','Julia never appears as a shared surname, including historical aliases');
    nt_check(isset($pairs['21:25']),'Real surname survives swapped fields');
    foreach(['21:22','21:23','21:24','22:23','22:24'] as $key)nt_check(!isset($pairs[$key]),'A shared first name generates no unrelated pairs');
    nt_check(in_array('name',$pairs['21:25']['kinds'],true)&&$pairs['21:25']['strength']==='name','Swapped full names are the same full name, not merely a surname');
    nt_check(\Lavka\identity_suggestion_name('Юлия Владимировна Рябова')===\Lavka\identity_suggestion_name('Рябова Юлия Владимировна')&&\Lavka\identity_suggestion_name('Юлия Владимировна Рябова')!==\Lavka\identity_suggestion_name('Юлия Петровна Рябова'),'Canonical order retains distinguishing patronymics');
    $cards=array_column($report['customers'],null,'id');nt_check(in_array('Рябова',$cards['21']['surnames'],true)&&!in_array('Юлия',$cards['21']['surnames'],true),'Report and CRM share corrected surname evidence');
    \Lavka\identity_review_pairs($db,$dir,[['5','6']],'2026-10-27','UAH');
    $report=(new \Lavka\Analytics($db,$dir))->report(0,'2026-10-27','UAH','all',false);
    $before=hash_file('sha256',$dir.'/analytics.sqlite3');$review=hash_file('sha256',$dir.'/identity-reviews.json');$links=$db->query('SELECT * FROM manual_links ORDER BY a,b')->fetchAll();
    $trace=\Lavka\identity_trace($db,$dir,'1','2','2026-10-27','UAH',$report['identity_revision']);
    nt_check($trace['read_only']&&!$trace['existing_links_changed']&&$trace['basis']==='current_saved_links','Trace is explicitly a read-only reconstruction');
    nt_check(count($trace['variants'])===2&&$trace['variants'][0]['matches'][0]['kind']==='phone','Distinct source pairs produce deterministic, separately inspectable variants');
    $variant=$trace['variants'][0];$right=$trace['paths'][$variant['right_path']];
    nt_check(array_column($right['nodes'],'id')===['2','7','8'],'Path follows the real transitive component from displayed contact to source');
    nt_check($right['edges'][0]['reasons'][0]['kind']==='account'&&$right['edges'][0]['reasons'][0]['account_id']==='22','Automatic link uses the actual shared account');
    nt_check($right['edges'][1]['reasons'][0]['kind']==='manual'&&$right['edges'][1]['reasons'][0]['created_at']==='2026-10-20','Manual link shows its persisted pair and date');
    nt_check($right['nodes'][1]['outside_sales_scope']&&$right['nodes'][2]['surname']==='Северная'&&$right['nodes'][2]['name_fields_adjusted'],'Foreign-currency bridge and corrected swapped fields are labelled');
    nt_check($right['manual_edges']===1&&count($trace['paths'][$variant['left_path']]['edges'])===0,'Direct current evidence is not fabricated as a prior merge');
    nt_check(strpos(\Lavka\encode($trace),'Будущая')===false,'Future orders stay out of the graph');
    foreach([['1','2','stale'],['1','3',$report['identity_revision']]] as $bad){try{\Lavka\identity_trace($db,$dir,$bad[0],$bad[1],'2026-10-27','UAH',$bad[2]);throw new RuntimeException('Stale/unrelated trace accepted');}catch(\Lavka\HttpError $e){nt_check($e->status===409,'Stale explanations fail clearly');}}
    nt_check($trace['review_state']==='pending','Pending suggestion is labelled separately from saved merges');
    $dismissedTrace=\Lavka\identity_trace($db,$dir,'5','6','2026-10-27','UAH',$report['identity_revision']);
    nt_check($dismissedTrace['review_state']==='different_customers','Reviewing a dismissed pair does not present it as a new suggestion');
    nt_check(hash_file('sha256',$dir.'/analytics.sqlite3')===$before&&hash_file('sha256',$dir.'/identity-reviews.json')===$review&&$db->query('SELECT * FROM manual_links ORDER BY a,b')->fetchAll()===$links,'Viewing chains never changes one byte of existing work or review decisions');
    echo \Lavka\encode(['given_names_vs_surnames'=>'passed','swapped_and_combined_fields'=>'passed','repeated_first_names'=>'not_evidence','real_transitive_paths'=>'passed','manual_dates_and_automatic_edges'=>'passed','existing_database_and_reviews'=>'byte_unchanged','php'=>PHP_VERSION])."\n";
}finally{$db=null;foreach(glob($dir.'/*') as $p)if(is_file($p))unlink($p);rmdir($dir);}
