/** Real rack placement editor; all writes use the same service as Add/Edit Device. */
(() => {
  'use strict';
  const source=document.querySelector('#rackViewData');if(!source)return;
  let data=JSON.parse(source.textContent),editing=false,busy=false,scale=1;
  const node=document.querySelector('#rackViewNode'),cabinets=document.querySelector('#rackCabinets'),pool=document.querySelector('#rackDevicePool'),message=document.querySelector('#rackViewMessage'),manual=document.querySelector('#rackManualPlacement');
  const deviceSelect=document.querySelector('#rackMoveDevice'),rackSelect=document.querySelector('#rackMoveRack'),startInput=document.querySelector('#rackMoveStart'),heightInput=document.querySelector('#rackMoveHeight');
  const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
  function currentNode(){return data.nodes.find(n=>String(n.id)===node.value);}
  function nodeDevices(){const selected=currentNode();return selected?data.devices.filter(d=>Number(d.site_id)===Number(selected.site_id)&&(!d.node_id||Number(d.node_id)===Number(selected.id))):[];}
  function populateNodes(){const selected=node.value;node.replaceChildren(new Option('Select node',''));data.nodes.forEach(n=>node.add(new Option(n.name+' — '+n.site_name,String(n.id))));node.value=data.nodes.some(n=>String(n.id)===selected)?selected:data.nodes.length?String(data.nodes[0].id):'';}
  function card(device){const n=el('div','rack-device '+(device.status==='Up'?'online':device.status==='Down'?'offline':'other'));n.dataset.deviceId=device.id;n.draggable=editing;n.tabIndex=0;const link=el('a','',device.name);link.href='device.php?id='+device.id;n.append(link,el('small','',device.status));n.title=device.name+' · '+device.status;n.addEventListener('dragstart',event=>{if(!editing||busy){event.preventDefault();return;}event.dataTransfer.setData('text/plain',String(device.id));deviceSelect.value=String(device.id);heightInput.value=device.height||1;});return n;}
  function droppable(target,rackId,unit,peripheral=false){target.addEventListener('dragover',event=>{if(editing&&!busy){event.preventDefault();target.classList.add('drag-over');}});target.addEventListener('dragleave',()=>target.classList.remove('drag-over'));target.addEventListener('drop',event=>{event.preventDefault();target.classList.remove('drag-over');const id=Number(event.dataTransfer.getData('text/plain'));if(editing&&nodeDevices().some(d=>d.id===id))save(id,rackId,unit,Number(heightInput.value),peripheral);});}
  function render(){
    const selected=currentNode(),racks=data.racks.filter(r=>String(r.node_id)===node.value),devices=nodeDevices();
    cabinets.replaceChildren();pool.hidden=!editing;manual.hidden=!editing;document.querySelector('#rackViewEdit').hidden=!data.management;
    document.querySelector('#rackViewEdit').setAttribute('aria-pressed',String(editing));
    const oldDevice=deviceSelect.value;deviceSelect.replaceChildren(new Option('Select device',''));devices.forEach(d=>deviceSelect.add(new Option(d.name,String(d.id))));if(devices.some(d=>String(d.id)===oldDevice))deviceSelect.value=oldDevice;
    rackSelect.replaceChildren(new Option('Unassigned','0'));racks.forEach(r=>rackSelect.add(new Option(r.name+' — Rack '+r.rack_number,String(r.id))));
    const list=document.querySelector('#rackDeviceList');list.replaceChildren();devices.forEach(d=>list.append(card(d)));droppableOncePool();
    if(!selected){cabinets.append(el('p','','Add a node and rack configuration in Presets to get started.'));return;}
    if(!racks.length)cabinets.append(el('p','','This node has no racks. Select its rack configuration in Presets.'));
    racks.forEach(rack=>{
      const shell=el('section','rack-cabinet'),heading=el('header','');heading.append(el('h3','',rack.name),el('small','',selected.name+' · Rack '+rack.rack_number+' · '+rack.unit_count+' U'));shell.append(heading);
      const frame=el('div','rack-frame'),slots=el('div','rack-slots');slots.style.gridTemplateRows='repeat('+rack.unit_count+',var(--rack-unit-size))';
      const occupied=new Set(rack.blocked.map(Number));devices.filter(d=>d.rack_id===Number(rack.id)&&!d.peripheral).forEach(d=>{for(let u=d.start;u<d.start+d.height;u++)occupied.add(u);});
      for(let u=1;u<=Number(rack.unit_count);u++){const slot=el('div','rack-slot'+(occupied.has(u)?' occupied':''));slot.style.gridRow=String(u);slot.append(el('span','rack-unit-label',u+'U'));slot.title=occupied.has(u)?u+'U — In use':u+'U — Available';droppable(slot,Number(rack.id),u);slots.append(slot);}
      devices.filter(d=>d.rack_id===Number(rack.id)&&!d.peripheral).forEach(d=>{const n=card(d);n.style.gridRow=d.start+' / span '+d.height;droppable(n,Number(rack.id),d.start);slots.append(n);});
      frame.append(slots);shell.append(frame);
      const peripherals=el('div','rack-peripherals');peripherals.append(el('h4','','Peripheral Slots'));droppable(peripherals,Number(rack.id),0,true);devices.filter(d=>d.rack_id===Number(rack.id)&&d.peripheral).forEach(d=>peripherals.append(card(d)));shell.append(peripherals);cabinets.append(shell);
    });
  }
  let poolBound=false;function droppableOncePool(){if(!poolBound){droppable(pool,0,0);poolBound=true;}}
  async function refresh(){const response=await fetch('rack_placement.php',{credentials:'same-origin',cache:'no-store'});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Unable to refresh racks.');data=result.data;populateNodes();render();}
  async function save(id,rackId,start,height,peripheral=false){
    if(busy)return;const device=data.devices.find(d=>d.id===id);if(!device){message.textContent='Select a device.';return;}
    if(rackId&&!peripheral&&(!Number.isInteger(start)||!Number.isInteger(height)||start<1||height<1||height>100)){message.textContent='Enter valid start unit and device height.';return;}
    const body=new FormData(document.querySelector('#rackViewToken'));body.set('host_id',id);body.set('rack_id',rackId);body.set('revision',device.revision);body.set('peripheral',peripheral?'1':'0');if(rackId&&!peripheral)for(let u=start;u<start+height;u++)body.append('units[]',String(u));
    busy=true;message.textContent='Saving placement…';
    try{const response=await fetch('rack_placement.php',{method:'POST',credentials:'same-origin',body});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Placement was not saved.');data=result.data;populateNodes();render();message.textContent='Placement saved. Add/Edit Device uses this same placement.';}catch(error){message.textContent=error.message;try{await refresh();}catch(refreshError){message.textContent+=' '+refreshError.message;}}finally{busy=false;}
  }
  node.addEventListener('change',()=>{message.textContent='';render();});
  document.querySelector('#rackViewEdit').addEventListener('click',()=>{editing=!editing;render();message.textContent=editing?'Drag devices to consecutive rack units or a peripheral slot. Changes save when dropped.':'';});
  deviceSelect.addEventListener('change',()=>{const d=data.devices.find(d=>String(d.id)===deviceSelect.value);if(d)heightInput.value=d.height||1;});
  document.querySelector('#rackMoveSave').addEventListener('click',()=>save(Number(deviceSelect.value),Number(rackSelect.value),Number(startInput.value),Number(heightInput.value)));
  document.querySelector('#rackViewZoomIn').addEventListener('click',()=>{scale=Math.min(2,scale+.1);cabinets.style.setProperty('--rack-unit-size',Math.round(28*scale)+'px');});
  document.querySelector('#rackViewZoomOut').addEventListener('click',()=>{scale=Math.max(.6,scale-.1);cabinets.style.setProperty('--rack-unit-size',Math.round(28*scale)+'px');});
  document.querySelector('#rackViewFullscreen').addEventListener('click',()=>{if(document.fullscreenElement)document.exitFullscreen();else document.querySelector('.icct-map-panel').requestFullscreen().catch(()=>message.textContent='Fullscreen is unavailable.');});
  populateNodes();render();setInterval(()=>{if(!editing&&!busy&&!document.querySelector('#icct-panel-rack').hidden)refresh().catch(error=>message.textContent=error.message);},30000);
})();
