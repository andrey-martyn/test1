"""Browser checks with native shop CSS/scripts and mocked HTTP; never calls a shop."""
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
THEME=ROOT/'store/catalog/view/theme/unishop2'
CSS=''.join((THEME/'stylesheet'/name).read_text() for name in ('bootstrap.min.css','stylesheet.css','lavka-mobile-cards.css','lavka-product-tags.css'))
JQ=(THEME/'js/jquery-2.2.4.min.js').read_text()
MOCK="""window.requests=[];$.ajax=function(options){var d=$.Deferred();requests.push({options:options,deferred:d});return d.promise();};"""


def card(pid):
    weights=''.join('<label class="option__item"><input type="radio" name="option[70]" value="%s"><span class="option__name">%s г</span></label>'%(n,n) for n in (20,50,100,200,500,1000))
    return '<div class="product-layout"><div class="product-thumb uni-item"><div class="product-thumb__image"><div class="sticker"><div class="sticker__item mpn">Old</div><div class="sticker__item special">Акція</div></div><a><img alt="Фото" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect width=\'200\' height=\'200\' fill=\'beige\'/%3E%3C/svg%3E"></a></div><div class="product-thumb__caption"><a class="product-thumb__name">Тестовий товар</a><div class="product-thumb__option option"><div class="option__group"><label class="option__group-name">Фасовка</label>'+weights+'</div></div><div class="product-thumb__row"><div class="product-thumb__price">45 грн</div><div class="product-thumb__cart"><button class="product-thumb__add-to-cart" data-pid="'+str(pid)+'">Купити</button><button class="product-thumb__wishlist">♡</button></div></div></div></div></div>'


tags=[dict(name='lavka-'+code,text=label,value='',text_after='',background=bg,color='#ffffff') for code,label,bg in (
    ('new','Новинка','#7c9b42'),('hit','Хіт продажів','#bb8a26'),('fresh','Свіжий помел','#4f7f28'),('brand','Наша марка','#63745e'),('exclusive','Ексклюзив','#8c3445'))]


