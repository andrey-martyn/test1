<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';

function replace_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function replace_reject(callable $call): void {
    try { $call(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Invalid replacement was accepted');
}
function replacement_watch(PDO $db, string $suffix, string $group='lavka-test'): array {
    return \Lavka\competitors_save_target($db,['source_id'=>'banka','kind'=>'product','url'=>'https://banka-speciy.in.ua/'.$suffix,
        'label'=>'Прежняя смесь в банке','match_group'=>$group,'match_confirmed'=>true,'grams_override'=>450,'pack_label'=>'500 г','packaging'=>'zip']);
}
function replacement_observation(): array {
    return ['kind'=>'product','title'=>'Прежняя смесь в банке','offers'=>[['key'=>'450','label'=>'450 г','price'=>'71','currency'=>'UAH','grams'=>450,'unit'=>'pack','min_quantity'=>1,'condition'=>'','availability'=>'in_stock']],
        'text'=>'','links'=>[],'warnings'=>[],'method'=>'synthetic'];
}
$directory=sys_get_temp_dir().'/lavka-replace-'.bin2hex(random_bytes(8));mkdir($directory,0700);$db=null;
try {
    $db=\Lavka\competitors_db($directory);$old=replacement_watch($db,'wrong-mixture');
    \Lavka\competitors_record_attempt($db,$old['id'],replacement_observation(),null,gmdate('Y-m-d\TH:i:s\Z'),200);
    $before=\Lavka\competitors_history($db,$old['id']);
    $allBefore=$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll();
    foreach ([['url'=>$old['url']],['url'=>'https://other.invalid/product'],['url'=>'http://banka-speciy.in.ua/spice'],['url'=>'https://banka-speciy.in.ua/right','label'=>str_repeat('x',170)],
        ['url'=>'https://banka-speciy.in.ua/right','match_confirmed'=>true]] as $body) {
        replace_reject(function()use($db,$old,$body){\Lavka\competitors_replace_target($db,$old['id'],$body);});
        replace_expect($allBefore===$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll(), 'Validation and mid-operation failures roll back all target changes');
    }
    $saved=\Lavka\competitors_replace_target($db,$old['id'],['url'=>'https://banka-speciy.in.ua/right-spice','label'=>'Правильная специя']);$new=$saved['target'];
    replace_expect($new['id']!==$old['id'] && $new['enabled'] && $new['queued'] && !$new['match_confirmed'] && $new['match_method']==='unreviewed','Fresh URL has its own identity and can use automatic matching');
    replace_expect($new['observation']===null && $new['last_success']===null && $new['grams_override']===null,'Wrong-page price, reading date and weight override are never transferred');
    replace_expect($new['match_group']===$old['match_group'] && $new['packaging']==='zip' && $new['pack_label']==='500 г','Comparison group and requested packaging/pack preference are retained');
    $archived=\Lavka\competitors_history($db,$old['id']);
    replace_expect(!$archived['target']['enabled'] && !$archived['target']['queued'] && $archived['target']['url']===$old['url'],'Wrong URL is archived, never overwritten');
    replace_expect($before['observations']===$archived['observations'] && $before['attempts']===$archived['attempts'] && $before['target']['observation']===$archived['target']['observation'],'Original history and price payloads are unchanged');
    replace_expect($archived['replacements'][0]['target_id']===$new['id'] && \Lavka\competitors_history($db,$new['id'])['replacements'][0]['target_id']===$old['id'],'History links connect the two independent pages');
    $count=(int)$db->query('SELECT count(*) FROM competitor_targets')->fetchColumn();
    $again=\Lavka\competitors_replace_target($db,$old['id'],['url'=>$new['url']]);
    replace_expect($again['target']['id']===$new['id'] && $count===(int)$db->query('SELECT count(*) FROM competitor_targets')->fetchColumn(),'A retried request is idempotent');
    replace_reject(function()use($db,$old){\Lavka\competitors_replace_target($db,$old['id'],['url'=>'https://banka-speciy.in.ua/another']);});
    $second=replacement_watch($db,'wrong-second');
    $reuse=\Lavka\competitors_replace_target($db,$second['id'],['url'=>$new['url']]);
    replace_expect($reuse['reused'] && $reuse['target']['id']===$new['id'] && !$reuse['target']['match_confirmed'],'An existing same-group URL is reused without inheriting another page confirmation');
    $different=replacement_watch($db,'different-group','other-group');$third=replacement_watch($db,'wrong-third');
    replace_reject(function()use($db,$third,$different){\Lavka\competitors_replace_target($db,$third['id'],['url'=>$different['url']]);});
    replace_expect(\Lavka\competitors_target($db,$third['id'])['enabled'] && \Lavka\competitors_target($db,$different['id'])['match_group']==='other-group','A different-group collision never archives or moves either target');
    $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999991']);
    replace_reject(function()use($db,$own){\Lavka\competitors_replace_target($db,$own['id'],['url'=>'https://lavka-speciy.com/another']);});
    echo json_encode(['php'=>PHP_VERSION,'replacement_atomicity_and_validation'=>'passed','independent_history_and_no_price_transfer'=>'passed','idempotency_and_same_group_reuse'=>'passed'])."\n";
} finally { $db=null;foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory); }
