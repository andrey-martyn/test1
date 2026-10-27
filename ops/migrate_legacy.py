#!/usr/bin/env python3
"""Remove one reviewed legacy CSS block from this test store; preserve every other setting.

--check is read-only. --apply backs up the single JSON field outside the web root,
then uses a compare-and-swap update. --rollback restores that field only.
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

import test_store as store

ROOT = Path(__file__).resolve().parent


class MigrationGuardError(ValueError):
    """A deliberately safe diagnostic; never includes settings/credentials."""


def digest(value):
    return hashlib.sha256(value.encode('utf-8')).hexdigest()


def read_plan():
    plan = json.loads((ROOT/'migrations/legacy-grid.json').read_text())
    if (plan['target'],plan['database'],plan['field'],plan['source']) != (
            store.HOSTNAME,store.DATABASE,'user_css',{'table':'oc_uni_setting','column':'data','store_id':0,'format':'json'}):
        raise MigrationGuardError('Unexpected migration scope')
    for key in ('before','after'):
        name = plan[key+'_file']
        if Path(name).name != name or not name.endswith('.css'):
            raise MigrationGuardError('Unsafe CSS reference')
        plan[key+'_css'] = (ROOT/'migrations'/name).read_bytes().decode('utf-8')
    if digest(plan['before_css']) != plan['before_decoded_sha256'] or digest(plan['after_css']) != plan['after_stored_sha256']:
        raise MigrationGuardError('Reviewed CSS file changed')
    if not plan['before_css'].startswith(plan['after_css']) or 'data-lavka-mobile-grid' not in plan['before_css'][len(plan['after_css']):]:
        raise MigrationGuardError('Unexpected CSS removal')
    return plan


def guarded_report(rollback=False):
    report = store.preflight(compatibility=not rollback)
    blockers = [issue for issue in report['issues'] if issue != store.LEGACY_ISSUE]
    source = (report.get('db') or {}).get('unishop_settings_source')
    if not report.get('isolation_ok') or blockers or source != read_plan()['source']:
        print(json.dumps(report,ensure_ascii=True,indent=2))
        raise MigrationGuardError('Test installation/source is unverified')
    return report


def bridge(action, **values):
    config = store.config_values(store.SHOP/'config.php')
    config.setdefault('DB_PORT','3306')
    config.update({'action':action,'store_id':0})
    config.update(values)
    result = subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(ROOT/'migrations/user-css.php')],
                            input=json.dumps(config),universal_newlines=True,
                            stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20)
    response = json.loads(result.stdout)
    if result.returncode or response.get('ok') is not True:
        code = response.get('code','UNKNOWN_FAILURE')
        if not isinstance(code,str) or not re.fullmatch(r'[A-Z0-9_]{1,80}',code): code = 'UNKNOWN_FAILURE'
        raise MigrationGuardError('Private CSS database operation failed: '+code)
    return response


def css_span(raw):
    """Locate a TOP-LEVEL JSON string, preserving all other bytes, objects and numbers."""
    decoder = json.JSONDecoder()
    def whitespace(pos):
        while pos < len(raw) and raw[pos] in ' \r\n\t': pos += 1
        return pos
    pos = whitespace(0)
    if raw[pos:pos+1] != '{': raise MigrationGuardError('JSON object required')
    pos = whitespace(pos+1)
    found = []
    while raw[pos:pos+1] != '}':
        key, pos = decoder.raw_decode(raw,pos)
        if not isinstance(key,str): raise MigrationGuardError('Invalid JSON key')
        pos = whitespace(pos)
        if raw[pos:pos+1] != ':': raise MigrationGuardError('Invalid JSON delimiter')
        start = whitespace(pos+1)
        value, end = decoder.raw_decode(raw,start)
        if key == 'user_css': found.append((start,end,value))
        pos = whitespace(end)
        if raw[pos:pos+1] == '}': break
        if raw[pos:pos+1] != ',': raise MigrationGuardError('Invalid JSON delimiter')
        pos = whitespace(pos+1)
    if raw[pos:pos+1] != '}' or whitespace(pos+1) != len(raw) or len(found) != 1 or not isinstance(found[0][2],str):
        raise MigrationGuardError('Unique CSS string required')
    return found[0]


def replace_literal(raw, literal):
    value = json.loads(literal)
    if not isinstance(value,str): raise MigrationGuardError('CSS replacement must be a string')
    start,end,_ = css_span(raw)
    return raw[:start]+literal+raw[end:]


def migration_state(plan):
    response = bridge('read')
    raw = response['row']
    start,end,css = css_span(raw)
    values = json.loads(raw)
    if digest(values.get('user_js','')) != plan['expected_js_stored_sha256']:
        raise MigrationGuardError('Custom JavaScript differs from the reviewed export')
    if digest(css) == plan['after_stored_sha256'] and css == plan['after_css']:
        return {'raw':raw,'status':'already_clean'}
    if digest(css) != plan['expected_css_stored_sha256'] or response['decoded_css'] != plan['before_css']:
        raise MigrationGuardError('Custom CSS differs from the reviewed export')
    if not css.startswith(plan['after_css']):
        raise MigrationGuardError('Stored CSS prefix differs')
    return {'raw':raw,'status':'ready','before_literal':raw[start:end],
            'after_literal':json.dumps(plan['after_css'],ensure_ascii=True)}


def cache_targets(plan):
    loader = 'catalog/model/extension/module/uni_settings.php'
    original = store.SHOP/loader
    if not original.is_file() or not store.within(original.resolve(),store.SHOP) or store.sha(original) != plan['settings_loader_sha256']:
        raise MigrationGuardError('Settings loader differs from reviewed metadata')
    modified = store.STORAGE/'modification'/loader
    if modified.exists() and (not store.within(modified.resolve(),store.STORAGE) or store.sha(modified) != plan['settings_loader_sha256']):
        raise MigrationGuardError('Unknown modified settings loader')
    cache = store.STORAGE/'cache'
    if cache.resolve() != cache or not cache.is_dir(): raise MigrationGuardError('Unsafe test cache')
    targets = [('storage',p) for p in sorted(cache.glob('cache.unishop.settings.0.*')) if re.fullmatch(r'cache\.unishop\.settings\.0\.[0-9]+',p.name)]
    generated = store.SHOP/'catalog/view/theme/unishop2/stylesheet/generated-user-style.0.css'
    if generated.exists() or generated.is_symlink():
        if not generated.is_file() or generated.is_symlink() or not store.within(generated.resolve(),store.SHOP):
            raise MigrationGuardError('Unsafe generated CSS path')
        normalized = generated.read_bytes().replace(b'\r\n',b'\n')
        if normalized not in [plan[k+'_css'].encode('utf-8').replace(b'\r\n',b'\n') for k in ('before','after')]:
            raise MigrationGuardError('Generated user CSS differs; refusing to delete it')
        targets.append(('shop',generated))
    for root,path in targets:
        if path.is_symlink() or not path.is_file() or not store.within(path.resolve(),store.SHOP if root == 'shop' else store.STORAGE):
            raise MigrationGuardError('Unsafe cache entry')
    return targets


def private_json(path, value):
    temporary = path.with_name(path.name+'.tmp')
    with os.fdopen(os.open(str(temporary),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600),'w') as handle:
        json.dump(value,handle,ensure_ascii=True,indent=2)
        handle.write('\n'); handle.flush(); os.fsync(handle.fileno())
    os.replace(temporary,path)


def purge_cache(plan, saved):
    targets = cache_targets(plan)
    snapshots = []
    for root,path in targets:
        base = store.SHOP if root == 'shop' else store.STORAGE
        destination = saved/root/path.relative_to(base)
        destination.parent.mkdir(parents=True,exist_ok=True)
        before = store.sha(path)
        shutil.copy2(path,destination); destination.chmod(0o600)
        if store.sha(destination) != before: raise MigrationGuardError('Cache backup hash mismatch')
        snapshots.append((path,before))
    for path,before in snapshots:
        if path.is_symlink() or store.sha(path) != before: raise MigrationGuardError('Cache changed during invalidation')
        path.unlink()
    return len(snapshots)


def apply(plan, state):
    if state['status'] == 'already_clean': return {'migration':'already_clean','database_writes':False}
    if store.BACKUPS.resolve() != store.BACKUPS or store.within(store.BACKUPS,store.SHOP) or store.within(store.BACKUPS,store.STORAGE):
        raise MigrationGuardError('Unsafe migration backup path')
    identifier = 'legacy-grid-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
    saved = store.BACKUPS/identifier
    saved.mkdir(parents=True,mode=0o700)
    record = {'target':store.HOSTNAME,'database':store.DATABASE,'source':plan['source'],'field':'user_css',
              'before_literal':state['before_literal'],'after_literal':state['after_literal'],'status':'prepared'}
    for which in ('before','after'): record[which+'_literal_sha256'] = digest(record[which+'_literal'])
    private_json(saved/'migration.json',record)
    try:
        after = replace_literal(state['raw'],state['after_literal'])
        bridge('replace',before=state['raw'],after=after)
        count = purge_cache(plan,saved/'cache-before-apply')
        current = bridge('read')['row']
        if css_span(current)[2] != plan['after_css']: raise MigrationGuardError('CSS verification failed')
        record['status'] = 'applied'; private_json(saved/'migration.json',record)
    except Exception:
        try:
            current = bridge('read')['row']
            css = css_span(current)[2]
            if css == json.loads(record['after_literal']):
                bridge('replace',before=current,after=replace_literal(current,record['before_literal']))
            elif css != json.loads(record['before_literal']):
                raise MigrationGuardError('CSS changed concurrently; manual recovery required')
            purge_cache(plan,saved/'cache-before-recovery')
            record['status'] = 'recovered'; private_json(saved/'migration.json',record)
        except Exception:
            print(json.dumps({'error':'MIGRATION_RECOVERY_REQUIRED','rollback_id':identifier},ensure_ascii=True),file=sys.stderr)
            raise MigrationGuardError('Migration recovery requires inspection')
        print(json.dumps({'error':'MIGRATION_FAILED_AND_RECOVERED','backup':str(saved)},ensure_ascii=True),file=sys.stderr)
        raise MigrationGuardError('Migration stopped and CSS restored')
    return {'migration':'applied','field':'user_css','user_js_changed':False,'backup':str(saved),
            'rollback_id':identifier,'test_cache_files_invalidated':count}


def rollback(plan, identifier):
    if not re.fullmatch(r'legacy-grid-[0-9TZ.]+',identifier) or store.BACKUPS.resolve() != store.BACKUPS:
        raise MigrationGuardError('Unsafe migration rollback identifier')
    saved = store.BACKUPS/identifier
    if not store.within(saved.resolve(),store.BACKUPS): raise MigrationGuardError('Unsafe migration backup')
    metadata = saved/'migration.json'
    if metadata.is_symlink() or not store.within(metadata.resolve(),saved): raise MigrationGuardError('Unsafe migration metadata')
    record = json.loads(metadata.read_text())
    if (record['target'],record['database'],record['source'],record['field']) != (store.HOSTNAME,store.DATABASE,plan['source'],'user_css'):
        raise MigrationGuardError('Unexpected migration backup scope')
    for which in ('before','after'):
        if digest(record[which+'_literal']) != record[which+'_literal_sha256']: raise MigrationGuardError('Migration backup hash mismatch')
    if digest(json.loads(record['before_literal'])) != plan['expected_css_stored_sha256'] or digest(json.loads(record['after_literal'])) != plan['after_stored_sha256']:
        raise MigrationGuardError('Backup CSS is outside the reviewed migration')
    current = bridge('read')['row']
    css = css_span(current)[2]
    if css not in (json.loads(record['before_literal']),json.loads(record['after_literal'])):
        raise MigrationGuardError('Custom CSS changed; manual merge required')
    cache_targets(plan)
    if css != json.loads(record['before_literal']):
        bridge('replace',before=current,after=replace_literal(current,record['before_literal']))
    count = purge_cache(plan,saved/('cache-before-rollback-'+datetime.datetime.now(datetime.timezone.utc).strftime('%H%M%S.%f')))
    record['status'] = 'rolled_back'; private_json(saved/'migration.json',record)
    return {'migration':'rolled_back','rollback_id':identifier,'test_cache_files_invalidated':count}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true')
    action.add_argument('--apply',action='store_true')
    action.add_argument('--rollback',metavar='MIGRATION_ID')
    args = parser.parse_args()
    try:
        guarded_report(rollback=bool(args.rollback))
        plan = read_plan()
        if args.rollback:
            result = rollback(plan,args.rollback)
        else:
            # Check ALL release files before changing the database, including known LF variants.
            manifest = store.checked_manifest()
            state = migration_state(plan)
            targets = cache_targets(plan)
            result = apply(plan,state) if args.apply else {'check':'passed','migration':state['status'],
                'field':'user_css','user_js_changed':False,'database_writes':False,
                'release_files_verified':len(manifest['files']),'test_cache_files_to_invalidate':len(targets)}
        print(json.dumps(result,ensure_ascii=True,indent=2))
        return 0
    except store.ReleaseCheckError as error:
        print(str(error),file=sys.stderr)
        return 2
    except MigrationGuardError as error:
        print(json.dumps({'error':'MIGRATION_GUARD_FAILED','reason':str(error)},ensure_ascii=True),file=sys.stderr)
        return 2
    except (OSError, ValueError, KeyError, TypeError, subprocess.TimeoutExpired):
        print('Stopped. Test-store migration guards failed. No raw settings or credential diagnostics are displayed.',file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
