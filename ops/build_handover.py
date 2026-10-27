#!/usr/bin/env python3
"""Build an allowlisted admin handoff from the approved source commit; no shop access."""
import argparse
import datetime
import hashlib
import html
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = '1.0.0-rc1'
SOURCE = '74d9b07'
DOCS = ['README.md','01_CHANGES.md','02_INSTALL.md','03_ACCEPTANCE.md',
        '04_ROLLBACK.md','05_DATABASE.md','06_FILE_GUIDE.md']


def sha(data):
    return hashlib.sha256(data).hexdigest()


def git(*args):
    return subprocess.check_output(['git']+list(args),cwd=ROOT)


def inline(text):
    text = html.escape(text)
    text = re.sub(r'`([^`]+)`',r'<code>\1</code>',text)
    def link(match):
        target = match[2]
        if target in DOCS: target = '#'+Path(target).stem
        return '<a href="'+target+'">'+match[1]+'</a>'
    text = re.sub(r'\[([^\]]+)\]\(([^)]+)\)',link,text)
    return re.sub(r'\*\*([^*]+)\*\*',r'<strong>\1</strong>',text)


def markdown(text):
    """Small offline renderer for the headings/tables/lists/code used in these docs."""
    lines = text.splitlines()
    rendered = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line.strip(): i += 1; continue
        if line.startswith('```'):
            code = []; i += 1
            while i < len(lines) and not lines[i].startswith('```'):
                code.append(lines[i]); i += 1
            rendered.append('<pre><code>'+html.escape('\n'.join(code))+'</code></pre>')
            i += 1; continue
        heading = re.match(r'^(#{1,5})\s+(.+)$',line)
        if heading:
            level = len(heading[1])+1
            rendered.append('<h{0}>{1}</h{0}>'.format(level,inline(heading[2])))
            i += 1; continue
        if line.startswith('|'):
            rows = []
            while i < len(lines) and lines[i].startswith('|'):
                cells = [c.strip() for c in lines[i].strip('|').split('|')]
                if not all(re.fullmatch(r'[:\- ]+',c) for c in cells): rows.append(cells)
                i += 1
            table = '<table>'
            for index,row in enumerate(rows):
                tag = 'th' if index == 0 else 'td'
                table += '<tr>'+''.join('<'+tag+'>'+inline(c)+'</'+tag+'>' for c in row)+'</tr>'
            rendered.append(table+'</table>'); continue
        bullet = re.match(r'^(?:- |\d+\. )(.+)$',line)
        if bullet:
            ordered = not line.startswith('- ')
            tag = 'ol' if ordered else 'ul'
            items = []
            while i < len(lines):
                item = re.match(r'^(?:- |\d+\. )(.+)$',lines[i])
                if not item: break
                content = item[1]; i += 1
                while i < len(lines) and lines[i].startswith('  ') and lines[i].strip():
                    content += ' '+lines[i].strip(); i += 1
                content = content.replace('[ ]','☐')
                items.append('<li>'+inline(content)+'</li>')
            rendered.append('<'+tag+'>'+''.join(items)+'</'+tag+'>'); continue
        paragraph = [line]; i += 1
        while i < len(lines) and lines[i].strip() and not re.match(r'^(#|\||```|- |\d+\. )',lines[i]):
            paragraph.append(lines[i]); i += 1
        rendered.append('<p>'+inline(' '.join(paragraph))+'</p>')
    return '\n'.join(rendered)


def handbook(folder):
    sections = []
    navigation = []
    for name in DOCS:
        text = (folder/name).read_text(encoding='utf-8')
        title = text.splitlines()[0].lstrip('# ')
        anchor = Path(name).stem
        navigation.append('<li><a href="#'+anchor+'">'+html.escape(title)+'</a></li>')
        sections.append('<section id="'+anchor+'">'+markdown(text)+'</section>')
    style = '''body{font:16px/1.6 system-ui,sans-serif;color:#253020;background:#f4f6f3;margin:0}
main{max-width:1100px;margin:24px auto;padding:30px;background:white;border-radius:12px}
h1,h2,h3{line-height:1.3}h1{font-size:28px}h2{font-size:24px}h3{font-size:19px}
a{color:#34621f}nav{padding:15px 24px;background:#eef4e9;border-radius:8px}
section{padding-top:20px;margin-top:26px;border-top:1px solid #dce3d7}
table{width:100%;border-collapse:collapse;font-size:14px;margin:18px 0}
td,th{border:1px solid #dce3d7;padding:9px;text-align:left;overflow-wrap:anywhere}
th{background:#f1f5ed}pre{background:#f4f5f3;padding:16px;border-radius:6px;overflow:auto}
code{font:13px/1.5 ui-monospace,Consolas,monospace}p code,li code,td code{overflow-wrap:anywhere}
li{margin:6px 0}@media print{body{background:white}main{margin:0;padding:0}nav{display:none}
section{break-before:page}pre{white-space:pre-wrap}a{color:inherit;text-decoration:none}}
@media(max-width:700px){main{margin:0;padding:16px;border-radius:0}table{font-size:12px}}
'''
    page = '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Передача Игорю — Лавка Спецій</title><style>'+style+'</style><main><h1>Мобильные карточки: пакет для администратора</h1><p>Версия '+VERSION+' · исходники '+SOURCE+' · только проверка и ручной перенос.</p><nav><ul>'+''.join(navigation)+'</ul></nav>'+''.join(sections)+'</main></html>'
    (folder/'HANDBOOK.html').write_text(page,encoding='utf-8')


