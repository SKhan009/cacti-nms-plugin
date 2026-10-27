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
  const badge=document.querySelector('#mibBrowserBadge');badge.hidden=false;badge.dataset.sourceState=record.source_state;badge.querySelector('span').textContent=record.source_status;badge.title=record.source_count?record.created_count+' of '+record.source_count+' supported OIDs created':'';
  const state=leaves.find(leaf=>Number(leaf.dataset.mibNode)===id)?.querySelector('.mib-browser-state svg');if(state)badge.querySelector('svg').replaceWith(state.cloneNode(true));
  const create=document.querySelector('#mibBrowserCreate');if(create){create.disabled=!record.can_create;create.title=record.can_create?'Open Step 2 — Data Source Generation':(record.source_state==='exists'?'Data sources already exist.':'This object has no supported numeric OIDs.');create.onclick=()=>location.assign('protocols/snmp/mibs/controllers/mib_repository.php?select='+encodeURIComponent(record.bundle)+'&object='+encodeURIComponent(record.object_index));}
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
 if(leaves.length){const initial=source.dataset.initialObject;const target=initial===''?null:records.findIndex(record=>record.object_index===Number(initial));select(target!==null&&target>=0?target:Number(leaves[0].dataset.mibNode));}
})();

(()=>{'use strict';
 const dialog=document.querySelector('#mibUploadDialog');if(!dialog)return;
 const title=document.querySelector('#mibUploadTitle'),chooser=document.querySelector('#mibUploadChooser'),progress=document.querySelector('#mibUploadProgress'),bar=document.querySelector('#mibUploadBar'),count=document.querySelector('#mibUploadCount'),filename=document.querySelector('#mibUploadFilename'),error=document.querySelector('#mibUploadError');
 const intro=document.querySelector('#mibUploadIntro'),nextStep=document.querySelector('#mibUploadNext'),nextForm=document.querySelector('#mibUploadNextForm'),proceed=document.querySelector('#mibUploadProceed');
 let busy=false,returnFocus=null,completed=null;
 function choice(){proceed.textContent=nextForm.querySelector('[name=upload_next]:checked').value==='repository'?'Save':'Proceed';}
 nextForm.addEventListener('change',choice);
 nextForm.addEventListener('submit',event=>{event.preventDefault();if(!completed)return;const repository=nextForm.querySelector('[name=upload_next]:checked').value==='repository';location.assign(repository?completed.repository.href:completed.configure.href);});
 document.querySelectorAll('[data-mib-upload-open]').forEach(link=>link.addEventListener('click',event=>{if(event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||event.button!==0)return;event.preventDefault();returnFocus=link;if(!busy&&!completed){title.textContent='Upload MIB';if(chooser)chooser.hidden=false;progress.hidden=true;error.hidden=true;}dialog.showModal();}));
 dialog.querySelectorAll('[data-mib-upload-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
 dialog.addEventListener('close',()=>{if(completed){location.assign(completed.repository.href);return;}if(returnFocus?.isConnected)returnFocus.focus();});
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
   const repository=new URL(result.repository||'protocols/snmp/mibs/controllers/mib_repository.php',document.baseURI);
   if(repository.origin!==location.origin||repository.pathname!==next.pathname){fail('The upload returned an invalid repository destination.');return;}
   const summary=result.summary||{name:files.map(file=>file.name).join(', '),object_count:total,supported_count:0,existing_count:0,can_configure:false};
   completed={configure:next,repository};
   setTimeout(()=>{
    busy=false;returnFocus.disabled=false;progress.hidden=true;intro.hidden=true;nextStep.hidden=false;dialog.classList.add('mib-upload-next-step');title.textContent='MIB Upload Successful — Choose Next Step';
    nextStep.querySelectorAll('[data-upload-summary]').forEach(cell=>cell.textContent=String(summary[cell.dataset.uploadSummary]??'—'));
    const automatic=nextForm.querySelector('[value=automatic]'),repositoryChoice=nextForm.querySelector('[value=repository]');automatic.disabled=!summary.can_configure;automatic.checked=!!summary.can_configure;repositoryChoice.checked=!summary.can_configure;choice();
    if(summary.parse_error){error.textContent=summary.parse_error;error.hidden=false;}
    if(!dialog.open)dialog.showModal();
   },700);
  });
  xhr.addEventListener('error',()=>fail('The connection was interrupted. Reopen the MIB Repository to check whether the file was saved before retrying.'));
  xhr.addEventListener('timeout',()=>fail('The upload response timed out. Reopen the MIB Repository to check whether the file was saved before retrying.'));
  xhr.send(body);
 }));
})();

