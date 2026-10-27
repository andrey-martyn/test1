<?php
// CLI bridge. Credentials/settings travel through private pipes, never CLI arguments.
$c = json_decode(stream_get_contents(STDIN), true);
function stop_css($code) { echo json_encode(array('ok'=>false,'code'=>$code)); exit(1); }
if (!is_array($c) || $c['DB_DATABASE'] !== 'lavkasp_test' || !preg_match('/^[a-zA-Z0-9_]*$/', $c['DB_PREFIX']) || !isset($c['store_id']) || $c['store_id'] !== 0) stop_css('INVALID_TEST_TARGET');
if (!extension_loaded('mysqli')) stop_css('MYSQLI_UNAVAILABLE');
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
try {
    if (!@$db->real_connect($c['DB_HOSTNAME'], $c['DB_USERNAME'], $c['DB_PASSWORD'], $c['DB_DATABASE'], (int)$c['DB_PORT'])) stop_css('CONNECT_FAILED');
    if (!$db->set_charset('utf8mb4')) stop_css('CHARSET_FAILED');
    $r = $db->query('SELECT DATABASE() AS selected_database');
    if (!$r || $r->fetch_assoc()['selected_database'] !== 'lavkasp_test') stop_css('DATABASE_IDENTITY_MISMATCH');
    $table = '`'.$c['DB_PREFIX'].'uni_setting`';
    $r = $db->query('SELECT data FROM '.$table.' WHERE store_id = 0 LIMIT 2');
    if (!$r || $r->num_rows !== 1) stop_css('UNIQUE_SETTINGS_ROW_NOT_FOUND');
    $raw = $r->fetch_assoc()['data'];
    $data = json_decode($raw);
    if (strlen($raw) > 4*1024*1024 || !is_object($data) || !isset($data->user_css) || !is_string($data->user_css)) stop_css('UNSUPPORTED_SETTINGS_FORMAT');
    if ($c['action'] === 'read') {
        // Captured by Python in memory; this response must never be printed/saved wholesale.
        echo json_encode(array('ok'=>true,'row'=>$raw,'decoded_css'=>html_entity_decode($data->user_css, ENT_QUOTES, 'UTF-8')));
    } elseif ($c['action'] === 'replace') {
        if (!isset($c['before'], $c['after']) || $raw !== $c['before']) stop_css('SETTINGS_CHANGED_CONCURRENTLY');
        $next = json_decode($c['after']);
        if (!is_object($next) || !isset($next->user_css) || !is_string($next->user_css)) stop_css('INVALID_CSS_REPLACEMENT');
        unset($data->user_css, $next->user_css);
        if (json_encode($data) !== json_encode($next)) stop_css('NON_CSS_CHANGE_REJECTED');
        $stmt = $db->prepare('UPDATE '.$table.' SET `data` = ? WHERE `store_id` = ? AND BINARY `data` = BINARY ? LIMIT 1');
        if (!$stmt) stop_css('CSS_UPDATE_PREPARE_FAILED');
        $stmt->bind_param('sis', $c['after'], $c['store_id'], $c['before']);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) stop_css('CSS_COMPARE_AND_SWAP_FAILED');
        $stmt->close();
        echo json_encode(array('ok'=>true,'updated'=>true));
    } else { stop_css('UNKNOWN_ACTION'); }
    $db->close();
} catch (Throwable $e) { stop_css('CSS_MIGRATION_BRIDGE_FAILED'); }
