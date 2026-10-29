<?php
// ReviewFactory TEST demo
class ControllerExtensionModuleReviewFactoryDemo extends Controller
{
    private function bridge()
    {
        require_once DIR_SYSTEM . "library/review_factory_demo.php";
        return new ReviewFactoryDemoBridge();
    }
    private function link($args)
    {
        return html_entity_decode(
            $this->url->link("extension/module/review_factory_demo", $args, true),
            ENT_QUOTES | ENT_HTML5,
            "UTF-8",
        );
    }
    public function index()
    {
        $this->load->language("extension/module/review_factory_demo");
        $this->document->setTitle($this->language->get("heading_title"));
        $data = [
            "heading_title" => $this->language->get("heading_title"),
            "error" => "",
            "success" => "",
            "items" => [],
            "total" => 0,
            "filter" => "pending",
            "page" => 1,
        ];
        $token = $this->session->data["user_token"] ?? "";
        if (!is_string($token) || strlen($token) < 16) {
            $this->response->redirect($this->url->link("common/login", "", true));
            return;
        }
        $base = "user_token=" . rawurlencode($token);
        $data["action"] = $this->link($base);
        $data["user_token"] = $token;
        $data["filters"] = [];
        foreach (
            [
                "pending" => "Ожидают решения",
                "approved" => "Одобрены для демо",
                "hidden" => "Не показывать",
                "all" => "Все",
            ]
            as $key => $label
        ) {
            $data["filters"][] = [
                "label" => $label,
                "url" => $this->link($base . "&filter=" . $key),
            ];
        }
        try {
            $getToken = $this->request->get["user_token"] ?? null;
            if (!is_string($getToken) || !hash_equals($token, $getToken)) {
                throw new RuntimeException("permission");
            }
            $bridge = $this->bridge();
            if (!$bridge->available()) {
                throw new RuntimeException("test_only");
            }
            if (!$this->user->hasPermission("access", "extension/module/review_factory_demo")) {
                throw new RuntimeException("permission");
            }
            if (($this->request->server["REQUEST_METHOD"] ?? "GET") === "POST") {
                if (
                    !$this->user->hasPermission("modify", "extension/module/review_factory_demo") ||
                    !is_string($this->request->post["user_token"] ?? null) ||
                    !hash_equals($token, $this->request->post["user_token"])
                ) {
                    throw new RuntimeException("permission");
                }
                $revision = $this->request->post["revision"] ?? "";
                if (!is_string($revision) || !ctype_digit($revision)) {
                    throw new RuntimeException("revision");
                }
                $bridge->moderate(
                    $this->request->post["entry_id"] ?? "",
                    $this->request->post["decision"] ?? "",
                    "ocstore:" . (int) $this->user->getId(),
                    (int) $revision,
                );
                $this->session->data["review_factory_demo_success"] =
                    "Решение сохранено. В демо-блоке показываются только одобренные записи.";
                $this->response->redirect($data["action"]);
                return;
            }
            $filter = $this->request->get["filter"] ?? "pending";
            $page = $this->request->get["page"] ?? "1";
            if (!is_string($filter) || !is_string($page) || !ctype_digit($page)) {
                throw new RuntimeException("filter");
            }
            $list = $bridge->listing($filter, (int) $page);
            $data = array_merge($data, $list);
            $data["filter"] = $filter;
            foreach ($data["items"] as &$row) {
                $row["state_label"] = [
                    "pending" => "Ожидает решения",
                    "approved" => "Одобрено для демо",
                    "hidden" => "Не показывать",
                ][$row["demo_state"]];
                $row["product_url"] =
                    "https://test.lavka-speciy.com/index.php?route=product/product&product_id=" .
                    (int) $row["product_id"];
            }
            unset($row);
            $data["prev"] =
                $page > 1
                    ? $this->link($base . "&filter=" . $filter . "&page=" . ((int) $page - 1))
                    : "";
            $data["next"] =
                (int) $page * 20 < $list["total"]
                    ? $this->link($base . "&filter=" . $filter . "&page=" . ((int) $page + 1))
                    : "";
            $data["success"] = $this->session->data["review_factory_demo_success"] ?? "";
            unset($this->session->data["review_factory_demo_success"]);
        } catch (Throwable $e) {
            $data["error"] =
                "Демо-показ недоступен или запись изменилась. Проверьте TEST-установку, права доступа и обновите страницу.";
        }
        $data["header"] = $this->load->controller("common/header");
        $data["column_left"] = $this->load->controller("common/column_left");
        $data["footer"] = $this->load->controller("common/footer");
        $this->response->setOutput($this->load->view("extension/module/review_factory_demo", $data));
    }
    public function menu(&$route, &$data, &$code = null)
    {
        if (
            !$this->config->get("module_review_factory_demo_status") ||
            !$this->bridge()->available() ||
            !$this->user->hasPermission("access", "extension/module/review_factory_demo") ||
            !isset($data["menus"]) ||
            !is_array($data["menus"])
        ) {
            return;
        }
        $data["menus"][] = [
            "id" => "menu-review-factory-demo",
            "icon" => "fa-flask",
            "name" => "Демо-отзывы TEST",
            "href" => $this->url->link(
                "extension/module/review_factory_demo",
                "user_token=" . rawurlencode($this->session->data["user_token"] ?? ""),
                true,
            ),
            "children" => [],
        ];
    }
    public function install()
    {
        if (
            !$this->bridge()->available() ||
            !$this->user->hasPermission("modify", "extension/extension/module")
        ) {
            return;
        }
        $this->load->model("setting/event");
        $this->model_setting_event->deleteEventByCode("review_factory_demo");
        $this->model_setting_event->addEvent(
            "review_factory_demo",
            "catalog/controller/common/header/before",
            "extension/module/review_factory_demo/header",
            1,
            0,
        );
        $this->model_setting_event->addEvent(
            "review_factory_demo",
            "admin/view/common/column_left/before",
            "extension/module/review_factory_demo/menu",
            1,
            0,
        );
        $this->load->model("setting/setting");
        $this->model_setting_setting->editSetting("module_review_factory_demo", [
            "module_review_factory_demo_status" => 1,
        ]);
        $this->load->model("user/user_group");
        $group = $this->db->query(
            "SELECT user_group_id FROM `" .
                DB_PREFIX .
                "user` WHERE user_id=" .
                (int) $this->user->getId(),
        )->row["user_group_id"];
        foreach (["access", "modify"] as $permission) {
            $this->model_user_user_group->addPermission(
                (int) $group,
                $permission,
                "extension/module/review_factory_demo",
            );
        }
    }
    public function uninstall()
    {
        if (
            !$this->bridge()->available() ||
            !$this->user->hasPermission("modify", "extension/extension/module")
        ) {
            return;
        }
        $this->load->model("setting/event");
        $this->model_setting_event->deleteEventByCode("review_factory_demo");
        $this->load->model("setting/setting");
        $this->model_setting_setting->deleteSetting("module_review_factory_demo");
    }
}
