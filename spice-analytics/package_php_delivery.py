"""Export an independent PHP application with no Python runtime or private data."""
from pathlib import Path
import zipfile

ROOT=Path(__file__).parent
PHP=ROOT/'php'

def php_runtime_files():
    allowed=[]
    for folder in ('src','bin','public','static','vendor'):
        allowed.extend(p for p in (PHP/folder).rglob('*') if p.is_file())
    allowed.extend(PHP/name for name in ('README.md','COMPATIBILITY.md','COMPETITORS.md','COMPETITOR_REPORT.md','COMPETITOR_CATALOG.md','SANDBOX.md','PRICING_RULES.md','COMPETITOR_STRATEGY.md','DASHBOARD.md','BUSINESS_UI.md','composer.json','.gitignore'))
    forbidden={'source.json','access.json','access-php.json','.env'}
    assert not any(p.name in forbidden or p.suffix in ('.sql','.sqlite3','.mmdb') or 'data' in p.relative_to(PHP).parts for p in allowed)
    return sorted(allowed)

def build():
    archive=ROOT.parent/'lavka-php.zip'
    with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=7) as z:
        for p in php_runtime_files():z.write(p,'lavka-php/'+p.relative_to(PHP).as_posix())
    with zipfile.ZipFile(archive) as z:assert z.testzip() is None
    print(f'Prepared {archive.name}: {archive.stat().st_size} bytes. PHP only, private database and credentials excluded.')

if __name__=='__main__':build()
