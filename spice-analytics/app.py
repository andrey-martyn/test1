from __future__ import annotations
import argparse
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import sqlite3
import threading
import time
from datetime import date, datetime
from zoneinfo import ZoneInfo

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import FileResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from analytics import Analytics

ROOT = Path(__file__).parent
DATA = Path(os.environ.get('ANALYTICS_DATA_DIR',str(ROOT/'data')))
DATABASE = DATA/'analytics.sqlite3'
AUTH_FILE = DATA/'access.json'

def set_password(password):
    if len(password) < 12:
        raise ValueError('Пароль должен содержать минимум 12 символов')
    DATA.mkdir(parents=True,exist_ok=True)
    salt = secrets.token_bytes(16)
    hashed = hashlib.scrypt(password.encode(),salt=salt,n=16384,r=8,p=1).hex()
    payload = {'salt':salt.hex(),'hash':hashed,'secret':secrets.token_hex(32)}
    fd = os.open(AUTH_FILE,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
    with os.fdopen(fd,'w') as f:
        json.dump(payload,f)
    AUTH_FILE.chmod(0o600)

def check_password(password):
    if not AUTH_FILE.exists():
        return False
    payload = json.loads(AUTH_FILE.read_text())
    hashed = hashlib.scrypt(password.encode(),salt=bytes.fromhex(payload['salt']),n=16384,r=8,p=1).hex()
    return hmac.compare_digest(hashed,payload['hash'])

app = FastAPI(title='Лавка · Аналитика',docs_url=None,redoc_url=None,openapi_url=None)
app.mount('/static',StaticFiles(directory=ROOT/'static'),name='static')
sessions = {}
attempts = {}
lock = threading.RLock()
store = None

def get_store():
    global store
    with lock:
        if store is None:
            if not DATABASE.exists():
                raise HTTPException(503,'Сначала импортируйте SQL-выгрузку')
            store = Analytics(DATABASE)
        return store

@app.middleware('http')
async def security(request,call_next):
    # Same-origin mutations only, including login; no permissive CORS.
    if request.method not in ('GET','HEAD','OPTIONS'):
        origin = request.headers.get('origin')
        if origin and origin.rstrip('/') != str(request.base_url).rstrip('/'):
            return JSONResponse({'detail':'Недопустимый источник запроса'},status_code=403)
    public = request.url.path in ('/login','/api/login','/health') or request.url.path.startswith('/static/')
    if not public:
        session = sessions.get(request.cookies.get('session',''))
        if not session or session < time.time():
            if request.url.path.startswith('/api/'):
                return JSONResponse({'detail':'Войдите в кабинет'},status_code=401)
            return RedirectResponse('/login',status_code=303)
    response = await call_next(request)
    response.headers['Cache-Control'] = 'no-store' if not request.url.path.startswith('/static/') else 'private, max-age=60'
    response.headers['X-Content-Type-Options'] = 'nosniff'
    response.headers['X-Frame-Options'] = 'DENY'
    response.headers['Referrer-Policy'] = 'same-origin'
    response.headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    return response

@app.get('/health')
def health():
    return {'status':'ok','database_present':DATABASE.exists()}

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
    ip = request.client.host
    with lock:
        recent = [t for t in attempts.get(ip,[]) if t > time.time()-300]
        if len(recent) >= 10:
            raise HTTPException(429,'Слишком много попыток. Подождите пять минут.')
        if not check_password(body.password):
            attempts[ip] = recent+[time.time()]
            raise HTTPException(401,'Неверный пароль')
        attempts.pop(ip,None)
        token = secrets.token_urlsafe(32)
        sessions[token] = time.time()+12*3600
    response = JSONResponse({'ok':True})
    response.set_cookie('session',token,httponly=True,samesite='strict',max_age=12*3600,secure=request.url.scheme=='https')
    return response

@app.post('/api/logout')
def logout(request: Request):
    sessions.pop(request.cookies.get('session',''),None)
    response = JSONResponse({'ok':True})
    response.delete_cookie('session')
    return response

@app.get('/api/meta')
def meta():
    return {**get_store().metadata(),'today':datetime.now(ZoneInfo('Europe/Kyiv')).date().isoformat()}

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
