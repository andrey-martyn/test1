<?php
declare(strict_types=1);
namespace Lavka;

final class Geo {
    private mixed $reader=null;
    private ?string $error=null;
    private array $cache=[];
    public function __construct(string $directory){
        $autoload=dirname(__DIR__).'/vendor/autoload.php';if(is_file($autoload))require_once $autoload;
        $path=$directory.'/geoip-city.mmdb';
        if(is_file($path)){
            try {if(!class_exists('MaxMind\\Db\\Reader'))throw new \RuntimeException('reader_missing');$this->reader=new \MaxMind\Db\Reader($path);}
            catch(\Throwable $e){$this->error='GeoIP недоступен';}
        }
    }
    public function metadata(): array {return ['available'=>$this->reader!==null,'database'=>'GeoIP City / local MMDB','error'=>$this->error,'provider'=>'DB-IP Lite / MaxMind-compatible','note'=>'Приблизительное местоположение сети на дату геобазы; не адрес доставки.'];}
    public function lookup(?string $ip): ?array {
        $ip=trim($ip??'');if(array_key_exists($ip,$this->cache))return $this->cache[$ip];
        if(!$this->reader||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return $this->cache[$ip]=null;
        try {$r=$this->reader->get($ip);}catch(\Throwable $e){return $this->cache[$ip]=null;}
        if(!$r)return $this->cache[$ip]=null;
        $country=$r['country']??[];$code=$country['iso_code']??'';$sub=$r['subdivisions'][0]??[];
        $label=fn($item)=>$item['names']['uk']??$item['names']['ru']??$item['names']['en']??'';
        return $this->cache[$ip]=['city'=>$label($r['city']??[]),'zone'=>$label($sub),'zone_code'=>$code&&isset($sub['iso_code'])?$code.'-'.$sub['iso_code']:'','country'=>$label($country),'country_code'=>$code,'source'=>'IP · приблизительно'];
    }
    public function address(array $o): array {
        $shipping=(bool)trim(($o['shipping_city']??'')?:($o['shipping_zone']??''));$prefix=$shipping?'shipping_':'payment_';
        $country=($o[$prefix.'country']??'')?:($o['shipping_country']??'')?:($o['payment_country']??'');
        return ['city'=>($o[$prefix.'city']??'')?:'Город не указан','zone'=>$o[$prefix.'zone']??'','zone_code'=>'','country'=>$country,'country_code'=>in_array(normalize($country),['україна','украина','ukraine'],true)?'UA':'','source'=>$shipping?'Адрес доставки':'Адрес оплаты'];
    }
    public function resolve(array $o,string $mode='mixed'): array {
        $address=$this->address($o);if($mode==='address')return $address;$ip=$this->lookup($o['ip']??'');
        if($mode==='ip')return $ip??['city'=>'IP не определён','zone'=>'','zone_code'=>'','country'=>'Не определена','country_code'=>'','source'=>'IP · нет результата'];
        return $address['zone']?$address:($ip??$address);
    }
}
