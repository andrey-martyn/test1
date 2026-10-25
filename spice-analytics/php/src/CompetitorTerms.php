<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

/**
 * A read-only projection of saved public terms. Numbers are not inferred from
 * source notes, product cards, another carrier, or a failed/old observation.
 */
function competitors_terms_rows(): array
{
    return [
        ['id'=>'minimum_order','label'=>'Минимальный заказ','description'=>'Сумма обычного розничного заказа. Ниже — доступнее для покупателя.','unit'=>'UAH','rank_direction'=>'min'],
        ['id'=>'free_delivery_np_branch','label'=>'Бесплатная доставка','description'=>'Новая почта · в отделение · обычный заказ. Сравниваем только явно указанный порог.','unit'=>'UAH','rank_direction'=>'min'],
        ['id'=>'dispatch','label'=>'Когда отправляют','description'=>'Срок отправки, а не срок прибытия посылки.','unit'=>null,'rank_direction'=>null],
        ['id'=>'delivery_methods','label'=>'Способы доставки','description'=>'Перевозчики, курьер и самовывоз — по сохранённым условиям.','unit'=>null,'rank_direction'=>null],
        ['id'=>'payment_methods','label'=>'Способы оплаты','description'=>'Показываем доступные способы; комиссию и ограничения проверяем в источнике.','unit'=>null,'rank_direction'=>null],
        ['id'=>'first_order_discount','label'=>'Первый заказ','description'=>'Скидка новому покупателю вместе с условиями её получения.','unit'=>null,'rank_direction'=>null],
        ['id'=>'regular_discounts','label'=>'Скидки и лояльность','description'=>'Порог заказа, накопления, подписка и другие условия сохраняются. Проценты разных программ не объявляем общим победителем.','unit'=>null,'rank_direction'=>null],
        ['id'=>'promotions','label'=>'Акции','description'=>'Действие акции и исключения нужно сверять по ссылке. Общего рейтинга акций нет.','unit'=>null,'rank_direction'=>null],
        ['id'=>'returns','label'=>'Возврат и обмен','description'=>'Публичные условия магазина; исключения важны.','unit'=>null,'rank_direction'=>null],
    ];
}

function competitors_terms_clean(string $value,int $limit=600): string
{
    $value=html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $value=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',' ',$value);
    $value=preg_replace('/[\p{Z}\s]+/u',' ',$value);
    return mb_substr(trim($value),0,$limit);
}

/** Strict UTC timestamps: invalid/future dates cannot establish fresh facts. */
function competitors_terms_timestamp($value): ?int
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$value)) return null;
    $stamp=strtotime($value);
    return $stamp!==false && gmdate('Y-m-d\TH:i:s\Z',$stamp)===$value?$stamp:null;
}

function competitors_terms_state(array $target,int $now): string
{
    if (($target['last_attempt']['state']??null)==='error') return 'error';
    $stamp=competitors_terms_timestamp($target['last_success']??null);
    if ($stamp===null || $stamp>$now+300) return 'unknown';
    return $now-$stamp<=48*3600?'fresh':'stale';
}

/** A clause with negated delivery/payment is not an affirmative service list. */
function competitors_terms_negated(string $line): bool
{
    return (bool)preg_match('/(?:\bне\s+(?:здійсню|зд[іi]йсню|відправ|отправ|достав|працю|работ|прийма|принима|оплачу|має|маємо|передбач|предусмотр)|(?:недоступ|не\s+доступ)|(?:відсутн|отсутств))/iu',$line);
}

function competitors_terms_money_pattern(): string
{
    return '(\d{1,3}(?:[\x{00a0} ]\d{3})+(?:[.,]\d{1,2})?|\d{1,8}(?:[.,]\d{1,2})?)\s*(?:грн(?:\.|ив(?:ень|ен|ні|ни|на))?|UAH|₴)';
}

