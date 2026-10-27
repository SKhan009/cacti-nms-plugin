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
 review.querySelectorAll('[data-mib-color]').forEach(control=>{
  const picker=control.querySelector('[data-mib-color-picker]'),hex=control.querySelector('[data-mib-color-hex]');
  picker.addEventListener('input',()=>{hex.value=picker.value.slice(1).toUpperCase();hex.dispatchEvent(new Event('input',{bubbles:true}));});
  hex.addEventListener('input',()=>{if(/^[a-fA-F0-9]{6}$/.test(hex.value))picker.value='#'+hex.value;});
 });
 const eligible=objects.filter(object=>!object.querySelector('summary input').disabled),all=review.querySelector('[data-mib-select-all]');
 function selection(){const count=eligible.filter(object=>object.querySelector('summary input').checked).length;all.checked=count>0&&count===eligible.length;all.indeterminate=count>0&&count<eligible.length;all.disabled=eligible.length===0;review.querySelector('[data-mib-selected-count]').textContent=`${count} of ${eligible.length} numeric objects selected`;}
 function toggle(object){const checked=object.querySelector('summary input').checked;object.querySelectorAll('.mib-object-body input,.mib-object-body select').forEach(input=>input.disabled=!checked);}
 objects.forEach(object=>{
  const checkbox=object.querySelector('summary input');checkbox.addEventListener('click',e=>e.stopPropagation());checkbox.addEventListener('change',()=>{toggle(object);selection();});toggle(object);
  const index=object.querySelector('[data-mib-index]'),oid=object.querySelector('[data-base-oid]');
  index?.addEventListener('input',()=>{oid.value=index.value.trim()?oid.dataset.baseOid+'.'+index.value.trim():oid.dataset.baseOid;oid.setCustomValidity('');oid.removeAttribute('aria-invalid');oid.closest('.field')?.querySelector('.inline-feedback')?.remove();});
  oid?.addEventListener('input',()=>{oid.setCustomValidity('');if(index)index.value=oid.value.startsWith(oid.dataset.baseOid+'.')?oid.value.slice(oid.dataset.baseOid.length+1):'';});
 });
 function setSelection(rows,checked){rows.forEach(object=>{object.querySelector('summary input').checked=checked;toggle(object);});selection();}
 all.addEventListener('change',()=>setSelection(eligible,all.checked));review.querySelector('[data-mib-select-filtered]').addEventListener('click',()=>setSelection(eligible.filter(object=>matches.includes(object)),true));review.querySelector('[data-mib-clear]').addEventListener('click',()=>setSelection(eligible,false));selection();
 const graph=review.querySelector('[name="create_graph"]'),data=review.querySelector('[name="create_data"]');graph.addEventListener('change',()=>{if(graph.checked)data.checked=true;});data.addEventListener('change',()=>{if(!data.checked)graph.checked=false;});
 // Hidden pages remain in the form. Pack all selected edits before disabling individual fields.
 function reveal(object,input){objectSearch.value='';matches=objects;const limit=pager.querySelector('[data-mib-size]').value;objectPage=limit==='all'?1:Math.floor(objects.indexOf(object)/Number(limit))+1;renderObjects();object.open=true;input.reportValidity();input.focus();}
 review.addEventListener('submit',event=>{
  if(event.submitter?.value==='discard'){objects.forEach(object=>object.querySelectorAll('input,select').forEach(input=>input.disabled=true));return;}
  for(const object of eligible){
   if(!object.querySelector('summary input').checked)continue;
   const oid=object.querySelector('[data-base-oid]'),index=object.querySelector('[data-mib-index]');
   oid.value=oid.value.trim().replace(/^\./,'');
   if(index&&oid.value!==oid.dataset.baseOid&&oid.value!==''&&(!oid.value.startsWith(oid.dataset.baseOid+'.')||!/^\d+(?:\.\d+)*$/.test(oid.value.slice(oid.dataset.baseOid.length+1)))){
    event.preventDefault();oid.setCustomValidity('Enter a numeric instance index or keep the base OID for a reusable template.');reveal(object,oid);return;
   }
  }
  for(const input of review.querySelectorAll('input,select')){
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

// Search and inspect the saved repository without changing template selections.
(()=>{'use strict';
 const source=document.querySelector('#mibBrowserData');if(!source)return;
 const records=JSON.parse(source.textContent),tree=document.querySelector('#mibBrowserTree'),search=document.querySelector('#mibBrowserSearch'),leaves=[...tree.querySelectorAll('[data-mib-node]')],branches=[...tree.querySelectorAll('details')],empty=document.querySelector('#mibBrowserEmpty');
 let selected=null,priorOpen=null;
 function select(id){
  const record=records[id];if(!record)return;selected=id;
  leaves.forEach(leaf=>{const active=Number(leaf.dataset.mibNode)===id;leaf.classList.toggle('selected',active);leaf.setAttribute('aria-pressed',String(active));});
  document.querySelector('#mibBrowserName').textContent=record.name;
  document.querySelectorAll('[data-mib-property]').forEach(cell=>{const value=record[cell.dataset.mibProperty];cell.textContent=value===undefined||value===null||value===''?'—':String(value);});
  const badge=document.querySelector('#mibBrowserBadge');badge.hidden=false;badge.classList.toggle('not-created',!record.created);badge.querySelector('span').textContent=record.created?'Data Source Exists':'Data Source Not Created';
  const state=leaves.find(leaf=>Number(leaf.dataset.mibNode)===id)?.querySelector('.mib-browser-state svg');if(state)badge.querySelector('svg').replaceWith(state.cloneNode(true));
  const configure=document.querySelector('#mibBrowserConfigure');if(configure){configure.hidden=false;configure.href='protocols/snmp/mibs/controllers/mib_repository.php?wizard='+encodeURIComponent(record.bundle);}
 }
 leaves.forEach(leaf=>leaf.addEventListener('click',()=>select(Number(leaf.dataset.mibNode))));
 search.addEventListener('input',()=>{
  const query=search.value.trim().toLowerCase();if(query&&!priorOpen)priorOpen=branches.map(branch=>branch.open);
  let count=0;
  leaves.forEach(leaf=>{const record=records[Number(leaf.dataset.mibNode)];leaf.hidden=![record.name,record.module,record.base_oid].join(' ').toLowerCase().includes(query);if(!leaf.hidden)count++;});
  [...branches].reverse().forEach(branch=>{branch.hidden=![...branch.querySelectorAll('[data-mib-node]')].some(leaf=>!leaf.hidden);if(query&&!branch.hidden)branch.open=true;});
  if(!query&&priorOpen){branches.forEach((branch,i)=>branch.open=priorOpen[i]);priorOpen=null;}
  empty.hidden=count>0;
  if(count&&!leaves.some(leaf=>!leaf.hidden&&Number(leaf.dataset.mibNode)===selected))select(Number(leaves.find(leaf=>!leaf.hidden).dataset.mibNode));
 });
 if(leaves.length)select(Number(leaves[0].dataset.mibNode));
})();

(()=>{'use strict';
 const dialog=document.querySelector('#mibUploadDialog');if(!dialog)return;
 const title=document.querySelector('#mibUploadTitle'),chooser=document.querySelector('#mibUploadChooser'),progress=document.querySelector('#mibUploadProgress'),bar=document.querySelector('#mibUploadBar'),count=document.querySelector('#mibUploadCount'),filename=document.querySelector('#mibUploadFilename'),error=document.querySelector('#mibUploadError');
 let busy=false,returnFocus=null;
 document.querySelectorAll('[data-mib-upload-open]').forEach(link=>link.addEventListener('click',event=>{if(event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||event.button!==0)return;event.preventDefault();returnFocus=link;if(!busy){title.textContent='Upload MIB';if(chooser)chooser.hidden=false;progress.hidden=true;error.hidden=true;}dialog.showModal();}));
 dialog.querySelectorAll('[data-mib-upload-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
 dialog.addEventListener('close',()=>{if(returnFocus?.isConnected)returnFocus.focus();});
 document.querySelectorAll('[data-mib-upload-form]').forEach(form=>form.addEventListener('submit',event=>{
  event.preventDefault();if(busy||!form.reportValidity())return;
  const files=[...form.querySelector('[type=file]').files];if(!files.length)return;
  busy=true;returnFocus=form.querySelector('[type=submit]');returnFocus.disabled=true;title.textContent='Uploading MIB';error.hidden=true;if(chooser)chooser.hidden=true;progress.hidden=false;bar.value=0;
  filename.textContent='Uploading “'+files.map(file=>file.name).join(', ')+'”';count.textContent='Uploading file…';if(!dialog.open)dialog.showModal();
  const body=new FormData(form);body.set('upload_response','json');
  const xhr=new XMLHttpRequest();xhr.open('POST',new URL(form.getAttribute('action')||location.href,document.baseURI).href,true);xhr.responseType='json';xhr.timeout=180000;xhr.setRequestHeader('Accept','application/json');
  function fail(message){busy=false;returnFocus.disabled=false;title.textContent='Upload MIB';if(chooser)chooser.hidden=false;progress.hidden=true;error.textContent=message;error.hidden=false;if(!dialog.open)dialog.showModal();}
  xhr.upload.addEventListener('progress',event=>{if(event.lengthComputable){bar.value=event.loaded/event.total*100;count.textContent='Uploading file: '+Math.round(bar.value)+'%';}});
  xhr.upload.addEventListener('load',()=>{bar.removeAttribute('value');count.textContent='File uploaded. Scanning OIDs…';});
  xhr.addEventListener('load',()=>{
   const result=xhr.response;if(xhr.status<200||xhr.status>=300||!result?.ok){fail(result?.error||'Upload could not be completed. Check the file and your session, then retry.');return;}
   const next=new URL(result.redirect,document.baseURI);if(next.origin!==location.origin||!next.pathname.endsWith('/protocols/snmp/mibs/controllers/mib_repository.php')){fail('The upload returned an invalid destination. Reopen the MIB Repository.');return;}
   bar.value=100;const total=Number(result.object_count)||0;count.textContent=total+'/'+total+' OIDs Uploaded & Scanned';title.textContent='MIB Uploaded';
   setTimeout(()=>location.assign(next.href),700);
  });
  xhr.addEventListener('error',()=>fail('The connection was interrupted. Reopen the MIB Repository to check whether the file was saved before retrying.'));
  xhr.addEventListener('timeout',()=>fail('The upload response timed out. Reopen the MIB Repository to check whether the file was saved before retrying.'));
  xhr.send(body);
 }));
})();
