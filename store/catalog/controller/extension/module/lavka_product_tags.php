<?php
class ControllerExtensionModuleLavkaProductTags extends Controller {
    public function info() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Cache-Control: private, no-store, max-age=0');
        $this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') { $this->response->addHeader('HTTP/1.1 405 Method Not Allowed'); $this->response->setOutput(json_encode(['error'=>'POST_REQUIRED'])); return; }
        $ids = isset($this->request->post['product_ids']) ? $this->request->post['product_ids'] : [];
        if (!is_array($ids) || !$ids || count($ids)>100) { $this->response->setOutput(json_encode(['error'=>'INVALID_IDS'])); return; }
        foreach ($ids as $id) if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id)) { $this->response->setOutput(json_encode(['error'=>'INVALID_IDS'])); return; }
        $ids = array_values(array_unique(array_map('intval',$ids)));
        try {
            $this->load->model('extension/module/lavka_product_tags');
            $engine = $this->model_extension_module_lavka_product_tags->engine();
            $q = $this->db->query("SELECT p.* FROM `".DB_PREFIX."product` p INNER JOIN `".DB_PREFIX."product_to_store` ps ON p.product_id=ps.product_id WHERE ps.store_id=".(int)$this->config->get('config_store_id')." AND p.status=1 AND p.date_available<=NOW() AND p.product_id IN (".implode(',',$ids).")");
            $data = [];
            foreach ($q->rows as $product) $data[(int)$product['product_id']] = $engine->stickers($product);
            $review_enabled=(bool)$this->config->get('config_review_status');
            $reviews=[];
            if ($review_enabled && $data) {
                foreach ($data as $id=>$stickers) $reviews[$id]=['total'=>0,'rating'=>0];
                // One aggregate for visible products only; no authors, text or unpublished reviews.
                $q=$this->db->query("SELECT r.product_id, COUNT(*) AS total, AVG(r.rating) AS rating FROM `".DB_PREFIX."review` r WHERE r.status=1 AND r.product_id IN (".implode(',',array_keys($data)).") GROUP BY r.product_id");
                foreach ($q->rows as $row) if (isset($reviews[(int)$row['product_id']])) {
                    $reviews[(int)$row['product_id']]=['total'=>max(0,(int)$row['total']),'rating'=>round(max(0,min(5,(float)$row['rating'])),1)];
                }
            }
            $this->response->setOutput(json_encode(['products'=>$data,'review_enabled'=>$review_enabled,'reviews'=>$reviews]));
        } catch (Exception $e) {
            $this->response->addHeader('HTTP/1.1 503 Service Unavailable');
            $this->response->setOutput(json_encode(['error'=>'TAGS_UNAVAILABLE']));
        }
    }
}
