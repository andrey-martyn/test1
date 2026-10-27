#!/usr/bin/env python3
"""Export ONLY frontend user_js/user_css for review; never update the shop or DB.

The private ZIP is written outside the web root. Existing installation guards
remain in effect; the known legacy-code issue alone does not prevent reading it.
"""
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

import test_store as store


PHP_EXPORT = r'''
$c = json_decode(stream_get_contents(STDIN), true);
function export_fail($code) { echo json_encode(array('ok'=>false,'code'=>$code)); exit(1); }
if (!is_array($c) || $c['DB_DATABASE'] !== 'lavkasp_test' || !preg_match('/^[a-zA-Z0-9_]*$/', $c['DB_PREFIX'])) export_fail('INVALID_EXPORT_TARGET');
if (!extension_loaded('mysqli')) export_fail('MYSQLI_UNAVAILABLE');
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
try {
    if (!@$db->real_connect($c['DB_HOSTNAME'], $c['DB_USERNAME'], $c['DB_PASSWORD'], $c['DB_DATABASE'], (int)$c['DB_PORT'])) export_fail('CONNECT_FAILED');
    if (!$db->set_charset('utf8mb4')) export_fail('CHARSET_FAILED');
    $r = $db->query('SELECT DATABASE() AS selected_database');
    if (!$r || $r->fetch_assoc()['selected_database'] !== 'lavkasp_test') export_fail('DATABASE_IDENTITY_MISMATCH');
    if (!isset($c['source_store_id']) || !is_int($c['source_store_id']) || $c['source_store_id'] < 0) export_fail('INVALID_STORE_ID');
    $r = $db->query('SELECT data FROM `'.$c['DB_PREFIX'].'uni_setting` WHERE store_id = '.$c['source_store_id'].' LIMIT 2');
    if (!$r || $r->num_rows !== 1) export_fail('UNIQUE_SETTINGS_ROW_NOT_FOUND');
    $raw = $r->fetch_assoc()['data'];
    $settings = json_decode($raw, true);
    if (!is_array($settings) || !is_object(json_decode($raw)) || (!isset($settings['version']) && !isset($settings['catalog']))) export_fail('UNSUPPORTED_SETTINGS_FORMAT');
    $out = array('ok'=>true,'database'=>'lavkasp_test','settings_sha256'=>hash('sha256', $raw),'fields'=>array());
    foreach (array('user_js', 'user_css') as $key) {
        $present = array_key_exists($key, $settings);
        $value = $present ? $settings[$key] : '';
        if (!is_string($value) || strlen($value) > 2*1024*1024) export_fail('UNSUPPORTED_FRONTEND_FIELD');
        // Exactly the same single decode used by UniShop2's header/stylesheet.
        $code = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $out['fields'][$key] = array('present'=>$present,'stored_sha256'=>hash('sha256', $value),'stored_bytes'=>strlen($value),'decoded_sha256'=>hash('sha256', $code),'code'=>$code);
    }
    $db->close();
    echo json_encode($out);
} catch (Exception $e) { export_fail('FRONTEND_EXPORT_FAILED'); }
'''


