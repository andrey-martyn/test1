/* Exact reconstruction, numeric edits, Cyrillic, escaping and bounded work. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const context={esc:value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])),competitorDate:value=>value};
vm.createContext(context);vm.runInContext(fs.readFileSync(__dirname+'/../static/competitor-diff.js','utf8'),context);
const {competitorTextDiff:diff,competitorDiffText:normalize,competitorDiffMarkup:html}=context;
function exact(before,after){
  const result=diff(before,after);
  assert.equal(result.parts.filter(p=>p.kind!=='added').map(p=>p.text).join(''),normalize(before));
  assert.equal(result.parts.filter(p=>p.kind!=='removed').map(p=>p.text).join(''),normalize(after));
  assert.equal(result.changed,normalize(before)!==normalize(after));return result;
}
let result=exact('Мінімальне замовлення 100 грн. Доставка 60 грн.','Мінімальне замовлення 200 грн. Доставка 90 грн.');
assert.equal(result.parts.filter(p=>p.kind==='removed').map(p=>p.text).join(''),'10060');
assert.equal(result.parts.filter(p=>p.kind==='added').map(p=>p.text).join(''),'20090');
assert(!result.coarse);
for(const [a,b] of [['','новые условия'],['больше не доставляем',''],['раз два раз три','раз три раз два'],['🙂 Доставка Нова пошта','🙂 Самовивіз'],['e\u0301 € 10,50','e\u0301 € 12,50'],['a a a b','a a b a'],[' A\n B\tC ','A B C'],['текст','текст']])exact(a,b);
assert(!diff('Доставка\n\t100 грн.','Доставка 100 грн.').changed);
const hostile='<img src=x onerror=alert(1)> & <script>alert(2)</script>';
result=exact('Безопасный текст',hostile);
assert(!html(result).includes('<img'));assert(!html(result).includes('<script>'));assert(html(result).includes('&lt;'));
let seed=17;const random=()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed;};
const alphabet=['а','б','в','100','200',' ','🙂','<','&','Нова','пошта'];
for(let trial=0;trial<1800;trial++){
  const text=()=>Array.from({length:random()%35},()=>alphabet[random()%alphabet.length]).join('');
  exact(text(),text());
}
result=exact('обычная доставка '.repeat(300)+'100 грн. '+'самовывоз '.repeat(300),'обычная доставка '.repeat(300)+'200 грн. '+'самовывоз '.repeat(300));
assert(!result.coarse);assert(html(result).length<2000);assert(html(result).includes('100'));assert(html(result).includes('200'));
result=exact(Array.from({length:1800},(_,i)=>'старое'+i).join(' '),Array.from({length:1800},(_,i)=>'новое'+i).join(' '));
assert(result.coarse);assert(html(result).includes('существенно переписана'));
const rows=[{checked_at:'new',observation:{text:'Новая доставка'}},{checked_at:'old',observation:{text:'Прежняя доставка'}}];
assert(context.competitorSnapshotText(rows,0,true).includes('data-snapshot-diff="0" open'));
assert(context.competitorSnapshotText(rows,1).includes('Предыдущего снимка в этой истории нет'));
assert(!context.competitorSnapshotChanged([{observation:{text:'a b'}},{observation:{text:'a\n b'}}],0));
assert(!context.competitorSelectedChange({before:{},after:{text:'нет исходного текста'}}));
console.log(JSON.stringify({exact_reconstruction:1800,numeric_and_cyrillic_changes:'passed',html_escaping:'passed',whitespace_and_baseline:'passed',long_pages:'bounded'}));
