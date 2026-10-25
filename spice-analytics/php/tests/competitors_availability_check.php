<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorParser.php';

function stock_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function stock_page(string $extra = '', string $control = '', string $button = '<button id="button-cart">Купити</button>'): string
{
    $control = $control ?: '<input type="radio" class="product-option-value" name="option[9]" value="201" data-price="110 грн" data-description="500 г" data-min-quantity="1" data-special="0">';
    return '<html><head><meta charset="utf-8"></head><body><main><section class="product" data-product-id="17"><h1>Тестовая специя</h1>'.$extra.$control.$button.'</section></main></body></html>';
}
function stock_parse(string $html): array { return \Lavka\competitors_parse(['kind'=>'product'], $html, 'https://banka-speciy.in.ua/test-spice')['offers']; }
function stock_first(string $html): array { return stock_parse($html)[0]; }
$plain = stock_first(stock_page());
stock_expect($plain['availability']==='unknown' && $plain['availability_basis']==='not_listed', 'No claim about physical stock is invented');
stock_expect(\Lavka\competitors_stock_usable('banka',$plain) && !\Lavka\competitors_stock_usable('spicery',$plain), 'Only the configured source accepts an unlabelled offer');
foreach (['Нет в наличии','Немає в наявності','Out of stock'] as $text) {
    $offer=stock_first(stock_page('<link itemprop="availability" href="https://schema.org/InStock"><div class="stock-status">'.$text.'</div>'));
    stock_expect($offer['availability']==='out_of_stock' && !\Lavka\competitors_stock_usable('banka',$offer), 'Explicit negative overrides positive metadata: '.$text);
}
foreach (['Нет в наличии','Not in stock','Немає в наявності'] as $text) {
    $offer=stock_first(stock_page('<p>'.$text.'</p>'));
    stock_expect($offer['availability']==='out_of_stock', 'Plain primary-product status is recognized: '.$text);
}
$offer=stock_first(stock_page('<div class="product-availability">Під замовлення</div>'));
stock_expect($offer['availability']==='preorder' && !\Lavka\competitors_stock_usable('banka',$offer), 'Preorders remain excluded');
foreach ([str_replace('type="radio"','disabled type="radio"',stock_page()),stock_page('','','<button id="button-cart" disabled>Купити</button>')] as $html) {
    $offer=stock_first($html);
    stock_expect($offer['availability_basis']==='blocked' && !\Lavka\competitors_stock_usable('banka',$offer), 'Disabled purchase never becomes a listed offer');
}
$offer=stock_first(stock_page('','','<button>Повідомити про наявність</button>'));
stock_expect($offer['availability']==='out_of_stock', 'Notify-me is not an enabled purchase');
$offer=stock_first(stock_page('<aside><span class="stock-status">Нет в наличии</span><button disabled>Купити</button></aside>'));
stock_expect($offer['availability_basis']==='not_listed', 'Related products cannot supply primary-product availability');
$offer=stock_first(stock_page('<span class="product-availability">Уточняйте наличие</span>'));
stock_expect($offer['availability_basis']==='unknown' && !\Lavka\competitors_stock_usable('banka',$offer), 'An unresolved status is different from an absent status');
$a='<input type="radio" class="product-option-value" name="option[9]" value="201" data-price="110 грн" data-description="500 г" data-min-quantity="1" data-special="0" data-stock="0">';
$b='<input type="radio" class="product-option-value" name="option[9]" value="202" data-price="40 грн" data-description="100 г" data-min-quantity="1" data-special="0">';
$offers=stock_parse(stock_page('', $a.$b));$byId=[];foreach($offers as $offer)$byId[$offer['source_variant_id']]=$offer;
stock_expect($byId['201']['availability']==='out_of_stock' && $byId['202']['availability_basis']==='not_listed', 'A variant negative never spreads to its sibling');
$labelled=str_replace('data-stock="0"','id="product-size-17-201"',$a).'<label for="product-size-17-201"><span>Нет в наличии</span></label>'.$b;
$byId=[];foreach(stock_parse(stock_page('',$labelled)) as $offer)$byId[$offer['source_variant_id']]=$offer;
stock_expect($byId['201']['availability']==='out_of_stock' && $byId['202']['availability_basis']==='not_listed', 'A linked label applies only to its native option');
$positive=stock_first(stock_page('',str_replace('data-stock="0"','data-stock="2"',$a)));
stock_expect($positive['availability']==='in_stock' && $positive['availability_basis']==='explicit', 'A positive native stock quantity is retained as evidence');
$json=json_encode(['@context'=>'https://schema.org','@type'=>'Product','name'=>'Тестовая специя','offers'=>['@type'=>'Offer','price'=>'110','priceCurrency'=>'UAH','availability'=>'https://schema.org/OutOfStock']],JSON_UNESCAPED_UNICODE);
$control=str_replace('data-stock="0"','checked',$a);
$offer=stock_first(stock_page('<script type="application/ld+json">'.$json.'</script><link itemprop="availability" href="https://schema.org/InStock"><div class="product-price">110 грн</div>',$control));
stock_expect($offer['availability']==='out_of_stock', 'Selected exact-price negative JSON overrides positive page metadata');
$directory=sys_get_temp_dir().'/lavka-stock-'.bin2hex(random_bytes(8));mkdir($directory,0700);
try {
    $db=\Lavka\competitors_db($directory);$now=gmdate('Y-m-d\TH:i:s\Z');
    $legacy=\Lavka\competitors_save_target($db,['source_id'=>'banka','kind'=>'product','url'=>'https://banka-speciy.in.ua/legacy-stock','label'=>'Legacy','match_group'=>'stock-test','match_confirmed'=>true]);
    $fresh=\Lavka\competitors_save_target($db,['source_id'=>'banka','kind'=>'product','url'=>'https://banka-speciy.in.ua/current-stock','label'=>'Current','match_group'=>'stock-test','match_confirmed'=>true]);
    $payload=['kind'=>'product','title'=>'Тестовая специя','offers'=>[$plain],'text'=>'','links'=>[],'warnings'=>[],'method'=>'synthetic'];
    \Lavka\competitors_record_attempt($db,$fresh['id'],$payload,null,$now,200);
    unset($payload['offers'][0]['availability_basis']);
    \Lavka\competitors_record_attempt($db,$legacy['id'],$payload,null,$now,200);
    $before=$db->query('SELECT id,observation,last_success,fingerprint,match_confirmed FROM competitor_targets ORDER BY id')->fetchAll();
    $db->exec("DELETE FROM competitor_migrations WHERE name='availability-evidence-v1'");
    $db=null;$db=\Lavka\competitors_db($directory);
    stock_expect(\Lavka\competitors_target($db,$legacy['id'])['queued'] && !\Lavka\competitors_target($db,$fresh['id'])['queued'], 'Only legacy unknowns are queued for one fresh reading');
    stock_expect($before===$db->query('SELECT id,observation,last_success,fingerprint,match_confirmed FROM competitor_targets ORDER BY id')->fetchAll(), 'Migration preserves evidence, dates, prices and manual matches');
    $db->exec('UPDATE competitor_targets SET queued=0');$db=null;$db=\Lavka\competitors_db($directory);
    stock_expect(!\Lavka\competitors_target($db,$legacy['id'])['queued'], 'Migration never defeats later backoff by requeueing on every request');
    $saved=\Lavka\competitors_target($db,$fresh['id']);
    stock_expect($saved['observation']['offers'][0]['availability_basis']==='not_listed', 'Evidence survives observation validation and storage');
    echo json_encode(['php'=>PHP_VERSION,'source_policy_and_negative_precedence'=>'passed','variant_scope_and_disabled_controls'=>'passed','one_time_recheck_and_evidence_preservation'=>'passed'])."\n";
} finally { $db=null;foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory); }
