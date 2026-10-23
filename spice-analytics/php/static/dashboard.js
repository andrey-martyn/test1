/* Business overview. Reads saved local reports; actions open existing workflows. */
const dashboardState={data:null,loading:false,error:'',marketError:'',group:'days',filter:'all',marketTab:'prices',sequence:0};
const dashboardMoney=value=>value===null||value===undefined?'—':num(value,0)+' '+esc(dashboardState.data?.currency||'UAH');
const dashboardMonth=date=>new Date(date+'T12:00:00').toLocaleDateString('ru-RU',{month:'long',year:'numeric'});
const dashboardDate=value=>value?new Date(value).toLocaleString('ru-RU',{timeZone:'Europe/Kiev',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}):'ещё не было';
function dashboardFresh(){const d=dashboardState.data;if(!d||dashboardState.error)return false;return d.sync?.configured?d.sync.state==='ok'&&!d.sync.stale:Date.parse(d.server_now)-Date.parse(d.imported_at||'')<86400000;}
async function dashboardLoad(){
  if(dashboardState.loading)return;dashboardState.loading=true;dashboardState.error='';const sequence=++dashboardState.sequence;
  if(state.view==='overview')render();
  const currency=$('#currency').value||'UAH';
  const results=await Promise.allSettled([api('/api/dashboard?currency='+encodeURIComponent(currency)),api('/api/competitors')]);
  if(sequence!==dashboardState.sequence)return;
  const [sales,market]=results;
  if(sales.status==='fulfilled'){dashboardState.data=sales.value;state.report=null;}else dashboardState.error=sales.reason.message;
  if(market.status==='fulfilled'){competitorState.data=market.value;competitorState.sandbox=market.value.sandbox_pricing||null;dashboardState.marketError='';}else dashboardState.marketError=market.reason.message;
  dashboardState.loading=false;if(state.view==='overview')render();
}
function dashboardDelta(key){const c=dashboardState.data.comparison,a=c.current[key],b=c.previous[key];if(!c.days||a===null||b===null)return 'Сравнение пока недоступно';if(b===0)return a===0?'Без изменений за полные дни':'В прошлом периоде было 0';const delta=(a/b-1)*100;return (delta>0?'+':'')+num(delta,1)+'% за '+c.days+' полных дней';}
function dashboardMarket(){
  const data=competitorState.data;if(!data||dashboardState.marketError)return {prices:[],news:[],errors:0,fresh:0,total:0,last:null};
  const targets=data.targets.filter(t=>t.enabled&&!competitorOwn(t)),byId=new Map(targets.map(t=>[t.id,t])),now=Date.parse(data.server_now),cutoff=now-7*86400000;
  const prices=[],seen=new Set();
  for(const change of data.changes||[]){
    const target=byId.get(change.target_id);if(change.kind!=='price'||!target||!target.match_confirmed||target.last_attempt?.state==='error'||competitorStale(target)||Date.parse(change.occurred_at)<cutoff)continue;
    const before=change.before?.offers||[],after=change.after?.offers||[];
    for(const next of competitorPackagingFilter(target,after.filter(o=>o.unit==='pack'&&o.grams===500)).offers){
      const identity=o=>JSON.stringify([o.key,o.variant_id||'',o.source_variant_id||'',o.grams,competitorPackaging(o),o.currency,o.unit,o.min_quantity,o.condition||'']);
      const previous=before.find(o=>identity(o)===identity(next));
      const current=target.observation?.offers?.find(o=>identity(o)===identity(next));
      if(!previous||!current||!competitorEligible(target,current)||!competitorEligible(target,previous)||!competitorEligible(target,next)||next.currency!=='UAH')continue;
      const oldPrice=Number(previous.price),newPrice=Number(next.price),percent=(newPrice/oldPrice-1)*100,key=target.id+':'+identity(next);
      if(!Number.isFinite(percent)||Math.abs(newPrice-oldPrice)<.005||seen.has(key))continue;seen.add(key);
      prices.push({id:'market-'+change.id,key,target,oldPrice,newPrice,percent,at:change.occurred_at,label:competitorGroupName(target.match_group),pack:competitorPackagingNote(target,next)});
    }
  }
  prices.sort((a,b)=>Math.abs(b.percent)-Math.abs(a.percent)||Math.abs(b.newPrice-b.oldPrice)-Math.abs(a.newPrice-a.oldPrice));
  const news=[];
  for(const discovery of data.discoveries||[]){const target=byId.get(discovery.target_id);if(target&&!competitorStale(target)&&target.last_attempt?.state!=='error'&&Date.parse(discovery.first_seen)>=cutoff)news.push({kind:'new',title:discovery.title||'Новая замеченная позиция',source:discovery.source_id,at:discovery.first_seen,target,url:discovery.url});}
  for(const change of data.changes||[]){const target=byId.get(change.target_id);if(change.kind==='terms'&&target&&!competitorStale(target)&&target.last_attempt?.state!=='error'&&Date.parse(change.occurred_at)>=cutoff)news.push({kind:'terms',title:'Изменились условия магазина',source:target.source_id,at:change.occurred_at,target});}
  news.sort((a,b)=>Date.parse(b.at)-Date.parse(a.at));
  const successes=targets.filter(t=>t.last_success).map(t=>t.last_success).sort();
  return {prices,news,errors:targets.filter(t=>t.last_attempt?.state==='error').length,fresh:targets.filter(t=>t.last_success&&!competitorStale(t)&&t.last_attempt?.state!=='error').length,total:targets.length,last:successes.at(-1)||null};
}
function dashboardTasks(){
  const customers=dashboardFresh()?(dashboardState.data?.actions||[]).map(c=>({...c,kind:'customer'})):[];
  const market=dashboardMarket().prices.filter(p=>Math.abs(p.percent)>=5).slice(0,5).map(p=>({...p,kind:'market',type:p.percent<0?'risk':'opportunity',priority:Math.min(85,50+Math.abs(p.percent))}));
  return [...customers,...market].sort((a,b)=>b.priority-a.priority);
}
function dashboardTaskHTML(task){
  const customer=task.kind==='customer',title=customer?(task.type==='risk'?'Вернуть постоянного покупателя':'Напомнить о повторном заказе'):(task.percent<0?'Конкурент снизил цену':'Конкурент поднял цену');
  const who=customer?(state.privacy?'Покупатель '+task.customer_id:task.name):task.label+' · 500 г';
  const detail=customer?task.days_since+' дн. без заказа · обычно '+task.interval_days+' дн.':competitorSource(task.target.source_id).name+' · '+(task.percent>0?'+':'')+num(task.percent,1)+'%';
  return `<button class="dash-task ${task.type}" data-dash-action="task" data-id="${esc(task.id)}" aria-label="${esc(title+' · '+who+' · '+detail)}"><span class="dash-signal" aria-hidden="true">${task.type==='risk'?'!':'↗'}</span><span><strong title="${esc(who)}">${esc(customer?(task.type==='risk'?'Вернуть · ':'Напомнить · ')+who:who)}</strong><small>${esc(detail)}</small></span><span aria-hidden="true">→</span></button>`;
}
function dashboardBuckets(){
  const data=dashboardState.data;if(dashboardState.group==='days')return data.days.map((d,i)=>({...d,label:String(i+1),previous:data.previous_days[i]?.amount??null,end:d.date}));
  const groups=[];
  data.days.forEach((day,index)=>{const weekday=new Date(day.date+'T12:00:00').getDay();if(!groups.length||weekday===1)groups.push({date:day.date,end:day.date,label:'',amount:0,previous:0,count:0,order_ids:[],state:'closed',hasPrevious:false});const group=groups.at(-1);group.end=day.date;group.label=String(Number(group.date.slice(8)))+'–'+Number(day.date.slice(8));
    if(day.state!=='future'){group.amount+=day.amount;group.count+=day.count;group.order_ids.push(...day.order_ids);}if(day.state==='today')group.state='today';else if(day.state==='future'&&group.state==='closed'&&!group.order_ids.length&&group.date>data.today)group.state='future';
    if(day.state==='closed'&&data.previous_days[index]){group.previous+=data.previous_days[index].amount;group.hasPrevious=true;}
  });return groups.map(g=>({...g,previous:g.hasPrevious?g.previous:null}));
}
function dashboardChart(){
  const mobile=innerWidth<=720,font=mobile?Math.ceil(12*640/Math.max(260,innerWidth-36)):14;
  const buckets=dashboardBuckets(),W=640,H=143,X=mobile?80:50,Y=10,max=Math.max(1,...buckets.flatMap(b=>[b.amount||0,b.state==='closed'?b.previous||0:0])),step=(W-X-10)/buckets.length,bw=Math.min(29,step*.54);let svg='<defs><pattern id="dash-today-pattern" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="5" height="5" fill="#e3ede8"/><path d="M0 0V5" stroke="#205b46" stroke-width="2"/></pattern></defs>';
  for(let i=0;i<=3;i++){const y=Y+H-H*i/3;svg+=`<line x1="${X}" y1="${y}" x2="630" y2="${y}" stroke="#dce3df"/><text x="${X-8}" y="${y+font*.35}" fill="#4b5a53" font-size="${font}" text-anchor="end">${mobile&&max>=1000?num(max*i/3/(max>=1000000?1000000:1000),1)+(max>=1000000?' м':' т'):num(max*i/3,max<10?1:0)}</text>`;}
  buckets.forEach((b,i)=>{const x=X+step*(i+.5),height=b.amount/max*H,prior=b.state==='closed'?b.previous:null;if(prior!==null)svg+=`<rect x="${x-bw*.7}" y="${Y+H-prior/max*H}" width="${bw}" height="${prior/max*H}" fill="#b6c2bd" rx="2"/>`;if(b.state!=='future')svg+=`<rect x="${x-bw*.3}" y="${Y+H-height}" width="${bw}" height="${Math.max(0,height)}" rx="2" fill="${b.state==='today'?'url(#dash-today-pattern)':'#205b46'}"/>`;
    if(b.state!=='future')svg+=`<g class="dash-chart-hit" role="button" tabindex="0" data-dash-action="bucket" data-index="${i}" aria-label="${esc(b.label+': '+dashboardMoney(b.amount)+', '+b.count+' заказов'+(b.state==='today'?' · неполный период':''))}"><rect x="${x-step/2}" y="${Y}" width="${step}" height="${H}" fill="transparent"/><title>${esc(b.label+': '+dashboardMoney(b.amount)+' · '+b.count+' заказов'+(b.state==='today'?' · неполный период':''))}</title></g>`;
    if(dashboardState.group==='weeks'||i%5===0||i===buckets.length-1)svg+=`<text x="${x}" y="${Y+H+19}" fill="#4b5a53" font-size="${font}" text-anchor="middle">${esc(b.label)}</text>`;
  });return `<svg viewBox="0 0 640 180" role="group" aria-label="Суммы заказов по ${dashboardState.group==='days'?'дням':'неделям'}. Нажмите столбец для состава.">${svg}</svg>`;
}
function dashboardMarketHTML(market){
  if(dashboardState.marketError)return '<p class="dash-unavailable">Не удалось загрузить мониторинг. <button class="dash-link" data-dash-action="reload">Повторить</button></p>';
  const prices=dashboardState.marketTab==='prices',items=prices?market.prices:market.news;
  return items.slice(0,2).map((item,i)=>`<button class="dash-market-row" data-dash-action="${prices?'market':'news'}" data-index="${i}"><span><strong>${esc(prices?item.label:item.title)}</strong><small>${esc(competitorSource(prices?item.target.source_id:item.source).name)} · ${prices?'500 г':item.kind==='new'?'впервые замечено':'проверить условия'}</small></span>${prices?`<b>${item.percent>0?'+':''}${num(item.percent,1)}%</b>`:'<span aria-hidden="true">→</span>'}</button>`).join('')||`<p class="dash-unavailable">${market.fresh?(prices?'Подтверждённых изменений цен за 7 дней нет.':'Новых замеченных позиций и изменений условий за 7 дней нет.'):'Нет свежих результатов наблюдений.'}</p>`;
}
function dashboardOverviewView(){
  const d=dashboardState.data;if(!d)return `<div class="dash-loading">${dashboardState.error?esc(dashboardState.error)+' <button class="button" data-dash-action="reload">Повторить</button>':'Собираем сводку месяца…'}</div>`;
  const market=dashboardMarket(),tasks=dashboardTasks().filter(t=>dashboardState.filter==='all'||t.type===dashboardState.filter),fresh=dashboardFresh(),metrics=d.metrics;
  const sourceNote=dashboardState.error?'Ошибка чтения. Показана сохранённая сводка.':!fresh?'Данные продаж требуют обновления. Рекомендации по покупателям приостановлены.':d.sync?.configured?'Продажи · '+dashboardDate(d.sync.last_success):'Ручная копия · '+dashboardDate(d.imported_at);
  const tile=(label,value,note,action='method')=>`<button class="dash-kpi" data-dash-action="${action}"><span>${label}</span><strong>${value}</strong><small>${note}</small></button>`;
  return `<div class="dash-board"><div class="dash-period"><span>${esc(dashboardMonth(d.month_start))} · по ${fmtDate(d.today)}</span><span class="dash-source ${fresh?'':'needs-update'}">${esc(sourceNote)}</span><button class="dash-link" data-dash-action="reload" ${dashboardState.loading?'disabled':''}>${dashboardState.loading?'Обновляем…':'↻ Обновить'}</button></div>
  <section class="dash-kpis" aria-label="Показатели месяца">${tile('Сумма заказов',dashboardMoney(metrics.amount),esc(dashboardDelta('amount')))}${tile('Заказы',num(metrics.count),'Сегодня '+num(d.days.find(day=>day.state==='today')?.count||0)+' · день ещё идёт')}${tile('Средний заказ',dashboardMoney(metrics.average),'С учётом скидок и доставки')}${tile('Повторные покупатели',metrics.repeat_share===null?'—':num(metrics.repeat_share,1)+'%',num(metrics.repeat_customers)+' из '+num(metrics.customers)+' покупали до начала месяца')}</section>
  <div class="dash-middle"><section class="dash-panel dash-sales"><div class="dash-panel-head"><div><h2>Продажи месяца</h2><p>Суммы оформленных заказов · ${esc(d.currency)}</p></div><div class="dash-switch" aria-label="Группировка продаж">${['days','weeks'].map(g=>`<button data-dash-action="group" data-value="${g}" aria-pressed="${dashboardState.group===g}">${g==='days'?'Дни':'Недели'}</button>`).join('')}</div></div><div class="dash-chart">${dashboardChart()}</div><div class="dash-legend"><span><i></i>Этот месяц</span><span><i class="previous"></i>Предыдущий</span><span><i class="today"></i>Неполный период</span></div><div class="dash-sales-foot"><span>Нажмите столбец для заказов</span><button class="dash-link" data-dash-action="reports">Другие периоды →</button></div></section>
  <section class="dash-panel dash-priorities"><div class="dash-panel-head"><div><h2>На что обратить внимание</h2><p>Три первых действия по приоритету</p></div><button class="dash-link" data-dash-action="queue">Все (${num(dashboardTasks().length)}) →</button></div><div class="dash-switch dash-filters" aria-label="Тип действия">${[['all','Все'],['risk','Риски'],['opportunity','Возможности']].map(([key,label])=>`<button data-dash-action="filter" data-value="${key}" aria-pressed="${dashboardState.filter===key}">${label}</button>`).join('')}</div><div class="dash-tasks">${tasks.slice(0,3).map(dashboardTaskHTML).join('')||`<p class="dash-unavailable">${fresh?'В этом списке нет подтверждённых действий.':'Нужна свежая копия продаж для рекомендаций.'}</p>`}</div></section></div>
  <div class="dash-bottom"><section class="dash-panel"><div class="dash-panel-head"><h2>Запасы и закупка</h2><span class="dash-state">Не подключено</span></div><div class="dash-placeholder"><strong>Остатки ещё не загружены</strong><p>Покажем, что закончилось и что пора заказать.</p><button class="dash-link" data-dash-action="inventory">Что нужно для расчёта →</button></div></section>
  <section class="dash-panel dash-market"><div class="dash-panel-head"><h2>У конкурентов</h2><button class="dash-link" data-dash-action="competitors">Сравнить →</button></div><div class="dash-switch" aria-label="События конкурентов">${[['prices','Цены'],['news','Новинки и условия']].map(([key,label])=>`<button data-dash-action="market-tab" data-value="${key}" aria-pressed="${dashboardState.marketTab===key}">${label}</button>`).join('')}</div><div class="dash-market-items">${dashboardMarketHTML(market)}</div><button class="dash-source-link" data-dash-action="market-health">${dashboardState.marketError?'Источник недоступен':'Свежие страницы: '+market.fresh+'/'+market.total+(market.errors?' · ошибки: '+market.errors:'')} →</button></section>
  <section class="dash-panel"><div class="dash-panel-head"><h2>Обращения с сайта</h2><span class="dash-state">Не подключено</span></div><div class="dash-inbox"><span>Отзывы <b>—</b></span><span>Сообщения <b>—</b></span><span>Обратные звонки <b>—</b></span></div><button class="dash-link dash-inbox-link" data-dash-action="inbox">Как подключим единую очередь →</button></section></div><div class="dash-note">Суммы заказов не подтверждают оплату. <button class="dash-link" data-dash-action="costs">Прибыль — после подключения себестоимости →</button></div></div>`;
}
function dashboardInfo(kind){
  const d=dashboardState.data;
  const content={
    inventory:['Запасы и закупка','Остатки пока не подключены. Текущий каталог конкурентов содержит наличие выбранных товаров, но не полный склад.','Сначала проверим, в каких единицах ведётся запас и общий ли он для всех фасовок. Для списка закупок понадобятся резервы, ожидаемые поставки, сроки поставщика и минимальная партия. Прочерк означает отсутствие данных, а не пустой склад.'],
    inbox:['Обращения с сайта','Отзывы, сообщения и обратные звонки пока не загружены.','Нужно определить таблицы и признаки обработки установленных модулей в тестовом магазине. Затем покажем число ожидающих обращений, срок ожидания и переход к обработке. Отзывы не будут публиковаться автоматически.'],
    costs:['Прибыль и себестоимость','Прибыль появится после подключения истории закупочных цен и расходов на фасовку.','Текущая закупочная цена не должна переписывать прибыль прошлых продаж. Неизвестная стоимость не считается нулевой. Добавим долю продаж, для которых себестоимость известна; валовая прибыль заменит средний заказ на главном экране.'],
    method:['Как читать сводку месяца','Сумма включает учитываемые статусы заказов, скидки и доставку. Это не подтверждённая выручка и не прибыль.','Повторный покупатель — человек с учитываемой покупкой до первого числа месяца. Купивший впервые в этом месяце не становится повторным в этой метрике даже после второго заказа. Объединение покупателей и правила статусов берутся из действующей аналитики.'],
  };
  const [title,intro,body]=content[kind]||content.method;openDrawer('ГЛАВНЫЙ ЭКРАН');state.customer=null;
  $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(title)}</h2><p>${esc(intro)}</p><p>${esc(body)}</p>${kind==='method'?`<p>Динамика сравнивает ${d.comparison.days} полностью завершённых дней: ${fmtDate(d.comparison.current_start)} — ${fmtDate(d.comparison.current_end)} и ${fmtDate(d.comparison.previous_start)} — ${fmtDate(d.comparison.previous_end)}. На первом дне месяца сравнения ещё нет. Если предыдущий месяц короче, берём доступное число полных дней.</p><p>Серые столбцы показаны только для закрытых периодов. Недели начинаются с понедельника и обрезаются границами месяца; предыдущий месяц сопоставляется по тем же номерам дней. Сегодняшняя неделя ещё не завершена.</p><button class="button" data-dash-action="settings">Настройки учитываемых заказов →</button>`:''}</div>`;
}
function dashboardOpenMarket(item){
  if(!item)return;openDrawer('ИЗМЕНЕНИЕ ЦЕНЫ · 500 Г');state.customer=null;
  $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(item.label)}</h2><p>${esc(competitorSource(item.target.source_id).name)} · ${esc(item.pack)}</p><div class="dash-price-change"><span>${competitorMoney(item.oldPrice,'UAH')}</span> → <strong>${competitorMoney(item.newPrice,'UAH')}</strong><b>${item.percent>0?'+':''}${num(item.percent,1)}%</b></div><p>Изменение замечено ${esc(dashboardDate(item.at))}. Текущая цена и все предложения доступны по источнику.</p><p>${item.percent<0?'Проверьте нашу цену и спрос. Снижение цены конкурента само по себе не требует снижения нашей.':'Проверьте нашу позицию и себестоимость: возможно, скидка больше не нужна.'}</p><p>${competitorLink(item.target.url,'Открыть товар у конкурента')}</p><button class="button primary" data-dash-action="competitors">Открыть таблицу сравнения →</button></div>`;
}
function dashboardOpenQueue(){openDrawer('РИСКИ И ВОЗМОЖНОСТИ');state.customer=null;const tasks=dashboardTasks().filter(t=>dashboardState.filter==='all'||t.type===dashboardState.filter);$('#drawer-content').innerHTML=`<div class="dash-detail"><h2>Действия по приоритету</h2><p>Фильтр: ${esc({all:'все',risk:'риски',opportunity:'возможности'}[dashboardState.filter])}. Покупатели не дублируются; запреты и паузы CRM учтены. Свежесть продаж: ${esc(dashboardDate(dashboardState.data.sync?.last_success||dashboardState.data.imported_at))}.</p><div class="dash-queue">${tasks.slice(0,50).map(dashboardTaskHTML).join('')||'<p>В этом списке пока нет действий.</p>'}</div>${tasks.length>50?'<p>На этой панели первые 50 действий.</p>':''}<button class="button" data-dash-action="crm-queue">Открыть полную очередь контактов →</button></div>`;}
async function dashboardHandle(button){
  const action=button.dataset.dashAction;
  if(action==='reload'){await dashboardLoad();return;}
  if(['group','filter','market-tab'].includes(action)){dashboardState[{group:'group',filter:'filter','market-tab':'marketTab'}[action]]=button.dataset.value;render();document.querySelector(`[data-dash-action="${action}"][data-value="${button.dataset.value}"]`)?.focus({preventScroll:true});return;}
  if(['method','inventory','inbox','costs'].includes(action)){dashboardInfo(action);return;}
  if(action==='queue'){dashboardOpenQueue();return;}
  if(action==='market-health'){
    openDrawer('СОСТОЯНИЕ МОНИТОРИНГА');state.customer=null;const data=competitorState.data;
    const rows=(data?.sources||[]).filter(source=>source.id!=='lavka').map(source=>{const targets=data.targets.filter(t=>t.enabled&&t.source_id===source.id),fresh=targets.filter(t=>t.last_success&&!competitorStale(t)&&t.last_attempt?.state!=='error').length,errors=targets.filter(t=>t.last_attempt?.state==='error').length,last=targets.map(t=>t.last_success).filter(Boolean).sort().at(-1);return `<tr><td>${esc(source.name)}</td><td>${fresh} / ${targets.length}</td><td>${errors}</td><td>${esc(dashboardDate(last))}</td></tr>`;}).join('');
    $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>Свежесть наблюдений</h2><p>Свежая страница успешно проверена за 48 часов и не имеет ошибки последней попытки. Считаем только выбранные страницы конкурентов; это не весь ассортимент рынка.</p>${dashboardState.marketError?'<p>Последнее чтение мониторинга не удалось. Ниже сохранённая информация.</p>':''}<div class="table-scroll"><table><thead><tr><th>Магазин</th><th>Свежие / всего</th><th>Ошибки</th><th>Последний успех</th></tr></thead><tbody>${rows}</tbody></table></div><p>Новинки и изменения цен на главной — за 7 дней. Неизвестные и старые цены не создают рекомендаций.</p><button class="button" data-dash-action="errors">Открыть список ошибок →</button></div>`;return;
  }
  if(action==='task'){const task=dashboardTasks().find(t=>t.id===button.dataset.id);if(!task)return;if(task.kind==='market')dashboardOpenMarket(task);else await openCRM(task.customer_id);return;}
  if(action==='market'){dashboardOpenMarket(dashboardMarket().prices[Number(button.dataset.index)]);return;}
  if(action==='news'){const item=dashboardMarket().news[Number(button.dataset.index)];if(!item)return;openDrawer('СОБЫТИЕ КОНКУРЕНТА');state.customer=null;$('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(item.title)}</h2><p>${esc(competitorSource(item.source).name)} · ${esc(dashboardDate(item.at))}</p><p>${item.kind==='new'?'Впервые замечено на наблюдаемой странице. Это не дата появления товара на рынке.':'Изменился текст условий. Изменение ещё не означает новую акцию; проверьте пороги, сроки и ограничения в общей таблице.'}</p>${competitorLink(item.url||item.target.url,'Открыть источник')}<p><button class="button" data-dash-action="terms">Сравнить условия магазинов →</button></p></div>`;return;}
  if(action==='bucket'){const bucket=dashboardBuckets()[Number(button.dataset.index)];if(!bucket)return;openDrawer('ЗАКАЗЫ · '+bucket.label);state.customer=null;const sequence=competitorState.historySequence;const orders=await Promise.all(bucket.order_ids.map(id=>api('/api/orders/'+id)));if(!$('#drawer').open||sequence!==competitorState.historySequence)return;$('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${fmtDate(bucket.date)}${bucket.end!==bucket.date?' — '+fmtDate(bucket.end):''}</h2><p>${num(bucket.count)} заказов · ${dashboardMoney(bucket.amount)}${bucket.state==='today'?' · неполный период':''}</p></div>`+(orders.map(o=>orderHTML(o)).join('')||'<p class="dash-detail">Заказов за этот день нет.</p>');return;}
  if(['reports','settings','crm-queue','competitors','terms','errors'].includes(action)){
    $('#drawer').close();state.customer=null;
    if(action==='reports'){navigate('overview-report');return;}if(action==='settings'){navigate('settings');return;}if(action==='crm-queue'){$('#as-of').value=dashboardState.data.today;state.crmScope='todo';state.actionMin=state.meta.settings.followup_min_orders;navigate('actions');return;}
    competitorState.tab=action==='terms'?'terms':action==='errors'?'errors':'products';competitorState.source='all';competitorState.search='';competitorState.archived=false;competitorState.matrixPack='500';navigate('competitors');
  }
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-dash-action]');if(button)dashboardHandle(button).catch(error=>toast(error.message));});
document.addEventListener('keydown',event=>{const button=event.target.closest('.dash-chart-hit');if(button&&['Enter',' '].includes(event.key)){event.preventDefault();dashboardHandle(button).catch(error=>toast(error.message));}});

let dashboardResize;
window.addEventListener('resize',()=>{clearTimeout(dashboardResize);dashboardResize=setTimeout(()=>{if(state.view==='overview'&&dashboardState.data&&document.querySelector('.dash-chart'))document.querySelector('.dash-chart').innerHTML=dashboardChart();},100);});
