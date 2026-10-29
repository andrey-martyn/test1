<?php
declare(strict_types=1);
require_once __DIR__ . "/demo_fixture.php";
$base = sys_get_temp_dir() . "/rf-native-demo-" . bin2hex(random_bytes(6));
$f = demoFixture($base);
define("DIR_SYSTEM", $f["root"] . "/system/");
define("DB_DATABASE", ($argv[2] ?? "test") === "production" ? "lavkasp_lavka" : "lavkasp_test");
define("DB_PREFIX", "oc_");
$_SERVER["HTTP_HOST"] = "test.lavka-speciy.com";
class Controller
{
    private $values = [];
    public function __get($name)
    {
        return $this->values[$name] ?? null;
    }
    public function __set($name, $value)
    {
        $this->values[$name] = $value;
    }
}
class DemoResponse
{
    public $headers = [],
        $output = "",
        $redirect = "";
    function addHeader($v)
    {
        $this->headers[] = $v;
    }
    function setOutput($v)
    {
        $this->output = $v;
    }
    function redirect($v)
    {
        $this->redirect = $v;
    }
}
class DemoConfig
{
    function get($key)
    {
        return $key === "module_review_factory_demo_status" ? 1 : 0;
    }
}
class DemoUser
{
    public $modify = true,
        $access = true;
    function hasPermission($type, $route)
    {
        return $type === "modify" ? $this->modify : $this->access;
    }
    function getId()
    {
        return 7;
    }
}
class DemoDocument
{
    public $scripts = [],
        $styles = [];
    function addScript($v)
    {
        $this->scripts[] = $v;
    }
    function addStyle($v)
    {
        $this->styles[] = $v;
    }
    function setTitle($v) {}
}
class DemoNativeDb
{
    public $queries = [];
    function query($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, "SELECT user_group_id") === 0) {
            return (object) ["row" => ["user_group_id" => 3]];
        }
        if (strpos($sql, "SELECT p.product_id") !== 0) {
            throw new RuntimeException("Unexpected native SQL");
        }
        return (object) ["rows" => strpos($sql, "IN (1") !== false ? [["product_id" => 1]] : []];
    }
}
class DemoLoad
{
    public $viewData;
    function language($v) {}
    function model($v) {}
    function controller($v)
    {
        return "<div>native frame</div>";
    }
    function view($v, $data)
    {
        $this->viewData = $data;
        return json_encode($data);
    }
}
class DemoLanguage
{
    function get($key)
    {
        return "TEST demo";
    }
}
class DemoUrl
{
    function link($route, $args, $secure)
    {
        return "https://test.lavka-speciy.com/admin/index.php?route=" .
            $route .
            ($args ? "&amp;" . str_replace("&", "&amp;", $args) : "");
    }
}
class DemoEvents
{
    public $events = ["other_module" => ["unrelated"]];
    function deleteEventByCode($code)
    {
        foreach (array_keys($this->events) as $key) {
            if ($key === $code || strpos($key, $code . "|") === 0) {
                unset($this->events[$key]);
            }
        }
    }
    function addEvent($code, $trigger, $action, $status, $sort)
    {
        $this->events[$code . "|" . $trigger] = [$trigger, $action, $status, $sort];
    }
}
class DemoSettings
{
    public $settings = [];
    function editSetting($key, $value)
    {
        $this->settings[$key] = $value;
    }
    function deleteSetting($key)
    {
        unset($this->settings[$key]);
    }
}
class DemoPermissions
{
    public $permissions = [];
    function addPermission($group, $type, $route)
    {
        $this->permissions[] = [$group, $type, $route];
    }
}
$mode = $argv[1] ?? "catalog";
$checks = 0;
function nc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
try {
    require $f["root"] . "/" . $mode . "/controller/extension/module/review_factory_demo.php";
    $c = new ControllerExtensionModuleReviewFactoryDemo();
    $c->response = new DemoResponse();
    $c->config = new DemoConfig();
    $c->document = new DemoDocument();
    $c->db = new DemoNativeDb();
    $c->user = new DemoUser();
    $c->load = new DemoLoad();
    $c->language = new DemoLanguage();
    $c->url = new DemoUrl();
    $token = str_repeat("a", 32);
    $c->session = (object) ["data" => ["user_token" => $token]];
    $c->request = (object) [
        "get" => ["user_token" => $token],
        "post" => [],
        "server" => ["REQUEST_METHOD" => "GET"],
    ];
    if ($mode === "catalog") {
        $c->request->get = ["route" => "product/product"];
        nc(
            $c->index() === "" && $c->response->headers === [],
            "A layout invocation never turns a normal page into JSON",
        );
        if (DB_DATABASE !== "lavkasp_test") {
            $c->request->get = ["route" => "product/product"];
            nc(
                $c->index() === "" && $c->response->headers === [],
                "A layout invocation never turns a normal page into JSON",
            );
            $route = "common/header";
            $args = [];
            $c->header($route, $args);
            $c->request->get = ["ids" => "1"];
            $c->request->get["route"] = "extension/module/review_factory_demo";
            $c->index();
            nc(
                $c->document->scripts === [] &&
                    $c->db->queries === [] &&
                    strpos($c->response->output, "demo_disabled") !== false,
                "Production database is refused before native SQL or demo reads",
            );
            echo ReviewFactory\json([
                "state" => "ok",
                "mode" => "production_db_guard",
                "checks" => $checks,
                "php" => PHP_VERSION,
            ]) . "\n";
            return;
        }
        $route = "common/header";
        $args = [];
        $c->header($route, $args);
        nc(
            count($c->document->scripts) === 1 && count($c->document->styles) === 1,
            "Native header hook adds standalone demo assets",
        );
        $c->request->get = ["ids" => "1,2", "detail" => "1"];
        $c->request->get["route"] = "extension/module/review_factory_demo";
        $c->index();
        nc(
            (array) json_decode($c->response->output)->products === [],
            "Unapproved records are absent from the native API",
        );
        ReviewFactory\demoModerate(
            $f["private"],
            $f["entry"]["id"],
            "approved",
            "ocstore:7",
            (int) $f["entry"]["revision"],
        );
        $c->request->get["route"] = "extension/module/review_factory_demo";
        $c->index();
        $result = json_decode($c->response->output, true);
        nc(
            $result["products"][1]["demo_count"] === 1 && count($result["products"][1]["entries"]) === 1,
            "Native API returns the approved demo and count",
        );
        nc(!isset($result["products"][2]), "Native API enforces current product visibility");
        nc(
            strpos($c->response->output, "<img") === false,
            "JSON is encoded safely for HTML-sensitive text",
        );
        nc(
            in_array("Cache-Control: no-store", $c->response->headers, true) &&
                in_array("X-Robots-Tag: noindex, nofollow, noarchive", $c->response->headers, true),
            "Demo API is uncached and excluded from indexing",
        );
        $before = count($c->db->queries);
        $c->request->get = ["ids" => "1); DROP TABLE oc_review"];
        $c->request->get["route"] = "extension/module/review_factory_demo";
        $c->index();
        nc(
            count($c->db->queries) === $before &&
                strpos($c->response->output, "demo_unavailable") !== false,
            "Malformed IDs never reach native SQL",
        );
        $_SERVER["HTTP_HOST"] = "lavka-speciy.com";
        $c->request->get = ["ids" => "1"];
        $c->request->get["route"] = "extension/module/review_factory_demo";
        $c->index();
        nc(
            strpos($c->response->output, "demo_disabled") !== false,
            "Copied module refuses the production hostname",
        );
        foreach ($c->db->queries as $sql) {
            nc(
                stripos($sql, "review") === false && strpos($sql, "SELECT ") === 0,
                "Public controller never reads or modifies native review or customer data",
            );
        }
    } else {
        $c->model_setting_event = new DemoEvents();
        $c->model_setting_setting = new DemoSettings();
        $c->model_user_user_group = new DemoPermissions();
        $c->user->modify = false;
        $c->install();
        nc(
            count($c->model_setting_event->events) === 1,
            "Unauthorized module installation creates no event",
        );
        $c->user->modify = true;
        $c->install();
        $c->install();
        nc(
            count($c->model_setting_event->events) === 3 &&
                count($c->model_user_user_group->permissions) === 4,
            "Installation is idempotent and grants the current administrator group only",
        );
        nc(
            $c->model_setting_event->events[
                "review_factory_demo|catalog/controller/common/header/before"
            ][0] === "catalog/controller/common/header/before",
            "OpenCart 3 header event is registered",
        );
        $route = "common/column_left";
        $menus = ["menus" => [["id" => "native-menu"]]];
        $template = "";
        $c->menu($route, $menus, $template);
        nc(
            count($menus["menus"]) === 2 && $menus["menus"][1]["id"] === "menu-review-factory-demo",
            "Authorized moderator gets a dedicated native sidebar item",
        );
        $c->user->access = false;
        $empty = ["menus" => []];
        $c->menu($route, $empty, $template);
        nc($empty["menus"] === [], "Sidebar entry respects module access permissions");
        $c->user->access = true;
        $c->index();
        nc(
            $c->load->viewData["total"] === 1 &&
                $c->load->viewData["items"][0]["demo_state"] === "pending",
            "Native moderation list starts with saved synthetic records",
        );
        nc(
            strpos($c->load->viewData["filters"][0]["url"], "&filter=") !== false &&
                strpos($c->load->viewData["filters"][0]["url"], "&amp;") === false,
            "URLs are escaped exactly once by the template",
        );
        $post = [
            "entry_id" => $f["entry"]["id"],
            "decision" => "approved",
            "revision" => (string) $f["entry"]["revision"],
            "user_token" => "wrong",
        ];
        $c->request->server["REQUEST_METHOD"] = "POST";
        $c->request->post = $post;
        $c->index();
        nc(
            ReviewFactory\demoList($f["private"])["total"] === 1,
            "Invalid CSRF token cannot approve a record",
        );
        $c->request->post["user_token"] = $token;
        $c->user->modify = false;
        $c->index();
        nc(
            ReviewFactory\demoList($f["private"])["total"] === 1,
            "Read-only administrator cannot moderate",
        );
        $c->user->modify = true;
        $c->index();
        nc(
            ReviewFactory\demoList($f["private"], "approved")["total"] === 1 &&
                $c->response->redirect !== "",
            "Authorized administrator approves explicitly",
        );
        $c->request->post["decision"] = "hidden";
        $c->index();
        nc(
            ReviewFactory\demoList($f["private"], "hidden")["total"] === 1,
            "Administrator can withdraw the approved record",
        );
        $c->uninstall();
        nc(
            count($c->model_setting_event->events) === 1 &&
                isset($c->model_setting_event->events["other_module"]),
            "Uninstall removes only this module event",
        );
        nc(
            ReviewFactory\demoList($f["private"], "hidden")["total"] === 1,
            "Uninstall preserves private moderation history",
        );
    }
    echo ReviewFactory\json([
        "state" => "ok",
        "mode" => $mode,
        "checks" => $checks,
        "php" => PHP_VERSION,
    ]) . "\n";
} finally {
    demoRemove($base);
}
