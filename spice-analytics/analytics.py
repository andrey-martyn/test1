from __future__ import annotations
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from decimal import Decimal, ROUND_HALF_UP
from functools import lru_cache
from pathlib import Path
from statistics import median
import json
import re
import sqlite3
import unicodedata
from geo import GeoResolver
import marketing
import ga4

ZERO = Decimal('0')
def amount(value):
    return Decimal(str(value or '0'))

def money(value):
    return float(value.quantize(Decimal('0.01'), rounding=ROUND_HALF_UP))

def normalize(value):
    return ' '.join(unicodedata.normalize('NFKC', value or '').casefold().split())

def email_key(value):
    value = normalize(value)
    if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', value):
        return ''
    local = value.split('@')[0]
    if re.match(r'^(?:nomail|no[-_.]?email|no[-_.]?mail|none|null|test|unknown|нет)(?:\d*)$', local):
        return ''
    return value

def phone_key(value):
    digits = re.sub(r'\D', '', value or '')
    if len(digits) == 10 and digits.startswith('0'):
        digits = '38' + digits
    if digits.startswith('00'):
        digits = digits[2:]
    if not 10 <= len(digits) <= 15 or len(set(digits)) <= 2:
        return ''
    return digits

def packaging(options):
    selected = [o for o in options if re.search('фасов|упаков|вага|вес|weight|pack', normalize(o.get('name')))]
    if not selected:
        return {'label':'Не указана', 'grams':None, 'unit':'unknown'}
    if len(selected) != 1:
        return {'label':' / '.join(o['value'] for o in selected), 'grams':None, 'unit':'unknown'}
    label = selected[0]['value'].strip()
    m = re.fullmatch(r'(\d+(?:[.,]\d+)?)\s*(кг|kg|г|гр|g|шт\.?|pcs?\.?)', normalize(label))
    if not m:
        return {'label':label, 'grams':None, 'unit':'unknown'}
    value = amount(m[1].replace(',', '.'))
    if m[2] in ('кг','kg'):
        value *= 1000
    elif m[2] not in ('г','гр','g'):
        return {'label':label, 'grams':None, 'unit':'pieces'}
    return {'label':label, 'grams':float(value), 'unit':'weight'}

class DSU:
    def __init__(self, ids):
        self.parents = {x:x for x in ids}
    def find(self, x):
        if self.parents[x] != x:
            self.parents[x] = self.find(self.parents[x])
        return self.parents[x]
    def union(self, a, b):
        if a in self.parents and b in self.parents:
            a,b = self.find(a),self.find(b)
            self.parents[max(a,b,key=int)] = min(a,b,key=int)

