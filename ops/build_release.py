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
manifest = {
    'target': 'test.lavka-speciy.com',
    'database': 'lavkasp_test',
    'source_commit': subprocess.check_output(['git','rev-parse','883aa91^{commit}'],cwd=ROOT,text=True).strip(),
    'source_archive_sha256': baseline['archive_sha256'],
    'files': [{'path':name, 'before_sha256':before.get(name), 'after_sha256':hashlib.sha256((ROOT/'store'/name).read_bytes()).hexdigest()} for name in FILES],
    'deleted_files': [],
    'database_changes': [],
}
(ROOT/'docs/release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
patch = subprocess.check_output(['git','diff','--no-ext-diff','--relative=store','--binary',manifest['source_commit'],'--','store'],cwd=ROOT)
(ROOT/'docs/mobile-category.patch').write_bytes(patch)
print('Updated manifest and patch for',len(FILES),'files.')
