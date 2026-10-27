#!/usr/bin/env python3
"""Read-only comparison of six shop files. Does not read configs or connect to DB."""
import argparse
import hashlib
import json
from pathlib import Path
import sys

PACKAGE = Path(__file__).resolve().parents[1]
FILES = {
    'catalog/controller/extension/module/uni_new_data.php',
    'catalog/view/theme/unishop2/js/common.js',
    'catalog/view/theme/unishop2/js/live-price.js',
    'catalog/view/theme/unishop2/js/showmore-ajaxpagination.js',
    'catalog/view/theme/unishop2/js/lavka-mobile-cards.js',
    'catalog/view/theme/unishop2/stylesheet/lavka-mobile-cards.css',
}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def within(path, root):
    try:
        path.relative_to(root)
        return True
    except ValueError:
        return False


def inspect(shop):
    manifest = json.loads((PACKAGE/'MANIFEST.json').read_text())
    entries = manifest['files']
    if manifest.get('manifest_version') != 1 or len(entries) != 6 or {entry['path'] for entry in entries} != FILES:
        raise ValueError('Unexpected manifest')
    rows = []
    for entry in entries:
        path = Path(entry['path'])
        if path.is_absolute() or '..' in path.parts or path.suffix not in ('.php','.js','.css'):
            raise ValueError('Unsafe file path')
        payload = PACKAGE/'files'/path
        if not within(payload.resolve(),PACKAGE/'files') or digest(payload) != entry['after_sha256']:
            raise ValueError('Package payload differs from manifest')
        if entry['before_sha256']:
            baseline = PACKAGE/'baseline'/path
            if not within(baseline.resolve(),PACKAGE/'baseline') or digest(baseline) != entry['before_sha256']:
                raise ValueError('Package baseline differs from manifest')
        target = shop/path
        row = {'path':entry['path'],'action':entry['action'],'status':None}
        if target.is_symlink() or not within(target.resolve(),shop):
            row['status'] = 'path_redirected_review_required'
        elif not target.exists():
            row['status'] = 'ready_to_add' if entry['action'] == 'add' else 'missing_source'
        elif not target.is_file():
            row['status'] = 'not_a_regular_file'
        else:
            actual = digest(target)
            row['actual_sha256'] = actual
            if actual in (entry['after_sha256'],entry['after_lf_sha256']):
                row['status'] = 'already_current'
            elif entry['action'] == 'modify' and actual in (entry['before_sha256'],entry['before_lf_sha256']):
                row['status'] = 'baseline_matches'
            else:
                row['status'] = 'manual_merge_required'
        rows.append(row)
    states = [row['status'] for row in rows]
    if all(state in ('baseline_matches','ready_to_add') for state in states):
        outcome = 'baseline_matches'
    elif all(state == 'already_current' for state in states):
        outcome = 'files_already_match_release'
    else:
        outcome = 'manual_merge_required'
    return {'result':outcome,'scope':'Only six source files; DB, OCMOD and runtime are not checked.',
            'writes':False,'network_requests':0,'files':rows}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('shop_root',help='Actual shop root or a current review copy')
    args = parser.parse_args()
    try:
        shop = Path(args.shop_root).expanduser().resolve()
        if not shop.is_dir(): raise ValueError('Shop root is not a directory')
        result = inspect(shop)
        print(json.dumps(result,ensure_ascii=True,indent=2))
        return 0 if result['result'] != 'manual_merge_required' else 2
    except (OSError,ValueError,KeyError,TypeError):
        print(json.dumps({'result':'invalid_package','writes':False,'message':'Check package integrity, paths and shop root.'}))
        return 2


if __name__ == '__main__':
    sys.exit(main())
