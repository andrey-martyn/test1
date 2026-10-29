#!/usr/bin/env python3
"""Deploy mobile product detail assets only to the verified test shop; no DB writes."""
import argparse
import json
import sys
import subprocess
import product_tags as checked
import test_store as store


def template_check(report,manifest):
    """No override, or one byte-reviewed effective source, is supported without DB edits."""
    db=report.get('db') or {}
    count=db.get('product_template_overrides')
    result={'ok':False,'count':count,'scope':db.get('product_template_override_scope'),
            'records':db.get('product_template_override_details',[]),
            'diagnostics':db.get('product_template_override_diagnostics')}
    if type(count) is int and count==0:
        result.update({'ok':True,'status':'no_active_override'});return result
    records=result['records']
    if type(count) is not int or count<0:
        result['status']='count_unverified';return result
    if count!=1 or not isinstance(records,list) or len(records)!=1 or not isinstance(records[0],dict):
        result['status']='active_source_unverified';return result
    expected=next((entry['allowed_sha256'] for entry in manifest.get('required_sources',[]) if entry['path']=='catalog/view/theme/unishop2/template/product/product.twig'),[])
    record=records[0]
    scope=result['scope'] or {}
    for reviewed in manifest.get('reviewed_overrides',[]):
        if all(scope.get(key)==reviewed.get(key) for key in ('store_id','theme','route')):
            expected=expected+reviewed.get('allowed_sha256',[])
    matched=any(record.get(key) in expected for key in ('decoded_sha256','decoded_lf_sha256'))
    if scope.get('store_id')==0 and scope.get('theme')=='unishop2' and scope.get('route')=='product/product' and record.get('source_bounded') is True and matched:
        result.update({'ok':True,'status':'active_override_matches_reviewed_source'})
    else:result['status']='active_override_differs'
    return result


def diagnostic_report(report):
    # Source-only metadata: no raw settings, template code or connection details.
    manifest=json.loads((store.REPO/'docs/product-page-release.json').read_text())
    files=[]
    for entry in manifest.get('required_sources',[]):
        path=store.SHOP/entry['path']
        valid=path.is_file() and not path.is_symlink() and store.within(path.resolve(),store.SHOP)
        actual=store.sha(path) if valid else None
        files.append({'path':entry['path'],'sha256':actual,'reviewed_match':actual in entry['allowed_sha256']})
    return {'target':store.HOSTNAME,'isolation_ok':report.get('isolation_ok'),
            'database_verified':report.get('db',{}).get('database_verified'),
            'template_check':template_check(report,manifest),'source_files':files,
            'shop_writes':False,'database_writes':False}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    action=parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true');action.add_argument('--apply',action='store_true')
    action.add_argument('--diagnose',action='store_true',help='Read only active template metadata and source hashes')
    action.add_argument('--export-template',action='store_true',help='Export only the active template outside webroot for source review')
    action.add_argument('--rollback',metavar='FILE_BACKUP_ID')
    args=parser.parse_args()
    try:
        report=store.preflight(compatibility=not bool(args.rollback))
        if not report['ok'] or report.get('db',{}).get('runtime_store_id')!=0:
            print(json.dumps(report,ensure_ascii=True,indent=2));return 2
        if args.diagnose or args.export_template:
            diagnostic=diagnostic_report(report)
            if args.export_template:
                from product_template import export_template
                diagnostic['export']=export_template(diagnostic)
            print(json.dumps(diagnostic,ensure_ascii=True,indent=2));return 0
        if args.rollback:
            result=store.rollback(args.rollback)
        else:
            manifest,current=checked.release('docs/product-page-release.json')
            if manifest.get('feature')!='mobile-product': raise ValueError('Unexpected feature')
            templates=template_check(report,manifest)
            if not templates['ok']:
                raise store.ReleaseCheckError(json.dumps({'error':'PRODUCT_TEMPLATE_OVERRIDES_UNVERIFIED','template_check':templates,'action':'Run --diagnose; inspect active test source without resetting it.'}))
            # Reviewed gallery and native cart template is inspected, never overwritten.
            for entry in manifest.get('required_sources',[]):
                path=store.SHOP/entry['path']
                if path.is_symlink() or not store.within(path.resolve(),store.SHOP) or not path.is_file() or store.sha(path) not in entry['allowed_sha256']:
                    raise store.ReleaseCheckError(json.dumps({'error':'PRODUCT_SOURCE_MISMATCH','path':entry['path']}))
            refresh=[entry['path'] for entry in manifest['files'] if (store.STORAGE/'modification'/entry['path']).exists()]
            result={'check':'passed','target':store.HOSTNAME,'files_to_update':[entry['path'] for entry in manifest['files']],
                    'already_current':current,'ocmod_refresh_required_for':refresh,'database_writes':False,'template_check':templates,
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
    except (OSError,ValueError,KeyError,TypeError,subprocess.TimeoutExpired):
        print('Stopped. Mobile-product test-store prerequisites differ; no connection details displayed.',file=sys.stderr);return 2


if __name__=='__main__':sys.exit(main())