(()=>{'use strict';
 const form=document.querySelector('#mibGenerationForm');if(!form)return;
 const button=document.querySelector('#mibGenerate'),progress=document.querySelector('#mibGenerationProgress'),status=document.querySelector('#mibGenerationStatus'),bar=document.querySelector('#mibGenerationBar'),error=document.querySelector('#mibGenerationError'),spinner=document.querySelector('.mib-generation-spinner');let busy=false;
 const review=form.querySelector('.mib-generation-review'),rows=[...form.querySelectorAll('[data-generation-row]')],panels=[...form.querySelectorAll('[data-generation-panel]')];
 function openPanel(id){panels.forEach(panel=>panel.hidden=panel.dataset.generationPanel!==id);rows.forEach(row=>{const active=row.dataset.generationRow===id;row.classList.toggle('active',active);row.querySelector('button').setAttribute('aria-expanded',String(active));});document.querySelector('#mibGenerationEmpty').hidden=true;}
 function collection(panel){const method=panel.querySelector('[name$="[collection_method]"]'),index=panel.querySelector('[data-mib-index]'),oid=panel.querySelector('[data-base-oid]');if(!method||!oid)return;const indexed=method.value==='indexed';oid.readOnly=indexed;if(index){index.closest('.field').hidden=indexed;index.disabled=method.disabled||indexed;if(indexed){index.value='';oid.value=oid.dataset.baseOid;}}}
 function selection(){let count=0;rows.forEach(row=>{const checked=row.querySelector('input').checked;if(checked)count++;form.querySelector('[data-generation-panel="'+row.dataset.generationRow+'"]').querySelectorAll('input,select').forEach(input=>input.disabled=!checked);});panels.forEach(collection);button.disabled=count===0;document.querySelector('#mibGenerationSelected').textContent=count+' OIDs selected';}
 if(review){
  form.noValidate=true;
  panels.forEach(panel=>{
   panel.querySelector('[name$="[collection_method]"]')?.addEventListener('change',()=>collection(panel));
   const help={'Data Source - Template Name *':'Name saved in native Cacti data templates.','Collection Method':'Installed Cacti SNMP input method selected from the OID definition.','Polling Interval / Cacti Profile':'Collection profiles configured in Cacti.','Data Source Active':'Enable or disable this Cacti data source template.','Polling OID *':'Numeric OID parsed from the MIB.','Data Source Type':'How RRDTool interprets the collected value.','Units':'Units declared by the MIB, when available.','RRD Minimum (U = unknown)':'Use the MIB bound or U for an unknown minimum.','RRD Maximum (U = unknown)':'Use the MIB bound or U for an unknown maximum.'};
   panel.querySelectorAll('.field-label').forEach(label=>{const explanation=help[label.textContent.trim()];if(!explanation)return;const icon=document.createElement('span');icon.className='mib-field-help';icon.textContent='ⓘ';icon.title=explanation;icon.setAttribute('aria-label',explanation);label.append(icon);});
   const presets=panel.querySelector('[data-fault-presets]');
   if(presets){
    const cards=presets.querySelector('[data-fault-cards]'),payload=presets.querySelector('[data-fault-payload]');
    function syncPresets(){payload.value=JSON.stringify([...cards.children].map(card=>{const rule={};card.querySelectorAll('[data-fault-field]').forEach(input=>rule[input.dataset.faultField]=input.type==='checkbox'?input.checked:input.value);return rule;}));presets.querySelector('[data-fault-add]').disabled=cards.children.length>=8;}
    function bindCard(card){
     function update(){const values={};card.querySelectorAll('[data-fault-field]').forEach(input=>values[input.dataset.faultField]=input.type==='checkbox'?input.checked:input.value);card.querySelector('[data-fault-title]').textContent=values.name||'New Fault Preset';const badge=card.querySelector('[data-fault-status]');badge.textContent=values.enabled?'Enabled':'Disabled';badge.classList.toggle('enabled',values.enabled);card.querySelectorAll('[data-fault-preview]').forEach(item=>{const key=item.dataset.faultPreview;item.textContent=key==='condition'?card.querySelector('[data-fault-field=condition]').selectedOptions[0].textContent:(values[key]|| (key==='threshold'?'Not configured':'—'));if(key==='severity')item.dataset.severity=values.severity;});syncPresets();}
     card.querySelector('[data-fault-edit]').addEventListener('click',()=>{const fields=card.querySelector('.mib-fault-fields');fields.hidden=!fields.hidden;card.querySelector('[data-fault-edit]').setAttribute('aria-expanded',String(!fields.hidden));});
     card.querySelector('[data-fault-remove]').addEventListener('click',()=>{card.remove();syncPresets();});card.querySelectorAll('[data-fault-field]').forEach(input=>input.addEventListener('input',update));update();
    }
    [...cards.children].forEach(bindCard);presets.querySelector('[data-fault-add]').addEventListener('click',()=>{if(cards.children.length>=8)return;const card=presets.querySelector('template').content.firstElementChild.cloneNode(true);cards.append(card);bindCard(card);card.querySelector('[data-fault-edit]').click();});
   }
   const tabs=[...panel.querySelectorAll('[data-generation-tab]')];
   function activateTab(tab){tabs.forEach(item=>item.setAttribute('aria-selected',String(item===tab)));panel.querySelectorAll('[data-generation-tab-panel]').forEach(item=>item.hidden=item.dataset.generationTabPanel!==tab.dataset.generationTab);}
   tabs.forEach((tab,index)=>{tab.addEventListener('click',()=>activateTab(tab));tab.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=tabs[event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length];activateTab(next);next.focus();});});
   const active=panel.querySelector('[role=switch]');if(active)active.addEventListener('change',()=>panel.querySelector('[data-active-label]').textContent=active.checked?'Enabled':'Disabled');
  });
  rows.forEach(row=>{row.querySelector('button').addEventListener('click',()=>openPanel(row.dataset.generationRow));row.querySelector('input').addEventListener('change',selection);});selection();
  document.querySelector('#mibGenerationSearch').addEventListener('input',event=>{const term=event.target.value.trim().toLowerCase();let count=0;rows.forEach(row=>{row.hidden=!row.dataset.search.includes(term);if(!row.hidden)count++;});document.querySelector('#mibGenerationNoMatches').hidden=count>0;});
 }
 let exclusionsConfirmed=false;
 const confirmDialog=document.querySelector('#mibExclusionConfirm');
 if(confirmDialog){
  confirmDialog.querySelectorAll('[data-exclusion-cancel]').forEach(control=>control.addEventListener('click',()=>confirmDialog.close()));
  confirmDialog.querySelector('[data-exclusion-continue]').addEventListener('click',()=>{exclusionsConfirmed=true;confirmDialog.close();form.requestSubmit(button);});
  rows.forEach(row=>row.querySelector('input').addEventListener('change',()=>exclusionsConfirmed=false));
 }
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy||button.disabled)return;
  if(review)for(const input of form.querySelectorAll('[data-generation-panel] input,[data-generation-panel] select'))if(!input.disabled&&!input.checkValidity()){const panel=input.closest('[data-generation-panel]');openPanel(panel.dataset.generationPanel);panel.querySelector('[data-generation-tab='+input.closest('[data-generation-tab-panel]').dataset.generationTabPanel+']').click();input.reportValidity();return;}
  const excluded=rows.filter(row=>!row.querySelector('input').checked);
  if(review&&excluded.length&&!exclusionsConfirmed){const list=confirmDialog.querySelector('[data-excluded-oids]');list.replaceChildren(...excluded.map(row=>{const item=document.createElement('li');const label=row.querySelector('button>span:last-child');item.textContent=label.firstChild.textContent.trim()+' ('+label.querySelector('small').textContent+')';return item;}));confirmDialog.showModal();return;}
  busy=true;button.disabled=true;error.hidden=true;
  if(!review){progress.hidden=false;progress.setAttribute('aria-busy','true');spinner.hidden=false;bar.removeAttribute('value');status.textContent='Please wait till the generation completes.';document.querySelector('#mibGenerationCaption').textContent='Creating templates in Cacti…';}

  const body=new FormData(form);body.set('generation_response','json');
  if(review){body.set('action','prepare_generation');body.set('exclusions_confirmed',exclusionsConfirmed?'1':'0');const payload={selected:{},records:{}};for(const [name,value] of [...body]){let match=name.match(/^selected\[(\d+)\]$/);if(match){payload.selected[match[1]]=value;body.delete(name);continue;}match=name.match(/^records\[(\d+)\]\[(\w+)\]$/);if(match){payload.records[match[1]]??={};payload.records[match[1]][match[2]]=value;body.delete(name);}}body.set('review_payload',JSON.stringify(payload));}
  try{
   const response=await fetch(new URL(form.getAttribute('action'),document.baseURI),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
   if(!response.headers.get('content-type')?.includes('application/json'))throw Error('Your session expired. Reopen the MIB Repository to check the saved stage.');
   const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Could not generate templates.');
   const next=new URL(result.redirect,document.baseURI);if(next.origin!==location.origin||!next.pathname.endsWith('/protocols/snmp/mibs/controllers/mib_repository.php'))throw Error('Invalid generation destination.');
   location.assign(next.href);
  }catch(failure){busy=false;button.disabled=false;spinner.hidden=true;progress.hidden=true;progress.setAttribute('aria-busy','false');error.textContent=failure.message;error.hidden=false;}
 });
})();

