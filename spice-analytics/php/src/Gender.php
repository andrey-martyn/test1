<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

const NAME_GENDER_LABELS = ['male' => 'Мужчины', 'female' => 'Женщины', 'unknown' => 'Не определено'];

function name_gender_key(string $name): string
{
    return str_replace(['ё', "'", '’', 'ʼ', '`'], ['е', '', '', '', ''], mb_strtolower(trim($name), 'UTF-8'));
}

/** A local, deliberately conservative name dictionary; never a confirmed profile attribute. */
function name_gender_dictionary(): array
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $lists = [
            'male' => 'Август Авраам Адам Адріан Адриан Азамат Аким Алан Александр Александер Александар Олександр Алексей Олексій Анатолий Анатолій Андрей Андрій Андрэй Андреас Антон Аркадий Аркадій Арсен Арсеній Арсений Артем Артём Артемий Артемій Артур Архип Афанасий Богдан Борис Бронислав Вадим Валентин Валерий Валерій Василий Василь Веніамін Вениамин Виктор Віктор Виталий Віталій Владимир Володимир Владислав Всеволод Вячеслав В’ячеслав Гавриил Гаврило Геннадий Геннадій Георгий Георгій Герман Глеб Гліб Гордей Гордій Григорий Григорій Давид Даниил Данил Данило Данііл Денис Дмитрий Дмитро Евгений Євген Євгеній Евдоким Егор Єгор Елисей Єлисей Ефим Юхим Захар Зеновий Зеновій Зиновий Зіновій Иван Іван Игнат Гнат Игорь Ігор Илья Ілля Иннокентий Йосип Иосиф Ираклий Кирилл Кирило Клим Климент Константин Костянтин Кузьма Лаврентий Лев Леон Леонид Леонід Лука Лук’ян Макар Максим Марк Матвей Матвій Мирон Мирослав Митрофан Михаил Михайло Микола Назар Наум Нестор Никита Микита Николай Нікіта Олег Олесь Омелян Остап Павел Павло Петр Петро Пилип Филипп Платон Прохор Радислав Радомир Рафаэль Рафаїл Ренат Ринат Роберт Родион Родіон Роман Ростислав Руслан Святослав Семен Семён Сергій Сергей Станислав Станіслав Степан Тарас Тимофей Тимофій Тимур Тихон Трохим Федір Федор Фёдор Фелікс Феликс Харитон Эдуард Едуард Эмиль Еміль Юлиан Юліан Юрий Юрій Яків Яков Ян Ярослав
                Aleksandr Alexander Aleksander Alexandr Oleksandr Alexey Alexei Aleksei Aleksey Oleksii Oleksiy Anatoliy Anatolii Anatoly Andrey Andrei Andrii Andriy Anton Arkadiy Arsen Arseniy Artem Artyom Artur Arthur Bohdan Bogdan Boris Borys Vadim Vadym Valentin Valentyn Valeriy Valerii Vasiliy Vasyl Viktor Victor Vitaliy Vitalii Vladimir Volodymyr Vladyslav Vladislav Vsevolod Viacheslav Vyacheslav Hennadii Gennadiy Georgiy Heorhii Herman Gleb Hlib Grigoriy Hryhorii David Danylo Daniil Danil Denys Denis Dmitry Dmitriy Dmytro Evgeniy Evgeny Yevhen Yevhenii Egor Yehor Ivan Ihor Igor Ilya Illia Iosif Yosyp Kirill Kyrylo Konstantin Kostiantyn Lev Leon Leonid Luka Lukian Maksym Maxim Mark Matvii Matvey Miron Myron Mykhailo Mikhail Mykola Nazar Nikita Mykyta Nikolay Nikolai Oleg Oleh Ostap Pavel Pavlo Petr Petro Philip Pylyp Roman Rostyslav Rostislav Ruslan Sviatoslav Semen Semyon Sergey Sergei Serhii Serhiy Stanislav Stanislaw Stepan Taras Timofey Tymofii Timur Tikhon Fedor Fedir Felix Eduard Emil Yuriy Yurii Yuri Yakov Yakiv Yan Jan Yaroslav',
            'female' => 'Августа Аврора Агата Агафья Агнесса Аделина Аза Аида Аліна Алина Алиса Аліса Алла Альбина Альбіна Амалия Амалія Анастасия Анастасія Ангелина Ангеліна Анжела Анжелика Анжеліка Анна Антонина Антоніна Арина Аріна Ася Афина Афіна Бажена Белла Богдана Божена Валентина Валерия Валерія Ванда Варвара Василина Василиса Василіса Василина Вера Віра Вероника Вероніка Виктория Вікторія Виолетта Віолетта Вита Віта Виталина Віталіна Влада Владислава Галина Ганна Дана Дарина Дарья Дар’я Даша Джамиля Диана Діана Дина Діна Доминика Домініка Ева Єва Евгения Євгенія Евдокия Євдокія Екатерина Катерина Елена Олена Елизавета Єлизавета Ефросинья Жанна Зинаида Зінаїда Злата Зоряна Зоя Иванна Іванна Илона Ілона Инга Інга Инесса Інеса Инна Інна Ира Іра Ирина Ірина Ия Ія Камилла Каміла Карина Каріна Каролина Кароліна Катя Кира Кіра Клавдия Клавдія Кристина Крістіна Христина Ксения Ксенія Лада Лариса Леся Лиана Ліана Лидия Лідія Лилия Лілія Лиля Ліля Лина Ліна Лия Лія Лолита Лоліта Любовь Любов Люба Людмила Люся Майя Мальвина Мальвіна Маргарита Марина Мария Марія Марта Марьяна Мар’яна Маша Мелания Меланія Мила Милана Мілана Милена Мілена Мирослава Надежда Надія Надя Наталия Наталія Наталья Наталя Ната Нелли Неллі Ника Ніка Николь Ніколь Нина Ніна Нонна Оксана Олеся Ольга Оля Полина Поліна Раиса Раїса Регина Регіна Рената Римма Рита Роза Роксолана Руслана Сабина Сабіна Светлана Світлана Серафима Снежана Сніжана Соломия Соломія Соня София Софія Софья Станислава Станіслава Стефания Стефанія Сусанна Тамара Таисия Таїсія Таня Татьяна Тетяна Ульяна Уляна Фаина Фаїна Фатима Фатіма Флора Хелен Циля Эвелина Евеліна Элеонора Елеонора Элина Еліна Эльвира Ельвіра Эмма Емма Юлия Юлія Юля Юстина Яна Янина Яніна Ярина Ярослава
                Alina Alice Alisa Alla Albina Anastasia Anastasiya Anastasiia Anhelina Angelina Angela Anzhela Anna Antonina Arina Asya Bella Bohdana Bogdana Bozhena Valentyna Valentina Valeria Valeriya Valeriia Varvara Vira Vera Veronika Veronica Viktoriia Viktoriya Victoria Violetta Vita Vitalina Vlada Vladyslava Halyna Galina Hanna Ganna Dana Daria Dariya Dariia Daryna Darina Darya Dasha Diana Dina Dominika Eva Yevheniia Yevgeniya Ekaterina Katerina Kateryna Elena Olena Elizaveta Yelyzaveta Zhanna Zinaida Zlata Zoriana Zoya Ivanna Ilona Inga Inna Ira Iryna Irina Iia Iya Karina Karyna Karolina Katia Katya Kira Klavdiia Kristina Khrystyna Kseniia Ksenia Ksenya Lada Larysa Larisa Lesia Lesya Lidia Lidiia Liliia Liliya Lilia Lili Lila Lina Liia Liya Liubov Lyubov Liuba Lyuba Liudmyla Lyudmila Maia Maya Marharyta Margarita Maryna Marina Mariia Mariya Maria Mary Marta Mariana Maryana Marianna Melaniia Milana Milena Myroslava Nadiia Nadiya Nadezhda Nadia Nadya Natalia Natalya Natalija Nataliya Nataliia Natali Nata Nelli Nelly Nika Nicole Nina Nonna Oksana Olesia Olesya Olha Olga Olya Polina Raisa Regina Rehina Renata Rimma Rita Roza Roksolana Ruslana Sabina Svitlana Svetlana Serafima Snezhana Snizhana Solomiia Solomiya Sonia Sonya Sofiia Sofia Sofiya Sophia Stanislava Stefaniia Susanna Tamara Taisiia Taisiya Tania Tanya Tatiana Tatyana Tetiana Tetyana Uliana Ulyana Faina Fatima Flora Evelina Eleonora Elina Elvira Emma Yuliia Yuliya Yulia Julia Yulya Yana Yanina Yaryna Yaroslava',
        ];
        foreach ($lists as $gender => $list) {
            foreach (preg_split('/\s+/u', trim($list)) as $name) {
                $key = name_gender_key($name);
                $names[$key] = isset($names[$key]) && $names[$key] !== $gender ? 'unknown' : $gender;
            }
        }
        foreach (explode(' ', 'витя ваня коля миша дима паша сережа леша костя андрюша вова володя толик толя жора гриша юра вадик vitya vanya kolya misha dima pasha seryozha lesha kostya vova volodya tolik tolya zhora grisha yura') as $name) $names[$name] = 'male';
        foreach (explode(' ', 'александра олександра анфиса анфіса анюта аня галя лена люда настя света вика ксюша лиза ліза орыся орися alexandra oleksandra aleksandra anya ania galya galia lena liuda lyuda nastia nastya sveta svieta vika ksyusha liza orysia') as $name) $names[$name] = 'female';
        // Common Ukrainian spellings and international names observed in diagnostics.
        foreach (['male'=>'Айрат Ayrat Airat', 'female'=>'Інгрит Інгрід Ингрит Ингрид Ingrid Міла Марианна Маріанна Marianna Дарія Софико Sofiko Юліана Юлиана Juliana Павлина Павліна Paulina Тоня Tonya Tonja Гаяне Gayane Альона Алёна Alyona Aliona'] as $gender=>$list) {
            foreach(preg_split('/\s+/u',$list) as $name)$names[name_gender_key($name)]=$gender;
        }
        // Shared short forms and names with more than one common interpretation.
        foreach (explode(' ', 'саша сашенька женя валя шура слава алекс сэнди сэм сэмми лесли sasha sacha zhenya valya shura slava alex sandy sam sammy leslie') as $name) $names[$name] = 'unknown';
    }
    return $names;
}

