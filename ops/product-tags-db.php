<?php
// CLI-only bridge: config is received through stdin, never included/executed.
$input = json_decode(stream_get_contents(STDIN),true);
function stop_tags_db($code) { echo json_encode(['ok'=>false,'code'=>$code]); exit(1); }
if (!is_array($input) || !isset($input['DB_DATABASE'],$input['DB_PREFIX'],$input['store_id'],$input['action']) || $input['DB_DATABASE'] !== 'lavkasp_test' || !is_string($input['DB_PREFIX']) || !preg_match('/^[a-zA-Z0-9_]*$/D',$input['DB_PREFIX']) || $input['store_id'] !== 0 || !in_array($input['action'],['check','initialize'],true)) stop_tags_db('INVALID_TEST_TARGET');
if (!extension_loaded('mysqli')) stop_tags_db('MYSQLI_UNAVAILABLE');
mysqli_report(MYSQLI_REPORT_OFF);
class TagsDbAdapter {
    public $db;
    public function __construct($db) { $this->db=$db; }
    public function query($sql) {
        $r=$this->db->query($sql);
        if ($r === false) throw new RuntimeException('DB_QUERY_FAILED');
        $rows=[];
        if ($r !== true) while ($row=$r->fetch_assoc()) $rows[]=$row;
        return (object)['rows'=>$rows,'row'=>$rows ? $rows[0] : [],'num_rows'=>count($rows)];
    }
    public function escape($value) { return $this->db->real_escape_string($value); }
    public function countAffected() { return $this->db->affected_rows; }
}
class TagsCliConfig { public function get($key) { return $key==='config_store_id' ? 0 : null; } }
class TagsCliRegistry {
    private $data;
    public function __construct($db) { $this->data=['db'=>$db,'config'=>new TagsCliConfig()]; }
    public function get($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
    public function has($key) { return isset($this->data[$key]); }
}
try {
    $connection=mysqli_init(); $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT,5);
    if (!@$connection->real_connect($input['DB_HOSTNAME'],$input['DB_USERNAME'],$input['DB_PASSWORD'],$input['DB_DATABASE'],(int)$input['DB_PORT'])) stop_tags_db('CONNECT_FAILED');
    if (!$connection->set_charset('utf8mb4')) stop_tags_db('CHARSET_FAILED');
    $db=new TagsDbAdapter($connection);
    if ($db->query('SELECT DATABASE() AS selected_database')->row['selected_database']!=='lavkasp_test') stop_tags_db('DATABASE_IDENTITY_MISMATCH');
    define('DB_PREFIX',$input['DB_PREFIX']);
    require_once __DIR__.'/../store/system/library/lavka_product_tags.php';
    $expected=[
        'lavka_tag_definition'=>['store_id','code','enabled','label_ru','label_uk','background','foreground','sort_order','days','revision'],
        'lavka_product_tag'=>['store_id','product_id','code','enabled','revision']
    ];
    $tables=[];
    foreach ($expected as $name=>$columns) {
        $table=DB_PREFIX.$name;
        $q=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$db->escape($table)."'");
        $exists=(bool)$q->num_rows;
        if ($exists) {
            if (strtolower($q->row['ENGINE'])!=='innodb') stop_tags_db('EXISTING_TAG_TABLE_ENGINE_DIFFERS');
            $actual=$db->query('SHOW COLUMNS FROM `'.$table.'`')->rows;
            if (array_column($actual,'Field')!==$columns) stop_tags_db('EXISTING_TAG_TABLE_SCHEMA_DIFFERS');
            $keys=$db->query("SHOW INDEX FROM `".$table."` WHERE Key_name='PRIMARY'")->rows;
            $required=$name==='lavka_tag_definition' ? ['store_id','code'] : ['store_id','product_id','code'];
            if (array_column($keys,'Column_name')!==$required) stop_tags_db('EXISTING_TAG_TABLE_KEY_DIFFERS');
        }
        $tables[$name]=['exists'=>$exists];
    }
    $engine=new LavkaProductTags(new TagsCliRegistry($db));
    $preview=$engine->defaults();
    if ($tables['lavka_tag_definition']['exists']) {
        $count=$db->query('SELECT COUNT(*) AS total FROM `'.DB_PREFIX.'lavka_tag_definition` WHERE store_id=0')->row['total'];
        if ((int)$count===5) $preview=$engine->definitions();
        elseif ((int)$count>5) stop_tags_db('EXISTING_TAG_DEFINITIONS_DIFFER');
    }
    if ($input['action']==='initialize') {
        $preview=$engine->initialize();
        foreach ($tables as &$table) $table['exists']=true;
        unset($table);
    } elseif ($input['action']!=='check') { stop_tags_db('UNKNOWN_ACTION'); }
    echo json_encode(['ok'=>true,'database'=>'lavkasp_test','tables'=>$tables,'definitions'=>$preview,
        'database_writes'=>$input['action']==='initialize','scope'=>'Only two new tag tables; existing products/settings are not updated.']);
    $connection->close();
} catch (Throwable $e) { stop_tags_db('TAG_SCHEMA_OPERATION_FAILED'); }
