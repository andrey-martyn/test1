<?php

declare (strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/GeoAudit.php';
require_once __DIR__ . '/Regional.php';
require_once __DIR__ . '/Loyalty.php';
require_once __DIR__ . '/Marketing.php';
require_once __DIR__ . '/GA4.php';
require_once __DIR__ . '/Sync.php';
require_once __DIR__ . '/History.php';
require_once __DIR__ . '/CRM.php';
require_once __DIR__ . '/Gender.php';
final class Analytics
{
    public $db;
    public $directory;
    public $orders = [], $lines = [], $totals = [], $history = [], $statuses = ['0' => 'Не оформлен / статус 0'], $actions = [], $links = [], $settings = [];
    public $importMeta;
    public $importedAt;
    public $geo;
    public function __construct(\PDO $db, string $directory)
    {
        $this->db = $db;
        $this->directory = $directory;
        $this->geo = new Geo($directory);
        $options = [];
        $packPool = [];
        foreach ($db->query('SELECT customer_id,payload FROM customer_actions') as $r) {
            $this->actions[$r['customer_id']] = decode($r['payload']);
        }
        $query = $db->prepare('SELECT payload FROM raw_records WHERE table_name=? ORDER BY rowid');
        $statusRows = [];
        // Stream records and retain only calculation fields, including for old full SQL dumps.
        // Do not build a second in-memory copy of all 90,000 source records.
        foreach (['oc_order_option', 'oc_order_product', 'oc_order', 'oc_order_total', 'oc_order_history', 'oc_order_status'] as $table) {
            $query->execute([$table]);
            $allowed = array_fill_keys(SOURCE_FIELDS[$table], true);
            while ($row = $query->fetch()) {
                $r = array_intersect_key(decode($row['payload']), $allowed);
                if ($table === 'oc_order_option') {
                    $options[$r['order_product_id']][] = ['name' => $r['name'], 'value' => $r['value']];
                } elseif ($table === 'oc_order_product') {
                    $selection = $options[$r['order_product_id']] ?? [];
                    $packKey = encode($selection);
                    $packPool[$packKey] = $packPool[$packKey] ?? package($selection);
                    $this->lines[$r['order_id']][] = ['product_id' => $r['product_id'], 'name' => $r['name'], 'model' => $r['model'], 'quantity' => $r['quantity'], 'price' => $r['price'], 'total' => $r['total'], 'pack' => $packPool[$packKey]];
                    unset($options[$r['order_product_id']]);
                } elseif ($table === 'oc_order') {
                    $this->orders[$r['order_id']] = $r;
                } elseif ($table === 'oc_order_total') {
                    $this->totals[$r['order_id']][] = $r;
                } elseif ($table === 'oc_order_history') {
                    $this->history[$r['order_id']][] = $r;
                } else {
                    $statusRows[] = $r;
                }
            }
            $query->closeCursor();
            if ($table === 'oc_order_product') {
                unset($options);
            }
        }
        usort($statusRows, function ($a, $b) {
            return (int) ($a['language_id'] !== '1') <=> (int) ($b['language_id'] !== '1');
        });
        foreach ($statusRows as $r) {
            $this->statuses[$r['order_status_id']] = $this->statuses[$r['order_status_id']] ?? $r['name'];
        }
        $last = $db->query('SELECT summary,imported_at FROM imports ORDER BY id DESC LIMIT 1')->fetch();
        if (!$last || !$this->orders) {
            throw new \RuntimeException('Сначала загрузите данные магазина');
        }
        $this->importMeta = decode($last['summary']);
        $this->importedAt = $last['imported_at'];
        $eligible = [];
        foreach ($this->statuses as $id => $name) {
            if ((string) $id !== '0' && !preg_match('/отмен|анулир|аннулир|возврат|возмещ|неуда|истек|изменен|cancel|refund|fail|revers|void|denied/ui', $name)) {
                $eligible[] = (string) $id;
            }
        }
        $this->settings = ['interval_days' => 30, 'grace_days' => 7, 'minimum_orders' => 3, 'followup_min_orders' => 2, 'contact_cooldown_days' => 7, 'status_ids' => $eligible];
        $saved = $db->query("SELECT value FROM settings WHERE key='report'")->fetchColumn();
        if ($saved) {
            $this->settings = array_replace($this->settings, decode($saved));
        }
        $this->links = $db->query('SELECT a,b FROM manual_links')->fetchAll();
    }
    public function metadata(): array
    {
        $dates = [];
        $years = [];
        $currencies = [];
        $statusCounts = [];
        foreach ($this->orders as $o) {
            $d = substr($o['date_added'], 0, 10);
            $dates[] = $d;
            $years[calendar_year($d)] = true;
            $currencies[$o['currency_code']] = true;
            $statusCounts[$o['order_status_id']] = ($statusCounts[$o['order_status_id']] ?? 0) + 1;
        }
        $yy = array_keys($years);
        rsort($yy, SORT_NUMERIC);
        $cc = array_keys($currencies);
        sort($cc);
        $statuses = $this->statuses;
        uksort($statuses, function ($a, $b) {
            return (int) $a <=> (int) $b;
        });
        $rows = [];
        foreach ($statuses as $id => $name) {
            $rows[] = ['id' => (string) $id, 'name' => $name, 'count' => $statusCounts[$id] ?? 0];
        }
        return ['counts' => $this->importMeta['counts'], 'date_min' => min($dates), 'date_max' => max($dates), 'years' => $yy, 'currencies' => $cc, 'statuses' => $rows, 'settings' => $this->settings, 'imported_at' => $this->importedAt, 'geoip' => $this->geo->metadata(), 'warnings' => ['Статус «В обработке» не подтверждает оплату. Показаны суммы заказов.', 'География: адрес и отдельная приблизительная оценка по локальной GeoIP-базе, если она установлена.', ($this->importMeta['source'] ?? '') === 'mysql' ? 'Автоматическая копия из MySQL.' : 'Импорт SQL-выгрузки вручную. Подключение к магазину ещё не настроено.']];
    }
    public function identity(array $orders): DSU
    {
        $dsu = new DSU(array_map(function ($o) {
            return (string) $o['order_id'];
        }, $orders));
        $indices = [];
        foreach ($orders as $o) {
            $keys = [];
            if (($o['customer_id'] ?? '0') !== '0' && ($o['customer_id'] ?? '') !== '') {
                $keys[] = 'account:' . $o['customer_id'];
            }
            $email = email_key($o['email']);
            $phone = phone_key($o['telephone']);
            if ($email && $phone) {
                $keys[] = 'contact:' . encode([$email, $phone]);
            }
            foreach ($keys as $key) {
                if (isset($indices[$key])) {
                    $dsu->union($o['order_id'], $indices[$key]);
                } else {
                    $indices[$key] = $o['order_id'];
                }
            }
        }
        foreach ($this->links as $r) {
            $dsu->union($r['a'], $r['b']);
        }
        return $dsu;
    }
    public function report(int $year, string $asof, string $currency, string $gender = 'all', bool $includeRegional = true): array
    {
        if ($gender !== 'all' && !isset(NAME_GENDER_LABELS[$gender])) throw new \InvalidArgumentException('Неверная группа по имени');
        $before = array_values(array_filter($this->orders, function ($o) use ($asof) {
            return substr($o['date_added'], 0, 10) <= $asof;
        }));
        $dsu = $this->identity($before);
        $valid = array_values(array_filter($before, function ($o) use ($currency) {
            return in_array($o['order_status_id'], $this->settings['status_ids'], true) && $o['currency_code'] === $currency;
        }));
        $allGroups = [];
        foreach ($valid as $o) $allGroups[$dsu->find($o['order_id'])][] = $o;
        $genders = [];$genderDetails=[];
        foreach ($allGroups as $cid => $orders){$genderDetails[$cid]=customer_gender_details($orders);$genders[$cid]=$genderDetails[$cid]['gender'];}
        $genderLoyalty=$includeRegional?loyalty_report($this->geo,$allGroups,$asof,$year,true):null;
        unset($orders);
        $unfilteredPeriod = array_values(array_filter($valid, function ($o) use ($year) {
            return $year === 0 || calendar_year($o['date_added']) === $year;
        }));
        $genderBreakdown = gender_sales_breakdown($unfilteredPeriod, $dsu, $genders);
        $genderDiagnostics=gender_diagnostics($allGroups,$genderDetails,$unfilteredPeriod,$dsu);unset($allGroups);
        if ($gender !== 'all') $valid = array_values(array_filter($valid, function ($o) use ($gender, $genders, $dsu) {
            return ($genders[$dsu->find($o['order_id'])] ?? 'unknown') === $gender;
        }));
        $period = array_values(array_filter($valid, function ($o) use ($year) {
            return $year === 0 || calendar_year($o['date_added']) === $year;
        }));
        $observedYears = [];
        foreach ($before as $o) if ($o['currency_code'] === $currency) $observedYears[calendar_year($o['date_added'])] = true;
        $historyYears = $observedYears ? range(min(array_keys($observedYears)), max(array_keys($observedYears))) : [];
        $columns = history_columns($year, $asof, $historyYears);
        $activity = [];
        $periodIds = array_fill_keys(array_column($period, 'order_id'), true);
        $identityContactOrders=[];foreach($before as $o)if(in_array($o['order_status_id'],$this->settings['status_ids'],true))$identityContactOrders[$dsu->find($o['order_id'])][]=$o;
        $groups = [];
        foreach ($valid as $o) {
            $groups[$dsu->find($o['order_id'])][] = $o;
        }
        $customers = [];
        foreach ($groups as $cid => &$orders) {
            usort($orders, function ($a, $b) {
                return strcmp($a['date_added'], $b['date_added']) ?: (int) $a['order_id'] <=> (int) $b['order_id'];
            });
            $latest = $orders[count($orders) - 1];
            $selected = array_values(array_filter($orders, function ($o) use ($periodIds) {
                return isset($periodIds[$o['order_id']]);
            }));
            $dates = array_values(array_unique(array_map(function ($o) {
                return substr($o['date_added'], 0, 10);
            }, $orders)));
            sort($dates);
            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $gaps[] = days($dates[$i - 1], $dates[$i]);
            }
            $personal = count($dates) >= $this->settings['minimum_orders'];
            $interval = $personal ? max(1, (int) even_round(median(array_slice($gaps, -6)))) : $this->settings['interval_days'];
            $since = days(end($dates), $asof);
            $overdue = $since - $interval;
            $reminder = $overdue > $this->settings['grace_days'] ? 'overdue' : ($overdue >= 0 ? 'due' : 'ok');
            $weeks = [];
            $customerActivity = [];
            foreach ($selected as $o) {
                $key = activity_key($o['date_added'], $year);
                $customerActivity[$key] = $customerActivity[$key] ?? ['amount' => '0', 'count' => 0, 'order_ids' => []];
                $customerActivity[$key]['amount'] = plus($customerActivity[$key]['amount'], order_value($o));
                ++$customerActivity[$key]['count'];
                $customerActivity[$key]['order_ids'][] = $o['order_id'];
                $weeksKey = week($o['date_added']);
                $w = $year === 0 ? $key : $weeksKey;
                $weeks[$w] = $weeks[$w] ?? ['amount' => '0', 'count' => 0, 'order_ids' => []];
                $weeks[$w]['amount'] = plus($weeks[$w]['amount'], order_value($o));
                $weeks[$w]['count']++;
                $weeks[$w]['order_ids'][] = $o['order_id'];
            }
            foreach ($customerActivity as &$cell) $cell['amount'] = money($cell['amount']);
            unset($cell);
            foreach ($weeks as &$w) {
                $w['amount'] = money($w['amount']);
            }
            unset($w);
            $emails = array_values(array_unique(array_filter(array_column($orders, 'email'))));
            sort($emails, SORT_STRING);
            $phones = array_values(array_unique(array_filter(array_column($orders, 'telephone'))));
            sort($phones, SORT_STRING);
            $ids = array_map('strval', array_column($orders, 'order_id'));
            $actions = [];
            $accounts = [];
            $manual = false;
            foreach ($orders as $o) {
                if (isset($this->actions[$o['order_id']])) {
                    $actions[] = $this->actions[$o['order_id']];
                }
                if ($o['customer_id'] !== '0') {
                    $accounts[$o['customer_id']] = true;
                }
            }
            usort($actions, function ($a, $b) {
                return (int) in_array($b['stage'] ?? '', ['do_not_contact', 'declined'], true) <=> (int) in_array($a['stage'] ?? '', ['do_not_contact', 'declined'], true) ?: strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
            });
            $action = $actions[0] ?? [];
            $snoozed = !empty($action['next_contact']) && $action['next_contact'] > $asof;
            $contacted = $action['contact_date'] ?? null;
            $cooling = $contacted && days($contacted, $asof) >= 0 && days($contacted, $asof) < $this->settings['contact_cooldown_days'];
            $eligible = count($orders) >= $this->settings['followup_min_orders'] && !in_array($action['stage'] ?? '', ['do_not_contact', 'declined'], true) && !$snoozed && !$cooling;
            foreach ($this->links as $link) {
                if (in_array($link['a'], $ids, true) || in_array($link['b'], $ids, true)) {
                    $manual = true;
                    break;
                }
            }
            $suppression = ($action['stage'] ?? '') === 'do_not_contact' ? 'Не контактировать' : (($action['stage'] ?? '') === 'declined' ? 'Отказ' : ($snoozed ? 'Отложено' : ($cooling ? 'Недавний контакт' : (count($orders) < $this->settings['followup_min_orders'] ? 'Мало покупок' : ''))));
            $contactFields=identity_contact_fields($identityContactOrders[$cid]??$orders);
            $customers[] = $contactFields + ['id' => (string) $cid, 'name' => trim($latest['firstname'] . ' ' . $latest['lastname']) ?: 'Гость', 'email' => $latest['email'], 'phone' => $latest['telephone'], 'emails' => $emails, 'phones' => $phones, 'city' => $latest['shipping_city'] ?: $latest['payment_city'], 'country' => $latest['shipping_country'] ?: $latest['payment_country'], 'order_count' => count($selected), 'lifetime_count' => count($orders), 'amount' => money(sum_orders($selected)), 'lifetime_amount' => money(sum_orders($orders)), 'average' => $selected ? money(bcdiv(sum_orders($selected), (string) count($selected), 12)) : 0, 'last_order' => end($dates), 'days_since' => $since, 'interval_days' => $interval, 'personal_interval' => $personal, 'due_on' => add_days(end($dates), $interval), 'overdue_days' => max(0, $overdue), 'reminder' => $reminder, 'followup_eligible' => $eligible, 'action' => $action ?: new \stdClass(), 'suppression' => $suppression, 'account_count' => count($accounts), 'manual' => $manual, 'activity' => (object) $customerActivity, 'weeks' => (object) $weeks, 'order_ids' => $ids];
        }
        unset($orders);
        foreach ($customers as &$customer){$detail=$genderDetails[$customer['id']]??['gender'=>'unknown','reason'=>'missing','examples'=>[]];$customer['gender_estimate']=$detail['gender'];$customer['gender_reason']=$detail['reason'];$customer['gender_examples']=$detail['examples'];}
        unset($customer);
        usort($customers, function ($a, $b) {
            return $b['amount'] <=> $a['amount'];
        });
        $products = [];
        $weekly = [];
        $periodCustomers = [];
        foreach ($period as $o) {
            $key = activity_key($o['date_added'], $year);
            $first = $groups[$dsu->find($o['order_id'])][0]['order_id'] === $o['order_id'];
            $activity[$key] = $activity[$key] ?? ['amount' => '0', 'count' => 0, 'first_count' => 0, 'repeat_count' => 0];
            $activity[$key]['amount'] = plus($activity[$key]['amount'], order_value($o));
            ++$activity[$key]['count'];
            ++$activity[$key][$first ? 'first_count' : 'repeat_count'];
            $w = $year === 0 ? $key : week($o['date_added']);
            $weekly[$w] = $weekly[$w] ?? ['amount' => '0', 'count' => 0];
            $weekly[$w]['amount'] = plus($weekly[$w]['amount'], order_value($o));
            $weekly[$w]['count']++;
            $cid = $dsu->find($o['order_id']);
            $periodCustomers[$cid] = true;
            foreach ($this->lines[$o['order_id']] ?? [] as $line) {
                $key = encode([$line['product_id'], $line['pack']['label']]);
                $products[$key] = $products[$key] ?? ['product_id' => $line['product_id'], 'name' => $line['name'], 'model' => $line['model'], 'pack' => $line['pack'], 'quantity' => 0, 'amount' => '0', 'grams' => 0, 'years' => [], 'orders' => [], 'pairs' => []];
                $p =& $products[$key];
                $y = calendar_year($o['date_added']);
                $p['years'][$y] = $p['years'][$y] ?? ['quantity' => 0, 'amount' => '0', 'grams' => 0];
                $p['years'][$y]['quantity'] += (int) $line['quantity'];
                $p['years'][$y]['amount'] = plus($p['years'][$y]['amount'], times($line['total'], $o['currency_value']));
                if ($line['pack']['grams'] !== null) $p['years'][$y]['grams'] += $line['pack']['grams'] * (int) $line['quantity'];
                $p['quantity'] += (int) $line['quantity'];
                $p['amount'] = plus($p['amount'], times($line['total'], $o['currency_value']));
                if ($line['pack']['grams'] !== null) {
                    $p['grams'] += $line['pack']['grams'] * (int) $line['quantity'];
                }
                $p['orders'][$o['order_id']] = true;
                $p['pairs'][$cid][$o['order_id']] = true;
                unset($p);
            }
        }
        $productRows = [];
        foreach ($products as $p) {
            foreach ($p['years'] as &$y) $y['amount'] = money($y['amount']);
            unset($y);
            $p['amount'] = money($p['amount']);
            $p['order_count'] = count($p['orders']);
            $p['customers'] = count($p['pairs']);
            $p['customer_ids'] = array_map('strval', array_keys($p['pairs']));
            $p['repeat_customers'] = count(array_filter($p['pairs'], function ($ids) {
                return count($ids) > 1;
            }));
            $p['orders'] = null;
            unset($p['pairs']);
            $productRows[] = $p;
        }
        usort($productRows, function ($a, $b) {
            return $b['amount'] <=> $a['amount'];
        });
        foreach ($weekly as &$w) {
            $w['amount'] = money($w['amount']);
        }
        unset($w);
        $locations = [];
        foreach (['mixed', 'address', 'ip'] as $mode) {
            $locations[$mode] = $this->geography($period, $dsu, $mode);
        }
        $marketing = marketing($this, $customers, $groups, $period, $asof, $year);
        $overdue = 0;
        $repeat = 0;
        foreach ($customers as $c) {
            if ($c['reminder'] === 'overdue' && $c['followup_eligible']) {
                $overdue++;
            }
            if (isset($periodCustomers[$c['id']]) && $c['lifetime_count'] > 1) {
                $repeat++;
            }
        }
        foreach ($activity as &$cell) $cell['amount'] = money($cell['amount']);
        unset($cell);
        $annual = history_summary($this, $valid, $groups, $dsu, $historyYears);
        $historyTotals = ['amount' => money(sum_orders($valid)), 'order_count' => count($valid), 'customer_count' => count($groups), 'quantity' => array_sum(array_column($annual, 'quantity')), 'kilograms' => even_round(array_sum(array_column($annual, 'kilograms')), 3)];
        $total = sum_orders($period);
        $crm = crm_build($this, $customers, $groups, $dsu, $asof, $currency);
        $regional=null;
        if($includeRegional){
            $coverageStart='';foreach($before as $order)if($order['currency_code']===$currency){$date=substr($order['date_added'],0,10);if($coverageStart===''||$date<$coverageStart)$coverageStart=$date;}
            $regional=regional_report($this,$valid,$groups,$dsu,$asof,$coverageStart);
        }
        return ['loyalty' => $includeRegional?['gender'=>$genderLoyalty,'regional'=>loyalty_report($this->geo,$groups,$asof,$year)]:null, 'regional' => $regional, 'gender_filter' => $gender, 'gender_breakdown' => $genderBreakdown, 'gender_diagnostics'=>$genderDiagnostics, 'year' => $year, 'as_of' => $asof, 'currency' => $currency, 'week_count' => $year ? week($year . '-12-28') : 0, 'scope' => $year ? 'year' : 'all', 'crm' => $crm, 'annual' => $annual, 'history_totals' => $historyTotals, 'calendar' => $columns, 'activity' => (object) $activity, 'duplicate_pairs' => identity_pairs($customers), 'customers' => $customers, 'products' => $productRows, 'locations' => $locations['mixed'], 'location_modes' => $locations, 'geoip' => $this->geo->metadata(), 'marketing' => $marketing, 'ga4' => $gender === 'all' ? ga4_report($this->db, $year, $asof, $currency, $period) : null, 'geo_quality' => geo_quality($this->geo, $period), 'weekly' => (object) $weekly, 'metrics' => ['amount' => money($total), 'order_count' => count($period), 'customer_count' => count($periodCustomers), 'average' => $period ? money(bcdiv($total, (string) count($period), 12)) : 0, 'overdue' => $overdue, 'repeat_customers' => $repeat, 'quantity' => array_sum(array_column($productRows, 'quantity')), 'kilograms' => even_round(array_sum(array_column($productRows, 'grams')) / 1000, 3)], 'duplicates' => $this->duplicates($customers)];
    }
    public function geography(array $orders, DSU $dsu, string $mode): array
    {
        $locations = [];
        foreach ($orders as $o) {
            $loc = $this->geo->resolve($o, $mode);
            $key = encode([$loc['country_code'] ?: normalize($loc['country']), $loc['zone_code'] ?: normalize($loc['zone']), normalize($loc['city'])]);
            $locations[$key] = $locations[$key] ?? array_merge($loc, ['count'=>0,'amount'=>'0','customers'=>[],'years'=>[], 'sources'=>[], 'variants'=>[], 'zone_variants'=>[], 'channels'=>[]]);
            $locations[$key]['sources'][$loc['source']] = ($locations[$key]['sources'][$loc['source']] ?? 0)+1;
            if ($loc['original_city'] !== '') $locations[$key]['variants'][$loc['original_city']] = ($locations[$key]['variants'][$loc['original_city']] ?? 0)+1;
            if ($loc['original_zone'] !== '') $locations[$key]['zone_variants'][$loc['original_zone']] = ($locations[$key]['zone_variants'][$loc['original_zone']] ?? 0)+1;
            $kind=regional_channel($this->geo,$o);$cid=$dsu->find($o['order_id']);$y=calendar_year($o['date_added']);
            $channels=&$locations[$key]['channels'];
            if(!isset($channels[$kind]))$channels[$kind]=['count'=>0,'amount'=>'0','customers'=>[],'years'=>[],'sources'=>[],'variants'=>[],'zone_variants'=>[]];
            $channel=&$channels[$kind];++$channel['count'];$channel['amount']=plus($channel['amount'],order_value($o));$channel['customers'][$cid]=true;
            $channel['sources'][$loc['source']]=($channel['sources'][$loc['source']]??0)+1;
            if($loc['original_city']!=='')$channel['variants'][$loc['original_city']]=($channel['variants'][$loc['original_city']]??0)+1;
            if($loc['original_zone']!=='')$channel['zone_variants'][$loc['original_zone']]=($channel['zone_variants'][$loc['original_zone']]??0)+1;
            if(!isset($channel['years'][$y]))$channel['years'][$y]=['count'=>0,'amount'=>'0','customers'=>[]];
            ++$channel['years'][$y]['count'];$channel['years'][$y]['amount']=plus($channel['years'][$y]['amount'],order_value($o));$channel['years'][$y]['customers'][$cid]=true;
            unset($channel,$channels);
            $loc =& $locations[$key];
            $loc['years'][$y] = $loc['years'][$y] ?? ['count' => 0, 'amount' => '0', 'customers' => []];
            ++$loc['years'][$y]['count'];
            $loc['years'][$y]['amount'] = plus($loc['years'][$y]['amount'], order_value($o));
            $loc['years'][$y]['customers'][$dsu->find($o['order_id'])] = true;
            $loc['count']++;
            $loc['amount'] = plus($loc['amount'], order_value($o));
            $loc['customers'][$dsu->find($o['order_id'])] = true;
            unset($loc);
        }
        $rows = [];
        foreach ($locations as $r) {
            foreach($r['channels'] as &$channel){
                $channel['amount']=money($channel['amount']);$channel['customers']=count($channel['customers']);
                $channel['variant_count']=count($channel['variants']);$channel['variants']=array_slice($channel['variants'],0,20,true);$channel['zone_variants']=array_slice($channel['zone_variants'],0,20,true);
                $channel['source']=count($channel['sources'])===1?(string)key($channel['sources']):'Несколько источников';
                foreach($channel['years'] as &$year){$year['amount']=money($year['amount']);$year['customers']=count($year['customers']);}unset($year);
            }unset($channel);
            $r['source'] = count($r['sources'])===1 ? (string)key($r['sources']) : 'Несколько источников';
            $r['zone_variants'] = array_slice($r['zone_variants'],0,20,true);
            $r['variant_count'] = count($r['variants']);$r['variants'] = array_slice($r['variants'],0,20,true);
            foreach ($r['years'] as &$y) { $y['amount'] = money($y['amount']); $y['customers'] = count($y['customers']); }
            unset($y);
            $r['amount'] = money($r['amount']);
            $r['customers'] = count($r['customers']);
            $rows[] = $r;
        }
        usort($rows, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        return $rows;
    }
    public function duplicates(array $customers): array
    {
        $keys = [];
        $byid = [];
        foreach ($customers as $c) {
            $byid[$c['id']] = $c;
            foreach ($c['emails'] as $email) {
                if ($k = email_key($email)) {
                    $keys['email'][$k][$c['id']] = true;
                }
            }
            foreach ($c['phones'] as $phone) {
                if ($k = phone_key($phone)) {
                    $keys['phone'][$k][$c['id']] = true;
                }
            }
            $name = normalize($c['name']);
            if (count(explode(' ', $name)) >= 2) {
                $keys['name'][$name][$c['id']] = true;
            }
        }
        $seen = [];
        $candidates = [];
        foreach (['email' => 'Совпадает email', 'phone' => 'Совпадает телефон', 'name' => 'Совпадает имя — нужна проверка'] as $kind => $reason) {
            $values = $keys[$kind] ?? [];
            ksort($values, SORT_STRING);
            foreach ($values as $value => $ids) {
                if (count($ids) < 2 || count($ids) > 20) {
                    continue;
                }
                $ids = array_keys($ids);
                sort($ids, SORT_NUMERIC);
                $pair = encode($ids);
                if (isset($seen[$pair])) {
                    continue;
                }
                $seen[$pair] = true;
                $members = [];
                foreach ($ids as $cid) {
                    $members[] = array_intersect_key($byid[$cid], array_flip(['id', 'name', 'email', 'phone', 'lifetime_count', 'city']));
                }
                $candidates[] = ['reason' => $reason, 'value' => (string) $value, 'members' => $members];
            }
        }
        return $candidates;
    }
    public function order(string $id): array
    {
        $o = $this->orders[$id];
        $lines = [];
        foreach ($this->lines[$id] ?? [] as $p) {
            $lines[] = ['name' => $p['name'], 'model' => $p['model'], 'product_id' => $p['product_id'], 'pack' => $p['pack'], 'quantity' => (int) $p['quantity'], 'price' => money(times($p['price'], $o['currency_value'])), 'total' => money(times($p['total'], $o['currency_value']))];
        }
        $totals = ordered($this->totals[$id] ?? [], function ($a, $b) {
            return (int) $a['sort_order'] <=> (int) $b['sort_order'];
        });
        $history = ordered($this->history[$id] ?? [], function ($a, $b) {
            return strcmp($a['date_added'], $b['date_added']);
        });
        return ['id' => $id, 'date' => $o['date_added'], 'status' => $this->statuses[$o['order_status_id']] ?? $o['order_status_id'], 'name' => $o['firstname'] . ' ' . $o['lastname'], 'email' => $o['email'], 'phone' => $o['telephone'], 'city' => $o['shipping_city'] ?: $o['payment_city'], 'zone' => $o['shipping_zone'] ?: $o['payment_zone'], 'country' => $o['shipping_country'] ?: $o['payment_country'], 'geography' => ['shipping_city'=>$o['shipping_city'],'shipping_zone'=>$o['shipping_zone'],'shipping_country'=>$o['shipping_country'],'payment_city'=>$o['payment_city'],'payment_zone'=>$o['payment_zone'],'payment_country'=>$o['payment_country'],'resolved'=>$this->geo->resolve($o)], 'shipping_method' => $o['shipping_method'], 'payment_method' => $o['payment_method'], 'amount' => money(order_value($o)), 'currency' => $o['currency_code'], 'lines' => $lines, 'totals' => array_map(function ($t) use ($o) {
            return ['title' => $t['title'], 'code' => $t['code'], 'value' => money(times($t['value'], $o['currency_value']))];
        }, $totals), 'history' => array_map(function ($h) {
            return ['date' => $h['date_added'], 'status' => $this->statuses[$h['order_status_id']] ?? $h['order_status_id']];
        }, $history)];
    }
}
function fast_metadata(\PDO $db, string $directory): array
{
    $last = $db->query('SELECT summary,imported_at FROM imports ORDER BY id DESC LIMIT 1')->fetch();
    if (!$last) {
        throw new \RuntimeException('Сначала загрузите данные магазина');
    }
    $summary = decode($last['summary']);
    $years = [];
    $currencies = [];
    $counts = [];
    $min = null;
    $max = null;
    $stmt = $db->prepare('SELECT payload FROM raw_records WHERE table_name=?');
    $stmt->execute(['oc_order']);
    while ($row = $stmt->fetch()) {
        $o = decode($row['payload']);
        $d = substr($o['date_added'], 0, 10);
        $min = $min === null || $d < $min ? $d : $min;
        $max = $max === null || $d > $max ? $d : $max;
        $years[calendar_year($d)] = true;
        $currencies[$o['currency_code']] = true;
        $counts[$o['order_status_id']] = ($counts[$o['order_status_id']] ?? 0) + 1;
    }
    $stmt->closeCursor();
    $stmt->execute(['oc_order_status']);
    $rows = [];
    while ($row = $stmt->fetch()) {
        $rows[] = decode($row['payload']);
    }
    $stmt->closeCursor();
    usort($rows, function ($a, $b) {
        return (int) ($a['language_id'] !== '1') <=> (int) ($b['language_id'] !== '1');
    });
    $statuses = ['0' => 'Не оформлен / статус 0'];
    foreach ($rows as $r) {
        $statuses[$r['order_status_id']] = $statuses[$r['order_status_id']] ?? $r['name'];
    }
    uksort($statuses, function ($a, $b) {
        return (int) $a <=> (int) $b;
    });
    $statusRows = [];
    $eligible = [];
    foreach ($statuses as $id => $name) {
        $statusRows[] = ['id' => (string) $id, 'name' => $name, 'count' => $counts[$id] ?? 0];
        if ((string) $id !== '0' && !preg_match('/отмен|анулир|аннулир|возврат|возмещ|неуда|истек|изменен|cancel|refund|fail|revers|void|denied/ui', $name)) {
            $eligible[] = (string) $id;
        }
    }
    $settings = ['interval_days' => 30, 'grace_days' => 7, 'minimum_orders' => 3, 'followup_min_orders' => 2, 'contact_cooldown_days' => 7, 'status_ids' => $eligible];
    $saved = $db->query("SELECT value FROM settings WHERE key='report'")->fetchColumn();
    if ($saved) {
        $settings = array_replace($settings, decode($saved));
    }
    $yy = array_keys($years);
    rsort($yy, SORT_NUMERIC);
    $cc = array_keys($currencies);
    sort($cc, SORT_STRING);
    return ['counts' => $summary['counts'], 'date_min' => $min, 'date_max' => $max, 'years' => $yy, 'currencies' => $cc, 'statuses' => $statusRows, 'settings' => $settings, 'imported_at' => $last['imported_at'], 'geoip' => (new Geo($directory))->metadata(), 'warnings' => ['Статус «В обработке» не подтверждает оплату. Показаны суммы заказов.', 'География: адрес и отдельная приблизительная оценка по локальной GeoIP-базе, если она установлена.', ($summary['source'] ?? '') === 'mysql' ? 'Автоматическая копия из MySQL.' : 'Импорт SQL-выгрузки вручную. Подключение к магазину ещё не настроено.']];
}