const NAME_GENDER_REASONS = [
    'recognized'=>'Имя найдено в словаре', 'recognized_component'=>'Имя найдено внутри полного имени',
    'missing'=>'Поле «Имя» пустое', 'initials'=>'Указаны инициалы',
    'ambiguous'=>'Имя подходит и мужчинам, и женщинам', 'dictionary_missing'=>'Написания пока нет в словаре',
    'possible_surname'=>'Похоже, в поле указана только фамилия', 'organization'=>'Указана организация', 'invalid_format'=>'В поле есть посторонние символы или текст',
    'conflicting_name'=>'В одном поле разные мужские и женские имена',
    'conflicting_profile'=>'В заказах объединённого профиля есть мужские и женские имена',
];

function name_gender_details(string $firstName): array
{
    $names=name_gender_dictionary();
    $name=trim(str_replace(["\xc2\xa0","\xe2\x80\x8b","\xef\xbb\xbf",'–','—','‑'],[' ','','','-','-','-'],$firstName));
    $result=function(string $gender,string $reason): array{return ['gender'=>$gender,'reason'=>$reason];};
    if($name==='')return $result('unknown','missing');
    if(mb_strlen($name,'UTF-8')>200||!preg_match("/^[\\p{L}\\p{M}\\s.'’ʼ`-]+$/uD",$name))return $result('unknown','invalid_format');
    // Repair visual Latin/Cyrillic lookalikes only in a mixed-script field.
    $name=preg_replace_callback('/[\p{L}\p{M}]+/u',function($match){$word=$match[0];return preg_match('/\p{Cyrillic}/u',$word)&&preg_match('/[A-Za-z]/',$word)?strtr($word,['A'=>'А','B'=>'В','C'=>'С','E'=>'Е','H'=>'Н','K'=>'К','M'=>'М','O'=>'О','P'=>'Р','T'=>'Т','X'=>'Х','Y'=>'У','i'=>'і','I'=>'І','a'=>'а','c'=>'с','e'=>'е','o'=>'о','p'=>'р','x'=>'х','y'=>'у']):$word;},$name);
    $parts=preg_split('/[\\s-]+/u',name_gender_key($name));
    if(array_intersect(array_map(function($p){return trim($p,'.');},$parts),['тов','фоп','ооо','іп','ип','пп','llc','shop','магазин','лавка','компания','компанія']))return $result('unknown','organization');
    $signals=[];$ambiguous=false;$initials=true;
    foreach($parts as $part){$clean=trim($part,'.');if(mb_strlen($clean,'UTF-8')>1)$initials=false;
        if(isset($names[$clean])){if($names[$clean]==='unknown')$ambiguous=true;else $signals[$names[$clean]]=true;}}
    if(count($signals)>1)return $result('unknown','conflicting_name');
    // A shared short form is never guessed from a surname or a nearby name.
    if($ambiguous)return $result('unknown','ambiguous');
    if(count($signals)===1){$gender=(string)key($signals);return $result($gender,($names[trim($parts[0],'.')]??null)===$gender?'recognized':'recognized_component');}
    $initials=$initials||(bool)preg_match('/^\p{L}\./u',$parts[0]);
    if(!$initials&&count($parts)===1&&preg_match('/(?:енко|enko|ський|ська|цький|цька|ский|ская)$/u',$parts[0]))return $result('unknown','possible_surname');
    return $result('unknown',$initials?'initials':'dictionary_missing');
}
function name_gender_estimate(string $firstName): string{return name_gender_details($firstName)['gender'];}

