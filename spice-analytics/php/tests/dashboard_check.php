<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Dashboard.php';
function expect_dashboard(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function customer_dashboard(string $id, string $first, array $ids, array $extra=[]): array {
    return array_replace(['id'=>$id,'first_order'=>$first,'order_ids'=>$ids,'name'=>'Synthetic '.$id,'followup_eligible'=>true,'reminder'=>'overdue','suppression'=>'','segment'=>'return','segment_name'=>'Пора возвращать','priority'=>70,'days_since'=>40,'interval_days'=>30,'lifetime_count'=>2,'lifetime_amount'=>100],$extra);
}
function order_dashboard(string $id,string $date,string $total='100',string $rate='1'): array {return ['order_id'=>$id,'date_added'=>$date.' 12:00:00','total'=>$total,'currency_value'=>$rate];}
$report=['currency'=>'UAH','customers'=>[
    customer_dashboard('1','2026-01-01',['1','2','3','11','12','13']),
    customer_dashboard('2','2026-03-02',['4','5']),
    customer_dashboard('3','2026-01-01',['6'],['followup_eligible'=>false,'suppression'=>'Не контактировать']),
    customer_dashboard('4','2026-01-01',['7']),
    customer_dashboard('5','2026-01-01',['8'],['segment'=>'sleeping','priority'=>90]),
    customer_dashboard('6','2026-01-01',['9'],['followup_eligible'=>false,'suppression'=>'Отложено']),
    customer_dashboard('7','2026-01-01',['10'],['followup_eligible'=>false,'suppression'=>'Недавний контакт']),
], 'crm'=>['customers'=>(object)['4'=>['state'=>'waiting']]]];
$orders=[order_dashboard('1','2025-03-01','100'),order_dashboard('11','2025-04-01','9999'),order_dashboard('12','2026-02-01','9999'),order_dashboard('13','2025-03-31','500'),order_dashboard('2','2026-03-01','50.25'),order_dashboard('3','2026-03-03','20'),order_dashboard('4','2026-03-02','30'),order_dashboard('5','2026-03-03','40'),order_dashboard('other-currency','2026-03-01','10000'),order_dashboard('cancelled','2026-03-01','10000')];
$r=\Lavka\dashboard_projection($report,$orders,'2026-03-03');
expect_dashboard($r['metrics']['amount']===140.25 && $r['metrics']['count']===4,'Only eligible report order IDs contribute; today included in month total');
expect_dashboard($r['metrics']['customers']===2 && $r['metrics']['repeat_customers']===1 && $r['metrics']['repeat_share']===50.0,'Unique people with a purchase before month start, not repeated orders or two purchases in current month');
expect_dashboard($r['comparison']['days']===2 && $r['comparison']['current']['amount']===80.25 && $r['comparison']['previous']['amount']===100.0,'Today and intervening months excluded from equal-day yearly comparison');
expect_dashboard($r['days'][2]['state']==='today' && $r['days'][3]['state']==='future' && $r['days'][2]['order_ids']===['3','5'],'Chart distinguishes incomplete day and future; exact orders retained');
expect_dashboard(array_column($r['actions'],'customer_id')===['5','1','2'],'Do not contact, deferred, recent contact and waiting CRM are suppressed; priority order unique');
expect_dashboard($r['actions'][0]['type']==='risk' && $r['sources']['inventory']==='not_connected','Sleeping regular customer is risk; missing sources are explicit, not zero');
$r=\Lavka\dashboard_projection($report,$orders,'2026-03-31');
expect_dashboard($r['comparison']['days']===30 && $r['comparison']['current_end']==='2026-03-30' && $r['comparison']['previous_end']==='2025-03-30','The same month last year, with equal completed-day windows');
expect_dashboard(count($r['days'])===31 && count($r['previous_days'])===31,'Calendar lengths are real');
$r=\Lavka\dashboard_projection($report,$orders,'2026-01-01');
expect_dashboard($r['comparison']['days']===0 && $r['comparison']['current_end']===null && $r['comparison']['previous_end']===null,'First day has no complete-day growth; no division by zero');
expect_dashboard($r['comparison']['previous_start']==='2025-01-01' && $r['metrics']['repeat_share']===null && $r['metrics']['average']===null,'Year boundary and empty denominators stay unknown');
$leap=\Lavka\dashboard_projection($report,[],'2024-02-29');
expect_dashboard($leap['comparison']['days']===28 && count($leap['previous_days'])===28,'Leap-day comparison uses 28 shared completed days');
expect_dashboard($r['comparison']['basis']==='same_month_last_year','The comparison basis is explicit');
$rate=\Lavka\dashboard_projection(['currency'=>'USD','customers'=>[customer_dashboard('1','2026-01-01',['1'])]], [order_dashboard('1','2026-03-01','10','2')], '2026-03-02');
expect_dashboard($rate['metrics']['amount']===20.0 && $rate['currency']==='USD','Same order-value conversion convention as existing reports');
echo "Dashboard month, comparison, identity, permissions and unknown-source checks passed\n";
