<?php
require_once DIR_SYSTEM.'library/lavka_product_tags.php';
class ModelExtensionModuleLavkaProductTags extends Model {
    public function engine() {
        if (!$this->registry->has('lavka_product_tags')) $this->registry->set('lavka_product_tags',new LavkaProductTags($this->registry));
        return $this->registry->get('lavka_product_tags');
    }
    public function purge($product_id) {
        // Call only the known cache integration, never synthetic full-product edit events.
        $path = DIR_APPLICATION.'controller/extension/module/lscache.php';
        if (!is_file($path)) return 'not_installed';
        if (!class_exists('ControllerExtensionModuleLSCache',false)) require_once $path;
        $controller = new ControllerExtensionModuleLSCache($this->registry);
        if (!is_callable([$controller,'editProduct'])) return 'unsupported';
        $args = [(int)$product_id]; $output = null;
        $controller->editProduct('catalog/product/lavkaTag',$args,$output);
        return 'requested';
    }
}
