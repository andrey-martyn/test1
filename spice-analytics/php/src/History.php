<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__ . '/Gender.php';

function activity_key(string $date, int $year): string
{
    if ($year === 0) return substr($date, 0, 4);
    $d = new \DateTimeImmutable(substr($date, 0, 10));
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
}
function history_columns(int $year, string $asof, array $years): array
{
    $rows = [];
    if ($year === 0) {
        sort($years, SORT_NUMERIC);
        foreach ($years as $y) {
            $rows[] = ['key' => (string) $y, 'label' => (string) $y, 'start' => $y . '-01-01', 'end' => min($y . '-12-31', $asof), 'kind' => 'year'];
        }
    } elseif ($asof >= $year . '-01-01') {
        $end = min($asof, $year . '-12-31');
        $d = new \DateTimeImmutable(activity_key($year . '-01-01', $year));
        while ($d->format('Y-m-d') <= $end) {
            $rows[] = ['key' => $d->format('Y-m-d'), 'label' => $d->format('d.m'), 'start' => max($d->format('Y-m-d'), $year . '-01-01'), 'end' => min($d->modify('+6 days')->format('Y-m-d'), $end), 'kind' => 'week'];
            $d = $d->modify('+7 days');
        }
    }
    return $rows;
}
function history_summary(Analytics $s, array $valid, array $groups, DSU $dsu, array $years): array
{
    $rows = [];
    sort($years, SORT_NUMERIC);
    foreach ($years as $year) {
        $months = [];
        for ($m = 1; $m <= 12; ++$m) $months[] = ['month' => $m, 'count' => 0, 'amount' => '0'];
        $rows[$year] = ['year' => $year, 'amount' => '0', 'order_count' => 0, 'customer_count' => 0, 'new_customers' => 0, 'returning_customers' => 0, 'repeat_orders' => 0, 'repeat_amount' => '0', 'quantity' => 0, 'kilograms' => 0, 'average' => 0, 'months' => $months, '_customers' => [], '_new' => [], '_returning' => []];
    }
    $first = [];
    foreach ($groups as $cid => $orders) $first[$cid] = $orders[0];
    foreach ($valid as $order) {
        $y = calendar_year($order['date_added']);
        $r =& $rows[$y];
        $cid = $dsu->find($order['order_id']);
        $r['amount'] = plus($r['amount'], order_value($order));
        ++$r['order_count'];
        $r['_customers'][$cid] = true;
        if (calendar_year($first[$cid]['date_added']) === $y) $r['_new'][$cid] = true;
        else $r['_returning'][$cid] = true;
        if ($first[$cid]['order_id'] !== $order['order_id']) {
            ++$r['repeat_orders'];
            $r['repeat_amount'] = plus($r['repeat_amount'], order_value($order));
        }
        $month = (int) substr($order['date_added'], 5, 2) - 1;
        ++$r['months'][$month]['count'];
        $r['months'][$month]['amount'] = plus($r['months'][$month]['amount'], order_value($order));
        foreach ($s->lines[$order['order_id']] ?? [] as $line) {
            $r['quantity'] += (int) $line['quantity'];
            if ($line['pack']['grams'] !== null) $r['kilograms'] += $line['pack']['grams'] * (int) $line['quantity'] / 1000;
        }
        unset($r);
    }
    foreach ($rows as &$r) {
        $r['customer_count'] = count($r['_customers']);
        $r['new_customers'] = count($r['_new']);
        $r['returning_customers'] = count($r['_returning']);
        $r['average'] = $r['order_count'] ? money(bcdiv($r['amount'], (string) $r['order_count'], 12)) : 0;
        $r['amount'] = money($r['amount']);
        $r['repeat_amount'] = money($r['repeat_amount']);
        $r['kilograms'] = even_round($r['kilograms'], 3);
        foreach ($r['months'] as &$m) $m['amount'] = money($m['amount']);
        unset($m, $r['_customers'], $r['_new'], $r['_returning']);
    }
    unset($r);
    return array_values($rows);
}

