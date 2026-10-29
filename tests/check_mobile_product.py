"""Real UniShop cart/gallery handlers with local HTTP only; never touches a shop."""
import os
import re
from pathlib import Path
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
THEME=ROOT/'store/catalog/view/theme/unishop2'
TEMPLATE=Path(os.environ.get('LAVKA_TEST_PRODUCT_TWIG') or ROOT/'tests/fixtures/product_page_active.twig').read_text()
BOOTSTRAP=Path(os.environ['LAVKA_TEST_BOOTSTRAP_JS']).read_text()
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
CSS=''.join((THEME/'stylesheet'/name).read_text() for name in ('bootstrap.min.css','stylesheet.css','lavka-mobile-cards.css','lavka-product-tags.css','lavka-card-reviews.css','lavka-mobile-product.css'))
PHOTO='data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="600" height="600"%3E%3Crect width="600" height="600" fill="%23f1f4e9"/%3E%3C/svg%3E'
PHOTO2=PHOTO.replace('f1f4e9','ede6d7')

def html(kind='radio',extra=False,disabled=False):
    weights=[('50','50 г',0),('100','100 г',45),('200','200 г',115),('500','500 г',305),('1000','1 кг',575)]
    if kind=='select':
        fields='<select name="option[70]" id="input-option70" class="option__select"><option value="">Оберіть</option>'+''.join('<option value="%s" data-prefix="+" data-price="%s">%s</option>'%(value,delta,label) for value,label,delta in weights)+'</select>'
    else:
        fields='<div id="input-option70">'+''.join('<label class="option__item"><input type="radio" class="hidden" name="option[70]" value="%s" data-prefix="+" data-price="%s"><span class="option__name">%s</span></label>'%(value,delta,label) for value,label,delta in weights)+'</div>'
    option='<div class="product-page__option option row"><div class="option__group col-xs-12"><label class="option__group-name">* Фасовка:</label>'+fields+'</div>'
    if extra:option+='<div class="option__group col-xs-12"><label class="option__group-name">Напис</label><input type="text" name="option[71]" id="input-option71" class="form-control"></div>'
    option+='</div>'
    return '''<!doctype html><html lang="uk"><head><meta charset="utf-8"><style>'''+CSS+'''</style></head><body><header style="height:64px;padding:16px;color:#4f7f28;border-bottom:1px solid #eee">☰　Лавка Спецій</header><main><div id="product" class="container"><ul class="breadcrumb mobile"><li><a href="/">Головна</a></li><li>Суміші прянощів</li></ul><div class="row"><div id="content" class="col-sm-12"><div class="heading-h1"><h1>Часникова сіль з травами</h1></div><div class="row"><div class="product-page col-xs-12"><div class="row"><div class="product-page__image col-sm-6 col-lg-5"><div class="product-page__image-main uni-item"><div class="sticker"><div class="sticker__item lavka-hit" style="--lavka-sticker-bg:#fff100;background:#fff100;color:black">Хіт продажів</div></div><div class="product-page__image-main-carousel"><a class="product-page__image-main-a" href="/photo1"><img class="img-responsive" src='''+"'"+PHOTO+"'"+'''></a><a class="product-page__image-main-a" href="/photo2"><img class="img-responsive" src='''+"'"+PHOTO2+"'"+'''></a></div><button type="button" class="product-page__wishlist-btn wishlist" title="В обране" onclick="window.favorites++">♡</button></div><div class="product-page__image-addit owl-carousel"><img class="product-page__image-addit-img selected" data-thumb='''+"'"+PHOTO+"'"+''' data-full="/photo1" src='''+"'"+PHOTO+"'"+'''><img class="product-page__image-addit-img" data-thumb='''+"'"+PHOTO2+"'"+''' data-full="/photo2" src='''+"'"+PHOTO2+"'"+'''></div></div><div class="col-sm-6 col-lg-6 col-lg-push-1 product-block"><div class="product-page__rating rating"><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i> &nbsp; <a class="product-page__rating-a" onclick="$('a[href=\\'#tab-review\\']').click();scroll_to('#tab-review');return false;">Відгуки: 1</a><br></div><div class="prod_smalldescr">З нашою приправою дуже просто приготувати смачний соус: додайте чайну ложку в сметану. Підходить до свіжих овочів, печеної картоплі та домашніх грінок.</div><div class="product-data"><div class="product-data__item manufacturer"><div class="product-data__item-div"><span class="product-data__item-span">Виробник:</span></div><a href="/brand">Лавка Спецій</a></div><div class="product-data__item stock"><div class="product-data__item-div"><span class="product-data__item-span">Наявність:</span></div>'''+('Немає в наявності' if disabled else 'В наявності')+'''</div></div><br><div class="product-page__price price" data-price="45" data-special="0" data-eurcurr="50">45 грн</div><div class="product-page__eur-price"><span class="eur-price">0.9 €</span> *</div>'''+option+'''<div class="product-page__cart"><input type="hidden" name="product_id" value="816"><div class="qty-switch"><input type="text" name="quantity" value="1" data-minimum="1" id="input-quantity" class="qty-switch__input form-control"><div><i class="qty-switch__btn fa fa-plus btn-default">+</i><i class="qty-switch__btn fa fa-minus btn-default">−</i></div></div><button type="button" class="product-page__add-to-cart add_to_cart btn btn-lg'''+(' disabled' if disabled else '')+'''" data-pid="816" id="button-cart"'''+(' disabled' if disabled else '')+'''><i>🛒</i><span>Купити</span></button></div><div class="product-page__share">Поділитися</div></div></div></div></div><ul class="nav nav-tabs"><li class="active"><a href="#tab-description" data-toggle="tab">Опис</a></li><li><a href="#tab-specification" data-toggle="tab">Характеристики</a></li><li><a class="tab-review" href="#tab-review" data-toggle="tab">Відгуки</a></li></ul><div class="tab-content"><div class="tab-pane active" id="tab-description" style="min-height:1300px"><p>Про товар. Повний опис і поради щодо використання.</p></div><div class="tab-pane" id="tab-specification" style="min-height:900px">Склад: прянощі.</div><div class="tab-pane" id="tab-review"><form id="form-review">Штатна форма</form></div></div></div></div></div></main><footer style="height:150px">Лавка Спецій</footer></body></html>'''

