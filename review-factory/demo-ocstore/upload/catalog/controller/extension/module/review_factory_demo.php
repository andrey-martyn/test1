<?php
// ReviewFactory TEST demo
class ControllerExtensionModuleReviewFactoryDemo extends Controller
{
    private function bridge()
    {
        require_once DIR_SYSTEM . "library/review_factory_demo.php";
        return new ReviewFactoryDemoBridge();
    }
    public function header(&$route, &$args)
    {
        if (!$this->config->get("module_review_factory_demo_status") || !$this->bridge()->available()) {
            return;
        }
        $this->response->addHeader("X-Robots-Tag: noindex, nofollow, noarchive");
        $dir = defined("DIR_APPLICATION")
            ? DIR_APPLICATION . "view/javascript/"
            : dirname(__DIR__, 3) . "/view/javascript/";
        foreach (["css", "js"] as $type) {
            $name = "review_factory_demo." . $type;
            $version = is_file($dir . $name) ? substr(hash_file("sha256", $dir . $name), 0, 16) : "1";
            $url = "catalog/view/javascript/" . $name . "?v=" . $version;
            if ($type === "css") {
                $this->document->addStyle($url);
            } else {
                $this->document->addScript($url);
            }
        }
    }
    public function index()
    {
        if (($this->request->get["route"] ?? "") !== "extension/module/review_factory_demo") {
            return "";
        }
        $this->response->addHeader("Content-Type: application/json; charset=utf-8");
        $this->response->addHeader("X-Content-Type-Options: nosniff");
        $this->response->addHeader("X-Robots-Tag: noindex, nofollow, noarchive");
        $this->response->addHeader("Cache-Control: no-store");
        try {
            $bridge = $this->bridge();
            if (!$this->config->get("module_review_factory_demo_status") || !$bridge->available()) {
                $this->response->addHeader("HTTP/1.1 404 Not Found");
                $this->response->setOutput('{"error":"demo_disabled"}');
                return;
            }
            if (($this->request->server["REQUEST_METHOD"] ?? "GET") !== "GET") {
                throw new RuntimeException("method");
            }
            $raw = $this->request->get["ids"] ?? "";
            if (
                !is_string($raw) ||
                strlen($raw) > 500 ||
                !preg_match('/^[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,39}$/D', $raw)
            ) {
                throw new RuntimeException("ids");
            }
            $ids = array_values(array_unique(array_map("intval", explode(",", $raw))));
            foreach ($ids as $id) {
                if ($id > 2147483647) {
                    throw new RuntimeException("ids");
                }
            }
            $detail = $this->request->get["detail"] ?? null;
            if ($detail !== null) {
                if (
                    !is_string($detail) ||
                    !ctype_digit($detail) ||
                    !in_array((int) $detail, $ids, true)
                ) {
                    throw new RuntimeException("detail");
                }
                $detail = (int) $detail;
            }
            // Verify visibility using normal store data; no customer or review table is touched.
            $result = $this->db->query(
                "SELECT p.product_id FROM `" .
                    DB_PREFIX .
                    "product` p JOIN `" .
                    DB_PREFIX .
                    "product_to_store` ps ON ps.product_id=p.product_id WHERE p.status=1 AND p.date_available<=CURRENT_DATE AND ps.store_id=" .
                    (int) $this->config->get("config_store_id") .
                    " AND p.product_id IN (" .
                    implode(",", $ids) .
                    ")",
            );
            $ids = array_map(function ($r) {
                return (int) $r["product_id"];
            }, $result->rows);
            if ($detail !== null && !in_array($detail, $ids, true)) {
                $detail = null;
            }
            $this->response->setOutput(
                json_encode(
                    $bridge->read($ids, $detail),
                    JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES |
                        JSON_HEX_TAG |
                        JSON_HEX_AMP |
                        JSON_HEX_APOS |
                        JSON_HEX_QUOT,
                ),
            );
        } catch (Throwable $e) {
            $this->response->addHeader("HTTP/1.1 503 Service Unavailable");
            $this->response->setOutput('{"error":"demo_unavailable"}');
        }
    }
}
