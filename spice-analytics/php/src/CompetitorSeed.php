<?php

declare (strict_types=1);
namespace Lavka;

/**
 * Product priorities and requested packs supplied by the user.
 * Public product IDs identify the page; repeated packs share one target.
 * This seed contains no sales/customer figures and assigns no current prices.
 * Equivalence to a competitor's product must be confirmed separately.
 */
function competitors_priority_targets(): array
{
    $products = [
        990 => ['Перець чорний в/г, середний помел', ['1 кг', '200 г']],
        823 => ['Ваниль натуральная молотая с сахаром', ['1 кг', '50 г', '200 г']],
        1212 => ['Куркума з чорним перцем 550 мг, 100 капсул', ['1 шт.']],
        1232 => ['Кориця Цейлонська мелена (справжня кориця)', ['50 г', '20 г']],
        1491 => ['Магній (цитрат + гліцинат), 60 капс', ['1 шт.']],
        1214 => ['Тріфала (Triphala) 600 мг, 100 капсул', ['1 шт.']],
        812 => ['Какао порошок преміум алкалізований, жирність 22%', ['500 г', '200 г', '1 кг', '50 г']],
        1466 => ['Тибетський травний збір (Гранат-5, Себру-5), 50 г', ['50 г']],
        991 => ['Іван-чай ферментований', ['200 г']],
        947 => ['Топлене масло Гхі (Гі), 400 мл', ['1 шт.']],
        896 => ['Кориця Цейлонська палички (справжня кориця)', ['50 г', '200 г']],
        1331 => ['Кокосовий цукор пісок', ['500 г']],
        1437 => ['Пребіотик-Форм (детокс, сорбент, клітковина)', ['500 г', '200 г']],
        974 => ['Оцет яблучний домашній, 500 мл', ['1 шт.']],
        1215 => ['Тройчатка 350 мг, 100 капсул', ['1 шт.']],
        895 => ['Дедушкина соль', ['200 г', '1 кг']],
        1452 => ['Соєвий соус Кіккоман (Kikkoman), 1 л', ['1 шт.']],
        1218 => ['Апельсинове какао', ['200 г', '50 г']],
        688 => ['Ваніль стручок 18-20 см (Бурбон), 1 шт.', ['1 шт.']],
        1006 => ['Ламінарія сублімірована (морська капуста)', ['500 г']],
        1429 => ['Корица Цейлонская 400 мг, 100 капсул', ['1 шт.']],
        1188 => ['Кардамон зелений мелений в/с', ['50 г']],
        694 => ['Кардамон зелений зерна', ['50 г', '20 г']],
        1440 => ['Печінка - Жовчний, суміш трав 450 мг, 100 капсул', ['1 шт.']],
        1234 => ['Кукурудзяно-кокосові вершки / молоко', ['500 г']],
        1067 => ['Какао терте (Schokinag)', ['200 г']],
        1386 => ['Протеїн сироватковий (ананас)', ['200 г']],
        1489 => ['Меносан (Menosan), 60 таб.', ['1 шт.']],
        1262 => ['Перець чорний мелений копчений', ['500 г']],
        1283 => ['Калінджи (чорний кмин) 520 мг, 100 капсул', ['1 шт.']],
        1240 => ['Бразильський горіх', ['500 г']],
        1282 => ['Черный чай в/с Пекое стронг', ['500 г']],
        699 => ['Куркума преміум, Індія', ['200 г', '500 г', '1 кг']],
        1481 => ['Молочний Улун (Оолонг) Те Гуань Інь чай', ['200 г']],
        1358 => ['Листы нори для суши, 100 шт.', ['1 шт.']],
        1490 => ['Вітамін D 2000 МЕ, 60 капс з оливковою олією', ['1 шт.']],
        1061 => ['Кешью горіх', ['500 г']],
        698 => ['Кориця мелена (Цинамон, Пряна кориця)', ['200 г']],
        712 => ['Перець чорний горошок', ['1 кг']],
        1456 => ['Нім 480 мг, 100 капсул', ['1 шт.']],
        1032 => ['Рицинова олія (касторка), 200 мл', ['1 шт.']],
        1174 => ['Спіруліна таблетки (390 табл. в 100 грамах)', ['200 г']],
        815 => ['Індійська чорна сіль', ['500 г']],
        1222 => ['Трікату - детокс Trikatu 570 мг, 100 капсул', ['1 шт.']],
        1462 => ['Стресс Гард (Stress Guard), тоник для нервов, адаптоген, 60 капс.', ['1 шт.']],
        724 => ['Часник гранули', ['200 г']],
        1307 => ['Псіллум, псіліум (лушпиння насіння подорожника)', ['200 г']],
        1231 => ['Гречаний чай (Ку Цяо)', ['200 г']],
        966 => ['Овочевий суп', ['200 г']],
        1048 => ['Паприка копченая (сладкая)', ['200 г']],
        868 => ['Перець довгий (Піппалі)', ['200 г']],
        926 => ['Какао масло натуральне', ['200 г']],
        1387 => ['Протеїн сироватковий (полуниця)', ['200 г']],
        1480 => ['Ламінарія (джерело природного йоду) 650 мг, 100 капсул', ['1 шт.']],
        1028 => ['Кокосові вершки сухі', ['500 г']],
        689 => ['Гвоздика', ['50 г']],
    ];
    $targets = [];
    foreach ($products as $id => $product) {
        $targets[] = [
            'seed_key' => 'priority-lavka-' . $id,
            'source_id' => 'lavka',
            'kind' => 'product',
            'url' => 'https://lavka-speciy.com/index.php?route=product/product&product_id=' . $id,
            'label' => $product[0],
            'pack_label' => implode(' / ', $product[1]),
            'grams_override' => null,
            'match_group' => 'lavka-' . $id,
            'match_confirmed' => false,
            'enabled' => true,
        ];
    }
    return $targets;
}

