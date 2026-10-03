(()=>{'use strict';
const review=document.querySelector('#mibReview');
const objects=[...document.querySelectorAll('[data-mib-object]')],objectSearch=document.querySelector('[data-mib-search]'),pager=document.querySelector('[data-mib-pagination]');
let objectPage=1,matches=objects;
function renderObjects(){
 if(!pager)return;
 const size=pager.querySelector('[data-mib-size]').value,limit=size==='all'?Math.max(1,matches.length):Number(size),pages=Math.max(1,Math.ceil(matches.length/limit));
 objectPage=Math.max(1,Math.min(objectPage,pages));objects.forEach(object=>object.hidden=true);
 matches.slice((objectPage-1)*limit,objectPage*limit).forEach(object=>object.hidden=false);
 pager.querySelector('[data-mib-range]').textContent=(matches.length?`${(objectPage-1)*limit+1}–${Math.min(objectPage*limit,matches.length)}`:'0')+` of ${matches.length} objects`;
 pager.querySelector('[data-mib-page]').replaceChildren(...Array.from({length:pages},(_,i)=>new Option(String(i+1),String(i+1),false,i+1===objectPage)));
 pager.querySelector('[data-mib-pages]').textContent=`of ${pages} pages`;
 pager.querySelector('[data-mib-prev]').disabled=objectPage===1;pager.querySelector('[data-mib-next]').disabled=objectPage===pages;
}
function filterObjects(){objectPage=1;matches=objects.filter(object=>object.textContent.toLowerCase().includes(objectSearch?.value.trim().toLowerCase()||''));renderObjects();}
if(pager){pager.querySelector('[data-mib-size]').addEventListener('change',()=>{objectPage=1;renderObjects();});pager.querySelector('[data-mib-page]').addEventListener('change',e=>{objectPage=Number(e.target.value);renderObjects();});pager.querySelector('[data-mib-prev]').addEventListener('click',()=>{objectPage--;renderObjects();});pager.querySelector('[data-mib-next]').addEventListener('click',()=>{objectPage++;renderObjects();});}
objectSearch?.addEventListener('input',filterObjects);filterObjects();
if(review){
 review.querySelectorAll('[data-mib-instance]').forEach(select=>select.addEventListener('change',()=>{
  if(!select.value)return;const values=JSON.parse(select.value);
  for(const [key,value] of Object.entries(values)){const input=review.querySelector(`[name="records[${select.dataset.record}][${key}]"]`);if(input){input.value=value;input.setCustomValidity('');}}
 }));
 const eligible=objects.filter(object=>!object.querySelector('summary input').disabled),all=review.querySelector('[data-mib-select-all]');
 function selection(){const count=eligible.filter(object=>object.querySelector('summary input').checked).length;all.checked=count>0&&count===eligible.length;all.indeterminate=count>0&&count<eligible.length;all.disabled=eligible.length===0;review.querySelector('[data-mib-selected-count]').textContent=`${count} of ${eligible.length} numeric objects selected`;}
 function toggle(object){const checked=object.querySelector('summary input').checked;object.querySelectorAll('.mib-object-body input,.mib-object-body select').forEach(input=>input.disabled=!checked);}
 objects.forEach(object=>{
  const checkbox=object.querySelector('summary input');checkbox.addEventListener('click',e=>e.stopPropagation());checkbox.addEventListener('change',()=>{toggle(object);selection();});toggle(object);
  const index=object.querySelector('[data-mib-index]'),oid=object.querySelector('[data-base-oid]');
  index?.addEventListener('input',()=>{oid.value=index.value.trim()?oid.dataset.baseOid+'.'+index.value.trim():'';oid.setCustomValidity('');oid.removeAttribute('aria-invalid');oid.closest('.field')?.querySelector('.inline-feedback')?.remove();});
  oid?.addEventListener('input',()=>{oid.setCustomValidity('');if(index)index.value=oid.value.startsWith(oid.dataset.baseOid+'.')?oid.value.slice(oid.dataset.baseOid.length+1):'';});
 });
 function setSelection(rows,checked){rows.forEach(object=>{object.querySelector('summary input').checked=checked;toggle(object);});selection();}
 all.addEventListener('change',()=>setSelection(eligible,all.checked));review.querySelector('[data-mib-select-filtered]').addEventListener('click',()=>setSelection(eligible.filter(object=>matches.includes(object)),true));review.querySelector('[data-mib-clear]').addEventListener('click',()=>setSelection(eligible,false));selection();
 const graph=review.querySelector('[name="create_graph"]'),data=review.querySelector('[name="create_data"]');graph.addEventListener('change',()=>{if(graph.checked)data.checked=true;});data.addEventListener('change',()=>{if(!data.checked)graph.checked=false;});
 // Hidden pages remain in the form. Pack all selected edits before disabling individual fields.
 function reveal(object,input){objectSearch.value='';matches=objects;const limit=pager.querySelector('[data-mib-size]').value;objectPage=limit==='all'?1:Math.floor(objects.indexOf(object)/Number(limit))+1;renderObjects();object.open=true;input.reportValidity();input.focus();}
 review.addEventListener('submit',event=>{
  if(event.submitter?.value==='discard'){objects.forEach(object=>object.querySelectorAll('input,select').forEach(input=>input.disabled=true));return;}
  if(event.submitter?.value!=='fetch_inputs')for(const object of eligible){
   if(!object.querySelector('summary input').checked)continue;
   const oid=object.querySelector('[data-base-oid]'),index=object.querySelector('[data-mib-index]');
   oid.value=oid.value.trim().replace(/^\./,'');
   if(index&&(!oid.value.startsWith(oid.dataset.baseOid+'.')||!/^\d+(?:\.\d+)*$/.test(oid.value.slice(oid.dataset.baseOid.length+1)))){
    event.preventDefault();oid.setCustomValidity('Enter the actual device instance index or a full instance OID.');reveal(object,oid);return;
   }
  }
  if(event.submitter?.value!=='fetch_inputs')for(const input of review.querySelectorAll('input,select')){
   if(input.disabled||input.checkValidity())continue;
   event.preventDefault();const object=input.closest('[data-mib-object]');if(object)reveal(object,input);else{input.reportValidity();input.focus();}return;
  }
  const payload={selected:{},records:{}};
  for(const [name,value] of new FormData(review)){
   let match=name.match(/^selected\[(\d+)\]$/);if(match){payload.selected[match[1]]=value;continue;}
   match=name.match(/^records\[(\d+)\]\[(\w+)\]$/);if(match){payload.records[match[1]]??={};payload.records[match[1]][match[2]]=value;}
  }
  let packed=review.querySelector('[name="review_payload"]');if(!packed){packed=document.createElement('input');packed.type='hidden';packed.name='review_payload';review.append(packed);}packed.value=JSON.stringify(payload);
  objects.forEach(object=>object.querySelectorAll('input,select').forEach(input=>input.disabled=true));
 });
}
const search=document.querySelector('#mibSearch');if(!search)return;
const type=document.querySelector('#mibType'),size=document.querySelector('#mibSize'),pageSelect=document.querySelector('#mibPage'),previous=document.querySelector('#mibPrevious'),next=document.querySelector('#mibNext'),rows=[...document.querySelectorAll('#mibFiles tr[data-type]')];let page=1,filtered=[];
function render(){const limit=Number(size.value),pages=Math.max(1,Math.ceil(filtered.length/limit));page=Math.max(1,Math.min(page,pages));rows.forEach(row=>row.hidden=true);filtered.slice((page-1)*limit,page*limit).forEach(row=>row.hidden=false);document.querySelector('#mibEmpty').hidden=filtered.length>0;document.querySelector('#mibRange').textContent=(filtered.length?`${(page-1)*limit+1}–${Math.min(page*limit,filtered.length)}`:'0')+` of ${filtered.length} files`;pageSelect.replaceChildren(...Array.from({length:pages},(_,i)=>new Option(String(i+1),String(i+1),false,i+1===page)));document.querySelector('#mibPageCount').textContent=`of ${pages} pages`;previous.disabled=page===1;next.disabled=page===pages;}
function filter(){page=1;filtered=rows.filter(row=>(!type.value||row.dataset.type===type.value)&&row.textContent.toLowerCase().includes(search.value.trim().toLowerCase()));render();}
search.addEventListener('input',filter);type.addEventListener('change',filter);size.addEventListener('change',filter);pageSelect.addEventListener('change',()=>{page=Number(pageSelect.value);render();});previous.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});filter();
})();
