(() => {
  'use strict';
  const search=document.querySelector('#nodeListSearch'),site=document.querySelector('#nodeListSite'),node=document.querySelector('#nodeListNode'),status=document.querySelector('#nodeListStatus');
  if(!search)return;
  const rows=[...document.querySelectorAll('#nodeDeviceTable tbody tr[data-site]')];
  function filter(){
    const query=search.value.trim().toLowerCase();let count=0;
    rows.forEach(row=>{row.hidden=!!((site.value&&row.dataset.site!==site.value)||(node.value&&row.dataset.node!==node.value)||(status.value&&row.dataset.status!==status.value)||!row.textContent.toLowerCase().includes(query));if(!row.hidden)count++;});
    document.querySelector('#nodeListCount').textContent=count+' of '+rows.length+' devices';document.querySelector('#nodeListEmpty').hidden=count>0;
  }
  [search,site,node,status].forEach(control=>control.addEventListener('input',filter));
  site.addEventListener('change',()=>{[...node.options].forEach(option=>option.hidden=!!(option.dataset.site&&site.value&&option.dataset.site!==site.value));if(node.selectedOptions[0].hidden)node.value='';filter();});filter();
  const form=document.querySelector('#nodeAssignment');if(!form)return;
  const add=document.querySelector('#nodeAdd'),target=document.querySelector('#nodeAssignNode'),toggle=document.querySelector('#nodeDeviceToggle'),options=document.querySelector('#nodeDeviceOptions'),deviceSearch=document.querySelector('#nodeDeviceSearch'),choices=[...document.querySelectorAll('.node-device-option')],save=document.querySelector('#nodeSave');
  function close(){options.hidden=true;toggle.setAttribute('aria-expanded','false');}
  function summary(){const count=choices.filter(row=>row.querySelector('input').checked&&!row.querySelector('input').disabled).length;document.querySelector('#nodeDeviceSummary').textContent=count?count+' devices selected':'Select devices';save.disabled=!target.value||!count;}
  function deviceFilter(){let visible=0;const query=deviceSearch.value.trim().toLowerCase(),selected=target.selectedOptions[0],siteId=selected?.dataset.site;
    choices.forEach(row=>{row.hidden=!siteId||!row.dataset.search.toLowerCase().includes(query);if(!row.hidden)visible++;});document.querySelector('#nodePickerEmpty').hidden=visible>0;
  }
  function updateNode(clear=true){const selected=target.selectedOptions[0];document.querySelector('#nodeAssignSite').textContent=selected?.dataset.siteName||'Select node';toggle.disabled=!target.value;
    choices.forEach(row=>{const checkbox=row.querySelector('input'),assigned=Number(row.dataset.node);checkbox.disabled=assigned>0||!target.value||row.dataset.site!==selected?.dataset.site;if(clear||checkbox.disabled)checkbox.checked=false;row.classList.toggle('unavailable',checkbox.disabled);row.querySelector('.node-assignment-note').textContent=assigned?(String(assigned)===target.value?'Already assigned to this node':'Assigned to '+row.querySelector('.node-assignment-note').dataset.nodeName):(target.value&&row.dataset.site!==selected?.dataset.site?'Different site':'');});deviceFilter();summary();close();
  }
  add.addEventListener('click',()=>{form.hidden=false;add.setAttribute('aria-expanded','true');target.focus();});
  document.querySelector('#nodeCancel').addEventListener('click',()=>{form.reset();updateNode();form.hidden=true;add.setAttribute('aria-expanded','false');add.focus();});
  target.addEventListener('change',()=>updateNode());toggle.addEventListener('click',()=>{options.hidden=!options.hidden;toggle.setAttribute('aria-expanded',String(!options.hidden));if(!options.hidden)deviceSearch.focus();});
  deviceSearch.addEventListener('input',deviceFilter);choices.forEach(row=>row.querySelector('input').addEventListener('change',summary));
  document.querySelector('#nodeSelectVisible').addEventListener('click',()=>{choices.forEach(row=>{const input=row.querySelector('input');if(!row.hidden&&!input.disabled)input.checked=true;});summary();});
  document.querySelector('#nodeClearSelected').addEventListener('click',()=>{choices.forEach(row=>row.querySelector('input').checked=false);summary();});
  document.addEventListener('click',event=>{if(!event.target.closest('.node-picker'))close();});
  options.addEventListener('keydown',event=>{if(event.key==='Escape'){close();toggle.focus();}});
  form.addEventListener('submit',event=>{if(save.disabled){event.preventDefault();return;}save.disabled=true;});updateNode(false);
})();