/** Remaining unknown words may be a city, customer class or another restriction. */
function competitors_terms_ordinary_free_clause(string $line): bool
{
    $patterns=[
        '/'.competitors_terms_money_pattern().'/iu',
        '/(?:на\s+(?:будь[- ]?яку|любую)\s+сум\pL*|без\s+(?:мінімальн|минимальн)\pL*\s+сум\pL*)/iu',
        '/(?:нов\pL*\s+(?:пошт|почт)\pL*|\bНП\b|\bNP\b|Nova\s*Poshta)/iu',
        '/(?:безкоштов\pL*|бесплат\pL*|коштом\s+магазину|за\s+наш\s+рахунок)/iu',
        '/(?:відділен\pL*|отделен\pL*|достав\pL*|пересил\pL*|замовлен\pL*|заказ\pL*|сум\pL*|вартіст\pL*|стоимост\pL*)/iu',
        '/(?:Україн\pL*|Украин\pL*|територі\pL*|территори\pL*|становить|складає|составляет|включно|включительно)/iu',
        '/(?<!\pL)(?:при|на|от|від|до|у|в|для|по|за|з|із|і|и|всій|всей|всю)(?!\pL)/iu',
    ];
    $remaining=preg_replace($patterns,' ',$line);
    return !preg_match('/[\pL\d%]/u',$remaining);
}

function competitors_terms_amount(string $raw): ?float
{
    $value=str_replace(["\xc2\xa0",' ', ','],['','','.'],$raw);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$value)) return null;
    $number=(float)$value;
    return $number>=0 && $number<=10000000?$number:null;
}

function competitors_terms_money_display(float $number): string
{
    $decimals=abs($number-round($number))<0.000001?0:2;
    return number_format($number,$decimals,',',' ').' грн';
}

/** Keep adjacent explicit restrictions attached to their discount, not as a new benefit. */
function competitors_terms_discount_quote(array $lines,int $index): string
{
    $quote=$lines[$index];
    for ($next=$index+1;$next<count($lines) && $next<=$index+4;$next++) {
        $line=$lines[$next];
        if ($line==='') break;
        if (!preg_match('/^(?:тільки|только|лише|лишь|за\s|при\s|для\s|після\s|после\s|потріб|необхід|не(?=\s|сум|поєд|діє)|подпис|підпис|суммир|сумує|винят|исключ|крім|кроме|діє\s+за|действует\s+при|промокод|код\s)/iu',$line)) break;
        // A second percentage usually starts another offer/tier. A negated
        // exception (e.g. not combined with a 5% discount) stays attached.
        if (preg_match('/\d+(?:[.,]\d+)?\s*%/u',$line) && !preg_match('/^(?:не|крім|кроме|винят|исключ)/iu',$line)) break;
        $quote.=' '.$line;
        if (mb_strlen($quote)>=600) break;
    }
    return competitors_terms_clean($quote);
}

