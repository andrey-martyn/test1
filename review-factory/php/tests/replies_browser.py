"""Reply UI checks against a private synthetic fixture; no live store access."""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

base=os.environ.get('RF_TEST_URL','http://127.0.0.1:8093')
password=os.environ.get('RF_TEST_PASSWORD')
if not password:
    password=dict(line.split('=',1) for line in Path(os.environ['RF_TEST_ENV_FILE']).read_text().splitlines())['RF_TEST_PASSWORD']
checks=[]
def check(ok,message):
    assert ok,message
    checks.append(message)

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=shutil.which('chromium'),args=['--no-sandbox'])
    ctx=browser.new_context(viewport={'width':390,'height':844},locale='ru-RU')
    page=ctx.new_page();errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(base)
    page.locator('input[name=login]').fill('reply-demo')
    page.locator('input[name=password]').fill(password)
    page.get_by_role('button',name='Войти в мастерскую').click()
    page.get_by_role('button',name='Ответить',exact=True).wait_for()
    check(page.locator('nav').get_by_role('button',name='Написать материал').count()==0,'Reply-only account cannot create staff reviews')
    check(page.evaluate('state.view')=='replies','Reply workspace is the default')
    csrf=page.evaluate('state.csrf')
    check(ctx.request.post(base+'/?route=claim',data={'count':1},headers={'Origin':base,'X-CSRF-Token':csrf}).status==403,'Reply-only account cannot reserve products through API')
    check(page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'),'390px reply list fits viewport')
    page.get_by_role('button',name='Ответить',exact=True).click()
    page.locator('#reply-editor').wait_for()
    check('3 / 5' in page.locator('.reply-review-meta').inner_text(),'Original rating is displayed without controls')
    check(page.get_by_role('radio').count()==0,'Reply form contains no review rating controls')
    text='Спасибо за вопрос! Храните корицу в плотно закрытой упаковке.'
    page.locator('#reply-body').fill(text)
    page.get_by_role('button',name='Сохранить черновик',exact=True).click()
    page.locator('#reply-save-state').filter(has_text='Черновик сохранён').wait_for()
    check(not page.evaluate('state.dirty'),'Reply draft saves on server')
    page.reload();page.get_by_role('button',name='Ответить',exact=True).wait_for();page.get_by_role('button',name='Ответить',exact=True).click();page.locator('#reply-editor').wait_for()
    check(page.locator('#reply-body').input_value()==text,'Reply draft survives reload')
    check(page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'),'390px reply editor fits viewport')
    output=Path(os.environ.get('RF_TEST_OUTPUT','/tmp/rf-replies'))
    output.mkdir(parents=True,exist_ok=True)
    page.screenshot(path=str(output/'replies-mobile.png'),full_page=True)
    page.set_viewport_size({'width':1440,'height':1000});page.screenshot(path=str(output/'replies-desktop.png'),full_page=True)
    page.get_by_role('button',name='Сохранить ответ на TEST').click()
    page.locator('.reply-banner').wait_for()
    check(page.locator('#reply-body').is_disabled(),'Queued reply is immutable until processed')
    source=ctx.request.get(base+'/?route=replies/17').json()
    check(source['rating'] in [3,'3'] and source['shop_reply']=='','HTTP submission preserves original and does not pretend MySQL is updated')
    page.get_by_role('button',name='Мои ответы',exact=True).click();page.locator('table').wait_for()
    check('Ожидает сохранения' in page.locator('table').inner_text(),'History distinguishes pending and applied replies')
    author=browser.new_context()
    login=author.request.post(base+'/?route=login',data={'login':'author-demo','password':password},headers={'Origin':base})
    token=login.json()['csrf']
    check(author.request.get(base+'/?route=replies').status==403,'Ordinary author cannot read reply queue')
    check(author.request.post(base+'/?route=reply-drafts',data={'review_id':17,'body':'Чужой ответ'},headers={'Origin':base,'X-CSRF-Token':token}).status==403,'Ordinary author cannot submit store replies')
    check(not errors,'No JavaScript errors')
    browser.close()
print(json.dumps({'state':'ok','checks':len(checks),'widths':[390,1440]},ensure_ascii=False))
