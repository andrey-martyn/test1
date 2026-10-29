"""Real UniShop cart/gallery handlers with local HTTP only; never touches a shop."""
import os
import json
import re
import subprocess
from pathlib import Path
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
THEME=ROOT/'store/catalog/view/theme/unishop2'
TEMPLATE=Path(os.environ.get('LAVKA_TEST_PRODUCT_TWIG') or ROOT/'tests/fixtures/product_page_active.twig').read_text()
NATIVE=Path(os.environ['LAVKA_TEST_BOOTSTRAP_JS']).resolve().parent.parent
BOOTSTRAP=(NATIVE/'js/bootstrap.min.js').read_text()
COMMON=(THEME/'js/common.js').read_text()
SCROLL=COMMON[COMMON.index('function uniScrollTo('):COMMON.index('function uniChangeBtn(')]
QTY=COMMON[COMMON.index("$('body').on('blur click', '.qty-switch__input"):COMMON.index("$('body').on('mouseenter touchstart', '.option__img'")]
CART=TEMPLATE[TEMPLATE.index("$('html body').on('click', '#button-cart:not(.disabled)'"):TEMPLATE.index("$('button[id^=\\'button-upload\\']')")]
CART=re.sub(r'\{\{.*?\}\}','UAH',CART)
CART_START=TEMPLATE.index("$('html body').on('click', '#button-cart:not(.disabled)'")
VIEW=TEMPLATE[TEMPLATE.rfind('<script>',0,CART_START)+len('<script>'):CART_START]
VIEW_VALUES={'microdata.code':'UAH','product_id':'816','microdata.name':'Test spice','microdata.manufacturer':'Lavka','microdata.category':'Spices','microdata.price':'45'}
VIEW=re.sub(r'\{\{\s*(.*?)\s*\}\}',lambda match:VIEW_VALUES[match.group(1)],VIEW)
HAS_VIEW='dataLayer.push' in VIEW
GALLERY=TEMPLATE[TEMPLATE.index('let img_array = []'):TEMPLATE.index('</script>',TEMPLATE.index('let img_array = []'))]
GALLERY=re.sub(r'\{%.*?%\}','',GALLERY,flags=re.S);GALLERY=re.sub(r'\{\{.*?\}\}','0',GALLERY,flags=re.S)
REVIEW_START=TEMPLATE.index("$('.tab-review').on('click mouseover'")
REVIEW=TEMPLATE[REVIEW_START:TEMPLATE.index("$('#review').on('click', '.pagination a'",REVIEW_START)].replace('{{ product_id }}','816')
CSS=''.join((THEME/'stylesheet'/name).read_text() for name in ('bootstrap.min.css','stylesheet.css'))
CSS+=(NATIVE/'stylesheet/font-awesome.min.css').read_text()+(NATIVE/'stylesheet/generated.0.css').read_text()
CSS+=(NATIVE/'stylesheet/flymenu.css').read_text()
CSS+=''.join((THEME/'stylesheet'/name).read_text() for name in ('lavka-mobile-cards.css','lavka-product-tags.css','lavka-card-reviews.css','lavka-mobile-product.css'))
PANEL_REFERENCE=json.loads((ROOT/'docs/product-page-panel-reference.json').read_text())

def merged_css(css):
    # Exercise the actual installed UniShop regex compressor, not a replacement.
    tool=(NATIVE.parents[3]/'catalog/controller/extension/module/uni_tool.php').read_text()
    begin=tool.index("$contents = preg_replace( '!/\\*[^*]")
    end=tool.index('$contents = trim($contents);',begin)+len('$contents = trim($contents);')
    compressor=tool[begin:end]
    assert compressor.count('$contents = preg_replace')==8
    program='$contents=file_get_contents("php://stdin");'+compressor+'echo $contents;'
    return subprocess.run([os.environ.get('LAVKA_TEST_PHP_CLI','php'),'-r',program],input=css,
                          stdout=subprocess.PIPE,universal_newlines=True,check=True).stdout

PHOTO='data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="600" height="600"%3E%3Crect width="600" height="600" fill="%23f1f4e9"/%3E%3C/svg%3E'
PHOTO2=PHOTO.replace('f1f4e9','ede6d7')

