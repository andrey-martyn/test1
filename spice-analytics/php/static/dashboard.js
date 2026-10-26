/* Business overview. Reads saved local reports; actions open existing workflows. */
const dashboardState={data:null,loading:false,error:'',marketError:'',group:'days',filter:'all',marketTab:'all',sequence:0};
const dashboardMoney=value=>value===null||value===undefined?'—':num(value,0)+' '+esc(dashboardState.data?.currency||'UAH');
const dashboardMonth=date=>new Date(date+'T12:00:00').toLocaleDateString('ru-RU',{month:'long',year:'numeric'});
const dashboardDate=value=>value?new Date(value).toLocaleString('ru-RU',{timeZone:'Europe/Kiev',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}):'ещё не было';
function dashboardFresh(){const d=dashboardState.data;if(!d||dashboardState.error)return false;return d.sync?.configured?d.sync.state==='ok'&&!d.sync.stale:Date.parse(d.server_now)-Date.parse(d.imported_at||'')<86400000;}
async function dashboardLoad(){
  if(dashboardState.loading)return;if(state.view==='overview')state.report=null;dashboardState.loading=true;dashboardState.error='';const sequence=++dashboardState.sequence;
  if(state.view==='overview')render();
  const currency=$('#currency').value||'UAH';
  const results=await Promise.allSettled([api('/api/dashboard?currency='+encodeURIComponent(currency)),api('/api/competitors')]);
  if(sequence!==dashboardState.sequence)return;
  const [sales,market]=results;
  if(sales.status==='fulfilled'){dashboardState.data=sales.value;}else dashboardState.error=sales.reason.message;
  if(market.status==='fulfilled'){competitorState.data=market.value;competitorState.sandbox=market.value.sandbox_pricing||null;dashboardState.marketError='';}else dashboardState.marketError=market.reason.message;
  dashboardState.loading=false;if(state.view==='overview')render();
}
function dashboardDelta(key){const c=dashboardState.data.comparison,a=c.current[key],b=c.previous[key];if(!c.days||a===null||b===null)return 'Сравнение пока недоступно';if(b===0)return a===0?'Без изменений за полные дни':'В прошлом периоде было 0';const delta=(a/b-1)*100;return (delta>0?'+':'')+num(delta,1)+'% к '+c.previous_start.slice(0,4)+' г. · '+c.days+' дн.';}
function dashboardMarket(){
  const data=competitorState.data;if(!data||dashboardState.marketError)return {prices:[],news:[],events:[],positions:[],errors:0,fresh:0,total:0,last:null};
  const targets=data.targets.filter(t=>t.enabled&&!competitorOwn(t)),byId=new Map(targets.map(t=>[t.id,t])),now=Date.parse(data.server_now),cutoff=now-7*86400000;
  const prices=[],news=[],seen=new Set(),identity=o=>JSON.stringify([o.key,o.variant_id||'',o.source_variant_id||'',o.grams,competitorPackaging(o),o.currency,o.unit,o.min_quantity,o.condition||'']);
  const recent=at=>Number.isFinite(Date.parse(at))&&Date.parse(at)>=cutoff&&Date.parse(at)<=now+300000;
  const current=target=>target&&!competitorStale(target)&&target.last_attempt?.state!=='error';
  for(const change of data.changes||[]){
    const target=byId.get(change.target_id);if(!current(target)||!recent(change.occurred_at))continue;
    if(change.kind==='terms'){
      const facts=change.commercial_changes||[],promotions=facts.some(f=>['promotions','first_order_discount','regular_discounts'].includes(f.row));
      const summary=facts.slice(0,2).map(f=>f.label+': '+(f.before||'не было указано')+' → '+(f.after||'больше не указано')).join(' · ');
      news.push({id:'terms-'+change.id,kind:promotions?'promotion':'terms',title:promotions?'Изменились акции или скидки':'Изменились условия магазина',summary:summary||'Изменился текст страницы — проверьте условия',source:target.source_id,at:change.occurred_at,target,details:facts,beforeText:change.before?.text||'',afterText:change.after?.text||'',priority:promotions?90:85});continue;
    }
    if(!target.match_confirmed||!['price','availability'].includes(change.kind))continue;
    const before=change.before?.offers||[],after=change.after?.offers||[],changes=[];
    for(const next of competitorPackagingFilter(target,after.filter(o=>o.unit==='pack'&&o.grams>0&&o.currency==='UAH')).offers){
      const previous=before.find(o=>identity(o)===identity(next)),live=target.observation?.offers?.find(o=>identity(o)===identity(next));
      if(!previous||!live)continue;
      const oldPrice=Number(previous.price),newPrice=Number(next.price),percent=(newPrice/oldPrice-1)*100;
      if(change.kind==='price'&&competitorEligible(target,live)&&competitorEligible(target,previous)&&competitorEligible(target,next)&&Number.isFinite(percent)&&Math.abs(newPrice-oldPrice)>=.005){
        changes.push({id:'market-'+change.id+'-'+next.key,kind:'price',target,oldPrice,newPrice,percent,at:change.occurred_at,label:competitorGroupName(target.match_group),title:competitorGroupName(target.match_group),source:target.source_id,pack:competitorPackagingNote(target,next),grams:next.grams,priority:Math.abs(percent)>=5?95:60});
      }
      if(change.kind==='availability'&&previous.availability!==next.availability&&['in_stock','out_of_stock','preorder'].includes(next.availability)){
        const key='stock:'+target.id+':'+identity(next);if(seen.has(key))continue;seen.add(key);
        news.push({id:'stock-'+change.id+'-'+next.key,kind:'availability',title:competitorGroupName(target.match_group),summary:(competitorAvailability[previous.availability]||'Наличие неизвестно')+' → '+competitorAvailability[next.availability]+' · '+num(next.grams)+' г',source:target.source_id,at:change.occurred_at,target,priority:75});
      }
    }
    // One main price signal per product; prefer the working standard of 500 g.
    changes.sort((a,b)=>(b.grams===500)-(a.grams===500)||Math.abs(b.percent)-Math.abs(a.percent));
    const item=changes[0];if(item&&!seen.has('price:'+target.id)){seen.add('price:'+target.id);prices.push(item);}
  }
  for(const d of data.discoveries||[]){const target=byId.get(d.target_id);if(current(target)&&recent(d.first_seen)&&!d.baseline)news.push({id:'new-'+d.target_id+'-'+d.url,kind:'new',title:d.title||'Новая замеченная позиция',summary:'Впервые замечено в наблюдаемом каталоге',source:d.source_id,at:d.first_seen,target,url:d.url,priority:65});}
  prices.sort((a,b)=>Math.abs(b.percent)-Math.abs(a.percent));news.sort((a,b)=>b.priority-a.priority||Date.parse(b.at)-Date.parse(a.at));
  const events=[...prices,...news].sort((a,b)=>b.priority-a.priority||Date.parse(b.at)-Date.parse(a.at));
  const positions=competitorMatrixRows().filter(r=>r.grams===500&&r.comparable&&!r.equal).sort((a,b)=>Math.abs(b.priceGapPercent)-Math.abs(a.priceGapPercent));
  const successes=targets.map(t=>t.last_success).filter(Boolean).sort();
  return {prices,news,events,positions,errors:targets.filter(t=>t.last_attempt?.state==='error').length,fresh:targets.filter(current).length,total:targets.length,last:successes.at(-1)||null};
}
function dashboardTasks(){return dashboardFresh()?(dashboardState.data?.actions||[]).map(c=>({...c,kind:'customer'})):[];}
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
  const mobile=innerWidth<=720,contentWidth=innerWidth-(mobile?24:290),estimate=innerWidth>=1160?(contentWidth-48)/3:innerWidth>720?(contentWidth-20)/2:contentWidth-32;
  const width=document.querySelector('.dash-chart')?.clientWidth||estimate,font=Math.ceil(14*640/Math.max(200,width));
  const buckets=dashboardBuckets(),W=640,H=200,X=mobile?115:85,Y=15,max=Math.max(1,...buckets.flatMap(b=>[b.amount||0,b.state==='closed'?b.previous||0:0])),step=(W-X-10)/buckets.length,bw=Math.min(29,step*.54);let svg='<defs><pattern id="dash-today-pattern" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="5" height="5" fill="#e3ede8"/><path d="M0 0V5" stroke="#205b46" stroke-width="2"/></pattern></defs>';
  for(let i=0;i<=3;i++){const y=Y+H-H*i/3;svg+=`<line x1="${X}" y1="${y}" x2="630" y2="${y}" stroke="#dce3df"/><text x="${X-8}" y="${y+font*.35}" fill="#4b5a53" font-size="${font}" text-anchor="end">${mobile&&max>=1000?num(max*i/3/(max>=1000000?1000000:1000),1)+(max>=1000000?' м':' т'):num(max*i/3,max<10?1:0)}</text>`;}
  buckets.forEach((b,i)=>{const x=X+step*(i+.5),height=b.amount/max*H,prior=b.state==='closed'?b.previous:null;if(prior!==null)svg+=`<rect x="${x-bw*.7}" y="${Y+H-prior/max*H}" width="${bw}" height="${prior/max*H}" fill="#b6c2bd" rx="2"/>`;if(b.state!=='future')svg+=`<rect x="${x-bw*.3}" y="${Y+H-height}" width="${bw}" height="${Math.max(0,height)}" rx="2" fill="${b.state==='today'?'url(#dash-today-pattern)':'#205b46'}"/>`;
    if(b.state!=='future')svg+=`<g class="dash-chart-hit" role="button" tabindex="0" data-dash-action="bucket" data-index="${i}" aria-label="${esc(b.label+': '+dashboardMoney(b.amount)+', '+b.count+' заказов'+(b.state==='today'?' · неполный период':''))}"><rect x="${x-step/2}" y="${Y}" width="${step}" height="${H}" fill="transparent"/><title>${esc(b.label+': '+dashboardMoney(b.amount)+' · '+b.count+' заказов'+(b.state==='today'?' · неполный период':''))}</title></g>`;
    if(dashboardState.group==='weeks'||i%5===0||i===buckets.length-1)svg+=`<text x="${x}" y="${Y+H+font+5}" fill="#4b5a53" font-size="${font}" text-anchor="middle">${esc(b.label)}</text>`;
  });return `<svg viewBox="0 0 640 265" role="group" aria-label="Суммы заказов по ${dashboardState.group==='days'?'дням':'неделям'}. Нажмите столбец для состава.">${svg}</svg>`;
}
function dashboardMarketEvents(market){
  const tab=dashboardState.marketTab;
  return market.events.filter(item=>tab==='all'||tab==='prices'&&item.kind==='price'||tab==='news'&&item.kind==='new'||tab==='terms'&&['terms','promotion','availability'].includes(item.kind));
}
function dashboardMarketHTML(market,limit=3){
  if(dashboardState.marketError)return '<p class="dash-unavailable">Не удалось загрузить данные конкурентов. <button class="dash-link" data-dash-action="reload">Повторить</button></p>';
  const items=dashboardMarketEvents(market);
  if(items.length)return items.slice(0,limit).map(item=>{
    const price=item.kind==='price',tag={price:'Цена',new:'Новый товар',terms:'Доставка и условия',promotion:'Акции и скидки',availability:'Наличие'}[item.kind];
    const detail=price?competitorMoney(item.oldPrice)+' → '+competitorMoney(item.newPrice)+' грн · '+num(item.grams)+' г':item.summary;
    return `<button class="dash-market-row ${price&&item.percent<0?'price-down':''}" data-dash-action="event" data-id="${esc(item.id)}"><span><span class="dash-event-kind">${tag}</span><strong>${esc(item.title)}</strong><span class="dash-event-summary">${esc(detail)}</span><small>${esc(competitorSource(item.source).name)} · ${esc(dashboardDate(item.at))}</small></span>${price?'<b>'+(item.percent>0?'+':'')+num(item.percent)+'%</b>':'<span aria-hidden="true">→</span>'}</button>`;
  }).join('');
  const positions=dashboardState.marketTab==='all'?market.positions.slice(0,2):[];
  return `<p class="dash-unavailable">${market.fresh?'Новых событий за 7 дней нет.':'Пока нет свежих проверок конкурентов.'}${positions.length?' Сейчас в сравнении:':''}</p>`+positions.map(row=>`<button class="dash-market-row" data-dash-action="position" data-row="${esc(row.key)}"><span><strong>${esc(row.label)} · 500 г</strong><span class="dash-event-summary">Наша ${row.above?'выше':'ниже'} на ${num(Math.abs(row.priceGapPercent))}%</span><small>У нас ${competitorMoney(row.cells.lavka.price)} · минимум конкурентов ${competitorMoney(row.competitorMinimum)} грн</small></span><span aria-hidden="true">→</span></button>`).join('');
}
function dashboardMarketPanel(market){return `<section class="dash-panel dash-market"><div class="dash-panel-head"><div><h2>Что у конкурентов</h2><p>Изменения за 7 дней</p></div><button class="dash-link" data-dash-action="market-events">Все (${num(market.events.length)}) →</button></div><div class="dash-switch" aria-label="События конкурентов">${[['all','Все'],['prices','Цены'],['terms','Условия'],['news','Новинки']].map(([key,label])=>`<button data-dash-action="market-tab" data-value="${key}" aria-pressed="${dashboardState.marketTab===key}">${label}</button>`).join('')}</div><div class="dash-market-items">${dashboardMarketHTML(market)}</div><button class="dash-source-link" data-dash-action="market-health">Проверено ${num(market.fresh)} из ${num(market.total)}${market.errors?' · ошибок '+num(market.errors):''} →</button></section>`;}
function dashboardComparisonLabel(){const c=dashboardState.data.comparison;return c.days?'1–'+c.days+' '+new Date(c.current_start+'T12:00:00').toLocaleDateString('ru-RU',{month:'short'})+' · '+c.current_start.slice(0,4)+' ↔ '+c.previous_start.slice(0,4):'Полных дней для сравнения пока нет';}
function dashboardWorkflows(){
  const d=dashboardState.data,market=competitorState.data,available=market&&!dashboardState.marketError,summary=available?competitorReadinessSummary('500'):null;
  const primary=summary?[
    {key:'price-differences',label:'Различия цен',above:summary.above,below:summary.below,disabled:!summary.above&&!summary.below,description:'Наша цена выше или ниже минимальной пригодной цены конкурента за 500 г. Откроются только различающиеся цены, начиная с наибольшего отклонения.'},
    {key:'confirm-products',label:'Подтвердить товары',count:summary.f.unconfirmed+summary.f.unlinked,disabled:!summary.f.unconfirmed&&!summary.f.unlinked,description:'Загруженные карточки, для которых нужно проверить, что это тот же товар.'},
    {key:'price-errors',label:'Ошибки чтения',count:summary.errors,disabled:!summary.errors,description:'Страницы с неудачной проверкой. В списке есть причина, время и действия для каждой карточки.'}
  ]:[{key:'market-reload',label:'Обновить данные конкурентов',description:'Не удалось получить свежую сводку. Повторите чтение сохранённых результатов.'}],extra=[];
  const m=available?market.monitoring:null;
  if(m&&(m.runner_state==='overdue'||m.runner_state==='never'&&m.pending>0))extra.push({key:'price-runner',label:m.runner_state==='never'?'Настроить проверку цен':'Проверить загрузку цен',description:m.runner_state==='never'?'Фоновый обработчик ещё не запускался. Откроется настройка расписания.':'Проверки давно не запускались. Откроется состояние обработчика и расписание.'});
  if(dashboardFresh())primary.push({key:'contact-matches',label:'Сверить клиентов',count:d.workflows?.duplicate_pairs||0,disabled:!d.workflows?.duplicate_pairs,description:'Возможные совпадения покупателей за всю историю в этой валюте. Это пары для проверки, а не готовое решение об объединении.'});
  else primary.push({key:'sales-sync',label:'Проверить обновление продаж',description:'Продажи требуют обновления. Здесь можно посмотреть состояние синхронизации и инструкции.'});
  if(summary){
    if(summary.above)extra.push({key:'prices-above',label:'Наша цена выше',count:summary.above,description:'Цены 500 г выше минимума конкурентов, начиная с наибольшего отклонения.'});
    if(summary.below)extra.push({key:'prices-below',label:'Наша цена ниже',count:summary.below,description:'Цены 500 г ниже минимума конкурентов, начиная с наибольшего отклонения. Проверьте маржу и условия предложения.'});
    if(!summary.ready&&!summary.f.unconfirmed&&!summary.f.unlinked)extra.push(summary.actions.find(item=>item.key==='find-products'));
    for(const [kind,count] of Object.entries(summary.f.blocked_500))if(competitorTaskLabels[kind])extra.push({key:'comparison-'+kind,label:competitorTaskLabels[kind],count,taskKind:kind,description:{own:'У нашего товара нет пригодной цены 500 г. Откроются конкретные товары и ссылки в магазин.',stock:'Наличие мешает сравнению. Откроются предложения, которые нужно проверить.',pack:'Не найдена цена точной фасовки 500 г. Можно посмотреть другой вес или подобрать ссылку.',packaging:'Нужно выбрать подходящую упаковку для сравнения.',conditional:'Цена зависит от условий покупки. Проверьте ограничения в карточке.'}[kind]||'Откроются карточки, которым нужна проверка.'});
  }
  return {primary,extra,all:[...primary,...extra].filter(item=>!item.disabled),queued:summary?.queued||0};
}
function dashboardWorkflowButton(item,classes=''){
  const differences=item.key==='price-differences',counts=differences?`<span class="dash-price-count above">↑ ${num(item.above)} <span>выше</span></span><span class="dash-price-divider" aria-hidden="true">/</span><span class="dash-price-count below">↓ ${num(item.below)} <span>ниже</span></span>`:item.count!==undefined?'<span class="dash-workflow-count">'+num(item.count)+'</span>':'';
  return `<button type="button" class="button ${classes}" data-dash-action="workflow" data-key="${esc(item.key)}" ${item.disabled?'disabled':''}${differences?' aria-label="Различия цен: наша выше — '+num(item.above)+', наша ниже — '+num(item.below)+'. Сравнение 500 г"':''}><span class="dash-workflow-label">${esc(item.label)}</span>${counts?'<span class="dash-workflow-counts">'+counts+'</span>':''}</button>`;
}
function dashboardWorkflowsView(){
  const flow=dashboardWorkflows();
  return `<section class="dash-workflows" aria-labelledby="dash-workflows-title"><div class="dash-workflows-heading"><h2 id="dash-workflows-title">Что сделать дальше</h2><button class="dash-link" type="button" data-dash-action="all-workflows">Все действия →</button></div><div class="dash-workflows-primary">${flow.primary.map(item=>dashboardWorkflowButton(item)).join('')}</div></section>`;
}
function dashboardOpenWorkflows(){
  const flow=dashboardWorkflows();state.customer=null;openDrawer('ЧТО СДЕЛАТЬ ДАЛЬШЕ');
  $('#drawer-content').innerHTML=`<div class="dash-detail dash-workflow-list"><h2>Что сделать дальше</h2><p>Выберите действие — откроется нужный список с подходящими фильтрами.</p>${flow.all.map(item=>`<section class="dash-workflow-item">${dashboardWorkflowButton(item)}<p>${esc(item.description)}</p></section>`).join('')||'<p>Сейчас нет задач, требующих проверки.</p>'}${flow.queued?'<div class="dash-workflow-queue"><strong>В очереди на проверку: '+num(flow.queued)+'</strong><p>Эти страницы обрабатываются автоматически. Повторно отправлять их не нужно.</p><button class="button" data-dash-action="workflow" data-key="price-queue">Посмотреть очередь →</button></div>':''}</div>`;
}
async function dashboardRunWorkflow(key){
  const flow=dashboardWorkflows(),task=flow.all.find(item=>item.key===key);
  if(!task&&!(key==='price-queue'&&flow.queued)){toast('Список действий обновился. Посмотрите актуальные задачи на главной.');return;}
  if(key==='market-reload'){await dashboardLoad();if($('#drawer').open)dashboardOpenWorkflows();return;}
  if(key==='sales-sync'){state.customer=null;openDrawer('ОБНОВЛЕНИЕ ПРОДАЖ');$('#drawer-content').innerHTML='<div class="dash-detail">'+(dashboardState.error?'<p>'+esc(dashboardState.error)+'</p>':'')+sourceSettings(state.meta)+'<button class="button" data-dash-action="reload">Обновить сводку →</button></div>';return;}
  if(key==='contact-matches'){
    const d=dashboardState.data;$('#year').value='0';$('#as-of').value=d.today;$('#currency').value=d.currency;
    state.duplicateKind='all';state.duplicateSort='match';state.duplicateDir=1;state.duplicateSelected.clear();state.duplicateCustomer=null;
    navigate('duplicates');return;
  }
  const c=competitorState;c.tab=key==='price-errors'?'errors':key==='price-runner'?'coverage':['confirm-products','find-products'].includes(key)?'matching':'products';
  c.source='all';c.search='';c.archived=false;c.matrixPack='500';c.matrixPage=1;
  c.matrixStatus=key==='price-differences'?'difference':key==='prices-above'?'above':key==='prices-below'?'below':'all';c.matrixSort=key==='price-differences'?'difference':key==='prices-above'?'above':key==='prices-below'?'below':'name';
  navigate('competitors');
  if(key==='confirm-products'||key==='find-products')await monitoringOpen('all',0,key==='confirm-products'?'review':'all');
  else if(task?.taskKind||key==='price-queue')competitorTasks(task?.taskKind||'queue');
}
function dashboardOverviewView(){
  const d=dashboardState.data;if(!d)return `<div class="dash-loading">${dashboardState.error?esc(dashboardState.error)+' <button class="button" data-dash-action="reload">Повторить</button>':'Собираем сводку месяца…'}</div>`;
  requestAnimationFrame(()=>{const chart=document.querySelector('.dash-chart');if(state.view==='overview'&&chart)chart.innerHTML=dashboardChart();});
  const market=dashboardMarket(),tasks=dashboardTasks().filter(t=>dashboardState.filter==='all'||t.type===dashboardState.filter),fresh=dashboardFresh(),metrics=d.metrics;
  const sourceNote=dashboardState.error?'Ошибка чтения. Показана сохранённая сводка.':!fresh?'Данные продаж требуют обновления. Рекомендации по покупателям приостановлены.':d.sync?.configured?'Продажи · '+dashboardDate(d.sync.last_success):'Ручная копия · '+dashboardDate(d.imported_at);
  const tile=(label,value,note,help)=>`<article class="dash-kpi"><span>${label}${infoHelp(help)}</span><strong>${value}</strong><small>${note}</small></article>`;
  return `<div class="dash-board"><div class="dash-period"><span>${esc(dashboardMonth(d.month_start))} · по ${fmtDate(d.today)}</span><span class="dash-source ${fresh?'':'needs-update'}">${esc(sourceNote)}</span><button class="dash-link" data-dash-action="reload" ${dashboardState.loading?'disabled':''}>${dashboardState.loading?'Обновляем…':'↻ Обновить'}</button></div>
  <section class="dash-kpis" aria-label="Показатели месяца">${tile('Сумма заказов',dashboardMoney(metrics.amount),esc(dashboardDelta('amount')),'Сумма заказов за месяц')}${tile('Заказы',num(metrics.count),'Сегодня '+num(d.days.find(day=>day.state==='today')?.count||0)+' · день ещё идёт','Количество заказов за месяц')}${tile('Средний заказ',dashboardMoney(metrics.average),'С учётом скидок и доставки','Средний заказ за месяц')}${tile('Повторные клиенты',metrics.repeat_share===null?'—':num(metrics.repeat_share)+'%',num(metrics.repeat_customers)+' из '+num(metrics.customers)+' покупали раньше','Повторные покупатели за месяц')}</section>
  ${dashboardWorkflowsView()}<div class="dash-middle"><section class="dash-panel dash-sales"><div class="dash-panel-head"><div><h2>Продажи месяца</h2><p>Тот же месяц год назад · ${esc(d.currency)}</p></div><div class="dash-switch" aria-label="Группировка продаж">${['days','weeks'].map(g=>`<button data-dash-action="group" data-value="${g}" aria-pressed="${dashboardState.group===g}">${g==='days'?'Дни':'Недели'}</button>`).join('')}</div></div><div class="dash-chart">${dashboardChart()}</div><div class="dash-legend"><span><i></i>${d.month_start.slice(0,4)}</span><span><i class="previous"></i>${d.comparison.previous_start.slice(0,4)}</span><span><i class="today"></i>Сегодня, неполный день</span></div><p class="dash-comparison-period">${esc(dashboardComparisonLabel())}</p><div class="dash-sales-foot"><span>Столбец → заказы</span><button class="dash-link" data-dash-action="reports">Другие периоды →</button></div></section>
  <section class="dash-panel dash-priorities"><div class="dash-panel-head"><div><h2>С кем связаться</h2><p>По приоритету</p></div><button class="dash-link" data-dash-action="queue">Все (${num(dashboardTasks().length)}) →</button></div><div class="dash-switch dash-filters" aria-label="Тип действия">${[['all','Все'],['risk','Вернуть'],['opportunity','Напомнить']].map(([key,label])=>`<button data-dash-action="filter" data-value="${key}" aria-pressed="${dashboardState.filter===key}">${label}</button>`).join('')}</div><div class="dash-tasks">${tasks.slice(0,3).map(dashboardTaskHTML).join('')||`<p class="dash-unavailable">${fresh?'В этом списке нет подтверждённых действий.':'Нужна свежая копия продаж для рекомендаций.'}</p>`}</div></section>
  ${dashboardMarketPanel(market)}</div><div class="dash-bottom"><section class="dash-panel"><div class="dash-panel-head"><h2>Запасы и закупка</h2><span class="dash-state">Не подключено</span></div><div class="dash-placeholder"><strong>Остатки ещё не загружены</strong><p>Покажем, что закончилось и что пора заказать.</p><button class="dash-link" data-dash-action="inventory">Что нужно для расчёта →</button></div></section><section class="dash-panel"><div class="dash-panel-head"><h2>Обращения с сайта</h2><span class="dash-state">Не подключено</span></div><div class="dash-inbox"><span>Отзывы <b>—</b></span><span>Сообщения <b>—</b></span><span>Обратные звонки <b>—</b></span></div><button class="dash-link dash-inbox-link" data-dash-action="inbox">Как подключим единую очередь →</button></section></div><div class="dash-note">Суммы заказов не подтверждают оплату. <button class="dash-link" data-dash-action="costs">Прибыль и себестоимость →</button></div></div>`;
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
  $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(title)}</h2><p>${esc(intro)}</p><p>${esc(body)}</p>${kind==='method'?`<p>Динамика сравнивает ${d.comparison.days} полностью завершённых дней: ${fmtDate(d.comparison.current_start)} — ${fmtDate(d.comparison.current_end)} и ${fmtDate(d.comparison.previous_start)} — ${fmtDate(d.comparison.previous_end)}. На первом дне месяца сравнения ещё нет. Сравниваем тот же месяц прошлого года. Если в одном феврале 29 дней, а в другом 28, берём общее число завершённых дней.</p><p>Серые столбцы показаны только для закрытых периодов. Недели начинаются с понедельника и обрезаются границами месяца; прошлогодний месяц сопоставляется по тем же номерам дней. Сегодняшняя неделя ещё не завершена.</p><button class="button" data-dash-action="settings">Настройки учитываемых заказов →</button>`:''}</div>`;
}
function dashboardOpenMarket(item){
  if(!item)return;openDrawer('ИЗМЕНЕНИЕ ЦЕНЫ · '+num(item.grams)+' Г');state.customer=null;
  $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(item.label||item.title)}</h2><p>${esc(competitorSource(item.target.source_id).name)} · ${esc(item.pack)}</p><div class="dash-price-change"><span>${competitorMoney(item.oldPrice,'UAH')}</span> → <strong>${competitorMoney(item.newPrice,'UAH')}</strong><b>${item.percent>0?'+':''}${num(item.percent,1)}%</b></div><p>Изменение замечено ${esc(dashboardDate(item.at))}. Текущая цена и все предложения доступны по источнику.</p><p>${item.percent<0?'Проверьте нашу цену и спрос. Снижение цены конкурента само по себе не требует снижения нашей.':'Проверьте нашу позицию и себестоимость: возможно, скидка больше не нужна.'}</p><p>${competitorLink(item.target.url,'Открыть товар у конкурента')}</p><button class="button primary" data-dash-action="competitors" data-group="${esc(item.target.match_group)}" data-pack="${item.grams}">Открыть таблицу сравнения →</button></div>`;
}
function dashboardOpenEvent(item){
  if(!item)return;if(item.kind==='price'){dashboardOpenMarket(item);return;}
  openDrawer('СОБЫТИЕ КОНКУРЕНТА');state.customer=null;
  const facts=item.details||[];
  $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(item.title)}</h2><p>${esc(competitorSource(item.source).name)} · замечено ${esc(dashboardDate(item.at))}</p><p>${esc(item.summary)}</p>${item.kind==='new'?'<p>Впервые замечено на наблюдаемой странице. Это не дата появления товара на рынке.</p>':item.kind==='availability'?'<p>Наличие изменилось у конкретной фасовки. Перед заказом проверьте актуальное предложение.</p>':'<p>Показаны изменения сохранённой страницы. Сроки акции и ограничения проверяйте по источнику.</p>'}${facts.map(f=>`<section class="dash-commercial-change"><h3>${esc(f.label)}</h3><p><b>Раньше:</b> ${esc(f.before||'Не было указано')}</p><p><b>Теперь:</b> ${esc(f.after||'Больше не указано')}</p>${(f.after_quotes||[]).map(q=>'<blockquote>'+esc(q)+'</blockquote>').join('')}</section>`).join('')}${!facts.length&&item.kind==='terms'?'<details><summary>Сохранённый текст: раньше и теперь</summary><h3>Раньше</h3><pre>'+esc(item.beforeText)+'</pre><h3>Теперь</h3><pre>'+esc(item.afterText)+'</pre></details>':''}<p>${competitorLink(item.url||item.target.url,'Открыть источник')}</p><p><button class="button" data-dash-action="event-history" data-id="${item.target.id}">История этой страницы →</button>${['terms','promotion'].includes(item.kind)?'<button class="button primary" data-dash-action="terms" data-source="'+esc(item.source)+'" data-terms-row="'+esc(facts[0]?.row||'promotions')+'">Сравнить это условие →</button>':''}</p></div>`;
}
function dashboardOpenQueue(){openDrawer('РИСКИ И ВОЗМОЖНОСТИ');state.customer=null;const tasks=dashboardTasks().filter(t=>dashboardState.filter==='all'||t.type===dashboardState.filter);$('#drawer-content').innerHTML=`<div class="dash-detail"><h2>Действия по приоритету</h2><p>Фильтр: ${esc({all:'все',risk:'риски',opportunity:'возможности'}[dashboardState.filter])}. Покупатели не дублируются; запреты и паузы в общении учтены. Свежесть продаж: ${esc(dashboardDate(dashboardState.data.sync?.last_success||dashboardState.data.imported_at))}.</p><div class="dash-queue">${tasks.slice(0,50).map(dashboardTaskHTML).join('')||'<p>В этом списке пока нет действий.</p>'}</div>${tasks.length>50?'<p>На этой панели первые 50 действий.</p>':''}<button class="button" data-dash-action="crm-queue">Открыть полную очередь контактов →</button></div>`;}
async function dashboardHandle(button){
  const action=button.dataset.dashAction;
  if(action==='reload'){await dashboardLoad();return;}
  if(action==='all-workflows'){dashboardOpenWorkflows();return;}
  if(action==='workflow'){await dashboardRunWorkflow(button.dataset.key);return;}
  if(action==='event'){dashboardOpenEvent(dashboardMarket().events.find(item=>item.id===button.dataset.id));return;}
  if(action==='market-events'){const market=dashboardMarket();openDrawer('СОБЫТИЯ КОНКУРЕНТОВ');state.customer=null;$('#drawer-content').innerHTML='<div class="dash-detail"><h2>События за 7 дней</h2>'+dashboardMarketHTML(market,30)+(dashboardMarketEvents(market).length>30?'<p>Показаны первые 30 событий. Полная история — в разделе конкурентов.</p>':'')+'<button class="button" data-dash-action="competitors">Открыть сравнение цен →</button></div>';return;}
  if(action==='event-history'){const target=competitorState.data?.targets.find(t=>t.id===Number(button.dataset.id));if(!target)return;competitorState.tab='changes';competitorState.source=target.source_id;competitorState.search=target.label;competitorState.archived=false;navigate('competitors');await competitorHistory(target.id);return;}
  if(action==='position'){const row=competitorMatrixRows().find(r=>r.key===button.dataset.row);if(!row)return;competitorState.tab='products';competitorState.matrixPack=String(row.grams);competitorState.matrixStatus='all';competitorState.matrixPage=1;competitorState.source='all';competitorState.search=row.group;navigate('competitors');return;}

  if(['group','filter','market-tab'].includes(action)){dashboardState[{group:'group',filter:'filter','market-tab':'marketTab'}[action]]=button.dataset.value;render();document.querySelector(`[data-dash-action="${action}"][data-value="${button.dataset.value}"]`)?.focus({preventScroll:true});return;}
  if(['method','inventory','inbox','costs'].includes(action)){dashboardInfo(action);return;}
  if(action==='queue'){dashboardOpenQueue();return;}
  if(action==='market-health'){
    openDrawer('ОБНОВЛЕНИЕ ДАННЫХ КОНКУРЕНТОВ');state.customer=null;const data=competitorState.data;
    const rows=(data?.sources||[]).filter(source=>source.id!=='lavka').map(source=>{const targets=data.targets.filter(t=>t.enabled&&t.source_id===source.id),fresh=targets.filter(t=>t.last_success&&!competitorStale(t)&&t.last_attempt?.state!=='error').length,errors=targets.filter(t=>t.last_attempt?.state==='error').length,last=targets.map(t=>t.last_success).filter(Boolean).sort().at(-1);return `<tr><td>${esc(source.name)}</td><td>${fresh} / ${targets.length}</td><td>${errors}</td><td>${esc(dashboardDate(last))}</td></tr>`;}).join('');
    $('#drawer-content').innerHTML=`<div class="dash-detail"><h2>Когда обновлялись данные</h2><p>Свежая страница успешно проверена за 48 часов и не имеет ошибки последней попытки. Считаем только выбранные страницы конкурентов; это не весь ассортимент рынка.</p>${dashboardState.marketError?'<p>Последнее чтение мониторинга не удалось. Ниже сохранённая информация.</p>':''}<div class="table-scroll"><table><thead><tr><th>Магазин</th><th>Свежие / всего</th><th>Ошибки</th><th>Последний успех</th></tr></thead><tbody>${rows}</tbody></table></div><p>Новинки и изменения цен на главной — за 7 дней. Неизвестные и старые цены не создают рекомендаций.</p><button class="button" data-dash-action="errors">Открыть список ошибок →</button></div>`;return;
  }
  if(action==='task'){const task=dashboardTasks().find(t=>t.id===button.dataset.id);if(!task)return;if(task.kind==='market')dashboardOpenMarket(task);else await openCRM(task.customer_id);return;}
  if(action==='market'){dashboardOpenMarket(dashboardMarket().prices[Number(button.dataset.index)]);return;}
  if(action==='news'){const item=dashboardMarket().news[Number(button.dataset.index)];if(!item)return;openDrawer('СОБЫТИЕ КОНКУРЕНТА');state.customer=null;$('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${esc(item.title)}</h2><p>${esc(competitorSource(item.source).name)} · ${esc(dashboardDate(item.at))}</p><p>${item.kind==='new'?'Впервые замечено на наблюдаемой странице. Это не дата появления товара на рынке.':'Изменился текст условий. Изменение ещё не означает новую акцию; проверьте пороги, сроки и ограничения в общей таблице.'}</p>${competitorLink(item.url||item.target.url,'Открыть источник')}<p><button class="button" data-dash-action="terms">Сравнить условия магазинов →</button></p></div>`;return;}
  if(action==='bucket'){const bucket=dashboardBuckets()[Number(button.dataset.index)];if(!bucket)return;openDrawer('ЗАКАЗЫ · '+bucket.label);state.customer=null;const sequence=competitorState.historySequence;const orders=await Promise.all(bucket.order_ids.map(id=>api('/api/orders/'+id)));if(!$('#drawer').open||sequence!==competitorState.historySequence)return;$('#drawer-content').innerHTML=`<div class="dash-detail"><h2>${fmtDate(bucket.date)}${bucket.end!==bucket.date?' — '+fmtDate(bucket.end):''}</h2><p>${num(bucket.count)} заказов · ${dashboardMoney(bucket.amount)}${bucket.state==='today'?' · неполный период':''}</p></div>`+(orders.map(o=>orderHTML(o)).join('')||'<p class="dash-detail">Заказов за этот день нет.</p>');return;}
  if(['reports','settings','crm-queue','competitors','terms','errors'].includes(action)){
    $('#drawer').close();state.customer=null;
    if(action==='reports'){navigate('overview-report');return;}if(action==='settings'){navigate('settings');return;}if(action==='crm-queue'){$('#as-of').value=dashboardState.data.today;state.crmScope='todo';state.actionMin=state.meta.settings.followup_min_orders;navigate('actions');return;}
    competitorState.tab=action==='terms'?'terms':action==='errors'?'errors':'products';competitorState.source='all';competitorState.search=button.dataset.group||'';competitorState.archived=false;competitorState.matrixPack=button.dataset.pack||'500';competitorState.matrixStatus='all';competitorState.matrixPage=1;navigate('competitors');if(action==='terms'&&button.dataset.source&&button.dataset.termsRow)competitorTermsDetail(button.dataset.termsRow,button.dataset.source);
  }
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-dash-action]');if(button)dashboardHandle(button).catch(error=>toast(error.message));});
document.addEventListener('keydown',event=>{const button=event.target.closest('.dash-chart-hit');if(button&&['Enter',' '].includes(event.key)){event.preventDefault();dashboardHandle(button).catch(error=>toast(error.message));}});

let dashboardResize;
window.addEventListener('resize',()=>{clearTimeout(dashboardResize);dashboardResize=setTimeout(()=>{if(state.view==='overview'&&dashboardState.data&&document.querySelector('.dash-chart'))document.querySelector('.dash-chart').innerHTML=dashboardChart();},100);});
