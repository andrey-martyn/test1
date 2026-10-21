"""Compare PHP and the established reference on the same private snapshot.

The Python reference is a DEVELOPMENT check, never a production dependency.
No customer names, contacts or report payloads are printed.
"""
from pathlib import Path
import json
import math
import os
PHP_IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php-dev')
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT))
from analytics import Analytics

def compare(a,b,path='',differences=None):
    differences=[] if differences is None else differences
    if isinstance(a,dict) and isinstance(b,dict):
        if set(a)!=set(b):differences.append(path+' object keys')
        for key in a.keys()&b.keys():compare(a[key],b[key],path+'.'+key,differences)
    elif isinstance(a,list) and isinstance(b,list):
        if len(a)!=len(b):differences.append(path+' list length')
        for i,(x,y) in enumerate(zip(a,b)):compare(x,y,path+f'[{i}]',differences)
    elif isinstance(a,(int,float)) and isinstance(b,(int,float)):
        if not math.isclose(a,b,abs_tol=.005,rel_tol=1e-10):differences.append(path+' number')
    elif a!=b:differences.append(path+' value')
    return differences

(ROOT/'test-results').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='php-contract-',dir=ROOT/'test-results') as t:
    directory=Path(t);data=directory/'data';data.mkdir()
    with sqlite3.connect(ROOT/'data/analytics.sqlite3') as source,sqlite3.connect(data/'analytics.sqlite3') as target:source.backup(target)
    reference=Analytics(data/'analytics.sqlite3');checks=[]
    for year,asof in [(2026,'2026-10-20'),(2025,'2025-12-31'),(2021,'2021-03-10')]:
        output=directory/f'report-{year}.json'
        command=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app',PHP_IMAGE,'php','-d','memory_limit=256M','php/bin/report.php',
                 '--data','/app/'+data.relative_to(ROOT).as_posix(),'--year',str(year),'--as-of',asof,'--currency','UAH','--output','/app/'+output.relative_to(ROOT).as_posix()]
        result=subprocess.run(command,capture_output=True,text=True,timeout=120)
        if result.returncode:raise RuntimeError('PHP report failed: '+result.stderr[:600])
        performance=json.loads(result.stdout)
        actual=json.loads(output.read_text());expected=reference.report(year,asof,'UAH')
        differences=compare(expected,actual)
        if differences:
            print(json.dumps({'year':year,'mismatches':len(differences),'paths':differences[:40]},ensure_ascii=False))
            raise SystemExit(1)
        # Second invocation must serve the persisted report cache correctly.
        cached=subprocess.run(command,capture_output=True,text=True,timeout=120,check=True)
        cache_stats=json.loads(cached.stdout)
        checks.append({'year':year,'all_report_fields':'match','first_seconds':performance['seconds'],'cached_seconds':cache_stats['seconds'],'peak_mb':performance['peak_mb']})
    print(json.dumps({'php_reference_parity':checks},ensure_ascii=False))
