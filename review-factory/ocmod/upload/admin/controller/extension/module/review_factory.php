<?php
// ocStore/OpenCart 3. No core files are overwritten.
class ControllerExtensionModuleReviewFactory extends Controller
{
    public function install()
    {
        if (!$this->user->hasPermission("modify", "extension/module/review_factory")) {
            return;
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" .
                DB_PREFIX .
                "review_factory_meta` (
          `review_id` INT UNSIGNED NOT NULL,`request_id` CHAR(32) NOT NULL,`payload_hash` CHAR(64) NOT NULL,
          `origin` VARCHAR(16) NOT NULL DEFAULT 'staff',`author_user_id` INT UNSIGNED NOT NULL,
          `author_role` VARCHAR(64) NOT NULL,`experience_date` VARCHAR(10) NOT NULL DEFAULT '',`city` VARCHAR(80) NOT NULL DEFAULT '',
          `sentiment` VARCHAR(16) NOT NULL,`depth` VARCHAR(16) NOT NULL,`dispatch_state` VARCHAR(16) NOT NULL,
          `created_at` DATETIME NOT NULL,`approved_by` INT UNSIGNED DEFAULT NULL,`published_at` DATETIME DEFAULT NULL,PRIMARY KEY(`review_id`),UNIQUE KEY `request_id` (`request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" .
                DB_PREFIX .
                "review_factory_config` (`key` VARCHAR(64) NOT NULL,`value` VARCHAR(255) NOT NULL,PRIMARY KEY(`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );
        $this->db->query(
            "INSERT IGNORE INTO `" .
                DB_PREFIX .
                "review_factory_config` VALUES('rating_filter_verified','0')",
        );
        $this->db->query(
            "INSERT IGNORE INTO `" .
                DB_PREFIX .
                "review_factory_config` VALUES('test_dispatch_ready','0')",
        );
        $this->load->model("setting/event");
        $this->model_setting_event->deleteEventByCode("lavka_review_factory_date");
        $this->model_setting_event->addEvent(
            "lavka_review_factory_date",
            "admin/model/catalog/review/editReview/before",
            "extension/module/review_factory/dateBefore",
        );
        $this->model_setting_event->deleteEventByCode("lavka_review_factory_approval");
        $this->model_setting_event->addEvent(
            "lavka_review_factory_approval",
            "admin/model/catalog/review/editReview/after",
            "extension/module/review_factory/dateAfter",
        );
    }
    public function uninstall()
    {
        // Metadata is needed to keep staff reviews outside customer aggregates.
        $n = $this->db->query("SELECT COUNT(*) n FROM `" . DB_PREFIX . "review_factory_meta`");
        if (!empty($n->row["n"])) {
            throw new Exception(
                "Сначала удалите или перенесите авторские материалы. Метаданные и фильтр рейтинга должны сохраняться вместе с ними.",
            );
        }
        $this->load->model("setting/event");
        $this->model_setting_event->deleteEventByCode("lavka_review_factory_date");
        $this->model_setting_event->deleteEventByCode("lavka_review_factory_approval");
    }
    public function dateBefore(&$route, &$args)
    {
        if (!isset($args[0], $args[1]["status"]) || (int) $args[1]["status"] !== 1) {
            return;
        }
        $id = (int) $args[0];
        $r = $this->db->query(
            "SELECT r.status FROM `" .
                DB_PREFIX .
                "review` r JOIN `" .
                DB_PREFIX .
                "review_factory_meta` m ON m.review_id=r.review_id WHERE r.review_id=" .
                $id .
                " AND m.origin='staff'",
        );
        if ($r->num_rows && !(int) $r->row["status"]) {
            $args[1]["date_added"] = date("Y-m-d H:i:s");
        }
        $this->cache->delete("product");
    }
    public function dateAfter(&$route, &$args, &$output)
    {
        if (!isset($args[0], $args[1]["status"]) || (int) $args[1]["status"] !== 1) {
            return;
        }
        $this->db->query(
            "UPDATE `" .
                DB_PREFIX .
                "review_factory_meta` m JOIN `" .
                DB_PREFIX .
                "review` r ON r.review_id=m.review_id SET m.approved_by=COALESCE(m.approved_by," .
                (int) $this->user->getId() .
                "),m.published_at=COALESCE(m.published_at,NOW()) WHERE m.review_id=" .
                (int) $args[0] .
                " AND r.status=1 AND m.origin='staff'",
        );
    }
    public function index()
    {
        $this->document->setTitle("Комбайн отзывов — подключение");
        $message = "";
        $modified = DIR_MODIFICATION . "catalog/model/catalog/product.php";
        $filter = is_file($modified);
        $source = $filter ? file_get_contents($modified) : "";
        foreach (["r1", "r2"] as $alias) {
            $needle = $alias . ".product_id = p.product_id AND " . $alias . ".status = '1'";
            $filter =
                $filter &&
                substr_count($source, $needle) > 0 &&
                substr_count($source, $needle) === substr_count($source, $needle . " AND NOT EXISTS");
        }
        if (DB_DATABASE === "lavkasp_test") {
            $this->db->query(
                "UPDATE `" .
                    DB_PREFIX .
                    "review_factory_config` SET value='" .
                    ($filter ? "1" : "0") .
                    "' WHERE `key`='test_dispatch_ready'",
            );
        }
        if ($this->request->server["REQUEST_METHOD"] === "POST") {
            if (!$this->user->hasPermission("modify", "extension/module/review_factory")) {
                $message = "Недостаточно прав.";
            } elseif (!$filter) {
                $message =
                    "Сначала обновите модификаторы и проверьте журнал OCMOD: фильтр рейтинга не найден.";
            } elseif (empty($this->request->post["verified"])) {
                $message = "Подтвердите проверку витрины, JSON-LD и используемых фидов.";
            } else {
                $this->db->query(
                    "UPDATE `" .
                        DB_PREFIX .
                        "review_factory_config` SET value='1' WHERE `key`='rating_filter_verified'",
                );
                $message = "Передача в штатную модерацию разрешена. Автоматической активации нет.";
            }
        }
        $escape = function ($s) {
            return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
        };
        $action = $this->url->link(
            "extension/module/review_factory",
            "user_token=" . $this->session->data["user_token"],
            true,
        );
        $header = $this->load->controller("common/header");
        $left = $this->load->controller("common/column_left");
        $footer = $this->load->controller("common/footer");
        $html =
            $header .
            $left .
            '<div id="content"><div class="container-fluid"><h1>Комбайн отзывов</h1><div class="panel panel-default"><div class="panel-body"><p>' .
            $escape($message) .
            "</p><p>Статус фильтра стандартного каталога: <strong>" .
            ($filter ? "найден" : "не найден") .
            '</strong>.</p><p>На lavkasp_test передача в модерацию доступна после нахождения обоих фильтров. Это позволяет провести проверку перед переносом. На рабочем сайте требуется отметка ниже.</p><p>Авторские оценки видны в обычной ленте. Оценки покупателей считаются отдельно. Проверьте все места вывода рейтинга UniShop2, JSON-LD и используемые товарные фиды на тестовой копии.</p><p>Проверка стандартного model/catalog/product.php не подтверждает совместимость сторонних прямых SQL-запросов.</p><form method="post" action="' .
            $escape($action) .
            '"><label><input type="checkbox" name="verified" value="1"> Проверены рейтинг и число покупательских оценок на карточке, в категориях, JSON-LD и активных фидах. Тестовая авторская запись не изменила эти значения.</label><p><button class="btn btn-primary">Сохранить результат проверки</button></p></form><p>Материалы всегда поступают со статусом 0. Активация — обычным действием в «Каталог → Отзывы».</p><p>Сохраните метаданные и OCMOD-фильтр при обновлениях: без них оценки персонала попадут в покупательский рейтинг.</p></div></div></div></div>' .
            $footer;
        $this->response->setOutput($html);
    }
}
