#!/usr/bin/env python3
"""Read-only preflight and hash-checked file deployment for this test store only.

Run outside the web root. Never include/execute store config.php or its bootstrap.
Only SELECT queries are issued by the optional PHP CLI database probe.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from urllib.parse import urlsplit

REPO = Path(__file__).resolve().parents[1]
SHOP = Path('/home/lavkasp/lavka-speciy.com/test')
STORAGE = Path('/home/lavkasp/lavka-speciy.com/test_storage')
BACKUPS = Path('/home/lavkasp/lavka-speciy.com/test_frontend_backups')
DATABASE = 'lavkasp_test'
HOSTNAME = 'test.lavka-speciy.com'


class ReleaseCheckError(ValueError):
    """Safe release diagnostics containing file paths/hashes, never configuration values."""


def within(path, root):
    return os.path.commonpath([str(path), str(root)]) == str(root)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def config_values(path):
    """Accept only static string/integer/constant concatenation. Never eval PHP."""
    text = path.read_text(encoding='utf-8-sig')
    lexical = r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*[\s\S]*?\*/|//[^\n]*|\#[^\n]*"
    text = re.sub(lexical, lambda m: m[0] if m[0][0] in "'\"" else ' ', text)
    definitions = re.finditer(r'''\bdefine\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1\s*,\s*(.*?)\s*\)\s*;''', text, re.S)
    values = {}
    for definition in definitions:
        key, expression = definition[2], definition[3]
        if not (key.startswith(('DIR_', 'DB_')) or key in ('HTTP_SERVER', 'HTTPS_SERVER', 'VERSION')):
            continue
        tokens = re.findall(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|[A-Z_][A-Z0-9_]*|\d+|\.|\S", expression)
        parts = []
        try:
            for index, token in enumerate(tokens):
                if index % 2:
                    if token != '.':
                        raise ValueError()
                elif token.startswith("'"):
                    parts.append(re.sub(r"\\([\\'])", lambda m: m[1], token[1:-1]))
                elif token.startswith('"'):
                    value = token[1:-1]
                    if re.search(r'(?<!\\)\$', value):
                        raise ValueError()
                    escapes = {'n':'\n','r':'\r','t':'\t','v':'\v','e':'\x1b','f':'\f','\\':'\\','$':'$','"':'"'}
                    parts.append(re.sub(r'\\([nrtvef\\$\"])', lambda m: escapes[m[1]], value))
                elif token == '__DIR__':
                    parts.append(str(path.parent))
                elif token == '__FILE__':
                    parts.append(str(path))
                elif token.isdigit():
                    parts.append(token)
                else:
                    parts.append(values[token])
            if not tokens or len(tokens) % 2 == 0:
                raise ValueError()
            values[key] = ''.join(parts)
        except (KeyError, ValueError):
            # Unsupported expressions are reported by name, never with their value.
            values[key] = None
    return values


PHP_PROBE = r'''
$c = json_decode(stream_get_contents(STDIN), true);
function fail_probe($code, $stage = null, $details = array()) {
    global $out;
    $result = isset($out) && is_array($out) ? $out : array();
    $result['ok'] = false;
    $result['code'] = $code;
    if ($stage !== null) $result['failed_stage'] = $stage;
    if ($details) $result['diagnostics'] = $details;
    echo json_encode($result);
    exit(1);
}
function unishop_source_refs($source) {
    $patterns = array(
        'config_get_keys' => '~config->get\(\s*[\'"]((?:config_)?uni[a-zA-Z0-9_]*)[\'"]~',
        'load_routes' => '~load->(?:model|controller)\(\s*[\'"]([a-zA-Z0-9_/]*uni[a-zA-Z0-9_/]*)[\'"]~',
        'table_names' => '~DB_PREFIX\s*\.\s*[\'"]([a-zA-Z0-9_]*uni[a-zA-Z0-9_]*)~'
    );
    $out = array();
    foreach ($patterns as $name => $pattern) {
        preg_match_all($pattern, $source, $matches);
        $out[$name] = array_slice(array_values(array_unique($matches[1])), 0, 32);
    }
    return $out;
}
function unishop_settings_summary($data, $source, &$out) {
    $u = json_decode($data, true);
    if (!is_array($u) || !is_object(json_decode($data)) || (!isset($u['version']) && !isset($u['catalog']))) {
        $out['unishop_settings_error'] = 'SETTINGS_JSON_FORMAT_UNSUPPORTED';
        return false;
    }
    $out['unishop_settings_found'] = true;
    unset($out['unishop_settings_error']);
    $out['unishop_settings_source'] = $source;
    $out['unishop_version'] = isset($u['version']) && is_string($u['version']) && preg_match('/^[0-9.]{1,32}$/', $u['version']) ? $u['version'] : null;
    $items = isset($u['catalog']['items_on_mobile']) ? $u['catalog']['items_on_mobile'] : null;
    $out['items_on_mobile'] = in_array($items, array(1, 2, '1', '2'), true) ? (int)$items : null;
    // Match the theme's actual isset() checks, including presence-valued switches.
    $out['liveprice_enabled'] = isset($u['liveprice']);
    $out['merge_css'] = isset($u['merge_css']);
    $out['merge_js'] = isset($u['merge_js']);
    $out['legacy_mobile_code'] = (bool)preg_match('/data-lavka-mobile|lavka-mobile-cards|lavka-option-select|lavka-card-buyline|uni-grid-test/', (isset($u['user_js']) && is_string($u['user_js']) ? $u['user_js'] : '').(isset($u['user_css']) && is_string($u['user_css']) ? $u['user_css'] : ''));
    return true;
}
if (!extension_loaded('mysqli')) fail_probe('MYSQLI_UNAVAILABLE');
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
try {
    if (!@$db->real_connect($c['DB_HOSTNAME'], $c['DB_USERNAME'], $c['DB_PASSWORD'], $c['DB_DATABASE'], (int)$c['DB_PORT'])) fail_probe('CONNECT_FAILED');
    $r = $db->query('SELECT DATABASE() AS selected_database');
    if (!$r) fail_probe('DATABASE_QUERY_FAILED');
    $out = array('ok'=>true, 'database'=>$r->fetch_assoc()['selected_database'], 'php_cli_version'=>PHP_VERSION);
    if ($out['database'] !== 'lavkasp_test') fail_probe('DATABASE_IDENTITY_MISMATCH', 'database_identity');
    $out['database_verified'] = true;
    $p = $c['DB_PREFIX'];
    $store_table = $db->real_escape_string($p.'store');
    $r = $db->query("SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$store_table."' ORDER BY ORDINAL_POSITION");
    if (!$r) fail_probe('STORE_SCHEMA_QUERY_FAILED', 'store_schema', array('sql_errno'=>(int)$db->errno));
    $store_columns = array();
    while ($row = $r->fetch_assoc()) $store_columns[] = strtolower($row['column_name']);
    $out['store_mapping'] = array('status'=>'unverified', 'columns'=>$store_columns);
    if (!in_array('store_id', $store_columns, true) || !in_array('url', $store_columns, true)) {
        fail_probe('STORE_SCHEMA_UNSUPPORTED', 'store_schema');
    }
    $urls = array_unique(array($c['HTTP_SERVER'], $c['HTTPS_SERVER']));
    $escaped_urls = array();
    foreach ($urls as $url) $escaped_urls[] = $db->real_escape_string($url);
    $url_list = "'".implode("','", $escaped_urls)."'";
    $where = '`url` IN ('.$url_list.')';
    if (in_array('ssl', $store_columns, true)) $where .= ' OR `ssl` IN ('.$url_list.')';
    $r = $db->query("SELECT `store_id` FROM `".$p."store` WHERE ".$where." LIMIT 2");
    if (!$r) fail_probe('STORE_MAPPING_QUERY_FAILED', 'store_mapping', array('sql_errno'=>(int)$db->errno));
    $matched_stores = array();
    while ($row = $r->fetch_assoc()) $matched_stores[] = (int)$row['store_id'];
    $out['store_mapping']['matched_store_ids'] = $matched_stores;
    if (count($matched_stores) > 1) fail_probe('STORE_MAPPING_AMBIGUOUS', 'store_mapping');
    $store_id = $matched_stores ? $matched_stores[0] : 0;
    $out['store_mapping']['status'] = $matched_stores ? 'matched' : 'default_store';
    $out['runtime_store_id'] = $store_id;
    $r = $db->query("SELECT store_id, `key`, value FROM `".$p."setting` WHERE store_id IN (0,".$store_id.") AND `key` IN ('config_theme','config_template','theme_default_directory','theme_unishop2_directory','config_url','config_ssl','config_unishop2') ORDER BY store_id");
    if (!$r) fail_probe('SETTINGS_QUERY_FAILED');
    $out['settings'] = array();
    $out['unishop_settings_found'] = false;
    $out['liveprice_enabled'] = null;
    $out['legacy_mobile_code'] = null;
    while ($row = $r->fetch_assoc()) {
        if ($row['key'] === 'config_unishop2') {
            unishop_settings_summary($row['value'], array('table'=>$p.'setting','key'=>'config_unishop2','store_id'=>(int)$row['store_id']), $out);
        } else { $out['settings'][$row['key']] = $row['value']; }
    }
    $r = $db->query("SELECT code, name, version, IF(code LIKE '%unishop%', xml, '') AS unishop_xml FROM `".$p."modification` WHERE status = 1");
    if (!$r) fail_probe('MODIFICATIONS_QUERY_FAILED');
    $out['active_ocmod'] = array();
    $out['unishop_loader_refs'] = array();
    while ($row = $r->fetch_assoc()) {
        if ($row['unishop_xml'] !== '') $out['unishop_loader_refs'][$row['code']] = unishop_source_refs($row['unishop_xml']);
        unset($row['unishop_xml']);
        $out['active_ocmod'][] = $row;
    }
    $native_loader = false;
    foreach ($out['unishop_loader_refs'] as $refs) {
        if (in_array('extension/module/uni_settings', $refs['load_routes'], true)) $native_loader = true;
    }
    if ($native_loader || !$out['unishop_settings_found']) {
        // Metadata only: never print setting values, raw XML, license keys or customer rows.
        $r = $db->query("SELECT store_id, code, `key`, serialized FROM `".$p."setting` WHERE `key` LIKE '%unishop%' OR code LIKE '%unishop%' OR `key` LIKE 'uni%' OR code LIKE 'uni%' ORDER BY store_id, `key` LIMIT 100");
        $out['unishop_setting_rows'] = array();
        if ($r) while ($row = $r->fetch_assoc()) $out['unishop_setting_rows'][] = $row;
        $names = array($p.'uni_setting', $p.'uni_settings', $p.'unishop2_setting', $p.'unishop2_settings');
        $r = $db->query("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('".implode("','", $names)."') ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $out['unishop_settings_tables'] = array();
        if ($r) while ($row = $r->fetch_assoc()) $out['unishop_settings_tables'][$row['table_name']][] = $row['column_name'];
        if ($native_loader) {
            // The active loader overrides runtime config_unishop2 from uni_setting.data.
            $out['unishop_settings_found'] = false;
            $out['liveprice_enabled'] = null;
            $out['legacy_mobile_code'] = null;
            unset($out['unishop_settings_source'], $out['unishop_version'], $out['items_on_mobile'], $out['merge_css'], $out['merge_js']);
            $columns = isset($out['unishop_settings_tables'][$p.'uni_setting']) ? $out['unishop_settings_tables'][$p.'uni_setting'] : array();
            if (in_array('store_id', $columns, true) && in_array('data', $columns, true)) {
                $r = $db->query("SELECT data FROM `".$p."uni_setting` WHERE store_id = ".$store_id." LIMIT 2");
                if ($r && $r->num_rows === 1) {
                    unishop_settings_summary($r->fetch_assoc()['data'], array('table'=>$p.'uni_setting','column'=>'data','store_id'=>$store_id,'format'=>'json'), $out);
                } else { $out['unishop_settings_error'] = 'UNIQUE_SETTINGS_ROW_NOT_FOUND'; }
            } else { $out['unishop_settings_error'] = 'NATIVE_SETTINGS_SCHEMA_UNVERIFIED'; }
        }
    }
    $r = $db->query("SELECT COUNT(*) AS n FROM `".$p."theme` WHERE route LIKE '%product/category%' OR route LIKE '%common/header%' OR route LIKE '%uni_options%'");
    $out['relevant_template_overrides'] = $r ? (int)$r->fetch_assoc()['n'] : null;
    $db->close();
    echo json_encode($out);
} catch (Exception $e) { fail_probe('DATABASE_PROBE_FAILED'); }
'''


def runtime_source_refs(db):
    """Read bounded source files named by OCMOD, emitting identifiers, never code/values."""
    paths = [SHOP/'catalog/controller/startup/startup.php', STORAGE/'modification/catalog/controller/startup/startup.php', STORAGE/'modification/system/framework.php']
    loaders = db.get('unishop_loader_refs') or {}
    if not isinstance(loaders, dict):
        loaders = {}
    for refs in loaders.values():
        for route in refs.get('load_routes', []):
            if not isinstance(route, str) or not re.fullmatch(r'extension/module/uni[a-zA-Z0-9_]*(?:/[a-zA-Z0-9_]+)*', route):
                continue
            relative = '/'.join(route.split('/')[:3]) + '.php'
            paths.extend([SHOP/'catalog/model'/relative, SHOP/'catalog/controller'/relative])
    result = []
    patterns = {
        'config_get_keys': r'''config->get\(\s*['"]((?:config_)?uni[a-zA-Z0-9_]*)['"]''',
        'table_names': r'''DB_PREFIX\s*\.\s*['"]([a-zA-Z0-9_]*uni[a-zA-Z0-9_]*)''',
    }
    for path in dict.fromkeys(paths):
        if not path.is_file() or not (within(path.resolve(), SHOP) or within(path.resolve(), STORAGE)) or path.stat().st_size > 2 * 1024 * 1024:
            continue
        source = path.read_text(encoding='utf-8-sig', errors='replace')
        refs = {key: sorted(set(re.findall(pattern, source)))[:32] for key, pattern in patterns.items()}
        if any(refs.values()):
            refs['file'] = str(path)
            result.append(refs)
    return result


def preflight(compatibility=True):
    report = {'target': HOSTNAME, 'shop': str(SHOP), 'storage': str(STORAGE), 'issues': [], 'warnings': [], 'isolation_ok': False}
    issues = report['issues']
    for root in (SHOP, STORAGE):
        if not root.is_dir() or root.resolve() != root:
            issues.append('Expected directory missing or redirected by symlink: ' + str(root))
    if within(REPO, SHOP) or within(REPO, STORAGE):
        issues.append('Keep the Git checkout outside the shop and storage.')
    if issues:
        report['ok'] = False
        return report
    index = SHOP/'index.php'
    report['opencart_version'] = config_values(index).get('VERSION') if index.is_file() and within(index.resolve(), SHOP) else None
    if compatibility and report['opencart_version'] != '3.0.2.0':
        issues.append('OpenCart version differs from the supplied 3.0.2.0 sources.')
    vqmod = SHOP/'vqmod'
    report['vqmod_present'] = vqmod.exists()
    if vqmod.exists():
        if not within(vqmod.resolve(), SHOP) or not within((vqmod/'xml').resolve(), SHOP):
            issues.append('VQmod path points outside test shop.')
        else:
            report['vqmod_xml_files'] = sorted(path.name for path in (vqmod/'xml').glob('*.xml'))
    configs = []
    for relative in ('config.php', 'admin/config.php'):
        path = SHOP / relative
        if not path.is_file() or not within(path.resolve(), SHOP):
            issues.append('Config missing or outside test shop: ' + relative)
            continue
        config = config_values(path)
        configs.append(config)
        safe = {'file': relative, 'database': config.get('DB_DATABASE'), 'paths': {}}
        for key, value in config.items():
            if key.startswith('DIR_'):
                resolved = Path(value).resolve() if value else None
                safe['paths'][key] = str(resolved) if resolved else 'UNRESOLVED'
                if not resolved or not (within(resolved, SHOP) or within(resolved, STORAGE)):
                    issues.append(relative + ': non-test or unresolved path ' + key)
                elif not Path(value).is_absolute() or not Path(value).is_dir():
                    issues.append(relative + ': directory missing or not absolute: ' + key)
                elif key in ('DIR_STORAGE','DIR_CACHE','DIR_LOGS','DIR_DOWNLOAD','DIR_UPLOAD','DIR_MODIFICATION','DIR_SESSION') and not within(resolved, STORAGE):
                    issues.append(relative + ': storage path outside test_storage: ' + key)
        if config.get('DB_DATABASE') != DATABASE:
            issues.append(relative + ': DB_DATABASE is not lavkasp_test')
        if not config.get('DIR_STORAGE') or Path(config['DIR_STORAGE']).resolve() != STORAGE:
            issues.append(relative + ': DIR_STORAGE does not resolve to test_storage')
        for key in ('HTTP_SERVER','HTTPS_SERVER'):
            if urlsplit(config.get(key) or '').hostname != HOSTNAME:
                issues.append(relative + ': unexpected hostname in ' + key)
        report.setdefault('configs', []).append(safe)
    if len(configs) != 2:
        issues.append('Both test configs are required.')
    elif any(configs[0].get(key) != configs[1].get(key) for key in ('DB_HOSTNAME','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_PREFIX','DB_PORT')):
        issues.append('Catalog and admin DB connection settings differ.')
    if not issues:
        config = dict(configs[0])
        config.setdefault('DB_PORT', '3306')
        if config.get('DB_DRIVER') != 'mysqli' or not re.fullmatch(r'[A-Za-z0-9_]*', config.get('DB_PREFIX') or ''):
            issues.append('Unsupported DB driver or prefix; requires manual read-only inspection.')
        elif any(config.get(key) is None for key in ('DB_HOSTNAME','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_PORT')):
            issues.append('DB connection constants could not be parsed statically.')
        elif not shutil.which('php'):
            issues.append('PHP CLI missing; database identity is unverified.')
        else:
            try:
                result = subprocess.run(
                    ['php','-d','display_errors=0','-d','log_errors=0','-r',PHP_PROBE],
                    input=json.dumps(config), universal_newlines=True,
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
                db = json.loads(result.stdout)
                report['db'] = db
                identity_ok = db.get('database') == DATABASE and (db.get('database_verified') is True or db.get('ok') is True)
                report['isolation_ok'] = identity_ok
                settings = db.get('settings') or {}
                if not db.get('ok'):
                    code = db.get('code', 'UNKNOWN_FAILURE')
                    stage = db.get('failed_stage', 'unknown')
                    errno = db.get('diagnostics', {}).get('sql_errno')
                    message = 'Read-only probe stopped: {0} (stage={1}'.format(code, stage)
                    if isinstance(errno, int): message += ', SQL errno={0}'.format(errno)
                    issues.append(message + ').')
                elif not identity_ok:
                    issues.append('Read-only database probe did not confirm lavkasp_test.')
                else:
                    if compatibility and db.get('legacy_mobile_code'):
                        issues.append('Legacy custom mobile code detected. Review/migrate it before installation.')
                    theme = settings.get('config_theme')
                    if compatibility and theme != 'unishop2' and settings.get('theme_default_directory') != 'unishop2':
                        issues.append('Active UniShop2 theme was not confirmed.')
                    if compatibility and not db.get('unishop_settings_found'):
                        issues.append('UniShop2 runtime settings source is unverified; review loader/schema diagnostics.')
                        report['runtime_source_refs'] = runtime_source_refs(db)
                    elif compatibility and not db.get('liveprice_enabled'):
                        issues.append('Native live-price calculation is disabled in the confirmed settings.')
                    if db.get('relevant_template_overrides') is None or db.get('relevant_template_overrides',0) > 0:
                        report['warnings'].append('Template editor overrides need inspection; do not reset them blindly.')
                for key in ('config_url','config_ssl'):
                    if settings.get(key) and urlsplit(settings[key]).hostname != HOSTNAME:
                        issues.append('Non-test store URL in DB: ' + key)
                        report['isolation_ok'] = False
            except (ValueError, subprocess.TimeoutExpired):
                issues.append('Database probe failed or timed out; no raw diagnostics displayed.')
    if configs:
        modification = configs[0].get('DIR_MODIFICATION')
        if compatibility and modification and within(Path(modification).resolve(), STORAGE):
            relative = 'catalog/controller/extension/module/uni_new_data.php'
            if (Path(modification) / relative).exists():
                issues.append('OCMOD shadows uni_new_data.php; determine a test-only refresh procedure before install.')
    report['scope'] = 'Config paths, DB identity and selected settings. External integrations and web PHP runtime still require inspection.'
    report['ok'] = not issues
    return report


def checked_manifest():
    manifest = json.loads((REPO/'docs/release-manifest.json').read_text())
    if manifest['target'] != HOSTNAME:
        raise ValueError('Unexpected release target')
    for entry in manifest['files']:
        relative = Path(entry['path'])
        if relative.is_absolute() or '..' in relative.parts or not str(relative).startswith(('catalog/view/theme/unishop2/js/','catalog/view/theme/unishop2/stylesheet/','catalog/controller/extension/module/')):
            raise ValueError('Unsafe manifest path')
        if relative.name in ('config.php', '.env') or relative.suffix not in ('.js','.css','.php'):
            raise ValueError('Disallowed payload')
        payload, target = REPO/'store'/relative, SHOP/relative
        if not within(payload.resolve(), REPO/'store') or sha(payload) != entry['after_sha256']:
            raise ValueError('Payload hash mismatch: ' + str(relative))
        if target.is_symlink() or not within(target.resolve(), SHOP) or not within(target.parent.resolve(), SHOP):
            raise ValueError('Target path redirected: ' + str(relative))
        actual = sha(target) if target.is_file() else None
        if actual != entry['before_sha256']:
            normalized = hashlib.sha256(target.read_bytes().replace(b'\r\n', b'\n')).hexdigest() if target.is_file() else None
            raise ReleaseCheckError(json.dumps({'error':'SOURCE_HASH_MISMATCH','path':str(relative),'expected_sha256':entry['before_sha256'],'actual_sha256':actual,'actual_lf_sha256':normalized}, sort_keys=True))
    return manifest


def install(manifest):
    if BACKUPS.resolve() != BACKUPS or within(BACKUPS, SHOP) or within(BACKUPS, STORAGE):
        raise ValueError('Unsafe backup directory')
    identifier = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
    backup = BACKUPS/identifier
    backup.mkdir(parents=True, mode=0o700)
    for entry in manifest['files']:
        target = SHOP/entry['path']
        if target.exists():
            saved = backup/entry['path']; saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target, saved)
    (backup/'manifest.json').write_text(json.dumps(manifest, indent=2)+'\n')
    changed = []
    try:
        for entry in manifest['files']:
            target = SHOP/entry['path']; payload = REPO/'store'/entry['path']
            mode = target.stat().st_mode & 0o777 if target.exists() else 0o644
            temp = target.with_name(target.name + '.lavka-install-tmp')
            if temp.exists() or temp.is_symlink():
                raise ValueError('Temporary file already exists')
            changed.append(entry)
            with temp.open('xb') as handle:
                handle.write(payload.read_bytes())
            temp.chmod(mode)
            os.replace(temp, target)
            if sha(target) != entry['after_sha256']:
                raise ValueError('Installed hash mismatch')
    except Exception:
        for entry in reversed(changed):
            target = SHOP/entry['path']; saved = backup/entry['path']
            if saved.exists(): shutil.copy2(saved, target)
            elif target.exists(): target.unlink()
            temp = target.with_name(target.name + '.lavka-install-tmp')
            if temp.exists(): temp.unlink()
        raise
    return {'installed': [entry['path'] for entry in manifest['files']], 'backup': str(backup), 'rollback_id': identifier}


def rollback(identifier):
    if not re.fullmatch(r'[0-9TZ.]+', identifier):
        raise ValueError('Invalid backup identifier')
    backup = BACKUPS/identifier
    if BACKUPS.resolve() != BACKUPS or not within(backup.resolve(), BACKUPS):
        raise ValueError('Unsafe backup path')
    manifest = json.loads((backup/'manifest.json').read_text())
    if manifest.get('target') != HOSTNAME:
        raise ValueError('Unexpected rollback target')
    for entry in manifest['files']:
        path = Path(entry['path']); target = SHOP/path; saved = backup/path
        if path.is_absolute() or '..' in path.parts or target.is_symlink() or not within(target.resolve(), SHOP):
            raise ValueError('Unsafe rollback path')
        if sha(target) != entry['after_sha256']:
            raise ValueError('Installed file changed; manual rollback required: ' + str(path))
        if entry['before_sha256'] and (not within(saved.resolve(), backup) or sha(saved) != entry['before_sha256']):
            raise ValueError('Backup hash mismatch')
    for entry in reversed(manifest['files']):
        target = SHOP/entry['path']; saved = backup/entry['path']
        if entry['before_sha256']: shutil.copy2(saved, target)
        else: target.unlink()
    return {'rolled_back': identifier}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--audit', action='store_true')
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--rollback', metavar='BACKUP_ID')
    args = parser.parse_args()
    try:
        report = preflight(compatibility=not bool(args.rollback))
        # Python 3.6 hosting accounts may use an ASCII terminal locale.
        print(json.dumps(report, ensure_ascii=True, indent=2))
        if not report['ok']: return 2
        if args.audit: return 0
        if args.rollback:
            result = rollback(args.rollback)
        else:
            manifest = checked_manifest()
            result = install(manifest) if args.apply else {'check':'passed','files':[entry['path'] for entry in manifest['files']]}
        print(json.dumps(result, ensure_ascii=True, indent=2))
        return 0
    except ReleaseCheckError as error:
        print(str(error), file=sys.stderr)
        return 2
    except (OSError, ValueError, KeyError):
        # Config parsing, subprocess stderr or DB messages must never leak secrets.
        print('Stopped. Files/configs differ or a prerequisite failed. No raw credential diagnostics are displayed.', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
