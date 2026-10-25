<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/CompetitorTerms.php';
require_once dirname(__DIR__).'/src/CompetitorParser.php';
function tw_facts(string $text,string $row):array{return array_values(array_filter(\Lavka\competitors_terms_extract($text),function($f)use($row){return $f['row']===$row;}));}
function tw_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['100 грн минимальная сумма заказа'=>100.0,'Мінімальна сума замовлення для доставки по Україні — 100 грн.'=>100.0,'Минимальная сумма заказа для отправки по Украине – 200 грн .'=>200.0] as $line=>$amount){$facts=tw_facts($line,'minimum_order');tw_expect(count($facts)===1&&$facts[0]['value']===$amount,'National minimum: '.$line);}
foreach(["Мінімальне замовлення для здійснення кур'єрської доставки по Одесі - 69 грн.",'Минимальная сумма заказа по Киеву 200 грн','Раньше 100 грн минимальная сумма заказа','100 грн минимальная сумма заказа для оптовиков'] as $line)tw_expect(!tw_facts($line,'minimum_order'),'Local/historical/restricted minimum not global');
$line='Бесплатная доставка от 3 000 грн — на отделение и в почтомат Новой Почты, в Укрпочту и курьером по Киеву.';
$f=tw_facts($line,'free_delivery_np_branch');tw_expect(count($f)===1&&$f[0]['value']===3000.0&&$f[0]['quote']===$line,'NP threshold preserved with original multi-carrier evidence');
$f=tw_facts('При заказе на сумму от 3500 грн. – доставка Новой Почтой на отделение или поштомат по Украине за наш счет.','free_delivery_np_branch');tw_expect(count($f)===1&&$f[0]['value']===3500.0,'Explicit national branch threshold');
tw_expect(!tw_facts('При заказе на сумму от 3000 грн. – доставка Новой Почтой по Киеву на отделение за наш счет.','free_delivery_np_branch'),'Local threshold stays local');
tw_expect(!tw_facts('Доставка післяплатою не проводиться!','payment_methods'),'Negated COD is not an available payment method');
tw_expect(!tw_facts('Замовлення обробляються в робочі дні з 10 до 18 годин.','dispatch'),'Processing hours are not dispatch hours');
tw_expect(count(tw_facts('Замовлення відправляються на наступний робочий день після отримання передоплати.','dispatch'))===1,'Actual conditional dispatch survives');
tw_expect(!tw_facts('Возврат денег продавцу — 20 грн + 2%.','returns'),'COD transfer fee is not a goods return policy');
$text=\Lavka\competitor_commercial_text("Оплата\nпереказ на карту або розрахунковий рахунок.\nготівкою або через термінал у разі Самовивезення.",'Умови');
$f=tw_facts($text,'payment_methods');tw_expect(in_array('На счёт',array_column($f,'value'),true)&&in_array('Картой',array_column($f,'value'),true),'Split bank transfer and terminal text recognized');
echo "Public terms wording: passed\n";