/** @return array<int,array{row:string,value:mixed,display:string,quote:string}> */
function competitors_terms_extract(string $text): array
{
    $text=mb_substr($text,0,12000);
    $parts=preg_split('/\r?\n|(?<=[!?;])\s+|(?<=\.)\s+(?=[А-ЯІЇЄҐA-Z])/u',$text);
    $facts=[]; $seen=[]; $discountContext='';
    $put=function(string $row,$value,string $display,string $quote)use(&$facts,&$seen):void {
        $display=competitors_terms_clean($display,240); $quote=competitors_terms_clean($quote);
        if ($display==='' || $quote==='') return;
        $key=$row.':'.json_encode($value).':'.$quote;
        if (isset($seen[$key]) || count($facts)>=100) return;
        $seen[$key]=true;
        $facts[]=['row'=>$row,'value'=>$value,'display'=>$display,'quote'=>$quote];
    };
    $money=competitors_terms_money_pattern();
    $lines=array_map(function($part){return competitors_terms_clean((string)$part,1000);},array_slice($parts?:[],0,180));
    foreach ($lines as $index=>$line) {
        if ($line==='') continue;
        $discount=(bool)preg_match('/(?:скид|зниж|накопич|накопит|лояльн|підпис|подпис)/iu',$line);
        if ($discount && !preg_match('/%/u',$line) && mb_strlen($line)<120) $discountContext=$line;
        elseif (preg_match('/(?:оплата|доставка|повернення|возврат)/iu',$line) && !$discount) $discountContext='';
        $wholesale=(bool)preg_match('/(?:оптов|гуртов|HoReCa|дистриб|юридич|юридическ|постій|постоян|перш|перв|підпис|подпис|промокод|за\s+умови|при\s+оплат)/iu',$line);
        $minimum='/^((?:мінімальн|минимальн)\pL*\s+(?:(?:сума|сумма|вартість|стоимость)\s+)?(?:заказ|замовлен)\pL*)([^\d%]{0,35})'.$money.'(?:\s*[.!?]?\s*)$/iu';
        if (!$wholesale && preg_match($minimum,$line,$m)) {
            $prefix=substr($line,0,(int)strpos($line,$m[1]));
            $historicalOrNegated=preg_match('/(?:ранее|раньше|раніш|раніше|був|було|була|был|было|была|скасован|отмен[её]н|не\s+установ|не\s+встанов|не\s+передбач)/iu',$prefix);
            $amount=competitors_terms_amount($m[3]);
            $connector=trim($m[2]," \t:—–-=");
            $ordinary=$connector==='' || preg_match('/^(?:від|от|становить|складає|составляет|не\s+менее|не\s+менше|не\s+нижче|не\s+ниже)$/iuD',$connector);
            if ($amount!==null && $ordinary && !$historicalOrNegated) $put('minimum_order',$amount,competitors_terms_money_display($amount),$line);
        }
        if (!$wholesale && !preg_match('/'.$money.'/iu',$line) && preg_match('/^(?:(?:без|немає|нет)\s+(?:(?:мінімальн|минимальн)\pL*\s+)(?:(?:суми|суммы|сума|сумма)\s+)?(?:замовлен|заказ)\pL*|(?:мінімальн|минимальн)\pL*\s+(?:(?:сума|сумма)\s+)?(?:замовлен|заказ)\pL*\s*[:—–-]?\s*(?:не\s+обмеж\pL*|не\s+огранич\pL*|відсутн\pL*|нет|немає))\s*[.!?]?\s*$/iu',$line)) {
            $put('minimum_order',0.0,'Без минимальной суммы',$line);
        }
        $np=(bool)preg_match('/(?:нов\pL*\s+(?:пошт|почт)\pL*|\bНП\b|\bNP\b|Nova\s*Poshta)/iu',$line);
        $branch=(bool)preg_match('/(?:відділен|отделен)/iu',$line);
        $free=(bool)preg_match('/(?:безкоштов|бесплат|коштом\s+магазину|за\s+наш\s+рахунок)/iu',$line);
        $shippingRestrictions=(bool)preg_match('/(?:кур[’\x{0027}]?єр|курьер|адресн|Ки[еє]в|Киев|Льв[іо]в|Одес|Дн[іе]пр|Харк[іо]в|Харьков|тільки|только|крім|кроме|передоплат|предоплат|перш|перв|оптов|гуртов|підпис|подпис|купон|промокод|акці|акци|накоп|%)/iu',$line);
        if ($np && $branch && $free && !$shippingRestrictions && competitors_terms_ordinary_free_clause($line) && !competitors_terms_negated($line) && !preg_match('/(?:не\s+(?:є\s+)?(?:безкоштов|бесплат))/iu',$line)) {
            if (preg_match_all('/'.$money.'/iu',$line,$allMoney)===1 && preg_match('/(?:від|от)\s*'.$money.'/iu',$line,$m)) {
                $amount=competitors_terms_amount($m[1]);
                if ($amount!==null) $put('free_delivery_np_branch',$amount,'От '.competitors_terms_money_display($amount),$line);
            } elseif (preg_match('/(?:на\s+(?:будь[- ]?яку|любую)\s+сум|без\s+(?:мінімальн|минимальн)\pL*\s+сум)/iu',$line)) {
                $put('free_delivery_np_branch',0.0,'На любую сумму',$line);
            }
        }
        if (preg_match('/(?:відправ|отправ|оброб|обработ)/iu',$line) && preg_match('/(?:день|днів|дней|дн[яі]|доб|годин|час|сьогодні|сегодня|щодня|ежеднев|робоч|рабоч)/iu',$line)) {
            $put('dispatch',$line,$line,$line);
        }
        if (!competitors_terms_negated($line)) {
            $delivery=[];
            if ($np) $delivery[]='Новая почта';
            if (preg_match('/(?:укрпошт|укрпочт)/iu',$line)) $delivery[]='Укрпочта';
            if (preg_match('/(?:meest|міст\s*(?:експрес|експресс)|мист\s*экспресс)/iu',$line)) $delivery[]='Meest';
            if (preg_match('/(?:самовив|самовывоз)/iu',$line)) $delivery[]='Самовывоз';
            if (preg_match('/(?:кур[’\x{0027}]?єр|курьер)/iu',$line)) $delivery[]='Курьер';
            foreach ($delivery as $item) $put('delivery_methods',$item,$item,$line);
            if (preg_match('/(?:оплат|плат[еі]ж|банків|банков|готів|наличн|післяплат|наложен|visa|mastercard|liqpay|wayforpay)/iu',$line)) {
                $payment=[];
                if (preg_match('/(?:карт|visa|mastercard|liqpay|wayforpay)/iu',$line)) $payment[]='Картой';
                if (preg_match('/(?:післяплат|наложен|при\s+(?:отриман|получен))/iu',$line)) $payment[]='При получении';
                if (preg_match('/(?<!\pL)(?:готів\pL*|наличн\pL*)(?!\pL)/iu',$line)) $payment[]='Наличными';
                if (preg_match('/(?:безготів|безналич|рахунок|сч[её]т|IBAN|банківськ\pL*\s+переказ|банковск\pL*\s+перевод)/iu',$line)) $payment[]='На счёт';
                foreach (array_unique($payment) as $item) $put('payment_methods',$item,$item,$line);
            }
        }
        $percentage=(bool)preg_match('/\d+(?:[.,]\d+)?\s*%/u',$line);
        if ($percentage && ($discount || $discountContext!=='')) {
            $context=competitors_terms_discount_quote($lines,$index);
            $quote=$discount?$context:($discountContext.' · '.$context);
            $first=(bool)preg_match('/(?:перв\pL*\s+заказ|перш\pL*\s+замовлен|нов\pL*\s+(?:клиент|клієнт|покуп))/iu',$quote);
            $put($first?'first_order_discount':'regular_discounts',$quote,$quote,$quote);
        }
        if (preg_match('/(?:акці|акци|розпрод|распрод|\bSale\b|промокод|купон)/iu',$line) && mb_strlen($line)>12) $put('promotions',$line,$line,$line);
        if (preg_match('/(?:повернен|возврат|обмін|обмен)/iu',$line) && mb_strlen($line)>14) $put('returns',$line,$line,$line);
    }
    return $facts;
}