/** Use the resolved customer's complete eligible history, not just its latest name. */
function customer_gender_details(array $orders): array
{
    $signals=[];$reasons=[];$examples=[];$representatives=[];
    foreach($orders as $order){$name=(string)($order['firstname']??'');$detail=name_gender_details($name);
        if($detail['gender']!=='unknown'){$signals[$detail['gender']]=true;if(!isset($representatives[$detail['gender']]))$representatives[$detail['gender']]=$name;}
        $reasons[$detail['reason']]=($reasons[$detail['reason']]??0)+1;
        if(count($examples)<6)$examples[$name]=true;
    }
    if(count($signals)>1)$reason='conflicting_profile';
    elseif(count($signals)===1)$reason='recognized';
    else{arsort($reasons);$reason=(string)(key($reasons)??'missing');}
    if(count($signals)>1){$chosen=[];foreach($representatives as $name)$chosen[$name]=true;foreach($examples as $name=>$unused){if(count($chosen)>=6)break;$chosen[$name]=true;}$examples=$chosen;}
    return ['gender'=>count($signals)===1?(string)key($signals):'unknown','reason'=>$reason,'examples'=>array_map('strval',array_keys($examples))];
}
function customer_gender_estimate(array $orders): string{return customer_gender_details($orders)['gender'];}

