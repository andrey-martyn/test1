"""Native Bootstrap and shop scripts; all HTTP is mocked, no review is submitted."""
import os
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
THEME=ROOT/'store/catalog/view/theme/unishop2'
BOOTSTRAP=Path(os.environ['LAVKA_TEST_BOOTSTRAP_JS']).read_text()
scope={'__file__':str(ROOT/'tests/check_product_tags.py')}
exec((ROOT/'tests/check_product_tags.py').read_text().split('with sync_playwright() as p:',1)[0],scope)
CSS=scope['CSS']+(THEME/'stylesheet/lavka-card-reviews.css').read_text()


def content(width,language='uk'):
    cards=(scope['card'](816)+scope['card'](817)).replace('<div class="product-thumb__row">','<div class="product-thumb__rating rating"><i class="fa fa-star"></i></div><div class="product-thumb__row">')
    return '<html lang="'+language+'"><head><base href="https://fixture.invalid/"><style>'+CSS+'</style></head><body><main><div class="container"><div id="content"><div class="products-block row">'+cards+'</div></div></div></main></body></html>'


def scripts(page):
    page.add_script_tag(content=scope['JQ']);page.add_script_tag(content=BOOTSTRAP)
    page.add_script_tag(content=scope['MOCK'])
    for name in ('lavka-mobile-cards.js','lavka-product-tags.js','lavka-card-reviews.js'):
        page.add_script_tag(content=(THEME/'js'/name).read_text())


def answer(page,products,summaries,index=0,enabled=True):
    page.wait_for_function('requests.length>'+str(index))
    page.evaluate('(data)=>{requests[data.index].deferred.resolve({products:data.products,review_enabled:data.enabled,reviews:data.summaries});}',{'index':index,'products':{str(k):v for k,v in products.items()},'summaries':{str(k):v for k,v in summaries.items()},'enabled':enabled})


