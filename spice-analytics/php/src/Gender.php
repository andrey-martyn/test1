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
function name_gender_estimate(string $firstName): string
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
        // Shared short forms and names with more than one common interpretation.
        foreach (explode(' ', 'саша сашенька женя валя шура слава алекс сэнди сэм сэмми лесли sasha sacha zhenya valya shura slava alex sandy sam sammy leslie') as $name) $names[$name] = 'unknown';
    }
    $firstName = trim($firstName);
    if ($firstName === '' || mb_strlen($firstName, 'UTF-8') > 200 || !preg_match("/^[\\p{L}\\p{M}\\s.'’ʼ`-]+$/uD", $firstName)) return 'unknown';
    $parts = preg_split('/[\s-]+/u', name_gender_key($firstName));
    if (array_intersect($parts, ['тов', 'фоп', 'ооо', 'іп', 'ип', 'пп', 'llc', 'shop', 'магазин', 'компания', 'компанія'])) return 'unknown';
    $gender = $names[$parts[0]] ?? 'unknown';
    if ($gender === 'unknown') return 'unknown';
    foreach (array_slice($parts, 1) as $part) {
        if (isset($names[$part]) && $names[$part] !== 'unknown' && $names[$part] !== $gender) return 'unknown';
    }
    return $gender;
}

/** Use the resolved customer's complete eligible history, not the latest name or selected year. */
function customer_gender_estimate(array $orders): string
{
    $signals = [];
    foreach ($orders as $order) {
        $gender = name_gender_estimate((string)($order['firstname'] ?? ''));
        if ($gender !== 'unknown') $signals[$gender] = true;
    }
    return count($signals) === 1 ? (string)key($signals) : 'unknown';
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
