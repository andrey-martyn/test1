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
  'overview-report':['Продажи','Сколько заказывают и как меняется спрос. Выберите период ниже.'],
  products:['Товары','Что покупают, в каких фасовках и на какую сумму.'],
  customers:['История покупок','Найдите покупателя и посмотрите, что и когда он заказывал.'],
  actions:['С кем связаться','Кому подошло время заказа: проверьте историю и выберите повод для разговора.'],
  retention:['Повторные покупки','Возвращаются ли покупатели и сколько времени проходит до следующего заказа.'],
  segments:['Группы покупателей','Кто недавно пришёл, кто покупает регулярно, а кто давно не возвращался.'],
  opportunities:['Что предложить','Кому пора пополнить покупки и какие товары часто берут вместе.'],
  seasonality:['Спрос по сезонам','Что покупают по месяцам и когда оформляют заказы.'],
  geography:['Где покупают','Города и регионы, откуда приходят заказы.'],
  ga4:['Посетители и реклама','Откуда приходят посетители и какие действия видны на сайте.'],
  duplicates:['Совпадения контактов','Проверьте, не разделилась ли история одного человека между несколькими карточками.'],
  manual:['Как вернуть покупателя','Понятные шаги для разговора, предложения и повторного заказа.'],
  competitors:['Цены и конкуренты','Наши цены рядом с ценами других магазинов, их новинки и условия покупки.'],
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
function businessNavigation(view){
  const area=businessArea(view),views=businessAreas[area].views,container=document.getElementById('section-navigation');
  document.querySelectorAll('#navigation [data-area]').forEach(button=>{const active=button.dataset.area===area;button.classList.toggle('active',active);if(active)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');});
  container.hidden=views.length<2;
  container.innerHTML=views.length<2?'':views.map((item,index)=>`<button type="button" ${index===0?'data-section-view':'data-view'}="${item}" ${item===view?'aria-current="page"':''}>${esc(businessViewTitles[item][0])}</button>`).join('');
  if(views.length>1)requestAnimationFrame(()=>{const active=container.querySelector('[aria-current]');if(active&&container.scrollWidth>container.clientWidth)container.scrollLeft=Math.max(0,active.offsetLeft-container.offsetLeft-12);});
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-section-view],#section-navigation [data-view]');if(button){const view=button.dataset.sectionView||button.dataset.view;navigate(view);document.querySelector('#section-navigation [data-view="'+view+'"],#section-navigation [data-section-view="'+view+'"]')?.focus({preventScroll:true});}});
