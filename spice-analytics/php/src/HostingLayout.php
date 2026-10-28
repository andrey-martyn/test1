<?php
declare(strict_types=1);
namespace Lavka;

/** Two explicit layouts, never arbitrary or public data directories. No writes. */
function hosting_layout_paths(string $home,string $layout='legacy'): array
{
    $root=realpath($home);
    if(!$root||$root!==rtrim($home,'/')||is_link($home)||!is_dir($root))throw new \InvalidArgumentException('hosting_root_invalid');
    if(!in_array($layout,['legacy','organized'],true))throw new \InvalidArgumentException('hosting_layout_invalid');
    $organized=$layout==='organized';
    $repository=$root.($organized?'/apps/lavka-modules':'/analytics-app');
    return ['layout'=>$layout,'home'=>$root,'repository'=>$repository,'application_root'=>$repository.'/spice-analytics/php',
        'previous_data_dir'=>$root.($organized?'/private/analytics/previous':'/analytics-private'),
        'data_dir'=>$root.($organized?'/private/analytics/test':'/analytics-private-test'),
        'review_code'=>$repository.'/review-factory/php',
        'review_data'=>$root.($organized?'/private/review-factory/test':'/review-factory-private-test'),
        'analytics_backups'=>$root.($organized?'/backups/analytics':'/analytics-backups'),
        'frontend_backups'=>$root.($organized?'/backups/frontend/test':'/test_frontend_backups'),
        'shop_root'=>$root.'/test','document_root'=>$root.'/test/analytics',
        'review_webroot'=>$root.'/test/review-workshop','public_url'=>'https://test.lavka-speciy.com/analytics'];
}

function hosting_layout_for_application(string $application): array
{
    $app=realpath($application);
    if(!$app||$app!==rtrim($application,'/')||is_link($application))throw new \InvalidArgumentException('hosting_application_invalid');
    foreach(['legacy'=>'/analytics-app/spice-analytics/php','organized'=>'/apps/lavka-modules/spice-analytics/php'] as $name=>$suffix){
        if(substr($app,-strlen($suffix))!==$suffix)continue;
        $paths=hosting_layout_paths(substr($app,0,-strlen($suffix)),$name);
        if($paths['application_root']===$app)return $paths;
    }
    throw new \InvalidArgumentException('hosting_application_layout_unknown');
}
