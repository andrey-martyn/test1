"""Exercise the supplied shop scripts with mocked transport, never a live shop/DB."""
from pathlib import Path
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
THEME = ROOT / 'store/catalog/view/theme/unishop2'

def card(pid, name='Імбирний чай (Тибетський чай)', kind='radio', extra=False):
    weights = [('50','50 г','0'),('200','200 г','115'),('500','500 г','305'),('1000','1 кг','575')]
    if kind == 'select':
        options = '<select name="option[70]" class="option__select"><option value="">Оберіть</option>' + ''.join('<option value="%s" data-prefix="+" data-price="%s">%s</option>' % (v, price, text) for v,text,price in weights) + '</select>'
    else:
        options = ''.join('<label class="option__item"><input type="radio" name="option[70]" value="%s" class="hidden" data-prefix="+" data-price="%s"><span class="option__name">%s</span></label>' % (v,price,text) for v,text,price in weights)
    group = '<div class="option__group"><label class="option__group-name">* Фасовка:</label>' + options + '</div>'
    if extra:
        group += '<div class="option__group"><label>Колір</label><input name="option[71]" type="text"></div>'
    return """<div class="product-layout product-grid grid-view col-sm-6"><article class="product-thumb uni-item" data-test="%s"><div class="product-thumb__image"><a href="#product"><img alt="Спеції" src="data:image/svg+xml,%%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%%3E%%3Crect width='200' height='200' fill='beige'/%%3E%%3C/svg%%3E"></a></div><div class="product-thumb__caption"><a class="product-thumb__name">%s</a><div class="product-thumb__description">Довгий опис</div><div class="product-thumb__option option">%s</div><div class="product-thumb__row"><div class="product-thumb__price price" data-price="45" data-special="0">45 грн</div><div class="product-thumb__cart cart"><div class="qty-switch"><input class="qty-switch__input" value="1"></div><button class="product-thumb__add-to-cart add_to_cart" data-pid="%s" title="Купити" onclick="cart.add(%s,this)"><span>Купити</span></button><button class="product-thumb__wishlist" title="Обране">♡</button></div></div></div></article></div>""" % (pid,name,group,pid,pid)

