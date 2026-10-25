<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__.'/GeoNames.php';

final class Geo
{
    private $reader = null;
    private $error = null;
    private $cache = [];
    private $addressCache = [];
    public function __construct(string $directory)
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        $path = $directory . '/geoip-city.mmdb';
        if (is_file($path)) {
            try {
                if (!class_exists('MaxMind\\Db\\Reader')) throw new \RuntimeException('reader_missing');
                $this->reader = new \MaxMind\Db\Reader($path);
            } catch (\Throwable $e) { $this->error = 'GeoIP недоступен'; }
        }
    }
    public function metadata(): array
    {
        return ['available'=>$this->reader!==null,'database'=>'GeoIP City / local MMDB','error'=>$this->error,'provider'=>'DB-IP Lite / MaxMind-compatible',
            'note'=>'Приблизительное местоположение сети на дату геобазы; не адрес доставки.',
            'reference'=>['version'=>geo_city_reference()['version'],'places'=>count(geo_city_reference()['cities'])]];
    }
    public function publicIp(?string $ip): bool
    {
        return (bool)filter_var(trim($ip ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    public function lookup(?string $ip): ?array
    {
        $ip = trim($ip ?? '');
        if (array_key_exists($ip, $this->cache)) return $this->cache[$ip];
        if (!$this->reader || !$this->publicIp($ip)) return $this->cache[$ip] = null;
        try { $r = $this->reader->get($ip); } catch (\Throwable $e) { return $this->cache[$ip] = null; }
        if (!$r) return $this->cache[$ip] = null;
        $country = $r['country'] ?? [];$code = $country['iso_code'] ?? '';$sub = $r['subdivisions'][0] ?? [];
        $label = function ($item) { return $item['names']['uk'] ?? $item['names']['ru'] ?? $item['names']['en'] ?? ''; };
        return $this->cache[$ip] = geo_canonical(['city'=>$label($r['city'] ?? []),'zone'=>$label($sub),
            'zone_code'=>$code && isset($sub['iso_code']) ? $code.'-'.$sub['iso_code'] : '',
            'country'=>$label($country),'country_code'=>$code,'source'=>'IP · приблизительно','source_kind'=>'ip']);
    }
    private function location(array $o, string $prefix, string $source, string $kind): array
    {
        return geo_canonical(['city'=>$o[$prefix.'city'] ?? '', 'zone'=>$o[$prefix.'zone'] ?? '',
            'country'=>$o[$prefix.'country'] ?? '', 'source'=>$source, 'source_kind'=>$kind]);
    }
    public function pickupOdesa(array $o): bool
    {
        $method = normalize($o['shipping_method'] ?? '');
        if (!preg_match('/(?:самов[ыи]в[оіи]з|само-в[ыи]воз|pick[ -]?up)/u', $method)) return false;
        if (preg_match('/(?:недоступ|не\s+(?:доступ|здійсню|осуществля|предоставл|передбач|самов)|без\s+самов|(?:нова|новая|новой|новій|нову|nova)[\s-]+(?:[гм]\.\s*)?одес)/u', $method)) return false;
        return (bool)preg_match('/(?<!\p{L})(?:одес{1,2}[аіеыиу]|odes{1,2}a)(?!\p{L})/u', $method);
    }
    public function address(array $o): array
    {
        $fields=['shipping_city','shipping_zone','shipping_country','payment_city','payment_zone','payment_country','shipping_method'];
        $key=encode(array_map(function($field)use($o){return $o[$field]??'';},$fields));
        if(!isset($this->addressCache[$key]))$this->addressCache[$key]=$this->resolveAddress($o);
        return $this->addressCache[$key];
    }
    private function resolveAddress(array $o): array
    {
        $shipping = $this->location($o, 'shipping_', 'Адрес доставки', 'shipping');
        // The explicitly selected Odesa collection method identifies fulfillment,
        // including when the checkout retained the buyer's city in address fields.
        if ($this->pickupOdesa($o)) {
            return geo_canonical(['city'=>'Одеса','zone'=>'Одеська область','zone_code'=>'UA-51','country'=>'Україна',
                'country_code'=>'UA','original_city'=>'','original_zone'=>'','source'=>'По месту самовывоза','source_kind'=>'pickup','pickup_rule'=>'odesa']);
        }
        if ($shipping['city'] !== '') return $shipping;
        if ($shipping['zone'] !== '') return $shipping;
        $payment = $this->location($o, 'payment_', 'Адрес оплаты', 'payment');
        if ($payment['city'] !== '' || $payment['zone'] !== '') return $payment;
        $empty = $shipping['country'] !== '' ? $shipping : $payment;
        $empty['source'] = 'Адрес не указан';$empty['source_kind'] = 'unknown';
        return $empty;
    }
    public function resolve(array $o, string $mode = 'mixed'): array
    {
        if ($mode === 'ip') $result = $this->lookup($o['ip'] ?? '') ?? geo_canonical(['source'=>'IP · нет результата','source_kind'=>'unknown']);
        else {
            $result = $this->address($o);
            if ($mode !== 'address' && $result['city'] === '' && $result['zone'] === '') {
                $ip = $this->lookup($o['ip'] ?? '');
                // Retain partial addresses and never replace a known country
                // with an operator/VPN location in a different country.
                if ($ip && ($ip['city'] !== '' || $ip['zone'] !== '') && ($result['country'] === '' || ($result['country_code'] !== '' && $result['country_code'] === $ip['country_code']))) $result = $ip;
            }
        }
        if ($result['city'] === '') $result['city'] = 'Город не указан';
        if ($result['country'] === '') $result['country'] = 'Не определена';
        return $result;
    }
}
