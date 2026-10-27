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
 tree.querySelectorAll('[data-tree-selection]').forEach(box=>box.addEventListener('change',()=>select(Number(box.dataset.treeSelection))));
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
 const form=document.querySelector('#mibSelectionForm');if(!form)return;
 const source=document.querySelector('#mibBrowserData'),records=JSON.parse(source.textContent),boxes=[...form.querySelectorAll('[data-tree-selection]')],selected=new Set(),next=document.querySelector('#mibSelectionNext'),payload=document.querySelector('#mibSelectionPayload');
 function targets(index){const record=records[index];return records.filter(item=>item.numeric&&item.can_create&&(item.object_index===record.object_index||(item.module===record.module&&item.base_oid.startsWith(record.base_oid+'.'))));}
 function sync(){boxes.forEach(box=>{const items=targets(Number(box.dataset.treeSelection)),count=items.filter(item=>selected.has(item.object_index)).length;box.checked=items.length>0&&count===items.length;box.indeterminate=count>0&&count<items.length;});const values={};selected.forEach(index=>values[index]=1);payload.value=JSON.stringify({selected:values,records:{}});next.disabled=!selected.size;document.querySelector('#mibTreeSelectionCount').textContent=selected.size+' OIDs selected';}
 boxes.forEach(box=>box.addEventListener('change',()=>{targets(Number(box.dataset.treeSelection)).forEach(item=>box.checked?selected.add(item.object_index):selected.delete(item.object_index));sync();}));
 // Restore the selection count alongside browser-restored checkbox state.
 function restoreSelection(){selected.clear();boxes.filter(box=>box.checked&&!box.disabled).forEach(box=>targets(Number(box.dataset.treeSelection)).forEach(item=>selected.add(item.object_index)));sync();}
 window.addEventListener('pageshow',restoreSelection);
 boxes.filter(box=>box.checked&&!box.disabled).forEach(box=>targets(Number(box.dataset.treeSelection)).forEach(item=>selected.add(item.object_index)));
 if(source.dataset.initialObject!==''){const index=records.findIndex(item=>item.object_index===Number(source.dataset.initialObject));if(index>=0)targets(index).forEach(item=>selected.add(item.object_index));}
 form.addEventListener('submit',event=>{sync();if(!selected.size)event.preventDefault();});sync();
})();
