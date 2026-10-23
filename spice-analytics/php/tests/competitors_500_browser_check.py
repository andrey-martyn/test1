"""Exact 500 g / packaging and B50 draft checks with synthetic saved observations."""
import json
import os
from pathlib import Path
from datetime import datetime, timezone

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('ANALYTICS_TEST_OUTPUT', '/workspace/work/competitors/500-browser'))
OUT.mkdir(parents=True, exist_ok=True)
NOW = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
SOURCES = [dict(id=s, name=n, role='own' if s == 'lavka' else 'competitor') for s, n in [
    ('lavka', 'Лавка специй'), ('deluxe', 'Deluxe'), ('spicery', 'Spicery Shop'),
    ('banka', 'Банка Спецій'), ('specii', 'Лавка прянощів')]]


def offer(price, grams, packaging='unknown', stock='in_stock', raw=None, variant=None):
    value = dict(key=variant or f'{packaging}-{grams}', label=f'{packaging} {grams} г', price=str(price),
                 old_price=None, grams=grams, packaging=packaging, currency='UAH', unit='pack',
                 availability=stock, min_quantity=1, condition='')
    if raw is not None:
        value['provenance'] = dict(raw_price=str(raw), currency_decimal_places=0)
    if variant:
        value['variant_id'] = variant
    return value


def target(tid, source, product, offers, own=False):
    return dict(id=tid, source_id=source, kind='product', enabled=True, label=f'Товар {product}',
                url=(f'https://test.lavka-speciy.com/index.php?route=product%2Fproduct&product_id={product}'
                     if own else f'https://{source}.example.invalid/product-{product}'),
                match_group=f'product-{product}', match_confirmed=True, pack_label='50 г, 500 г',
                packaging='auto', last_success=NOW, last_attempt=dict(state='ok'),
                observation=dict(offers=offers, warnings=[]))


main_packs = [offer('100', 50, raw='100.4999'), offer('900', 500, raw='900.4900')]
targets = [
    target(1, 'lavka', 990, [], True),
    target(2, 'spicery', 990, [offer('825', 500, 'doypack', variant='doy500'),
                            offer('750', 500, 'zip', variant='zip500'), offer('80', 50, 'doypack')]),
    target(3, 'deluxe', 990, [offer('850', 500), offer('90', 50)]),
    target(4, 'banka', 990, [offer('810', 500, stock='unknown')]),
    target(5, 'specii', 990, [offer('830', 500, 'kraft'), offer('700', 500, 'zip')]),
    target(6, 'lavka', 691, [], True),
    target(7, 'spicery', 691, [offer('300', 500, 'zip')]),
    target(8, 'lavka', 692, [], True),
    target(9, 'deluxe', 692, [offer('320', 500, 'zip', variant='zip-other'),
                            offer('300', 500, 'jar', variant='jar-other')]),
    target(10, 'lavka', 693, [], True),
    target(11, 'deluxe', 693, [offer('470', 500)]),
]
data = dict(sources=SOURCES, targets=targets, changes=[], discoveries=[], runs=[], server_now=NOW,
            environment=dict(mode='sandbox'), own_catalog=dict(state='ok', environment='sandbox',
            source_database='lavkasp_test', last_success=NOW, currency_decimal_places=0, products=[
                dict(target_id=1, product_id=990, label='Товар 990', offers=main_packs),
                dict(target_id=6, product_id=691, label='Товар 691', offers=[offer('50', 50), offer('400', 500)]),
                dict(target_id=8, product_id=692, label='Товар 692', offers=[offer('65', 50), offer('450', 500)]),
                dict(target_id=10, product_id=693, label='Товар 693', offers=[offer('70', 50), offer('72', 50), offer('500', 500)]),
            ]), sandbox_pricing=dict(enabled=False, can_write=False))
helpers = """
const state={view:'competitors',customer:null};
const $=selector=>document.querySelector(selector);
const esc=value=>String(value??'').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));
const num=(value,digits=0)=>Number(value).toLocaleString('ru-RU',{maximumFractionDigits:digits});
const toast=()=>{};
const openDrawer=label=>{$('#drawer-label').textContent=label;$('#drawer').showModal();};
const api=()=>{throw Error('Unexpected API request in saved-data preview');};
"""
css = (ROOT / 'static/style.css').read_text() + '\n' + (ROOT / 'static/competitors.css').read_text()
script = (ROOT / 'static/competitors.js').read_text()