function competitors_terms_evidence(array $target,string $state,string $quote): array
{
    return ['target_id'=>(int)($target['id']??0),'url'=>(string)($target['url']??''),
        'checked_at'=>$target['last_success']??null,'quote'=>$quote,'state'=>$state];
}

/** @param array $targets Existing overview targets; source notes are never facts. */
function competitors_terms_comparison(array $targets,?string $now=null): array
{
    $time=competitors_terms_timestamp($now??utc_now())??time();
    $sourceIds=['lavka','deluxe','spicery','banka','specii']; $bySource=[];
    // Product catalogues can contain thousands of rows and sort before terms.
    // Apply the bound to actual terms pages, never to the mixed target list.
    $termsCount=0;
    foreach ($targets as $target) {
        if (!is_array($target) || empty($target['enabled']) || ($target['kind']??'')!=='terms' || !in_array($target['source_id']??'',$sourceIds,true)) continue;
        if(++$termsCount>350)break;
        $state=competitors_terms_state($target,$time); $text=$target['observation']['text']??'';
        $facts=is_string($text)?competitors_terms_extract($text):[];
        $bySource[$target['source_id']][]=['target'=>$target,'state'=>$state,'facts'=>$facts];
    }
    $rows=competitors_terms_rows();
    foreach ($rows as &$row) {
        $row['cells']=[]; $row['best_source_ids']=[];
        foreach ($sourceIds as $sourceId) {
            $pages=$bySource[$sourceId]??[]; $matches=[]; $fallback=[];
            foreach ($pages as $page) {
                $fallback[]=competitors_terms_evidence($page['target'],$page['state'],'');
                foreach ($page['facts'] as $fact) if ($fact['row']===$row['id']) $matches[]=['fact'=>$fact,'state'=>$page['state'],'evidence'=>competitors_terms_evidence($page['target'],$page['state'],$fact['quote'])];
            }
            $cell=['value'=>null,'display'=>$pages?'Не найдено в источнике':'Нет источника','state'=>'unknown',
                'reason'=>$pages?'В сохранённых условиях этот пункт не распознан. Откройте источник.':'Для этого магазина ещё не добавлена страница условий.','page_count'=>count($pages),'evidence'=>array_slice($fallback,0,6)];
            if (!$matches) {
                $states=array_column($pages,'state');
                if (in_array('error',$states,true)) { $cell['state']='error'; $cell['display']='Нужна проверка'; $cell['reason']='Последняя попытка чтения завершилась ошибкой; этот пункт пока не подтверждён.'; }
                elseif ($pages && !array_filter($pages,function($p){return !empty($p['target']['observation']['text']);})) { $cell['display']='Ещё не проверено'; $cell['reason']='Ссылка добавлена; ждём первую успешную фоновую проверку.'; }
            } else {
                $fresh=array_values(array_filter($matches,function($m){return $m['state']==='fresh';}));
                $selected=$fresh?:$matches; $states=array_column($selected,'state');
                $state=$fresh?'fresh':(in_array('error',$states,true)?'error':(in_array('stale',$states,true)?'stale':'unknown'));
                $values=[]; $displays=[]; $evidence=[]; $keys=[];
                foreach ($selected as $match) {
                    $key=encode($match['fact']['value']); $values[$key]=$match['fact']['value'];
                    $displays[$key]=$match['fact']['display'];
                    $e=$match['evidence']; $eKey=$e['target_id'].':'.$e['quote'];
                    if (!isset($keys[$eKey])) { $evidence[]=$e; $keys[$eKey]=true; }
                }
                $conflict=$row['rank_direction']!==null && count($values)>1;
                $display=implode(' · ',array_slice(array_values($displays),0,5));
                $cell=['value'=>$conflict?null:(count($values)===1?reset($values):implode(' · ',array_values($values))),
                    'display'=>$conflict?'Условия расходятся':competitors_terms_clean($display,360),
                    'state'=>$conflict?'conflict':$state,'page_count'=>count($pages),'reason'=>$conflict?'В сохранённых источниках разные суммы для одного условия. Проверьте страницы.':
                        ($state==='fresh'?'Распознано из сохранённого текста. Проверьте ограничения по ссылке.':($state==='error'?'Последняя проверка не удалась. Показан прежний текст; в выборе лучшего условия он не участвует.':($state==='stale'?'Сведения старше 48 часов; в выборе лучшего условия они не участвуют.':'Дата успешной проверки не подтверждена.'))),
                    'evidence'=>array_slice($evidence,0,8)];
            }
            $row['cells'][$sourceId]=$cell;
        }
        if ($row['rank_direction']==='min') {
            $eligible=[];
            foreach ($row['cells'] as $source=>$cell) if ($cell['state']==='fresh' && (is_int($cell['value']) || is_float($cell['value']))) $eligible[$source]=$cell['value'];
            if (count($eligible)>=2) {
                $minimum=min($eligible);
                foreach ($eligible as $source=>$value) if (abs($minimum-$value)<0.000001) $row['best_source_ids'][]=$source;
            }
        }
    }
    unset($row);
    return ['version'=>1,'source_ids'=>$sourceIds,'fresh_hours'=>48,'rows'=>$rows];
}
