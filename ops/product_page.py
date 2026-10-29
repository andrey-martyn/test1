#!/usr/bin/env python3
"""Deploy mobile product detail assets only to the verified test shop; no DB writes."""
import argparse
import json
import sys
import product_tags as checked
import test_store as store


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    action=parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true');action.add_argument('--apply',action='store_true')
    action.add_argument('--rollback',metavar='FILE_BACKUP_ID')
    args=parser.parse_args()
    try:
        report=store.preflight(compatibility=not bool(args.rollback))
        if not report['ok'] or report.get('db',{}).get('runtime_store_id')!=0:
            print(json.dumps(report,ensure_ascii=True,indent=2));return 2
        if args.rollback:
            result=store.rollback(args.rollback)
        else:
            if report.get('db',{}).get('product_template_overrides')!=0:
                raise store.ReleaseCheckError(json.dumps({'error':'PRODUCT_TEMPLATE_OVERRIDES_UNVERIFIED','action':'Inspect test product template overrides; do not reset them.'}))
            manifest,current=checked.release('docs/product-page-release.json')
            if manifest.get('feature')!='mobile-product': raise ValueError('Unexpected feature')
            # Reviewed gallery and native cart template is inspected, never overwritten.
            for entry in manifest.get('required_sources',[]):
                path=store.SHOP/entry['path']
                if path.is_symlink() or not store.within(path.resolve(),store.SHOP) or not path.is_file() or store.sha(path) not in entry['allowed_sha256']:
                    raise store.ReleaseCheckError(json.dumps({'error':'PRODUCT_SOURCE_MISMATCH','path':entry['path']}))
            refresh=[entry['path'] for entry in manifest['files'] if (store.STORAGE/'modification'/entry['path']).exists()]
            result={'check':'passed','target':store.HOSTNAME,'files_to_update':[entry['path'] for entry in manifest['files']],
                    'already_current':current,'ocmod_refresh_required_for':refresh,'database_writes':False,
                    'page_cache_action':'Refresh only test-host HTML cache, then hard reload the product page.'}
            if args.apply:
                for entry in manifest['files']:
                    parent=(store.SHOP/entry['path']).parent
                    if not store.within(parent.resolve(),store.SHOP): raise ValueError('Redirected directory')
                    parent.mkdir(parents=True,exist_ok=True)
                if manifest['files']:result.update(store.install(manifest))
                else:result['installed']='already_current'
        print(json.dumps(result,ensure_ascii=True,indent=2));return 0
    except store.ReleaseCheckError as error:
        print(str(error),file=sys.stderr);return 2
    except (OSError,ValueError,KeyError,TypeError):
        print('Stopped. Mobile-product test-store prerequisites differ; no connection details displayed.',file=sys.stderr);return 2


if __name__=='__main__':sys.exit(main())
