<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
function loyalty_verify($ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
$directory=sys_get_temp_dir().'/lavka-loyalty-'.bin2hex(random_bytes(6));\Lavka\private_dir($directory);
try{
    $groups=[];
    foreach([
        ['1','Виктор','2025-01-01',[0,90,180],'Одеса','Одеська область','Самовывоз Одесса'],
        ['2','Виктор','2025-06-01',[0],'Киев','Киевская область','Нова пошта'],
        ['3','Светлана','2025-07-01',[0,91],'Чернігів','Чернігівська область','Нова пошта'],
        ['4','Светлана','2025-12-31',[0,1],'Одеса','Одеська область','Нова пошта'],
        ['5','Светлана','2026-09-01',[0,20],'Одеса','Одеська область','Нова пошта'],
        ['6','Саша','2025-01-01',[0],'Киев','Киевская область','Нова пошта'],
    ] as $spec){[$id,$name,$date,$offsets,$city,$zone,$method]=$spec;
        foreach($offsets as $index=>$offset)$groups[$id][]=['order_id'=>$id.'0'.$index,'firstname'=>$name,'date_added'=>\Lavka\add_days($date,$offset).' 12:00:00','shipping_city'=>$city,'shipping_zone'=>$zone,'shipping_country'=>'Україна','shipping_method'=>$method];
    }
    $geo=new \Lavka\Geo($directory);$data=\Lavka\loyalty_report($geo,$groups,'2026-10-25',0,true)['rows'];
    loyalty_verify($data['male']['eligible']===2&&$data['male']['returned']===1&&$data['male']['rate']===50.0,'Exact 90-day boundary and denominator');
    loyalty_verify($data['female']['eligible']===2&&$data['female']['returned']===1&&$data['female']['pending']===1,'Recent buyers excluded even if already repeated; 91 days is outside');
    loyalty_verify($data['male']['regular_180']===1&&$data['male']['regular_rate']===50.0,'Three orders at exact 180-day boundary');
    loyalty_verify($data['unknown']['eligible']===1&&$data['unknown']['returned']===0,'Ambiguous names stay separate');
    $cohort=\Lavka\loyalty_report($geo,$groups,'2026-10-25',2025,true)['rows'];loyalty_verify($cohort['female']['returned']===1&&$cohort['female']['customers']===2,'First-purchase cohort includes later-year repeat orders');
    $recent=\Lavka\loyalty_report($geo,$groups,'2026-10-25',2026,true)['rows'];loyalty_verify($recent['female']['rate']===null&&$recent['female']['pending']===1,'No matured buyers means unavailable, not zero');
    $groups['1'][1]['shipping_city']='Киев';$groups['1'][1]['shipping_zone']='Киевская область';$groups['1'][1]['shipping_method']='Нова пошта';
    $regions=\Lavka\loyalty_report($geo,$groups,'2026-10-25',0)['rows'];
    loyalty_verify($regions['all']['UA-51']['customers']===3&&$regions['pickup']['UA-51']['customers']===1&&$regions['delivery']['UA-51']['customers']===2,'One entry region and channel, no multi-region double count');
    loyalty_verify(array_sum(array_column($regions['all'],'customers'))===count($groups),'Regional buyers reconcile');
    echo \Lavka\encode(['fixed_observation_windows'=>'passed','gender_and_first_purchase_regions'=>'passed','cohorts_pending_and_unique_buyers'=>'passed','php'=>PHP_VERSION]),PHP_EOL;
}finally{foreach(glob($directory.'/*') as $file)if(is_file($file))unlink($file);rmdir($directory);}
