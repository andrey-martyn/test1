/* Estimates stay local to the report; they do not change shop profiles. */
const genderLabels={all:'Все покупатели',male:'Мужчины',female:'Женщины',unknown:'Не определено'};
const genderFilterViews=new Set(['overview-report','seasonality','geography','products','customers']);
const genderHelp='Это приблизительная оценка по имени в заказах, а не указанный покупателем пол. Используется локальный словарь русских, украинских имён и распространённых вариантов латиницей. Саша, Женя, инициалы и незнакомые имена остаются в «Не определено». Фамилия и телефон для оценки не используются. Если в учитываемой истории объединённого покупателя есть и мужские, и женские имена, вся история относится к «Не определено». Учитываются выбранная валюта, статусы и дата проверки.\nФильтр пересчитывает продажи, товары, сезонность, географию и историю покупок. «Сегодня», очередь контактов, другие CRM-разделы и посетители GA4 показывают всех покупателей.';
function reportGender(view=state.view){return genderFilterViews.has(view)?state.gender:'all';}
function genderControls(){
 const visible=genderFilterViews.has(state.view),control=document.getElementById('gender-control'),note=document.getElementById('gender-filter-note');
 control.hidden=!visible;document.getElementById('gender').disabled=!visible;document.getElementById('gender').value=state.gender;
 note.hidden=!visible;note.textContent=state.gender==='all'?'Группы по имени — приблизительная оценка. Неоднозначные имена учитываются отдельно.':genderLabels[state.gender]+' · оценка по имени. Все показатели этого отчёта пересчитаны для выбранной группы.';
}
async function applyGenderFilter(gender){
 if(!Object.hasOwn(genderLabels,gender)||!genderFilterViews.has(state.view)||state.gender===gender)return;
 navigationBefore();state.gender=gender;state.page=1;state.historyOpen=null;navigationPage();render();await load();
}
function genderComparisonView(){
 const r=state.report,rows=r.gender_breakdown||[];
 return `<section class="panel gender-comparison" aria-label="Сравнение покупателей по имени">${panelHeading('Кто покупает: оценка по имени','Все три группы за выбранный период, в указанной валюте — независимо от выбранного фильтра.',`<button class="button" data-action="gender-filter" data-gender="all" ${state.gender==='all'?'disabled':''}>Все покупатели</button>`)}<div class="gender-cards">${rows.map(row=>`<article class="gender-card ${state.gender===row.key?'selected':''}"><button class="gender-group" data-action="gender-filter" data-gender="${row.key}" aria-pressed="${state.gender===row.key}">${esc(genderLabels[row.key])}<span aria-hidden="true">${state.gender===row.key?'✓':'→'}</span></button><dl><div><dt>Покупателей</dt><dd>${num(row.customer_count)}</dd></div><div><dt>Заказов</dt><dd>${num(row.order_count)}</dd></div><div><dt>Сумма заказов</dt><dd>${cash(row.amount)}</dd></div><div><dt>Средний заказ</dt><dd>${cash(row.average)}</dd></div></dl><p>${num(row.amount_share,1)}% суммы заказов за период</p></article>`).join('')}</div></section>`;
}
document.addEventListener('change',event=>{if(event.target.id==='gender')applyGenderFilter(event.target.value);});
document.addEventListener('click',event=>{const button=event.target.closest('[data-action="gender-filter"]');if(button)applyGenderFilter(button.dataset.gender);});
