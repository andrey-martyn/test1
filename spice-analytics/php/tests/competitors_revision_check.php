<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
function revision_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$directory=sys_get_temp_dir().'/lavka-revision-'.bin2hex(random_bytes(8));mkdir($directory,0700);$db=null;
try {
    $db=\Lavka\competitors_db($directory);
    $target=\Lavka\competitors_save_target($db,['source_id'=>'specii','kind'=>'product','url'=>'https://www.specii.in.ua/synthetic-revision','label'=>'Синтетическая специя','match_group'=>'revision-test','match_confirmed'=>true]);
    $before=$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll();
    $revision=\Lavka\competitors_revision($db,$directory);
    revision_expect((bool)preg_match('/^[a-f0-9]{64}$/',$revision['revision']), 'Only an opaque revision is returned');
    revision_expect($revision['revision']===\Lavka\competitors_revision($db,$directory)['revision'], 'An unchanged snapshot has a stable revision');
    revision_expect($before===$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll(), 'Polling never queues or modifies targets');
    \Lavka\competitors_record_attempt($db,$target['id'],null,'variant_prices_unavailable','2026-10-26T10:00:00Z',200);
    $failed=\Lavka\competitors_revision($db,$directory)['revision'];
    revision_expect($failed!==$revision['revision'], 'An unsuccessful attempt changes the revision');
    \Lavka\competitors_queue($db,$target['id']);
    $queued=\Lavka\competitors_revision($db,$directory)['revision'];
    revision_expect($queued!==$failed, 'Queueing a saved error changes the revision');
    $observation=['kind'=>'product','title'=>'Синтетическая специя','offers'=>[['key'=>'500','label'=>'500 г','price'=>'125','currency'=>'UAH','grams'=>500,'unit'=>'pack','availability'=>'in_stock','min_quantity'=>1,'condition'=>'']],'text'=>'','links'=>[],'warnings'=>[],'method'=>'synthetic'];
    \Lavka\competitors_record_attempt($db,$target['id'],$observation,null,'2026-10-26T10:01:00Z',200);
    $success=\Lavka\competitors_revision($db,$directory)['revision'];
    revision_expect($success!==$queued, 'A successful reading changes the revision');
    file_put_contents($directory.'/own-catalog.json','{}');
    revision_expect(\Lavka\competitors_revision($db,$directory)['revision']!==$success, 'A new own-catalog snapshot invalidates the table');
    file_put_contents($directory.'/own-catalog.json','{"price":"100"}');$catalog=\Lavka\competitors_revision($db,$directory)['revision'];
    $mtime=filemtime($directory.'/own-catalog.json');
    file_put_contents($directory.'/own-catalog.json','{"price":"200"}');touch($directory.'/own-catalog.json',$mtime);
    revision_expect(\Lavka\competitors_revision($db,$directory)['revision']!==$catalog, 'Same-size catalogue updates within the same second are detected');
    echo json_encode(['php'=>PHP_VERSION,'read_only_revision_and_queue_error_success_transitions'=>'passed'])."\n";
} finally { $db=null;foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory); }
