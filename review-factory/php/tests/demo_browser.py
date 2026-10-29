"""Standalone storefront module against synthetic native-theme markup and runtime-generated data."""
import json
import os
import shutil
import copy
from urllib.parse import urlparse,parse_qs
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[2]
assets=root/'demo-ocstore/upload/catalog/view/javascript'
payload=json.loads(Path(os.environ['RF_DEMO_PAYLOAD']).read_text())
out=Path(os.environ.get('RF_TEST_OUTPUT','/tmp/rf-demo-browser'));out.mkdir(parents=True,exist_ok=True)
checks=[]
def check(ok,message):
    assert ok,message
    checks.append(message)

structured='{"@context":"https://schema.org","@type":"Product","aggregateRating":{"@type":"AggregateRating","ratingValue":4.2,"reviewCount":9}}'
html='''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://test.lavka-speciy.com/"><link rel="stylesheet" href="catalog/view/javascript/review_factory_demo.css"><style>body{font:14px Arial;margin:16px}#content{max-width:1100px;margin:auto}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.product-thumb{border:1px solid #bbb;border-radius:10px;padding:8px;height:120px;overflow:hidden}.native-rating{color:#6a4600}.tab-content{border:1px solid #bbb;padding:12px;margin:16px 0}</style><script type="application/ld+json">'''+structured+'''</script></head><body><main id="content"><h1>TEST · QA product</h1><div class="native-rating" id="native-rating">★ 4,20 · 9 отзывов покупателей</div><div id="product"><input type="hidden" name="product_id" value="1"><button id="button-cart" onclick="cart.add('1')">В корзину</button></div><div class="tab-content"><div id="tab-review">Оригинальная лента покупательских отзывов</div></div><div class="grid"><article class="product-thumb" data-product-id="1"><h2>QA product 1</h2><span class="native-rating">★ 4,20 · 9 отзывов</span></article><article class="product-thumb" data-product-id="2"><h2>QA product 2</h2><span class="native-rating">Нет покупательских оценок</span></article></div></main><script>window.cart={add:()=>window.cartCalls=(window.cartCalls||0)+1};</script><script src="catalog/view/javascript/review_factory_demo.js"></script></body></html>'''

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('chromium'),args=['--no-sandbox'])
    context=browser.new_context(viewport={'width':390,'height':844},locale='ru-RU')
    state={'payload':payload,'failed':False,'api_calls':0,'catalog_only':False};errors=[]
    def serve(route):
        url=route.request.url
        if 'route=extension%2Fmodule%2Freview_factory_demo' in url:
            state['api_calls']+=1
            data=copy.deepcopy(state['payload']);detail=parse_qs(urlparse(url).query).get('detail',[None])[0]
            for key,item in data.get('products',{}).items():
                if str(key)!=detail:item['entries']=[]
            route.fulfill(status=503 if state['failed'] else 200,content_type='application/json',body=json.dumps(data,ensure_ascii=False));return
        if '/review_factory_demo.js' in url:
            route.fulfill(content_type='application/javascript; charset=utf-8',body=(assets/'review_factory_demo.js').read_text());return
        if '/review_factory_demo.css' in url:
            route.fulfill(content_type='text/css',body=(assets/'review_factory_demo.css').read_text());return
        if '/qa-demo' in url:
            route.fulfill(content_type='text/html; charset=utf-8',body=html.replace('id="product"','id="catalog-root"') if state['catalog_only'] else html);return
        route.abort()
    context.route('**/*',serve)
    page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto('https://test.lavka-speciy.com/qa-demo');page.locator('#rf-demo-product .rf-demo-entry').wait_for()
    check(page.locator('.rf-demo-badge').count()==1,'Only an approved product receives a catalog demo badge')
    check('синтетические' in page.locator('.rf-demo-badge').inner_text(),'Catalog score is explicitly synthetic')
    check(page.locator('#native-rating').inner_text()=='★ 4,20 · 9 отзывов покупателей','Native buyer rating and count remain unchanged')
    check(page.locator('script[type="application/ld+json"]').text_content()==structured,'Native structured rating is untouched')
    check(page.locator('.rf-demo-panel [itemprop],.rf-demo-panel script[type="application/ld+json"]').count()==0,'Demo panel contains no rating schema markup')
    check('Вымышленный автор:' in page.locator('.rf-demo-entry h3').inner_text(),'Author identity is labelled fictional')
    check(page.locator('.rf-demo-body img').count()==0 and '<img' in page.locator('.rf-demo-body').inner_text(),'Synthetic body is rendered as literal text')
    check(page.locator('#tab-review').inner_text()=='Оригинальная лента покупательских отзывов','Demo panel does not replace native reviews')
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'390px storefront remains within viewport')
    visible=page.locator('.product-thumb').first.evaluate('(el)=>el.querySelector(".rf-demo-badge").getBoundingClientRect().bottom<=el.getBoundingClientRect().bottom+1')
    check(visible,'Catalog demo badge remains visible on a fixed-height native card')
    page.locator('#button-cart').click();check(page.evaluate('window.cartCalls')==1,'Cart handler is preserved')
    page.screenshot(path=str(out/'demo-storefront-mobile.png'),full_page=True)
    page.evaluate("""()=>{const c=document.createElement('article');c.className='product-thumb';const title=document.createElement('h2');title.textContent='QA dynamic card';c.append(title);const b=document.createElement('button');b.setAttribute('onclick',"cart.add('1')");b.textContent='Buy';c.append(b);document.querySelector('.grid').append(c);}""")
    page.wait_for_function('()=>document.querySelectorAll(".rf-demo-badge").length===2')
    check(state['api_calls']==1,'Dynamic cards reuse the approved data without repeated requests')
    page.set_viewport_size({'width':1440,'height':1000});page.screenshot(path=str(out/'demo-storefront-desktop.png'),full_page=True)
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Desktop storefront fits viewport')
    state['catalog_only']=True;page.reload();page.locator('.rf-demo-badge').wait_for()
    check(page.locator('.rf-demo-panel').count()==0,'Catalog summaries do not render a detail panel')
    before=state['api_calls']
    page.evaluate("""()=>{const root=document.createElement('div');root.id='product';const input=document.createElement('input');input.name='product_id';input.value='1';root.append(input);document.getElementById('content').append(root);}""")
    page.locator('#rf-demo-product .rf-demo-entry').wait_for()
    check(state['api_calls']==before+1,'Opening an Ajax detail requests text after an earlier catalog summary')
    state['catalog_only']=False
    state['payload']={'environment':'test','label':'DEMO','products':{}}
    page.reload();page.wait_for_load_state('networkidle')
    check(page.locator('.rf-demo-panel,.rf-demo-badge').count()==0,'Withdrawn data produces no demo score or empty demo section')
    state['failed']=True;page.reload();page.wait_for_load_state('networkidle')
    check('9 отзывов' in page.locator('#native-rating').inner_text(),'API failure leaves the normal storefront usable')
    calls=state['api_calls'];page.goto('https://lavka-speciy.com/qa-demo');page.wait_for_load_state('networkidle')
    check(state['api_calls']==calls and page.locator('.rf-demo-panel,.rf-demo-badge').count()==0,'Production hostname never requests or renders demo data')
    check(not errors,'No JavaScript errors')
    browser.close()
print(json.dumps({'state':'ok','checks':len(checks),'widths':[390,1440]},ensure_ascii=False))
