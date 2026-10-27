/* Presentation only: routes, saved IDs and calculation rules remain stable. */
const businessAreas={
  overview:{label:'Сегодня',views:['overview']},
  sales:{label:'Продажи',views:['overview-report','seasonality','geography','ga4']},
  products:{label:'Товары',views:['products']},
  customers:{label:'Покупатели',views:['customers','actions','retention','segments','opportunities','duplicates','manual']},
  competitors:{label:'Цены и конкуренты',views:['competitors']},
  settings:{label:'Настройки',views:['settings']}
};
const businessViewTitles={
  overview:['Сегодня в Лавочке','Как идут дела и что стоит сделать дальше.'],
  'overview-report':['Продажи','Как меняется результат и что проверить первым. Выберите период ниже.'],
  products:['Товары','Что покупают, в каких фасовках и на какую сумму.'],
  customers:['История покупок','Найдите покупателя и посмотрите, что и когда он заказывал.'],
  actions:['С кем связаться','Кому подошло время заказа: проверьте историю и выберите повод для разговора.'],
  retention:['Повторные покупки','Как помочь первой покупке стать второй и проверить результат.'],
  segments:['Группы покупателей','Кто недавно пришёл, кто покупает регулярно, а кто давно не возвращался.'],
  opportunities:['Что предложить','Кому пора пополнить покупки и какие товары часто берут вместе.'],
  seasonality:['Спрос по сезонам','Когда готовиться к спросу: проверяем месяцы по отдельным годам.'],
  geography:['Где покупают','Доли регионов, изменения по годам и следующий шаг.'],
  ga4:['Посетители и реклама','Откуда приходят посетители и какие действия видны на сайте.'],
  duplicates:['Совпадения контактов','Проверьте, не разделилась ли история одного человека между несколькими карточками.'],
  manual:['Как вернуть покупателя','Понятные шаги для разговора, предложения и повторного заказа.'],
  competitors:['Цены и конкуренты','Наши цены по сравнению с ценами других магазинов. Новинки и условия покупки.'],
  settings:['Настройки кабинета','Какие заказы учитывать и когда напоминать о следующей покупке.']
};
const businessSegmentNames={new:'Недавно купили впервые',second:'Ждём второй заказ',loyal:'Постоянные и активные',growing:'Начали покупать повторно',vip_risk:'Постоянные пропустили срок',return:'Подошёл срок заказа',sleeping:'Постоянные с долгой паузой',oneoff:'Один заказ и долгая пауза'};
const businessSegmentSuggestions={new:'Уточнить, всё ли подошло, и помочь со следующим заказом.',second:'Узнать впечатления и предложить знакомые товары.',loyal:'Поддерживать отношения и предлагать уместные новинки.',growing:'Помочь повторить удачный заказ и выбрать дополнение.',vip_risk:'Лично уточнить, почему появилась пауза.',return:'Напомнить о знакомых товарах и удобном повторном заказе.',sleeping:'Аккуратно восстановить разговор и узнать, актуальны ли покупки.',oneoff:'Проверить, есть ли подходящий повод вернуться к разговору.'};
function businessSegmentName(id,fallback=''){return businessSegmentNames[id]||fallback;}
function businessReport(report){
  for(const customer of report.customers||[])customer.segment_name=businessSegmentName(customer.segment,customer.segment_name);
  for(const group of report.marketing?.segments||[]){group.name=businessSegmentName(group.id,group.name);group.suggestion=businessSegmentSuggestions[group.id]||group.suggestion;}
  if(typeof manualContent!=='undefined')for(const group of manualContent.segments)group.name=businessSegmentName(group.id,group.name);
  return report;
}
function businessArea(view){return Object.keys(businessAreas).find(key=>businessAreas[key].views.includes(view))||'overview';}
function businessTabArrow(direction){
  return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${direction<0?'m15 6-6 6 6 6':'m9 6 6 6-6 6'}"/></svg>`;
}
const businessJourneyRoutes={
 'overview-report':[['seasonality','Сравнить сезоны и годы'],['products','Посмотреть товары'],['actions','С кем связаться']],
 seasonality:[['products','Что продавалось'],['geography','Где покупают'],['actions','С кем связаться']],
 geography:[['customers','История покупателей'],['seasonality','Спрос по сезонам'],['actions','С кем связаться']],
 ga4:[['overview-report','Продажи'],['products','Товары'],['settings','Настройки']],
 products:[['seasonality','Спрос по сезонам'],['customers','История покупателей'],['competitors:products','Сравнить цены']],
 customers:[['duplicates','Сверить клиентов'],['actions','С кем связаться'],['manual','Как вернуть покупателя']],
 actions:[['duplicates','Сверить клиентов'],['manual:conversation','Подготовить обращение'],['settings','Правила напоминаний']],
 duplicates:[['actions','С кем связаться после сверки'],['manual:conversation','Как подготовить обращение'],['settings','Правила напоминаний']],
 retention:[['actions','С кем связаться'],['segments','Группы покупателей'],['manual','Как вернуть покупателя']],
 segments:[['actions','С кем связаться'],['duplicates','Сверить клиентов'],['manual:scenarios','Сценарии работы с покупателями']],
 opportunities:[['products','Товары и фасовки'],['actions','С кем связаться'],['manual','Как предложить помощь']],
 manual:[['actions','Открыть список контактов'],['duplicates','Сначала сверить клиентов'],['settings','Правила напоминаний']],
 competitors:[['competitors:changes','Изменения у конкурентов'],['competitors:terms','Условия магазинов'],['products','Наши продажи по товарам']],
 settings:[['actions','С кем связаться'],['duplicates','Сверить клиентов'],['manual','Как вернуть покупателя']]
};
const businessCompetitorTabs=[['products','Сравнение цен'],['terms','Условия магазинов'],['changes','Изменения'],['discoveries','Ассортимент'],['matching','Подтвердить товары'],['coverage','Загрузка и поиск'],['links','Карточки товаров'],['sources','Магазины'],['errors','Ошибки чтения']];
function businessTargetButton(target,label){return `<button type="button" class="button" data-business-target="${esc(target)}">${esc(label)}</button>`;}
function businessDirectoryView(){
 return `<details class="business-directory"><summary>Все разделы кабинета</summary><div class="business-directory-groups">${Object.entries(businessAreas).map(([area,group])=>`<section><h4>${esc(group.label)}</h4>${(area==='competitors'?businessCompetitorTabs.map(([tab,label])=>['competitors:'+tab,label]):group.views.flatMap(view=>view==='manual'?[[view,businessViewTitles[view][0]],['manual:books','Книги и источники']]:[[view,businessViewTitles[view][0]]])).map(([target,label])=>businessTargetButton(target,label)).join('')}</section>`).join('')}</div></details>`;
}
function businessJourney(view){
 const root=document.getElementById('business-journey');if(!root)return;
 const keepOpen=root.dataset.view===view&&root.querySelector('details')?.open;root.dataset.view=view;root.hidden=view==='overview';
 if(root.hidden){root.innerHTML='';return;}
 const links=businessJourneyRoutes[view]||[];
 root.innerHTML=`<div class="business-journey-heading">Дальше по задаче</div><div class="business-journey-links">${links.map(([target,label])=>businessTargetButton(target,label)).join('')}</div>${businessDirectoryView()}`;
 if(keepOpen)root.querySelector('details').open=true;
}
async function businessOpenTarget(target){
 const [view,tab]=target.split(':');if(!businessViewTitles[view]||tab&&!((view==='competitors'&&businessCompetitorTabs.some(item=>item[0]===tab))||(view==='manual'&&manualTabs.some(item=>item[0]===tab))))return;
 navigate(view);
 if(view==='actions'){state.crmScope='todo';state.crmTier='all';state.crmDuplicates='all';state.actionMin=state.meta?.settings.followup_min_orders||0;}
 if(view==='duplicates'){state.duplicateKind='all';state.duplicateReview='pending';state.duplicateSort='match';state.duplicateDir=1;state.duplicateSelected.clear();state.duplicateCustomer=null;}
 if(view==='manual'){state.manualTab=tab||'start';state.manualSearch='';}
 if(view==='competitors'){competitorState.tab=tab||'products';competitorState.search='';competitorState.source='all';competitorState.archived=false;competitorState.matrixPage=1;}
 render();if(view==='competitors')competitorRememberLocation();navigationRemember();window.scrollTo(0,0);
 if(view==='competitors'&&tab==='matching')await monitoringOpen('all',0,'review');
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-business-target]');if(button)businessOpenTarget(button.dataset.businessTarget);});
function businessNavigation(view){
  const area=businessArea(view),views=businessAreas[area].views,container=document.getElementById('section-navigation');
  businessJourney(view);
  const previous=container.querySelector('.section-tabs'),left=container.dataset.area===area?(previous?.scrollLeft||0):0;
  document.querySelectorAll('#navigation [data-area]').forEach(button=>{const active=button.dataset.area===area;button.classList.toggle('active',active);if(active)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');});
  container.hidden=views.length<2;container.dataset.area=area;
  container.innerHTML=views.length<2?'':`<button class="competitor-tab-arrow" type="button" data-section-scroll="-1" aria-label="Прокрутить вкладки влево">${businessTabArrow(-1)}</button><div class="section-tabs">${views.map((item,index)=>`<button type="button" ${index===0?'data-section-view':'data-view'}="${item}" ${item===view?'aria-current="page"':''}>${esc(businessViewTitles[item][0])}</button>`).join('')}</div><button class="competitor-tab-arrow" type="button" data-section-scroll="1" aria-label="Прокрутить вкладки вправо">${businessTabArrow(1)}</button>`;
  if(views.length>1)requestAnimationFrame(()=>{const strip=container.querySelector('.section-tabs'),active=strip?.querySelector('[aria-current]');if(!active)return;strip.scrollLeft=left;const a=active.getBoundingClientRect(),b=strip.getBoundingClientRect();if(a.left<b.left)strip.scrollLeft-=b.left-a.left;else if(a.right>b.right)strip.scrollLeft+=a.right-b.right;});
}
document.addEventListener('click',event=>{
  const arrow=event.target.closest('#section-navigation [data-section-scroll]');
  if(arrow){const strip=arrow.parentElement.querySelector('.section-tabs');strip.scrollBy({left:Number(arrow.dataset.sectionScroll)*strip.clientWidth*.75,behavior:'smooth'});return;}
  const button=event.target.closest('[data-section-view],#section-navigation [data-view]');
  if(button){const view=button.dataset.sectionView||button.dataset.view;navigate(view,{sectionTab:true});document.querySelector('#section-navigation [data-view="'+view+'"],#section-navigation [data-section-view="'+view+'"]')?.focus({preventScroll:true});}
});
document.addEventListener('keydown',event=>{
  const button=event.target.closest('#section-navigation .section-tabs button');
  if(!button||!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
  event.preventDefault();const tabs=[...button.parentElement.children],current=tabs.indexOf(button),next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(current+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
  tabs[next].click();
});