def html(kind='radio',extra=False,disabled=False,css=CSS):
    weights=[('50','50 г',0),('100','100 г',45),('200','200 г',115),('500','500 г',305),('1000','1 кг',575)]
    if kind=='select':
        fields='<select name="option[70]" id="input-option70" class="option__select"><option value="">Оберіть</option>'+''.join('<option value="%s" data-prefix="+" data-price="%s">%s</option>'%(value,delta,label) for value,label,delta in weights)+'</select>'
    else:
        fields='<div id="input-option70">'+''.join('<label class="option__item"><input type="radio" class="hidden" name="option[70]" value="%s" data-prefix="+" data-price="%s"><span class="option__name">%s</span></label>'%(value,delta,label) for value,label,delta in weights)+'</div>'
    option='<div class="product-page__option option row"><div class="option__group col-xs-12"><label class="option__group-name">* Фасовка:</label>'+fields+'</div>'
    if extra:option+='<div class="option__group col-xs-12"><label class="option__group-name">Напис</label><input type="text" name="option[71]" id="input-option71" class="form-control"></div>'
    option+='</div>'
    return '''<!doctype html><html lang="uk"><head><meta charset="utf-8"><style>'''+css+'''</style></head><body><header style="height:64px;padding:16px;color:#4f7f28;border-bottom:1px solid #eee">☰　Лавка Спецій<a href="/terms" hidden>Замовлення, оплата та доставка</a></header><main><div id="product" class="container"><ul class="breadcrumb mobile"><li><a href="/">Головна</a></li><li>Суміші прянощів</li></ul><div class="row"><div id="content" class="col-sm-12"><div class="heading-h1"><h1>Часникова сіль з травами</h1></div><div class="row"><div class="product-page col-xs-12"><div class="row"><div class="product-page__image col-sm-6 col-lg-5"><div class="product-page__image-main uni-item"><div class="sticker"><div class="sticker__item lavka-hit" style="--lavka-sticker-bg:#fff100;background:#fff100;color:black">Хіт продажів</div></div><div class="product-page__image-main-carousel"><a class="product-page__image-main-a" href="/photo1"><img class="img-responsive" src='''+"'"+PHOTO+"'"+'''></a><a class="product-page__image-main-a" href="/photo2"><img class="img-responsive" src='''+"'"+PHOTO2+"'"+'''></a></div><button type="button" class="product-page__wishlist-btn wishlist" title="В обране" onclick="window.favorites++">♡</button></div><div class="product-page__image-addit owl-carousel"><img class="product-page__image-addit-img selected" data-thumb='''+"'"+PHOTO+"'"+''' data-full="/photo1" src='''+"'"+PHOTO+"'"+'''><img class="product-page__image-addit-img" data-thumb='''+"'"+PHOTO2+"'"+''' data-full="/photo2" src='''+"'"+PHOTO2+"'"+'''></div></div><div class="col-sm-6 col-lg-6 col-lg-push-1 product-block"><div class="product-page__rating rating"><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i> &nbsp; <a class="product-page__rating-a" onclick="$('a[href=\\'#tab-review\\']').click();scroll_to('#tab-review');return false;">Відгуки: 1</a><br></div><div class="prod_smalldescr">З нашою приправою дуже просто приготувати смачний соус: додайте чайну ложку в сметану. Підходить до свіжих овочів, печеної картоплі та домашніх грінок.</div><div class="product-data"><div class="product-data__item manufacturer"><div class="product-data__item-div"><span class="product-data__item-span">Виробник:</span></div><a href="/brand">Єгипет</a></div><div class="product-data__item stock"><div class="product-data__item-div"><span class="product-data__item-span">Наявність:</span></div>'''+('Немає в наявності' if disabled else 'В наявності')+'''</div></div><br><div class="product-page__price price" data-price="45" data-special="0" data-eurcurr="50">45 грн</div><div class="product-page__eur-price"><span class="eur-price">0.9 €</span> *</div>'''+option+'''<div class="product-page__cart"><input type="hidden" name="product_id" value="816"><div class="qty-switch"><input type="text" name="quantity" value="1" data-minimum="1" id="input-quantity" class="qty-switch__input form-control"><div><i class="qty-switch__btn fa fa-plus btn-default">+</i><i class="qty-switch__btn fa fa-minus btn-default">−</i></div></div><button type="button" class="product-page__add-to-cart add_to_cart btn btn-lg'''+(' disabled' if disabled else '')+'''" data-pid="816" id="button-cart"'''+(' disabled' if disabled else '')+'''><i>🛒</i><span>Купити</span></button></div><div class="product-page__share">Поділитися</div></div></div></div></div><ul class="nav nav-tabs"><li class="active"><a href="#tab-description" data-toggle="tab">Опис</a></li><li><a href="#tab-specification" data-toggle="tab">Характеристики</a></li><li><a class="tab-review" href="#tab-review" data-toggle="tab">Відгуки</a></li></ul><div class="tab-content"><div class="tab-pane active" id="tab-description" style="min-height:1300px"><p>Про товар. Повний опис і поради щодо використання.</p></div><div class="tab-pane" id="tab-specification" style="min-height:900px">Склад: прянощі.</div><div class="tab-pane" id="tab-review" style="min-height:900px"><div id="review"></div><form id="form-review">Штатна форма</form></div></div></div></div></div></main><footer style="height:150px">Лавка Спецій</footer></body></html>'''

RUNTIME="""window.uniJsVars={currency:{decimal:0,decimal_p:'.',thousand_p:' ',symbol_l:'',symbol_r:' грн'},qty_switch_step:false,cart_popup_disable:true,cart_popup_autohide:false,cart_btn:{}};window.dataLayer=[];window.gaCalls=[];window.gtag=function(){gaCalls.push(Array.from(arguments));};window.favorites=0;window.popups=[];window.uniFlyAlert=function(){};window.uniModalWindow=function(){};$.magnificPopup={open:function(data){popups.push(data.items);}};"""


