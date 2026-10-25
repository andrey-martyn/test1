<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/CompetitorParser.php';
function ce_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ce_rejects(callable $callback):void{try{$callback();}catch(\Lavka\CompetitorParseError $e){return;}throw new RuntimeException('Unconfirmed price was accepted');}
function ce_page(string $body,array $json):string{return '<html><head><meta charset="utf-8"><script type="application/ld+json">'.\Lavka\encode($json).'</script></head><body><main>'.$body.'</main></body></html>';}
// Reconstructed public DOM and normalized schema shape from the targeted report.
$deluxeUrl='https://deluxe.com.ua/catalog/spetsii/cardamon.html';
$body='<div class="priceblock"><h1>Кардамон зеленый</h1><span class="available-pr">В наличии</span><ul class="itemPriceList">';
$json=['@type'=>'Product','name'=>'Кардамон зеленый','url'=>$deluxeUrl,'offers'=>[]];
foreach([[2055,25,163,173],[831,50,300,319],[23,100,429,474],[180,200,726,952],[26,500,1859,2077]] as $v){
 $body.='<li data-price-id="'.$v[0].'" data-price="'.$v[2].'" data-price-new="'.$v[3].'"><div class="weight-amount">'.$v[1].' г</div><div class="weight-price">'.$v[2].' грн</div></li>';
 $json['offers'][]=['@type'=>'Offer','price'=>$v[2],'priceCurrency'=>'UAH','availability'=>'https://schema.org/InStock','eligibleQuantity'=>['@type'=>'QuantitativeValue','minValue'=>1,'unitCode'=>'GRM']];
}
$body.='</ul></div><aside><select class="weightSelect"><option data-price="1">500 г</option></select></aside>';
$parsed=\Lavka\competitors_parse(['kind'=>'product'],ce_page($body,$json),$deluxeUrl);
$weights=[];foreach($parsed['offers'] as $offer){$weights[(int)$offer['grams']]=$offer['price'];ce_expect($offer['old_price']===null,'Auxiliary attribute is not advertised as a discount');}
ce_expect(count($weights)===5&&$weights[25]==='163.00'&&$weights[50]==='300.00'&&$weights[100]==='429.00'&&$weights[200]==='726.00'&&$weights[500]==='1859.00','All five visible exact pack prices survive auxiliary-price guard');
ce_expect($parsed['method']==='deluxe_variant_prices','Native exact-weight parsing is retained');
$conflict=$json;$conflict['offers'][0]['price']=173;
$partial=\Lavka\competitors_parse(['kind'=>'product'],ce_page($body,$conflict),$deluxeUrl);
ce_expect(count($partial['offers'])===4,'Schema price must agree with the displayed current price');
$foreign=$json;$foreign['url']='https://deluxe.com.ua/catalog/spetsii/other.html';
ce_rejects(function()use($body,$foreign,$deluxeUrl){\Lavka\competitors_parse(['kind'=>'product'],ce_page($body,$foreign),$deluxeUrl);});
$related=$json;$related['name']='Другой товар';
ce_rejects(function()use($body,$related,$deluxeUrl){\Lavka\competitors_parse(['kind'=>'product'],ce_page($body,$related),$deluxeUrl);});
ce_rejects(function()use($body,$deluxeUrl){\Lavka\competitors_parse(['kind'=>'product'],ce_page($body,[]),$deluxeUrl);});
$bankaUrl='https://banka-speciy.in.ua/ua/paprika-copchenaya-molotaya';
$banka='<section class="product" data-product-id="305"><h1>Паприка мелена копчена</h1><div class="product-price">34.00 грн</div>';
foreach([[952,100,34],[951,250,85],[950,500,170]] as $i=>$v)$banka.='<input type="radio" class="product-option-value" '.($i===0?'checked':'').' id="product-size-305-'.$v[0].'" name="option[9]" value="'.$v[0].'" data-price="'.$v[2].' грн" data-description="'.$v[1].' г" data-name="'.$v[1].' г" data-min-quantity="1">';
$banka.='</section>';
$bankaJson=['@type'=>'Product','name'=>'Паприка мелена копчена','url'=>$bankaUrl,'offers'=>['@type'=>'Offer','price'=>'34','priceCurrency'=>'UAH','availability'=>'https://schema.org/InStock']];
$read=function(string $body,array $schema)use($bankaUrl):array{$p=\Lavka\competitors_parse(['kind'=>'product'],ce_page($body,$schema),$bankaUrl);$out=[];foreach($p['offers'] as $o)$out[$o['source_variant_id']]=$o;return $out;};
$offers=$read($banka,$bankaJson);
ce_expect($offers[952]['availability']==='in_stock'&&$offers[951]['availability']==='unknown'&&$offers[950]['availability']==='unknown','JSON stock belongs only to the selected pack with the same visible price');
ce_expect($offers[952]['price']==='34.00'&&$offers[951]['price']==='85.00','Stock enrichment never replaces native pack prices');
foreach(['url'=>'https://banka-speciy.in.ua/ua/other','name'=>'Другая паприка'] as $key=>$value){$bad=$bankaJson;$bad[$key]=$value;ce_expect($read($banka,$bad)[952]['availability']==='unknown','Wrong primary identity cannot confirm stock');}
foreach(['price'=>'35','priceCurrency'=>'USD','eligibleCustomerType'=>'members'] as $key=>$value){$bad=$bankaJson;$bad['offers'][$key]=$value;ce_expect($read($banka,$bad)[952]['availability']==='unknown','Different price, currency or eligibility cannot confirm retail stock');}
$wrongWeight=$bankaJson;$wrongWeight['offers']['weight']=['value'=>250,'unitCode'=>'GRM'];ce_expect($read($banka,$wrongWeight)[952]['availability']==='unknown','Other mass cannot confirm selected pack stock');
ce_expect($read(str_replace('checked','',$banka),$bankaJson)[952]['availability']==='unknown','No selected pack means no stock inheritance');
ce_expect($read(str_replace('checked','checked disabled',$banka),$bankaJson)[952]['availability']==='unknown','Disabled pack does not become available');
ce_expect($read(str_replace('34.00 грн','35.00 грн',$banka),$bankaJson)[952]['availability']==='unknown','Page price and selected native option must agree');
$multiple=str_replace('value="951"','checked value="951"',$banka);ce_expect($read($multiple,$bankaJson)[952]['availability']==='unknown','Ambiguous selected option does not inherit stock');
$out=$bankaJson;$out['offers']['availability']='https://schema.org/OutOfStock';ce_expect($read($banka,$out)[952]['availability']==='out_of_stock','Explicit negative stock survives');
$conflicted=$bankaJson;$conflicted['offers']=[$bankaJson['offers'],$out['offers']];ce_expect($read($banka,$conflicted)[952]['availability']==='unknown','Contradictory matched structured stock stays unknown');
ce_expect($read($banka.'<button>Купити</button>',[])[952]['availability']==='unknown','Buy button alone still never establishes stock');
echo \Lavka\encode(['deluxe_auxiliary_price_evidence'=>'passed','banka_selected_pack_stock'=>'passed','related_conflicting_and_unselected_evidence_rejected'=>'passed','external_requests'=>0])."\n";
