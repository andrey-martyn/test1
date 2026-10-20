<?php
declare(strict_types=1);
namespace Lavka;

date_default_timezone_set('Europe/Kyiv');
const TABLES = ['oc_order'=>'order_id','oc_order_product'=>'order_product_id','oc_order_option'=>'order_option_id','oc_order_total'=>'order_total_id','oc_order_history'=>'order_history_id','oc_order_status'=>null];

function private_dir(string $path): void {
    if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) throw new \RuntimeException('Не удалось создать закрытый каталог');
    chmod($path,0700);
}
function encode(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE); }
function decode(string $value): mixed { return json_decode($value,true,512,JSON_THROW_ON_ERROR); }
function atomic_write(string $path,string $value): void {
    private_dir(dirname($path));$tmp=tempnam(dirname($path),'.atomic-');
    try { chmod($tmp,0600);if(file_put_contents($tmp,$value,LOCK_EX)===false)throw new \RuntimeException('Ошибка записи');if(!rename($tmp,$path))throw new \RuntimeException('Ошибка замены файла'); }
    finally { if(is_file($tmp))unlink($tmp); }
}
function normalize(?string $s): string {
    $s=\Normalizer::normalize($s??'',\Normalizer::FORM_KC);
    return trim(preg_replace('/\s+/u',' ',mb_convert_case($s,MB_CASE_FOLD,'UTF-8')));
}
function email_key(?string $s): string {
    $s=normalize($s);if(!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u',$s))return '';
    if(preg_match('/^(?:nomail|no[-_.]?email|no[-_.]?mail|none|null|test|unknown|нет)\d*$/u',explode('@',$s)[0]))return '';
    return $s;
}
function phone_key(?string $s): string {
    $s=preg_replace('/\D/','',$s??'');if(strlen($s)===10&&str_starts_with($s,'0'))$s='38'.$s;
    if(str_starts_with($s,'00'))$s=substr($s,2);
    return strlen($s)>=10&&strlen($s)<=15&&count(array_unique(str_split($s)))>2?$s:'';
}
function decimal(mixed $s): string { $s=(string)($s?:'0');if(!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/',$s))throw new \InvalidArgumentException('Некорректное число');return $s; }
function plus(mixed $a,mixed $b): string { return bcadd(decimal($a),decimal($b),12); }
function times(mixed $a,mixed $b): string { return bcmul(decimal($a),decimal($b),12); }
function money(mixed $n): float { $n=decimal($n);return (float)bcadd(bcadd($n,bccomp($n,'0',12)<0?'-0.005':'0.005',12),'0',2); }
function order_value(array $o): string { return times($o['total'],$o['currency_value']); }
function sum_orders(array $orders): string { $total='0';foreach($orders as $o)$total=plus($total,order_value($o));return $total; }
function even_round(float $n,int $places=0): float { return (float)sprintf('%.'.$places.'F',$n); }
function median(array $values): ?float { if(!$values)return null;sort($values,SORT_NUMERIC);$n=count($values);return $n%2?(float)$values[intdiv($n,2)]:($values[intdiv($n,2)-1]+$values[intdiv($n,2)])/2; }
function day(string $s): \DateTimeImmutable { return new \DateTimeImmutable(substr($s,0,10),new \DateTimeZone('Europe/Kyiv')); }
function days(string $from,string $to): int { return (int)day($from)->diff(day($to))->format('%r%a'); }
function add_days(string $s,int $days): string { return day($s)->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'); }
function iso_year(string $s): int { return (int)day($s)->format('o'); }
function week(string $s): int { return (int)day($s)->format('W'); }
function month_index(string $s): int { return (int)substr($s,0,4)*12+(int)substr($s,5,2)-1; }
function month_label(int $i): string { return sprintf('%04d-%02d',intdiv($i,12),$i%12+1); }
function pct(float|int $n,float|int $d): ?float { return $d?even_round($n/$d*100,1):null; }
function ordered(array $items,callable $compare): array { usort($items,$compare);return $items; }
function ids(array $values): array { return array_map('strval',array_keys($values)); }
function package(array $options): array {
    $selected=array_values(array_filter($options,fn($o)=>preg_match('/фасов|упаков|вага|вес|weight|pack/u',normalize($o['name']??''))));
    if(!$selected)return ['label'=>'Не указана','grams'=>null,'unit'=>'unknown'];
    if(count($selected)!==1)return ['label'=>implode(' / ',array_column($selected,'value')),'grams'=>null,'unit'=>'unknown'];
    $label=trim($selected[0]['value']);$result=['label'=>$label,'grams'=>null,'unit'=>'unknown'];
    if(!preg_match('/^(\d+(?:[.,]\d+)?)\s*(кг|kg|г|гр|g|шт\.?|pcs?\.?)$/u',normalize($label),$m))return $result;
    $grams=(float)str_replace(',','.',$m[1]);
    if(in_array($m[2],['кг','kg'],true))$grams*=1000;
    elseif(!in_array($m[2],['г','гр','g'],true))return ['label'=>$label,'grams'=>null,'unit'=>'pieces'];
    return ['label'=>$label,'grams'=>$grams,'unit'=>'weight'];
}
function database(string $path): \PDO {
    private_dir(dirname($path));$db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
    chmod($path,0600);$db->exec('PRAGMA busy_timeout=15000');return $db;
}
function init_schema(\PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS raw_records(table_name TEXT,record_id TEXT,payload TEXT,PRIMARY KEY(table_name,record_id));
        CREATE TABLE IF NOT EXISTS imports(id INTEGER PRIMARY KEY,imported_at TEXT,digest TEXT,summary TEXT);
        CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT);
        CREATE TABLE IF NOT EXISTS manual_links(a TEXT,b TEXT,created_at TEXT,PRIMARY KEY(a,b));
        CREATE TABLE IF NOT EXISTS customer_actions(customer_id TEXT PRIMARY KEY,payload TEXT);
        CREATE TABLE IF NOT EXISTS ga4_records(kind TEXT,record_key TEXT,payload TEXT,PRIMARY KEY(kind,record_key));
        CREATE TABLE IF NOT EXISTS app_revision(id INTEGER PRIMARY KEY CHECK(id=1),value INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS sync_status(id INTEGER PRIMARY KEY CHECK(id=1),payload TEXT);');
}
function bump(\PDO $db): void { $db->exec('INSERT OR IGNORE INTO app_revision VALUES(1,0);UPDATE app_revision SET value=value+1 WHERE id=1'); }
function revision(\PDO $db): string { return (string)$db->query('SELECT coalesce(max(id),0) FROM imports')->fetchColumn().'-'.(string)($db->query('SELECT value FROM app_revision WHERE id=1')->fetchColumn()?:0); }
function utc_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

final class DSU {
    public array $parents=[];
    public function __construct(array $ids){foreach($ids as $id)$this->parents[$id]=(string)$id;}
    public function find(string|int $id): string { $id=(string)$id;if($this->parents[$id]!==$id)$this->parents[$id]=$this->find($this->parents[$id]);return $this->parents[$id]; }
    public function union(string|int $a,string|int $b): void { if(!isset($this->parents[$a],$this->parents[$b]))return;$a=$this->find($a);$b=$this->find($b);$this->parents[max((int)$a,(int)$b)]=(string)min((int)$a,(int)$b); }
}