def start(browser,width=390,kind='radio',extra=False,disabled=False,enhance=True,single=False,merged=False):
    page=browser.new_page(viewport={'width':width,'height':844});page.set_default_timeout(7000)
    page.errors=[];page.posts=[];page.pending=[];page.terms_requests=[];page.review_requests=[]
    page.on('pageerror',lambda error:page.errors.append(str(error)))
    page.route('**/*',lambda route:route.abort())
    page.route('https://fixture.invalid/fonts/*',lambda route:route.fulfill(status=200,content_type='font/woff2',body=(NATIVE/'fonts'/route.request.url.rsplit('/',1)[-1]).read_bytes()))
    def terms(route):
        page.terms_requests.append(route.request.url)
        route.fulfill(status=200,content_type='text/html; charset=utf-8',body='<div id="content"><div class="article_description"><h2>Доставка</h2><p>Чинні умови магазину.</p><ul><li>Нова пошта</li></ul><h2>Оплата</h2><p>Умови оплати з інформаційної сторінки.</p><script>window.unexpectedTermsScript=true</script><img src="/never-fetch-this" onerror="window.unexpectedTermsScript=true"></div></div>')
    page.route('https://fixture.invalid/terms',terms)
    markup=html(kind,extra,disabled,css=merged_css(CSS) if merged else CSS)
    if single:
        markup=re.sub(r'<a class="product-page__image-main-a" href="/photo2">.*?</a>','',markup)
        markup=re.sub(r'<div class="product-page__image-addit owl-carousel">.*?</div>','',markup)
    page.route('https://fixture.invalid/product',lambda route:route.fulfill(status=200,content_type='text/html; charset=utf-8',body=markup))
    def cart(route):page.posts.append(parse_qs(route.request.post_data));page.pending.append(route)
    page.route('**/index.php?route=checkout/cart/add',cart)
    page.route('**/index.php?route=common/cart/info',lambda route:route.fulfill(status=200,body='<div></div>'))
    def reviews(route):
        page.review_requests.append(route.request.url)
        route.fulfill(status=200,content_type='text/html',body='<p>Опубліковані відгуки.</p>')
    page.route('**/index.php?route=product/product/review&product_id=816',reviews)
    page.goto('https://fixture.invalid/product')
    for script in ((THEME/'js/jquery-2.2.4.min.js').read_text(),BOOTSTRAP,RUNTIME,VIEW,(THEME/'js/owl.carousel.min.js').read_text(),QTY,SCROLL,CART,REVIEW,GALLERY,(THEME/'js/live-price.js').read_text(),(THEME/'js/lavka-card-reviews.js').read_text()):page.add_script_tag(content=script)
    page.wait_for_timeout(80)
    page.evaluate('document.fonts.ready')
    page.evaluate('document.querySelector(".product-page__image-main").dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:12,rating:4.8}}}))')
    page.before=page.locator('#product').evaluate('(el)=>el.outerHTML')
    if enhance:
        page.add_script_tag(content=(THEME/'js/lavka-mobile-product.js').read_text())
        if width<768:page.locator('.lavka-product-buy').wait_for()
        else:page.wait_for_timeout(80)
    return page


