<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorTerms.php';

function terms_expect(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function terms_target(int $id,string $source,string $text,string $checked='2026-10-23T10:00:00Z',string $attempt='ok'): array
{
    return ['id'=>$id,'source_id'=>$source,'kind'=>'terms','enabled'=>true,
        'url'=>\Lavka\competitors_source($source)['base_url'].'/terms-'.$id,
        'last_success'=>$checked,'last_attempt'=>['state'=>$attempt,'checked_at'=>'2026-10-23T10:05:00Z'],
        'observation'=>$text===''?null:['kind'=>'terms','text'=>$text]];
}
function terms_row(array $matrix,string $id): array
{
    foreach ($matrix['rows'] as $row) if ($row['id']===$id) return $row;
    throw new RuntimeException('Missing row '.$id);
}
function terms_facts(string $text,string $row): array
{
    return array_values(array_filter(\Lavka\competitors_terms_extract($text),function($f)use($row){return $f['row']===$row;}));
}

$now='2026-10-23T11:00:00Z';
$pages=[
    terms_target(1,'lavka',"Минимальный заказ — 300 грн.\nБесплатная доставка Новой почтой в отделение от 2 500 грн.\nОплата картой или наложенным платежом.\nОтправляем в течение 1–2 рабочих дней.\nСкидка 10% на первый заказ за подписку на рассылку.\nПостоянная скидка 5% при заказе от 2000 грн.\nАкция на паприку до конца месяца, кроме оптовых заказов.\nВозврат и обмен: уточните исключения у менеджера."),
    terms_target(2,'deluxe',"Минимальный заказ — 200 грн.\nБесплатная доставка Новой почтой в отделение от 2000 грн."),
    terms_target(3,'spicery',"Минимальный заказ — 100 грн.\nБесплатная доставка Новой почтой в отделение от 1500 грн.",'2026-10-20T10:00:00Z','error'),
    terms_target(4,'banka','Минимальный заказ — 400 грн.'),
    terms_target(5,'banka','Минимальный заказ — 450 грн.'),
    terms_target(6,'specii',''),
];
$matrix=\Lavka\competitors_terms_comparison($pages,$now);
terms_expect($matrix['version']===1 && count($matrix['rows'])===9,'Stable comparison contract');
$min=terms_row($matrix,'minimum_order');
terms_expect($min['cells']['lavka']['value']===300.0 && $min['cells']['lavka']['state']==='fresh','Explicit minimum order');
terms_expect($min['best_source_ids']===['deluxe'],'Only comparable fresh sums can lead');
terms_expect($min['cells']['spicery']['value']===100.0 && $min['cells']['spicery']['state']==='error','Failed latest check retains prior amount, excluded from leader');
terms_expect($min['cells']['banka']['state']==='conflict' && $min['cells']['banka']['value']===null,'Conflicting same-scope fresh pages are not silently minimized');
terms_expect($min['cells']['specii']['state']==='unknown' && $min['cells']['specii']['value']===null,'First check is unknown, never zero');
$free=terms_row($matrix,'free_delivery_np_branch');
terms_expect($free['cells']['lavka']['value']===2500.0 && $free['best_source_ids']===['deluxe'],'Same carrier branch thresholds are compared');
terms_expect(strpos($free['cells']['lavka']['evidence'][0]['quote'],'2 500')!==false && $free['cells']['lavka']['evidence'][0]['target_id']===1,'Evidence has literal quote, URL, target and success date');
terms_expect($free['cells']['lavka']['evidence'][0]['checked_at']==='2026-10-23T10:00:00Z','Evidence date uses successful snapshot');
terms_expect(strpos(terms_row($matrix,'payment_methods')['cells']['lavka']['display'],'При получении')!==false,'Payment methods parsed');
terms_expect(strpos(terms_row($matrix,'first_order_discount')['cells']['lavka']['display'],'подписку')!==false,'Subscription condition retained with first-order discount');
terms_expect(terms_row($matrix,'regular_discounts')['best_source_ids']===[],'Different discount eligibility is not ranked');
terms_expect(strpos(terms_row($matrix,'dispatch')['cells']['lavka']['display'],'рабочих')!==false,'Dispatch conditions retained');
terms_expect(terms_row($matrix,'returns')['cells']['lavka']['state']==='fresh','Return conditions presented with evidence');

// A full own catalogue sorts before terms in the real overview payload.
$catalogue=[];for($i=0;$i<416;++$i)$catalogue[]=['id'=>1000+$i,'source_id'=>'lavka','kind'=>'product','enabled'=>true];
$large=\Lavka\competitors_terms_comparison(array_merge($catalogue,$pages),$now);
terms_expect(terms_row($large,'minimum_order')['cells']['lavka']['value']===300.0,'416 products must not hide an existing own terms source');
terms_expect(terms_row($large,'minimum_order')['cells']['deluxe']['value']===200.0,'Full catalogue must not hide competitor conditions');

$stale=terms_target(7,'deluxe','Минимальный заказ — 50 грн.','2026-10-20T10:00:00Z');
$noLeader=\Lavka\competitors_terms_comparison([terms_target(1,'lavka','Минимальный заказ — 300 грн.'),$stale],$now);
terms_expect(terms_row($noLeader,'minimum_order')['cells']['deluxe']['state']==='stale','Successful old snapshot is stale');
terms_expect(terms_row($noLeader,'minimum_order')['best_source_ids']===[],'A single fresh source is not declared best');
$future=terms_target(8,'banka','Минимальный заказ — 1 грн.','2026-11-01T10:00:00Z');
terms_expect(terms_row(\Lavka\competitors_terms_comparison([$future],$now),'minimum_order')['cells']['banka']['state']==='unknown','Future snapshot does not establish fresh fact');
$disabled=terms_target(9,'lavka','Минимальный заказ — 1 грн.'); $disabled['enabled']=false;
terms_expect(terms_row(\Lavka\competitors_terms_comparison([$disabled],$now),'minimum_order')['cells']['lavka']['value']===null,'Archived page excluded');

$unsafeShipping=[
    'Бесплатная доставка курьером по Киеву от 1000 грн.',
    'Бесплатная доставка Новой почтой от 1000 грн.',
    'Бесплатная доставка Укрпочтой в отделение от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение в Житомире от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение для постоянных клиентов от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение при оплате картой от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение по промокоду от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение на первый заказ от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение при 100% предоплате от 1000 грн.',
    'Доставка Новой почтой в отделение не бесплатная при заказе от 1000 грн.',
    'Бесплатная доставка Новой почтой в отделение свыше 1000 грн.',
    'Минимальный заказ от 200 грн, бесплатная доставка Новой почтой в отделение от 2000 грн.',
];
foreach ($unsafeShipping as $text) terms_expect(terms_facts($text,'free_delivery_np_branch')===[],'Do not rank restricted or ambiguous shipping: '.$text);
terms_expect(terms_facts('Безкоштовна доставка Новою поштою у відділення при замовленні від 2 000 грн.','free_delivery_np_branch')[0]['value']===2000.0,'Ordinary Ukrainian branch threshold');
terms_expect(terms_facts('Бесплатная доставка Новой почтой в отделение на любую сумму.','free_delivery_np_branch')[0]['value']===0.0,'Explicit unconditional free delivery may be zero');
terms_expect(terms_facts('Минімальна сума замовлення 200 грн.','minimum_order')===[],'Do not infer typo or near match as known condition');
terms_expect(terms_facts('Мінімальна сума замовлення становить 200 грн.','minimum_order')[0]['value']===200.0,'Explicit Ukrainian minimum');
terms_expect(terms_facts('Минимальный заказ не менее 200 грн.','minimum_order')[0]['value']===200.0,'Explicit inclusive lower bound');
terms_expect(terms_facts('Минимальный заказ до 200 грн.','minimum_order')===[],'Do not invert an upper bound');
terms_expect(terms_facts('Минимальный заказ не 200 грн.','minimum_order')===[],'Negated amount is not minimum');
terms_expect(terms_facts('Минимальный оптовый заказ 200 грн.','minimum_order')===[],'Wholesale condition excluded');
terms_expect(terms_facts('Минимальный заказ 200 грн для постоянных клиентов.','minimum_order')===[],'Conditional buyer-class minimum excluded');
terms_expect(terms_facts('Без минимальной суммы заказа.','minimum_order')[0]['value']===0.0,'Explicit absence of minimum is zero, not unknown');
foreach (['Ранее минимальный заказ 200 грн.','Отменён минимальный заказ 200 грн.','Не установлен минимальный заказ 200 грн.'] as $text) {
    terms_expect(terms_facts($text,'minimum_order')===[],'Historical/abolished/negated minimum is not current: '.$text);
}
$splitDiscount=terms_facts("Скидка 10% на первый заказ.\nТолько за подписку на рассылку.\nНе суммируется с акциями.\nМинимальный заказ 200 грн.",'first_order_discount');
terms_expect(count($splitDiscount)===1 && strpos($splitDiscount[0]['quote'],'подписку')!==false && strpos($splitDiscount[0]['quote'],'Не суммируется')!==false,'Adjacent subscription and combination restrictions remain attached to first-order offer');
terms_expect(strpos($splitDiscount[0]['quote'],'Минимальный заказ')===false,'Another terms heading is not swallowed as a discount restriction');
$tierDiscount=terms_facts("Скидка 5% при сумме заказа от 2000 грн.\nСкидка 10% при сумме прошлых покупок от 15000 грн.",'regular_discounts');
terms_expect(count($tierDiscount)===2 && strpos($tierDiscount[1]['quote'],'прошлых покупок')!==false,'Order threshold and accumulated purchase threshold remain distinct literal conditions');
foreach (['В Киеве минимальный заказ 200 грн.','Для подписчиков минимальный заказ 200 грн.','Для киевлян без минимальной суммы заказа.','Без минимальной суммы заказа в Киеве.'] as $text) {
    terms_expect(terms_facts($text,'minimum_order')===[],'Scoped prefix or suffix cannot become ordinary nationwide minimum: '.$text);
}
terms_expect(terms_facts('Мінімальна сума замовлення відсутня.','minimum_order')[0]['value']===0.0,'Whole explicit Ukrainian no-minimum clause remains supported');
$cash=terms_facts('Оплата безготівковим переказом на рахунок.','payment_methods');
terms_expect(array_column($cash,'value')===['На счёт'],'Cashless payment never becomes cash');
terms_expect(terms_facts('Наложенным платежом не отправляем.','payment_methods')===[],'Negated payment method excluded');

// Overview integration reads local saved observations only. No MySQL or HTTP.
$dir=sys_get_temp_dir().'/lavka-terms-test-'.bin2hex(random_bytes(5));
mkdir($dir,0700);
try {
    $db=\Lavka\competitors_db($dir);
    $overview=\Lavka\competitors_overview($db);
    terms_expect(isset($overview['terms_comparison']) && count($overview['terms_comparison']['rows'])===9,'Overview always supplies matrix including unknown sources');
    $db=null;
} finally {
    foreach (glob($dir.'/*')?:[] as $file) unlink($file);
    rmdir($dir);
}
echo \Lavka\encode(['structured_terms'=>'passed','evidence_and_freshness'=>'passed','scope_and_negation'=>'passed','conflict_and_unknown'=>'passed','local_overview'=>'passed','real_network_requests'=>0])."\n";
