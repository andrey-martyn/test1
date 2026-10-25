<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorDiscovery.php';
$directory=sys_get_temp_dir().'/lavka-concentration-'.bin2hex(random_bytes(5));\Lavka\private_dir($directory);
try{
 $db=\Lavka\competitors_db($directory);$db->exec('UPDATE competitor_targets SET enabled=0');
 $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999000','label'=>'Асафетида','match_group'=>'lavka-999000']);
 \Lavka\competitors_index_link($db,'spicery','https://spiceryshop.com.ua/content/products/asafetida-15','Асафетида 15%','page');
 $candidate=(int)$db->query("SELECT id FROM competitor_search_index WHERE source_id='spicery'")->fetchColumn();
 $db->prepare('INSERT INTO competitor_suggestions VALUES(?,?,100)')->execute([$own['id'],$candidate]);
 $result=\Lavka\competitors_discovery_results($db);
 if(count($result['rows'])!==1||$result['rows'][0]['score']!==55||$result['rows'][0]['identity']['exact'])throw new RuntimeException('Old stored 100% must not override the current concentration check');
 echo json_encode(['legacy_confidence_recalculated'=>'passed','external_requests'=>0])."\n";
}finally{
 $db=null;foreach(scandir($directory) as $name)if($name!=='.'&&$name!=='..')unlink($directory.'/'.$name);rmdir($directory);
}