RUNTIME="""window.uniJsVars={currency:{decimal:0,decimal_p:'.',thousand_p:' ',symbol_l:'',symbol_r:' грн'},qty_switch_step:false,cart_popup_disable:true,cart_popup_autohide:false,cart_btn:{}};window.dataLayer=[];window.gaCalls=[];window.gtag=function(){gaCalls.push(Array.from(arguments));};window.favorites=0;window.popups=[];window.uniFlyAlert=function(){};window.uniModalWindow=function(){};$.magnificPopup={open:function(data){popups.push(data.items);}};"""


def start(browser,width=390,kind='radio',extra=False,disabled=False,enhance=True,single=False):
    page=browser.new_page(viewport={'width':width,'height':844});page.set_default_timeout(7000)
    page.errors=[];page.posts=[];page.pending=[]
    page.on('pageerror',lambda error:page.errors.append(str(error)))
    page.route('https://fixture.invalid/**',lambda route:route.abort())
    markup=html(kind,extra,disabled)
    if single:
        markup=re.sub(r'<a class="product-page__image-main-a" href="/photo2">.*?</a>','',markup)
        markup=re.sub(r'<div class="product-page__image-addit owl-carousel">.*?</div>','',markup)
    page.route('https://fixture.invalid/product',lambda route:route.fulfill(status=200,content_type='text/html; charset=utf-8',body=markup))
    def cart(route):page.posts.append(parse_qs(route.request.post_data));page.pending.append(route)
    page.route('**/index.php?route=checkout/cart/add',cart)
    page.route('**/index.php?route=common/cart/info',lambda route:route.fulfill(status=200,body='<div></div>'))
    page.goto('https://fixture.invalid/product')
    for script in ((THEME/'js/jquery-2.2.4.min.js').read_text(),BOOTSTRAP,RUNTIME,VIEW,(THEME/'js/owl.carousel.min.js').read_text(),QTY,SCROLL,CART,GALLERY,(THEME/'js/live-price.js').read_text(),(THEME/'js/lavka-card-reviews.js').read_text()):page.add_script_tag(content=script)
    page.wait_for_timeout(80)
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
            assert page.locator('.product-block .stock').count()==0 and page.locator('.lavka-product-meta .stock').count()==1
            assert page.locator('.lavka-product-buy .product-page__option').count()==1
            assert page.locator('#button-cart').bounding_box()['y']<page.locator('.prod_smalldescr').bounding_box()['y']
            assert page.locator('.product-page__add-to-cart').bounding_box()['height']>=48
            assert page.locator('input[value="50"]').is_checked()
            boxes=page.locator('.lavka-product-choice').evaluate_all('(els)=>els.map(e=>({x:e.getBoundingClientRect().x,y:e.getBoundingClientRect().y,width:e.offsetWidth}))')
            assert len(boxes)==5 and len({round(b['y']) for b in boxes})==1,'Five pack buttons must fit one photo row'
            assert min(b['width'] for b in boxes)>=44
            assert page.locator('.owl-item .lavka-product-choice').count()==0,'Owl must not clone pack controls'
            assert page.locator('.product-page__image-main .lavka-product-packs').count()==1
            assert page.locator('.product-page__rating-a').get_attribute('data-lavka-review-count')=='12'
            meta=page.locator('.lavka-product-meta').evaluate('(e)=>Array.from(e.children).map(n=>n.getBoundingClientRect().y+n.getBoundingClientRect().height/2)')
            assert abs(meta[0]-meta[1])<1,'Rating and stock vertical centers must agree'
            line=page.locator('.lavka-product-totals,.product-page__cart>.qty-switch,#button-cart').evaluate_all('(els)=>els.map(n=>n.getBoundingClientRect().y+n.getBoundingClientRect().height/2)')
            assert max(line)-min(line)<1,'Price, quantity and buy button must share a center line'
            manufacturer=page.locator('.manufacturer').evaluate('(e)=>e.lastElementChild.getBoundingClientRect().x-e.firstElementChild.getBoundingClientRect().right')
            assert 0<=manufacturer<=10,'Manufacturer value must be adjacent to its label'
            page.get_by_role('button',name='200 г',exact=True).click();page.wait_for_function('document.querySelector(".product-page__price").textContent.trim()==="160 грн"')
            page.locator('.lavka-product-qty-button[data-direction=plus]').press('Enter');page.wait_for_function('document.querySelector(".product-page__price").textContent.trim()==="320 грн"')
            assert page.locator('.lavka-product-pack-caption').inner_text()=='за 2 × 200 г'
            page.locator('.product-page__image-addit-img').nth(1).press('Enter');page.wait_for_timeout(300)
            assert page.locator('.product-page__image-addit-img').nth(1).evaluate('(e)=>e.classList.contains("selected")')
            page.locator('.product-page__image-main-carousel .owl-item.active a').click();assert len(page.evaluate('popups[0]'))==2
            page.locator('.product-page__wishlist-btn').click();assert page.evaluate('favorites')==1
            assert page.locator('#input-quantity').input_value()=='2','Leaving qty button must not change quantity again'
            page.locator('.product-page__rating-a').click();assert page.locator('#tab-review').is_visible();page.wait_for_timeout(500)
            page.evaluate('$("a[href=\\"#tab-description\\"]").tab("show");window.scrollTo(0,0)')
            page.wait_for_timeout(150);page.screenshot(path='/tmp/lavka-mobile-product-'+str(width)+'.png',full_page=True)
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
    page.locator('.lavka-product-dock button').click();page.wait_for_timeout(150)
    assert len(page.posts)==1 and page.posts[0]['option[70]']==['500'] and page.posts[0]['quantity']==['1'],page.posts
    assert page.locator('#button-cart').is_disabled()
    page.evaluate('document.getElementById("button-cart").click();document.querySelector(".lavka-product-dock button").click()')
    page.wait_for_timeout(5200);assert len(page.posts)==1
    page.pending.pop().fulfill(status=200,content_type='application/json',body='{"success":"Added","products":{}}')
    page.locator('#button-cart').wait_for(state='attached');page.wait_for_function('!document.getElementById("button-cart").disabled')
    assert page.locator('.lavka-product-status').inner_text()=='Додано до кошика'
    assert page.evaluate('gaCalls.filter(e=>e[1]==="add_to_cart").length')==1
    assert page.evaluate('dataLayer.filter(e=>e.ecommerce && e.ecommerce.add).length')==1
    # Network failure surfaces a check-cart message and never retries a purchase automatically.
    page.locator('.lavka-product-dock button').click();page.wait_for_timeout(80)
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
            assert page.locator('#button-cart').is_disabled();assert page.locator('.lavka-product-dock button').is_disabled()
        assert not page.errors,page.errors
        page.close()
    print('PASS native select, extra required options, unavailable products and single-photo overlay',flush=True)

    page=start(browser)
    page.evaluate('document.querySelector(".product-page__image-main").dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:0,rating:0}}}))')
    assert not page.locator('.product-page__rating').is_visible(),'No rating, icon or invitation without published reviews'
    page.evaluate('document.querySelector(".product-page__image-main").dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:3,rating:4.6}}}))')
    page.wait_for_function('document.querySelector(".product-page__rating-a").dataset.lavkaReviewCount==="3"')
    assert page.locator('.lavka-review-stars__fill').get_attribute('style')=='width: 92%;'
    page.evaluate('var fly=document.createElement("div");fly.id="fly-menu";fly.className="show";fly.style="position:fixed;top:0;left:0;right:0;height:40px;z-index:999;background:white";document.body.appendChild(fly);window.scrollTo(0,350)')
    page.wait_for_timeout(150)
    assert abs(page.locator('#content>.nav-tabs').bounding_box()['y']-40)<1,'Sticky tabs must sit below the actual floating shop menu'
    page.locator('.nav-tabs a[href="#tab-specification"]').click();page.wait_for_timeout(700)
    assert page.locator('#tab-specification').is_visible()
    assert page.locator('#tab-specification').bounding_box()['y']>=page.locator('.nav-tabs').bounding_box()['y']+44,'Tab content must not be hidden behind navigation'
    page.locator('.nav-tabs a[href="#tab-description"]').click();page.wait_for_timeout(700)
    assert page.locator('#tab-description').is_visible()
    page.set_viewport_size({'width':1280,'height':844});page.wait_for_timeout(80)
    assert page.locator('.lavka-product-packs').count()==0
    assert page.locator('.product-page__rating-a').get_attribute('data-lavka-review-count') is None
    assert page.locator('.product-page__rating-a').inner_text()=='Відгуки: 3'
    assert not page.errors,page.errors
    page.close()
    print('PASS sticky native sections below shop menu, live review counts, zero-review hiding and desktop restoration',flush=True)
    browser.close()
