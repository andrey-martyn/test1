function geoSourcesLabel(location){
 const sources=Object.entries(location.sources||{});
 return sources.length>1?sources.map(([label,count])=>label+': '+num(count)).join(' · '):location.source;
}
function geoVariants(location){
 const variants=Object.entries(location.variants||{});
 if(!variants.length||(variants.length===1&&variants[0][0]===location.city))return '';
 return `<details class="geo-variants"><summary>Написания: ${num(location.variant_count||variants.length)}</summary><ul>${variants.map(([label,count])=>`<li>${esc(label)} · ${num(count)} заказов</li>`).join('')}</ul>${location.variant_count>variants.length?'<p>Показаны первые 20 вариантов.</p>':''}</details>`;
}
function geoAuditView(){
 const q=state.report?.geo_quality;if(!q?.modes?.mixed)return '';
 return `<details class="report-detail geo-diagnostics"><summary>Техническая проверка адресов${q.needs_review?' · '+num(q.needs_review)+' для уточнения':''}</summary><div class="geo-diagnostics-body"><p>Самовывоз в Одесе: ${num(q.pickup_odesa)} заказов. После восстановления без города: ${num(q.modes.mixed.city_missing)}; без области: ${num(q.modes.mixed.region_missing)}. Населённый пункт вне справочника с указанной областью учитывается в этой области.</p>${q.needs_review?`<div class="table-scroll"><table><thead><tr><th>Заказ</th><th>Доставка</th><th>Место</th><th>Что проверить</th></tr></thead><tbody>${q.unknown_orders.map(row=>`<tr><td><button class="button" data-geo-order="${esc(row.id)}">№ ${esc(row.id)}</button></td><td>${esc(row.shipping_method||'Не указан')}</td><td>${esc(row.city)}, ${esc(row.zone||'Область не определена')}</td><td>${esc(row.reason)}</td></tr>`).join('')}</tbody></table></div>`:'<p>Критичных пропусков для текущей проверки нет.</p>'}</div></details>`;
}

document.addEventListener('click',async event=>{
 const button=event.target.closest('[data-geo-order]');if(!button)return;
 openDrawer('ПРОВЕРКА ГЕОГРАФИИ ЗАКАЗА');const sequence=drawerSequence;
 try{const order=await api('/api/orders/'+encodeURIComponent(button.dataset.geoOrder));if(sequence===drawerSequence&&document.getElementById('drawer').open){const geo=order.geography,resolved=geo.resolved;document.getElementById('drawer-content').innerHTML=`<section class="geo-order-context"><h2>География заказа № ${esc(order.id)}</h2><dl><dt>Город доставки в заказе</dt><dd>${esc(geo.shipping_city||'Не заполнен')}</dd><dt>Область доставки в заказе</dt><dd>${esc(geo.shipping_zone||'Не заполнена')}</dd><dt>Город и область оплаты</dt><dd>${esc([geo.payment_city,geo.payment_zone].filter(Boolean).join(', ')||'Не заполнены')}</dd><dt>В статистике</dt><dd>${esc(resolved.city)}, ${esc(resolved.zone||'область не определена')} · ${esc(resolved.source)}</dd></dl></section>`+orderHTML(order,true);enhanceHelp(document.getElementById('drawer'));}}
 catch(error){if(sequence===drawerSequence&&document.getElementById('drawer').open)document.getElementById('drawer-content').innerHTML='<div class="notice">'+esc(error.message)+'</div>';}
});
