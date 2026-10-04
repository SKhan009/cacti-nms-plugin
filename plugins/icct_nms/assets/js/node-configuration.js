(() => {
  'use strict';
  const search=document.querySelector('#nodeListSearch'),site=document.querySelector('#nodeListSite'),node=document.querySelector('#nodeListNode'),status=document.querySelector('#nodeListStatus');
  if(!search)return;
  const rows=[...document.querySelectorAll('#nodeDeviceTable tbody tr[data-site]')];
  let page=1,filtered=rows;
  const size=document.querySelector('#nodePageSize'),previous=document.querySelector('#nodePagePrevious'),next=document.querySelector('#nodePageNext');
  function renderPage(){
    const limit=Number(size.value),pages=Math.max(1,Math.ceil(filtered.length/limit));page=Math.min(page,pages);
    rows.forEach(row=>row.hidden=true);filtered.slice((page-1)*limit,page*limit).forEach(row=>row.hidden=false);
    document.querySelector('#nodeListCount').textContent=(filtered.length?((page-1)*limit+1)+'–'+Math.min(page*limit,filtered.length):'0')+' of '+filtered.length+' devices'+(filtered.length!==rows.length?' ('+rows.length+' total)':'');
    document.querySelector('#nodeListEmpty').hidden=filtered.length>0;document.querySelector('#nodePageNumber').textContent='Page '+page+' of '+pages;previous.disabled=page===1;next.disabled=page===pages;
  }
  function filter(){
    const query=search.value.trim().toLowerCase();page=1;
    filtered=rows.filter(row=>!((site.value&&row.dataset.site!==site.value)||(node.value&&row.dataset.node!==node.value)||(status.value&&row.dataset.status!==status.value)||!row.textContent.toLowerCase().includes(query)));renderPage();
  }
  previous.addEventListener('click',()=>{page--;renderPage();});next.addEventListener('click',()=>{page++;renderPage();});size.addEventListener('change',()=>{page=1;renderPage();});
  [search,status].forEach(control=>control.addEventListener('input',filter));
  function listNodes(){[...node.options].forEach(option=>{option.hidden=!!(option.dataset.site&&site.value&&option.dataset.site!==site.value);option.disabled=option.hidden;});if(node.selectedOptions[0].hidden)node.value='';}
  node.addEventListener('change',()=>{if(node.selectedOptions[0].dataset.site)site.value=node.selectedOptions[0].dataset.site;listNodes();filter();});
  site.addEventListener('change',()=>{listNodes();filter();});filter();
  const form=document.querySelector('#nodeAssignment');if(!form)return;
  const assignSite=document.querySelector('#nodeAssignSite');
  const add=document.querySelector('#nodeAdd'),target=document.querySelector('#nodeAssignNode'),toggle=document.querySelector('#nodeDeviceToggle'),options=document.querySelector('#nodeDeviceOptions'),deviceSearch=document.querySelector('#nodeDeviceSearch'),choices=[...document.querySelectorAll('.node-device-option')],save=document.querySelector('#nodeSave');
  function close(){options.hidden=true;toggle.setAttribute('aria-expanded','false');}
  function summary(){const count=choices.filter(row=>row.querySelector('input').checked&&!row.querySelector('input').disabled).length;document.querySelector('#nodeDeviceSummary').textContent=count?count+' devices selected':'Select devices';save.disabled=!target.value||!count;}
  function deviceFilter(){let visible=0;const query=deviceSearch.value.trim().toLowerCase(),selected=target.selectedOptions[0],siteId=selected?.dataset.site;
    choices.forEach(row=>{row.hidden=!siteId||row.dataset.site!==siteId||row.querySelector('input').disabled||!row.dataset.search.toLowerCase().includes(query);if(!row.hidden)visible++;});document.querySelector('#nodePickerEmpty').hidden=visible>0;
  }
  function updateNode(clear=true){const selected=target.selectedOptions[0];if(target.value)assignSite.value=selected.dataset.site;toggle.disabled=!target.value;
    choices.forEach(row=>{const checkbox=row.querySelector('input'),assigned=Number(row.dataset.node);checkbox.disabled=assigned>0||!target.value||row.dataset.site!==selected?.dataset.site;if(clear||checkbox.disabled)checkbox.checked=false;row.classList.toggle('unavailable',checkbox.disabled);row.querySelector('.node-assignment-note').textContent=assigned?(String(assigned)===target.value?'Already assigned to this node':'Assigned to '+row.querySelector('.node-assignment-note').dataset.nodeName):(target.value&&row.dataset.site!==selected?.dataset.site?'Different site':'');});deviceFilter();summary();close();
  }
  function assignNodes(){[...target.options].forEach(option=>{option.hidden=!!(option.value&&option.dataset.site!==assignSite.value);option.disabled=option.hidden;});if(target.selectedOptions[0].hidden)target.value='';const available=[...target.options].some(option=>option.value&&!option.hidden);target.disabled=!assignSite.value||!available;target.options[0].textContent=assignSite.value&&!available?'No nodes at this site':'Select node';}
  function syncList(){site.value=assignSite.value;node.value=target.value;listNodes();filter();}
  [site,node].forEach(control=>control.addEventListener('change',()=>{if(form.hidden)return;assignSite.value=site.value;target.value=node.value==='0'?'':node.value;assignNodes();updateNode();}));
  assignSite.addEventListener('change',()=>{target.value='';deviceSearch.value='';assignNodes();updateNode();syncList();});
  add.addEventListener('click',()=>{form.hidden=false;add.setAttribute('aria-expanded','true');assignSite.value=site.value;target.value=node.value==='0'?'':node.value;assignNodes();updateNode();(assignSite.value?target:assignSite).focus();});
  document.querySelector('#nodeCancel').addEventListener('click',()=>{form.reset();assignNodes();updateNode();form.hidden=true;add.setAttribute('aria-expanded','false');add.focus();});
  target.addEventListener('change',()=>{deviceSearch.value='';updateNode();syncList();});toggle.addEventListener('click',()=>{options.hidden=!options.hidden;toggle.setAttribute('aria-expanded',String(!options.hidden));if(!options.hidden)deviceSearch.focus();});
  deviceSearch.addEventListener('input',deviceFilter);choices.forEach(row=>row.querySelector('input').addEventListener('change',summary));
  document.querySelector('#nodeSelectVisible').addEventListener('click',()=>{choices.forEach(row=>{const input=row.querySelector('input');if(!row.hidden&&!input.disabled)input.checked=true;});summary();});
  document.querySelector('#nodeClearSelected').addEventListener('click',()=>{choices.forEach(row=>row.querySelector('input').checked=false);summary();});
  document.addEventListener('click',event=>{if(!event.target.closest('.node-picker'))close();});
  options.addEventListener('keydown',event=>{if(event.key==='Escape'){close();toggle.focus();}});
  form.addEventListener('submit',event=>{if(save.disabled){event.preventDefault();return;}save.disabled=true;});if(target.value)assignSite.value=target.selectedOptions[0].dataset.site;assignNodes();updateNode(false);
})();