with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for width in (320,390,432,600,767,768,1280):
        page=start(browser,width)
        if width>=768:
            assert page.locator('#product').evaluate('(el)=>el.outerHTML')==page.before,'Desktop DOM must be identical'
            assert page.locator('.lavka-product-dock').count()==0
        else:
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'Horizontal overflow'
            assert page.locator('.product-data .stock').count()==0 and page.locator('.lavka-product-meta .stock').count()==1
            assert page.locator('.lavka-product-buy .product-page__option').count()==1
            assert page.locator('#button-cart').bounding_box()['y']<page.locator('.prod_smalldescr').bounding_box()['y']
            assert page.locator('.product-page__add-to-cart').bounding_box()['height']>=48
            assert page.locator('input[value="50"]').is_checked()
            assert page.locator('.breadcrumb').evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(245, 245, 245)'
            assert page.locator('.lavka-product-nav').evaluate('(e)=>getComputedStyle(e).borderTopLeftRadius')=='8px'
            assert page.locator('#button-cart span').is_visible(),'Main buy button must retain its text at every mobile width'
            assert page.locator('#button-cart').evaluate('(e)=>getComputedStyle(e,"::before").display')=='none'
            assert page.locator('.lavka-product-caption-eur').inner_text().strip()=='/ 0.9 €'
            assert not page.locator('.product-page__eur-price').is_visible(),'Old separate euro line is hidden only on mobile'
            boxes=page.locator('.lavka-product-choice').evaluate_all('(els)=>els.map(e=>({x:e.getBoundingClientRect().x,y:e.getBoundingClientRect().y,width:e.offsetWidth,height:e.offsetHeight}))')
            assert len(boxes)==5 and max(b['y'] for b in boxes)-min(b['y'] for b in boxes)<=1.5,'Five pack buttons must fit one photo row'
            assert min(b['width'] for b in boxes)>=44
            assert all(abs(b['width']-b['height'])<1 for b in boxes),'Pack controls must be circles'
            colors=page.locator('.lavka-product-choice').evaluate_all('(els)=>els.map(e=>getComputedStyle(e).backgroundColor)')
            assert colors[0]=='rgba(61, 100, 29, 0.82)' and colors[1]=='rgba(255, 255, 255, 0.4)','Same transparency as approved category'
            assert page.locator('.owl-item .lavka-product-choice').count()==0,'Owl must not clone pack controls'
            assert page.locator('.product-page__image-main .lavka-product-packs').count()==1
            assert page.locator('.product-page__rating-a').get_attribute('data-lavka-review-count')=='12'
            meta=page.locator('.lavka-product-meta').bounding_box()
            rating=page.locator('.product-page__rating').bounding_box()
            assert abs(rating['x']+rating['width']/2-meta['x']-meta['width']/2)<1,'Rating must be horizontally centered'
            assert page.locator('.product-page__image').bounding_box()['y']+page.locator('.product-page__image').bounding_box()['height']<=meta['y'],'Metadata belongs below gallery'
            assert meta['y']+meta['height']<page.locator('.product-page__price').bounding_box()['y'],'Metadata belongs above price'
            stock=page.locator('.lavka-product-meta .stock').bounding_box();brand=page.locator('.manufacturer').bounding_box()
            assert stock['x']<rating['x'] and brand['x']>rating['x']+rating['width']
            line=page.locator('.lavka-product-totals,.product-page__cart>.qty-switch,#button-cart').evaluate_all('(els)=>els.map(n=>n.getBoundingClientRect().y+n.getBoundingClientRect().height/2)')
            assert max(line)-min(line)<1,'Price, quantity and buy button must share a center line'
            assert page.locator('.product-data .manufacturer').count()==0
            assert page.locator('.lavka-product-nav button').all_text_contents()==['Про товар','Відгуки','Доставка й оплата']
            assert abs(page.locator('.breadcrumb a').first.bounding_box()['x']-page.locator('.heading-h1').bounding_box()['x'])<=1,'Breadcrumb and product title left edges must agree'
            assert page.locator('#button-cart').evaluate('(e)=>getComputedStyle(e).fontWeight')=='700'
            page.get_by_role('button',name='200 г',exact=True).click();page.wait_for_function('document.querySelector(".product-page__price").textContent.trim()==="160 грн"')
            page.locator('.lavka-product-qty-button[data-direction=plus]').press('Enter');page.wait_for_function('document.querySelector(".product-page__price").textContent.trim()==="320 грн"')
            assert page.locator('.lavka-product-pack-caption').text_content()=='за 2 × 200 г / 6.4 €'
            caption_y=page.locator('.lavka-product-pack-caption>span').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().y)')
            assert max(caption_y)-min(caption_y)<1,'Pack and euro belong on the same caption line'
            page.locator('.product-page__image-addit-img').nth(1).press('Enter');page.wait_for_timeout(300)
            assert page.locator('.product-page__image-addit-img').nth(1).evaluate('(e)=>e.classList.contains("selected")')
            page.locator('.product-page__image-main-carousel .owl-item.active a').click();assert len(page.evaluate('popups[0]'))==2
            page.locator('.product-page__wishlist-btn').click();assert page.evaluate('favorites')==1
            assert page.locator('#input-quantity').input_value()=='2','Leaving qty button must not change quantity again'
            page.locator('.product-page__rating-a').click();assert page.locator('#tab-review').is_visible();page.wait_for_timeout(500)
            page.evaluate('$("a[href=\\"#tab-description\\"]").tab("show");window.scrollTo(0,0)')
            page.evaluate("$('html, body').stop(true);window.scrollTo({top:0,behavior:'instant'})");page.wait_for_timeout(150)
            page.screenshot(path='/tmp/lavka-mobile-product-'+str(width)+'.png',full_page=True)
            # Returning to desktop restores original wrappers, positions and the SAME native fields.
            page.set_viewport_size({'width':1280,'height':844});page.wait_for_timeout(80)
            assert page.locator('.lavka-product-buy,.lavka-product-meta,.lavka-product-dock').count()==0
            assert page.locator('.product-block>.product-page__price').count()==1
            assert page.locator('.product-block .stock').count()==1
            assert page.locator('input[value="200"]').is_checked()
            assert page.locator('.nav-tabs').bounding_box()['y']>page.locator('.product-page').bounding_box()['y']
            assert page.locator('.nav-tabs a[href="#tab-description"]').inner_text()=='Опис'
        assert page.evaluate('dataLayer.filter(e=>e.ecommerce && e.ecommerce.detail).length')==int(HAS_VIEW)
        assert page.evaluate('gaCalls.filter(e=>e[1]==="view_item").length')==int(HAS_VIEW)
        assert not page.errors,page.errors
        print('PASS product layout/desktop, native packs, quantity, gallery, wishlist and tabs:',width,flush=True);page.close()

    page=start(browser)
    page.get_by_role('button',name='500 г',exact=True).click();page.wait_for_timeout(350)
    page.evaluate('window.scrollTo(0,document.querySelector("#button-cart").getBoundingClientRect().bottom+scrollY+80)')
    page.locator('.lavka-product-dock').wait_for(state='visible')
    assert page.locator('.lavka-product-dock__price').inner_text()=='350 грн'
    page.screenshot(path='/tmp/lavka-product-dock-v3.png')
    assert page.locator('.lavka-product-dock__count').inner_text()=='1'
    assert page.locator('.lavka-product-dock__qty-button[data-direction=minus]').is_disabled()
    page.locator('.lavka-product-dock__qty-button[data-direction=plus]').press('Enter')
    page.wait_for_function('document.querySelector(".lavka-product-dock__price").textContent==="700 грн"')
    assert page.locator('#input-quantity').input_value()=='2'
    assert 'за 2 × 500 г' in page.locator('.lavka-product-dock__price').get_attribute('aria-label')
    assert page.locator('.lavka-product-dock__count').inner_text()=='2'
    page.locator('.lavka-product-dock__qty-button[data-direction=minus]').click()
    page.wait_for_function('document.querySelector(".lavka-product-dock__price").textContent==="350 грн"')
    assert not page.locator('.lavka-product-dock__actions').is_visible(),'Narrow dock prioritizes price, quantity and buy'
    page.locator('.lavka-product-dock__buy').click();page.wait_for_timeout(150)
    assert len(page.posts)==1 and page.posts[0]['option[70]']==['500'] and page.posts[0]['quantity']==['1'],page.posts
    assert page.locator('#button-cart').is_disabled()
    assert page.locator('.lavka-product-dock__qty-button[data-direction=plus]').is_disabled()
    page.evaluate('document.querySelector(".lavka-product-dock__qty-button[data-direction=plus]").click()')
    assert page.locator('#input-quantity').input_value()=='1','Pending request locks both quantity controls'
    page.evaluate('document.getElementById("button-cart").click();document.querySelector(".lavka-product-dock__buy").click()')
    page.wait_for_timeout(5200);assert len(page.posts)==1
    page.pending.pop().fulfill(status=200,content_type='application/json',body='{"success":"Added","products":{}}')
    page.locator('#button-cart').wait_for(state='attached');page.wait_for_function('!document.getElementById("button-cart").disabled')
    assert page.locator('.lavka-product-status').inner_text()=='Додано до кошика'
    assert page.locator('.lavka-product-dock__qty-button[data-direction=plus]').is_enabled()
    assert page.evaluate('gaCalls.filter(e=>e[1]==="add_to_cart").length')==1
    assert page.evaluate('dataLayer.filter(e=>e.ecommerce && e.ecommerce.add).length')==1
    # Network failure surfaces a check-cart message and never retries a purchase automatically.
    page.locator('.lavka-product-dock__buy').click();page.wait_for_timeout(80)
    page.pending.pop().abort();page.wait_for_function('!document.getElementById("button-cart").disabled')
    assert page.locator('.lavka-product-status').get_attribute('data-error')=='true'
    assert len(page.posts)==2
    assert not page.errors,page.errors
    print('PASS sticky purchase: actual native POST, 5.2s pending guard, success/failure and no retries',flush=True);page.close()

    for kind,extra,disabled in (('select',False,False),('radio',True,False),('radio',False,True),('single',False,False)):
        page=start(browser,kind=kind,extra=extra,disabled=disabled,single=kind=='single')
        if kind=='single':
            assert page.locator('.product-page__image-main-a').count()==1
            assert page.locator('.product-page__image-addit').count()==0
            page.get_by_role('button',name='100 г',exact=True).click();page.wait_for_timeout(350)
            assert page.locator('.product-page__price').inner_text()=='90 грн'
            assert page.evaluate('popups.length')==0,'Choosing a photo-overlay pack must not open lightbox'
            page.locator('.product-page__image-main-a').click();assert len(page.evaluate('popups[0]'))==1
            page.evaluate('window.scrollTo(0,0)');page.wait_for_timeout(100)
            page.screenshot(path='/tmp/lavka-mobile-product-single-390.png',full_page=True)
        if kind=='select':
            page.get_by_role('button',name='500 г',exact=True).click();page.wait_for_timeout(350)
            assert page.locator('select[name="option[70]"]').input_value()=='500'
            assert page.locator('.product-page__price').inner_text()=='350 грн'
        if extra:
            assert page.locator('#input-option71').is_visible()
            assert page.locator('.lavka-product-packs').count()==0
            assert not page.locator('input[value="50"]').is_checked(),'Unknown/multiple groups keep native validation'
        if extra:
            page.locator('#button-cart').click();page.wait_for_timeout(80)
            page.pending.pop().fulfill(status=200,content_type='application/json',body='{"error":{"option":{"70":"Оберіть фасовку"}}}')
            page.wait_for_function('!document.getElementById("button-cart").disabled')
            assert page.locator('.text-danger').inner_text()=='Оберіть фасовку'
        if disabled:
            assert page.locator('#button-cart').is_disabled();assert page.locator('.lavka-product-dock__buy').is_disabled()
        assert not page.errors,page.errors
        page.close()
    print('PASS native select, extra required options, unavailable products and single-photo overlay',flush=True)

    page=start(browser)
    page.evaluate('document.querySelector(".product-page__image-main").dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:0,rating:0}}}))')
    assert not page.locator('.product-page__rating').is_visible(),'No invented rating without published reviews'
    page.locator('.lavka-product-review-empty').wait_for(state='visible')
    assert page.locator('.lavka-product-review-empty').inner_text()=='Написати відгук'
    meta=page.locator('.lavka-product-meta').bounding_box();empty=page.locator('.lavka-product-review-empty').bounding_box()
    assert abs(empty['x']+empty['width']/2-meta['x']-meta['width']/2)<1
    page.locator('.lavka-product-review-empty').click();page.wait_for_timeout(700)
    assert page.locator('#tab-review').is_visible() and len(page.review_requests)==1,'Write-review link opens native review section once'
    page.evaluate('document.querySelector(".product-page__image-main").dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:3,rating:4.6}}}))')
    page.wait_for_function('document.querySelector(".product-page__rating-a").dataset.lavkaReviewCount==="3"')
    assert page.locator('.lavka-review-stars__fill').get_attribute('style')=='width: 92%;'
    assert page.locator('.product-page__rating').is_visible() and not page.locator('.lavka-product-review-empty').is_visible()
    page.evaluate('var fly=document.createElement("div");fly.id="fly-menu";fly.className="show";fly.style="position:fixed;top:0;left:0;right:0;height:40px;z-index:999;background:white";document.body.appendChild(fly);window.scrollTo(0,350)')
    page.wait_for_timeout(150)
    assert abs(page.locator('.lavka-product-nav').bounding_box()['y']-40)<1,'Sticky tabs must sit below the actual floating shop menu'
    page.locator('.nav-tabs a[href="#tab-specification"]').click();page.wait_for_timeout(700)
    assert page.locator('#tab-specification').is_visible()
    assert page.locator('#tab-specification').bounding_box()['y']>=page.locator('.lavka-product-nav').bounding_box()['y']+38,'Tab content must not be hidden behind navigation'
    page.locator('.lavka-product-nav [data-section=about]').click();page.wait_for_timeout(700)
    assert page.locator('#tab-description').is_visible()
    page.set_viewport_size({'width':1280,'height':844});page.wait_for_timeout(80)
    assert page.locator('.lavka-product-packs').count()==0
    assert page.locator('.product-page__rating-a').get_attribute('data-lavka-review-count') is None
    assert page.locator('.product-page__rating-a').inner_text()=='Відгуки: 3'
    assert not page.errors,page.errors
    page.close()
    print('PASS sticky native sections, centered live stars/write-review fallback below photo and desktop restoration',flush=True)

    page=start(browser)
    page.locator('.lavka-product-nav [data-section=reviews]').click();page.wait_for_timeout(700)
    assert page.locator('#tab-review').is_visible() and page.evaluate('scrollY')>400
    assert len(page.review_requests)==1,'Top review navigation invokes the native loader once'
    assert page.locator('.lavka-product-nav [data-section=reviews]').get_attribute('aria-current')=='true'
    page.set_viewport_size({'width':1280,'height':844});page.wait_for_timeout(80)
    assert page.locator('.nav-tabs li.active a').get_attribute('href')=='#tab-review'
    assert page.locator('#tab-review').is_visible(),'Resizing preserves the active native tab'
    page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(80)
    page.locator('.lavka-product-nav [data-section=about]').click();page.wait_for_timeout(700)
    page.get_by_role('button',name='200 г',exact=True).click();page.wait_for_timeout(350)
    page.locator('.lavka-product-nav [data-section=delivery]').click()
    page.locator('.lavka-product-delivery').wait_for(state='visible')
    page.wait_for_function('document.querySelector(".lavka-product-delivery-shell").shadowRoot.querySelector(".lavka-product-delivery__body").textContent.includes("Чинні умови")')
    page.screenshot(path='/tmp/lavka-product-delivery-v3.png')
    assert page.url=='https://fixture.invalid/product' and len(page.terms_requests)==1,'Delivery opens current public terms without leaving product'
    assert page.locator('.lavka-product-delivery li').inner_text()=='Нова пошта'
    assert page.evaluate('window.unexpectedTermsScript') is None,'Fetched page scripts and handlers must not run'
    assert page.locator('.lavka-product-delivery script,.lavka-product-delivery img').count()==0
    assert not page.locator('.lavka-product-dock').is_visible()
    page.keyboard.press('Escape');page.locator('.lavka-product-delivery').wait_for(state='hidden')
    assert page.locator('input[value="200"]').is_checked() and page.locator('.product-page__price').inner_text()=='160 грн'
    assert page.locator('.lavka-product-nav [data-section=delivery]').evaluate('(e)=>document.activeElement===e'),'Dialog restores focus to opener'
    page.locator('.lavka-product-nav [data-section=delivery]').click();page.wait_for_timeout(80)
    assert len(page.terms_requests)==1,'Reopening reuses current page terms'
    page.locator('.lavka-product-delivery__close').click()
    page.evaluate('window.scrollTo(0,500)');page.wait_for_timeout(100)
    scroll_before=page.evaluate('scrollY')
    page.locator('.lavka-product-nav [data-section=delivery]').click();page.keyboard.press('Escape')
    assert abs(page.evaluate('scrollY')-scroll_before)<1,'Reading terms retains the product scroll position'
    page.locator('.lavka-product-nav [data-section=delivery]').click()
    page.set_viewport_size({'width':1280,'height':844});page.wait_for_timeout(100)
    assert page.locator('.lavka-product-delivery,.lavka-product-nav').count()==0
    assert page.evaluate('document.body.style.overflow')==''
    assert page.evaluate('document.documentElement.style.overflow')==''
    assert not page.errors,page.errors
    page.close()
    print('PASS delivery bottom sheet: current same-origin terms, safe formatting, retained pack, focus/close/cache and desktop cleanup',flush=True)

    page=start(browser)
    page.route('https://fixture.invalid/terms',lambda route:route.fulfill(status=503,body='Unavailable'))
    page.locator('.lavka-product-nav [data-section=delivery]').click()
    page.locator('.lavka-product-delivery__retry').wait_for(state='visible')
    assert page.url=='https://fixture.invalid/product' and len(page.posts)==0
    page.unroute('https://fixture.invalid/terms')
    page.route('https://fixture.invalid/terms',lambda route:route.fulfill(status=200,content_type='text/html',body='<div id="content"><div class="article_description"><p>Умови магазину</p></div></div>'))
    page.locator('.lavka-product-delivery__retry').click()
    page.wait_for_function('document.querySelector(".lavka-product-delivery-shell").shadowRoot.querySelector(".lavka-product-delivery__body").textContent==="Умови магазину"')
    page.locator('.lavka-product-delivery__close').click()
    assert page.locator('#input-quantity').input_value()=='1' and page.locator('input[value="50"]').is_checked()
    assert not page.errors,page.errors
    page.close()
    print('PASS delivery fetch failure/retry: product selection and cart untouched',flush=True)

    for width in (320,390,432,600,767):
        page=start(browser,width)
        page.evaluate('''var fly=document.createElement('div');fly.id='fly-menu';fly.className='fly-menu show';
          fly.innerHTML='<div class="container"><div class="row"><div class="fly-menu__block fly-menu__menu-m col-xs-3"><i class="fly-menu__icon fa fa-bars"></i></div><div class="fly-menu__block fly-menu__search-m col-xs-3"><i class="fly-menu__icon fa fa-search"></i></div><div class="fly-menu__block fly-menu__account col-xs-3"><i class="fly-menu__icon fa fa-user"></i></div><div class="fly-menu__block fly-menu__cart col-xs-3"><i class="fly-menu__icon fa fa-shopping-cart"></i></div></div></div>';
          document.body.appendChild(fly);document.querySelector('footer').style.height='900px';window.scrollTo(0,document.body.scrollHeight);''')
        page.wait_for_timeout(600)
        nav=page.locator('.lavka-product-nav').bounding_box();fly=page.locator('#fly-menu').bounding_box();dock=page.locator('.lavka-product-dock').bounding_box()
        assert abs(nav['y']-fly['y']-fly['height'])<1,'Navigation stays glued below shop menu even beyond product/footer'
        assert nav['x']==fly['x']==dock['x']==0
        assert nav['width']==fly['width']==dock['width']==width
        assert nav['height']==PANEL_REFERENCE['nav']['height'] and dock['height']==PANEL_REFERENCE['dock']['height']
        assert page.locator('.lavka-product-nav').evaluate('(e)=>parseFloat(getComputedStyle(e).paddingLeft)')==PANEL_REFERENCE['nav']['padding_x']
        assert page.locator('.lavka-product-nav').evaluate('(e)=>parseFloat(getComputedStyle(e).gap)')==PANEL_REFERENCE['nav']['gap']
        assert page.locator('.lavka-product-nav button').first.evaluate('(e)=>parseFloat(getComputedStyle(e).fontSize)')==PANEL_REFERENCE['nav']['font_size']
        assert page.locator('.lavka-product-nav').evaluate('(e)=>parseFloat(getComputedStyle(e).borderBottomLeftRadius)')==8
        assert page.locator('.lavka-product-nav').evaluate('(e)=>getComputedStyle(e).borderTopLeftRadius')=='0px','Docked top corners must be square'
        assert page.locator('.lavka-product-dock').evaluate('(e)=>getComputedStyle(e).borderRadius')=='16px 16px 0px 0px'
        assert page.locator('.lavka-product-dock__row').evaluate('(e)=>parseFloat(getComputedStyle(e).paddingLeft)')==16
        assert page.locator('.lavka-product-dock__buy').bounding_box()['height']==48
        price=page.locator('.lavka-product-dock__price').bounding_box();qty=page.locator('.lavka-product-dock__quantity').bounding_box();buy=page.locator('.lavka-product-dock__buy').bounding_box()
        assert price['x']==16 and width-buy['x']-buy['width']==16,'Keep real left and right insets'
        assert abs(buy['x']-qty['x']-qty['width']-8)<1,'Quantity belongs immediately left of buy'
        if width>=480:
            page.locator('.lavka-product-dock__action[data-action=heart]').click()
            assert page.evaluate('favorites')==1,'Wide dock wishlist invokes original control once'
        assert page.locator('.lavka-product-dock__buy').evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(79, 127, 40)','Keep shop green'
        centers=page.locator('.lavka-product-dock__totals,.lavka-product-dock__quantity,.lavka-product-dock__buy').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().y+e.getBoundingClientRect().height/2)')
        assert max(centers)-min(centers)<1
        assert page.locator('.lavka-product-nav').evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(255, 255, 255)'
        assert page.locator('.lavka-product-dock').evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(255, 255, 255)'
        page.screenshot(path='/tmp/lavka-product-bars-v5-'+str(width)+'.png')
        footer_scroll=page.evaluate('scrollY')
        page.locator('.lavka-product-nav [data-section=delivery]').click();page.locator('.lavka-product-terms').wait_for(state='visible')
        page.locator('.lavka-product-delivery__close').click();page.wait_for_timeout(80)
        assert abs(page.evaluate('scrollY')-footer_scroll)<1,'Modal close restores the exact footer position, including dock clearance'
        assert page.locator('.lavka-product-dock').is_visible()
        # Follow the visible lower edge while the native shop menu slides away/in.
        page.evaluate("var fly=document.getElementById('fly-menu');fly.style.transition='top 180ms linear';fly.style.top='-40px'")
        for sample in range(6):
            page.wait_for_timeout(35)
            gap=page.evaluate("document.querySelector('.lavka-product-nav').getBoundingClientRect().top-Math.max(0,document.getElementById('fly-menu').getBoundingClientRect().bottom)")
            assert abs(gap)<5,'Stack follows the moving shop header'
        page.evaluate("document.getElementById('fly-menu').style.top='0px'");page.wait_for_timeout(250)
        assert abs(page.locator('.lavka-product-nav').bounding_box()['y']-40)<1
        assert not page.errors,page.errors
        page.close()
    print('PASS attached Rozetka reference dimensions/spacing, shop colors, footer persistence and native-header transition',flush=True)

    page=start(browser)
    noisy=(ROOT/'tests/fixtures/product_delivery_editor.html').read_text()
    page.route('https://fixture.invalid/terms',lambda route:route.fulfill(status=200,content_type='text/html; charset=utf-8',body=noisy))
    page.add_style_tag(content='.lavka-product-terms,.terms-section__body{padding:0!important;background:red!important}.lavka-product-delivery p,.lavka-product-delivery summary{margin:0!important;font-size:30px!important;line-height:3!important}')
    page.locator('.lavka-product-nav [data-section=delivery]').click()
    page.locator('.lavka-product-terms').wait_for(state='visible')
    assert page.locator('.lavka-product-terms summary').all_text_contents()==['Доставка','Оплата',"Ми з вами на зв'язку:",'Як розмістити замовлення?']
    assert page.locator('.lavka-product-terms details[open] summary').all_text_contents()==['Доставка','Оплата']
    assert page.evaluate('document.querySelector(".lavka-product-terms")') is None,'Popup content must be isolated inside its shadow root'
    assert page.locator('.lavka-product-delivery').get_attribute('data-lavka-product-version')=='7'
    assert page.locator('.terms-section[data-kind=delivery] p').first.evaluate('(e)=>getComputedStyle(e).fontSize')=='14px','Theme styles cannot override popup paragraphs'
    page.locator('.terms-section[data-kind=contacts]>summary').click()
    assert page.locator('.terms-section[data-kind=contacts] p').first.is_visible(),'Contact information remains available on demand'
    assert page.locator('.lavka-product-terms').evaluate('(e)=>!Array.from(e.querySelectorAll("p,li,ul,ol")).some(n=>!n.textContent.trim())'),'No editor spacer paragraphs/lists'
    assert page.locator('.lavka-product-terms div:not(.terms-section__body),.lavka-product-terms span,.lavka-product-terms [style]').count()==0,'Editor layout and inline styling do not leak into popup'
    assert page.locator('.lavka-product-terms ul').count()==2 and page.locator('.lavka-product-terms li').count()==3,'Manual bullet paragraphs form a real compact list'
    assert page.locator('.lavka-product-terms a').get_attribute('href')=='mailto:store@example.invalid'
    assert page.locator('.lavka-product-terms table').inner_text().replace('\t',' ').replace('\n',' ').strip()=='Спосіб Умови Перевізник За чинними тарифами.'
    metrics=page.locator('.terms-section[data-kind=delivery] .terms-section__body').evaluate('(e)=>({padding:getComputedStyle(e).paddingLeft,paragraphMargins:Array.from(e.querySelectorAll("p")).map(n=>parseFloat(getComputedStyle(n).marginBottom))})')
    assert metrics['padding']=='16px' and max(metrics['paragraphMargins'])<=10
    assert page.locator('.lavka-product-terms').evaluate('(e)=>e.scrollWidth<=e.clientWidth')
    assert page.evaluate('window.unexpectedTermsScript') is None
    page.screenshot(path='/tmp/lavka-product-delivery-v6.png')
    assert not page.errors,page.errors
    page.close()
    print('PASS editor-rich delivery terms: headings, paragraphs, real lists, tables and links retained; empty lines and desktop layout removed',flush=True)

    page=start(browser)
    page.evaluate('$(".product-page__price").data("special",30).data("old-special",30).html("<span class=price-old>45 грн</span><span class=price-new>30 грн</span>")')
    page.get_by_role('button',name='200 г',exact=True).click()
    page.wait_for_function('document.querySelector(".product-page__price .price-new").textContent==="145 грн"')
    assert page.locator('.lavka-product-pack-caption').text_content()=='за 200 г / 2.9 €','Euro must follow the selected special total, not the old base price'
    assert not page.errors,page.errors
    page.close()

    page=start(browser)
    page.evaluate('$(".product-page__price").data("discount",JSON.stringify([{quantity:3,price:40}]))')
    page.locator('#input-quantity').fill('3');page.locator('#input-quantity').dispatch_event('change')
    page.wait_for_function('document.querySelector(".product-page__price").textContent==="120 грн"')
    assert page.locator('.lavka-product-pack-caption').text_content()=='за 3 × 50 г / 2.4 €','Euro must follow the native quantity-discount total'
    assert not page.errors,page.errors
    page.close()
    print('PASS euro caption uses native special/quantity-discount totals and configured exchange rate',flush=True)

    for width in (320,390,432,600,767):
        page=start(browser,width,merged=True)
        page.evaluate('window.scrollTo(0,document.body.scrollHeight)')
        page.locator('.lavka-product-dock').wait_for(state='visible')
        dock=page.locator('.lavka-product-dock').bounding_box()
        price=page.locator('.lavka-product-dock__price').bounding_box()
        quantity=page.locator('.lavka-product-dock__quantity').bounding_box()
        buy=page.locator('.lavka-product-dock__buy').bounding_box()
        assert price['x']==16 and width-buy['x']-buy['width']==16,'Merged CSS must retain both insets'
        assert abs(price['y']+price['height']/2-quantity['y']-quantity['height']/2)<1
        assert abs(quantity['y']-buy['y'])<1 and quantity['height']==buy['height']==48
        assert abs(buy['x']-quantity['x']-quantity['width']-8)<1
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'Merged layout must not overflow'
        assert page.locator('body').evaluate('(e)=>parseFloat(getComputedStyle(e).paddingBottom)')>=dock['height']+8
        assert page.locator('.lavka-product-dock__qty-button[data-direction=minus]').is_disabled()
        page.locator('.lavka-product-dock__qty-button[data-direction=plus]').click()
        page.wait_for_function('document.querySelector(".lavka-product-dock__price").textContent==="90 грн"')
        assert page.locator('#input-quantity').input_value()=='2' and page.locator('.lavka-product-dock__count').inner_text()=='2'
        page.locator('.lavka-product-qty-button[data-direction=plus]').evaluate('(e)=>e.click()')
        assert page.locator('.lavka-product-dock__count').inner_text()=='3','Main quantity propagates to dock'
        page.locator('.lavka-product-dock__qty-button[data-direction=minus]').click()
        assert page.locator('#input-quantity').input_value()=='2'
        page.wait_for_function('jQuery.timers.length===0 && document.querySelector(".lavka-product-dock__price").textContent==="90 грн"')
        if width==390:
            page.evaluate('$("#input-quantity").data("minimum",3).val(3).change();uniJsVars.qty_switch_step=true')
            assert page.locator('.lavka-product-dock__qty-button[data-direction=minus]').is_disabled()
            page.locator('.lavka-product-dock__qty-button[data-direction=plus]').click()
            assert page.locator('#input-quantity').input_value()=='6','Dock must use native minimum/step rules'
            page.wait_for_function('jQuery.timers.length===0 && document.querySelector(".lavka-product-dock__price").textContent==="270 грн"')
            assert page.locator('.product-block input[name=quantity]').count()==1,'Native serialization keeps a single quantity field'
            page.locator('.lavka-product-dock__buy').click();page.wait_for_timeout(80)
            assert len(page.posts)==1 and page.posts[0]['quantity']==['6'],page.posts
            page.pending.pop().fulfill(status=200,content_type='application/json',body='{"success":"Added","products":{}}')
            page.wait_for_function('!document.getElementById("button-cart").disabled')
        page.screenshot(path='/tmp/lavka-product-dock-v7-merged-'+str(width)+'.png')
        if width==390:
            page.add_style_tag(content='.lavka-product-dock{padding-bottom:34px}')
            page.evaluate('window.dispatchEvent(new Event("resize"))')
            assert page.locator('.lavka-product-dock').bounding_box()['height']==106
            assert page.locator('body').evaluate('(e)=>parseFloat(getComputedStyle(e).paddingBottom)')==114,'Page clearance includes the bottom safe area'
        page.set_viewport_size({'width':1280,'height':844});page.wait_for_timeout(100)
        assert page.locator('.lavka-product-dock').count()==0
        assert page.locator('body').evaluate('(e)=>e.style.getPropertyValue("--lavka-product-dock-clearance")')==''
        assert not page.errors,page.errors
        page.close()
    print('PASS actual UniShop CSS compression: dock insets, centers, both quantity controls, native minimum/step/POST and desktop cleanup',flush=True)
    browser.close()