// Use the same local filtering and page controls as the Inventory table.
(()=>{'use strict';
 const list=document.querySelector('[data-mib-saved-list]');if(!list)return;
 const rows=[...list.querySelectorAll('[data-list-row]')],filters=[...list.querySelectorAll('[data-list-filter]')],search=list.querySelector('[data-list-search]'),size=list.querySelector('[data-list-size]'),pageSelect=list.querySelector('[data-list-page]'),clear=list.querySelector('[data-list-clear]'),sort=list.querySelector('[data-list-sort]');
 let page=1,ascending=true;
 function rowValues(row,key){return key==='module'&&row.dataset.modules?JSON.parse(row.dataset.modules):[row.dataset[key]];}
 filters.forEach(filter=>{
  const values=[...new Set(rows.flatMap(row=>rowValues(row,filter.dataset.listFilter)).filter(Boolean))].sort((a,b)=>a.localeCompare(b));
  values.forEach(value=>filter.add(new Option(value,value)));
  filter.addEventListener('change',()=>{page=1;render();});
 });
 function render(){
  const query=search.value.trim().toLowerCase(),matches=rows.filter(row=>row.dataset.search.toLowerCase().includes(query)&&filters.every(filter=>!filter.value||rowValues(row,filter.dataset.listFilter).includes(filter.value))).sort((a,b)=>(ascending?1:-1)*a.dataset.name.localeCompare(b.dataset.name,undefined,{numeric:true}));
  const limit=Number(size.value),pages=Math.max(1,Math.ceil(matches.length/limit));page=Math.max(1,Math.min(page,pages));
  rows.forEach(row=>row.hidden=true);list.querySelectorAll('[data-list-editor]').forEach(editor=>editor.hidden=true);list.querySelectorAll('[data-repository-edit]').forEach(button=>button.setAttribute('aria-expanded','false'));
  matches.slice((page-1)*limit,page*limit).forEach(row=>{row.hidden=false;row.parentNode.append(row);const id=row.querySelector('[data-repository-edit]')?.dataset.repositoryEdit;if(id)row.parentNode.append(document.getElementById('mib-edit-'+id));});
  const empty=list.querySelector('[data-list-empty]');empty.hidden=matches.length>0;empty.parentNode.append(empty);
  list.querySelector('[data-list-range]').textContent=(matches.length?`${(page-1)*limit+1}–${Math.min(page*limit,matches.length)}`:'0')+` of ${matches.length} items`;
  pageSelect.replaceChildren(...Array.from({length:pages},(_,i)=>new Option(String(i+1),String(i+1),false,i+1===page)));
  list.querySelector('[data-list-pages]').textContent=`of ${pages} pages`;list.querySelector('[data-list-prev]').disabled=page===1;list.querySelector('[data-list-next]').disabled=page===pages;
  clear.disabled=!query&&!filters.some(filter=>filter.value);
 }
 search.addEventListener('input',()=>{page=1;render();});size.addEventListener('change',()=>{page=1;render();});pageSelect.addEventListener('change',()=>{page=Number(pageSelect.value);render();});
 list.querySelector('[data-list-prev]').addEventListener('click',()=>{page--;render();});list.querySelector('[data-list-next]').addEventListener('click',()=>{page++;render();});
 clear.addEventListener('click',()=>{search.value='';filters.forEach(filter=>filter.value='');page=1;render();});
 sort.addEventListener('click',()=>{ascending=!ascending;sort.closest('th').setAttribute('aria-sort',ascending?'ascending':'descending');sort.querySelector('span').textContent=ascending?'↑':'↓';render();});
 list.querySelectorAll('[data-repository-edit]').forEach(button=>button.addEventListener('click',()=>{const editor=document.getElementById('mib-edit-'+button.dataset.repositoryEdit);editor.hidden=!editor.hidden;button.setAttribute('aria-expanded',String(!editor.hidden));if(!editor.hidden)editor.querySelector('[name=name]').focus();}));
 list.querySelectorAll('[data-repository-cancel]').forEach(button=>button.addEventListener('click',()=>{const editor=button.closest('[data-list-editor]');editor.querySelector('form').reset();editor.hidden=true;const trigger=list.querySelector('[data-repository-edit="'+editor.dataset.listEditor+'"]');trigger.setAttribute('aria-expanded','false');trigger.focus();}));
 render();
})();

