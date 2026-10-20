from __future__ import annotations
import argparse
import json
import os
from pathlib import Path
import sqlite3
import threading
from datetime import date, datetime
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import FileResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from analytics import Analytics
from auth import Auth, LIFETIME, set_password as write_password
from db_state import bump_revision, local_revision

ROOT = Path(__file__).parent
DATA = Path(os.environ.get('ANALYTICS_DATA_DIR',str(ROOT/'data')))
DATABASE = DATA/'analytics.sqlite3'
AUTH_FILE = DATA/'access.json'
PUBLIC_URL = os.environ.get('ANALYTICS_PUBLIC_URL', '').rstrip('/')
if PUBLIC_URL:
    parsed_url = urlsplit(PUBLIC_URL)
    if (parsed_url.scheme != 'https' or not parsed_url.hostname or parsed_url.username
            or parsed_url.password or parsed_url.path or parsed_url.query or parsed_url.fragment):
        raise RuntimeError('ANALYTICS_PUBLIC_URL должен быть HTTPS-адресом кабинета без пути')

def set_password(password):
    write_password(AUTH_FILE, password)

def check_password(password):
    return Auth(DATA, AUTH_FILE).check_password(password)

app = FastAPI(title='Лавка · Аналитика',docs_url=None,redoc_url=None,openapi_url=None)
app.mount('/static',StaticFiles(directory=ROOT/'static'),name='static')
lock = threading.RLock()
store = None
store_revision = None

def database_revision():
    if not DATABASE.exists():
        raise HTTPException(503,'Сначала загрузите данные магазина')
    with sqlite3.connect(DATABASE, timeout=15) as con:
        try:
            revision = con.execute('SELECT max(id) FROM imports').fetchone()[0]
            if revision is None:
                raise HTTPException(503,'Первое обновление магазина ещё не завершено')
            return revision, local_revision(con)
        except sqlite3.OperationalError:
            raise HTTPException(503,'Сначала загрузите данные магазина') from None

def get_store():
    global store, store_revision
    with lock:
        revision = database_revision()
        if store is None or revision != store_revision:
            store = Analytics(DATABASE)
            store_revision = revision
        return store

@app.middleware('http')
async def security(request,call_next):
    if PUBLIC_URL:
        expected = urlsplit(PUBLIC_URL)
        if request.headers.get('host', '').lower() != expected.netloc.lower():
            return JSONResponse({'detail':'Недопустимый адрес кабинета'}, status_code=400)
        if request.url.scheme != 'https':
            return RedirectResponse(PUBLIC_URL + request.url.path, status_code=307)
    # Same-origin mutations only, including login; no permissive CORS.
    if request.method not in ('GET','HEAD','OPTIONS'):
        origin = request.headers.get('origin')
        if (request.headers.get('sec-fetch-site') == 'cross-site'
                or origin and origin.rstrip('/') != (PUBLIC_URL or str(request.base_url).rstrip('/'))):
            return JSONResponse({'detail':'Недопустимый источник запроса'},status_code=403)
    public = request.url.path in ('/login','/api/login','/health') or request.url.path.startswith('/static/')
    if not public:
        if not Auth(DATA, AUTH_FILE).valid(request.cookies.get('session','')):
            if request.url.path.startswith('/api/'):
                return JSONResponse({'detail':'Войдите в кабинет'},status_code=401)
            return RedirectResponse('/login',status_code=303)
    return await call_next(request)

@app.middleware('http')
async def response_headers(request, call_next):
    response = await call_next(request)
    response.headers['Cache-Control'] = 'no-store' if not request.url.path.startswith('/static/') else 'private, max-age=60'
    response.headers['X-Content-Type-Options'] = 'nosniff'
    response.headers['X-Frame-Options'] = 'DENY'
    response.headers['Referrer-Policy'] = 'same-origin'
    if PUBLIC_URL:
        response.headers['Strict-Transport-Security'] = 'max-age=31536000'
    response.headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive'
    response.headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    return response

@app.get('/health')
def health():
    return {'status':'ok'}

@app.get('/login')
def login_page():
    return FileResponse(ROOT/'static'/'login.html')

@app.get('/')
def index():
    return FileResponse(ROOT/'static'/'index.html')

class Login(BaseModel):
    password: str = Field(max_length=512)

@app.post('/api/login')
def login(body: Login,request: Request):
    if not AUTH_FILE.exists():
        raise HTTPException(503,'Сначала настройте пароль кабинета')
    try:
        token = Auth(DATA, AUTH_FILE).login(body.password, request.client.host if request.client else 'unknown')
    except PermissionError as exc:
        if str(exc) == 'rate_limit':
            raise HTTPException(429,'Слишком много попыток. Подождите пять минут.')
        raise HTTPException(401,'Неверный пароль') from None
    response = JSONResponse({'ok':True})
    response.set_cookie('session',token,httponly=True,samesite='strict',max_age=LIFETIME,secure=bool(PUBLIC_URL) or request.url.scheme=='https')
    return response

@app.post('/api/logout')
def logout(request: Request):
    Auth(DATA, AUTH_FILE).logout(request.cookies.get('session',''))
    response = JSONResponse({'ok':True})
    response.delete_cookie('session')
    return response

@app.get('/api/meta')
def meta():
    from sync_mysql import sync_status
    return {**get_store().metadata(),'today':datetime.now(ZoneInfo('Europe/Kyiv')).date().isoformat(),
            'production':bool(PUBLIC_URL),'names_visible':bool(PUBLIC_URL), 'sync':sync_status(DATABASE)}

