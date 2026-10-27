/* Read-only explanation of existing identity edges. No merge/undo actions here. */
const identityChain={owner:null,sequence:0,controller:null,pinned:false,timer:null,suppressFocus:false,data:null,variant:0,cache:new Map()};
const identityChainPopup=document.createElement('section');
identityChainPopup.id='identity-chain-popup';identityChainPopup.className='identity-chain-popup';identityChainPopup.hidden=true;
identityChainPopup.setAttribute('role','dialog');identityChainPopup.setAttribute('aria-modal','false');identityChainPopup.setAttribute('aria-label','Цепочка совпадения');document.body.append(identityChainPopup);
function identityChainHide(restoreFocus=false){
  const s=identityChain,owner=s.owner;clearTimeout(s.timer);++s.sequence;s.controller?.abort();s.controller=null;s.pinned=false;s.data=null;
  if(owner){owner.setAttribute('aria-expanded','false');owner.removeAttribute('aria-controls');}
  s.owner=null;identityChainPopup.hidden=true;identityChainPopup.replaceChildren();
  if(restoreFocus&&owner?.isConnected){s.suppressFocus=true;owner.focus({preventScroll:true});setTimeout(()=>{s.suppressFocus=false;},0);}
}
function identityChainPosition(){
  const owner=identityChain.owner;if(!owner?.isConnected){identityChainHide();return;}
  const r=owner.getBoundingClientRect(),w=identityChainPopup.offsetWidth,h=identityChainPopup.offsetHeight;
  let left=Math.max(8,Math.min(r.left,innerWidth-w-8)),top=r.bottom+8+h<=innerHeight?r.bottom+8:r.top-h-8;
  if(h>innerHeight-r.bottom-16&&h>r.top-16){
    if(r.left>=w+16)left=r.left-w-8;
    else if(innerWidth-r.right>=w+16)left=r.right+8;
    top=Math.min(r.top,innerHeight-h-8);
  }
  identityChainPopup.style.left=left+'px';
  identityChainPopup.style.top=Math.max(8,Math.min(top,innerHeight-h-8))+'px';
}
function identityChainNode(node){
  const title=state.privacy?'Запись покупателя':esc(node.name),contacts=state.privacy?'Контакты скрыты':[node.email,node.phone].filter(Boolean).map(esc).join(' · ');
  return `<div class="identity-chain-node"><strong>${title}</strong><small>Заказ №${esc(node.id)} · ${fmtDate(node.date)}</small>${contacts?`<span>${contacts}</span>`:''}${node.name_fields_adjusted&&!state.privacy?`<small>Фамилия для сравнения: ${node.surname?esc(node.surname):'не определена'}</small>`:''}${node.outside_sales_scope?'<small>Заказ вне выбранных статусов или валюты; показан как звено истории контактов.</small>':''}</div>`;
}
function identityChainDate(value){
  if(!value)return 'Дата не сохранена';
  return value.length>10?new Date(value).toLocaleString('ru-RU',{timeZone:'Europe/Kiev',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}):fmtDate(value);
}
function identityChainReason(reason){
  if(reason.kind==='manual')return `<strong>Объединено вручную</strong><small>${identityChainDate(reason.created_at)}</small>`;
  if(reason.kind==='account')return `<strong>Один аккаунт магазина</strong><small>Учётная запись №${esc(reason.account_id)}</small>`;
  return `<strong>Совпали email и телефон</strong>${state.privacy?'<small>Значения скрыты</small>':`<small>${esc(reason.email)} · ${esc(reason.phone)}</small>`}`;
}
function identityChainPath(path,label){
  if(!path?.available)return `<section class="identity-chain-side"><h4>${label}</h4><p>${esc(path?.message||'Не удалось показать эту часть истории')}</p></section>`;
  return `<section class="identity-chain-side"><h4>${label}</h4><p>${path.edges.length?'От последних контактов к заказу с совпавшими данными':'Совпадение в последних контактах'}</p><ol>${path.nodes.map((node,index)=>`<li>${identityChainNode(node)}${path.edges[index]?`<div class="identity-chain-edge"><span aria-hidden="true">↓</span><div>${path.edges[index].reasons.map(identityChainReason).join('')}</div></div>`:''}</li>`).join('')}</ol></section>`;
}
function identityChainContent(){
  const s=identityChain,data=s.data,variant=data.variants[s.variant];
  const variantLabel=(v,i)=>{
    const leaf=side=>data.paths[v[side+'_path']]?.nodes?.slice(-1)[0]?.id||'?';
    return `${i+1}. ${v.matches.map(m=>m.label).join(', ')} · №${leaf('left')} ↔ №${leaf('right')}`;
  };
  const options=data.variants.length>1?`<label class="identity-chain-variants">Какое совпадение показать<select id="identity-chain-variant">${data.variants.map((v,i)=>`<option value="${i}"${i===s.variant?' selected':''}>${esc(variantLabel(v,i))}</option>`).join('')}</select></label>`:'';
  return `${options}<div class="identity-chain-match"><strong>Совпавшие данные у двух карточек</strong>${variant.matches.map(m=>`<span>${esc(m.label)}: ${state.privacy?'значение скрыто':esc(m.value)}</span>`).join('')}<p>${data.review_state==='different_customers'?'Пара отмечена как разные клиенты. Показаны только связи внутри каждой истории.':'Это предложение для проверки. Эта строка сама не объединяет две карточки.'}</p></div><div class="identity-chain-paths">${identityChainPath(data.paths[variant.left_path],'Контакт 1')}${identityChainPath(data.paths[variant.right_path],'Контакт 2')}</div>${data.variant_total>data.variants.length?`<p>Показано ${data.variants.length} из ${data.variant_total} вариантов совпадения.</p>`:''}<p class="identity-chain-note">${esc(data.note)}</p>`;
}
function identityChainShell(body){
  const hadFocus=identityChainPopup.contains(document.activeElement);
  identityChainPopup.innerHTML=`<div class="identity-chain-heading"><h3>Цепочка совпадения</h3><button type="button" class="button" data-chain-close aria-label="Закрыть цепочку совпадения">×</button></div><div class="identity-chain-body">${body}</div>`;
  identityChainPopup.hidden=false;identityChainPosition();
  if(hadFocus)identityChainPopup.querySelector('[data-chain-close]')?.focus({preventScroll:true});
}
async function identityChainShow(button,pin=false){
  const s=identityChain;clearTimeout(s.timer);
  if(s.owner===button&&!identityChainPopup.hidden){if(pin)s.pinned=true;return;}
  identityChainHide();hideHelp();s.owner=button;s.pinned=pin;s.variant=0;
  button.setAttribute('aria-expanded','true');button.setAttribute('aria-controls','identity-chain-popup');
  const params=new URLSearchParams({left:button.dataset.chainLeft,right:button.dataset.chainRight,as_of:state.report.as_of,currency:state.report.currency,revision:state.report.identity_revision});
  const key=params.toString(),sequence=++s.sequence;s.controller=new AbortController();
  identityChainShell('<p role="status">Читаем сохранённые связи…</p>');
  try{
    const data=s.cache.get(key)||await api('/api/identity/trace?'+key,{signal:s.controller.signal});
    if(sequence!==s.sequence||s.owner!==button||!button.isConnected)return;
    s.data=data;s.cache.set(key,data);while(s.cache.size>12)s.cache.delete(s.cache.keys().next().value);
    identityChainShell(identityChainContent());
  }catch(error){
    if(sequence!==s.sequence||error.name==='AbortError')return;
    identityChainShell(`<p role="alert">${esc(error.message)}</p><button type="button" class="button" data-chain-reload>Обновить список совпадений</button>`);
  }
}
function identityChainLeave(){
  const s=identityChain;if(s.pinned)return;clearTimeout(s.timer);s.timer=setTimeout(()=>{if(!s.pinned&&!identityChainPopup.matches(':hover')&&!s.owner?.matches(':hover')&&!identityChainPopup.contains(document.activeElement)&&document.activeElement!==s.owner)identityChainHide();},220);
}
document.addEventListener('pointerover',event=>{
  const button=event.target.closest('.identity-chain-trigger');
  if(button){clearTimeout(identityChain.timer);if(identityChain.owner!==button)identityChain.timer=setTimeout(()=>identityChainShow(button),140);}
  else if(identityChainPopup.contains(event.target))clearTimeout(identityChain.timer);
});
document.addEventListener('pointerout',event=>{if(event.target.closest('.identity-chain-trigger')||identityChainPopup.contains(event.target))identityChainLeave();});
document.addEventListener('focusin',event=>{
  const button=event.target.closest('.identity-chain-trigger');if(button&&!identityChain.suppressFocus&&button.matches(':focus-visible'))identityChainShow(button);
  else if(!identityChainPopup.contains(event.target))identityChainHide();
});
document.addEventListener('click',event=>{
  const button=event.target.closest('.identity-chain-trigger');
  if(button){event.preventDefault();if(identityChain.owner===button&&identityChain.pinned)identityChainHide();else{identityChainShow(button,true);requestAnimationFrame(()=>{if(identityChain.owner===button&&identityChain.pinned)identityChainPopup.querySelector('[data-chain-close]')?.focus({preventScroll:true});});}return;}
  if(event.target.closest('[data-chain-close]')){identityChainHide(true);return;}
  if(event.target.closest('[data-chain-reload]')){identityChainHide();load();return;}
  if(identityChainPopup.contains(event.target)){identityChain.pinned=true;return;}
  identityChainHide();
});
document.addEventListener('change',event=>{if(event.target.id==='identity-chain-variant'&&identityChain.data){identityChain.variant=Number(event.target.value);identityChainShell(identityChainContent());identityChainPopup.querySelector('#identity-chain-variant')?.focus({preventScroll:true});}});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!identityChainPopup.hidden){event.preventDefault();event.stopImmediatePropagation();identityChainHide(true);}},true);
window.addEventListener('resize',()=>identityChainHide());
window.addEventListener('scroll',event=>{if(!identityChainPopup.contains(event.target))identityChainHide();},true);