/** Parsing affects suggestions only; saved identity edges are never rewritten. */
function identity_given_name(string $value): bool
{
    $parts=explode('-',name_gender_key($value));$names=name_gender_dictionary();
    if(!$parts)return false;
    foreach($parts as $part)if($part===''||!isset($names[$part]))return false;
    return true;
}
function identity_patronymic(string $value): bool
{
    return (bool)preg_match('/(?:ович|евич|йович|івна|ївна|овна|евна|ична)$/iu',$value);
}
function identity_name_parts(string $value): array
{
    return preg_split('/\s+/u',normalize(str_replace(["’","‘","`","ʼ","‐","‑","–","—"],["'","'","'","'","-","-","-","-"],$value)),-1,PREG_SPLIT_NO_EMPTY);
}
/** A known given name must never become surname-only evidence. */
function identity_surname_key(string $value): string
{
    $parts=identity_name_parts($value);$value=implode(' ',$parts);
    if(!preg_match("/^[\pL]+(?:[ '\-][\pL]+)*$/uD",$value) || mb_strlen(preg_replace('/[^\pL]/u','',$value))<2)return '';
    if(in_array($value,['тест','test','проверка','клієнт','клиент','покупатель','покупець','гость','гість','guest','customer','нет','немає','null','undefined','не указано','не вказано','фоп','тов','ооо','компания','магазин','фамилия','прізвище','surname','lastname'],true))return '';
    foreach($parts as $part)if(identity_given_name($part))return '';
    return $value;
}
/** Handle dedicated, swapped, repeated and combined checkout fields conservatively. */
function identity_order_surname(array $o): string
{
    $last=trim((string)($o['lastname']??''));$first=trim((string)($o['firstname']??''));
    if(identity_surname_key($last)!=='')return $last;
    // A malformed non-name in lastname is not permission to guess another field.
    $lastParts=identity_name_parts($last);$firstParts=identity_name_parts($first);
    foreach(array_merge($lastParts,$firstParts) as $part)if(!preg_match("/^[\pL]+(?:['\-][\pL]+)*$/uD",$part))return '';
    $hasGiven=false;$candidates=[];
    foreach(array_merge($firstParts,$lastParts) as $part){
        if(identity_given_name($part)){$hasGiven=true;continue;}
        if(identity_patronymic($part))continue;
        if($key=identity_surname_key($part))$candidates[$key]=$part;
    }
    if(!$hasGiven||count($candidates)!==1)return '';
    $chosen=(string)key($candidates);
    foreach(preg_split('/\s+/u',trim($first.' '.$last),-1,PREG_SPLIT_NO_EMPTY) as $original)if(identity_surname_key($original)===$chosen)return $original;
    return (string)reset($candidates);
}

/** Preserve historical names and contact people when records share a history. */
function identity_contact_fields(array $orders): array
{
    $names=[];$surnames=[];$contacts=[];
    foreach($orders as $o){
        $name=trim(($o['firstname']??'').' '.($o['lastname']??''));
        if($name!=='')$names[normalize($name)]=$name;
        if($surname=identity_order_surname($o))$surnames[identity_surname_key($surname)]=$surname;
        $contact=['name'=>$name?:'Гость','surname'=>identity_order_surname($o),'email'=>$o['email']??'','phone'=>$o['telephone']??'','city'=>($o['shipping_city']??'')?:($o['payment_city']??''),'order_id'=>(string)($o['order_id']??''),'date'=>substr($o['date_added']??'',0,10)];
        $key=encode([normalize($name),normalize($contact['email']),normalize($contact['phone']),normalize($contact['city'])]);if(!isset($contacts[$key])||strcmp($contact['date'],$contacts[$key]['date'])>=0)$contacts[$key]=$contact;
    }
    return ['names'=>array_values($names),'surnames'=>array_values($surnames),'contacts'=>array_values($contacts),'emails'=>array_values(array_unique(array_filter(array_column($orders,'email')))),'phones'=>array_values(array_unique(array_filter(array_column($orders,'telephone'))))];
}

/** Shared evidence index for the journal and CRM warnings. */
function identity_contact_keys(array $c): array
{
    $keys=[];
    foreach($c['emails']??[] as $v)if($k=identity_suggestion_email($v))$keys['email'][$k]=true;
    foreach($c['phones']??[] as $v)if($k=phone_key($v))$keys['phone'][$k]=true;
    foreach($c['names']??[$c['name']] as $v)if($k=identity_suggestion_name($v))$keys['name'][$k]=true;
    foreach($c['surnames']??[] as $v)if($k=identity_surname_key($v))$keys['surname'][$k]=true;
    return $keys;
}

