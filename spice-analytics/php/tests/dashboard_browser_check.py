"""Pilot overview: synthetic sales and saved observations, no shop/network writes."""
from copy import deepcopy
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT), str(ROOT / "tests")]
from test_production import snapshot
from sync_mysql import apply_snapshot

ARTIFACTS = Path(os.environ.get("ANALYTICS_TEST_OUTPUT", "/workspace/work/dashboard-pilot"))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
IMAGE = os.environ.get("ANALYTICS_PHP_IMAGE", "lavka-php73-dev")


def assert_current_page_assets(page):
    """HTML must bypass stale public copies and identify the source file's bytes."""
    assets = page.locator('script[src],link[rel="stylesheet"][href]')
    assert assets.count() > 0
    for element in assets.all():
        address = element.get_attribute("src") or element.get_attribute("href")
        parsed = urlparse(address)
        assert parsed.path == "/analytics/index.php", address
        params = parse_qs(parsed.query)
        assert len(params.get("route", [])) == 1, address
        route = params["route"][0]
        assert route.startswith("/static/") and route.endswith((".js", ".css")), address
        source = ROOT / "php/static" / route.removeprefix("/static/")
        assert source.parent == ROOT / "php/static" and source.is_file(), address
        expected = hashlib.sha256(source.read_bytes()).hexdigest()[:16]
        assert params.get("v") == [expected], address


SEED = r'''
require "php/src/Auth.php";
require "php/src/Competitors.php";
$input=json_decode(stream_get_contents(STDIN),true);
\Lavka\Auth::setPassword("/test-data",$input["password"]);
$db=\Lavka\competitors_db("/test-data");$db->exec("UPDATE competitor_targets SET enabled=0");
$own=\Lavka\competitors_save_target($db,["source_id"=>"lavka","kind"=>"product","url"=>"https://lavka-speciy.com/synthetic-clove","label"=>"Гвоздика <img src=x onerror=window.dashboardXss=1>","match_group"=>"synthetic-clove","match_confirmed"=>true]);
$t=\Lavka\competitors_save_target($db,["source_id"=>"spicery","kind"=>"product","url"=>"https://spiceryshop.com.ua/ru/content/products/synthetic-clove","label"=>"Гвоздика","match_group"=>"synthetic-clove","match_confirmed"=>true]);
function observed($price,$key="doy-500") {return ["kind"=>"product","title"=>"Гвоздика","offers"=>[["key"=>$key,"variant_id"=>$key,"label"=>"Дой-пак 500 г","price"=>$price,"currency"=>"UAH","grams"=>500,"availability"=>"in_stock","min_quantity"=>1,"unit"=>"pack","condition"=>"","packaging"=>"doypack"]],"text"=>"","links"=>[],"warnings"=>[],"method"=>"synthetic"];}
$old=gmdate("Y-m-d\TH:i:s\Z",time()-3600);$now=\Lavka\utc_now();
\Lavka\competitors_record_attempt($db,$own["id"],observed("600"),null,$now,200);
\Lavka\competitors_record_attempt($db,$t["id"],observed("500"),null,$old,200);
\Lavka\competitors_record_attempt($db,$t["id"],observed("550"),null,$now,200);
// A cheaper different native option must not become a false -45% price movement.
$other=\Lavka\competitors_save_target($db,["source_id"=>"deluxe","kind"=>"product","url"=>"https://deluxe.com.ua/synthetic-clove.html","label"=>"Другой вариант","match_group"=>"synthetic-clove","match_confirmed"=>true]);
\Lavka\competitors_record_attempt($db,$other["id"],observed("1000","old-500"),null,$old,200);
\Lavka\competitors_record_attempt($db,$other["id"],observed("550","new-500"),null,$now,200);
$catalog=\Lavka\competitors_save_target($db,["source_id"=>"banka","kind"=>"catalog","url"=>"https://banka-speciy.in.ua/ua/synthetic-new","label"=>"Наблюдаемый раздел"]);
function cataloged($extra=false) {$links=[["url"=>"https://banka-speciy.in.ua/ua/synthetic-base","title"=>"Базовый товар"]];if($extra)$links[]=["url"=>"https://banka-speciy.in.ua/ua/synthetic-new-item","title"=>"Новая замеченная смесь"];return ["kind"=>"catalog","title"=>"Каталог","offers"=>[],"links"=>$links,"text"=>"","warnings"=>[],"method"=>"synthetic"];}
\Lavka\competitors_record_attempt($db,$catalog["id"],cataloged(),null,$old,200);
\Lavka\competitors_record_attempt($db,$catalog["id"],cataloged(true),null,$now,200);
echo "ok";
'''