with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for width in (320,390,432,1280):
        page=browser.new_page(viewport={'width':width,'height':1000});errors=[]
        page.on('pageerror',lambda error:errors.append(str(error)))
        page.set_content(content(width));scripts(page)
        answer(page,{816:[],817:[]},{816:{'total':12,'rating':4.8},817:{'total':0,'rating':0}})
        links=page.locator('.lavka-card-reviews');page.wait_for_function('document.querySelectorAll(".lavka-card-reviews").length===1')
        assert links.first.locator('.lavka-review-stars svg path').count()==10
        assert links.first.locator('.lavka-card-reviews__count').inner_text()=='12'
        empty=page.locator('.product-thumb').nth(1)
        assert empty.locator('.lavka-card-reviews,.lavka-review-chat').count()==0
        assert not empty.evaluate('(el)=>el.classList.contains("lavka-has-reviews")')
        assert not empty.locator('.product-thumb__rating').is_visible()
        assert urlsplit(links.first.get_attribute('href')).fragment=='tab-review'
        if width<520:
            links.first.wait_for(state='visible');page.wait_for_timeout(80)
            ratio=links.first.locator('.lavka-review-stars').evaluate('(el)=>el.lastChild.getBoundingClientRect().width/el.getBoundingClientRect().width')
            assert abs(ratio-.96)<.01,ratio
            card=page.locator('.product-thumb').first
            assert card.evaluate('(el)=>el.classList.contains("lavka-reviews-inline")')==(width>=390)
            box=links.first.bounding_box();price=card.locator('.product-thumb__price').bounding_box();cart=card.locator('.product-thumb__add-to-cart').bounding_box()
            assert cart['width']==36 and cart['height']==36
            if width>=390:
                assert price['x']+price['width']<=box['x'] and box['x']+box['width']<=cart['x'],(width,price,box,cart)
                assert abs(box['y']+box['height']/2-cart['y']-cart['height']/2)<1
                assert abs((box['x']+box['width']/2)-(price['x']+price['width']+cart['x'])/2)<1
                assert abs(price['y']-cart['y'])<1
            else: assert box['y']+box['height']<=price['y']
            page.screenshot(path='/tmp/lavka-card-reviews-'+str(width)+'.png',full_page=True)
            card.locator('.product-thumb__price').evaluate('(el)=>el.textContent="123456789 грн"')
            page.wait_for_function('document.querySelector(".product-thumb").classList.contains("lavka-reviews-below")')
            card.locator('.product-thumb__price').evaluate('(el)=>el.textContent="25 грн"')
            if width>=390: page.wait_for_function('document.querySelector(".product-thumb").classList.contains("lavka-reviews-inline")')
            page.wait_for_timeout(120);assert page.evaluate('requests.length')==1,'Price/layout changes must not request metadata again'
        else:
            assert not links.first.is_visible(),'Desktop list keeps native rating'
            assert page.locator('.product-thumb__rating').first.is_visible()
        page.evaluate('(html)=>document.querySelector(".products-block").insertAdjacentHTML("beforeend",html)',scope['card'](818))
        answer(page,{818:[]},{818:{'total':7,'rating':3.5}},index=1)
        assert page.locator('.lavka-card-reviews').count()==2
        assert page.locator('.lavka-card-reviews__count').last.inner_text()=='7'
        assert page.evaluate('requests[1].options.data.product_ids')==[818]
        page.evaluate('window.dispatchEvent(new Event("focus"))')
        answer(page,{816:[],817:[],818:[]},{816:{'total':0,'rating':0},817:{'total':0,'rating':0},818:{'total':7,'rating':3.5}},index=2)
        assert page.locator('.lavka-card-reviews').count()==1,'Removing the last published review removes its widget'
        page.evaluate('window.dispatchEvent(new Event("focus"))')
        answer(page,{816:[],817:[],818:[]},{},index=3,enabled=False)
        assert page.locator('.lavka-card-reviews').count()==0
        assert not errors,errors
        print('PASS reviews: %spx, two rows, continuous 96%% fill, empty gap, layout/price changes and disabled reviews'%width,flush=True)
        page.close()

    for language in ('ru','uk','en'):
        page=browser.new_page(viewport={'width':390,'height':900});page.set_content(content(390,language));scripts(page)
        answer(page,{816:[],817:[]},{816:{'total':0,'rating':0},817:{'total':0,'rating':0}})
        assert page.locator('.lavka-card-reviews,.lavka-review-chat').count()==0
        assert page.locator('.lavka-has-reviews').count()==0
        page.close()
    print('PASS reviews: no empty CTA or chat icon in RU/UA/EN',flush=True)

    html='<html lang="uk"><head><base href="https://fixture.invalid/"><style>'+CSS+'</style></head><body><div class="product-page"><div class="product-page__image-main"><div class="sticker" data-lavka-product-id="818" hidden></div></div><div class="product-page__rating rating">'+('<i class="fa fa-star"></i>'*5)+'<a class="product-page__rating-a" onclick="$(\'.tab-review\').click();return false;">Відгуки: 0</a></div><ul class="nav nav-tabs"><li class="active"><a href="#tab-description" data-toggle="tab">Опис</a></li><li><a href="#tab-review" class="tab-review" data-toggle="tab">Відгуки</a></li></ul><div class="tab-content"><div class="tab-pane active" id="tab-description">Опис</div><div class="tab-pane" id="tab-review"><div class="review-info__score"></div><div class="review-info__total"></div><div class="review-info__rating rating">'+('<i class="fa fa-star"></i>'*5)+'</div><form id="form-review" class="collapse"><input name="name"><textarea name="text"></textarea><button type="button" id="button-review">Додати</button></form></div></div></div></body></html>'
    for fragment in ('form-review','tab-review'):
        page=browser.new_page(viewport={'width':390,'height':900})
        page.route('https://fixture.invalid/**',lambda route:route.fulfill(status=200,content_type='text/html',body=html))
        page.goto('https://fixture.invalid/product-818#'+fragment);scripts(page)
        answer(page,{818:[]},{818:{'total':12,'rating':4.8}})
        page.locator('#tab-review').wait_for(state='visible')
        assert page.locator('.product-page__rating-a').inner_text()=='Відгуки: 12'
        assert page.locator('.product-page__rating .fa-star').count()==0
        if fragment=='form-review': page.locator('#form-review').wait_for(state='visible')
        else: assert not page.locator('#form-review').is_visible()
        assert page.evaluate('requests.length')==1,'Opening reviews must not submit a review'
        page.evaluate('window.dispatchEvent(new Event("focus"))')
        answer(page,{818:[]},{818:{'total':0,'rating':0}},index=1)
        assert not page.locator('.product-page__rating').is_visible()
        assert not page.locator('.review-info__rating').is_visible()
        assert page.locator('.lavka-review-chat').count()==0
        assert page.locator('#form-review').count()==1,'Native form remains available'
        page.close()
    print('PASS product: native Bootstrap review tab/form open, matching fractional rating/count, zero summary hidden, no review submission',flush=True)
    browser.close()
