"""Authenticated, isolated QA flow. No store connection or publishing."""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

base = os.environ.get('RF_TEST_URL', 'http://127.0.0.1:8093')
password = dict(line.split('=', 1) for line in Path(os.environ['RF_TEST_ENV_FILE']).read_text().splitlines())['RF_TEST_PASSWORD']
checks = []
def check(ok, message):
    assert ok, message
    checks.append(message)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    ctx = browser.new_context(viewport={'width':390, 'height':844}, locale='ru-RU')
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    check(ctx.request.get(base+'/?route=simulation/summary').status == 401, 'Private QA API requires login')
    page.goto(base)
    check('noindex' in ctx.request.get(base).headers.get('x-robots-tag', ''), 'Private page excludes indexing')
    page.locator('input[name=login]').fill('admin-review')
    page.locator('input[name=password]').fill(password)
    page.get_by_role('button', name='Войти в мастерскую').click()
    page.locator('#simulation-writer').wait_for()
    check(page.evaluate('state.view') == 'simulation', 'Admin-review opens review queue')
    check('Ответы магазина' not in page.locator('nav').inner_text(), 'Reply workspace absent from QA navigation')
    first = page.evaluate('simulationState.current.entry')
    check(page.locator('#simulation-body').input_value().startswith('[ТЕСТОВЫЙ ОТЗЫВ'), 'Body is automatically filled and labelled')
    check(page.locator('.simulation-fields input,.simulation-fields select').count() == 0, 'Autofields are read only')
    check(page.get_by_role('radio').count() == 0 and page.locator('input[type=date]').count() == 0, 'No manual rating or date controls')
    description = page.locator('details').filter(has=page.locator('summary', has_text='Описание и характеристики'))
    check(description.get_attribute('open') is None, 'Description starts collapsed')
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), '390px queue fits viewport')
    page.reload(); page.locator('#simulation-writer').wait_for()
    check(page.evaluate('simulationState.current.entry') == first, 'Autofields and text stable after reload')
    body = '[ТЕСТОВЫЙ ОТЗЫВ] Проверка <script>window.evil=1</script> текста на мобильном экране.'
    page.locator('#simulation-body').fill(body)
    page.get_by_role('button', name='Сохранить черновик', exact=True).click()
    page.locator('#simulation-saved').filter(has_text='Тестовый черновик сохранён').wait_for()
    page.reload(); page.locator('#simulation-writer').wait_for()
    check(page.locator('#simulation-body').input_value() == body, 'Edited text survives reload')
    output = Path(os.environ.get('RF_TEST_OUTPUT', '/tmp/rf-simulation'))
    output.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(output/'simulation-mobile.png'), full_page=True)
    page.get_by_role('button', name='Сохранить тестовый отзыв').click()
    page.wait_for_function('(id)=>!state.busy&&simulationState.current.entry.id!==id', arg=first['id'])
    check(page.evaluate('simulationState.current.entry.product_id') != first['product_id'], 'Next product opens automatically')
    check(page.locator('#simulation-body').input_value().startswith('[ТЕСТОВЫЙ ОТЗЫВ'), 'Next product also has automatic text')
    page.get_by_role('button', name='Тестовые записи', exact=True).click(); page.locator('table').wait_for()
    check('Проверка <script>' in page.locator('table').inner_text(), 'Saved test record appears in history')
    page.get_by_role('button', name='Закрытый предпросмотр', exact=True).last.click()
    page.locator('.simulation-preview-list article').wait_for()
    check(page.locator('.simulation-entry-mark').count() == 1, 'Every preview record visibly marked TEST')
    check(not page.evaluate('window.evil'), 'Preview escapes HTML')
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), '390px preview fits viewport')
    page.get_by_role('button', name='Вернуться к очереди').click(); page.locator('#simulation-writer').wait_for()
    page.locator('#simulation-batch').select_option('5')
    page.wait_for_function('()=>simulationState.ids.length===5&&document.querySelectorAll(".assignment-strip button").length===5')
    check(page.locator('.assignment-strip button').count() == 5, 'Packet of five products available')
    packet_first = page.evaluate('simulationState.current.entry.id')
    page.get_by_role('button', name='Сохранить весь тестовый пакет', exact=True).click()
    page.wait_for_function('(id)=>!state.busy&&simulationState.ids.length===5&&simulationState.current.entry.id!==id', arg=packet_first)
    summary = ctx.request.get(base+'/?route=simulation/summary').json()
    check(int(summary['stats']['total']) == 6, 'Whole packet saves without manual text entry')
    page.set_viewport_size({'width':1440,'height':1000})
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), 'Desktop queue fits viewport')
    page.screenshot(path=str(output/'simulation-desktop.png'), full_page=True)
    csrf = page.evaluate('state.csrf')
    check(ctx.request.post(base+'/?route=claim', data={'count':1}, headers={'Origin':base,'X-CSRF-Token':csrf}).status == 403, 'QA operator cannot use native staff submission')
    check(ctx.request.post(base+'/?route=simulation/claim', data={'count':1}, headers={'Origin':base}).status == 403, 'QA mutation requires CSRF')
    check(not errors, 'No JavaScript errors')
    browser.close()
print(json.dumps({'state':'ok','checks':len(checks),'widths':[390,1440]}, ensure_ascii=False))