/** Suggestion filters only. Never change the identity edges of existing orders. */
function identity_suggestion_email(string $value): string
{
    $key=email_key($value);
    if($key && preg_match('/^(?:info|support|sales|admin|office|manager|zakaz|orders?|contact|noreply|no-reply)@/u',$key))return '';
    return $key;
}
function identity_suggestion_name(string $value): string
{
    $parts=identity_name_parts($value);$key=implode(' ',$parts);
    if(count($parts)<2 || !preg_match("/^[\\pL]+(?:[ '\\-][\\pL]+)*$/uD",$key))return '';
    foreach($parts as $part)if(in_array($part,['гость','гість','guest','клиент','клієнт','покупатель','покупець','customer','неизвестный','невідомий','нет','немає','null','undefined','ооо','тов','фоп'],true))return '';
    $given=[];$family=[];$patronymics=[];
    foreach($parts as $part){
        if(identity_given_name($part))$given[$part]=true;
        elseif(identity_patronymic($part))$patronymics[$part]=true;
        else $family[$part]=true;
    }
    if(!$family)return '';
    // Canonicalize only unambiguous given-name/surname order, retaining patronymics.
    if(count($given)===1&&count($family)===1)return implode(' ',array_merge(array_keys($given),array_keys($family),array_keys($patronymics)));
    return $key;
}
function identity_pair_member(array $c): array
{
    return array_intersect_key($c,array_flip(['id','name','names','surnames','current_surname','email','phone','emails','phones','lifetime_count','lifetime_amount','city']));
}
function identity_evidence_key(string $kind,array $contact): string
{
    if($kind==='email')return identity_suggestion_email($contact['email']??'');
    if($kind==='phone')return phone_key($contact['phone']??'');
    if($kind==='surname')return identity_surname_key($contact['surname']??$contact['current_surname']??'');
    return identity_suggestion_name($contact['name']??'');
}
function identity_match_evidence(string $kind,string $label,string $value,array $left,array $right): array
{
    $match=['kind'=>$kind,'label'=>$label,'value'=>$value];
    foreach(['left'=>$left,'right'=>$right] as $side=>$c){
        $match[$side.'_current']=identity_evidence_key($kind,$c)===$value;
        $sources=[];
        foreach($c['contacts']??[] as $contact)if(identity_evidence_key($kind,$contact)===$value)$sources[]=array_intersect_key($contact,array_flip(['name','email','phone','date','order_id']));
        usort($sources,function($a,$b){return strcmp($b['date']??'',$a['date']??'');});
        $match[$side.'_sources']=array_slice($sources,0,1);
    }
    return $match;
}

/** Each unordered pair appears once, with every matching contact field. */
function identity_pairs(array $customers,array $dismissed=[]): array
{
    $keys = [];
    $members = [];
    foreach ($customers as $c) {
        $members[$c['id']] = $c;
        foreach(identity_contact_keys($c) as $kind=>$values)foreach($values as $key=>$_)$keys[$kind][$key][$c['id']]=true;
    }
    $pairs = [];
    foreach (['email' => 'Email', 'phone' => 'Телефон', 'name' => 'Полное имя', 'surname'=>'Фамилия'] as $kind => $label) {
        foreach ($keys[$kind] ?? [] as $value => $ids) {
            if (count($ids) < 2 || ($kind!=='surname' && count($ids)>20)) continue;
            $ids = array_map('strval', array_keys($ids));
            sort($ids, SORT_NUMERIC);
            // Common surnames must not disappear entirely or create N² pairs.
            // A bounded chain gives every card an entry; arbitrary pairs use the picker.
            $limited=$kind==='surname' && count($ids)>20;
            for ($i = 0; $i < count($ids); ++$i) for ($j = $i + 1; $j < ($limited?min(count($ids),$i+2):count($ids)); ++$j) {
                $key = $ids[$i] . ':' . $ids[$j];
                if(isset($dismissed[$key]))continue;
                if (!isset($pairs[$key])) $pairs[$key] = ['id' => $key, 'ids' => [$ids[$i], $ids[$j]], 'left' => identity_pair_member($members[$ids[$i]]), 'right' => identity_pair_member($members[$ids[$j]]), 'matches' => [], 'kinds' => []];
                if($limited)$pairs[$key]['surname_limited']=true;
                $pairs[$key]['matches'][] = identity_match_evidence($kind,$label,(string)$value,$members[$ids[$i]],$members[$ids[$j]]);
                if (!in_array($kind, $pairs[$key]['kinds'], true)) $pairs[$key]['kinds'][] = $kind;
            }
        }
    }
    foreach($pairs as &$pair){
        $contact=in_array('email',$pair['kinds'],true)||in_array('phone',$pair['kinds'],true);
        $pair['strength']=$contact?'contact':(in_array('name',$pair['kinds'],true)?'name':'surname');
        $pair['priority']=$contact?(in_array('email',$pair['kinds'],true)&&in_array('phone',$pair['kinds'],true)?100:80):($pair['strength']==='name'?50:10);
        $pair['historical']=count(array_filter($pair['matches'],function($m){return !$m['left_current']||!$m['right_current'];}))>0;
    }unset($pair);
    return array_values($pairs);
}
