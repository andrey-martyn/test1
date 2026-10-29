<?php
// Test-only mysqli substitute: never connects to a database or runs store code.
namespace LavkaProbeFixture;

if (!defined('MYSQLI_REPORT_OFF')) define('MYSQLI_REPORT_OFF', 0);
if (!defined('MYSQLI_OPT_CONNECT_TIMEOUT')) define('MYSQLI_OPT_CONNECT_TIMEOUT', 0);

function extension_loaded($name) { return $name === 'mysqli'; }
function mysqli_report($flags) {}
function mysqli_init() { global $c; return new FixtureDatabase($c); }

class FixtureResult {
    public $num_rows;
    private $rows;
    private $offset = 0;
    public function __construct($rows) { $this->rows = $rows; $this->num_rows = count($rows); }
    public function fetch_assoc() { return $this->offset < $this->num_rows ? $this->rows[$this->offset++] : null; }
}

class FixtureDatabase {
    public static $queries = array();
    public $errno = 0;
    private $config;
    private $columns;
    public function __construct($config) {
        $this->config = $config;
        $this->columns = isset($config['fixture_columns']) ? $config['fixture_columns'] : array('store_id','name','url');
    }
    public function options($key, $value) {}
    public function real_connect($host, $user, $password, $database, $port) { return true; }
    public function set_charset($charset) { return $charset === 'utf8mb4'; }
    public function real_escape_string($value) { return str_replace("'", "\\'", $value); }
    public function close() {}
    public function prepare($sql) { self::$queries[] = $sql; return new FixtureStatement($sql,$this->config); }
    private function error($number) { $this->errno = $number; return false; }
    public function query($sql) {
        self::$queries[] = $sql;
        $this->errno = 0;
        if (stripos(ltrim($sql), 'SELECT ') !== 0) return $this->error(9002);
        if (strpos($sql, 'SELECT DATABASE()') === 0) {
            return new FixtureResult(array(array('selected_database'=>isset($this->config['fixture_database']) ? $this->config['fixture_database'] : 'lavkasp_test')));
        }
        if (strpos($sql, 'information_schema.COLUMNS') !== false && strpos($sql, "TABLE_NAME = 'oc_store'") !== false) {
            if (!empty($this->config['fixture_schema_error'])) return $this->error(1142);
            $rows = array();
            foreach ($this->columns as $name) $rows[] = array('column_name'=>$name);
            return new FixtureResult($rows);
        }
        if (strpos($sql, 'FROM `oc_store`') !== false) {
            if (preg_match('/\bssl\b/i', $sql) && !in_array('ssl', $this->columns, true)) return $this->error(1054);
            if (!empty($this->config['fixture_mapping_error'])) return $this->error(2013);
            $rows = array();
            $ids = isset($this->config['fixture_stores']) ? $this->config['fixture_stores'] : array();
            foreach ($ids as $id) $rows[] = array('store_id'=>$id);
            return new FixtureResult($rows);
        }
        if (strpos($sql, 'SELECT store_id, `key`, value FROM `oc_setting`') === 0) {
            return new FixtureResult(array(array('store_id'=>0,'key'=>'config_theme','value'=>'unishop2')));
        }
        if (strpos($sql, 'SELECT code, name, version, IF(') === 0) {
            $xml = '<modification><file><add><![CDATA[$this->load->model("extension/module/uni_settings");]]></add></file></modification>';
            return new FixtureResult(empty($this->config['fixture_no_loader']) ? array(array('code'=>'UniShop2 template','name'=>'UniShop2','version'=>'1.9.0.0','unishop_xml'=>$xml)) : array());
        }
        if (strpos($sql, 'SELECT store_id, code, `key`, serialized') === 0) return new FixtureResult(array());
        if (strpos($sql, 'information_schema.COLUMNS') !== false && strpos($sql, 'TABLE_NAME IN') !== false) {
            return new FixtureResult(array(array('table_name'=>'oc_uni_setting','column_name'=>'store_id'),array('table_name'=>'oc_uni_setting','column_name'=>'data')));
        }
        if (strpos($sql, 'SELECT data FROM `oc_uni_setting`') === 0) {
            $ids = isset($this->config['fixture_stores']) ? $this->config['fixture_stores'] : array();
            $expected = $ids ? (int)$ids[0] : 0;
            if (!preg_match('/store_id = ([0-9]+)/', $sql, $match) || (int)$match[1] !== $expected) return $this->error(9001);
            $settings = array('version'=>'1.9.0.0','catalog'=>array('items_on_mobile'=>2),'liveprice'=>1,'merge_css'=>1,'license'=>'fixture-secret','user_js'=>'var internal = "fixture-secret";');
            if (isset($this->config['fixture_settings'])) $settings = $this->config['fixture_settings'];
            $data = isset($this->config['fixture_raw_settings']) ? $this->config['fixture_raw_settings'] : (!empty($this->config['fixture_bad_json']) ? 'not-json' : json_encode($settings));
            $rows = array(array('data'=>$data));
            if (!empty($this->config['fixture_duplicate_settings'])) $rows[] = array('data'=>$data);
            return new FixtureResult($rows);
        }
        if ((strpos($sql, 'SELECT COUNT(*) AS n FROM `oc_theme`') === 0 && strpos($sql, "AND theme = '") !== false) || strpos($sql, 'SELECT theme_id, OCTET_LENGTH(code)') === 0) {
            $source = strpos($sql, 'SELECT theme_id,') === 0;
            if (!$source && !empty($this->config['fixture_theme_count_error'])) return $this->error(1142);
            if ($source && !empty($this->config['fixture_theme_source_error'])) return $this->error(1142);
            if (!preg_match("/store_id = ([0-9]+) AND theme = '([a-zA-Z0-9_-]+)' AND route = 'product\/product'/", $sql, $match)) return $this->error(9001);
            $rows = array();
            foreach (isset($this->config['fixture_theme_rows']) ? $this->config['fixture_theme_rows'] : array() as $row) {
                if ((int)$row['store_id'] !== (int)$match[1] || $row['theme'] !== $match[2] || $row['route'] !== 'product/product') continue;
                $rows[] = array('theme_id'=>$row['theme_id'],'stored_bytes'=>strlen($row['code']),'source_code'=>strlen($row['code'])<=2097152 ? $row['code'] : null);
            }
            return new FixtureResult($source ? array_slice($rows,0,3) : array(array('n'=>count($rows))));
        }
        if (strpos($sql, 'SELECT COUNT(*) AS n FROM `oc_theme`') === 0) return new FixtureResult(array(array('n'=>0)));
        return $this->error(9003);
    }
}

class FixtureStatement {
    public $affected_rows = 0;
    private $sql;
    private $config;
    public function __construct($sql,$config) { $this->sql=$sql; $this->config=$config; }
    public function bind_param($types,&$after,&$store_id,&$before) {
        if ($types !== 'sis' || $store_id !== 0 || !is_string($after) || !is_string($before)) throw new \Exception('Invalid fixture parameters');
    }
    public function execute() {
        if ($this->sql !== 'UPDATE `oc_uni_setting` SET `data` = ? WHERE `store_id` = ? AND BINARY `data` = BINARY ? LIMIT 1') return false;
        if (!empty($this->config['fixture_update_error'])) return false;
        $this->affected_rows = empty($this->config['fixture_race']) ? 1 : 0;
        return true;
    }
    public function close() {}
}
