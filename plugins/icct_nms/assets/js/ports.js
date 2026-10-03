"use strict";
(() => {
  const panel=document.querySelector('#device-ports');
  if(!panel)return;
  const form=document.querySelector('#device-form'),wizard=document.querySelector('#device-wizard');
  const profiles=JSON.parse(document.querySelector('#device-type-profiles').textContent);
  let observation=JSON.parse(document.querySelector('#port-observations').textContent),page=0,changedEndpoint=false;
  const originalHostname=form.elements.hostname.value, originalPoller=form.elements.poller_id.value;
  const rows=document.querySelector('#port-rows');
  function render() {
    const profile=profiles.find(p=>String(p.category_id)===form.elements.category_id.value&&p.name===form.elements.device_type.value);
    const expected=profile?.physical_ports ?? null;
    const changed=changedEndpoint||form.elements.hostname.value!==originalHostname||form.elements.poller_id.value!==originalPoller;
    const ports=changed?[]:observation.ports;
    const physical=ports.filter(p=>p.connector===1),other=ports.filter(p=>p.connector!==1);
    const unknownSlots=expected===null?0:Math.max(0,Number(expected)-physical.length);
    const total=physical.length+unknownSlots+other.length,maxPage=Math.max(0,Math.ceil(total/50)-1);
    page=Math.min(page,maxPage); rows.replaceChildren();
    const counts={inUse:0,available:0,disabled:0,unknown:unknownSlots};
    physical.forEach(p=>{if(p.status==='In use')counts.inUse++;else if(p.status==='Available (link down)')counts.available++;else if(p.status==='Disabled')counts.disabled++;else counts.unknown++;});
    document.querySelector('#port-summary').textContent=`Preset physical ports: ${expected===null?'Not set':expected} · Identified physical ports: ${physical.length} · Physical status — In use: ${counts.inUse} · Available: ${counts.available} · Disabled: ${counts.disabled} · Unknown: ${counts.unknown} · Other interfaces: ${other.length}`;
    document.querySelector('#port-observation').textContent=changed?'Device connection settings changed. Save to discover ports for the updated device.':observation.message+(observation.collected?` Last collected: ${new Date(observation.collected*1000).toLocaleString()}.`:'');
    for(let i=page*50;i<Math.min(total,(page+1)*50);i++) {
      const p=i<physical.length?physical[i]:i<physical.length+unknownSlots?null:other[i-physical.length-unknownSlots];
      const values=p?[p.name,p.index,p.connector===1?'Physical':p.connector===2?'Logical / no connector':'Physical type unreported',p.status,p.alias||p.description||'—']:['Unidentified preset slot '+(i-physical.length+1),'—','Preset capacity','Unknown','Awaiting physical-port identification'];
      const tr=document.createElement('tr');
      for(const value of values){const td=document.createElement('td');td.textContent=String(value);tr.append(td);}rows.append(tr);
    }
    if(!total){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=5;td.textContent='No interfaces discovered yet. Set the port count in Device Type presets and configure SNMP on this device.';tr.append(td);rows.append(tr);}
    document.querySelector('#ports-page').textContent=total?`${page*50+1}–${Math.min(total,(page+1)*50)} of ${total}`:'0 ports';
    document.querySelector('#ports-previous').disabled=page===0;
    document.querySelector('#ports-next').disabled=page===maxPage;
  }
  form.elements.category_id.addEventListener('change',()=>{page=0;render();});
  form.elements.device_type.addEventListener('change',()=>{page=0;render();});
  form.elements.hostname.addEventListener('input',render);form.elements.poller_id.addEventListener('change',render);
  document.querySelector('#protocol-snmp form')?.addEventListener('input',()=>{changedEndpoint=true;render();});
  document.querySelector('#protocol-snmp')?.addEventListener('change',()=>{changedEndpoint=true;render();});
  document.querySelector('#ports-previous').addEventListener('click',()=>{page--;render();});
  document.querySelector('#ports-next').addEventListener('click',()=>{page++;render();});
  let loading=false;
  async function refresh(){
    if(loading||location.hash!=='#ports'||document.hidden||form.dataset.staticPreview||!Number(wizard.dataset.deviceId))return;
    loading=true;
    try{const response=await fetch('ports.php?id='+encodeURIComponent(wizard.dataset.deviceId),{credentials:'same-origin',cache:'no-store'});if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Port observations could not load. Reload the page and check your Cacti session.');const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.error||'Port observations could not load.');observation=payload.data;render();}
    catch(error){document.querySelector('#port-observation').textContent=error.message;}
    finally{loading=false;}
  }
  window.addEventListener('hashchange',()=>{if(location.hash==='#ports')refresh();});
  setInterval(refresh,30000);render();
})();
