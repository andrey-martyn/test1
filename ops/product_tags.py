#!/usr/bin/env python3
"""Check/apply product tags only on the isolated test shop; no production mode."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys

import test_store as store


def release():
    manifest=json.loads((store.REPO/'docs/product-tags-release.json').read_text())
    if (manifest['target'],manifest['database'],manifest['store_id'])!=(store.HOSTNAME,store.DATABASE,0): raise ValueError('Unexpected target')
    changed=[]; current=[]
    for entry in manifest['files']:
        relative=Path(entry['path'])
        if relative.is_absolute() or '..' in relative.parts or relative.suffix not in ('.php','.js','.css','.twig') or not str(relative).startswith(('catalog/','admin/','system/library/')) or relative.name=='config.php': raise ValueError('Unsafe payload path')
        payload=store.REPO/'store'/relative; target=store.SHOP/relative
        if not store.within(payload.resolve(),store.REPO/'store') or store.sha(payload)!=entry['after_sha256']: raise ValueError('Payload differs')
        if target.is_symlink() or not store.within(target.resolve(),store.SHOP): raise ValueError('Redirected target')
        actual=store.sha(target) if target.is_file() else None
        if actual==entry['after_sha256']: current.append(entry['path']); continue
        if entry['before_sha256'] is None:
            if target.exists() and actual not in entry['allowed_before_sha256']: raise ValueError('New-file collision')
        elif actual not in entry['allowed_before_sha256']:
            raise store.ReleaseCheckError(json.dumps({'error':'SOURCE_HASH_MISMATCH','path':str(relative),'actual_sha256':actual,'allowed_before_sha256':entry['allowed_before_sha256']}))
        entry['before_sha256']=actual
        changed.append(entry)
    result=dict(manifest); result['files']=changed
    return result,current


def database(action):
    config=store.config_values(store.SHOP/'config.php'); config.setdefault('DB_PORT','3306')
    config.update({'action':action,'store_id':0})
    result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(store.REPO/'ops/product-tags-db.php')],
                          input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=25)
    reply=json.loads(result.stdout)
    if result.returncode or reply.get('ok') is not True:
        code=reply.get('code','TAG_SCHEMA_OPERATION_FAILED')
        if not isinstance(code,str) or not code.replace('_','').isalpha() or not code.isupper(): code='TAG_SCHEMA_OPERATION_FAILED'
        raise store.ReleaseCheckError(json.dumps({'error':'TAG_DB_CHECK_FAILED','code':code}))
    return reply


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    action=parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true'); action.add_argument('--apply',action='store_true')
    action.add_argument('--rollback',metavar='FILE_BACKUP_ID')
    args=parser.parse_args()
    try:
        report=store.preflight(compatibility=not bool(args.rollback))
        if not report['ok'] or report.get('db',{}).get('runtime_store_id')!=0:
            print(json.dumps(report,ensure_ascii=True,indent=2)); return 2
        if args.rollback:
            result=store.rollback(args.rollback)
            result['tag_tables']='Retained so new label assignments are not lost; no existing DB rows restored.'
        else:
            manifest,current=release()
            db=database('check')
            refresh=[]
            for entry in manifest['files']:
                if (store.STORAGE/'modification'/entry['path']).exists(): refresh.append(entry['path'])
            result={'check':'passed','files_to_update':[e['path'] for e in manifest['files']],'already_current':current,
                    'schema':db,'ocmod_refresh_required_for':refresh,'page_cache_action':'One test-host HTML cache refresh after first installation; label fragments then refresh via POST.'}
            if args.apply:
                # Create module directories only after the complete source/DB preflight.
                result['schema']=database('initialize')
                for entry in manifest['files']:
                    parent=(store.SHOP/entry['path']).parent
                    if not store.within(parent.resolve(),store.SHOP): raise ValueError('Unsafe parent directory')
                    parent.mkdir(parents=True,exist_ok=True)
                if manifest['files']: result.update(store.install(manifest))
                else: result['installed']='already_current'
        print(json.dumps(result,ensure_ascii=True,indent=2)); return 0
    except store.ReleaseCheckError as error:
        print(str(error),file=sys.stderr); return 2
    except (OSError,ValueError,KeyError,TypeError,subprocess.TimeoutExpired):
        print('Stopped. Product-tag test-store guards failed; no raw settings or connection diagnostics displayed.',file=sys.stderr); return 2


if __name__=='__main__': sys.exit(main())
