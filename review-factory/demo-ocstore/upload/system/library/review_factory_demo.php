<?php
// ReviewFactory TEST demo
class ReviewFactoryDemoBridge
{
    private $settings;
    public function available()
    {
        if (
            !defined("DB_DATABASE") ||
            DB_DATABASE !== "lavkasp_test" ||
            strtolower(explode(":", $_SERVER["HTTP_HOST"] ?? "")[0]) !== "test.lavka-speciy.com"
        ) {
            return false;
        }
        $file = DIR_SYSTEM . "library/review_factory_demo_config.php";
        if (!is_file($file) || is_link($file)) {
            return false;
        }
        $this->settings = require $file;
        if (
            !is_array($this->settings) ||
            !is_file($this->settings["runtime"] ?? "") ||
            is_link($this->settings["runtime"])
        ) {
            return false;
        }
        require_once $this->settings["runtime"];
        return true;
    }
    private function data()
    {
        if (!$this->available()) {
            throw new RuntimeException("demo_test_only");
        }
        return $this->settings["data"];
    }
    public function listing($filter, $page)
    {
        return \ReviewFactory\demoList($this->data(), $filter, $page);
    }
    public function moderate($id, $decision, $actor, $revision)
    {
        \ReviewFactory\demoModerate($this->data(), $id, $decision, $actor, $revision);
    }
    public function read($ids, $detail)
    {
        return \ReviewFactory\demoRead($this->data(), $ids, $detail);
    }
}
