"""Explicit CSV snapshots; no GA4 credentials or invented analytics metrics."""
import csv
from datetime import date,datetime
from decimal import Decimal,InvalidOperation
from io import StringIO
import json
import re
import sqlite3

ALIASES={
 'date':['date','дата'], 'channel':['channel','sessiondefaultchannelgroup','sessionprimarychannelgroup','группа каналов по умолчанию для сеанса'],
 'sessions':['sessions','сеансы'], 'users':['activeusers','users','активные пользователи'],
 'carts':['addtocarts','добавления в корзину'], 'checkouts':['checkouts','begincheckouts','оформления заказов'],
 'purchases':['ecommercepurchases','purchases','покупки'], 'revenue':['purchaserevenue','revenue','доход от покупок'],
 'cost':['advertisercost','adcost','cost','расходы'], 'currency':['currencycode','currency','валюта'],
 'transaction_id':['transactionid','transaction_id','идентификатор транзакции'],
 'source_medium':['sessionsourcemedium','source_medium','источник/канал сеанса'],
 'campaign':['sessioncampaignname','campaign','кампания сеанса'],
}

def header(s):return re.sub(r'[\s_\-/()]+','',str(s).casefold().lstrip('\ufeff'))
def number(value):
    s=str(value or '0').strip().replace('\u00a0','').replace(' ','')
    if ',' in s and '.' in s:
        s=s.replace(',','') if s.rfind('.')>s.rfind(',') else s.replace('.','').replace(',','.')
    else:s=s.replace(',','.')
    result=Decimal(s)
    if not result.is_finite() or result<0:raise ValueError('Нужны конечные неотрицательные числа')
    return result

def parse_date(value):
    for fmt in ('%Y%m%d','%Y-%m-%d','%d.%m.%Y'):
        try:return datetime.strptime(value.strip(),fmt).date().isoformat()
        except ValueError:pass
    raise ValueError('Дата должна быть YYYYMMDD, YYYY-MM-DD или DD.MM.YYYY')

def transaction_key(value):
    m=re.fullmatch(r'(?:#|order[-_ ]?)?(\d+)',str(value).strip(),re.I)
    return str(int(m[1])) if m else str(value).strip()

def import_csv(database,text,kind,currency):
    if kind not in ('daily','transactions'):raise ValueError('Неизвестный тип отчёта')
    if not re.fullmatch('[A-Z]{3}',currency):raise ValueError('Валюта — код из трёх букв, например UAH')
    lines=[line for line in text.lstrip('\ufeff').splitlines() if line.strip() and not line.lstrip().startswith('#')]
    if not lines:raise ValueError('Пустой CSV')
    delimiter=';' if lines[0].count(';')>lines[0].count(',') else ','
    reader=csv.DictReader(StringIO('\n'.join(lines)),delimiter=delimiter)
    mapped={}
    for canonical,aliases in ALIASES.items():
        for column in reader.fieldnames or []:
            if header(column) in {header(a) for a in aliases}:mapped[canonical]=column;break
    required={'date','channel','sessions'} if kind=='daily' else {'date','transaction_id','revenue'}
    if missing:=required-mapped.keys():raise ValueError('Нет колонок: '+', '.join(sorted(missing)))
    records={};skipped=0
    for line_no,row in enumerate(reader,start=2):
        if line_no>50002:raise ValueError('Лимит — 50 000 строк за импорт')
        field=lambda key:row.get(mapped.get(key,''),'') or ''
        if field('date').casefold() in ('total','totals','grand total','итого','общий итог','всього'):skipped+=1;continue
        try:
            day=parse_date(field('date'));ccy=(field('currency') or currency).strip().upper()
            if not re.fullmatch('[A-Z]{3}',ccy):raise ValueError('Некорректная валюта')
            if kind=='daily':
                if not field('sessions').strip():raise ValueError('Пустое значение sessions')
                record={'date':day,'channel':field('channel').strip() or '(not set)','currency':ccy}
                for key in ('sessions','users','carts','checkouts','purchases'):
                    x=number(field(key)) if key in mapped and field(key).strip() else None
                    if x is not None and x!=x.to_integral_value():raise ValueError('Счётчики должны быть целыми')
                    record[key]=int(x) if x is not None else None
                record['revenue']=str(number(field('revenue'))) if field('revenue').strip() else None
                record['cost']=str(number(field('cost'))) if field('cost').strip() else None
                key=(day,record['channel'],ccy)
            else:
                if not field('revenue').strip():raise ValueError('Пустое значение purchaseRevenue')
                tx=transaction_key(field('transaction_id'))
                if not tx or tx.casefold() in ('(not set)','(not provided)','не задано'):skipped+=1;continue
                record={'date':day,'transaction_id':tx,'revenue':str(number(field('revenue'))),'currency':ccy,
                        'source_medium':field('source_medium').strip(),'campaign':field('campaign').strip()}
                key=(tx,ccy)
            if key in records:raise ValueError('Повтор ключа: нужен один итог на день/канал или transaction_id')
            records[key]=record
        except (ValueError,InvalidOperation) as e:
            raise ValueError(f'Строка {line_no}: {e}') from None
    if not records:raise ValueError('Нет строк данных')
    with sqlite3.connect(database) as con:
        con.execute('CREATE TABLE IF NOT EXISTS ga4_records (kind TEXT, record_key TEXT, payload TEXT, PRIMARY KEY(kind,record_key))')
        con.execute('DELETE FROM ga4_records WHERE kind=?',(kind,))
        con.executemany('INSERT INTO ga4_records VALUES (?,?,?)',[(kind,json.dumps(k),json.dumps(v,ensure_ascii=False)) for k,v in records.items()])
    return {'kind':kind,'rows':len(records),'skipped_totals_or_missing_ids':skipped}