/**
 * Public product pages found for an initial, deliberately unconfirmed shortlist.
 * A matching group is only a review suggestion, never proof of equivalence.
 * No current price, availability, pack weight or successful check is seeded.
 */
function competitors_candidate_targets(): array
{
    $candidates = [
        ['deluxe', 812, 'Какао темный Premium quality 22%', 'https://deluxe.com.ua/catalog/confectionery/cacao-temnuj-premium-quality.html'],
        ['deluxe', 896, 'Корица цейлонская настоящая', 'https://deluxe.com.ua/catalog/spetsii/korica-ceylonskaya.html'],
        ['deluxe', 699, 'Куркума молотая Премиум', 'https://deluxe.com.ua/catalog/spetsii/kurkuma.html'],
        ['deluxe', 694, 'Кардамон зеленый', 'https://deluxe.com.ua/catalog/spetsii/cardamon.html'],
        ['deluxe', 1048, 'Копченая паприка Пиментон ASTA 180', 'https://deluxe.com.ua/catalog/spetsii/paprika-kopchenaya.html'],
        ['deluxe', 1331, 'Сахар кокосовый', 'https://deluxe.com.ua/catalog/confectionery/sahar-kokosovyiy.html'],
        ['spicery', 990, 'Перец черный крупного помола дробленый', 'https://spiceryshop.com.ua/ru/content/products/perec-chernyj-krupnogo-pomola-droblenyj'],
        ['spicery', 1232, 'Корица цейлонская (корица настоящая) С4 молотая', 'https://spiceryshop.com.ua/ru/content/products/korica-cejlonskaya-korica-nastoyaschaya-s4-molotaya'],
        ['spicery', 896, 'Корица цейлонская (корица настоящая) палочки С4', 'https://spiceryshop.com.ua/ru/content/products/korica-cejlonskaya-korica-nastoyaschaya-palochki-s4'],
        ['spicery', 694, 'Кардамон зеленый целый', 'https://spiceryshop.com.ua/ru/content/products/kardamon-zelenyi-tselyi-vs'],
        ['spicery', 699, 'Куркума молотая в/с', 'https://spiceryshop.com.ua/ru/content/products/kurkuma-molotaya-vs'],
        ['spicery', 689, 'Гвоздика целая', 'https://spiceryshop.com.ua/ru/content/products/gvozdika-tselaya'],
        ['banka', 1048, 'Паприка мелена копчена', 'https://banka-speciy.in.ua/ua/paprika-copchenaya-molotaya'],
        ['banka', 699, 'Куркума мелена преміум', 'https://banka-speciy.in.ua/ua/kurkuma-kupit'],
        ['banka', 1188, 'Кардамон мелений', 'https://banka-speciy.in.ua/ua/kardamon-poroshok'],
        ['banka', 1061, "Кеш'ю, сирий", 'https://banka-speciy.in.ua/ua/keshyu-siroy'],
        ['banka', 689, 'Гвоздика', 'https://banka-speciy.in.ua/ua/gvozdika-priprava'],
        ['banka', 812, 'Какао алкалізоване', 'https://banka-speciy.in.ua/ua/kakao-alkalizirovannoe'],
        ['specii', 896, 'Кориця цейлонська ALBA', 'https://www.specii.in.ua/ua/catalog/ekzoticheskie-pryanosti/koritsa-tseylonskaya-alba/'],
        ['specii', 689, 'Гвоздика ціла', 'https://www.specii.in.ua/ua/catalog/tselye-pryanosti/gvozdika-tselaya/'],
        ['specii', 699, 'Куркума мелена (скло)', 'https://www.specii.in.ua/ua/catalog/natural-spices-in-glass/turmeric-glass/'],
        ['specii', 724, 'Часник гранульований (скло)', 'https://www.specii.in.ua/ua/catalog/natural-spices-in-glass/granulated-garlic-glass/'],
    ];
    $targets = [];
    foreach ($candidates as $candidate) {
        $targets[] = [
            'seed_key' => 'candidate-' . $candidate[0] . '-lavka-' . $candidate[1],
            'source_id' => $candidate[0],
            'kind' => 'product',
            'url' => $candidate[3],
            'label' => $candidate[2],
            'pack_label' => '',
            'grams_override' => null,
            'match_group' => 'lavka-' . $candidate[1],
            'match_confirmed' => false,
            'enabled' => true,
        ];
    }
    return $targets;
}