with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for width in (320,390,432,1280):
        page=browser.new_page(viewport={'width':width,'height':1000})
        errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        page.set_content('<html><head><base href="https://fixture.invalid/"><style>'+CSS+'</style></head><body><main><div class="container"><div id="content"><div class="products-block row">'+card(816)+card(816)+'</div></div></div></main></body></html>')
        page.add_script_tag(content=JQ);page.add_script_tag(content=MOCK)
        page.add_script_tag(content=(THEME/'js/lavka-mobile-cards.js').read_text())
        page.add_script_tag(content=(THEME/'js/lavka-product-tags.js').read_text())
        page.wait_for_function('requests.length===1')
        assert page.evaluate('requests[0].options.type')=='POST'
        assert page.evaluate('requests[0].options.data.product_ids')==[816]
        shown=tags+[dict(name=code,text=str(1111*(index+1)),value='',text_after='',background='#bc6464',color='#e0bcbc') for index,code in enumerate(('upc','ean','jan','isbn'))] if width==320 else tags
        page.evaluate('(tags)=>requests[0].deferred.resolve({products:{816:tags}})',shown)
        assert page.locator('.sticker__item.lavka-exclusive').count()==2
        assert page.locator('.sticker__item.mpn').count()==0
        assert page.locator('.sticker__item.special').count()==2
        assert page.locator('.lavka-fresh').first.evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(79, 127, 40)'
        assert page.locator('.lavka-fresh').first.evaluate('(e)=>getComputedStyle(e).opacity')=='1'
        if width<520:
            page.wait_for_timeout(80)
            bounds=page.locator('.lavka-mobile-card').first.evaluate('''card=>{var tags=card.querySelector('.sticker').getBoundingClientRect(),packs=card.querySelector('.product-thumb__option').getBoundingClientRect(),image=card.querySelector('.product-thumb__image').getBoundingClientRect(),heart=card.querySelector('.product-thumb__wishlist').getBoundingClientRect();return {tagsBottom:tags.bottom,packsTop:packs.top,tagsRight:tags.right,heartLeft:heart.left,imageBottom:image.bottom,packsBottom:packs.bottom};}''')
            assert bounds['tagsBottom']<bounds['packsTop'],bounds
            assert bounds['tagsRight']<=bounds['heartLeft']+1,bounds
            assert bounds['packsBottom']<=bounds['imageBottom']+1,bounds
            picture=page.locator('.lavka-mobile-card').first.locator('.product-thumb__image img').bounding_box()
            assert picture['y']+picture['height']>=bounds['packsBottom']-1,'Weights must stay on the image'
            page.screenshot(path='/tmp/lavka-product-tags-'+str(width)+'.png',full_page=True)
        page.wait_for_timeout(180)
        assert page.evaluate('requests.length')==1,'Mutation/resize must not trigger request loops'
        page.evaluate('(html)=>document.querySelector(".products-block").insertAdjacentHTML("beforeend",html)',card(817))
        page.wait_for_function('requests.length===2')
        page.evaluate('requests[1].deferred.resolve({products:{817:[]}})')
        assert page.locator('.mpn').count()==0
        page.evaluate('window.dispatchEvent(new Event("focus"))')
        page.wait_for_function('requests.length===3')
        unsafe=[dict(tags[0],text='<img src=x onerror=alert(1)>')]
        page.evaluate('(tags)=>requests[2].deferred.resolve({products:{816:tags,817:[]}})',unsafe)
        assert page.locator('.sticker img').count()==0
        assert '<img' in page.locator('.lavka-new').first.inner_text()
        assert not errors,errors
        print('PASS tags: %spx, all labels, colors, deduplication, cache refresh, safe text'%width,flush=True)
        page.close()

    page=browser.new_page()
    page.set_content('<html><head><base href="https://fixture.invalid/"><style>'+CSS+'</style></head><body><div class="product-page"><div class="product-page__image-main"><div class="sticker" data-lavka-product-id="816" hidden></div></div></div></body></html>')
    page.add_script_tag(content=JQ);page.add_script_tag(content=MOCK)
    page.add_script_tag(content=(THEME/'js/lavka-product-tags.js').read_text())
    page.wait_for_function('requests.length===1')
    page.evaluate('(tags)=>requests[0].deferred.resolve({products:{816:tags}})',tags)
    assert page.locator('.product-page__image-main .sticker__item').count()==5
    assert page.locator('.sticker').is_visible()
    print('PASS product page: initially empty labels refresh even when price/cart/product_id input is hidden',flush=True)
    page.close()
    page=browser.new_page()
    widget='<div class="lavka-tag-controls" data-product-id="816" data-url="https://fixture.invalid/admin/tag" data-saving="Saving" data-saved="Saved" data-error="Error" data-conflict="Conflict"><input type="checkbox" data-tag="hit" data-revision="0"><input type="checkbox" data-tag="brand" data-revision="0"><small class="lavka-tag-status"></small></div>'
    page.set_content('<form><input type="checkbox" name="selected[]">'+widget+widget+'</form>')
    page.add_script_tag(content=JQ);page.add_script_tag(content=MOCK)
    page.add_script_tag(content=(ROOT/'store/admin/view/javascript/lavka-product-tags.js').read_text())
    hit=page.locator('input[data-tag=hit]').first
    hit.check();assert hit.is_disabled();assert page.locator('input[data-tag=brand]').first.is_enabled()
    assert page.locator('input[name="selected[]"]').is_checked() is False
    page.evaluate('requests[0].deferred.resolve({success:true,state:{hit:{enabled:true,revision:1}}})')
    assert hit.is_enabled() and hit.is_checked()
    assert page.locator('input[data-tag=hit]').nth(1).is_checked()
    hit.uncheck()
    page.evaluate('()=>{requests[1].deferred.reject({responseJSON:{error:"TAG_CONFLICT"}});} ')
    assert hit.is_checked() and page.locator('.lavka-tag-status').first.inner_text()=='Conflict'
    assert page.evaluate('requests.length')==2
    print('PASS admin: partial writes, pending state, independent bulk selection, duplicate widget synchronization and conflict',flush=True)
    browser.close()
