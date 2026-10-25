<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/CompetitorParser.php';
function pm_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
// Reconstructed from diagnostic structure, not asserted to be a full saved page.
$html='<main><div class="priceblock"><h1>Кардамон зеленый</h1><span class="available-pr">В наличии</span><ul class="itemPriceList">';
foreach([[25,163],[50,300],[100,429],[200,726],[500,1859]] as $pair)$html.='<li data-price="'.$pair[1].'"><span>'.$pair[0].' г</span><div class="weight-price">'.$pair[1].' грн</div></li>';
$html.='</ul></div><aside><select class="weightSelect"><option data-price="105">25 г</option><option data-price="1386">500 г</option></select></aside></main>';
$result=\Lavka\competitors_parse(['kind'=>'product'],$html,'https://deluxe.com.ua/catalog/spetsii/cardamon.html');
$packs=[];foreach($result['offers'] as $offer)$packs[(int)$offer['grams']]=$offer['price'];
pm_expect($packs[500]==='1859.00'&&$packs[25]==='163.00'&&count($packs)===5,'Primary rows bind exact weights to visible prices; recommendation selects excluded');
$html2=str_replace('data-price="1859"','data-price="1"',$html);$result2=\Lavka\competitors_parse(['kind'=>'product'],$html2,'https://deluxe.com.ua/catalog/spetsii/cardamon.html');
pm_expect(count($result2['offers'])===4,'Conflicting visible and attribute prices are rejected');
$catalog='<main><h1>Нові надходження</h1><div class="catalog-sort"><a href="?sort=p.price">від дешевих до дорогих</a></div><article class="card"><div class="card-info"><a class="card-name link-hover" href="/ua/listya-lavrushki">Лавровий лист</a><label>100 г</label><label>500 г</label></div><div class="card-price">54.00 грн</div></article></main>';
$result=\Lavka\competitors_parse(['kind'=>'catalog'],$catalog,'https://banka-speciy.in.ua/ua/novi-nadhodzhennja');
pm_expect(count($result['links'])===1&&$result['links'][0]['title']==='Лавровий лист','New Banka card-name structure is recognized without capturing sort links');
echo json_encode(['diagnostic_deluxe_rows'=>'passed','diagnostic_banka_catalog'=>'passed','related_and_conflicting_prices_excluded'=>'passed'])."\n";
