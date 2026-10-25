/* Explicit manual grouping; selection stays in memory, never in URLs/storage. */
const identityPicker={open:false,search:'',page:1,total:0,rows:[],selected:new Map(),scope:'',loading:false,busy:false,error:'',sequence:0,loaded:false};
let identitySearchTimer=null;
function identityPickerInvalidate(){const s=identityPicker;s.selected.clear();s.rows=[];s.total=0;s.loaded=false;s.loading=false;s.error='';s.sequence++;}
function identityContactView(c){
 if(!c.manual&&!(c.names?.length>1))return '';
 const contacts=c.contacts||[],count=contacts.length;
 return `<details class="identity-contacts"><summary>Имена и контакты в общей истории (${num(count)})</summary><p>Заказы сохранены с исходными контактами. Показатели покупателя и повторные покупки рассчитаны для всей группы.</p>${state.privacy?'<p>Имена и контакты скрыты. Включите показ имён, чтобы проверить участников.</p>':`<ul>${contacts.map(p=>`<li><strong>${esc(p.name)}</strong>${p.email?'<span>'+esc(p.email)+'</span>':''}${p.phone?'<span>'+esc(p.phone)+'</span>':''}${p.city?'<span>'+esc(p.city)+'</span>':''}</li>`).join('')}</ul>`}</details>`;
}
function identityPickerCard(c){
 const names=(c.names||[]).filter(n=>n!==c.name),contacts=state.privacy?'Контакты скрыты':[...(c.emails||[c.email]),...(c.phones||[c.phone])].filter(Boolean).map(esc).join(' · ');
 return `<strong>${name(c)}</strong>${!state.privacy&&names.length?'<span>'+names.map(esc).join(' · ')+'</span>':''}<span>${contacts||'Контакты не заполнены'}</span><small>${esc(c.city||'Город не указан')} · ${num(c.lifetime_count)} заказов · профиль ${esc(c.id)}</small>`;
}
function identityPickerResults(){
 const s=identityPicker;
 if(s.loading)return '<p role="status">Ищем карточки по всей истории…</p>';
 if(s.error)return '<p class="notice" role="alert">'+esc(s.error)+'</p>';
 return `<div class="identity-pick-list">${s.rows.map(c=>`<label class="identity-pick-card"><input type="checkbox" data-identity-pick="${esc(c.id)}" ${s.selected.has(c.id)?'checked':''} ${s.busy?'disabled':''} aria-label="Выбрать профиль ${esc(c.id)}"><span>${identityPickerCard(c)}</span></label>`).join('')||'<p>Карточки не найдены. Измените имя, фамилию или контакт.</p>'}</div><div class="identity-pick-pages"><span>Найдено: ${num(s.total)} · ${s.page} / ${Math.max(1,Math.ceil(s.total/30))}</span><button type="button" class="button" data-identity-action="prev" ${s.page>1&&!s.busy?'':'disabled'} aria-label="Предыдущая страница карточек">←</button><button type="button" class="button" data-identity-action="next" ${s.page*30<s.total&&!s.busy?'':'disabled'} aria-label="Следующая страница карточек">→</button></div>`;
}
function identityPickerSelection(){
 const s=identityPicker,rows=[...s.selected.values()];
 return `<div class="identity-picked"><h3>Выбрано карточек: ${rows.length} / 20</h3>${rows.length?`<ul>${rows.map(c=>`<li><span>${identityPickerCard(c)}</span><button type="button" class="button" data-identity-action="remove" data-id="${esc(c.id)}" ${s.busy?'disabled':''} aria-label="Убрать профиль ${esc(c.id)}">Убрать</button></li>`).join('')}</ul>`:'<p>Выберите минимум две карточки. Выбор сохраняется при смене поискового запроса и страницы.</p>'}<p>Все выбранные карточки станут одной общей историей покупок — одного покупателя, семьи или организации. Совпадение имён, телефонов и email не требуется. Ручную связь можно отменить в общей карточке.</p><div class="identity-pick-actions"><button type="button" class="button primary" data-identity-action="merge" ${rows.length>=2&&!s.busy?'':'disabled'}>${s.busy?'Объединяем…':'Объединить в одну историю'} (${rows.length})</button><button type="button" class="button" data-identity-action="clear" ${rows.length&&!s.busy?'':'disabled'}>Снять выбор</button></div></div>`;
}
function identityPickerView(){
 const s=identityPicker,asof=document.getElementById('as-of').value;
 if(s.scope!==asof){s.scope=asof;s.rows=[];s.selected.clear();s.loaded=false;s.loading=false;s.sequence++;}
 if(s.open&&!s.loaded&&!s.loading)requestAnimationFrame(()=>{if(s.open&&!s.loaded&&!s.loading)identityPickerLoad();});
 return `<details class="panel identity-picker" id="identity-picker" ${s.open?'open':''} ${state.mergeBusy?'inert aria-busy="true"':''}><summary>Объединить любые карточки <span>Даже без совпадений контактов</span></summary><div class="identity-picker-body"><p>Поиск по всем годам и валютам до ${fmtDate(asof)}. Учитываются статусы из настроек. Год, пол и фильтры списка покупателей этот поиск не ограничивают.</p><form id="identity-picker-search"><label>Имя, фамилия, email, телефон или номер профиля<input id="identity-search" type="search" maxlength="200" autocomplete="off" value="${esc(s.search)}" ${s.busy?'disabled':''}></label><button type="submit" class="button" ${s.busy?'disabled':''}>Найти</button></form><div id="identity-picker-selection">${identityPickerSelection()}</div><div id="identity-picker-results">${identityPickerResults()}</div></div></details>`;
}
function identityPickerRender(){
 const selected=document.getElementById('identity-picker-selection'),results=document.getElementById('identity-picker-results');
 if(selected)selected.innerHTML=identityPickerSelection();if(results)results.innerHTML=identityPickerResults();
 document.querySelectorAll('#identity-picker-search input,#identity-picker-search button').forEach(e=>e.disabled=identityPicker.busy);
 const pairs=document.querySelector('.duplicate-panel');if(pairs){pairs.inert=identityPicker.busy;pairs.setAttribute('aria-busy',String(identityPicker.busy));}
}
async function identityPickerLoad(){
 const s=identityPicker;if(s.busy)return;const sequence=++s.sequence,asof=document.getElementById('as-of').value;
 s.loading=true;s.error='';identityPickerRender();
 try{const q=new URLSearchParams({as_of:asof,search:s.search,page:s.page});const data=await api('/api/identity/customers?'+q);if(sequence!==s.sequence||asof!==document.getElementById('as-of').value)return;s.rows=data.customers;s.total=data.total;s.loaded=true;}
 catch(error){if(sequence===s.sequence)s.error=error.message;}
 finally{if(sequence===s.sequence){s.loading=false;identityPickerRender();}}
}
async function identityPickerOpen(id=null){
 const seed=id?(state.report?.customers.find(c=>c.id===id)||(state.customer?.customer.id===id?state.customer.customer:null)||(crmCard?.customer.id===id?crmCard.customer:null)):null,search=id?'':state.search;
 const fromCRM=document.getElementById('drawer').open&&uiNavigation.drawer?.kind==='crm';
 if(fromCRM)await navigationCheckDuplicates(id);else if(state.view!=='duplicates'||document.getElementById('drawer').open)navigate('duplicates');
 Object.assign(identityPicker,{open:true,search,page:1,loaded:false,error:'',scope:document.getElementById('as-of').value});identityPicker.selected.clear();if(seed)identityPicker.selected.set(seed.id,seed);
 render();await identityPickerLoad();requestAnimationFrame(()=>{document.getElementById('identity-picker')?.scrollIntoView({block:'start',behavior:'instant'});document.getElementById('identity-search')?.focus({preventScroll:true});});
}
async function identityPickerMerge(){
 const s=identityPicker;if(s.busy||state.mergeBusy||s.selected.size<2)return;const ids=[...s.selected.keys()],entry=uiNavigation.current;
 s.busy=true;s.error='';identityPickerRender();
 try{
  const result=await api('/api/identity/merge',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids,as_of:document.getElementById('as-of').value})});
  identityPickerInvalidate();for(const id of ids)if(id!==result.customer_id)uiNavigation.aliases.set(id,result.customer_id);
  await load();toast('Карточки объединены в общую историю. Имена и контакты сохранены.');
  if(uiNavigation.current===entry&&state.view==='duplicates'){
   if(uiNavigation.returnToCRM)navigationReturnToCRM();
   else{
    if(!state.report.customers.some(c=>c.id===result.customer_id)){
     const data=await api('/api/identity/customers?'+new URLSearchParams({as_of:document.getElementById('as-of').value,search:result.customer_id})),card=data.customers.find(c=>c.id===result.customer_id),currency=card&&Object.keys(card.amounts)[0];
     if(currency){document.getElementById('currency').value=currency;await load();}
    }
    await openCustomer(result.customer_id);
   }
  }
 }catch(error){s.error=error.message;toast(error.message);}
 finally{s.busy=false;identityPickerRender();}
}
document.addEventListener('toggle',event=>{if(event.target.id!=='identity-picker'||!event.target.isConnected)return;identityPicker.open=event.target.open;if(event.target.open&&!identityPicker.loading)identityPickerLoad();},true);
document.addEventListener('submit',event=>{if(event.target.id!=='identity-picker-search')return;event.preventDefault();clearTimeout(identitySearchTimer);identityPicker.search=document.getElementById('identity-search').value;identityPicker.page=1;identityPickerLoad();});
document.addEventListener('input',event=>{if(event.target.id!=='identity-search')return;identityPicker.search=event.target.value;identityPicker.page=1;clearTimeout(identitySearchTimer);identitySearchTimer=setTimeout(identityPickerLoad,250);});
document.addEventListener('change',event=>{const id=event.target.dataset.identityPick;if(!id||identityPicker.busy)return;if(event.target.checked){if(identityPicker.selected.size>=20){event.target.checked=false;toast('До 20 карточек в одной общей истории.');return;}const row=identityPicker.rows.find(c=>c.id===id);if(row)identityPicker.selected.set(id,row);}else identityPicker.selected.delete(id);identityPickerRender();document.querySelector('[data-identity-pick="'+id+'"]')?.focus({preventScroll:true});});
document.addEventListener('click',event=>{
 const open=event.target.closest('[data-action=manual-merge-open]');if(open){identityPickerOpen(open.dataset.id||null);return;}
 const button=event.target.closest('[data-identity-action]');if(!button||identityPicker.busy)return;
 const action=button.dataset.identityAction;
 if(action==='merge')identityPickerMerge();
 if(action==='clear'){identityPicker.selected.clear();identityPickerRender();}
 if(action==='remove'){identityPicker.selected.delete(button.dataset.id);identityPickerRender();}
 if(action==='prev'||action==='next'){identityPicker.page+=action==='prev'?-1:1;identityPickerLoad();}
});
