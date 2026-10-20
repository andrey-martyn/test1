"""Create a single-file, read-only demo with no names, contacts, IPs or street addresses."""
from copy import deepcopy
from pathlib import Path
import json
import sys
from analytics import Analytics

ROOT=Path(__file__).parent

def build(output):
    s=Analytics(ROOT/'data'/'analytics.sqlite3')
    meta=s.metadata();asof='2026-10-20';year=2026
    report=deepcopy(s.report(year,asof,'UAH'))
    order_to_customer={oid:c['id'] for c in report['customers'] for oid in c['order_ids']}
    for c in report['customers']:
        c['name']='Покупатель '+c['id'];c['email']='';c['phone']='';c['emails']=[];c['phones']=[]
        c['action']={k:v for k,v in c.get('action',{}).items() if k!='note'}
    for transaction in report['ga4'].get('matched',[]):
        transaction['source_medium']='Источник скрыт в демо';transaction['campaign']='Кампания скрыта в демо'
    for candidate in report['duplicates']:
        candidate['value']='Совпадение найдено в исходной базе; контакты в демо удалены'
        for m in candidate['members']:
            m['name']='Покупатель '+m['id'];m['email']='';m['phone']=''
    orders={}
    for oid,cid in order_to_customer.items():
        o=s.order_details(oid)
        o['name']='Покупатель '+cid;o['email']='';o['phone']=''
        # Methods are free-text module fields; use stable non-personal labels in the demo.
        o['payment_method']='Способ оплаты скрыт в демо'
        o['shipping_method']='Доставка по адресу из заказа'
        for total in o['totals']:
            total['title']={'sub_total':'Товары','shipping':'Доставка','total':'Всего','coupon':'Скидка','voucher':'Сертификат','tax':'Налог'}.get(total['code'],'Корректировка')
        orders[oid]=o
    meta['today']=asof;meta['years']=[year]
    payload={'meta':meta,'report':report,'orders':orders,'geography':{
        'ukraine':json.loads((ROOT/'static/ukraine.geojson').read_text()),
        'countries':json.loads((ROOT/'static/countries.geojson').read_text())}}
    encoded=json.dumps(payload,ensure_ascii=False,separators=(',',':')).replace('<','\\u003c')
    shim='''
const DEMO_DATA=PAYLOAD;
window.fetch=async function(input,options={}){
  const u=new URL(String(input),'https://offline.invalid');let data,status=200;
  if(options.method && options.method!=='GET'){data={detail:'Это демо для просмотра. Изменения доступны в локальном приложении.'};status=422;}
  else if(u.pathname==='/api/meta')data=DEMO_DATA.meta;
  else if(u.pathname==='/api/report')data=DEMO_DATA.report;
  else if(u.pathname.startsWith('/api/orders/'))data=DEMO_DATA.orders[u.pathname.split('/').pop()];
  else if(u.pathname.startsWith('/api/customers/')){
    const id=u.pathname.split('/')[3],customer=DEMO_DATA.report.customers.find(c=>c.id===id);
    data={customer,orders:customer.order_ids.slice().reverse().map(id=>DEMO_DATA.orders[id])};
  }else if(u.pathname.includes('ukraine.geojson'))data=DEMO_DATA.geography.ukraine;
  else if(u.pathname.includes('countries.geojson'))data=DEMO_DATA.geography.countries;
  else{data={detail:'Нет данных в демо'};status=404;}
  return new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
};
const demoNotice=document.querySelector('#notice');
demoNotice.textContent='Офлайн-демо на вашей выгрузке: 2026 год, проверка на 20.10.2026. Имена заменены, контакты удалены. Поиск, недели, карточки, фасовки и карта работают. Изменения и другие периоды — в полной версии.';
demoNotice.classList.remove('hidden');
document.querySelectorAll('#year,#as-of,#currency,#privacy').forEach(el=>el.disabled=true);
document.querySelector('#logout').style.display='none';
document.querySelector('.local-pill').textContent='Офлайн-демо';
new MutationObserver(()=>{
  document.querySelectorAll('#settings-form input,#settings-form button,#action-form input,#action-form textarea,#action-form select,#action-form button,[data-action=merge],[data-action=unmerge],#ga4-file,[data-action=import-ga4]').forEach(el=>el.disabled=true);
}).observe(document.body,{childList:true,subtree:true});
'''.replace('PAYLOAD',encoded)
    html=(ROOT/'static/index.html').read_text()
    html=html.replace('<link rel="stylesheet" href="/static/style.css">','<style>'+(ROOT/'static/style.css').read_text()+'</style>')
    html=html.replace('<link rel="stylesheet" href="/static/marketing.css">','<style>'+(ROOT/'static/marketing.css').read_text()+'</style>')
    html=html.replace('<script defer src="/static/app.js"></script>','')
    html=html.replace('<script defer src="/static/marketing.js"></script>','')
    html=html.replace('href="/"','href="#"')
    html=html.replace('</body>','<script>'+shim+'</script><script>'+(ROOT/'static/marketing.js').read_text()+'</script><script>'+(ROOT/'static/app.js').read_text()+'</script></body>')
    output.write_text(html)
    return {'file':str(output),'bytes':output.stat().st_size,'customers':len(report['customers']),'orders':len(orders)}

if __name__=='__main__':
    output=Path(sys.argv[1]) if len(sys.argv)>1 else ROOT/'demo.html'
    print(json.dumps(build(output),ensure_ascii=False))
