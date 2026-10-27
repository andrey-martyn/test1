"""Review/restore real pair suggestions without writing the existing analytics DB."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php73-dev')
OUT = Path(os.environ.get('ANALYTICS_TEST_OUTPUT', '/tmp/lavka-identity-review-browser'))
OUT.mkdir(parents=True, exist_ok=True)
SEED = r'''
define('LAVKA_IDENTITY_REVIEW_FIXTURE_ONLY',true);
require 'php/tests/identity_review_check.php';
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);
\Lavka\apply_snapshot($db,ir_fixture(),'test');
foreach([['2','8'],['3','4'],['3','7'],['3','9']] as $p)$db->prepare('INSERT INTO manual_links VALUES(?,?,?)')->execute([$p[0],$p[1],'2026-10-20']);
\Lavka\bump($db);\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
'''

def snapshot(data):
    with sqlite3.connect(data/'analytics.sqlite3') as db:
        return {name: db.execute('SELECT * FROM '+name+' ORDER BY rowid').fetchall()
                for name in ['raw_records','manual_links','customer_actions','crm_events','settings','imports','app_revision']}

with tempfile.TemporaryDirectory() as temporary:
    data = Path(temporary)
    password = secrets.token_urlsafe(24)
    docker = ['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}',
              '-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data',
              '-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
    subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),check=True,capture_output=True)
    before=snapshot(data)
    db_bytes=hashlib.sha256((data/'analytics.sqlite3').read_bytes()).hexdigest()
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    name='lavka-identity-review-'+secrets.token_hex(5)
    server=subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
    try:
        for _ in range(600):
            try:
                if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
            except httpx.TransportError:pass
            time.sleep(.1)
        else:raise RuntimeError('Identity review browser server did not start')
        assert httpx.post(base+'/index.php?route=/api/identity/review',json={'pairs':[['1','2']]},trust_env=False).status_code==401
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            context=browser.new_context(viewport={'width':1470,'height':1000})
            page=context.new_page();errors=[];external=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda req:external.append(req.url) if not req.url.startswith(origin+'/') else None)
            page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.dash-board')
            assert page.evaluate('dashboardState.data.workflows.duplicate_pairs')==2
            page.click('#navigation [data-area=customers]');page.click('#section-navigation [data-view=duplicates]')
            page.wait_for_selector('[data-pair="1:2"]')
            assert page.locator('.pair-checkbox').count()==2
            page.click('#privacy')
            row=page.locator('.duplicate-table tbody tr').filter(has=page.locator('[data-pair="1:2"]'))
            text=row.inner_text()
            assert all(s in text for s in ['Анна Северная','Олена Соколова','заказ №8','01.01.2024']),text
            assert row.locator('.match-item').count()==3
            assert 'Слабое основание' in page.locator('.duplicate-table tbody tr').filter(has=page.locator('[data-pair="5:6"]')).inner_text()
            page.screenshot(path=str(OUT/'historical-evidence-desktop.png'),full_page=True)
            pending=[];pattern='**/index.php?route=%2Fapi%2Fidentity%2Freview'
            page.route(pattern,lambda route:pending.append(route))
            row.locator('[data-action=reject-pair]').click();page.wait_for_function('() => state.identityReviewBusy')
            assert len(pending)==1 and json.loads(pending[0].request.post_data)['pairs']==[['1','2']]
            assert page.locator('[data-action=merge-selected]').evaluate_all('els=>els.every(e=>e.disabled)')
            assert page.locator('.pair-checkbox').evaluate_all('els=>els.every(e=>e.disabled)')
            assert page.locator('#identity-picker').evaluate('e=>e.inert')
            pending[0].continue_();page.unroute(pattern)
            page.wait_for_function('() => !state.identityReviewBusy && state.report.duplicate_pairs.length===1')
            assert page.locator('[data-pair="1:2"]').count()==0
            # The dashboard count is not served from the pre-review cache.
            page.click('#navigation [data-area=overview]');page.wait_for_function('() => !dashboardState.loading && dashboardState.data.workflows.duplicate_pairs===1')
            page.reload();page.wait_for_selector('.dash-board')
            page.click('#navigation [data-area=customers]');page.click('#section-navigation [data-view=duplicates]');page.wait_for_selector('#duplicate-review')
            assert page.locator('.pair-checkbox').count()==1
            page.select_option('#duplicate-review','dismissed')
            assert page.locator('[data-action=restore-pair]').count()==1
            assert page.locator('[data-action=merge-selected]').count()==0
            page.locator('[data-action=restore-pair]').click()
            page.wait_for_function('() => !state.identityReviewBusy && state.report.duplicate_pairs.length===2')
            page.select_option('#duplicate-review','pending')
            # A surname can be searched explicitly and stays clearly weak.
            page.select_option('#duplicate-review','surname')
            assert page.locator('.pair-checkbox').count()==1
            page.select_option('#duplicate-review','pending');page.check('#duplicate-select-all')
            page.locator('[data-action=reject-selected]').first.click()
            page.wait_for_function('() => !state.identityReviewBusy && state.report.duplicate_pairs.length===0')
            page.select_option('#duplicate-review','dismissed')
            assert page.locator('[data-action=restore-pair]').count()==2
            page.click('#privacy') if not page.evaluate('state.privacy') else None
            assert 'Анна Северная' not in page.locator('.duplicate-panel').inner_text()
            page.keyboard.press('Escape')
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':900})
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
                assert page.locator('[data-action=restore-pair]').first.bounding_box()['height']>=44
                page.screenshot(path=str(OUT/f'dismissed-{width}.png'),full_page=True)
            # API rejects unsafe methods, bad bodies and cross-origin writes.
            result=page.evaluate("""async()=>{
                const url=appUrl('/api/identity/review');
                const get=await fetch(url);
                const bad=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pairs:[['1','1']],as_of:'2026-10-27',currency:'UAH'})});
                return [get.status,bad.status];
            }""")
            assert result==[405,422],result
            with httpx.Client(base_url=base,trust_env=False) as client:
                for cookie in context.cookies():client.cookies.set(cookie['name'],cookie['value'],domain=cookie['domain'],path=cookie['path'])
                assert client.post(base+'/index.php?route=/api/identity/review',headers={'Origin':'https://untrusted.example.invalid'},json={}).status_code==403
            assert snapshot(data)==before,'All source records, CRM and previously merged links must remain identical'
            assert hashlib.sha256((data/'analytics.sqlite3').read_bytes()).hexdigest()==db_bytes,'Existing analytics DB bytes changed'
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'historical_evidence':'passed','reject_one_and_batch':'passed','reload_and_dashboard_cache':'passed','restore_and_weak_filter':'passed','existing_database_bytes':'unchanged','existing_manual_links':'preserved','privacy_auth_and_origin':'passed','widths':[1470,390,320]}))
    finally:
        subprocess.run(['docker','stop',name],check=False,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=20)