def main():
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        page = browser.new_page(viewport=dict(width=1440, height=1000))
        errors, requests = [], []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.on('request', lambda r: requests.append(r.url))
        page.set_content('<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>' + css +
                         '</style></head><body class="competitors-mode" data-environment="sandbox">'
                         '<main><div id="content"></div></main><dialog id="drawer"><span id="drawer-label"></span>'
                         '<div id="drawer-content"></div></dialog></body></html>')
        page.add_script_tag(content=helpers)
        page.add_script_tag(content=script)
        page.evaluate('(data)=>{competitorState.data=data;competitorsRender();}', data)
        assert page.locator('#competitor-matrix-pack').input_value() == '500'
        assert page.evaluate("competitorPackaging({packaging:'unknown',label:'Дой-пак 500 г'})") == 'unknown'
        assert page.evaluate("competitorPackaging({label:'Дой-пак крафт 500 г'})") == 'unknown'
        assert page.evaluate("competitorPackaging({label:'Дой-пак 500 г'})") == 'doypack'
        row = page.locator('tr[data-matrix-row="product-990|500"]')
        assert row.locator('td[data-source=spicery]').get_attribute('data-price') == '825.00'
        assert 'Дой-пак · 500 г' in row.locator('td[data-source=spicery]').inner_text()
        assert row.locator('td[data-source=specii]').get_attribute('data-price') == '830.00'
        assert row.locator('td[data-source=banka]').get_attribute('data-price') is None
        assert row.locator('td[data-source=spicery]').evaluate("el=>el.classList.contains('is-best')")
        assert not row.locator('td[data-source=banka]').evaluate("el=>el.classList.contains('is-best')")
        row.locator('td[data-source=spicery] [data-comp-action=matrix-detail]').click()
        assert page.locator('#drawer .selected-variant').count() == 1
        assert 'doypack 500 г' in page.locator('#drawer .selected-variant').inner_text()
        assert page.locator('#drawer .other-variant').count() == 2
        page.evaluate("document.getElementById('drawer').close()")
        missing = page.locator('tr[data-matrix-row="product-691|500"] td[data-source=spicery]')
        assert missing.get_attribute('data-price') is None
        assert 'Дой-пак' in missing.inner_text()
        ambiguous = page.locator('tr[data-matrix-row="product-692|500"] td[data-source=deluxe]')
        assert ambiguous.get_attribute('data-price') is None
        assert 'Выберите тип упаковки' in ambiguous.inner_text()
        duplicate_base = page.locator('tr[data-matrix-row="product-693|500"]')
        assert duplicate_base.locator('[data-matrix-select]').is_disabled()
        assert 'однозначная цена 50 г' in duplicate_base.inner_text()

        row.locator('[data-matrix-select]').check()
        page.click('[data-comp-action=matrix-preview]')
        row = page.locator('tr[data-matrix-row="product-990|500"]')
        assert row.locator('.competitor-suggestion').get_attribute('data-price') == '753.68'
        assert row.locator('.competitor-suggestion').get_attribute('data-base-50') == '100.49'
        assert 'Ограничено нашей текущей ценой 50 г' in row.inner_text()
        assert page.evaluate('competitorSandboxSelection()') == [dict(target_id=1, base_50='100.49')]
        chain = page.locator('tr[data-chain-row="product-990|500"]')
        assert chain.locator('[data-chain-grams="500"]').get_attribute('data-chain-price') == '753.68'
        assert chain.locator('[data-chain-grams="50"]').get_attribute('data-chain-price') == '100.49'

        # Raw500 cap survives a higher raw50 and whole-hryvnia storefront rounding.
        page.evaluate("""()=>{const p=competitorState.data.own_catalog.products[0];p.offers[0].provenance.raw_price='150.0000';p.offers[0].price='150';p.offers[1].provenance.raw_price='800.4999';p.offers[1].price='800';competitorsRender();}""")
        assert page.evaluate("competitorSuggestion(competitorMatrixRows().find(r=>r.key==='product-990|500')).base50") == 106.73
        assert page.evaluate("competitorSuggestion(competitorMatrixRows().find(r=>r.key==='product-990|500')).price") == 800.48

        # A chosen package can be changed explicitly; source defaults never mix types.
        page.evaluate("""()=>{competitorState.data.targets.find(t=>t.id===9).packaging='jar';competitorsRender();}""")
        assert page.locator('tr[data-matrix-row="product-692|500"] td[data-source=deluxe]').get_attribute('data-price') == '300.00'
        page.evaluate("competitorOpenForm(competitorState.data.targets.find(t=>t.id===2))")
        page.select_option('select[name=packaging]', 'zip')
        body = page.evaluate('competitorBody(competitorState.draft)')
        assert body['packaging'] == 'zip'
        assert body['source_id'] == 'spicery'
        page.click('[data-comp-action=cancel-form]')

        # Selecting50 replaces500 for the same product, keeping the server selection unique.
        page.select_option('#competitor-matrix-pack', 'all')
        page.locator('tr[data-matrix-row="product-990|50"] [data-matrix-select]').check()
        assert page.locator('tr[data-matrix-row="product-990|500"] [data-matrix-select]').is_checked() is False
        assert page.evaluate('competitorSandboxSelection()') == [dict(target_id=1, base_50='78.40')]
        page.select_option('#competitor-matrix-pack', '500')
        page.click('[data-comp-action=matrix-select-ready]')
        chosen = page.evaluate('competitorSandboxSelection()')
        assert len({item['target_id'] for item in chosen}) == len(chosen)
        assert all(item['target_id'] != 10 for item in chosen)
        page.click('[data-comp-action=matrix-preview]')

        for width in (1440, 390, 320):
            page.set_viewport_size(dict(width=width, height=900))
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), width
            scroll = page.locator('.competitor-matrix-scroll')
            assert scroll.evaluate('el=>el.scrollWidth>el.clientWidth')
            if width <= 390:
                assert row.locator('[data-matrix-select]').locator('..').bounding_box()['height'] >= 44
        page.set_viewport_size(dict(width=390, height=844))
        page.screenshot(path=str(OUT / 'comparison-500g-iphone13.png'), full_page=True)
        page.set_viewport_size(dict(width=1440, height=1000))
        page.screenshot(path=str(OUT / 'comparison-500g-desktop.png'), full_page=True)
        assert not errors, errors
        assert not requests, requests
        browser.close()
    print(json.dumps(dict(exact_500g='passed', packaging_before_market_minimum='passed',
                          raw_50_and_500_caps='passed', unique_product_selection='passed',
                          packaging_form='passed', mobile_390_and_320='passed', network_requests=0)))


if __name__ == '__main__':
    main()