class Analytics:
    def __init__(self, database):
        self.database = Path(database)
        con = sqlite3.connect(self.database)
        con.execute('CREATE TABLE IF NOT EXISTS customer_actions (customer_id TEXT PRIMARY KEY, payload TEXT)')
        self.actions={cid:json.loads(payload) for cid,payload in con.execute('SELECT customer_id,payload FROM customer_actions')}
        self.geo=GeoResolver(self.database.parent)
        raw = defaultdict(list)
        for table, payload in con.execute('SELECT table_name,payload FROM raw_records'):
            raw[table].append(json.loads(payload))
        self.orders = {o['order_id']:o for o in raw['oc_order']}
        self.options = defaultdict(list)
        for option in raw['oc_order_option']:
            self.options[option['order_product_id']].append(option)
        self.lines = defaultdict(list)
        for line in raw['oc_order_product']:
            line['pack'] = packaging(self.options[line['order_product_id']])
            self.lines[line['order_id']].append(line)
        self.totals, self.history = defaultdict(list), defaultdict(list)
        for x in raw['oc_order_total']:
            self.totals[x['order_id']].append(x)
        for x in raw['oc_order_history']:
            self.history[x['order_id']].append(x)
        self.statuses = {'0':'Не оформлен / статус 0'}
        for x in sorted(raw['oc_order_status'], key=lambda x:x['language_id'] != '1'):
            self.statuses.setdefault(x['order_status_id'],x['name'])
        self.import_meta = json.loads(con.execute('SELECT summary FROM imports ORDER BY id DESC LIMIT 1').fetchone()[0])
        self.imported_at = con.execute('SELECT imported_at FROM imports ORDER BY id DESC LIMIT 1').fetchone()[0]
        self.settings = {'interval_days':30, 'grace_days':7, 'minimum_orders':3,'followup_min_orders':2,'contact_cooldown_days':7,
                         'status_ids':[s for s,name in self.statuses.items() if s != '0' and not re.search('отмен|анулир|аннулир|возврат|возмещ|неуда|истек|изменен|cancel|refund|fail|revers|void|denied', name, re.I)]}
        saved = con.execute("SELECT value FROM settings WHERE key='report'").fetchone()
        if saved:
            self.settings.update(json.loads(saved[0]))
        self.manual_links = list(con.execute('SELECT a,b FROM manual_links'))
        con.close()
        self._reports = {}

    def metadata(self):
        dates = [o['date_added'][:10] for o in self.orders.values()]
        status_counts = Counter(o['order_status_id'] for o in self.orders.values())
        return {'counts':self.import_meta['counts'], 'date_min':min(dates), 'date_max':max(dates),
                'years':sorted({date.fromisoformat(d).isocalendar().year for d in dates},reverse=True),
                'currencies':sorted({o['currency_code'] for o in self.orders.values()}),
                'statuses':[{'id':k,'name':v,'count':status_counts[k]} for k,v in sorted(self.statuses.items(),key=lambda x:int(x[0]))],
                'settings':self.settings,'imported_at':self.imported_at,'geoip':self.geo.metadata(),
                'warnings':['Статус «В обработке» не подтверждает оплату. Показаны суммы заказов.',
                            'География: адрес и отдельная приблизительная оценка по локальной GeoIP-базе, если она установлена.',
                            'Импорт полной выгрузки вручную. Автоматическое подключение к магазину ещё не настроено.']}

    def identity(self, orders):
        dsu = DSU([o['order_id'] for o in orders])
        indices = {}
        for o in orders:
            keys = []
            if o.get('customer_id') not in (None,'','0'):
                keys.append(('account',o['customer_id']))
            email,phone = email_key(o['email']),phone_key(o['telephone'])
            if email and phone:
                keys.append(('contact',email,phone))
            for key in keys:
                if key in indices:
                    dsu.union(o['order_id'],indices[key])
                else:
                    indices[key] = o['order_id']
        for a,b in self.manual_links:
            dsu.union(a,b)
        return dsu

    def report(self, year, as_of, currency):
        key = (year,as_of,currency)
        if key not in self._reports:
            if len(self._reports) > 12:
                self._reports.clear()
            self._reports[key] = self._build(year,date.fromisoformat(as_of),currency)
        return self._reports[key]

    def _build(self, year, as_of, currency):
        # Identity may use earlier orders in other currencies, but never future orders.
        before = [o for o in self.orders.values() if o['date_added'][:10] <= as_of.isoformat()]
        dsu = self.identity(before)
        valid = [o for o in before if o['order_status_id'] in self.settings['status_ids'] and o['currency_code'] == currency]
        period = [o for o in valid if date.fromisoformat(o['date_added'][:10]).isocalendar().year == year]
        period_ids = {o['order_id'] for o in period}
        groups = defaultdict(list)
        for o in valid:
            groups[dsu.find(o['order_id'])].append(o)
        customers = []
        for cid, orders in groups.items():
            orders.sort(key=lambda o:(o['date_added'],int(o['order_id'])))
            latest = orders[-1]
            selected = [o for o in orders if o['order_id'] in period_ids]
            dates = sorted({date.fromisoformat(o['date_added'][:10]) for o in orders})
            gaps = [(b-a).days for a,b in zip(dates,dates[1:]) if (b-a).days > 0]
            personal = len(dates) >= self.settings['minimum_orders']
            interval = max(1,round(median(gaps[-6:]))) if personal else self.settings['interval_days']
            days_since = (as_of-dates[-1]).days
            due_on = dates[-1] + timedelta(days=interval)
            overdue = days_since-interval
            reminder = 'overdue' if overdue > self.settings['grace_days'] else 'due' if overdue >= 0 else 'ok'
            weeks = defaultdict(lambda:{'amount':ZERO,'count':0,'order_ids':[]})
            for o in selected:
                w = date.fromisoformat(o['date_added'][:10]).isocalendar().week
                weeks[w]['amount'] += self.order_amount(o)
                weeks[w]['count'] += 1
                weeks[w]['order_ids'].append(o['order_id'])
            emails = sorted({o['email'] for o in orders if o['email']})
            phones = sorted({o['telephone'] for o in orders if o['telephone']})
            ids = [o['order_id'] for o in orders]
            actions=[self.actions[oid] for oid in ids if oid in self.actions]
            action=max(actions,key=lambda a:(a.get('stage') in ('do_not_contact','declined'),a.get('updated_at',''))) if actions else {}
            snoozed=bool(action.get('next_contact') and action['next_contact']>as_of.isoformat())
            contacted=date.fromisoformat(action['contact_date']) if action.get('contact_date') else None
            cooling=contacted is not None and 0<=(as_of-contacted).days<self.settings['contact_cooldown_days']
            eligible=len(orders)>=self.settings['followup_min_orders'] and action.get('stage') not in ('do_not_contact','declined') and not snoozed and not cooling
            customers.append({'id':cid,'name':(' '.join([latest['firstname'],latest['lastname']])).strip() or 'Гость',
                'email':latest['email'],'phone':latest['telephone'],'emails':emails,'phones':phones,
                'city':latest['shipping_city'] or latest['payment_city'],
                'country':latest['shipping_country'] or latest['payment_country'],
                'order_count':len(selected),'lifetime_count':len(orders),
                'amount':money(sum((self.order_amount(o) for o in selected),ZERO)),
                'lifetime_amount':money(sum((self.order_amount(o) for o in orders),ZERO)),
                'average':money(sum((self.order_amount(o) for o in selected),ZERO)/len(selected)) if selected else 0,
                'last_order':dates[-1].isoformat(),'days_since':days_since,'interval_days':interval,
                'personal_interval':personal,'due_on':due_on.isoformat(),'overdue_days':max(0,overdue),
                'reminder':reminder,'followup_eligible':eligible,'action':action,
                'suppression':'Не контактировать' if action.get('stage')=='do_not_contact' else 'Отказ' if action.get('stage')=='declined' else 'Отложено' if snoozed else 'Недавний контакт' if cooling else 'Мало покупок' if len(orders)<self.settings['followup_min_orders'] else '',
                'account_count':len({o['customer_id'] for o in orders if o['customer_id'] != '0'}),
                'manual':any(a in ids or b in ids for a,b in self.manual_links),
                'weeks':{str(w):{**v,'amount':money(v['amount'])} for w,v in weeks.items()},'order_ids':ids})
        customers.sort(key=lambda c:c['amount'],reverse=True)
        products = {}
        weekly = defaultdict(lambda:{'amount':ZERO,'count':0})
        locations = {}
        for o in period:
            week = date.fromisoformat(o['date_added'][:10]).isocalendar().week
            weekly[week]['amount'] += self.order_amount(o)
            weekly[week]['count'] += 1
            cid = dsu.find(o['order_id'])
            for line in self.lines[o['order_id']]:
                k = (line['product_id'],line['pack']['label'])
                if k not in products:
                    products[k] = {'product_id':line['product_id'],'name':line['name'],'model':line['model'],
                                   'pack':line['pack'],'quantity':0,'amount':ZERO,'grams':ZERO,'orders':set(),'customers':Counter()}
                p = products[k]
                p['quantity'] += int(line['quantity'])
                p['amount'] += amount(line['total']) * amount(o['currency_value'])
                if line['pack']['grams'] is not None:
                    p['grams'] += amount(line['pack']['grams']) * int(line['quantity'])
                p['orders'].add(o['order_id'])
                p['customers'][(cid,o['order_id'])] += 1
        product_rows = []
        for p in products.values():
            by_customer = Counter(cid for cid,oid in p['customers'])
            product_rows.append({**p,'amount':money(p['amount']),'grams':float(p['grams']),
                'order_count':len(p['orders']),'customers':len(by_customer),
                'repeat_customers':sum(n > 1 for n in by_customer.values()),'orders':None})
        product_rows.sort(key=lambda p:p['amount'],reverse=True)
        location_modes={mode:self.geography(period,dsu,mode) for mode in ('mixed','address','ip')}
        loc_rows=location_modes['mixed']
        marketing_report=marketing.build(self,customers,groups,period,as_of)
        periods = set(dsu.find(o['order_id']) for o in period)
        total = sum((self.order_amount(o) for o in period),ZERO)
        return {'year':year,'as_of':as_of.isoformat(),'currency':currency,'week_count':date(year,12,28).isocalendar().week,
                'customers':customers,'products':product_rows,'locations':loc_rows,'location_modes':location_modes,
                'geoip':self.geo.metadata(),'marketing':marketing_report,
                'ga4':ga4.report(self.database,year,as_of,currency,period),
                'geo_quality':{'orders':len(period),'public_ip':sum(bool(o.get('ip')) for o in period),
                               'address_without_region':sum(not self.geo.address(o)['zone'] for o in period),
                               'ip_resolved':sum(self.geo.lookup(o.get('ip','')) is not None for o in period)},
                'weekly':{str(w):{**v,'amount':money(v['amount'])} for w,v in weekly.items()},
                'metrics':{'amount':money(total),'order_count':len(period),'customer_count':len(periods),
                           'average':money(total/len(period)) if period else 0,
                           'overdue':sum(c['reminder']=='overdue' and c['followup_eligible'] for c in customers),
                           'repeat_customers':sum(c['lifetime_count'] > 1 for c in customers if c['id'] in periods),
                           'quantity':sum(p['quantity'] for p in product_rows),
                           'kilograms':round(sum(p['grams'] for p in product_rows)/1000,3)},
                'duplicates':self.duplicate_candidates(customers)}

    def geography(self,orders,dsu,mode):
        locations={}
        for o in orders:
            loc=self.geo.resolve(o,mode)
            key=(normalize(loc['country']),normalize(loc['zone']),normalize(loc['city']),loc['source'])
            item=locations.setdefault(key,{**loc,'count':0,'amount':ZERO,'customers':set()})
            item['count']+=1;item['amount']+=self.order_amount(o);item['customers'].add(dsu.find(o['order_id']))
        rows=[{**loc,'amount':money(loc['amount']),'customers':len(loc['customers'])} for loc in locations.values()]
        return sorted(rows,key=lambda r:r['count'],reverse=True)

    def duplicate_candidates(self, customers):
        keys = defaultdict(set)
        by_id = {c['id']:c for c in customers}
        for c in customers:
            for email in c['emails']:
                if k := email_key(email):
                    keys[('email',k)].add(c['id'])
            for phone in c['phones']:
                if k := phone_key(phone):
                    keys[('phone',k)].add(c['id'])
            name = normalize(c['name'])
            if len(name.split()) >= 2:
                keys[('name',name)].add(c['id'])
        candidates = []
        seen = set()
        for (kind,value),ids in sorted(keys.items(),key=lambda kv:({'email':0,'phone':1,'name':2}[kv[0][0]],kv[0][1])):
            if len(ids) < 2 or len(ids) > 20:
                continue
            pairkey = tuple(sorted(ids,key=int))
            if pairkey in seen:
                continue
            seen.add(pairkey)
            candidates.append({'reason':{'email':'Совпадает email','phone':'Совпадает телефон','name':'Совпадает имя — нужна проверка'}[kind],
                               'value':value,'members':[{k:by_id[cid][k] for k in ('id','name','email','phone','lifetime_count','city')} for cid in pairkey]})
        return candidates

    @staticmethod
    def order_amount(o):
        return amount(o['total']) * amount(o['currency_value'])

    def order_details(self, oid):
        o = self.orders[oid]
        return {'id':oid,'date':o['date_added'],'status':self.statuses.get(o['order_status_id'],o['order_status_id']),
                'name':f"{o['firstname']} {o['lastname']}",'email':o['email'],'phone':o['telephone'],
                'city':o['shipping_city'] or o['payment_city'],'zone':o['shipping_zone'] or o['payment_zone'],
                'country':o['shipping_country'] or o['payment_country'],
                'shipping_method':o['shipping_method'],'payment_method':o['payment_method'],
                'amount':money(self.order_amount(o)),'currency':o['currency_code'],
                'lines':[{'name':p['name'],'model':p['model'],'product_id':p['product_id'],'pack':p['pack'],
                          'quantity':int(p['quantity']),'price':money(amount(p['price'])*amount(o['currency_value'])),
                          'total':money(amount(p['total'])*amount(o['currency_value']))} for p in self.lines[oid]],
                'totals':[{'title':t['title'],'code':t['code'],'value':money(amount(t['value'])*amount(o['currency_value']))} for t in sorted(self.totals[oid],key=lambda t:int(t['sort_order']))],
                'history':[{'date':h['date_added'],'status':self.statuses.get(h['order_status_id'],h['order_status_id'])} for h in sorted(self.history[oid],key=lambda x:x['date_added'])]}
