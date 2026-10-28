"""Synthetic HTTP and browser checks; never use a store's accounts or database."""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

base = os.environ.get('RF_TEST_URL', 'http://127.0.0.1:8093')
password = os.environ.get('RF_TEST_PASSWORD')
if not password:
    env_file = os.environ['RF_TEST_ENV_FILE']
    password = dict(line.split('=', 1) for line in Path(env_file).read_text().splitlines())['RF_TEST_PASSWORD']
output = Path(os.environ.get('RF_TEST_OUTPUT', '/tmp/rf-browser'))
output.mkdir(parents=True, exist_ok=True)
errors = []
checks = []

def check(value, message):
    assert value, message
    checks.append(message)

def login(page, login_name):
    page.goto(base)
    page.locator('input[name=login]').fill(login_name)
    page.locator('input[name=password]').fill(password)
    page.get_by_role('button', name='Войти в мастерскую').click()
    page.locator('#writer').wait_for()

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1100}, locale='ru-RU')
    page = context.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
    check(context.request.get(base + '/?route=me').status == 401, 'Unauthenticated API denied')
    login(page, 'tanya-demo')
    check(page.locator('#account').inner_text().startswith('Таня'), 'Personal profile displayed')
    description = page.locator('details').filter(has=page.locator('summary', has_text='Описание и характеристики'))
    check(description.get_attribute('open') is None, 'Long product description starts collapsed')
    description.locator('summary').click()
    check(description.get_attribute('open') is not None, 'Description can be expanded')
    description.locator('summary').click()
    page.screenshot(path=str(output / 'review-workshop-desktop.png'), full_page=True)
    product_id = page.evaluate('state.product.id')
    csrf = page.evaluate('state.csrf')
    bad = context.request.post(base + '/?route=claim', data={'count': 1}, headers={'Origin': base})
    check(bad.status == 403, 'Missing CSRF denied')
    cross = context.request.post(base + '/?route=claim', data={'count': 1}, headers={'Origin': 'https://example.com', 'X-CSRF-Token': csrf})
    check(cross.status == 403, 'Cross-origin mutation denied')
    page.set_viewport_size({'width': 390, 'height': 844})
    check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), '390px layout fits viewport')
    page.screenshot(path=str(output / 'review-workshop-mobile.png'), full_page=True)
    page.get_by_role('radio', name='4 из 5').check()
    body = 'Я использую эту корицу в выпечке. У неё приятный аромат и мягкий вкус.'
    page.locator('#body').fill(body)
    page.get_by_role('tab', name='Смотрю подсказки').click()
    check(page.locator('.references').is_visible(), 'Mobile references are one tap away')
    page.get_by_role('tab', name='Пишу материал').click()
    check(page.locator('#body').input_value() == body, 'Mobile pane switching preserves text')
    page.get_by_role('button', name='Сохранить черновик', exact=True).click()
    page.locator('#save-state').filter(has_text='Сохранено').wait_for()
    draft_id = page.evaluate('state.product.draft.id')
    check(page.locator('#save-state').inner_text().startswith('Сохранено'), 'Draft saved to server')
    page.reload()
    page.locator('#writer').wait_for()
    check(page.locator('#body').input_value() == body, 'Draft survives reload')
    page.get_by_role('button', name='Просмотр', exact=True).click()
    check(page.locator('dialog').is_visible(), 'Accessible preview opens')
    page.get_by_role('button', name='Вернуться к тексту').click()
    page.get_by_role('button', name='Отправить на модерацию').click()
    page.wait_for_function('() => !state.busy && state.product !== null && state.product.id !== ' + json.dumps(product_id))
    check(page.locator('#body').input_value() == '', 'Next product opens after submission')
    page.get_by_role('button', name='Мои материалы', exact=True).click()
    page.locator('table').wait_for()
    check('Ожидает отправки' in page.locator('table').inner_text(), 'Queued state visible without pretending published')
    # An author has a separate context and cannot inspect another author's draft.
    author_context = browser.new_context(viewport={'width': 390, 'height': 844})
    author = author_context.new_page()
    author.on('pageerror', lambda error: errors.append(str(error)))
    login(author, 'author-demo')
    check(author_context.request.get(base + '/?route=users').status == 403, 'Author cannot list accounts')
    check(author_context.request.get(base + '/?route=products/' + str(product_id)).status == 403, 'Author cannot open another assigned product')
    author_csrf = author.evaluate('state.csrf')
    denied = author_context.request.post(base + '/?route=drafts/' + draft_id + '/submit', data={'revision': 1}, headers={'Origin': base, 'X-CSRF-Token': author_csrf})
    check(denied.status == 404, 'Author cannot submit another author material')
    check('Команда и настройки' not in author.locator('nav').inner_text(), 'Admin navigation hidden from author')
    # Input and source text render as text, never HTML.
    author.locator('#body').fill('<img src=x onerror=alert(1)> Это текст, который проверяет безопасное отображение.')
    author.get_by_role('button', name='Просмотр', exact=True).click()
    check(author.locator('dialog img').count() == 0, 'Review preview escapes HTML')
    check(not errors, 'No JavaScript errors')
    browser.close()

print(json.dumps({'state': 'ok', 'checks': len(checks), 'widths': [390, 1440]}, ensure_ascii=False))