def validate(folder, entries):
    """Exercise the shipped checker and actual patch on disposable file copies."""
    def check(shop, expected, code):
        before = {str(p.relative_to(shop)):sha(p.read_bytes()) for p in shop.rglob('*') if p.is_file() and not p.is_symlink()}
        run = subprocess.run([sys.executable,'-B',str(folder/'tools/check_files.py'),str(shop)],
                             stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True,timeout=15)
        result = json.loads(run.stdout)
        assert run.returncode == code and result['result'] == expected,(run.returncode,result)
        after = {str(p.relative_to(shop)):sha(p.read_bytes()) for p in shop.rglob('*') if p.is_file() and not p.is_symlink()}
        assert before == after,'Read-only checker wrote to the shop'
    with tempfile.TemporaryDirectory(prefix='lavka-handover-review-') as temporary:
        base = Path(temporary)
        review = base/'review'
        shutil.copytree(str(folder/'baseline'),str(review))
        check(review,'baseline_matches',0)
        subprocess.run(['git','apply','--check',str(folder/'patches/mobile-category.patch')],cwd=str(review),check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        subprocess.run(['git','apply',str(folder/'patches/mobile-category.patch')],cwd=str(review),check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        for entry in entries: assert sha((review/entry['path']).read_bytes()) == entry['after_sha256']
        check(review,'files_already_match_release',0)
        modified = review/entries[0]['path']
        modified.write_bytes(modified.read_bytes()+b'\n/* local change */\n')
        check(review,'manual_merge_required',2)
        shutil.rmtree(str(review)); shutil.copytree(str(folder/'baseline'),str(review))
        for path in review.rglob('*'):
            if path.is_file(): path.write_bytes(path.read_bytes().replace(b'\r\n',b'\n'))
        check(review,'baseline_matches',0)
        new = next(e for e in entries if e['action'] == 'add')
        conflict = review/new['path']; conflict.parent.mkdir(parents=True,exist_ok=True); conflict.write_text('other code')
        check(review,'manual_merge_required',2)
        conflict.unlink()
        outside = base/'outside'; outside.write_text('not read')
        linked = review/entries[0]['path']; linked.unlink(); linked.symlink_to(outside)
        check(review,'manual_merge_required',2)
    for line in (folder/'SHA256SUMS').read_text().splitlines():
        expected,name = line.split('  ',1)
        assert sha((folder/name).read_bytes()) == expected,name


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',default=str(ROOT/'handover/releases'),help='Output directory, outside the shop; defaults to versioned GitHub handoff artifacts')
    args = parser.parse_args()
    output = Path(args.output).resolve(); output.mkdir(parents=True,exist_ok=True)
    source = git('rev-parse',SOURCE+'^{commit}').decode().strip()
    release = json.loads(git('show',source+':docs/release-manifest.json').decode())
    base = release['source_commit']
    name = 'lavka-mobile-cards-'+VERSION
    destination = output/(name+'.zip')
    with tempfile.TemporaryDirectory(prefix='lavka-admin-package-') as temporary:
        folder = Path(temporary)/name; folder.mkdir()
        for document in DOCS: shutil.copyfile(str(ROOT/'handover'/document),str(folder/document))
        (folder/'tools').mkdir()
        shutil.copyfile(str(ROOT/'handover/tools/check_files.py'),str(folder/'tools/check_files.py'))
        entries = []
        for entry in release['files']:
            path = entry['path']
            final = git('show',source+':store/'+path)
            assert sha(final) == entry['after_sha256'],path
            target = folder/'files'/path; target.parent.mkdir(parents=True,exist_ok=True); target.write_bytes(final)
            item = dict(entry,action='modify' if entry['before_sha256'] else 'add',after_lf_sha256=sha(final.replace(b'\r\n',b'\n')))
            entries.append(item)
            if entry['before_sha256']:
                original = git('show',base+':store/'+path)
                assert sha(original) == entry['before_sha256'],path
                target = folder/'baseline'/path; target.parent.mkdir(parents=True,exist_ok=True); target.write_bytes(original)
        (folder/'patches').mkdir()
        (folder/'patches/mobile-category.patch').write_bytes(git('diff','--no-ext-diff','--relative=store',base,source,'--','store'))
        database = folder/'database'; database.mkdir()
        for original,renamed in [('ops/migrations/legacy-grid-before.css','user-css-before.css'),('ops/migrations/legacy-grid-after.css','user-css-after.css'),('docs/legacy-grid.patch','legacy-grid-css.patch')]:
            (database/renamed).write_bytes(git('show',source+':'+original))
        specification = {'conditional':True,'field':'user_css','settings_table_suffix':'uni_setting',
                         'source_discovery':'Administrator verifies actual database, table prefix and store_id.',
                         'remove_comment':'/* Две карточки в категориях по штатной настройке UniShop2 */',
                         'known_before_decoded_sha256':sha((database/'user-css-before.css').read_bytes()),
                         'known_after_decoded_sha256':sha((database/'user-css-after.css').read_bytes()),
                         'user_js':'preserve','schema_changes':False,'executable_sql_included':False}
        (database/'change-spec.json').write_text(json.dumps(specification,ensure_ascii=True,indent=2)+'\n')
        manifest = {'manifest_version':1,'release':VERSION,'files':entries,'deleted_source_files':[],
                    'database_changes':'Conditional; see 05_DATABASE.md. No executable update included.'}
        (folder/'MANIFEST.json').write_text(json.dumps(manifest,indent=2)+'\n')
        metadata = {'name':'Lavka Speciy mobile product cards','version':VERSION,'prepared_on':'2026-10-27',
                    'source_commit':source,'baseline_commit':base,'source_archive_sha256':release['source_archive_sha256'],
                    'platform':{'opencart':'3.0.2.0','theme':'UniShop2','theme_version':'1.9.0.0'},
                    'status':'candidate_for_admin_review','production_changes_performed':False,
                    'test_evidence':{'initial_test_deployment':'confirmed_by_host_report','mobile_category_design':'approved_by_user_and_Tanya',
                                     'final_weight_100_host_deployment':'not_separately_confirmed','local_tool_checks':64,'earlier_mock_browser_scenarios':11},
                    'limitations':['Production source differences require manual merge.','Real 5-7 second backend delay is not diagnosed.','Complete checkout and returning-client cache acceptance remain required.']}
        (folder/'RELEASE.json').write_text(json.dumps(metadata,indent=2)+'\n')
        handbook(folder)
        paths = sorted(p for p in folder.rglob('*') if p.is_file())
        sums = ''.join(sha(path.read_bytes())+'  '+path.relative_to(folder).as_posix()+'\n' for path in paths)
        (folder/'SHA256SUMS').write_text(sums)
        validate(folder,entries)
        stamp = datetime.datetime.fromtimestamp(int(git('show','-s','--format=%ct',source).decode()),datetime.timezone.utc).timetuple()[:6]
        with zipfile.ZipFile(str(destination),'w',compression=zipfile.ZIP_DEFLATED) as archive:
            for path in sorted(p for p in folder.rglob('*') if p.is_file()):
                info = zipfile.ZipInfo(name+'/'+path.relative_to(folder).as_posix(),stamp)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info,path.read_bytes())
        shutil.copyfile(str(folder/'HANDBOOK.html'),str(output/(name+'-handbook.html')))
    with zipfile.ZipFile(str(destination)) as archive: assert archive.testzip() is None
    (output/(name+'.zip.sha256')).write_text(sha(destination.read_bytes())+'  '+destination.name+'\n')
    print(json.dumps({'archive':str(destination),'handbook':str(output/(name+'-handbook.html')),
                      'sha256':sha(destination.read_bytes()),'files_for_shop':len(entries),
                      'validation':'checksums, six checker scenarios, exact patch application passed',
                      'shop_writes':False},indent=2))


if __name__ == '__main__':
    main()
