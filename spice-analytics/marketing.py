"""Transparent retention rules and observed cohorts, not predictive probabilities."""
from collections import Counter,defaultdict
from datetime import date,datetime,timedelta
from decimal import Decimal
from itertools import combinations
from math import log1p
from statistics import median

SEGMENTS={
 'new':('Новые покупатели','Помочь сделать второй заказ, предложить знакомые фасовки.'),
 'second':('Ждём вторую покупку','Уточнить впечатления от первого заказа; предложить пополнение.'),
 'loyal':('Лояльные и активные','Личное предложение, новинки, доступ к большим фасовкам.'),
 'growing':('Развивающиеся','Закрепить привычку: повтор любимых товаров и полезное дополнение.'),
 'vip_risk':('Ценные под риском','Приоритетный личный контакт: выяснить причину паузы.'),
 'return':('Пора возвращать','Напомнить о знакомых товарах и удобном повторном заказе.'),
 'sleeping':('Уснувшие постоянные','Отдельная реактивация с понятным поводом вернуться.'),
 'oneoff':('Разовые и давно ушедшие','Редкая реактивация; отделить от постоянной очереди контактов.'),
}

def month_index(d):return d.year*12+d.month-1
def month_label(i):return f'{i//12:04}-{i%12+1:02}'
def pct(n,d):return float(round(n/d*100,1)) if d else None
def value(o):return Decimal(o['total'])*Decimal(o['currency_value'])
def rounded(n):return float(round(n,2))

