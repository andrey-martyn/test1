<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Gender.php';
function nd_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['Міла','Марианна','Дарія','Софико','Юліана','Юлiя','Павлина','Тоня','Гаяне','Альона','Інгрит'] as $name)nd_expect(\Lavka\name_gender_estimate($name)==='female','Recognize established spelling: '.$name);
nd_expect(\Lavka\name_gender_estimate('Айрат')==='male','Recognize Ayrat');
foreach(['Саша','Женя','Міріс','Богоцька','Єлеонська','Шевченко','TARASENKO'] as $name)nd_expect(\Lavka\name_gender_estimate($name)==='unknown','Do not infer gender from unknown names or surnames');
nd_expect(\Lavka\name_gender_details('Лавка')['reason']==='organization','Shop name is not a person');
nd_expect(\Lavka\name_gender_details('Шевченко')['reason']==='possible_surname','Explain missing first name');
nd_expect(\Lavka\name_gender_estimate('Olga Петренко')==='female','Pure Latin name is preserved');
$orders=[];foreach(['','Test test','Т','n','Таня','Татьяна','Unknown','Виктор'] as $name)$orders[]=['firstname'=>$name];
$details=\Lavka\customer_gender_details($orders);
nd_expect($details['gender']==='unknown'&&$details['reason']==='conflicting_profile','Mixed history remains unknown');
nd_expect(count($details['examples'])<=6&&in_array('Виктор',$details['examples'],true)&&in_array('Таня',$details['examples'],true),'Conflict examples show both signals even after first six rows');
echo json_encode(['diagnostic_name_variants'=>'passed','unknowns_remain_conservative'=>'passed','representative_conflicts'=>'passed'])."\n";
