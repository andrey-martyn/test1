"""Build the review bundle with explicit source paths and no private working files."""
from pathlib import Path
import shutil
import zipfile
from build_demo import build

ROOT=Path(__file__).parent
PATTERNS=['*.py','*.md','hosting_bootstrap.sh','requirements.txt','.gitignore','static/*.html','static/*.js','static/*.css','static/*.geojson','tests/*.py','tests/fixtures/GeoIP2-City-Test.mmdb']

def source_files():
    from package_php_delivery import php_runtime_files
    files={p for pattern in PATTERNS for p in ROOT.glob(pattern) if p.is_file()}
    files.update(php_runtime_files())
    files.update(p for p in (ROOT/'php/tests').iterdir() if p.is_file() and (p.suffix in ('.php','.py','.js') or p.name in ('Dockerfile','Dockerfile73')))
    files.update(p for p in (ROOT/'php/tests/fixtures').glob('*.html') if p.is_file() and not p.is_symlink())
    return sorted(files)

if __name__=='__main__':
    out=ROOT.parent
    build(out/'lavka-demo.html')
    shutil.copyfile(out/'lavka-demo.html',out/'lavka-demo-v2.html')
    archive=out/'lavka-analytics-v2.zip'
    with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=7) as z:
        z.write(out/'lavka-demo.html','lavka-demo.html')
        z.writestr('START-HERE.txt','ЛАВКА — УДЕРЖАНИЕ, ВЕРСИЯ 2\n\nРаспакуйте архив и откройте lavka-demo.html в Chrome.\nВ демо имена заменены, контакты и IP удалены. Новые экраны: очередь контактов, удержание/когорты, RFM, повторные продажи, сезонность, импорт GA4.\nПолная версия с паролем и сохранением настроек: spice-analytics/README.md. Рабочая БД и SQL-выгрузка в архив не включены. Для географии IP требуется отдельная настоящая City MMDB.\n')
        for p in source_files():z.write(p,'spice-analytics/'+p.relative_to(ROOT).as_posix())
    with zipfile.ZipFile(archive) as z:
        assert z.testzip() is None
        assert not any('/data/' in n or n.endswith(('.sql','access.json','.sqlite3')) for n in z.namelist())
    shutil.copyfile(archive,out/'lavka-analytics.zip')
    print(f'Prepared {archive.name}: {archive.stat().st_size} bytes; private database and credentials excluded.')