@app.get('/api/report')
def report(year:int,as_of:date,currency:str='UAH'):
    if not 2000 <= year <= 2100:
        raise HTTPException(422,'Неверный год')
    s = get_store()
    if currency not in s.metadata()['currencies']:
        raise HTTPException(422,'Неизвестная валюта')
    return s.report(year,as_of.isoformat(),currency)

@app.get('/api/orders/{oid}')
def order(oid:str):
    s = get_store()
    if oid not in s.orders:
        raise HTTPException(404,'Заказ не найден')
    return s.order_details(oid)

@app.get('/api/customers/{cid}/orders')
def customer_orders(cid:str,year:int,as_of:date,currency:str='UAH'):
    s = get_store()
    rep = s.report(year,as_of.isoformat(),currency)
    c = next((c for c in rep['customers'] if c['id']==cid),None)
    if not c:
        raise HTTPException(404,'Покупатель не найден')
    return {'customer':c,'orders':[s.order_details(oid) for oid in reversed(c['order_ids'])]}

class Settings(BaseModel):
    interval_days:int = Field(ge=1,le=730)
    grace_days:int = Field(ge=0,le=365)
    minimum_orders:int = Field(ge=3,le=30)
    status_ids:list[str]
    followup_min_orders:int = Field(default=2,ge=1,le=1000)
    contact_cooldown_days:int = Field(default=7,ge=0,le=365)

@app.put('/api/settings')
def save_settings(body:Settings):
    global store
    with lock:
        s = get_store()
        if not body.status_ids or not set(body.status_ids) <= s.statuses.keys():
            raise HTTPException(422,'Выберите хотя бы один известный статус')
        with sqlite3.connect(DATABASE) as con:
            con.execute("INSERT OR REPLACE INTO settings VALUES ('report',?)",(body.model_dump_json(),))
            bump_revision(con)
        store = Analytics(DATABASE)
    return {'ok':True}

class CustomerAction(BaseModel):
    stage:str = Field(pattern='^(planned|contacted|replied|declined|do_not_contact|new)$')
    contact_date:date|None = None
    next_contact:date|None = None
    note:str = Field(default='',max_length=3000)

@app.put('/api/customers/{cid}/action')
def save_action(cid:str,body:CustomerAction):
    global store
    with lock:
        s=get_store()
        if cid not in s.orders:raise HTTPException(404,'Покупатель не найден')
        payload={**body.model_dump(mode='json'),'updated_at':datetime.now(ZoneInfo('Europe/Kyiv')).isoformat()}
        if body.stage in ('contacted','replied') and body.contact_date is None:
            payload['contact_date']=datetime.now(ZoneInfo('Europe/Kyiv')).date().isoformat()
        with sqlite3.connect(DATABASE) as con:
            con.execute('INSERT OR REPLACE INTO customer_actions VALUES (?,?)',(cid,json.dumps(payload,ensure_ascii=False)))
            bump_revision(con)
        store=Analytics(DATABASE)
    return {'ok':True}

class GA4Import(BaseModel):
    kind:str = Field(pattern='^(daily|transactions)$')
    currency:str = Field(pattern='^[A-Z]{3}$')
    content:str = Field(max_length=2_000_000)

@app.post('/api/ga4/import')
def ga4_import(body:GA4Import):
    global store
    import ga4
    with lock:
        get_store()
        try:result=ga4.import_csv(DATABASE,body.content,body.kind,body.currency)
        except ValueError as e:raise HTTPException(422,str(e)) from None
        store=Analytics(DATABASE)
    return result

class Merge(BaseModel):
    ids:list[str] = Field(min_length=2,max_length=20)
    as_of:date

@app.post('/api/identity/merge')
def merge(body:Merge):
    global store
    with lock:
        s = get_store()
        if len(set(body.ids)) < 2 or any(cid not in s.orders for cid in body.ids):
            raise HTTPException(422,'Неверные идентификаторы')
        if any(s.orders[cid]['date_added'][:10] > body.as_of.isoformat() for cid in body.ids):
            raise HTTPException(422,'Заказ позже даты отчёта')
        with sqlite3.connect(DATABASE) as con:
            for other in body.ids[1:]:
                a,b = sorted((body.ids[0],other),key=int)
                con.execute('INSERT OR IGNORE INTO manual_links VALUES (?,?,?)',(a,b,datetime.now().isoformat()))
            bump_revision(con)
        store = Analytics(DATABASE)
    return {'ok':True}

class Unmerge(BaseModel):
    id:str
    as_of:date

@app.post('/api/identity/unmerge')
def unmerge(body:Unmerge):
    global store
    with lock:
        s = get_store()
        before = [o for o in s.orders.values() if o['date_added'][:10] <= body.as_of.isoformat()]
        dsu = s.identity(before)
        if body.id not in dsu.parents:
            raise HTTPException(404,'Покупатель не найден')
        root = dsu.find(body.id)
        ids = {oid for oid in dsu.parents if dsu.find(oid)==root}
        with sqlite3.connect(DATABASE) as con:
            for a,b in s.manual_links:
                if a in ids or b in ids:
                    con.execute('DELETE FROM manual_links WHERE a=? AND b=?',(a,b))
            bump_revision(con)
        store = Analytics(DATABASE)
    return {'ok':True}

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--init-password',action='store_true')
    parser.add_argument('--port',type=int,default=8000)
    args = parser.parse_args()
    if args.init_password:
        import getpass
        first = getpass.getpass('Новый пароль (минимум 12 символов): ')
        if first != getpass.getpass('Повторите пароль: '):
            raise SystemExit('Пароли не совпадают')
        set_password(first)
        print('Пароль сохранён. Значение не записывается в журнал.')
    else:
        if not AUTH_FILE.exists():
            raise SystemExit('Сначала задайте пароль: python app.py --init-password')
        import uvicorn
        uvicorn.run(app,host='127.0.0.1',port=args.port)
