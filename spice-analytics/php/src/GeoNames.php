<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

function geo_text(?string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value ?? ''));
    return in_array(normalize($value), ['', '-', '—', '.', '0', 'null', 'none', 'unknown', 'n/a', 'не указан', 'не указано', 'не вказано', 'не определена', 'не определен', 'не определён', 'город не указан', 'місто не вказано'], true) ? '' : $value;
}
function geo_city_text(?string $value): string
{
    $value = geo_text($value);
    return in_array(normalize($value), ['самовывоз','самовивіз','pickup','без адреса','без адреси'], true) ? '' : $value;
}
function geo_name_key(string $value): string
{
    return preg_replace('/[^\p{L}\p{N}]+/u', '', str_replace('ё', 'е', normalize($value)));
}
function geo_regions(): array
{
    static $regions;
    if ($regions !== null) return $regions;
    $rows = [
        ['UA-05','Вінницька область','Винницкая|Vinnytsia|Vinnytsya|Vinnitsa|Vinnytska'],
        ['UA-07','Волинська область','Волынская|Volyn|Volynska|Volyns’ka'],
        ['UA-09','Луганська область','Луганская|Luhansk|Lugansk|Luhanska|Luhans’k'],
        ['UA-12','Дніпропетровська область','Днепропетровская|Dnipropetrovsk|Dnepropetrovsk|Dnipropetrovska'],
        ['UA-14','Донецька область','Донецкая|Donetsk|Donetska|Donets’k'],
        ['UA-18','Житомирська область','Житомирская|Zhytomyr|Zhitomir|Zhytomyrska'],
        ['UA-21','Закарпатська область','Закарпатская|Zakarpattia|Transcarpathia|Zakarpatska'],
        ['UA-23','Запорізька область','Запорожская|Zaporizhzhia|Zaporizhzhya|Zaporizhia|Zaporozhye|Zaporizka'],
        ['UA-26','Івано-Франківська область','Ивано-Франковская|Ivano-Frankivsk|Ivano-Frankivs’k|Ivano-Frankivska'],
        ['UA-30','Київ','Киев|Kyiv|Kiev|Kyiv City|Kiev City|м. Київ|г. Киев'],
        ['UA-32','Київська область','Киевская|Kyiv Oblast|Kiev Oblast|Kyivska|Kievskaya'],
        ['UA-35','Кіровоградська область','Кировоградская|Kirovohrad|Kirovograd|Kirovohradska'],
        ['UA-40','Севастополь','Sevastopol|Sevastopol City|м. Севастополь'],
        ['UA-43','Автономна Республіка Крим','Крим|Крым|АР Крим|АР Крым|Автономная Республика Крым|Crimea|Autonomous Republic of Crimea'],
        ['UA-46','Львівська область','Львовская|Lviv|Lvov|L’viv|Lvivska'],
        ['UA-48','Миколаївська область','Николаевская|Mykolaiv|Mykolayiv|Nikolayev|Mykolaivska'],
        ['UA-51','Одеська область','Одесская|Odesa|Odessa|Odeska|Odesskaya'],
        ['UA-53','Полтавська область','Полтавская|Poltava|Poltavska'],
        ['UA-56','Рівненська область','Ровненская|Rivne|Rovno|Rivnenska'],
        ['UA-59','Сумська область','Сумская|Sumy|Sumska'],
        ['UA-61','Тернопільська область','Тернопольская|Ternopil|Ternopol|Ternopil’s’ka'],
        ['UA-63','Харківська область','Харьковская|Kharkiv|Kharkov|Kharkivska'],
        ['UA-65','Херсонська область','Херсонская|Kherson|Khersonska'],
        ['UA-68','Хмельницька область','Хмельницкая|Khmelnytskyi|Khmelnytskyy|Khmelnitskiy|Khmelnytska'],
        ['UA-71','Черкаська область','Черкасская|Cherkasy|Cherkassy|Cherkaska'],
        ['UA-74','Чернігівська область','Черниговская|Chernihiv|Chernigov|Chernihivska'],
        ['UA-77','Чернівецька область','Черновицкая|Chernivtsi|Chernovtsy|Chernivetska'],
    ];
    $regions = ['rows' => [], 'aliases' => []];
    foreach ($rows as $row) {
        [$code, $label, $aliases] = $row;
        $regions['rows'][$code] = $label;
        foreach (array_merge([$label, $code], explode('|', $aliases)) as $alias) $regions['aliases'][geo_region_key($alias)] = $code;
    }
    return $regions;
}
function geo_region_key(string $value): string
{
    return geo_name_key(preg_replace('/(?:область|області|области|обл\.?|oblast[’\x{0027}]?|region|province)/iu', '', $value));
}
function geo_region(string $value): string
{
    return geo_regions()['aliases'][geo_region_key(geo_text($value))] ?? '';
}
function geo_country(string $name, string $code = ''): array
{
    $name = geo_text($name);$key = geo_name_key($name);
    $ua = in_array($key, ['ua','ukr','україна','украина','ukraine','ukraina'], true);
    if ($ua || $code === 'UA') return ['Україна', 'UA'];
    if ($code !== '') return [$name, $code];
    if ($name === '') return ['', ''];
    $known = ['pl'=>['Polska','Poland','Польща','Польша'],'de'=>['Deutschland','Germany','Німеччина','Германия'],'gb'=>['United Kingdom','UK','Велика Британія','Великобритания'],'us'=>['USA','United States','США'],'by'=>['Беларусь','Білорусь','Belarus'],'ru'=>['Россия','Росія','Russia'],'md'=>['Молдова','Moldova'],'ro'=>['Румыния','Румунія','Romania'],'cz'=>['Чехия','Чехія','Czechia','Czech Republic'],'sk'=>['Словакия','Словаччина','Slovakia'],'fr'=>['Франция','Франція','France'],'it'=>['Италия','Італія','Italy'],'es'=>['Испания','Іспанія','Spain']];
    foreach ($known as $iso => $aliases) if ($key === $iso || in_array($key, array_map(__NAMESPACE__.'\\geo_name_key', $aliases), true)) return [$name, strtoupper($iso)];
    return [$name, ''];
}
function geo_city_reference(): array
{
    static $reference;
    if ($reference === null) {
        $data = decode(file_get_contents(__DIR__.'/ukraine-locations.json'));
        $reference = ['cities' => $data['cities'], 'aliases' => [], 'homonyms' => $data['homonyms'], 'version' => $data['version']];
        foreach ($data['cities'] as $i => $city) foreach (array_unique(array_merge([$city['name']], $city['aliases'])) as $alias) $reference['aliases'][geo_name_key($alias)][$i] = true;
    }
    return $reference;
}
function geo_city_part(string $city): string
{
    $city = geo_text($city);
    // A village must not be turned into a homonymous city by dropping its type.
    if (preg_match('/^(?:с\.|село\b|селище\b|смт\b|пгт\b)/iu', $city)) return $city;
    $city = preg_replace('/^(?:(?:город|місто)\s+|[гм]\.\s*|[гм]\s+)(?=\p{L})/iu', '', $city);
    if(preg_match('/^(.+?)\s*\(([^()]*)\)\s*$/u',$city,$parts)&&geo_region($parts[2])!=='')$city=trim($parts[1]);
    return trim(explode(',', $city, 2)[0]);
}
function geo_canonical(array $location): array
{
    $rawCity = geo_city_text($location['city'] ?? '');$rawZone = geo_text($location['zone'] ?? '');
    [$country, $countryCode] = geo_country($location['country'] ?? '', $location['country_code'] ?? '');
    $location = array_merge(['city'=>'','zone'=>'','zone_code'=>'','country'=>'','country_code'=>'','source'=>'','region_inferred'=>false,'city_matched'=>false,'city_conflict'=>false], $location);
    $location['city'] = $rawCity;$location['zone'] = $rawZone;$location['country'] = $country;$location['country_code'] = $countryCode;
    $location['original_city'] = $location['original_city'] ?? $rawCity;
    $location['original_zone'] = $location['original_zone'] ?? $rawZone;
    if (($countryCode !== '' && $countryCode !== 'UA') || ($country !== '' && $countryCode === '')) return $location;
    $zoneCode = isset(geo_regions()['rows'][$location['zone_code']]) ? $location['zone_code'] : geo_region($rawZone);
    if($zoneCode===''&&$rawZone===''&&preg_match('/\(([^()]*)\)\s*$/u',$rawCity,$parts))$zoneCode=geo_region($parts[1]);
    if ($zoneCode !== '') {
        $location['zone_code'] = $zoneCode;$location['zone'] = geo_regions()['rows'][$zoneCode];
        $location['country'] = 'Україна';$location['country_code'] = 'UA';
    }
    $city = geo_city_part($rawCity);$reference = geo_city_reference();$key = geo_name_key($city);
    $candidates = array_keys($reference['aliases'][$key] ?? []);
    if ($zoneCode !== '') $candidates = array_values(array_filter($candidates, function ($i) use ($reference, $zoneCode) {
        $code = $reference['cities'][$i]['zone_code'];
        return $code === $zoneCode || ($zoneCode === 'UA-32' && $code === 'UA-30');
    }));
    if (count($candidates) === 1) {
        $known = $reference['cities'][$candidates[0]];
        $location['city'] = $known['name'];$location['city_matched'] = true;
        $location['country'] = 'Україна';$location['country_code'] = 'UA';
        if ($zoneCode !== '' || !isset($reference['homonyms'][$key])) {
            $location['region_inferred'] = $zoneCode === '';
            $location['zone_code'] = $known['zone_code'];$location['zone'] = geo_regions()['rows'][$known['zone_code']];
        }
    } elseif ($zoneCode !== '' && isset($reference['aliases'][$key]) && !$candidates) $location['city_conflict'] = true;
    return $location;
}
