function periodTitle(){return state.report?.year?state.report.year+' год':'весь период';}
function entityHistory(years,kind){
  if(state.report.year)return '';
  const rows=Object.entries(years||{}).sort((a,b)=>Number(b[0])-Number(a[0]));
  return `<details class="entity-history"><summary>По годам (${rows.length})</summary>${dataTable(kind==='product'?['Год','Упаковки','Сумма товаров','Вес, кг']:['Год','Заказы','Покупатели','Сумма заказов'],rows.map(([year,v])=>kind==='product'?[year,num(v.quantity),cash(v.amount),num(v.grams/1000,3)]:[year,num(v.count),num(v.customers),cash(v.amount)]))}</details>`;
}
function yearOptions(years,current){return '<option value="0"'+(String(current)==='0'?' selected':'')+'>За всё время</option>'+years.map(y=>`<option value="${y}"${String(current)===String(y)?' selected':''}>${y} год</option>`).join('');}
function historySummaryView(){
  const r=state.report,t=r.history_totals;if(!t||['settings','duplicates','manual','competitors'].includes(state.view))return '';
  const opened=state.historyOpen===null?(r.year===0&&state.view==='overview'):state.historyOpen;
  return `<details class="panel history-summary" id="history-summary" ${opened?'open':''}><summary><span>История магазина по годам ${infoHelp('История магазина по годам')}</span><span class="muted">${num(t.order_count)} заказов · ${num(t.customer_count)} покупателей · ${cash(t.amount)}</span></summary><div class="history-intro">Вся доступная история до ${fmtDate(r.as_of)} · выбранные статусы и валюта. Нажмите год для подробностей.<button class="button" data-action="history-all">За всё время</button><button class="button" data-action="history-export">↓ CSV по годам</button></div><div class="table-scroll"><table class="annual-table"><thead><tr>${['Год','Сумма заказов','Заказы','Покупатели','Новые покупатели','Вернулись из прошлых лет','Повторные заказы','Средний заказ','Упаковки','Вес, кг'].map(x=>`<th>${esc(x)}${infoHelp(x)}</th>`).join('')}</tr></thead><tbody>${[...r.annual].reverse().map(y=>`<tr class="${r.year===y.year?'chosen-year':''}"><td><button class="year-link" data-action="history-year" data-year="${y.year}">${y.year}</button>${y.year===Number(r.as_of.slice(0,4))?'<small class="identity-meta">до '+fmtDate(r.as_of)+'</small>':''}</td><td>${cash(y.amount)}</td><td>${num(y.order_count)}</td><td>${num(y.customer_count)}</td><td>${num(y.new_customers)}</td><td>${num(y.returning_customers)}</td><td>${num(y.repeat_orders)}</td><td>${cash(y.average)}</td><td>${num(y.quantity)}</td><td>${num(y.kilograms,3)}</td></tr>`).join('')}</tbody><tfoot><tr><th>Весь период</th><td>${cash(t.amount)}</td><td>${num(t.order_count)}</td><td>${num(t.customer_count)} уникальных</td><td colspan="3">Покупатели в нескольких годах считаются в итоге один раз</td><td>${cash(t.order_count?t.amount/t.order_count:0)}</td><td>${num(t.quantity)}</td><td>${num(t.kilograms,3)}</td></tr></tfoot></table></div></details>`;
}
function verticalChart(groups,{money=false,label='Вертикальная диаграмма',kind='period',series=[]}={}){
  const colors=['#306b50','#86a982','#c48f49','#6686aa','#9d79a1','#859a4f','#ba7562','#65a3a0'];
  const max=Math.max(1,...groups.flatMap(g=>g.items.map(i=>Number(i.value)||0)));
  const perGroup=Math.max(1,...groups.map(g=>g.items.length)),width=Math.max(740,groups.length*Math.max(43,perGroup*12+12)+70),left=64,plot=width-left-15,groupWidth=plot/Math.max(groups.length,1),barWidth=Math.min(perGroup===1?37:13,(groupWidth-12)/perGroup),bottom=220;
  const val=v=>money?num(v,0):num(v,1);
  const grid=Array.from({length:5},(_,i)=>{const value=max*i/4,y=bottom-i*47;return `<line x1="${left}" x2="${width-10}" y1="${y}" y2="${y}" stroke="#e7ece5"/><text x="${left-8}" y="${y+4}" text-anchor="end" fill="#708077" font-size="14">${val(value)}</text>`;}).join('');
  const bars=groups.map((g,idx)=>{const center=left+groupWidth*(idx+.5);return g.items.map((item,j)=>{const height=Math.max(0,Number(item.value)||0)/max*188,x=center+(j-(g.items.length-1)/2)*barWidth-barWidth*.42,title=item.detail||`${g.label}${item.label?' · '+item.label:''}: ${money?cash(item.value):num(item.value)+' заказов'}`;return `<rect class="chart-bar" data-chart-bar data-value="${Number(item.value)||0}" x="${x}" y="${bottom-height}" width="${barWidth*.84}" height="${height}" rx="3" fill="${item.color||colors[j%colors.length]}" tabindex="0" role="${item.month?'button':'img'}" ${item.month?'data-purchase-month="'+item.month+'"':''} aria-label="${esc(title)}"><title>${esc(title)}</title></rect>${g.items.length===1?`<text x="${center}" y="${bottom-height-8}" text-anchor="middle" fill="#315e44" font-size="14">${val(item.value)}</text>`:''}`;}).join('')+`<text x="${center}" y="247" text-anchor="middle" fill="#64756b" font-size="14">${esc(g.label)}</text>`;}).join('');
  return `${series.length?`<div class="chart-legend">${series.map((s,i)=>`<span><i style="background:${colors[i%colors.length]}"></i>${esc(s)}</span>`).join('')}</div>`:''}<div class="chart-scroll"><svg data-chart="${kind}" class="vertical-chart" viewBox="0 0 ${width} 268" style="min-width:${Math.min(width,900)}px" role="img" aria-label="${esc(label)}">${grid}${bars}</svg></div>`;
}
function purchaseChart(){
  const r=state.report,names=['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
  if(r.year){
    const months=r.monthly_purchases||[];
    return verticalChart(months.map(m=>({label:names[m.month-1],items:[{value:m.amount,month:m.month,detail:`${names[m.month-1]} ${r.year}: ${cash(m.amount)}, заказов ${m.count}${m.start>r.as_of?' · месяц ещё не наступил':m.end>r.as_of?' · по '+fmtDate(r.as_of):''}. Нажмите для заказов.`}]})),{money:true,label:'Суммы заказов по месяцам '+r.year+' года',kind:'purchase-months'})+'<p class="purchase-month-note">Нажмите месяц для списка заказов.</p>';
  }
  return verticalChart(r.calendar.map(c=>({label:c.label,items:[{value:r.activity[c.key]?.amount||0,detail:`${c.label}: ${cash(r.activity[c.key]?.amount||0)}, заказов ${r.activity[c.key]?.count||0}`}]})),{money:true,label:'Суммы заказов по календарным годам'});
}
let purchaseMonthSequence=0,purchaseMonthState=null;
async function openPurchaseMonth(month){
  const r=state.report,bucket=r?.monthly_purchases?.find(m=>m.month===month);if(!r?.year||!bucket)return;
  const sequence=++purchaseMonthSequence;purchaseMonthState={sequence,ids:bucket.order_ids,loaded:0,busy:false};
  state.customer=null;openDrawer('ЗАКАЗЫ ЗА МЕСЯЦ');
  document.getElementById('drawer-content').innerHTML=`<div class="purchase-month-detail" data-sequence="${sequence}"><h2 class="drawer-name">${esc(new Date(bucket.start+'T12:00:00').toLocaleDateString('ru-RU',{month:'long',year:'numeric'}))}</h2><p>${num(bucket.count)} заказов · ${cash(bucket.amount)}${bucket.end>r.as_of&&bucket.start<=r.as_of?' · по '+fmtDate(r.as_of):''}</p><div id="purchase-month-orders"></div><p id="purchase-month-status" role="status"></p><button class="button" type="button" data-purchase-more hidden>Показать ещё</button></div>`;
  await loadPurchaseMonth();
}
async function loadPurchaseMonth(){
  const job=purchaseMonthState,root=document.querySelector('#drawer[open] .purchase-month-detail');
  if(!job||job.busy||Number(root?.dataset.sequence)!==job.sequence)return;job.busy=true;
  const status=root.querySelector('#purchase-month-status'),button=root.querySelector('[data-purchase-more]');button.disabled=true;status.textContent='Загружаем заказы…';
  try{
    const ids=job.ids.slice(job.loaded,job.loaded+20),orders=[];
    for(let start=0;start<ids.length;start+=5)orders.push(...await Promise.all(ids.slice(start,start+5).map(id=>api('/api/orders/'+encodeURIComponent(id)))));
    if(!root.isConnected||!document.getElementById('drawer').open||purchaseMonthState!==job)return;
    root.querySelector('#purchase-month-orders').insertAdjacentHTML('beforeend',orders.map(o=>orderHTML(o,true)).join(''));
    job.loaded+=orders.length;status.textContent=job.ids.length?'Показано '+job.loaded+' из '+job.ids.length:'Заказов за этот месяц нет.';
    button.hidden=job.loaded>=job.ids.length;button.textContent='Показать ещё '+Math.min(20,job.ids.length-job.loaded);
  }catch(error){if(root.isConnected){status.textContent=error.message;button.hidden=false;button.textContent='Повторить загрузку';}}
  finally{job.busy=false;if(button.isConnected)button.disabled=false;}
}
document.addEventListener('click',event=>{const month=event.target.closest('[data-purchase-month]');if(month)openPurchaseMonth(Number(month.dataset.purchaseMonth));if(event.target.closest('[data-purchase-more]'))loadPurchaseMonth();});
document.addEventListener('keydown',event=>{const month=event.target.closest('[data-purchase-month]');if(month&&['Enter',' '].includes(event.key)){event.preventDefault();openPurchaseMonth(Number(month.dataset.purchaseMonth));}});

function repeatChart(){const r=state.report,max=Math.max(1,...r.calendar.map(c=>(r.activity[c.key]?.count||0))),width=Math.max(740,r.calendar.length*15+70),step=(width-70)/Math.max(1,r.calendar.length);return `<div class="chart-legend"><span><i style="background:#b9d2b5"></i>Первая покупка</span><span><i style="background:#316a4c"></i>Повторный заказ</span></div><div class="chart-scroll"><svg data-chart="repeat" class="vertical-chart" viewBox="0 0 ${width} 255" role="img" aria-label="Новые и повторные заказы по ${r.year?'неделям':'годам'}">${r.calendar.map((c,i)=>{const v=r.activity[c.key]||{},a=180*(v.first_count||0)/max,b=180*(v.repeat_count||0)/max,x=55+i*step,w=Math.max(5,Math.min(38,step-5)),title=`${c.label}: первых ${v.first_count||0}, повторных ${v.repeat_count||0}`;return `<g role="img" tabindex="0" aria-label="${esc(title)}"><title>${esc(title)}</title><rect x="${x}" y="${210-a-b}" width="${w}" height="${b}" fill="#316a4c"/><rect x="${x}" y="${210-a}" width="${w}" height="${a}" fill="#b9d2b5"/></g>${(!r.year||i%4===0||i===r.calendar.length-1)?`<text x="${x+w/2}" y="237" text-anchor="middle" font-size="14" fill="#708077">${esc(c.label)}</text>`:''}`;}).join('')}</svg></div>`;}
function monthlyChart(){
  const r=state.report,names=['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'],compare=!r.year&&state.seasonYears,metric=state.seasonMetric,series=compare?r.annual.map(y=>String(y.year)):[];
  const groups=names.map((label,i)=>({label,items:compare?r.annual.map(y=>({label:String(y.year),value:y.months[i][metric]})):[{value:r.marketing.months[i][metric]}]}));
  return `<div class="toolbar"><label>Показатель <select id="season-metric" aria-label="Показатель сезонности"><option value="count"${selected('count',metric)}>Заказы</option><option value="amount"${selected('amount',metric)}>Сумма заказов</option></select></label>${!r.year?`<label class="checkbox-label"><input type="checkbox" id="season-years" ${state.seasonYears?'checked':''}>Сравнить годы</label>`:''}<span class="muted">${compare?'Отдельный цвет для каждого года':r.year?r.year+' год':'Все годы суммарно'}</span></div>`+verticalChart(groups,{money:metric==='amount',label:'Месяцы года: '+(metric==='count'?'заказы':'сумма заказов')+(compare?', сравнение годов':''),kind:'months',series});
}
async function openPeriodOrders(id,start,end){
  state.customer=null;openDrawer('ЗАКАЗЫ ЗА ПЕРИОД');
  try{
    const data=await api('/api/customers/'+encodeURIComponent(id)+'/orders?'+query());
    const orders=data.orders.filter(o=>o.date.slice(0,10)>=start&&o.date.slice(0,10)<=end);state.customer={...data,orders};
    $('#drawer-content').innerHTML=`<h2 class="drawer-name">${name(data.customer)}</h2><p>${fmtDate(start)} — ${fmtDate(end)} · ${orders.length} заказов · ${cash(orders.reduce((sum,o)=>sum+o.amount,0))}</p><div id="customer-orders">${orders.slice(0,20).map(o=>orderHTML(o,true)).join('')}</div>${orders.length>20?'<button class="button" data-action="more-orders">Показать все заказы</button>':''}`;
    enhanceHelp($('#drawer'));
  }catch(error){$('#drawer-content').textContent=error.message;}
}
function duplicateRows(){
  let rows=state.report.duplicate_pairs||[];
  if(state.duplicateCustomer)rows=rows.filter(r=>r.ids.includes(navigationCustomerId(state.duplicateCustomer)));
  if(state.duplicateKind!=='all')rows=rows.filter(r=>r.kinds.includes(state.duplicateKind));
  const q=state.search.trim().toLocaleLowerCase();if(q)rows=rows.filter(r=>[r.left.name,r.left.email,r.left.phone,r.right.name,r.right.email,r.right.phone,...(r.left.names||[]),...(r.right.names||[]),...r.matches.map(m=>m.value)].join(' ').toLocaleLowerCase().includes(q));
  const rank=r=>Math.min(...r.kinds.map(k=>({email:0,phone:1,name:2,surname:3}[k])));
  return [...rows].sort((a,b)=>{let v=0;if(state.duplicateSort==='left'||state.duplicateSort==='right')v=a[state.duplicateSort].name.localeCompare(b[state.duplicateSort].name,'uk');else if(state.duplicateSort==='orders')v=(a.left.lifetime_count+a.right.lifetime_count)-(b.left.lifetime_count+b.right.lifetime_count);else v=rank(a)-rank(b)||b.matches.length-a.matches.length;return state.duplicateDir*v||a.id.localeCompare(b.id);});
}
function selectedDuplicateRows(){const selectedIds=state.duplicateSelected;return (state.report.duplicate_pairs||[]).filter(r=>selectedIds.has(r.id));}
function duplicateSelectionInfo(rows){
  const roots=new Map(),uses=new Map();const find=x=>roots.get(x)===x?x:find(roots.get(x));
  rows.forEach(r=>r.ids.forEach(id=>{if(!roots.has(id))roots.set(id,id);uses.set(id,(uses.get(id)||0)+1);}));
  rows.forEach(r=>roots.set(find(r.ids[0]),find(r.ids[1])));
  return {contacts:roots.size,groups:new Set([...roots.keys()].map(find)).size,shared:[...uses.values()].some(n=>n>1)};
}
function duplicateTableView(){
  const all=state.report.duplicate_pairs||[],valid=new Set(all.map(r=>r.id));state.duplicateSelected=new Set([...state.duplicateSelected].filter(id=>valid.has(id)));
  const rows=duplicateRows(),pagination=pages(rows.length),visible=rows.slice((state.page-1)*PAGE,state.page*PAGE),chosen=selectedDuplicateRows(),preview=duplicateSelectionInfo(chosen);
  const person=c=>`<div class="duplicate-person"><button class="identity-button" data-action="customer" data-id="${c.id}">${name(c)}</button><span class="identity-meta">${state.privacy?'Контакты скрыты':esc(c.email||'Email не указан')+'<br>'+esc(c.phone||'Телефон не указан')}</span><span class="identity-meta">${esc(c.city)} · ${num(c.lifetime_count)} заказов · ${cash(c.lifetime_amount)}</span></div>`;
  const sortHead=(label,key)=>`<th aria-sort="${state.duplicateSort===key?(state.duplicateDir===1?'ascending':'descending'):'none'}"><button class="sort-heading" data-action="duplicate-sort" data-key="${key}">${label} ${state.duplicateSort===key?(state.duplicateDir===1?'↑':'↓'):'↕'}</button>${infoHelp(label)}</th>`;
  const mergeButton=()=>`<button class="button primary" data-action="merge-selected" ${!chosen.length||state.mergeBusy?'disabled':''}>${state.mergeBusy?'Объединяем…':'Объединить выбранные по строкам'} (${chosen.length})</button>`;
  const overlapNotice=preview.shared?'<div class="responsive-note merge-overlap">Выбраны строки с общим контактом. Они образуют цепочку: A–B и B–C объединят A, B и C. Независимые пары останутся разными покупателями.</div>':'';
  return `${navigationReturnBanner()}${identityPickerView()}<section class="panel duplicate-panel">${panelHeading('Возможные совпадения контактов','Одна строка — пара контактов. Совпавшие поля перечислены в отдельном столбце.')}<div class="responsive-note">Проверьте историю перед объединением. Совпадение имени или фамилии — повод для проверки, а не доказательство. Изменяется только аналитика; отменить ручное объединение можно в карточке покупателя.</div><div class="toolbar"><input id="search" class="search" aria-label="Поиск совпадений" placeholder="Имя, телефон или email…" value="${esc(state.search)}"><select id="duplicate-kind" aria-label="Что совпадает">${[['all','Все совпадения'],['email','Совпадает email'],['phone','Совпадает телефон'],['name','Совпадает полное имя'],['surname','Совпадает фамилия']].map(([k,v])=>`<option value="${k}"${selected(k,state.duplicateKind)}>${v}</option>`).join('')}</select><span class="muted">Сортировка — по заголовкам столбцов</span>${state.duplicateCustomer?`<button class="button" data-action="clear-duplicate-customer">Все пары · снять фильтр профиля ${state.duplicateCustomer}</button>`:''}</div><div class="merge-toolbar">${mergeButton()}${infoHelp('Объединить выбранные по строкам')}<button class="button" data-action="clear-pairs" ${!chosen.length||state.mergeBusy?'disabled':''}>Снять выбор</button><span class="muted">До 100 пар за операцию · выбрано ${preview.contacts} контактов, получится групп: ${preview.groups}</span></div>${overlapNotice}<p class="duplicate-mobile-hint">Совпадения и число заказов — справа. Смахните таблицу влево →</p><div class="table-scroll duplicate-scroll" tabindex="0" role="region" aria-label="Сравнение пар контактов, дополнительные столбцы справа"><table class="duplicate-table"><colgroup><col class="duplicate-select-column"><col class="duplicate-person-column"><col class="duplicate-person-column"><col class="duplicate-match-column"><col class="duplicate-orders-column"></colgroup><thead><tr><th><label class="duplicate-check-target"><input type="checkbox" id="duplicate-select-all" aria-label="Выбрать все пары на странице" ${visible.length&&visible.every(r=>state.duplicateSelected.has(r.id))?'checked':''} ${!visible.length||state.mergeBusy?'disabled':''}></label></th>${sortHead('Контакт 1','left')}${sortHead('Контакт 2','right')}${sortHead('Что совпадает','match')}${sortHead('Заказов у пары','orders')}</tr></thead><tbody>${visible.map(r=>`<tr class="${state.duplicateSelected.has(r.id)?'selected-pair':''}"><td><label class="duplicate-check-target"><input type="checkbox" class="pair-checkbox" data-pair="${r.id}" aria-label="Выбрать пару ${r.ids.join(' и ')}" ${state.duplicateSelected.has(r.id)?'checked':''} ${state.mergeBusy?'disabled':''}></label></td><td>${person(r.left)}</td><td>${person(r.right)}</td><td>${r.matches.map(m=>`<span class="match-item"><strong>${esc(m.label)}</strong><span>${state.privacy?'Значение скрыто':esc(m.value)}</span></span>`).join('')}${r.kinds.every(k=>['name','surname'].includes(k))?'<small class="name-only">Только '+(r.kinds.includes('name')?'полное имя':'фамилия')+' — возможны разные люди. Проверьте перед объединением.</small>':''}${r.surname_limited?'<small class="name-only">Распространённая фамилия: показана часть пар. Любые карточки можно выбрать вручную выше.</small>':''}</td><td>${num(r.left.lifetime_count+r.right.lifetime_count)}</td></tr>`).join('')||'<tr><td colspan="5" class="empty">Совпадений по этим условиям нет.</td></tr>'}</tbody></table></div>${pagination}<div class="merge-toolbar merge-toolbar-bottom" aria-label="Объединение выбранных пар внизу страницы"><span class="muted" role="status" aria-live="polite">Выбрано пар: ${chosen.length} · контактов: ${preview.contacts} · получится групп: ${preview.groups}</span>${overlapNotice}${mergeButton()}${infoHelp('Объединить выбранные по строкам')}<button class="button" data-action="clear-pairs" ${!chosen.length||state.mergeBusy?'disabled':''}>Снять выбор</button></div></section>`;
}
document.addEventListener('toggle',e=>{if(e.target.id==='history-summary'&&e.target.isConnected)state.historyOpen=e.target.open;},true);
document.addEventListener('change',e=>{
  if(e.target.id==='season-metric'){state.seasonMetric=e.target.value;render();}
  if(e.target.id==='season-years'){state.seasonYears=e.target.checked;render();}
  if(e.target.id==='duplicate-kind'){state.duplicateKind=e.target.value;state.page=1;render();}
  if(e.target.matches('.pair-checkbox')||e.target.id==='duplicate-select-all'){
    const ids=e.target.id==='duplicate-select-all'?duplicateRows().slice((state.page-1)*PAGE,state.page*PAGE).map(r=>r.id):[e.target.dataset.pair],next=new Set(state.duplicateSelected);
    ids.forEach(id=>e.target.checked?next.add(id):next.delete(id));
    if(next.size>100){e.target.checked=false;toast('Максимум 100 пар за одну операцию.');return;}
    state.duplicateSelected=next;render();
  }
});
document.addEventListener('click',async e=>{
  const b=e.target.closest('[data-action]');if(!b)return;
  if(['history-year','history-all'].includes(b.dataset.action)){$('#year').value=b.dataset.year||'0';state.historyOpen=null;await load();}
  if(b.dataset.action==='history-export'){
    const fields=['Год','Сумма заказов','Заказы','Покупатели','Новые покупатели','Вернулись из прошлых лет','Повторные заказы','Средний заказ','Упаковки','Вес кг'];
    const rows=state.report.annual.map(y=>[y.year,y.amount,y.order_count,y.customer_count,y.new_customers,y.returning_customers,y.repeat_orders,y.average,y.quantity,y.kilograms]);
    downloadText('\ufeff'+[fields,...rows].map(r=>r.map(csvCell).join(';')).join('\r\n'),'store-history-'+state.report.currency+'.csv');
  }
  if(b.dataset.action==='duplicate-sort'){const key=b.dataset.key;state.duplicateDir=state.duplicateSort===key?-state.duplicateDir:1;state.duplicateSort=key;state.page=1;render();}
  if(b.dataset.action==='clear-pairs'){state.duplicateSelected.clear();render();}
  if(b.dataset.action==='merge-selected'&&!state.mergeBusy&&!identityPicker.busy){
    const rows=selectedDuplicateRows();if(!rows.length)return;state.mergeBusy=true;render();
    try{const result=await api('/api/identity/merge-pairs',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pairs:rows.map(r=>r.ids),as_of:$('#as-of').value,currency:$('#currency').value})});state.duplicateSelected.clear();identityPickerInvalidate();await load();navigationRememberMerge(rows);if(uiNavigation.returnToCRM)navigationReturnToCRM();toast(`Объединено пар: ${result.pairs_merged}. Получилось групп: ${result.groups}.`);}
    catch(error){toast(error.message);}
    finally{state.mergeBusy=false;render();}
  }
});
