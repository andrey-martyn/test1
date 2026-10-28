<?php
define('TAG_FIXTURE_BOOTSTRAP',true);
require __DIR__.'/product_tags.php';
class Controller {
    protected $registry;
    public function __construct($registry) { $this->registry=$registry; }
    public function __get($key) { return $this->registry->get($key); }
}
class EndpointResponse {
    public $headers=[]; public $output;
    public function addHeader($value) { $this->headers[]=$value; }
    public function setOutput($value) { $this->output=$value; }
}
class EndpointUser {
    public $allowed=true;
    public function hasPermission($action,$route) { return $this->allowed && $route==='catalog/product'; }
}
class EndpointLoader {
    public $calls=0;
    public function model($route) { verify($route==='extension/module/lavka_product_tags','Only known tag model may load'); $this->calls++; }
}
class EndpointModel {
    private $engine;
    public $purged=[];
    public function __construct($engine) { $this->engine=$engine; }
    public function engine() { return $this->engine; }
    public function purge($id) { $this->purged[]=$id; return 'requested'; }
}
list($engine,$db,$registry)=fixture();
$request=(object)['get'=>['user_token'=>'fixture-token'],'post'=>['product_id'=>'816','code'=>'brand','enabled'=>'1','revision'=>'0'],'server'=>['REQUEST_METHOD'=>'POST']];
$response=new EndpointResponse(); $loader=new EndpointLoader(); $user=new EndpointUser(); $model=new EndpointModel($engine);
$registry->set('request',$request);$registry->set('response',$response);$registry->set('load',$loader);$registry->set('user',$user);$registry->set('model_extension_module_lavka_product_tags',$model);
$registry->get('session')->data['user_token']='fixture-token';
$db->queries=[];
$scenario=$argv[1];
if (strpos($scenario,'public')===0) {
    require __DIR__.'/../../store/catalog/controller/extension/module/lavka_product_tags.php';
    $controller=new ControllerExtensionModuleLavkaProductTags($registry);
    $request->post=['product_ids'=>['816','817']];
    if ($scenario==='public_bad_ids') $request->post=['product_ids'=>['816) OR 1=1']];
    if ($scenario==='public_get') $request->server['REQUEST_METHOD']='GET';
    $controller->info();
    $output=json_decode($response->output,true);
    if ($scenario==='public_ok') {
        verify(isset($output['products'][816]),'Visible product labels must be returned');
        verify(!isset($output['products'][816]['price']) && strpos($response->output,'767')===false,'Public API must expose only sticker properties');
        verify(strpos($db->queries[0],'ps.store_id=0 AND p.status=1 AND p.date_available<=NOW()')!==false,'Public read must restrict store, status and availability');
        verify(strpos($response->output,'fixture-token')===false,'No session token in response');
    } else verify(!$loader->calls && !$db->queries,'Invalid public request must stop before DB');
} else {
    require __DIR__.'/../../store/admin/controller/catalog/product.php';
    $controller=new ControllerCatalogProduct($registry);
    if ($scenario==='bad_token') $request->get['user_token']='other';
    if ($scenario==='array_token') $request->get['user_token']=[];
    if ($scenario==='no_permission') $user->allowed=false;
    if ($scenario==='get') $request->server['REQUEST_METHOD']='GET';
    if ($scenario==='bad_input') $request->post['revision']=[];
    $controller->lavkaTag();
    $output=json_decode($response->output,true);
    if ($scenario==='admin_ok') {
        verify($output['success'] && $output['state']['brand']['enabled'],'Authorized partial save');
        verify($model->purged===[816],'Only the selected product cache path may be invoked');
        verify($db->products[816]['mpn']==='Популярно' && $db->products[816]['quantity']===767,'Unrelated product values preserved');
    } else verify(!$loader->calls && !$db->queries,'Authorization/verb/input rejection must precede DB');
}
echo json_encode(['ok'=>true,'scenario'=>$scenario,'response'=>$output,'headers'=>$response->headers]);