(()=>{'use strict';
 const form=document.querySelector('#mibSelectionForm');if(!form)return;
 const source=document.querySelector('#mibBrowserData'),records=JSON.parse(source.textContent),boxes=[...form.querySelectorAll('[data-tree-selection]')],selected=new Set(),next=document.querySelector('#mibSelectionNext'),payload=document.querySelector('#mibSelectionPayload');
 function targets(index){const record=records[index];return records.filter(item=>item.numeric&&item.can_create&&(item.object_index===record.object_index||(item.module===record.module&&item.base_oid.startsWith(record.base_oid+'.'))));}
 function sync(){boxes.forEach(box=>{const items=targets(Number(box.dataset.treeSelection)),count=items.filter(item=>selected.has(item.object_index)).length;box.checked=items.length>0&&count===items.length;box.indeterminate=count>0&&count<items.length;});const values={};selected.forEach(index=>values[index]=1);payload.value=JSON.stringify({selected:values,records:{}});next.disabled=!selected.size;document.querySelector('#mibTreeSelectionCount').textContent=selected.size+' OIDs selected';}
 boxes.forEach(box=>box.addEventListener('change',()=>{targets(Number(box.dataset.treeSelection)).forEach(item=>box.checked?selected.add(item.object_index):selected.delete(item.object_index));sync();}));
 if(source.dataset.initialObject!==''){const index=records.findIndex(item=>item.object_index===Number(source.dataset.initialObject));if(index>=0)targets(index).forEach(item=>selected.add(item.object_index));}
 form.addEventListener('submit',event=>{sync();if(!selected.size)event.preventDefault();});sync();
})();
