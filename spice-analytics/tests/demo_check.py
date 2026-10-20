"""Render the self-contained delivery HTML without using the sandbox's blocked file:// navigation."""
from pathlib import Path
import json
import re
import sqlite3
import sys
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
path=Path(sys.argv[1]) if len(sys.argv)>1 else ROOT.parent/'lavka-demo.html'
text=path.read_text()
start=text.index('const DEMO_DATA=')+len('const DEMO_DATA=')
payload,_=json.JSONDecoder().raw_decode(text,start)
for c in payload['report']['customers']:
    assert c['name']=='Покупатель '+c['id'] and not c['email'] and not c['phone'] and not c['emails'] and not c['phones']
    assert 'note' not in c.get('action',{})
for order in payload['orders'].values():
    assert not order['email'] and not order['phone']
    assert not any(key in order for key in ('ip','forwarded_ip','shipping_address_1','payment_address_1'))
with sqlite3.connect(ROOT/'data/analytics.sqlite3') as con:
    actual_ips={json.loads(row[0])['ip'] for row in con.execute("SELECT payload FROM raw_records WHERE table_name='oc_order'")}
found_ips=set(re.findall(r'(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])',text))
assert not actual_ips & found_ips
errors=[];network=[]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1536,'height':1000})
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('request',lambda r:network.append(r.url) if r.url.startswith(('http:','https:')) else None)
    page.set_content(text,wait_until='load')
    page.wait_for_selector('.customer-table .identity-name')
    page.screenshot(path=str(ROOT.parent/'lavka-preview.png'))
    page.click('[data-action=order-preset][data-min="5"]')
    assert page.evaluate('customerRows().length>0&&customerRows().every(c=>c.lifetime_count>=5)')
    page.click('[data-action=clear-customer-filters]')
    page.locator('[data-action=customer]').first.click();page.wait_for_selector('#action-form')
    assert page.locator('#action-form button').is_disabled()
    page.click('[data-action=close-dialog]')
    for view in ('retention','segments','actions','opportunities','seasonality','geography','ga4'):
        page.click(f'[data-view={view}]');page.wait_for_selector('.panel')
        if view=='retention':assert page.locator('.cohort-table tbody tr').count()==18
        if view=='geography':page.wait_for_selector('#map .map-region');assert page.locator('#map .map-region').count()==27
        if view=='ga4':assert page.locator('[data-action=import-ga4]').is_disabled()
    page.set_viewport_size({'width':390,'height':844})
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    browser.close()
assert not errors,errors
assert not network,network
print(json.dumps({'offline_demo':'passed','customer_contact_and_ip_privacy':'passed','network_requests':0,'javascript_errors':0},ensure_ascii=False))