def read_frontend(report):
    """No report/exception contains DB credentials or raw, unselected settings."""
    db = report.get('db') or {}
    source = db.get('unishop_settings_source') or {}
    config = store.config_values(store.SHOP/'config.php')
    config.setdefault('DB_PORT', '3306')
    expected_table = (config.get('DB_PREFIX') or '') + 'uni_setting'
    store_id = source.get('store_id')
    if (not db.get('ok') or not db.get('unishop_settings_found') or
            source != {'table':expected_table,'column':'data','store_id':store_id,'format':'json'} or
            type(store_id) is not int or store_id < 0 or store_id != db.get('runtime_store_id')):
        raise ValueError('Native settings source is unverified')
    config['source_store_id'] = store_id
    result = subprocess.run(
        ['php','-d','display_errors=0','-d','log_errors=0','-r',PHP_EXPORT],
        input=json.dumps(config), universal_newlines=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    payload = json.loads(result.stdout)
    if result.returncode or payload.get('ok') is not True or payload.get('database') != store.DATABASE:
        raise ValueError('Read-only frontend export failed')
    if set(payload) != {'ok','database','settings_sha256','fields'} or not re.fullmatch(r'[0-9a-f]{64}', payload['settings_sha256']):
        raise ValueError('Unexpected export format')
    if set(payload['fields']) != {'user_js','user_css'}:
        raise ValueError('Unexpected frontend fields')
    for field in payload['fields'].values():
        if set(field) != {'present','stored_sha256','stored_bytes','decoded_sha256','code'} or not isinstance(field['code'], str):
            raise ValueError('Unexpected frontend field format')
        if not re.fullmatch(r'[0-9a-f]{64}', field['stored_sha256']) or type(field['present']) is not bool or type(field['stored_bytes']) is not int:
            raise ValueError('Invalid field metadata')
        if not 0 <= field['stored_bytes'] <= 2*1024*1024:
            raise ValueError('Frontend field exceeds size limit')
        if hashlib.sha256(field['code'].encode('utf-8')).hexdigest() != field['decoded_sha256']:
            raise ValueError('Frontend field hash mismatch')
    return payload


def loader_metadata():
    """Only static cache key identifiers, not PHP code or configuration values."""
    relative = 'catalog/model/extension/module/uni_settings.php'
    result = []
    for root in (store.SHOP, store.STORAGE/'modification'):
        path = root/relative
        if not path.is_file() or not store.within(path.resolve(), root) or path.stat().st_size > 2*1024*1024:
            continue
        code = path.read_text(encoding='utf-8-sig', errors='replace')
        keys = re.findall(r'''cache->(?:get|set|delete)\(\s*['"]([A-Za-z0-9_.-]{1,80})['"]''', code)
        calls = re.findall(r'cache->(?:get|set|delete)\s*\(', code)
        result.append({'file':str(path),'sha256':store.sha(path),'cache_key_prefixes':sorted(set(keys)),
                       'cache_calls':len(calls),'unresolved_cache_calls':len(calls)-len(keys)})
    return result


def export_bundle(report, payload):
    if store.BACKUPS.resolve() != store.BACKUPS or store.within(store.BACKUPS, store.SHOP) or store.within(store.BACKUPS, store.STORAGE):
        raise ValueError('Unsafe review directory')
    identifier = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
    directory = store.BACKUPS/('legacy-review-' + identifier)
    directory.mkdir(parents=True, mode=0o700)
    destination = directory/'legacy-frontend.zip'
    metadata = {'target':store.HOSTNAME,'database':store.DATABASE,
                'source':report['db']['unishop_settings_source'],
                'settings_sha256':payload['settings_sha256'],'fields':{},
                'loader_cache':loader_metadata(), 'shop_writes':False, 'database_writes':False}
    try:
        store.checked_manifest()
        metadata['release_source_check'] = {'ok':True}
    except store.ReleaseCheckError as error:
        metadata['release_source_check'] = {'ok':False,'diagnostic':json.loads(str(error))}
    except (OSError, ValueError, KeyError):
        metadata['release_source_check'] = {'ok':False,'code':'RELEASE_SOURCE_CHECK_FAILED'}
    filenames = {'user_js':'custom.js','user_css':'custom.css'}
    with os.fdopen(os.open(str(destination), os.O_CREAT|os.O_EXCL|os.O_WRONLY, 0o600), 'wb') as handle:
        with zipfile.ZipFile(handle, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
            for key in ('user_js','user_css'):
                field = payload['fields'][key]
                archive.writestr(filenames[key], field['code'].encode('utf-8'))
                metadata['fields'][key] = {name:value for name,value in field.items() if name != 'code'}
                metadata['fields'][key]['file'] = filenames[key]
            archive.writestr('review.json', json.dumps(metadata, ensure_ascii=True, indent=2)+'\n')
    return {'exported':str(destination),'fields':['user_js','user_css'],
            'shop_writes':False,'database_writes':False,
            'release_source_check':metadata['release_source_check']}


def main():
    try:
        report = store.preflight()
        # This action only reads the conflicting code. Installation is still blocked.
        blockers = [issue for issue in report['issues'] if issue != store.LEGACY_ISSUE]
        if not report.get('isolation_ok') or blockers:
            print(json.dumps(report, ensure_ascii=True, indent=2))
            return 2
        result = export_bundle(report, read_frontend(report))
        print(json.dumps(result, ensure_ascii=True, indent=2))
        return 0
    except (OSError, ValueError, KeyError, TypeError, subprocess.TimeoutExpired):
        print('Stopped. Read-only legacy export failed; no raw code or credential diagnostics are displayed.', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
