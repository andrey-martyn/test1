/* Compare saved text only. Never fetch a competitor page or change a decision. */
function competitorDiffText(value){return String(value??'').replace(/\s+/gu,' ').trim();}
function competitorDiffTokens(text){return text.match(/[\p{L}\p{M}\p{N}]+|\s+|[^\p{L}\p{M}\p{N}\s]/gu)||[];}
function competitorTextDiff(before,after){
  const a=competitorDiffTokens(competitorDiffText(before)),b=competitorDiffTokens(competitorDiffText(after));
  let start=0,end=0;
  while(start<a.length&&start<b.length&&a[start]===b[start])start++;
  while(end<a.length-start&&end<b.length-start&&a[a.length-1-end]===b[b.length-1-end])end++;
  const left=a.slice(start,a.length-end),right=b.slice(start,b.length-end),parts=[];
  const append=(kind,text)=>{if(!text)return;if(parts.at(-1)?.kind===kind)parts.at(-1).text+=text;else parts.push({kind,text});};
  append('same',a.slice(0,start).join(''));
  let edits=[],coarse=false;
  if(!left.length)edits=right.map(text=>({kind:'added',text}));
  else if(!right.length)edits=left.map(text=>({kind:'removed',text}));
  else{
    // Bounded Myers word diff. A completely rewritten page cannot freeze the UI.
    const frontier=new Map([[1,0]]),trace=[];let work=0,found=false;
    search:for(let distance=0;distance<=Math.min(left.length+right.length,700);distance++){
      trace.push(new Map(frontier));
      for(let diagonal=-distance;diagonal<=distance;diagonal+=2){
        let x=diagonal===-distance||(diagonal!==distance&&(frontier.get(diagonal-1)??-1)<(frontier.get(diagonal+1)??-1))?(frontier.get(diagonal+1)??0):(frontier.get(diagonal-1)??0)+1;
        let y=x-diagonal;
        while(x<left.length&&y<right.length&&left[x]===right[y]){x++;y++;if(++work>500000)break search;}
        if(++work>500000)break search;
        frontier.set(diagonal,x);
        if(x>=left.length&&y>=right.length){found=true;break search;}
      }
    }
    if(found){
      let x=left.length,y=right.length;
      for(let distance=trace.length-1;distance>=0;distance--){
        const previous=trace[distance],diagonal=x-y;
        const priorDiagonal=diagonal===-distance||(diagonal!==distance&&(previous.get(diagonal-1)??-1)<(previous.get(diagonal+1)??-1))?diagonal+1:diagonal-1;
        const priorX=previous.get(priorDiagonal)??0,priorY=priorX-priorDiagonal;
        while(x>priorX&&y>priorY){edits.push({kind:'same',text:left[--x]});y--;}
        if(!distance)break;
        if(x===priorX)edits.push({kind:'added',text:right[--y]});
        else edits.push({kind:'removed',text:left[--x]});
      }
      edits.reverse();
    }else{coarse=true;edits=[{kind:'removed',text:left.join('')},{kind:'added',text:right.join('')}];}
  }
  edits.forEach(part=>append(part.kind,part.text));
  append('same',end?a.slice(a.length-end).join(''):'');
  return {parts,changed:parts.some(part=>part.kind!=='same'),coarse};
}
function competitorDiffMarkup(diff){
  if(!diff.changed)return '<p class="competitor-diff-unchanged">Текст не изменился. Пробелы и переносы строк не учитываются.</p>';
  const context=110;
  const text=diff.parts.map((part,index)=>{
    if(part.kind!=='same')return `<${part.kind==='added'?'ins':'del'} class="competitor-diff-${part.kind}" title="${part.kind==='added'?'Добавлено':'Убрано'}">${esc(part.text)}</${part.kind==='added'?'ins':'del'}>`;
    const chars=Array.from(part.text),gap='<span class="competitor-diff-gap" aria-label="Неизменённый текст пропущен"> […] </span>';
    if(chars.length<=context*2)return esc(part.text);
    if(index===0)return gap+esc(chars.slice(-context).join(''));
    if(index===diff.parts.length-1)return esc(chars.slice(0,context).join(''))+gap;
    return esc(chars.slice(0,context).join(''))+gap+esc(chars.slice(-context).join(''));
  }).join('');
  return `<div class="competitor-diff-legend"><span><ins>Добавили</ins></span><span><del>Убрали</del></span><span>Без фона — прежний текст</span></div>${diff.coarse?'<p class="competitor-muted">Страница существенно переписана: изменённый фрагмент показан целиком.</p>':''}<div class="competitor-text-diff">${text}</div><p class="competitor-diff-note">Сравнение сохранённых текстов. Длинные неизменённые участки сокращены; полный текст доступен ниже.</p>`;
}
function competitorHistoryText(row){const observation=row?.observation||row?.data||row;return typeof observation?.text==='string'?observation.text:null;}
function competitorHistoryStamp(row){return row?.checked_at||row?.observed_at||row?.created_at||row?.at;}
function competitorSnapshotChanged(rows,index){
  const text=competitorHistoryText(rows[index]),before=competitorHistoryText(rows[index+1]);
  return text!==null&&before!==null&&competitorDiffText(text)!==competitorDiffText(before);
}
function competitorSnapshotText(rows,index,open=false){
  const text=competitorHistoryText(rows[index]);if(text===null||text==='')return '';
  const before=competitorHistoryText(rows[index+1]);
  const comparison=before===null?'<p class="competitor-muted">Предыдущего снимка в этой истории нет — сравнение недоступно.</p>':competitorSnapshotChanged(rows,index)?`<details class="competitor-snapshot-diff" data-snapshot-diff="${index}"${open?' open':''}><summary>Что изменилось с ${esc(competitorDate(competitorHistoryStamp(rows[index+1])))}</summary><div class="competitor-snapshot-diff-body"></div></details>`:`<p class="competitor-diff-unchanged">Текст не изменился с ${esc(competitorDate(competitorHistoryStamp(rows[index+1])))}.</p>`;
  return comparison+`<details class="competitor-snapshot-original"><summary>Весь текст страницы в этом снимке</summary><pre>${esc(text)}</pre></details>`;
}
function competitorSelectedChange(change){
  if(typeof change?.before?.text!=='string'||typeof change?.after?.text!=='string')return '';
  return `<section class="competitor-selected-change" data-change-id="${Number(change.id)}"><h3>Что изменилось ${esc(competitorDate(change.occurred_at))}</h3>${competitorDiffMarkup(competitorTextDiff(change.before.text,change.after.text))}<details class="competitor-snapshot-original"><summary>Полные тексты: до и после изменения</summary><h4>До изменения</h4><pre>${esc(change.before.text)}</pre><h4>После изменения</h4><pre>${esc(change.after.text)}</pre></details></section>`;
}
function competitorHydrateSnapshotDiff(root,rows){
  const render=details=>{
    if(!details.matches?.('details[data-snapshot-diff]')||!details.open||details.dataset.rendered)return;
    const index=Number(details.dataset.snapshotDiff);
    details.querySelector('.competitor-snapshot-diff-body').innerHTML=competitorDiffMarkup(competitorTextDiff(competitorHistoryText(rows[index+1]),competitorHistoryText(rows[index])));
    details.dataset.rendered='true';
  };
  root.addEventListener('toggle',event=>render(event.target),true);
  root.querySelectorAll('details[data-snapshot-diff][open]').forEach(render);
}
