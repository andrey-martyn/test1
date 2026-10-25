<?php

declare(strict_types=1);
namespace Lavka;

/** Local, explainable product rules. The shop name is never packaging evidence. */
function competitors_identity_name(string $name): string
{
    $name=preg_replace('/\s*(?:[|—–]| - )\s*(?:банка\s+спец[иі][йії]|banka\s+spice).*$/iu','',$name);
    return trim($name);
}
function competitors_identity_jar(string $name,string $url=''): bool
{
    $text=mb_strtolower(competitors_identity_name($name).' '.rawurldecode(parse_url($url,PHP_URL_PATH)?:''));
    return (bool)preg_match('/(?<!\p{L})(?:бан(?:ка|ки|ке|ку|ці|очк\p{L}*)|скло|склян\p{L}*|стекл\p{L}*|пластиков\p{L}*\s+(?:бан\p{L}*|ємні\p{L}*|емк\p{L}*|контейнер\p{L}*)|пэт|пет|jar|glass|bank[aeiu]|v-banke|v-bantsi|in-jar)(?!\p{L})/u',$text);
}
function competitors_identity_profile(string $name,string $url=''): array
{
    $tokens=competitors_name_tokens(competitors_identity_name($name));
    $forms=['ground','whole','crushed','granulated','flakes','seed','leaf','dried','smoked','ceylon','cassia','black','white','red'];
    $core=array_values(array_diff($tokens,$forms,['doypack','zip','kraft','loose']));sort($core);
    preg_match_all('/(?<![\pL\d])(\d{1,3}(?:[.,]\d{1,3})?)\s*%/u',$name,$matches);
    $percentages=[];foreach($matches[1] as $value)$percentages[(string)(float)str_replace(',','.',$value)]=true;
    $percentages=array_keys($percentages);sort($percentages,SORT_NUMERIC);
    return ['tokens'=>$tokens,'core'=>$core,'percentages'=>$percentages,'jar'=>competitors_identity_jar($name,$url)];
}
function competitors_identity_compare(string $own,string $candidate,string $url='',?array $a=null,?array $b=null): array
{
    $a=$a??competitors_identity_profile($own);$b=$b??competitors_identity_profile($candidate,$url);
    if($b['jar'])return ['score'=>0,'exact'=>false,'reason'=>'Банка или пластиковая ёмкость — исключено'];
    if(in_array('seed',$a['tokens'],true)&&in_array('seed',$b['tokens'],true)
        &&in_array('ground',$a['tokens'],true)!==in_array('ground',$b['tokens'],true))return ['score'=>0,'exact'=>false,'reason'=>'Целые и молотые семена — разные позиции'];
    $score=competitors_name_score($a['tokens'],$b['tokens']);
    if(!$score)return ['score'=>0,'exact'=>false,'reason'=>'Разный продукт или форма'];
    if(($a['percentages']??[])!==($b['percentages']??[]))return ['score'=>min(55,$score),'exact'=>false,'reason'=>'Различается или не указан процент состава — проверьте концентрацию'];
    $critical=['ground','whole','crushed','granulated','flakes','seed','leaf','smoked','ceylon','cassia','black','white','red'];
    $x=array_values(array_intersect($a['tokens'],$critical));$y=array_values(array_intersect($b['tokens'],$critical));sort($x);sort($y);
    $exact=$a['core']&&$a['core']===$b['core']&&$x===$y;
    if($exact)$score=100;
    else if($x!==$y)$score=min(55,$score);
    return ['score'=>$score,'exact'=>(bool)$exact,'reason'=>$exact?'Совпадают продукт и форма; упаковка проверяется по карточке':'Уточнить состав, форму или сорт'];
}
