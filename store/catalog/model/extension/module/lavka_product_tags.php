<?php
require_once DIR_SYSTEM.'library/lavka_product_tags.php';
class ModelExtensionModuleLavkaProductTags extends Model {
    public function engine() {
        if (!$this->registry->has('lavka_product_tags')) $this->registry->set('lavka_product_tags',new LavkaProductTags($this->registry));
        return $this->registry->get('lavka_product_tags');
    }
}