common = (THEME/'js/common.js').read_text()
cart_code = 'var cart = {' + common.split('var cart = {',1)[1].split('var voucher = {',1)[0]
# The actual uniSelectView and module plugin are tested separately from menu initialization.
view_code = 'var uniSelectView = {' + common.split('var uniSelectView = {',1)[1].split('function uniMenuBlur',1)[0]
module_code = common[common.index('(function($){\n\tvar Modules = {'):common.index('function uniTimer') if 'function uniTimer' in common else common.index('var cart = {')]
html = '<html lang="uk"><head><base href="https://fixture.invalid/"><style>'+(THEME/'stylesheet/bootstrap.min.css').read_text()+(THEME/'stylesheet/stylesheet.css').read_text()+(THEME/'stylesheet/lavka-mobile-cards.css').read_text()+(THEME/'stylesheet/lavka-card-reviews.css').read_text()+'</style></head><body><main><div class="container"><div id="content"><div class="products-block row">'+card(816)+card(817,'Ваніль натуральна мелена з тростинним цукром', 'select')+'</div></div></div></main></body></html>'
html = html.replace('</style>', '@media(max-width:519px){.product-thumb__option{display:none!important}}</style>',1)
bootstrap = r"""
window.uniJsVars={currency:{decimal:0,decimal_p:'.',thousand_p:' ',symbol_l:'',symbol_r:' грн'},cart_popup_disable:true,cart_popup_autohide:false,cart_btn:{}};
window.items_on_mobile=2;window.default_view='grid';window.module_on_mobile='carousel';window.uni_touch_support=false;
// Keep view preferences isolated from other browser scenarios.
var viewPreferences={};Object.defineProperty(window,'localStorage',{value:{getItem:function(key){return viewPreferences[key]||null;},setItem:function(key,value){viewPreferences[key]=String(value);}}});
window.uniAutoHeight=function(){};window.uniFlyAlert=function(){};window.uniModalWindow=function(){};window.getURLVar=function(){return '';};
window.requests=[];window.headerUpdates=0;window.delegatedClicks=0;
$(document).on('click','.add_to_cart',function(){delegatedClicks++;});
$.ajax=function(settings){var deferred=$.Deferred();requests.push({settings:settings,deferred:deferred});return deferred.promise();};
window.reply=function(index,success){var request=requests[index];if(success){var json={success:'Added'};request.settings.success(json);request.deferred.resolve(json);}else{request.settings.error({statusText:'error',responseText:''},'error','error');request.deferred.reject();}};
"""
results=[]
def passed(text):
    results.append(text)
    print('PASS',text,flush=True)

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page(viewport={'width':390,'height':844})
    page.set_default_timeout(6000)
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.route('https://fixture.invalid/**',lambda route:route.abort())
    page.route('https://fixture.invalid/category',lambda route:route.fulfill(status=200,content_type='text/html; charset=utf-8',body=html))
    page.goto('https://fixture.invalid/category')
    page.add_script_tag(content=(THEME/'js/jquery-2.2.4.min.js').read_text())
    page.add_script_tag(content=bootstrap)
    page.add_script_tag(content=cart_code)
    page.evaluate('()=>{cart.uniHeaderCartUpd=function(){headerUpdates++;};}')
    page.add_script_tag(content=(THEME/'js/live-price.js').read_text())
    page.add_script_tag(content=(THEME/'js/lavka-mobile-cards.js').read_text())
    page.add_script_tag(content=(THEME/'js/lavka-card-reviews.js').read_text())
    page.wait_for_function('document.querySelectorAll(".lavka-mobile-card").length===2')
    # Supply the same summary event as the metadata route; cart transport stays independent.
    page.evaluate('()=>document.querySelectorAll(".product-thumb__image").forEach(image=>image.dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:12,rating:4.8}}})))')
    page.wait_for_function('document.querySelectorAll(".lavka-card-reviews").length===2')
    first=page.locator('[data-test="816"]').first; second=page.locator('[data-test="817"]')
    first.locator('.lavka-pack-caption').wait_for(state='visible')
    assert first.locator('.lavka-pack-caption').inner_text()=='за 50 г'
    first.locator('input[value="200"]').check()
    assert first.locator('.price').inner_text()=='160 грн'
    assert first.locator('.lavka-pack-caption').inner_text()=='за 200 г'
    second.locator('.lavka-select-weight[data-value="500"]').click()
    assert second.locator('.price').inner_text()=='350 грн'
    assert second.locator('.lavka-pack-caption').inner_text()=='за 500 г'
    passed('Native radio and native select recalculate immediately with the selected weight caption')
    page.evaluate('()=>{$("[data-test=816] .qty-switch__input").val(2).trigger("change");}')
    assert first.locator('.price').inner_text()=='320 грн'
    assert first.locator('.lavka-pack-caption').inner_text()=='за 2 × 200 г'
    page.evaluate('()=>{$("[data-test=816] .qty-switch__input").val(1).trigger("change");}')
    passed('Multiple required packs show the matching total price and quantity in the caption')
    # Distinct form owners preserve checked inputs with identical names in separate cards.
    duplicate=card(816,'Повтор того самого товару')
    page.evaluate('(html)=>document.querySelector(".products-block").insertAdjacentHTML("beforeend",html)',duplicate)
    copies=page.locator('[data-test="816"]')
    copies.nth(1).locator('.lavka-pack-caption').wait_for(state='visible')
    copies.nth(1).locator('input[value="500"]').check()
    assert copies.nth(0).locator('input[value="200"]').is_checked()
    passed('Repeated products have independent native radio groups without renaming option fields')
    first.locator('.add_to_cart').click()
    page.wait_for_function('requests.length===1')
    page.wait_for_function('document.querySelector(".add_to_cart").disabled')
    assert first.locator('.add_to_cart').get_attribute('aria-busy')=='true'
    assert copies.nth(1).locator('.add_to_cart').is_disabled()
    for _ in range(7):
        first.locator('.add_to_cart').evaluate('(el)=>el.click()')
        page.evaluate('cart.add(816,document.querySelector(".add_to_cart"))')
    page.wait_for_timeout(5200)
    assert page.evaluate('requests.length')==1
    data=parse_qs(page.evaluate('requests[0].settings.data'))
    assert data=={'product_id':['816'],'quantity':['1'],'option[70]':['200']},data
    assert page.evaluate('delegatedClicks')==1
    assert not first.locator('input:checked').is_disabled()
    passed('A 5.2 second response and repeated clicks send exactly one POST with the chosen pack')
    page.evaluate('reply(0,true)')
    assert first.evaluate('(card)=>card.classList.contains("lavka-cart-added")')
    page.wait_for_function('!document.querySelector(".add_to_cart").disabled')
    assert page.evaluate('headerUpdates')==1, {'updates':page.evaluate('headerUpdates'),'errors':errors}
    second.locator('.add_to_cart').click()
    page.wait_for_function('requests.length===2')
    data=parse_qs(page.evaluate('requests[1].settings.data'))
    assert data['option[70]']==['500']
    page.evaluate('reply(1,false)')
    second.locator('.lavka-cart-message').wait_for(state='visible')
    page.wait_for_function("!document.querySelector('[data-test=\"817\"] .add_to_cart').disabled")
    page.wait_for_timeout(150)
    assert page.evaluate('requests.length')==2
    passed('Failure unlocks the button and warns to check the cart; no POST is automatically retried')
    # A price-only text replacement cannot reinitialize choices or add form owners.
    count=page.locator('.lavka-option-owner').count()
    first.locator('input[value="500"]').check()
    assert first.locator('.price').inner_text()=='350 грн'
    page.wait_for_timeout(80)
    assert page.locator('.lavka-option-owner').count()==count
    passed('Price changes do not rebuild the product catalog')
    # Match the source pagination behavior without firing a remote request.
    page.add_script_tag(content=view_code)
    page.evaluate('()=>{uniJsVars.showmore=true;uniJsVars.showmore_text="Показати ще";uniJsVars.ajax_pagination=false;document.getElementById("content").insertAdjacentHTML("beforeend",\'<ul class="pagination"><li class="active">1</li><li><a href="#page2">2</a></li></ul><div class="pagination-text">1–2</div>\');}')
    page.add_script_tag(content=(THEME/'js/showmore-ajaxpagination.js').read_text())
    page.locator('.show-more__btn').click()
    new_page='<div class="products-block">'+card(818,'Новий товар')+'</div><ul class="pagination"><li class="active">2</li></ul><div class="pagination-text">1–3</div>'
    page.evaluate('(markup)=>{requests[2].settings.success(markup);}','<div class="container">'+new_page+'</div>')
    page.locator('[data-test="818"] .lavka-pack-caption').wait_for(state='visible')
    assert first.locator('input[value="500"]').is_checked()
    assert second.locator('select').input_value()=='500'
    passed('The actual show-more handler preserves selected packs while initializing new cards')
    start=common.index('(function($){\n\tvar Modules = {')
    end=common.index('})(jQuery);',start)+len('})(jQuery);')
    page.add_script_tag(content=(THEME/'js/owl.carousel.min.js').read_text())
    page.add_script_tag(content=common[start:end])
    module_card=lambda pid,name: card(pid,name).split('>',1)[1].rsplit('</div>',1)[0]
    module='<div class="container"><div class="uni-module"><div class="uni-module__wrapper">'+module_card(819,'Товар каруселі')+module_card(820,'Інший товар')+'</div></div></div>'
    page.evaluate('(markup)=>{document.querySelector("main").insertAdjacentHTML("beforeend",markup);$(".uni-module").uniModules({type:"carousel",loop:true,autoheight:[],items:{0:{items:2}}});}',module)
    assert page.evaluate('$(".owl-carousel").data("owl.carousel").options.loop') is False
    assert page.locator('.owl-item.cloned').count()==0
    page.wait_for_function('document.querySelectorAll(".uni-module .lavka-mobile-card").length===2')
    page.evaluate('()=>document.querySelectorAll(".uni-module .product-thumb__image").forEach(image=>image.dispatchEvent(new CustomEvent("lavka:review-summary",{bubbles:true,detail:{enabled:true,summary:{total:12,rating:4.8}}})))')
    page.wait_for_function('document.querySelectorAll(".uni-module .lavka-reviews-inline").length===2')
    rating=page.locator('.uni-module .lavka-card-reviews').first.bounding_box()
    buy=page.locator('.uni-module .product-thumb__add-to-cart').first.bounding_box()
    assert rating['x']+rating['width']<=buy['x'] and buy['width']==36
    passed('The actual Owl/UniShop module disables radio clones that clear the chosen pack')
    page.evaluate('(markup)=>document.querySelector(".products-block").insertAdjacentHTML("beforeend",markup)',card(821,'Товар з кількома опціями',extra=True))
    page.locator('[data-lavka-fallback]').wait_for(state='visible')
    page.evaluate('uniSelectView.init()')
    assert page.locator('[data-lavka-fallback]').evaluate('(el)=>el.getBoundingClientRect().width')>300
    assert page.locator('[data-lavka-fallback] .product-thumb__option').is_visible()
    passed('Unknown or multiple option groups remain available in a full-width fallback card')
    # Preserve all native names through responsive view changes and show-more initialization.
    for width in [320,390,432]:
        page.set_viewport_size({'width':width,'height':844})
        metrics=first.evaluate('(card)=>{let s=card.querySelector(".option__name").getBoundingClientRect(),b=card.querySelector(".add_to_cart").getBoundingClientRect();return {circle:s.width,button:b.width,overflow:document.documentElement.scrollWidth>innerWidth};}')
        if metrics['overflow']:
            print('OVERFLOW',page.evaluate('Array.from(document.querySelectorAll("body *")).filter(el=>el.getBoundingClientRect().right>innerWidth+1).map(el=>({tag:el.tagName,cls:el.className,right:el.getBoundingClientRect().right})).slice(0,12)'))
        assert metrics=={'circle':32,'button':36,'overflow':False},metrics
    passed('320/390/432px: 32px weights, 36px cart, two columns and no horizontal page overflow')
    page.set_viewport_size({'width':1100,'height':844})
    assert first.locator('.lavka-pack-caption').is_hidden()
    assert second.locator('.lavka-native-select').is_visible()
    assert second.locator('.lavka-select-weights').is_hidden()
    assert first.locator('.add_to_cart').inner_text()=='Купити'
    passed('Desktop retains its native options and purchase button')
    assert not errors,errors
    page.set_viewport_size({'width':390,'height':844})
    copies.nth(1).evaluate('(card)=>card.closest(".product-layout").remove()')
    second.locator('.lavka-cart-message').evaluate('(el)=>el.remove()')
    (ROOT/'artifacts').mkdir(exist_ok=True)
    page.locator('.products-block').screenshot(path=str(ROOT/'artifacts/mobile-category.png'))
    browser.close()
print('Passed',len(results),'checks')
