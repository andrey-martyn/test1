const $=s=>document.querySelector(s);
const appBase=document.querySelector('meta[name="analytics-base"]').content;
const appUrl=path=>{
  if(!appBase)return path;
  const target=new URL(path,location.origin),params=new URLSearchParams(target.search);
  params.set('route',target.pathname);
  return appBase+'/index.php?'+params.toString();
};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const num=(n,d=0)=>Number(n||0).toLocaleString('ru-RU',{maximumFractionDigits:d});
const fmtDate=d=>d?new Date(d.slice(0,10)+'T12:00:00').toLocaleDateString('ru-RU'):'—';
const state={view:'customers',privacy:true,page:1,search:'',reminder:'all',periodOnly:true,sort:'amount',productMode:'variants',productSort:'amount',mapMode:'ukraine',geoMetric:'count',meta:null,report:null,customer:null};
const PAGE=35;
Object.assign(state,{ordersMin:0,ordersMax:'',ordersBasis:'lifetime_count',segment:'all',ids:null,eligibleOnly:false,actionMin:2,geoSource:'mixed',cohortCount:18,historyOpen:null,seasonMetric:'count',seasonYears:false,duplicateKind:'all',duplicateSort:'match',duplicateDir:1,duplicateSelected:new Set(),mergeBusy:false,crmScope:'todo',crmTier:'all',crmSort:'value',crmDuplicates:'all',duplicateCustomer:null});
const titles={overview:['Обзор магазина','Покупки, товары и география — вся картина в одном месте.'],customers:['Покупатели','История покупок и подходящий момент для нового заказа.'],products:['Товары и фасовки','Что покупают чаще: упаковки, килограммы и суммы заказов.'],geography:['География заказов','Где находятся ваши покупатели — по адресам из заказов.'],duplicates:['Объединение покупателей','Проверьте совпадения контактов и свяжите историю покупок.'],settings:['Настройки аналитики','Какие заказы учитывать и когда напоминать о следующей покупке.']};
const cash=v=>num(v,2)+' '+esc(state.report?.currency||'UAH');
Object.assign(titles,{retention:['Удержание и когорты','От первой покупки ко второй: где теряются и возвращаются покупатели.'],segments:['Сегменты и RFM','Давность, частота и сумма покупок — кому какое предложение подходит.'],actions:['Очередь контактов','Приоритетные покупатели, причины контакта и история работы.'],opportunities:['Повторные продажи','Пополнение запасов, товарные связки и первые покупки.'],seasonality:['Сезонность','Ритм заказов по месяцам, дням недели и времени суток.'],ga4:['GA4 и каналы','Трафик, действия на сайте и сопоставление покупок с заказами.']});
const name=c=>state.privacy?'Покупатель '+c.id:esc(c.name);
const contact=c=>state.privacy?'Контакты скрыты':esc(c.email||c.phone||'Без контактов');
const toast=text=>{const el=$('#toast');el.textContent=text;el.classList.remove('hidden');clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.classList.add('hidden'),4500);};
async function api(path,options={}){const r=await fetch(appUrl(path),options);if(r.status===401){location.href=appUrl('/login');throw Error('Нужен вход');}const data=await r.json();if(!r.ok)throw Error(typeof data.detail==='string'?data.detail:'Не удалось выполнить запрос');return data;}
const query=()=>new URLSearchParams({year:$('#year').value,as_of:$('#as-of').value,currency:$('#currency').value});
let loadSequence=0;
async function load(){
  const sequence=++loadSequence;
  $('#content').innerHTML='<div class="loading">Считаем историю покупок…</div>';
  try{const report=await api('/api/report?'+query());if(sequence!==loadSequence)return;state.report=report;state.duplicateSelected.clear();state.page=1;render();}
  catch(e){if(sequence===loadSequence)$('#content').innerHTML='<div class="notice">'+esc(e.message)+'</div>';}
}
async function init(){
  try{
    state.meta=await api('/api/meta');const m=state.meta;
    state.privacy=!m.names_visible;
    $('#year').innerHTML=yearOptions(m.years,m.years[0]);
    $('#currency').innerHTML=m.currencies.map(c=>`<option>${esc(c)}</option>`).join('');
    $('#as-of').value=m.today;
    updateSourceStamp(m);
    $('#footer-range').textContent=fmtDate(m.date_min)+' — '+fmtDate(m.date_max);
    await load();
  }catch(e){$('#content').innerHTML='<div class="notice">'+esc(e.message)+'</div>';}
}
function updateSourceStamp(m){
  const s=m.sync||{};
  $('#data-stamp').textContent=s.configured?(s.state==='error'?'Ошибка обновления · ':s.state==='running'?'Обновляем · ':'Обновлено · ')+(s.last_success?new Date(s.last_success).toLocaleString('ru-RU'):'первая загрузка'):'Выгрузка по '+fmtDate(m.date_max)+' · '+num(m.counts.oc_order)+' заказов';
  if(m.production){$('.local-pill').textContent='Закрытый кабинет';$('.sidebar-bottom p').textContent=s.configured?'ocStore · синхронизация MySQL':'ocStore · загруженная копия';}
}
async function refreshSource(force=false){
  if(!state.meta||(!force&&state.duplicateSelected.size)||(!force&&!state.meta.sync?.configured)||$('#drawer').open||document.hidden)return;
  try{
    const m=await api('/api/meta'),previous=state.meta;state.meta=m;updateSourceStamp(m);
    if($('#as-of').value===previous.today)$('#as-of').value=m.today;
    if(force||m.imported_at!==previous.imported_at||m.today!==previous.today){
      const year=$('#year').value;$('#year').innerHTML=yearOptions(m.years,year);
      const currency=$('#currency').value;$('#currency').innerHTML=m.currencies.map(c=>`<option>${esc(c)}</option>`).join('');if(m.currencies.includes(currency))$('#currency').value=currency;
      $('#footer-range').textContent=fmtDate(m.date_min)+' — '+fmtDate(m.date_max);await load();
    }
  }catch(e){if(force)toast(e.message);}
}
function sourceSettings(m){const s=m.sync||{};return `<section class="panel">${panelHeading('Источник и обновление',s.configured?'Копия магазина обновляется по расписанию cron.':'Пока используется загруженная SQL-копия.')}<div class="settings-grid"><div class="settings-note"><strong>${num(m.counts.oc_order)} заказов в закрытой копии</strong><br>История: ${fmtDate(m.date_min)} — ${fmtDate(m.date_max)}.<br>${s.configured?'Последняя успешная проверка: '+(s.last_success?esc(new Date(s.last_success).toLocaleString('ru-RU')):'ещё не завершена')+'.<br>Состояние: '+esc({ok:'актуально',running:'идёт проверка',error:'ошибка; сохранены предыдущие данные'}[s.state]||'ожидание')+(s.error?'<br>'+esc(s.error):''):'Полную выгрузку можно импортировать через import_dump.py; отчёты подхватят её автоматически.'}<br><button class="button" data-action="refresh-source">Обновить отчёт</button></div><div class="settings-note"><strong>Подключение только на чтение</strong><br>Кабинет читает шесть таблиц заказов. Изменения в магазине не выполняются. CRM-отметки, правила и объединения сохраняются в отдельной базе.<br>Для MyISAM сверяются два чтения; это проверка стабильности, а не транзакционный снимок. IP-география требует локальную GeoIP-базу.</div></div></section>`;}
function metric(label,value,foot,action=''){return `<article class="metric"><div class="metric-label">${label}${infoHelp(label)}<span>↗</span></div><div class="metric-value">${value}</div><div class="metric-foot">${foot}</div>${action?`<button class="metric-link" data-action="${action}" aria-label="${label}"></button>`:''}</article>`;}
function render(){
  if(!state.report)return;
  const r=state.report,m=r.metrics;
  $('#page-title').textContent=titles[state.view][0];$('#page-description').textContent=titles[state.view][1];
  document.querySelectorAll('[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===state.view));
  hideHelp();$('#duplicate-badge').textContent=r.duplicate_pairs.length;
  $('#metrics').innerHTML=metric('Сумма заказов',num(m.amount,2)+'<small>'+esc(r.currency)+'</small>','За '+periodTitle()+' до даты проверки')+
    metric('Заказы',num(m.order_count),num(m.customer_count)+' покупателей за период')+
    metric('Средний заказ',num(m.average,2)+'<small>'+esc(r.currency)+'</small>','Товары, скидки и доставка по заказу')+
    metric('Пора напомнить',num(m.overdue),'Просрочено · вся история покупателей','show-overdue');
  $('#privacy').textContent=state.privacy?'◉ Имена скрыты':'◎ Имена видны';$('#privacy').setAttribute('aria-pressed',state.privacy);
  const content=$('#content');
  if(state.view==='customers')content.innerHTML=customersView();
  if(state.view==='overview')content.innerHTML=overviewView();
  if(state.view==='products')content.innerHTML=productsView();
  if(state.view==='geography'){content.innerHTML=geographyView();drawMap();}
  if(state.view==='duplicates')content.innerHTML=duplicateTableView();
  if(state.view==='settings')content.innerHTML=settingsView();
  if(['retention','segments','actions','opportunities','seasonality','ga4'].includes(state.view))content.innerHTML=marketingView(state.view);
  $('#yearly-summary').innerHTML=historySummaryView();
  enhanceHelp();
  const allPairs=$('#duplicate-select-all');if(allPairs){const page=duplicateRows().slice((state.page-1)*PAGE,state.page*PAGE);allPairs.indeterminate=page.some(r=>state.duplicateSelected.has(r.id))&&!page.every(r=>state.duplicateSelected.has(r.id));}
}
function panelHeading(title,description,extra=''){return `<div class="panel-heading"><div><h2>${title}${infoHelp(title,description)}</h2><p>${description}</p></div>${extra}</div>`;}
function selected(value,current){return value===current?' selected':'';}
function pages(total){const count=Math.max(1,Math.ceil(total/PAGE));state.page=Math.min(state.page,count);return `<div class="pagination"><span>${num(total)} записей</span><div class="pages"><button class="button" data-action="prev-page" ${state.page===1?'disabled':''}>←</button><span>${state.page} / ${count}</span><button class="button" data-action="next-page" ${state.page===count?'disabled':''}>→</button></div></div>`;}
function customerRows(){
  let rows=state.report.customers.filter(c=>(!state.periodOnly||c.order_count>0)&&(state.reminder==='all'||c.reminder===state.reminder));
  rows=rows.filter(c=>c[state.ordersBasis]>=Number(state.ordersMin||0)&&(state.ordersMax===''||c[state.ordersBasis]<=Number(state.ordersMax))&&(state.segment==='all'||c.segment===state.segment)&&(!state.ids||state.ids.includes(c.id))&&(!state.eligibleOnly||c.followup_eligible));
  const search=state.search.trim().toLocaleLowerCase();
  if(search)rows=rows.filter(c=>([c.name,...c.emails,...c.phones,c.city,c.id].join(' ').toLocaleLowerCase()).includes(search));
  return rows.sort((a,b)=>state.sort==='priority'?b.priority-a.priority:state.sort==='amount'?b.amount-a.amount:state.sort==='last'?b.last_order.localeCompare(a.last_order):state.sort==='overdue'?b.overdue_days-a.overdue_days:a.name.localeCompare(b.name,'uk'));
}
function reminderBadge(c){return `<span class="badge ${c.reminder}">${c.reminder==='overdue'?'+'+num(c.overdue_days)+' дн. к сроку':c.reminder==='due'?'Пора напомнить':'Через '+num(Math.max(0,c.interval_days-c.days_since))+' дн.'}</span>`;}
function customersView(){
  const r=state.report,rows=customerRows(),pagination=pages(rows.length),visible=rows.slice((state.page-1)*PAGE,state.page*PAGE),columns=r.calendar;
  const max=Math.max(1,...rows.flatMap(c=>Object.values(c.activity).map(w=>w.amount)));
  return `<section class="panel">${panelHeading('Календарь покупок',r.year?'Одна строка — покупатель, ячейка — неделя календарного года. Нажмите покупку для состава.':'История покупок по годам: сумма и число заказов в каждой ячейке. Нажмите ячейку для состава.',`<button class="button" data-action="export">↓ Скачать CSV</button>`)}
  <div class="toolbar"><input class="search" id="search" placeholder="Поиск по имени, телефону, городу…" aria-label="Поиск покупателей" value="${esc(state.search)}"><select id="reminder" aria-label="Фильтр напоминаний"><option value="all"${selected('all',state.reminder)}>Все покупатели</option><option value="overdue"${selected('overdue',state.reminder)}>Просрочено</option><option value="due"${selected('due',state.reminder)}>Пора напомнить</option><option value="ok"${selected('ok',state.reminder)}>Пока рано</option></select><select id="sort" aria-label="Сортировка покупателей"><option value="priority"${selected('priority',state.sort)}>По приоритету контакта</option><option value="amount"${selected('amount',state.sort)}>По сумме заказов</option><option value="overdue"${selected('overdue',state.sort)}>По задержке заказа</option><option value="last"${selected('last',state.sort)}>По последнему заказу</option><option value="name"${selected('name',state.sort)}>По имени</option></select><label class="checkbox-label"><input type="checkbox" id="period-only" ${state.periodOnly?'checked':''}>Есть заказы за ${periodTitle()}</label></div>
  ${customerAdvancedFilters(rows)}<div class="table-scroll"><table class="customer-table ${r.year?'':'customer-years'}"><thead><tr><th class="identity">Покупатель</th><th>За период / всего</th><th>Последний заказ</th><th>Следующая покупка</th>${columns.map((col,i)=>`<th class="week-head" title="${fmtDate(col.start)} — ${fmtDate(col.end)}">${!r.year||i%4===0||i===columns.length-1?col.label:''}</th>`).join('')}</tr></thead><tbody>${visible.map(c=>`<tr><td class="identity"><button class="identity-button" data-action="customer" data-id="${c.id}"><span class="person"><span class="avatar">${state.privacy?'•':esc(c.name.split(/\s+/).slice(0,2).map(n=>n[0]).join(''))}</span><span><span class="identity-name">${name(c)}</span><span class="identity-meta">${esc(c.city||'Город не указан')} · ${c.lifetime_count} зак.</span></span></span></button></td><td class="amount-cell">${cash(c.amount)}<small>${c.order_count} заказов · всего ${cash(c.lifetime_amount)}</small></td><td>${fmtDate(c.last_order)}<span class="identity-meta">${c.days_since} дн. назад</span></td><td>${reminderBadge(c)}<span class="identity-meta">${c.personal_interval?'Обычно':'Базовый срок'} ${c.interval_days} дн.</span></td>${columns.map(col=>{
    const v=c.activity[col.key],level=v?Math.min(5,Math.max(1,Math.ceil(Math.sqrt(v.amount/max)*5))):0;
    const title=`${col.label}: ${cash(v?.amount||0)}, заказов ${v?.count||0}`;
    return `<td class="week-cell ${r.year?'':'year-cell'}">${v?`<button class="heat active heat-l${level}" data-action="period-orders" data-id="${c.id}" data-start="${col.start}" data-end="${col.end}" title="${title}" aria-label="${esc(title)}">${r.year?'':`<strong>${cash(v.amount)}</strong><small>${v.count} зак.</small>`}</button>`:`<span class="heat" title="${col.label}: нет заказов">${r.year?'':'—'}</span>`}</td>`;
  }).join('')}</tr>`).join('')||`<tr><td colspan="${columns.length+4}" class="empty">Покупатели по этим условиям не найдены.</td></tr>`}</tbody></table></div>
  <div class="heatmap-note"><span class="explanation">${r.year?'Недели внутри календарного года; крайние недели неполные.':'Столбцы — календарные годы.'} Напоминания — по всей истории до ${fmtDate(r.as_of)}.</span><span>Меньше</span>${[1,2,3,4,5].map(i=>`<i class="heat-l${i}"></i>`).join('')}<span>Больше · до ${cash(max)}</span></div>${pagination}</section>`;
}
function overviewView(){
  const r=state.report,m=r.metrics;
  return `<div class="two-columns"><section class="panel">${panelHeading(r.year?'Покупки по неделям':'Покупки по годам',periodTitle()+' · суммы учитываемых заказов')}${purchaseChart()}</section><section class="panel">${panelHeading('За цифрами','Дополнительные показатели периода')}<div class="mini-list"><div class="mini-row"><span>Купили повторно<small>Есть более одного заказа за всю историю</small></span><strong class="right">${num(m.repeat_customers)}<small>из ${num(m.customer_count)} покупателей периода</small></strong></div><div class="mini-row"><span>Продано упаковок</span><strong class="right">${num(m.quantity)}</strong></div><div class="mini-row"><span>Вес товаров<small>Только позиции с весовой фасовкой</small></span><strong class="right">${num(m.kilograms,2)} кг</strong></div><div class="mini-row"><span>Городов доставки</span><strong class="right">${num(r.locations.length)}</strong></div></div></section></div><div class="two-columns"><section class="panel">${panelHeading('Покупатели с наибольшей суммой','За выбранный период',`<button class="button" data-action="go-customers">Все покупатели →</button>`)}<div class="mini-list">${r.customers.filter(c=>c.order_count).slice(0,6).map((c,i)=>`<div class="mini-row"><span class="mini-rank">0${i+1}</span><button class="identity-button" data-action="customer" data-id="${c.id}">${name(c)}<small>${esc(c.city)} · ${c.order_count} заказов</small></button><strong class="right">${cash(c.amount)}</strong></div>`).join('')}</div></section><section class="panel">${panelHeading('Популярные фасовки','По количеству проданных упаковок')}<div class="mini-list">${packTotals().slice(0,6).map(p=>`<div class="mini-row"><span>${esc(p.label)}</span><strong class="right">${num(p.quantity)}<small>${cash(p.amount)}</small></strong></div>`).join('')}</div></section></div><div class="notice">В базе преобладает статус «В обработке». Аналитика отражает оформленные заказы; оплата и возвраты требуют согласованного учёта статусов.</div>`;
}
function packTotals(){const packs={};state.report.products.forEach(p=>{const label=p.pack.label;if(!packs[label])packs[label]={label,quantity:0,amount:0};packs[label].quantity+=p.quantity;packs[label].amount+=p.amount;});return Object.values(packs).sort((a,b)=>b.quantity-a.quantity);}
function productRows(){
  let rows=state.report.products;
  if(state.productMode==='products'){
    const groups={};rows.forEach(p=>{if(!groups[p.product_id])groups[p.product_id]={...p,quantity:0,amount:0,grams:0,variants:0,years:{},pack:{label:'Все фасовки'}};const g=groups[p.product_id];g.quantity+=p.quantity;g.amount+=p.amount;g.grams+=p.grams;g.variants++;Object.entries(p.years||{}).forEach(([y,v])=>{g.years[y]||={quantity:0,amount:0,grams:0};for(const k of ['quantity','amount','grams'])g.years[y][k]+=v[k];});});rows=Object.values(groups);
  }
  if(state.search.trim())rows=rows.filter(p=>(p.name+' '+p.model+' '+p.pack.label).toLowerCase().includes(state.search.toLowerCase()));
  return rows.slice().sort((a,b)=>b[state.productSort]-a[state.productSort]);
}
function productsView(){
  const rows=productRows(),pagination=pages(rows.length),packs=packTotals(),total=state.report.metrics.quantity;
  return `<section class="panel">${panelHeading('Какие фасовки выбирают','Доли по количеству упаковок. Штучные товары считаются отдельно от веса.')}<div class="pack-grid">${packs.map(p=>`<div class="pack-card"><strong>${esc(p.label)}</strong><p>${num(p.quantity)} упак.</p><div class="bar-track"><span style="width:${total?p.quantity/total*100:0}%"></span></div><p><small>${num(total?p.quantity/total*100:0,1)}% упаковок</small></p></div>`).join('')}</div></section><section class="panel">${panelHeading('Товары в заказах','Цены и названия взяты из исторических заказов, а не из текущего каталога.')}<div class="toolbar"><input class="search" id="search" value="${esc(state.search)}" placeholder="Название или код товара…" aria-label="Поиск товаров"><select id="product-mode" aria-label="Группировка товаров"><option value="variants"${selected('variants',state.productMode)}>Товар + фасовка</option><option value="products"${selected('products',state.productMode)}>Товар целиком</option></select><select id="product-sort" aria-label="Сортировка товаров"><option value="amount"${selected('amount',state.productSort)}>По сумме</option><option value="quantity"${selected('quantity',state.productSort)}>По упаковкам</option><option value="grams"${selected('grams',state.productSort)}>По весу</option></select></div><div class="table-scroll"><table><thead><tr><th>Товар</th><th>Фасовка</th><th>Упаковки</th><th>Общий вес</th><th>Сумма товаров</th>${state.productMode==='variants'?'<th>Покупатели / повторно</th>':''}</tr></thead><tbody>${rows.slice((state.page-1)*PAGE,state.page*PAGE).map(p=>`<tr><td><strong>${esc(p.name)}</strong><span class="identity-meta">${esc(p.model)} · ID ${p.product_id}</span>${entityHistory(p.years,'product')}</td><td><span class="badge neutral">${p.variants?p.variants+' вариантов':esc(p.pack.label)}</span></td><td>${num(p.quantity)}</td><td>${p.grams?num(p.grams/1000,3)+' кг':'—'}</td><td class="amount-cell">${cash(p.amount)}</td>${state.productMode==='variants'?`<td>${p.customers} / ${p.repeat_customers}</td>`:''}</tr>`).join('')||'<tr><td colspan="6" class="empty">Товары не найдены.</td></tr>'}</tbody></table></div><div class="responsive-note">Суммы позиций до скидок и доставки из итогов заказа. «Повторно» — покупатели, заказавшие этот товар в этой фасовке более чем в одном заказе периода.</div>${pagination}</section>`;
}
function locationRows(){return getLocations().filter(l=>!state.search||[l.city,l.zone,l.country].join(' ').toLowerCase().includes(state.search.toLowerCase()));}
function regionTotals(){const groups={};getLocations().forEach(l=>{const k=l.zone||'Регион не указан';if(!groups[k])groups[k]={label:k,zone_code:l.zone_code,count:0,amount:0};groups[k].count+=l.count;groups[k].amount+=l.amount;});return Object.values(groups).sort((a,b)=>b[state.geoMetric]-a[state.geoMetric]);}
function geographyView(){
  const rows=locationRows(),pagination=pages(rows.length),regions=regionTotals(),max=Math.max(1,...regions.map(x=>x[state.geoMetric]));
  return `<div class="two-columns"><section class="panel">${panelHeading(state.geoSource==='address'?'География доставки':state.geoSource==='ip'?'Местоположение по IP':'Адреса и приблизительная география','Цвет показывает '+(state.geoMetric==='count'?'количество заказов':'сумму заказов'),`<select id="map-mode" aria-label="Масштаб карты"><option value="ukraine"${selected('ukraine',state.mapMode)}>Украина</option><option value="europe"${selected('europe',state.mapMode)}>Европа</option></select>`)}<div class="toolbar"><select id="geo-metric" aria-label="Показатель карты"><option value="count"${selected('count',state.geoMetric)}>Количество заказов</option><option value="amount"${selected('amount',state.geoMetric)}>Сумма заказов</option></select></div>${geoSourceControls()}<div class="map-wrap" id="map"><div class="loading">Загружаем карту…</div></div><div class="location-summary">По адресу доставки, затем оплаты. При отсутствии региона заказ остаётся в таблице. IP — приблизительное место подключения, не адрес доставки. Исторический IP может сменить владельца. Геобаза: ${state.report.geoip.available?'подключена':'не установлена, IP ещё не расшифрованы'} Границы: Natural Earth. При использовании DB-IP Lite — DB-IP.com, CC BY 4.0.</div></section><section class="panel">${panelHeading('Регионы доставки','За выбранный период')}<div class="mini-list">${regions.slice(0,9).map(l=>`<div class="mini-row"><div style="width:100%"><div style="display:flex;justify-content:space-between;margin-bottom:9px"><span>${esc(l.label)}</span><strong>${state.geoMetric==='count'?num(l.count):cash(l.amount)}</strong></div><div class="bar-track"><span style="width:${l[state.geoMetric]/max*100}%"></span></div></div></div>`).join('')}</div></section></div><section class="panel">${panelHeading('Города и населённые пункты','Показаны значения из заказов. Разное написание одного города может давать отдельные строки.')}<div class="toolbar"><input class="search" id="search" value="${esc(state.search)}" placeholder="Поиск города или области…" aria-label="Поиск города"></div><div class="table-scroll"><table><thead><tr><th>Населённый пункт</th><th>Область / страна</th><th>Заказы</th><th>Покупатели</th><th>Сумма заказов</th></tr></thead><tbody>${rows.slice((state.page-1)*PAGE,state.page*PAGE).map(l=>`<tr><td><strong>${esc(l.city)}</strong><span class="identity-meta">${esc(l.source)}</span>${entityHistory(l.years,'location')}</td><td>${esc(l.zone||'Не указана')}<span class="identity-meta">${esc(l.country)}</span></td><td>${l.count}</td><td>${l.customers}</td><td class="amount-cell">${cash(l.amount)}</td></tr>`).join('')||'<tr><td colspan="5" class="empty">Нет данных по этим условиям.</td></tr>'}</tbody></table></div>${pagination}</section>`;
}
const geoCache={};
const norm=s=>String(s||'').toLowerCase().replace(/[’'`]/g,'').replace(/\s+/g,' ').trim();
async function drawMap(){
  const mode=state.mapMode,metric=state.geoMetric;
  try{
    if(!geoCache[mode])geoCache[mode]=await (await fetch(appBase+'/static/'+(mode==='ukraine'?'ukraine':'countries')+'.geojson')).json();
    if(state.view!=='geography'||state.mapMode!==mode)return;
    const features=geoCache[mode].features,bounds=mode==='ukraine'?[21.5,41.5,44,53]:[-14,45,33,72];
    const project=([lon,lat])=>[(lon-bounds[0])/(bounds[1]-bounds[0])*720,(bounds[3]-lat)/(bounds[3]-bounds[2])*410];
    const ringPath=ring=>ring.map((p,i)=>{const [x,y]=project(p);return (i?'L':'M')+x.toFixed(1)+','+y.toFixed(1);}).join('')+'Z';
    const regionData=regionTotals();
    const values=features.map(f=>{
      const p=f.properties;
      if(mode==='ukraine'){
        let aliases=[p.name_uk,p.name_ru,p.name_en,p.name].map(norm);
        if(p.iso_3166_2==='UA-32')aliases.push('київська область','киевская область');
        const entry=regionData.filter(l=>l.zone_code===p.iso_3166_2||aliases.includes(norm(l.label)));
        return entry.reduce((a,l)=>a+l[metric],0);
      }
      const code=p.ISO_A2_EH||p.ISO_A2||({UKR:'UA'}[p.ADM0_A3]);return getLocations().filter(l=>code&&l.country_code===code).reduce((a,l)=>a+l[metric],0);
      return 0;
    });
    const max=Math.max(1,...values),colors=['#e5ebe2','#d1e1cb','#a4c69c','#79a773','#4b8150','#275c35'];
    const paths=features.map((f,i)=>{const polys=f.geometry.type==='Polygon'?[f.geometry.coordinates]:f.geometry.coordinates;
      const d=polys.map(poly=>poly.map(ringPath).join('')).join('');const level=values[i]?Math.max(1,Math.ceil(Math.sqrt(values[i]/max)*5)):0;
      return `<path class="map-region" d="${d}" fill="${colors[level]}" fill-rule="evenodd"><title>${esc(f.properties.name_uk||f.properties.NAME_RU||f.properties.NAME||f.properties.name)}: ${metric==='count'?num(values[i])+' заказов':cash(values[i])}</title></path>`;
    }).join('');
    $('#map').innerHTML=`<svg viewBox="0 0 720 410" role="img" aria-label="Карта распределения заказов по ${mode==='ukraine'?'областям Украины':'странам Европы'}"><defs><clipPath id="map-clip"><rect width="720" height="410"/></clipPath></defs><g clip-path="url(#map-clip)">${paths}</g></svg><div class="map-legend"><span>Меньше</span>${colors.slice(1).map(c=>`<i style="background:${c}"></i>`).join('')}<span>Больше</span></div>`;
  }catch{$('#map').innerHTML='<div class="empty">Карта недоступна. Данные по городам — в таблице ниже.</div>';}
}
function settingsView(){const m=state.meta,s=m.settings;return `<section class="panel">${panelHeading('Правила расчёта','Изменения сохраняются в локальной базе и применяются ко всему отчёту.')}<form id="settings-form"><div class="settings-grid"><div><h3 class="section-caption">Напоминания о повторной покупке</h3><label class="field">Базовый интервал, дней<input name="interval_days" type="number" min="1" max="730" value="${s.interval_days}" required><small>Используется, пока у покупателя мало истории.</small></label><label class="field">Допустимая задержка, дней<input name="grace_days" type="number" min="0" max="365" value="${s.grace_days}" required><small>После этой задержки покупатель попадёт в «Просрочено».</small></label><label class="field">Минимум дат покупок для личного интервала<input name="minimum_orders" type="number" min="3" max="30" value="${s.minimum_orders}" required><small>Личный интервал — медиана последних шести промежутков между разными датами покупок. Заказы в один день не сокращают интервал.</small></label>${followupSettings(s)}<button class="button primary" type="submit">Сохранить настройки</button></div><div><h3 class="section-caption">Учитываемые статусы заказов</h3>${m.statuses.map(sx=>`<label class="status-option"><input type="checkbox" name="status_ids" value="${sx.id}" ${s.status_ids.includes(sx.id)?'checked':''}>${esc(sx.name)}<small>${num(sx.count)}</small></label>`).join('')}</div></div></form></section>${sourceSettings(m)}`;}
function orderHTML(o,open=false){return `<details class="order-detail" ${open?'open':''}><summary><span>№${esc(o.id)}</span><span class="muted">${fmtDate(o.date)}</span><strong>${num(o.amount,2)} ${esc(o.currency)}</strong></summary><div class="order-body"><div class="order-meta">${esc(o.status)} · ${esc(o.city)}, ${esc(o.country)}<br>${esc(o.shipping_method)}<br>${esc(o.payment_method)}</div><div class="table-scroll"><table><thead><tr><th>Товар / фасовка</th><th>Кол-во</th><th>Сумма</th></tr></thead><tbody>${o.lines.map(l=>`<tr><td>${esc(l.name)}<span class="identity-meta">${esc(l.pack.label)} · ${esc(l.model)}</span></td><td>${l.quantity}</td><td>${num(l.total,2)} ${esc(o.currency)}</td></tr>`).join('')}</tbody></table></div><div style="margin-top:13px">${o.totals.map(t=>`<div class="order-total"><span>${esc(t.title)}</span><span>${num(t.value,2)} ${esc(o.currency)}</span></div>`).join('')}</div>${o.history.length?`<div class="section-caption">История статусов</div><div class="order-meta">${o.history.map(h=>fmtDate(h.date)+' · '+esc(h.status)).join('<br>')}</div>`:''}</div></details>`;}
function openDrawer(label){$('#drawer-label').textContent=label;$('#drawer-content').innerHTML='<div class="loading">Загружаем…</div>';if(!$('#drawer').open)$('#drawer').showModal();}
async function openCustomer(id){
  openDrawer('КАРТОЧКА ПОКУПАТЕЛЯ');
  try{
    const data=await api('/api/customers/'+encodeURIComponent(id)+'/orders?'+query()),c=data.customer;state.customer=data;
    $('#drawer-content').innerHTML=`<h2 class="drawer-name">${name(c)}${infoHelp('История')}</h2><div class="drawer-contact">${contact(c)} · ${state.privacy?'Телефон скрыт':esc(c.phone)}<br>${esc(c.city)}, ${esc(c.country)} · ${c.account_count} учётных записей</div><div class="drawer-stats"><div class="drawer-stat"><small>Заказов за всё время</small><strong>${c.lifetime_count}</strong></div><div class="drawer-stat"><small>Сумма за всё время</small><strong>${num(c.lifetime_amount,2)}</strong></div><div class="drawer-stat"><small>Обычный интервал</small><strong>${c.interval_days} дн.</strong></div></div><div class="notice">${c.personal_interval?'Индивидуальный':'Базовый'} срок: ${c.interval_days} дней. Последний заказ — ${fmtDate(c.last_order)}. Следующий ожидается ${fmtDate(c.due_on)}. ${c.reminder==='overdue'?'Задержка '+c.overdue_days+' дней.':''}</div>${c.manual?`<button class="button" data-action="unmerge" data-id="${c.id}">Отменить ручные объединения этой карточки</button>`:''}${customerActionForm(c)}<h3 class="section-caption">История заказов · ${c.lifetime_count}</h3><div id="customer-orders">${data.orders.slice(0,20).map((o,i)=>orderHTML(o,i===0)).join('')}</div>${data.orders.length>20?'<button class="button" data-action="more-orders">Показать все заказы</button>':''}`;
    enhanceHelp($('#drawer'));
  }catch(e){$('#drawer-content').innerHTML='<div class="notice">'+esc(e.message)+'</div>';}
}
async function openWeek(ids){state.customer=null;openDrawer('ЗАКАЗЫ ЗА НЕДЕЛЮ');try{const orders=await Promise.all(ids.map(id=>api('/api/orders/'+id)));$('#drawer-content').innerHTML='<h2 class="drawer-name">Заказы за неделю</h2><p class="muted">'+orders.length+' заказов · '+cash(orders.reduce((a,o)=>a+o.amount,0))+'</p>'+orders.map(o=>orderHTML(o,true)).join('');}catch(e){toast(e.message);}}
function navigate(view){state.historyOpen=null;state.duplicateCustomer=null;state.view=view;state.page=1;state.search='';render();window.scrollTo({top:0,behavior:'smooth'});}
function exportCSV(){
  const rows=customerRows(),fields=['Покупатель','Город','Заказов за период','Сумма за период','Заказов всего','Сумма всего','Последний заказ','Дней с заказа','Интервал','Ожидаемая дата','Сегмент','RFM','Приоритет','Контакт разрешён правилами','Email','Телефон'];
  if(!state.report.year)fields.push(...state.report.calendar.flatMap(col=>['Заказы '+col.label,'Сумма '+col.label]));
  const cell=v=>'"'+String(v??'').replace(/^[=+@\-]/,"'$&").replace(/"/g,'""')+'"';
  const csv='\ufeff'+[fields,...rows.map(c=>[state.privacy?'Покупатель '+c.id:c.name,c.city,c.order_count,c.amount,c.lifetime_count,c.lifetime_amount,c.last_order,c.days_since,c.interval_days,c.due_on,c.segment_name,c.rfm,c.priority,c.followup_eligible?'Да':c.suppression,state.privacy?'':c.email,state.privacy?'':c.phone,...(state.report.year?[]:state.report.calendar.flatMap(col=>[c.activity[col.key]?.count||0,c.activity[col.key]?.amount||0]))])].map(r=>r.map(cell).join(';')).join('\r\n');
  const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));const a=document.createElement('a');a.href=url;a.download='customers-'+(state.report.year||'all')+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
$('#navigation').addEventListener('click',e=>{const b=e.target.closest('[data-view]');if(b)navigate(b.dataset.view);});
['#year','#as-of','#currency'].forEach(s=>$(s).addEventListener('change',()=>{if($('#as-of').value){state.historyOpen=null;load();}}));
$('#privacy').addEventListener('click',()=>{state.privacy=!state.privacy;render();if($('#drawer').open&&state.customer)openCustomer(state.customer.customer.id);});
$('#logout').addEventListener('click',async()=>{await api('/api/logout',{method:'POST'});location.href=appUrl('/login');});
let searchTimer;
document.addEventListener('input',e=>{if(e.target.id==='search'){state.search=e.target.value;clearTimeout(searchTimer);searchTimer=setTimeout(()=>{const value=state.search,pos=e.target.selectionStart;state.page=1;render();const input=$('#search');input.focus();input.setSelectionRange(pos,pos);},150);}});
document.addEventListener('change',e=>{
  const mappings={reminder:'reminder',sort:'sort','product-mode':'productMode','product-sort':'productSort','map-mode':'mapMode','geo-metric':'geoMetric','geo-source':'geoSource','orders-basis':'ordersBasis','segment':'segment','cohort-count':'cohortCount'};
  if(mappings[e.target.id]){state[mappings[e.target.id]]=e.target.value;state.page=1;render();}
  if(['orders-min','orders-max'].includes(e.target.id)){state[e.target.id==='orders-min'?'ordersMin':'ordersMax']=e.target.value;state.page=1;render();}
  if(e.target.id==='eligible-only'){state.eligibleOnly=e.target.checked;state.page=1;render();}
  if(e.target.id==='period-only'){state.periodOnly=e.target.checked;state.page=1;render();}
});
document.addEventListener('submit',async e=>{
  if(e.target.id!=='settings-form')return;e.preventDefault();const data=new FormData(e.target);const body={interval_days:Number(data.get('interval_days')),grace_days:Number(data.get('grace_days')),minimum_orders:Number(data.get('minimum_orders')),status_ids:data.getAll('status_ids'),followup_min_orders:Number(data.get('followup_min_orders')),contact_cooldown_days:Number(data.get('contact_cooldown_days'))};
  try{await api('/api/settings',{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});state.meta=await api('/api/meta');await load();toast('Настройки сохранены. Отчёт пересчитан.');}catch(err){toast(err.message);}
});
document.addEventListener('click',async e=>{
  const b=e.target.closest('[data-action]');if(!b)return;
  try{
    switch(b.dataset.action){
      case 'refresh-source':await refreshSource(true);toast('Отчёт обновлён из последней успешной копии магазина.');break;
      case 'customer':await openCustomer(b.dataset.id);break;
      case 'week':await openWeek(b.dataset.ids.split(','));break;
      case 'period-orders':await openPeriodOrders(b.dataset.id,b.dataset.start,b.dataset.end);break;
      case 'close-dialog':$('#drawer').close();state.customer=null;break;
      case 'prev-page':state.page=Math.max(1,state.page-1);render();break;
      case 'next-page':state.page++;render();break;
      case 'show-overdue':state.view='customers';state.reminder='overdue';state.periodOnly=false;state.sort='priority';state.ordersBasis='lifetime_count';state.ordersMin=state.meta.settings.followup_min_orders;state.ordersMax='';state.eligibleOnly=true;state.segment='all';state.ids=null;state.page=1;render();break;
      case 'go-customers':navigate('customers');break;
      case 'export':exportCSV();break;
      case 'merge':b.disabled=true;await api('/api/identity/merge',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids:b.dataset.ids.split(','),as_of:$('#as-of').value})});await load();toast('История объединена. Отменить можно в карточке покупателя.');break;
      case 'unmerge':b.disabled=true;await api('/api/identity/unmerge',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:b.dataset.id,as_of:$('#as-of').value})});$('#drawer').close();state.customer=null;await load();toast('Ручные объединения отменены.');break;
      case 'more-orders':$('#customer-orders').innerHTML=state.customer.orders.map(o=>orderHTML(o)).join('');b.remove();break;
    }
  }catch(err){toast(err.message);b.disabled=false;}
});
$('#drawer').addEventListener('click',e=>{if(e.target===$('#drawer')){const r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right)$('#drawer').close();}});
$('#period-help').innerHTML=infoHelp('Период');
$('#asof-help').innerHTML=infoHelp('Дата проверки');
$('#currency-help').innerHTML=infoHelp('Валюта');
init();
setInterval(()=>refreshSource(),60000);
