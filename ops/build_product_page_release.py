#!/usr/bin/env python3
"""Build the three-file mobile product release against the approved category baseline."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='e3e565d'
FILES=['catalog/view/theme/unishop2/stylesheet/lavka-mobile-product.css',
       'catalog/view/theme/unishop2/js/lavka-mobile-product.js',
       'catalog/controller/product/product.php']
sha=lambda raw:hashlib.sha256(raw).hexdigest()
entries=[]
for path in FILES:
    found=subprocess.run(['git','show',BASE+':store/'+path],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    before=found.stdout if found.returncode==0 else None
    variants=[] if before is None else sorted({sha(before),sha(before.replace(b'\r\n',b'\n')),sha(before.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'))})
    entries.append({'path':path,'before_sha256':sha(before) if before is not None else None,'allowed_before_sha256':variants,'after_sha256':sha((ROOT/'store'/path).read_bytes())})
# Exact reviewed source hashes are recorded separately; templates are not payload.
source=json.loads((ROOT/'docs/product-page-source.json').read_text())
manifest={'feature':'mobile-product','target':'test.lavka-speciy.com','database':'lavkasp_test','store_id':0,
          'source_commit':subprocess.check_output(['git','rev-parse',BASE],cwd=ROOT,universal_newlines=True).strip(),
          'files':entries,'required_sources':source['required_sources'],'reviewed_overrides':source.get('reviewed_overrides',[]),'deleted_files':[],'database_changes':[]}
(ROOT/'docs/product-page-release.json').write_text(json.dumps(manifest,indent=2)+'\n')
(ROOT/'docs/product-page.patch').write_bytes(subprocess.check_output(['git','diff','--no-ext-diff','--relative=store',BASE,'--']+['store/'+p for p in FILES],cwd=ROOT))
print('Mobile product manifest:',len(entries),'files; no DB changes.')
