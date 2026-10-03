(()=>{'use strict';
const review=document.querySelector('#mibReview');
if(review){
 review.querySelectorAll('[data-mib-object]').forEach(object=>{const checkbox=object.querySelector('summary input');checkbox.addEventListener('click',e=>e.stopPropagation());const toggle=()=>object.querySelectorAll('.mib-object-body input,.mib-object-body select').forEach(input=>input.disabled=!checkbox.checked);checkbox.addEventListener('change',toggle);toggle();});

 const graph=review.querySelector('[name="create_graph"]'),data=review.querySelector('[name="create_data"]');graph.addEventListener('change',()=>{if(graph.checked)data.checked=true;});data.addEventListener('change',()=>{if(!data.checked)graph.checked=false;});
}
const objectSearch=document.querySelector('[data-mib-search]');if(objectSearch)objectSearch.addEventListener('input',()=>document.querySelectorAll('[data-mib-object]').forEach(object=>object.hidden=!object.textContent.toLowerCase().includes(objectSearch.value.toLowerCase())));
const search=document.querySelector('#mibSearch');if(!search)return;
const type=document.querySelector('#mibType'),size=document.querySelector('#mibSize'),pageSelect=document.querySelector('#mibPage'),previous=document.querySelector('#mibPrevious'),next=document.querySelector('#mibNext'),rows=[...document.querySelectorAll('#mibFiles tr[data-type]')];let page=1,filtered=[];
function render(){const limit=Number(size.value),pages=Math.max(1,Math.ceil(filtered.length/limit));page=Math.max(1,Math.min(page,pages));rows.forEach(row=>row.hidden=true);filtered.slice((page-1)*limit,page*limit).forEach(row=>row.hidden=false);document.querySelector('#mibEmpty').hidden=filtered.length>0;document.querySelector('#mibRange').textContent=(filtered.length?`${(page-1)*limit+1}–${Math.min(page*limit,filtered.length)}`:'0')+` of ${filtered.length} files`;pageSelect.replaceChildren(...Array.from({length:pages},(_,i)=>new Option(String(i+1),String(i+1),false,i+1===page)));document.querySelector('#mibPageCount').textContent=`of ${pages} pages`;previous.disabled=page===1;next.disabled=page===pages;}
function filter(){page=1;filtered=rows.filter(row=>(!type.value||row.dataset.type===type.value)&&row.textContent.toLowerCase().includes(search.value.trim().toLowerCase()));render();}
search.addEventListener('input',filter);type.addEventListener('change',filter);size.addEventListener('change',filter);pageSelect.addEventListener('change',()=>{page=Number(pageSelect.value);render();});previous.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});filter();
})();
