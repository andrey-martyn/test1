#!/usr/bin/env python3
"""Build a source-only release for product tags from its recorded import baseline."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='647cb88'
PREVIOUS='9cd448d'
COMPATIBLE=['38e56b3','acbb270','b2be391','6487db4',PREVIOUS]
FILES=[
    'system/library/lavka_product_tags.php',
    'catalog/model/extension/module/lavka_product_tags.php',
    'admin/model/extension/module/lavka_product_tags.php',
    'catalog/controller/extension/module/lavka_product_tags.php',
    'admin/view/template/extension/module/lavka_tag_controls.twig',
    'admin/view/template/extension/module/lavka_product_tags.twig',
    'admin/view/javascript/lavka-product-tags.js',
    'admin/view/stylesheet/lavka-product-tags.css',
    'admin/language/ru-ru/extension/module/lavka_product_tags.php',
    'admin/language/uk-ua/extension/module/lavka_product_tags.php',
    'admin/language/en-gb/extension/module/lavka_product_tags.php',
    'catalog/view/theme/unishop2/js/lavka-product-tags.js',
    'catalog/view/theme/unishop2/stylesheet/lavka-product-tags.css',
    'catalog/view/theme/unishop2/js/lavka-card-reviews.js',
    'catalog/view/theme/unishop2/stylesheet/lavka-card-reviews.css',
    'catalog/view/theme/unishop2/stylesheet/lavka-mobile-cards.css',
    'catalog/view/theme/unishop2/template/extension/module/uni_stickers.twig',
    'admin/controller/catalog/product.php',
    'admin/model/catalog/product.php',
    'admin/view/template/catalog/product_list.twig',
    'admin/view/template/catalog/product_form.twig',
    'catalog/model/extension/module/uni_new_data.php',
    'catalog/controller/extension/module/uni_new_data.php',
]
def sha(b): return hashlib.sha256(b).hexdigest()
entries=[]
for path in FILES:
    found=subprocess.run(['git','show',BASE+':store/'+path],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    b=found.stdout if found.returncode==0 else None
    variants=[] if b is None else sorted({sha(b),sha(b.replace(b'\r\n',b'\n')),sha(b.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'))})
    for source in COMPATIBLE:
        installed=subprocess.run(['git','show',source+':store/'+path],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if installed.returncode==0:
            old=installed.stdout
            variants=sorted(set(variants+[sha(old),sha(old.replace(b'\r\n',b'\n')),sha(old.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'))]))
    if path in ('catalog/controller/extension/module/uni_new_data.php','catalog/view/theme/unishop2/stylesheet/lavka-mobile-cards.css'):
        previous=subprocess.check_output(['git','show','f006edb:store/'+path],cwd=ROOT)
        variants=sorted(set(variants+[sha(previous),sha(previous.replace(b'\r\n',b'\n'))]))
    after=sha((ROOT/'store'/path).read_bytes())
    entries.append({'path':path,'before_sha256':sha(b) if b is not None else None,'allowed_before_sha256':variants,'after_sha256':after})
manifest={'target':'test.lavka-speciy.com','database':'lavkasp_test','store_id':0,
          'source_commit':subprocess.check_output(['git','rev-parse',BASE],cwd=ROOT,universal_newlines=True).strip(),
          'upgrade_from_commit':subprocess.check_output(['git','rev-parse',PREVIOUS],cwd=ROOT,universal_newlines=True).strip(),
          'files':entries,'deleted_files':[],
          'database_changes':['Create only lavka_tag_definition and lavka_product_tag if absent; add min_sales DEFAULT 11 to the known previous tag definition schema. Existing labels, manual flags, products and orders are retained.']}
(ROOT/'docs/product-tags-release.json').write_text(json.dumps(manifest,indent=2)+'\n')
patch=subprocess.check_output(['git','diff','--no-ext-diff','--relative=store',BASE,'--']+['store/'+p for p in FILES],cwd=ROOT)
(ROOT/'docs/product-tags.patch').write_bytes(patch)
print('Product tags manifest:',len(FILES),'files.')