function gender_diagnostics(array $groups,array $details,array $period,DSU $dsu): array
{
    $ids=[];foreach($period as $order)$ids[$dsu->find($order['order_id'])]=true;
    $result=['customer_count'=>0,'reasons'=>[],'samples'=>[]];
    foreach($ids as $id=>$unused){$detail=$details[$id];if($detail['gender']!=='unknown')continue;
        ++$result['customer_count'];$reason=$detail['reason'];$result['reasons'][$reason]=($result['reasons'][$reason]??0)+1;
        if(count($result['samples'])<30)$result['samples'][]=['customer_id'=>(string)$id,'reason'=>$reason,'names'=>$detail['examples'],'order_ids'=>array_map('strval',array_slice(array_column($groups[$id],'order_id'),0,6))];
    }
    return $result;
}

function gender_sales_breakdown(array $orders, DSU $dsu, array $genders): array
{
    $rows = [];
    foreach (NAME_GENDER_LABELS as $key => $label) $rows[$key] = ['key' => $key, 'label' => $label, 'customer_count' => 0, 'order_count' => 0, 'amount' => '0', 'average' => 0, 'amount_share' => 0, '_customers' => []];
    $total = '0';
    foreach ($orders as $order) {
        $id = $dsu->find($order['order_id']);
        $row =& $rows[$genders[$id] ?? 'unknown'];
        $row['_customers'][$id] = true;
        ++$row['order_count'];
        $amount = order_value($order);
        $row['amount'] = plus($row['amount'], $amount);
        $total = plus($total, $amount);
        unset($row);
    }
    foreach ($rows as &$row) {
        $row['customer_count'] = count($row['_customers']);
        unset($row['_customers']);
        $row['average'] = $row['order_count'] ? money(bcdiv($row['amount'], (string)$row['order_count'], 12)) : 0;
        $row['amount_share'] = bccomp($total, '0', 12) > 0 ? even_round((float)bcdiv($row['amount'], $total, 12) * 100, 1) : 0;
        $row['amount'] = money($row['amount']);
    }
    unset($row);
    return array_values($rows);
}
