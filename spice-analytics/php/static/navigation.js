/* Browser entries contain opaque keys only. Filters, drafts and identities stay
   in memory and disappear on logout/reload; no notes go into URLs or storage. */
const uiNavigation={entries:new Map(),aliases:new Map(),current:null,drawer:null,returnToCRM:null,restoring:false,serial:0,session:crypto.randomUUID(),pendingDraft:null,backPending:false};
const navigationFields=['detailsOpen','regionalCitiesOpen','regionalPickerOpen','regionalTableOpen','regionalNotesOpen','regionalYears','regionalRegions','regionalWindow','regionalMeasure','regionalChannel','regionalExpanded','idsLabel','gender','view','page','search','reminder','periodOnly','sort','productMode','productSort','mapMode','geoMetric','ordersMin','ordersMax','ordersBasis','segment','ids','eligibleOnly','actionMin','geoSource','cohortCount','historyOpen','seasonMetric','seasonYears','duplicateKind','duplicateReview','duplicateSort','duplicateDir','duplicateCustomer','crmScope','crmTier','crmSort','crmDuplicates','manualTab','manualSegment','manualSearch'];
function navigationCustomerId(id){
 id=String(id);if(state.report?.customers.some(c=>c.id===id))return id;const seen=new Set();while(uiNavigation.aliases.has(id)&&!seen.has(id)){seen.add(id);id=uiNavigation.aliases.get(id);}return id;
}
function navigationCRMDraft(){
 if(uiNavigation.drawer?.kind!=='crm'||!document.querySelector('#crm-event-form'))return null;
 const forms={};for(const id of ['crm-event-form','crm-ai-form']){const form=document.getElementById(id);if(!form)continue;forms[id]={token:form.dataset.token,values:[...form.elements].filter(el=>el.name).map(el=>({name:el.name,value:el.value,checked:el.checked,type:el.type}))};}
 return {forms,scroll:document.getElementById('drawer').scrollTop};
}
function navigationRemember(){
 if(!uiNavigation.current)return;
 const old=uiNavigation.entries.get(uiNavigation.current),filters=Object.fromEntries(navigationFields.map(key=>[key,state[key]]));
 const snapshot={filters,period:document.getElementById('year').value,date:document.getElementById('as-of').value,currency:document.getElementById('currency').value,scroll:scrollY,drawer:uiNavigation.drawer?{...uiNavigation.drawer}:null,returnToCRM:uiNavigation.returnToCRM,draft:navigationCRMDraft()||old?.draft||null};
 uiNavigation.entries.set(uiNavigation.current,snapshot);
}
function navigationBefore(){if(!uiNavigation.restoring)navigationRemember();}
function navigationPush(){
 uiNavigation.current='lavka-'+uiNavigation.session+'-'+(++uiNavigation.serial);navigationRemember();uiNavigation.entries.get(uiNavigation.current).draft=null;history.pushState({lavkaUI:uiNavigation.current},'');
}
function navigationPage(){uiNavigation.drawer=null;uiNavigation.returnToCRM=null;navigationPush();}
function navigationDrawer(drawer){
 if(uiNavigation.restoring)return;
 if(uiNavigation.drawer?.kind===drawer.kind&&uiNavigation.drawer?.id===drawer.id)return;
 navigationBefore();uiNavigation.drawer=drawer;navigationPush();
}
function navigationHideDrawer(){++drawerSequence;hideHelp();document.getElementById('drawer').close();state.customer=null;}
function navigationCloseDrawer(){
 navigationBefore();navigationHideDrawer();
 if(uiNavigation.drawer&&!uiNavigation.backPending){uiNavigation.backPending=true;history.back();}
}
function navigationInit(){
 uiNavigation.current='lavka-'+uiNavigation.session+'-'+(++uiNavigation.serial);navigationRemember();history.replaceState({lavkaUI:uiNavigation.current},'');history.scrollRestoration='manual';
 window.addEventListener('popstate',async event=>{
  const target=uiNavigation.entries.get(event.state?.lavkaUI);if(!target)return;
  navigationBefore();uiNavigation.restoring=true;
  try{
   navigationHideDrawer();uiNavigation.current=event.state.lavkaUI;uiNavigation.drawer=target.drawer;uiNavigation.returnToCRM=target.returnToCRM;
   const changed=document.getElementById('year').value!==target.period||document.getElementById('as-of').value!==target.date||document.getElementById('currency').value!==target.currency||(state.report?.gender_filter||'all')!==(genderFilterViews.has(target.filters.view)?target.filters.gender:'all');
   Object.assign(state,target.filters);document.getElementById('year').value=target.period;document.getElementById('as-of').value=target.date;document.getElementById('currency').value=target.currency;
   if(changed||(!state.report&&state.view!=='overview'))await load();Object.assign(state,target.filters);render();
   if(state.view==='overview'&&!dashboardState.data)await dashboardLoad();
   if(state.view==='competitors'&&!competitorState.data)await competitorsLoad();
   if(target.drawer){const id=navigationCustomerId(target.drawer.id);uiNavigation.drawer={...target.drawer,id};uiNavigation.pendingDraft=target.draft;if(target.drawer.kind==='crm')await openCRM(id);else await openCustomer(id);}
   window.scrollTo({top:target.scroll,behavior:'auto'});
  }finally{uiNavigation.restoring=false;uiNavigation.backPending=false;}
 });
}
function navigationRestoreCRMDraft(id){
 const draft=uiNavigation.pendingDraft;uiNavigation.pendingDraft=null;if(!draft)return;
 for(const [key,saved] of Object.entries(draft.forms)){const form=document.getElementById(key);if(!form)continue;form.dataset.token=saved.token;
  for(const value of saved.values){const elements=[...form.elements].filter(el=>el.name===value.name);for(const element of elements){if(value.type==='checkbox'||value.type==='radio'){if(element.value===value.value)element.checked=value.checked;}else element.value=value.value;}}
 }
 document.getElementById('drawer').scrollTop=draft.scroll;
}
async function navigationCheckDuplicates(id){
 const fromCard=document.getElementById('drawer').open&&uiNavigation.drawer?.kind==='crm';
 const returnEntry=fromCard?uiNavigation.current:null,card=fromCard?crmCard:null;
 navigate('duplicates');uiNavigation.returnToCRM=returnEntry?{entry:returnEntry,id:String(id)}:null;
 if(card){document.getElementById('as-of').value=card.as_of;document.getElementById('currency').value=card.currency;}
 state.duplicateCustomer=String(id);state.duplicateKind='all';state.duplicateReview='pending';state.page=1;
 if(!state.report||state.report.as_of!==document.getElementById('as-of').value||state.report.currency!==document.getElementById('currency').value)await load();else render();
 navigationRemember();
}
function navigationReturnBanner(){
 const context=uiNavigation.returnToCRM;if(!context)return '';
 return `<div class="crm-return-banner"><div><strong>Проверка перед разговором</strong><p>После объединения вернём вас в карточку покупателя. Незаписанный текст останется в форме.</p></div><button class="button" data-action="crm-return">← Вернуться к разговору</button></div>`;
}
function navigationRememberMerge(rows){
 for(const id of new Set(rows.flatMap(row=>row.ids))){const customer=state.report?.customers.find(c=>c.id===id||c.order_ids.includes(id));if(customer&&customer.id!==id)uiNavigation.aliases.set(id,customer.id);}
}
function navigationReturnToCRM(){
 const context=uiNavigation.returnToCRM;if(!context||uiNavigation.backPending)return;
 // The duplicate report was pushed directly after its source card. Reuse that
 // entry so Back/Forward retain the originating page and queue filters.
 uiNavigation.backPending=true;history.back();
}