today = datetime.now(ZoneInfo("Europe/Kyiv")).date()
month = today.replace(day=1)
previous = (month-timedelta(days=1)).replace(day=1)
with tempfile.TemporaryDirectory() as temporary:
    data = Path(temporary)
    original = snapshot()
    rows = {table: {} for table in original}
    rows["oc_order_status"] = original["oc_order_status"]
    index = 0
    def add(customer, day, amount="500", currency="UAH", status="2"):
        global index
        index += 1
        key = str(index)
        order = deepcopy(original["oc_order"]["1"])
        order.update(order_id=key, customer_id=str(customer), date_added=str(day)+" 12:00:00",
                     date_modified=str(day)+" 12:00:00", firstname="Синтетический", lastname="Клиент "+str(customer),
                     email=f"synthetic{customer}@example.invalid", telephone="", total=amount,
                     currency_code=currency, order_status_id=status)
        rows["oc_order"][key] = order
        line = deepcopy(original["oc_order_product"]["1"])
        line.update(order_product_id=key, order_id=key, total=amount)
        rows["oc_order_product"][key] = line
        option = deepcopy(original["oc_order_option"]["1"])
        option.update(order_option_id=key, order_id=key, order_product_id=key)
        rows["oc_order_option"][key] = option
        return key
    for customer in range(1, 6):
        for age in (190,160,130,100,70,40):
            add(customer, today-timedelta(days=age), str(customer*100))
    for day in range(1, today.day+1):
        add(100 if day%2 else 101, month.replace(day=day), str(100+day*20))
    add(100, previous, "700")
    add(300, today, "99999", status="0")
    add(301, today, "99999", currency="USD")
    apply_snapshot(data/"analytics.sqlite3", rows, "synthetic")
    # Reproduce hosting: PHP reads current HTML from the private checkout, but
    # the web server may still have an old physical public/static directory.
    # A fresh HTML query string alone would not update these copied file bytes.
    site = data / "site"
    published = site / "analytics"
    public_assets = published / "static"
    public_assets.mkdir(parents=True)
    (published / "index.php").write_text('<?php require "/app/php/public/index.php";')
    stale_script = 'window.__stalePublicScriptExecuted=true;throw new Error("STALE_PUBLIC_SCRIPT_EXECUTED");'
    stale_style = 'html{--stale-public-stylesheet:1}.dash-kpis{display:block!important}'
    (public_assets / "app.js").write_text(stale_script)
    (public_assets / "style.css").write_text(stale_style)
    (data / "router.php").write_text('''<?php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath('/test-data/site' . $path);
if ($file !== false && strpos($file, '/test-data/site/') === 0 && is_file($file)) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') require $file;
    else return false;
} else require '/app/php/public/index.php';
''')
    password = secrets.token_urlsafe(24)
    name = "lavka-dashboard-"+secrets.token_hex(5)
    docker = ["docker", "run", "--rm", "--user", f"{os.getuid()}:{os.getgid()}",
              "-v", str(ROOT)+":/app:ro", "-v", temporary+":/test-data",
              "-e", "ANALYTICS_DATA_DIR=/test-data", "-e", "ANALYTICS_BASE_PATH=/analytics"]
    subprocess.run(docker+["-i",IMAGE,"php","-r",SEED],input=json.dumps({"password":password}).encode(),capture_output=True,check=True)
    with socket.socket() as listener:
        listener.bind(("127.0.0.1",0)); port=listener.getsockname()[1]
    server=subprocess.Popen(docker+["--name",name,"-p",f"127.0.0.1:{port}:8000",IMAGE,"php","-S","0.0.0.0:8000","-t","/test-data/site","/test-data/router.php"],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin=f"http://127.0.0.1:{port}";base=origin+"/analytics"
    try:
        for _ in range(300):
            try:
                if httpx.get(base+"/index.php?route=/health",trust_env=False,timeout=2).status_code==200:break
            except httpx.TransportError: pass
            time.sleep(.1)
        else: raise RuntimeError("Synthetic dashboard server did not start")
        assert httpx.get(base+"/index.php?route=/api/dashboard",trust_env=False).status_code==401
        for filename, expected in [("app.js", stale_script), ("style.css", stale_style)]:
            stale = httpx.get(base+"/static/"+filename,trust_env=False)
            assert stale.status_code == 200 and stale.text == expected
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path="/usr/bin/chromium",args=["--no-sandbox"])
            page=browser.new_page(viewport={"width":1280,"height":720})
            errors,external,mutations=[],[],[]
            styles=[]
            page.on("response",lambda response:styles.append({"url":response.url.split("/")[-1],"status":response.status}) if ".css" in response.url else None)
            page.on("pageerror",lambda error:errors.append(str(error)))
            page.on("request",lambda request:external.append(request.url) if request.url.startswith(("http:","https:")) and not request.url.startswith(origin+"/") else None)
            page.goto(base+"/")
            assert_current_page_assets(page)
            page.fill("#password",password);page.click("button[type=submit]")
            try: page.wait_for_selector(".dash-board",timeout=15000)
            except Exception:
                print({"errors":errors,"body":page.locator("body").inner_text()[-2400:]})
                raise
            page.on("request",lambda request:mutations.append(request.method+" "+request.url) if request.method in ["POST","PUT","PATCH","DELETE"] else None)
            assert_current_page_assets(page)
            assert page.evaluate("window.__stalePublicScriptExecuted") is None
            assert page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--stale-public-stylesheet').trim()") == ""
            page.wait_for_function("() => getComputedStyle(document.querySelector('.dash-kpis')).display === 'grid'")
            assert all(item["status"]==200 for item in styles),styles
            response=page.request.get(base+"/index.php?route=/api/dashboard&currency=UAH")
            assert response.status==200,response.text()
            result=response.json()
            expected=sum(float(o["total"]) for o in rows["oc_order"].values() if o["currency_code"]=="UAH" and o["order_status_id"]=="2" and o["date_added"][:7]==str(month)[:7])
            assert result["metrics"]["amount"]==expected,(result["metrics"],expected)
            assert result["metrics"]["repeat_share"]==round(100/(2 if today.day>1 else 1),1)
            assert page.request.get(base+"/index.php?route=/api/dashboard&currency=UAH").json()["metrics"]==result["metrics"]
            assert page.request.post(base+"/index.php?route=/api/dashboard",headers={"Origin":origin},data={}).status==405
            assert page.locator(".dash-kpi").count()==4
            assert page.locator(".dash-tasks .dash-task").count()==3
            assert page.locator(".dash-market-row").count()==1,"Only stable 500g variant generates a movement"
            assert "+10%" in page.locator(".dash-market-row").inner_text()
            assert "Не подключено" in page.locator(".dash-bottom").inner_text()
            assert page.evaluate("window.dashboardXss") is None
            # Real sandbox banner text has to fit too, without hiding its warning.
            page.locator("#environment-banner").evaluate("el=>{el.dataset.mode='sandbox';el.innerHTML='<strong>Тестовая среда · lavkasp_test</strong><span>Продажи и каталог тестового магазина. Изменение цен доступно только после проверки плана.</span>'}")
            measures=[]
            for width,height in [(1280,720),(1470,850),(390,844),(320,740)]:
                page.set_viewport_size({"width":width,"height":height})
                page.wait_for_timeout(140)
                page.screenshot(path=str(ARTIFACTS/f"dashboard-{width}.png"),full_page=True)
                sizes=page.evaluate("({width:innerWidth,height:innerHeight,scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight,bottom:document.querySelector('.dash-note').getBoundingClientRect().bottom})")
                assert sizes["scrollWidth"]<=width,sizes
                if width>=1280: assert sizes["bottom"]<=height and sizes["scrollHeight"]<=height,(sizes,styles,page.evaluate("Array.from(document.styleSheets).map(s=>({href:s.href,rules:s.cssRules.length}))"))
                measures.append(sizes)
            page.set_viewport_size({"width":1280,"height":720})
            page.click('[data-dash-action=group][data-value=weeks]')
            assert page.locator('.dash-chart-hit').count()<=6
            page.locator('.dash-chart-hit').first.click(force=True)
            page.wait_for_selector('#drawer[open] .dash-detail')
            page.click('[data-action=close-dialog]')
            page.click('[data-dash-action=group][data-value=days]')
            page.click('[data-dash-action=filter][data-value=risk]')
            page.click('[data-dash-action=queue]')
            page.wait_for_selector('#drawer[open] .dash-queue')
            assert 'Синтетический' not in page.locator('#drawer-content').inner_text(),"Privacy applies to compact and complete queues"
            page.click('[data-action=close-dialog]')
            page.click('[data-dash-action=filter][data-value=all]')
            page.locator('.dash-tasks [data-id^=customer]').first.click()
            page.wait_for_selector('#crm-prompt')
            page.click('[data-action=close-dialog]')
            page.locator('.dash-market-row').first.click()
            page.wait_for_selector('.dash-price-change')
            assert '500 UAH' in page.locator('.dash-price-change').inner_text()
            assert 'noopener' in page.locator('#drawer-content a').first.get_attribute('rel')
            page.click('[data-action=close-dialog]')
            page.click('[data-dash-action=market-tab][data-value=news]')
            assert 'Новая замеченная смесь' in page.locator('.dash-market-items').inner_text()
            page.locator('.dash-market-row').first.click()
            assert 'не дата появления' in page.locator('#drawer-content').inner_text()
            page.click('[data-action=close-dialog]')
            page.click('[data-dash-action=market-health]');assert 'Когда обновлялись данные' in page.locator('#drawer-content').inner_text();assert page.locator('#drawer-content tbody tr').count()==4;page.click('[data-action=close-dialog]')
            page.click('[data-dash-action=inventory]');assert 'пока не подключены' in page.locator('#drawer-content').inner_text();page.click('[data-action=close-dialog]')
            page.locator('.dash-kpi').last.click();assert 'до первого числа' in page.locator('#drawer-content').inner_text();page.click('[data-action=close-dialog]')
            page.click('[data-dash-action=reports]');page.wait_for_selector('.mini-list');assert page.locator('#year').is_visible()
            page.click('[data-view=customers]');page.wait_for_selector('.customer-table')
            page.fill('#as-of',str(previous));page.dispatch_event('#as-of','change');page.wait_for_function('(date) => state.report?.as_of===date',arg=str(previous))
            page.click('[data-view=overview]');page.wait_for_selector('.dash-board')
            page.locator('.dash-tasks [data-id^=customer]').first.click();page.wait_for_selector('#crm-prompt');assert page.evaluate('crmCard.as_of')==str(today);page.click('[data-action=close-dialog]')
            assert not page.locator('#year').is_visible()
            # Everyday areas expose every existing workflow through clear section tabs.
            assert 'Лавочка · Дела магазина' == page.title()
            assert page.locator('#navigation [data-area]').count()==6
            assert page.locator('#navigation').get_by_role('button',name='Продажи',exact=True).count()==1
            primary_text=page.locator('#navigation').inner_text()
            assert all(term not in primary_text for term in ['RFM','GA4','CRM','Когорты','Удержание'])
            page.click('[data-view=overview-report]');page.wait_for_selector('.mini-list')
            page.click('[data-view=seasonality]');page.wait_for_selector('[data-chart=months]')
            assert page.locator('#page-title').inner_text()=='Спрос по сезонам'
            page.click('[data-view=geography]');page.wait_for_selector('#map svg')
            page.click('[data-view=ga4]');page.wait_for_selector('.ga4-import')
            assert 'пока не загружены' in page.locator('#content').inner_text()
            assert page.locator('.business-import-help').evaluate('(el)=>!el.open')
            page.locator('.business-import-help summary').click();assert page.locator('.business-import-help code').is_visible();page.locator('.business-import-help summary').click()
            page.click('[data-view=customers]');page.wait_for_selector('.customer-table')
            page.click('[data-view=segments]');page.wait_for_selector('.segment-grid')
            assert 'Как давно и как часто покупают' in page.locator('#content').inner_text()
            page.click('[data-view=opportunities]');assert 'Что покупают вместе' in page.locator('#content').inner_text()
            page.click('[data-view=duplicates]');assert page.locator('#page-title').inner_text()=='Совпадения контактов'
            page.click('[data-view=manual]');page.wait_for_selector('.manual-start')
            assert page.locator('#page-title').inner_text()=='Как вернуть покупателя'
            page.click('[data-section-view=customers]');page.wait_for_selector('.customer-table')
            assert page.locator('#navigation [data-area=customers]').get_attribute('aria-current')=='page'
            page.set_viewport_size({'width':390,'height':844})
            page.click('[data-view=actions]');page.wait_for_selector('.crm-queue')
            assert page.locator('#page-title').inner_text()=='С кем связаться'
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
            page.locator('#section-navigation [data-view=segments]').focus();page.keyboard.press('Enter')
            assert page.locator('#page-title').inner_text()=='Группы покупателей'
            assert page.locator('#section-navigation [data-view=segments]').evaluate('el=>el===document.activeElement')
            # Long translated labels remain inside each page on a small phone.
            page.set_viewport_size({'width':320,'height':740})
            for view in ['retention','opportunities','manual']:
                page.click('[data-view='+view+']')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),view
            page.click('[data-view=products]');page.wait_for_selector('#content .panel')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'products'
            page.click('[data-view=overview-report]');page.wait_for_selector('.mini-list')
            for view in ['seasonality','geography','ga4']:
                page.click('[data-view='+view+']')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),view
            page.click('[data-view=overview]');page.wait_for_selector('.dash-board');page.wait_for_function('() => !dashboardState.loading')
            page.set_viewport_size({'width':1280,'height':720})
            # A late market response must not clear a sales report opened meanwhile.
            held=[]
            def hold_market(route):
                if parse_qs(urlparse(route.request.url).query).get('route')==['/api/competitors']:
                    held.append(route)
                else: route.continue_()
            page.route('**/index.php?*',hold_market)
            page.click('[data-dash-action=reload]');page.wait_for_timeout(150)
            assert len(held)==1
            page.click('[data-view=customers]');page.wait_for_selector('.customer-table')
            held[0].continue_();page.wait_for_function('() => !dashboardState.loading')
            page.unroute('**/index.php?*',hold_market)
            assert page.evaluate('state.report!==null'),'Late dashboard request must preserve current report'
            page.fill('#search','Синтетический');page.wait_for_timeout(180)
            assert page.locator('.customer-table tbody tr').count()>0
            page.click('[data-view=overview]');page.wait_for_selector('.dash-board');page.wait_for_function('() => !dashboardState.loading')
            assert mutations==[],mutations
            # A legacy CRM exclusion invalidates the revision-keyed dashboard projection.
            cid=result['actions'][0]['customer_id']
            stop=page.request.put(base+f'/index.php?route=/api/customers/{cid}/action',headers={"Origin":origin},data={"stage":"do_not_contact","note":"synthetic"})
            assert stop.ok,stop.text()
            newer=page.request.get(base+'/index.php?route=/api/dashboard&currency=UAH').json()
            assert cid not in [a['customer_id'] for a in newer['actions']]
            # Saved data is preserved, but a failed/stale sales sync suppresses CRM urgency.
            with sqlite3.connect(data/'analytics.sqlite3') as db:
                db.execute('INSERT OR REPLACE INTO sync_status VALUES(1,?)',(json.dumps({'state':'error','last_success':'2020-01-01T00:00:00Z'}),))
            page.click('[data-dash-action=reload]');page.wait_for_function("() => !dashboardState.loading")
            assert 'требуют обновления' in page.locator('.dash-period').inner_text()
            assert page.locator('.dash-tasks [data-id^=customer]').count()==0
            assert not errors,errors
            assert not external,external
            browser.close()
            (ARTIFACTS/'validation.json').write_text(json.dumps({'viewports':measures,'external_requests':external,'javascript_errors':errors,'writes_from_dashboard':mutations,'stale_public_assets_bypassed':True,'source_content_fingerprints':'verified'},ensure_ascii=False,indent=2))
            print('Dashboard auth, current fingerprinted assets despite stale public copies, saved data, comparison, cache, CRM, market variants, privacy, navigation and responsive checks passed')
    finally:
        subprocess.run(['docker','stop',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:server.wait(timeout=10)
        except subprocess.TimeoutExpired:server.kill()