def report(database,year,as_of,currency,orders):
    with sqlite3.connect(database) as con:
        exists=con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='ga4_records'").fetchone()
        raw=list(con.execute('SELECT kind,payload FROM ga4_records')) if exists else []
    data={'daily':[],'transactions':[]}
    for kind,payload in raw:
        r=json.loads(payload)
        if r['currency']==currency and r['date']<=as_of.isoformat() and date.fromisoformat(r['date']).isocalendar().year==year:data[kind].append(r)
    daily=data['daily'];transactions=data['transactions'];channels={}
    fields=['sessions','users','carts','checkouts','purchases','revenue','cost']
    totals={f:None for f in fields}
    for row in daily:
        ch=channels.setdefault(row['channel'],{'channel':row['channel'],**{f:None for f in fields}})
        for f in fields:
            if row[f] is not None:
                value=Decimal(str(row[f]));totals[f]=(totals[f] or Decimal(0))+value;ch[f]=(ch[f] or Decimal(0))+value
    def convert(row):return {k:float(v) if isinstance(v,Decimal) else v for k,v in row.items()}
    result_channels=[]
    for row in channels.values():
        row=convert(row);row['purchase_session_ratio']=round(row['purchases']/row['sessions']*100,2) if row['sessions'] and row['purchases'] is not None else None
        row['roas']=round(row['revenue']/row['cost'],2) if row['cost'] and row['revenue'] is not None else None
        result_channels.append(row)
    oc={o['order_id']:o for o in orders};matched=[];ga_only=[]
    for row in transactions:
        if row['transaction_id'] in oc:
            o=oc[row['transaction_id']];difference=Decimal(row['revenue'])-Decimal(o['total'])*Decimal(o['currency_value'])
            matched.append({'order_id':o['order_id'],'ga_amount':float(Decimal(row['revenue'])),'order_amount':float(Decimal(o['total'])*Decimal(o['currency_value'])),
                            'difference':float(round(difference,2)),'source_medium':row['source_medium'],'campaign':row['campaign']})
        else:ga_only.append(row['transaction_id'])
    matched_ids={m['order_id'] for m in matched}
    trend={}
    for row in daily:
        item=trend.setdefault(row['date'],{'date':row['date'],'sessions':0,'ga_purchases':0,'order_count':0,'order_amount':0})
        item['sessions']+=row['sessions'] or 0;item['ga_purchases']+=row['purchases'] or 0
    for o in orders:
        day=o['date_added'][:10];item=trend.setdefault(day,{'date':day,'sessions':0,'ga_purchases':0,'order_count':0,'order_amount':0})
        item['order_count']+=1;item['order_amount']+=float(Decimal(o['total'])*Decimal(o['currency_value']))
    return {'available':bool(raw),'daily_available':bool(daily),'transactions_available':bool(transactions),
            'daily_rows':len(daily),'transaction_rows':len(transactions),'totals':convert(totals),
            'channels':sorted(result_channels,key=lambda r:r['sessions'] or 0,reverse=True),'matched':matched,
            'ga_only':ga_only,'oc_only':sorted(set(oc)-matched_ids,key=int) if transactions else [],
            'trend':sorted(trend.values(),key=lambda r:r['date']) if daily else []}
