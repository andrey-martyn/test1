"""Persisted cell status, live worker results and actionable blockers; synthetic data only."""
from copy import deepcopy
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import tempfile
import time

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT), str(ROOT / 'tests')]
from test_production import snapshot
from sync_mysql import apply_snapshot

IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php73-dev')
OUT = Path(os.environ.get('ANALYTICS_TEST_OUTPUT', '/tmp/lavka-status-browser'))
OUT.mkdir(parents=True, exist_ok=True)
SEED = r'''
require 'php/src/Auth.php';require 'php/src/Competitors.php';
$input=json_decode(stream_get_contents(STDIN),true);\Lavka\Auth::setPassword('/test-data',$input['password']);
$db=\Lavka\competitors_db('/test-data');$db->exec('UPDATE competitor_targets SET enabled=0');$now=\Lavka\utc_now();$products=[];$ids=[];
function status_offer($price='200',$grams=500,$stock='in_stock',$packaging='kraft') {
 return ['key'=>(string)$grams.'-'.$packaging,'label'=>$grams.' г','price'=>$price,'currency'=>'UAH','grams'=>$grams,'unit'=>'pack','availability'=>$stock,'packaging'=>$packaging,'min_quantity'=>1,'condition'=>''];
}
function status_record($db,$target,$offers,$at) {
 \Lavka\competitors_record_attempt($db,$target['id'],['kind'=>'product','title'=>$target['label'],'offers'=>$offers,'links'=>[],'text'=>'','warnings'=>[],'method'=>'synthetic'],null,$at,200);
}
foreach(['queue','stock','own','pack','packaging','review','ready'] as $i=>$kind) {
 $group='status-'.$kind;$label='Проверка '.$kind;
 $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id='.(910000+$i),'label'=>$label,'match_group'=>$group,'match_confirmed'=>true]);
 $products[]=['target_id'=>$own['id'],'product_id'=>910000+$i,'source_product_id'=>910000+$i,'url'=>$own['url'],'label'=>$label,'offers'=>[status_offer('300',$kind==='own'?50:500,'in_stock','doypack')],'warnings'=>[]];
 $remote=\Lavka\competitors_save_target($db,['source_id'=>$kind==='packaging'?'deluxe':'specii','kind'=>'product','url'=>($kind==='packaging'?'https://deluxe.com.ua/':'https://www.specii.in.ua/').'synthetic-status-'.$kind,'label'=>$label,'match_group'=>$group,'match_confirmed'=>$kind!=='review']);$ids[$kind]=$remote['id'];
 if($kind==='queue'){\Lavka\competitors_record_attempt($db,$remote['id'],null,'variant_prices_unavailable',gmdate('Y-m-d\TH:i:s\Z',time()-3600),200);\Lavka\competitors_queue($db,$remote['id']);}
 else status_record($db,$remote,$kind==='packaging'?[status_offer('200',500,'in_stock','zip'),status_offer('210',500,'in_stock','kraft')]:[status_offer('200',$kind==='pack'?50:500,$kind==='stock'?'unknown':'in_stock')],$now);
}
\Lavka\atomic_write('/test-data/own-catalog.json',\Lavka\encode(['state'=>'ok','last_success'=>$now,'checked_at'=>$now,'products'=>$products,'product_count'=>count($products),'offer_count'=>count($products),'currency_decimal_places'=>2,'source_database'=>'synthetic','environment'=>'read_only']));echo json_encode($ids);
'''
WORKER = r'''
require 'php/src/Competitors.php';$input=json_decode(stream_get_contents(STDIN),true);$db=\Lavka\competitors_db('/test-data');
\Lavka\competitors_record_attempt($db,$input['id'],$input['observation']??null,$input['error']??null,$input['at'],200);
'''