def build(store,customers,groups,period,as_of):
    by_id={c['id']:c for c in customers}
    segments={key:{'id':key,'name':name,'suggestion':suggestion,'count':0,'amount':0,'customer_ids':[]} for key,(name,suggestion) in SEGMENTS.items()}
    spends=sorted(c['lifetime_amount'] for c in customers)
    cuts=[spends[min(len(spends)-1,int(len(spends)*q))] for q in (.2,.4,.6,.8)] if spends else [0]*4
    first={};seconds={};cohorts=defaultdict(set);activity=defaultdict(lambda:defaultdict(set));frequency=Counter();recency=defaultdict(Counter)
    matrix=defaultdict(list);timeline=defaultdict(lambda:{'first_count':0,'repeat_count':0,'first_amount':Decimal(0),'repeat_amount':Decimal(0)})
    repeat_intervals=[];conversion={d:{'window':d,'eligible':0,'returned':0} for d in (30,60,90)}
    first_products=defaultdict(lambda:{'eligible':set(),'returned':set()})
    ownership={};purchase_groups=defaultdict(dict);queue=[]
    for c in customers:
        orders=groups[c['id']];orders.sort(key=lambda o:(o['date_added'],int(o['order_id'])))
        dates=[date.fromisoformat(o['date_added'][:10]) for o in orders]
        first[c['id']]=orders[0]['order_id'];second=(dates[1]-dates[0]).days if len(dates)>1 else None
        if second is not None:seconds[c['id']]=second;repeat_intervals.append(second)
        start=month_index(dates[0]);cohorts[start].add(c['id'])
        for d in dates:activity[start][month_index(d)-start].add(c['id'])
        for horizon,item in conversion.items():
            if (as_of-dates[0]).days>=horizon:
                item['eligible']+=1;item['returned']+=int(second is not None and second<=horizon)
        n=c['lifetime_count'];r=c['days_since'];ratio=r/max(1,c['interval_days'])
        if n==1:segment='new' if r<=30 else 'second' if r<=90 else 'oneoff'
        elif r>max(180,c['interval_days']*3):segment='sleeping'
        elif c['reminder']!='ok':segment='vip_risk' if n>=5 else 'return'
        else:segment='loyal' if n>=5 else 'growing'
        recency_score=5 if r<=30 else 4 if r<=60 else 3 if r<=90 else 2 if r<=180 else 1
        freq_score=1 if n==1 else 2 if n==2 else 3 if n<5 else 4 if n<10 else 5
        m_score=1+sum(c['lifetime_amount']>x for x in cuts)
        vip=c['lifetime_amount']>=cuts[-1] and n>=2
        # A rule-based score, not a probability of churn or estimated revenue.
        priority=round(min(100, min(n,10)*3 + min(30,log1p(max(0,c['lifetime_amount']))/log1p(max(1,max(spends or [1])))*30) + min(35,max(0,ratio-1)*18) + (5 if vip else 0)))
        c.update({'segment':segment,'segment_name':SEGMENTS[segment][0],'rfm':f'{recency_score}{freq_score}{m_score}',
                  'recency_score':recency_score,'frequency_score':freq_score,'monetary_score':m_score,
                  'priority':priority,'vip':vip,'first_order':dates[0].isoformat(),'second_order_days':second,
                  'tenure_days':(as_of-dates[0]).days,'delay_ratio':round(ratio,2)})
        sg=segments[segment];sg['count']+=1;sg['amount']+=c['lifetime_amount'];sg['customer_ids'].append(c['id'])
        fb='1' if n==1 else '2–4' if n<5 else '5–9' if n<10 else '10+'
        frequency[fb]+=1
        rb='0–30' if r<=30 else '31–60' if r<=60 else '61–90' if r<=90 else '91–180' if r<=180 else '180+'
        recency[rb][fb]+=1
        matrix[(('recent' if r<=60 else 'warm' if r<=180 else 'old'),('one' if n==1 else 'repeat' if n<5 else 'loyal'))].append(c['id'])
        if c['followup_eligible'] and c['reminder']!='ok':queue.append({'customer_id':c['id'],'priority':priority,'segment':segment,'suggestion':SEGMENTS[segment][1]})
        owned=set()
        for o in orders:
            order_products=set()
            for line in store.lines[o['order_id']]:
                key=(line['product_id'],line['pack']['label']);owned.add(line['product_id']);order_products.add(line['product_id'])
                entry=purchase_groups[c['id']].setdefault(key,{'name':line['name'],'pack':line['pack']['label'],'dates':set(),'quantity':0})
                entry['dates'].add(date.fromisoformat(o['date_added'][:10]));entry['quantity']+=int(line['quantity'])
            if o['order_id']==first[c['id']] and (as_of-dates[0]).days>=60:
                for pid in order_products:
                    first_products[pid]['eligible'].add(c['id'])
                    if second is not None and second<=60:first_products[pid]['returned'].add(c['id'])
        ownership[c['id']]=owned
    for sg in segments.values():sg['amount']=round(sg['amount'],2)
    queue.sort(key=lambda q:q['priority'],reverse=True)
    for item in conversion.values():item['rate']=pct(item['returned'],item['eligible'])
    current=month_index(as_of)
    cohort_rows=[]
    for month,ids in sorted(cohorts.items(),reverse=True):
        cells=[]
        for offset in range(13):
            target=month+offset
            count=len(activity[month][offset]) if target<=current else None
            cells.append({'month':offset,'count':count,'rate':pct(count,len(ids)) if count is not None else None,
                          'partial':target==current,'future':target>current})
        cohort_rows.append({'month':month_label(month),'size':len(ids),'cells':cells})
    names={line['product_id']:line['name'] for lines in store.lines.values() for line in lines}
    product_counts=Counter();pairs=Counter();product_amounts=defaultdict(Decimal);season_products=defaultdict(lambda:Counter())
    hours=[[0]*24 for _ in range(7)];months=[{'month':i+1,'count':0,'amount':Decimal(0)} for i in range(12)]
    order_count=0;affinity_excluded_orders=0
    first_ids=set(first.values())
    for o in period:
        d=datetime.fromisoformat(o['date_added']);month=months[d.month-1]
        month['count']+=1;month['amount']+=value(o);hours[d.weekday()][d.hour]+=1
        week=d.date().isocalendar().week;kind='first' if o['order_id'] in first_ids else 'repeat'
        timeline[week][kind+'_count']+=1;timeline[week][kind+'_amount']+=value(o)
        ids={l['product_id'] for l in store.lines[o['order_id']]}
        if len(ids)<=100:
            product_counts.update(ids);pairs.update(combinations(sorted(ids),2));order_count+=1
        else:affinity_excluded_orders+=1
        for line in store.lines[o['order_id']]:
            product_amounts[line['product_id']]+=Decimal(line['total'])*Decimal(o['currency_value'])
            season_products[line['product_id']][d.month]+=int(line['quantity'])
    affinities=[]
    for (a,b),count in pairs.items():
        if count<3:continue
        lift=count*order_count/(product_counts[a]*product_counts[b])
        affinities.append({'a':a,'b':b,'name_a':names[a],'name_b':names[b],'orders':count,
                           'confidence_a':pct(count,product_counts[a]),'confidence_b':pct(count,product_counts[b]),'lift':round(lift,2)})
    affinities.sort(key=lambda p:(p['orders'],p['lift']),reverse=True)
    replenishment=[];product_gaps=defaultdict(list)
    for cid,items in purchase_groups.items():
        for key,item in items.items():
            dates=sorted(item['dates']);product_gaps[key].extend((b-a).days for a,b in zip(dates,dates[1:]))
    for cid,items in purchase_groups.items():
        c=by_id[cid]
        if not c['followup_eligible']:continue
        for key,item in items.items():
            dates=sorted(item['dates']);gaps=[(b-a).days for a,b in zip(dates,dates[1:])]
            if len(gaps)>=2:interval=round(median(gaps[-6:]));basis='Личная история товара'
            elif len(product_gaps[key])>=5:interval=round(median(product_gaps[key]));basis='Интервалы покупателей этой фасовки'
            else:continue
            due=dates[-1]+timedelta(days=max(1,interval));late=(as_of-due).days
            if late<0 or late>max(180,interval*3):continue
            replenishment.append({'customer_id':cid,'product_id':key[0],'product':item['name'],'pack':item['pack'],
                                  'last_date':dates[-1].isoformat(),'due_on':due.isoformat(),'late_days':late,'interval':interval,'basis':basis,'priority':c['priority']})
    replenishment.sort(key=lambda p:(p['priority'],p['late_days']),reverse=True)
    # One plausible complementary product per customer; never claim causality.
    offers=[]
    for c in customers:
        if not c['followup_eligible']:continue
        owned=ownership[c['id']];candidates=[]
        for p in affinities:
            if p['lift']<1:continue
            if p['a'] in owned and p['b'] not in owned:candidates.append((p,p['b'],p['confidence_a']))
            if p['b'] in owned and p['a'] not in owned:candidates.append((p,p['a'],p['confidence_b']))
        if candidates:
            p,pid,confidence=max(candidates,key=lambda x:(x[2],x[0]['orders']))
            offers.append({'customer_id':c['id'],'product':names[pid],'product_id':pid,'paired_orders':p['orders'],'confidence':confidence,'lift':p['lift'],'priority':c['priority']})
    offers.sort(key=lambda x:x['priority'],reverse=True)
    total=sum(product_amounts.values(),Decimal(0));cumulative=Decimal(0);abc=[]
    for pid,total_pid in sorted(product_amounts.items(),key=lambda p:p[1],reverse=True):
        share=float(cumulative/total) if total else 0
        abc.append({'product_id':pid,'name':names[pid],'amount':rounded(total_pid),'class':'A' if share<.8 else 'B' if share<.95 else 'C','share':round(float(total_pid/total)*100,2) if total else 0})
        cumulative+=total_pid
    first_product_rows=[{'product_id':pid,'name':names[pid],'eligible':len(x['eligible']),'returned':len(x['returned']),'rate':pct(len(x['returned']),len(x['eligible']))} for pid,x in first_products.items() if len(x['eligible'])>=5]
    first_product_rows.sort(key=lambda x:(x['rate'],x['eligible']),reverse=True)
    ranked=sorted((c for c in customers if c['order_count']),key=lambda c:c['amount'],reverse=True)
    period_total=sum(c['amount'] for c in ranked);top_n=max(1,round(len(ranked)*.2))
    total_first=sum(v['first_amount'] for v in timeline.values());total_repeat=sum(v['repeat_amount'] for v in timeline.values())
    return {'segments':list(segments.values()),'queue':queue,'cohorts':cohort_rows,
        'conversion':list(conversion.values()),'median_second_days':median(repeat_intervals) if repeat_intervals else None,
        'frequency':[{'label':k,'count':frequency[k]} for k in ('1','2–4','5–9','10+')],
        'recency':[{'label':k,'groups':dict(recency[k])} for k in ('0–30','31–60','61–90','91–180','180+')],
        'rfm_matrix':[{'recency':r,'frequency':f,'count':len(ids),'customer_ids':ids} for (r,f),ids in matrix.items()],
        'timeline':{str(w):{k:rounded(v) if isinstance(v,Decimal) else v for k,v in row.items()} for w,row in timeline.items()},
        'first_amount':rounded(total_first),'repeat_amount':rounded(total_repeat),'repeat_amount_share':pct(total_repeat,total_first+total_repeat),
        'top20_customer_share':pct(sum(c['amount'] for c in ranked[:top_n]),period_total),
        'replenishment':replenishment[:300],'offers':offers[:300],'affinities':affinities[:80],'affinity_excluded_orders':affinity_excluded_orders,
        'first_products':first_product_rows[:50],'abc':abc,
        'hours':hours,'months':[{**m,'amount':rounded(m['amount'])} for m in months],
        'product_months':[{'id':pid,'name':names[pid],'months':[season_products[pid][m] for m in range(1,13)]} for pid,_ in sorted(product_amounts.items(),key=lambda p:p[1],reverse=True)[:20]]}
