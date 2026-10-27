#!/usr/bin/env python3
"""Rebuild release hashes and a contextual patch from the recorded archive baseline."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    'catalog/controller/extension/module/uni_new_data.php',
    'catalog/view/theme/unishop2/js/common.js',
    'catalog/view/theme/unishop2/js/live-price.js',
    'catalog/view/theme/unishop2/js/showmore-ajaxpagination.js',
    'catalog/view/theme/unishop2/js/lavka-mobile-cards.js',
    'catalog/view/theme/unishop2/stylesheet/lavka-mobile-cards.css',
]
baseline = json.loads((ROOT/'docs/source-baseline.json').read_text())
before = {entry['path']: entry['sha256'] for entry in baseline['files']}
source_commit = subprocess.check_output(['git','rev-parse','883aa91^{commit}'],cwd=ROOT,universal_newlines=True).strip()
before_lf = {name: hashlib.sha256(subprocess.check_output(['git','show',source_commit+':store/'+name],cwd=ROOT).replace(b'\r\n',b'\n')).hexdigest() for name in FILES if name in before}
manifest = {
    'target': 'test.lavka-speciy.com',
    'database': 'lavkasp_test',
    'source_commit': source_commit,
    'source_archive_sha256': baseline['archive_sha256'],
    'files': [{'path':name, 'before_sha256':before.get(name), 'before_lf_sha256':before_lf.get(name), 'after_sha256':hashlib.sha256((ROOT/'store'/name).read_bytes()).hexdigest()} for name in FILES],
    'deleted_files': [],
    'database_changes': [{'id':'remove-reviewed-legacy-category-grid','plan':'ops/migrations/legacy-grid.json','scope':'Only user_css in the confirmed test-store UniShop2 JSON; preserves all other bytes and user_js.'}],
}
(ROOT/'docs/release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
patch = subprocess.check_output(['git','diff','--no-ext-diff','--relative=store','--binary',manifest['source_commit'],'--','store'],cwd=ROOT)
(ROOT/'docs/mobile-category.patch').write_bytes(patch)
print('Updated manifest and patch for',len(FILES),'files.')

# Small update for the exact mobile-card version already installed on the test host.
update_source = subprocess.check_output(['git','rev-parse','f006edb^{commit}'],cwd=ROOT,universal_newlines=True).strip()
update_files = [FILES[0],FILES[-1]]
entries = []
for name in update_files:
    original = subprocess.check_output(['git','show',update_source+':store/'+name],cwd=ROOT)
    entries.append({'path':name,'before_sha256':hashlib.sha256(original).hexdigest(),
                    'before_lf_sha256':hashlib.sha256(original.replace(b'\r\n',b'\n')).hexdigest(),
                    'after_sha256':hashlib.sha256((ROOT/'store'/name).read_bytes()).hexdigest()})
update = {'target':'test.lavka-speciy.com','database':'lavkasp_test','source_commit':update_source,
          'files':entries,'deleted_files':[],'database_changes':[]}
(ROOT/'docs/title-weight-update.json').write_text(json.dumps(update,indent=2)+'\n')
update_patch = subprocess.check_output(['git','diff','--no-ext-diff','--relative=store',update_source,'--']+['store/'+name for name in update_files],cwd=ROOT)
(ROOT/'docs/title-weight-update.patch').write_bytes(update_patch)
print('Updated two-file title-weight update manifest and patch.')