with tempfile.TemporaryDirectory() as temporary:
    data = Path(temporary)
    original = snapshot()
    rows = {table: {} for table in original}
    rows['oc_order_status'] = original['oc_order_status']
    order = deepcopy(original['oc_order']['1'])
    order.update(order_id='101', customer_id='101', firstname='Тест', lastname='Синтетический')
    rows['oc_order']['101'] = order
    line = deepcopy(original['oc_order_product']['1'])
    line.update(order_product_id='101', order_id='101')
    rows['oc_order_product']['101'] = line
    option = deepcopy(original['oc_order_option']['1'])
    option.update(order_option_id='101', order_id='101', order_product_id='101')
    rows['oc_order_option']['101'] = option
    apply_snapshot(data / 'analytics.sqlite3', rows, 'synthetic')
    password = secrets.token_urlsafe(24)
    docker = ['docker', 'run', '--rm', '--user', f'{os.getuid()}:{os.getgid()}',
              '-v', str(ROOT) + ':/app:ro', '-v', temporary + ':/test-data',
              '-e', 'ANALYTICS_DATA_DIR=/test-data', '-e', 'ANALYTICS_BASE_PATH=/analytics']
    def php(code, body):
        return subprocess.run(docker + ['-i', IMAGE, 'php', '-r', code],
                              input=json.dumps(body).encode(), capture_output=True, check=True)
    ids = json.loads(php(SEED, {'password': password}).stdout)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    name = 'lavka-status-browser-' + secrets.token_hex(5)
    server = subprocess.Popen(docker + ['--name', name, '-p', f'127.0.0.1:{port}:8000', IMAGE,
                                       'php', '-S', '0.0.0.0:8000', '-t', 'php/public', 'php/public/index.php'],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    origin = f'http://127.0.0.1:{port}'
    base = origin + '/analytics'
    try:
        for _ in range(300):
            try:
                if httpx.get(base + '/index.php?route=/health', trust_env=False, timeout=2).status_code == 200:
                    break
            except httpx.TransportError:
                pass
            time.sleep(.1)
        else:
            raise RuntimeError('Synthetic status server did not start')
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
            page = browser.new_page(viewport={'width': 1440, 'height': 1000})
            errors, external, requests = [], [], []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.on('request', lambda r: external.append(r.url) if r.url.startswith(('http:', 'https:')) and not r.url.startswith(origin + '/') else None)
            page.on('request', lambda r: requests.append(r.url))
            assert httpx.get(base + '/index.php?route=/api/competitors/revision', trust_env=False).status_code == 401
            page.goto(base + '/')
            page.fill('#password', password)
            page.click('button[type=submit]')
            page.wait_for_selector('.dash-board')
            page.click('[data-view=competitors]')
            page.wait_for_selector('.competitor-matrix')
            probe = page.request.get(base + '/index.php?route=/api/competitors/revision')
            assert probe.status == 200 and set(probe.json()) == {'revision', 'server_now'}
            assert page.request.post(base + '/index.php?route=/api/competitors/revision', data='{}').status == 405
            cell = page.locator('[data-matrix-row="status-queue|500"] td[data-source=specii]')
            assert cell.locator('.competitor-check-badge').inner_text() == 'В очереди'
            assert cell.locator('.competitor-cell-attempt time').count() == 1
            assert 'Предыдущая попытка:' in cell.inner_text()
            # The new actions open exactly the affected set, not a general unfiltered tab.
            for kind in ['stock', 'own', 'pack', 'packaging', 'queue']:
                page.locator(f'.competitor-readiness [data-comp-action=tasks][data-kind={kind}]').click()
                page.wait_for_selector(f'#drawer .competitor-task-list[data-kind={kind}]')
                assert page.locator('#drawer .competitor-task-card').count() == 1
                assert page.locator('#drawer .competitor-task-card a').count() >= 1
                if kind == 'packaging':
                    page.locator('#drawer [data-comp-action=edit]').first.click()
                    page.wait_for_selector('#competitor-target-form select[name=packaging]')
                    page.click('[data-comp-action=cancel-form]')
                elif kind == 'pack':
                    page.locator('#drawer [data-comp-action=task-detail]').click()
                    assert '50 г' in page.locator('#drawer .competitor-detail-variant').inner_text()
                    page.click('[data-action=close-dialog]')
                else:
                    page.click('[data-action=close-dialog]')
            page.locator('[data-comp-action=matching-loaded]').click()
            page.wait_for_function('() => !monitoringState.loading')
            assert 'Подтвердить товары' in page.locator('#competitor-tabpanel h2').first.inner_text()
            assert page.input_value('#monitoring-search [name=status]') == 'review'
            page.click('#competitor-tab-products')
            page.locator('[data-comp-action=matrix-ready]').click()
            assert page.input_value('#competitor-matrix-pack') == '500'
            assert page.input_value('#competitor-matrix-status') == 'ready'
            page.select_option('#competitor-matrix-status', 'all')
            page.locator('.competitor-load-details summary').click()
            page.screenshot(path=str(OUT / 'actions-desktop.png'), full_page=True)
            # A real reload restores the public competitor tab and saved queue state.
            page.clock.install()
            page.reload()
            page.wait_for_selector('.competitor-matrix')
            assert page.url.endswith('#competitors/products')
            assert cell.locator('.competitor-check-badge').inner_text() == 'В очереди'
            # A background response may never overwrite an unsaved form.
            cell.locator('[data-comp-action=matrix-detail]').first.click()
            page.locator('#drawer [data-comp-action=edit]').first.click()
            page.fill('#competitor-target-form [name=label]', 'Несохранённое название')
            at = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
            php(WORKER, {'id': ids['queue'], 'error': 'variant_prices_unavailable', 'at': at})
            page.clock.fast_forward(15001)
            assert page.input_value('#competitor-target-form [name=label]') == 'Несохранённое название'
            page.click('[data-comp-action=cancel-form]')
            page.clock.fast_forward(15001)
            page.wait_for_function('() => !competitorState.autoBusy && !competitorState.loading')
            page.wait_for_function("() => document.querySelector('[data-matrix-row=\"status-queue|500\"] td[data-source=specii] .competitor-check-badge')?.textContent==='Ошибка проверки'")
            assert cell.locator('time').get_attribute('datetime') == at
            cell.locator('[data-comp-action=queue]').click()
            page.wait_for_function('() => !competitorState.queueing && !competitorState.loading')
            assert cell.locator('.competitor-check-badge').inner_text() == 'В очереди'
            # Worker success arrives while the price drawer is open; both views update.
            cell.locator('[data-comp-action=matrix-detail]').first.click()
            observation = {'kind': 'product', 'title': 'Проверка queue', 'offers': [
                {'key': '500-kraft', 'label': '500 г', 'price': '125', 'currency': 'UAH', 'grams': 500,
                 'unit': 'pack', 'availability': 'in_stock', 'packaging': 'kraft', 'min_quantity': 1, 'condition': ''}],
                'links': [], 'text': '', 'warnings': [], 'method': 'synthetic'}
            php(WORKER, {'id': ids['queue'], 'observation': observation, 'at': at})
            page.clock.fast_forward(15001)
            page.wait_for_function("() => document.querySelector('[data-matrix-row=\"status-queue|500\"] td[data-source=specii] .competitor-check-badge')?.textContent==='Обновлено'")
            assert cell.locator('.competitor-cell-price').inner_text().startswith('125')
            assert page.locator('[data-comp-action=refresh]').is_enabled()
            assert cell.evaluate("el=>el.classList.contains('is-best')")
            assert page.locator('#drawer[open] .competitor-price-detail .competitor-check-badge').inner_text() == 'Обновлено'
            assert cell.locator('.competitor-cell-date time').get_attribute('datetime') == at
            page.click('[data-action=close-dialog]')
            page.reload()
            page.wait_for_selector('.competitor-matrix')
            assert cell.locator('.competitor-cell-price').inner_text().startswith('125')
            # Unchanged revision probes do not download another full overview.
            page.clock.fast_forward(15001)
            page.wait_for_function('() => !competitorState.autoBusy')
            count = sum('route=%2Fapi%2Fcompetitors&' in url or url.endswith('route=%2Fapi%2Fcompetitors') for url in requests)
            page.clock.fast_forward(15001)
            page.wait_for_function('() => !competitorState.autoBusy')
            assert count == sum('route=%2Fapi%2Fcompetitors&' in url or url.endswith('route=%2Fapi%2Fcompetitors') for url in requests)
            for width in [390, 320]:
                page.set_viewport_size({'width': width, 'height': 844})
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
                page.screenshot(path=str(OUT / f'actions-{width}.png'), full_page=True)
            assert not errors, errors
            assert not external, external
            browser.close()
            print(json.dumps({'saved_queue_error_and_success': 'passed', 'automatic_table_and_drawer_refresh': 'passed',
                              'reload_restoration_and_form_protection': 'passed', 'blocker_actions_and_mobile': 'passed'}))
    finally:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=15)
